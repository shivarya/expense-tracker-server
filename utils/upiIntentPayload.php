<?php

require_once __DIR__ . '/mccCategories.php';
require_once __DIR__ . '/paymentApps.php';

/**
 * UpiIntentPayload
 *
 * Validates and normalises the body of POST /parse/upi-intent: a Scan & Pay payment
 * the Android app started with a upi://pay intent, plus what the UPI app returned.
 * Pure (no DB): the controller checks that account_id belongs to the user.
 *
 * Returns the normalised payment, or ['error' => message] when the body is unusable.
 */
class UpiIntentPayload
{
    public const STATUSES = ['success', 'submitted', 'confirmed', 'unconfirmed'];
    public const MODES = ['record', 'merge_only'];
    private const MAX_AMOUNT = 1000000.0;

    public static function normalize(array $input): array
    {
        $clientRef = trim((string)($input['client_ref'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientRef)) {
            return ['error' => 'client_ref is required (a UUID)'];
        }

        $package = trim((string)($input['package'] ?? ''));
        if (strlen($package) > 255 || !preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+$/', $package)) {
            return ['error' => 'package must be an Android package name'];
        }

        $vpa = strtolower(trim((string)($input['vpa'] ?? '')));
        if (!self::isVpa($vpa)) {
            return ['error' => 'vpa is not a valid UPI ID'];
        }

        $rawAmount = $input['amount'] ?? null;
        if (is_string($rawAmount)) {
            $rawAmount = str_replace(',', '', trim($rawAmount));
        }
        if (!is_numeric($rawAmount) || (float)$rawAmount <= 0 || (float)$rawAmount > self::MAX_AMOUNT) {
            return ['error' => 'amount must be a positive number'];
        }

        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, self::STATUSES, true)) {
            return ['error' => 'status must be one of ' . implode(', ', self::STATUSES)];
        }

        $mode = strtolower(trim((string)($input['mode'] ?? 'record'))) ?: 'record';
        if (!in_array($mode, self::MODES, true)) {
            return ['error' => 'mode must be record or merge_only'];
        }

        $accountId = $input['account_id'] ?? null;
        if ($accountId !== null && $accountId !== '') {
            if (filter_var($accountId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return ['error' => 'account_id must be a positive integer'];
            }
            $accountId = (int)$accountId;
        } else {
            $accountId = null;
        }

        $app = PaymentApps::find($package);
        $appLabel = $app['label'] ?? (self::text($input['app_label'] ?? null, 60) ?? $package);

        return [
            'client_ref' => $clientRef,
            'package' => $package,
            'app_label' => $appLabel,
            'vpa' => $vpa,
            'payee_name' => self::text($input['payee_name'] ?? null, 200),
            'amount' => round((float)$rawAmount, 2),
            'note' => self::text($input['note'] ?? null, 500),
            'mcc' => MccCategories::normalize(isset($input['mcc']) ? (string)$input['mcc'] : null),
            'tr' => self::text($input['tr'] ?? null, 100),
            'status' => $status,
            'txn_id' => self::text($input['txn_id'] ?? null, 100),
            'approval_ref' => self::text($input['approval_ref'] ?? null, 100),
            'response_code' => self::text($input['response_code'] ?? null, 20),
            'completed_at' => self::text($input['completed_at'] ?? null, 40),
            'account_id' => $accountId,
            'mode' => $mode,
        ];
    }

    /** handle@psp, as NPCI allows: letters, digits, dot, hyphen, underscore. */
    public static function isVpa(string $vpa): bool
    {
        return strlen($vpa) <= 255 && (bool)preg_match('/^[a-z0-9][a-z0-9._-]{0,199}@[a-z0-9][a-z0-9.-]{1,63}$/i', $vpa);
    }

    /**
     * The 12-digit UPI RRN, only when a returned reference is exactly that. Apps put
     * other ids in txnId/ApprovalRefNo too, and a wrong upi_ref blocks a merge forever.
     */
    public static function upiRef(array $payment): ?string
    {
        foreach (['approval_ref', 'txn_id'] as $field) {
            $value = (string)($payment[$field] ?? '');
            if (preg_match('/^\d{12}$/', $value)) {
                return $value;
            }
        }
        return null;
    }

    /** Single-line text without control characters, cut to $max characters; null when empty. */
    private static function text($value, int $max): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string)$value) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
