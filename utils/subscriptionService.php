<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

/**
 * SubscriptionService
 *
 * Premium gating backed by Google Play Billing. The app sends a purchaseToken
 * after a Play purchase; we verify it server-side with the Play Developer API
 * (never trust the client) and persist the result in `subscriptions`.
 *
 * IMPORTANT: gating is OFF by default. isPremium() returns true unless
 * PREMIUM_ENFORCED=true, so existing/free flows (and Phase 2 testing) are
 * unaffected until you explicitly turn enforcement on.
 */
class SubscriptionService
{
    public static function enforced(): bool
    {
        return filter_var(self::env('PREMIUM_ENFORCED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /** True when the user may use premium features. */
    public static function isPremium(int $userId): bool
    {
        if (!self::enforced()) {
            return true; // gating disabled — everyone is "premium"
        }

        $row = self::latestSubscription($userId);
        if (!$row) {
            return false;
        }

        $activeStatus = in_array($row['status'], ['active', 'in_grace'], true);
        $notExpired = empty($row['expiry_time']) || strtotime($row['expiry_time']) > time();
        return $activeStatus && $notExpired;
    }

    public static function getStatus(int $userId): array
    {
        $row = self::latestSubscription($userId);

        return [
            'premium' => self::isPremium($userId),
            'enforced' => self::enforced(),
            'product_id' => $row['product_id'] ?? null,
            'status' => $row['status'] ?? null,
            'expiry_time' => $row['expiry_time'] ?? null,
            'auto_renewing' => isset($row['auto_renewing']) ? (bool)$row['auto_renewing'] : null,
        ];
    }

    /**
     * Verify a Play purchase token with the Google Play Developer API and upsert
     * the subscription. Returns the refreshed status. Throws if Play billing is
     * not configured or verification fails.
     */
    public static function verifyPurchase(int $userId, string $productId, string $purchaseToken): array
    {
        $package = self::env('GOOGLE_PLAY_PACKAGE_NAME');
        $saPath = self::env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON');

        if ($package === '' || $saPath === '') {
            throw new Exception('Play billing not configured (GOOGLE_PLAY_PACKAGE_NAME / GOOGLE_PLAY_SERVICE_ACCOUNT_JSON).');
        }
        if (!is_file($saPath)) {
            throw new Exception('Service account JSON not found at GOOGLE_PLAY_SERVICE_ACCOUNT_JSON path.');
        }

        $client = new \Google\Client();
        $client->setAuthConfig($saPath);
        $client->addScope('https://www.googleapis.com/auth/androidpublisher');
        $service = new \Google\Service\AndroidPublisher($client);

        // Play Developer API v1 subscriptions.get (stable; needs the product id).
        $purchase = $service->purchases_subscriptions->get($package, $productId, $purchaseToken);

        $expiryMillis = (int)$purchase->getExpiryTimeMillis();
        $autoRenew = (bool)$purchase->getAutoRenewing();
        $paymentState = $purchase->getPaymentState(); // 0 pending, 1 received, 2 free trial, 3 deferred (null after expiry)
        $nowMillis = (int)(microtime(true) * 1000);

        $paid = in_array((int)$paymentState, [1, 2], true);
        $notExpired = $expiryMillis > $nowMillis;

        if ($notExpired && $paid) {
            // A canceled-but-not-yet-expired sub still grants access until expiry.
            $status = $autoRenew ? 'active' : 'canceled';
        } elseif (!$notExpired) {
            $status = 'expired';
        } else {
            $status = 'pending';
        }

        $expiry = $expiryMillis > 0 ? date('Y-m-d H:i:s', intdiv($expiryMillis, 1000)) : null;

        self::upsert($userId, $productId, $purchaseToken, $status, $paymentState, $autoRenew, $expiry, [
            'expiry_millis' => $expiryMillis,
            'auto_renewing' => $autoRenew,
            'payment_state' => $paymentState,
        ]);

        return self::getStatus($userId);
    }

    private static function upsert(
        int $userId,
        string $productId,
        string $token,
        string $status,
        $paymentState,
        bool $autoRenew,
        ?string $expiry,
        array $raw
    ): void {
        getDB()->execute(
            "INSERT INTO subscriptions
                (user_id, product_id, purchase_token, status, payment_state, auto_renewing, expiry_time, raw)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                product_id = VALUES(product_id),
                status = VALUES(status),
                payment_state = VALUES(payment_state),
                auto_renewing = VALUES(auto_renewing),
                expiry_time = VALUES(expiry_time),
                raw = VALUES(raw),
                updated_at = CURRENT_TIMESTAMP",
            [
                $userId,
                $productId,
                $token,
                $status,
                $paymentState !== null ? (int)$paymentState : null,
                $autoRenew ? 1 : 0,
                $expiry,
                json_encode($raw),
            ]
        );
    }

    /** Latest subscription row for the user; tolerant of the table not existing yet. */
    private static function latestSubscription(int $userId): ?array
    {
        try {
            $row = getDB()->fetchOne(
                "SELECT product_id, status, expiry_time, auto_renewing FROM subscriptions
                 WHERE user_id = ? ORDER BY expiry_time DESC, id DESC LIMIT 1",
                [$userId]
            );
            return $row ?: null;
        } catch (Exception $e) {
            error_log('latestSubscription skipped: ' . $e->getMessage());
            return null;
        }
    }

    private static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = (string)($_ENV[$key] ?? '');
        }
        $v = trim((string)$v);
        return $v !== '' ? $v : $default;
    }
}
