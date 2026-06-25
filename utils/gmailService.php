<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/statementPasswordVault.php';

/**
 * GmailService
 *
 * Manages per-user Gmail (read-only) authorization for server-side statement
 * fetching. The mobile app performs Google Sign-In with the gmail.readonly
 * scope and offline access, then sends the one-time serverAuthCode here; we
 * exchange it for a refresh token and store the token set ENCRYPTED in
 * users.gmail_token. Subsequent fetches build an authorized client and refresh
 * the access token transparently.
 */
class GmailService
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    /** A Google_Client configured for our OAuth web app (no token yet). */
    private static function baseClient(): \Google\Client
    {
        $clientId = (string)(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '');
        $clientSecret = (string)(defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '');

        if ($clientId === '' || $clientSecret === '') {
            throw new Exception('Google OAuth is not configured (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');
        }

        $client = new \Google\Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessType('offline');
        $client->setScopes([self::SCOPE]);

        return $client;
    }

    /**
     * Exchange a mobile serverAuthCode for tokens and store them encrypted.
     * Returns ['connected' => true, 'email' => string|null].
     */
    public static function connectFromAuthCode(int $userId, string $authCode): array
    {
        $clientId = (string)(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '');
        $clientSecret = (string)(defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '');
        if ($clientId === '' || $clientSecret === '') {
            throw new Exception('Google OAuth is not configured (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');
        }

        // Native Google Sign-In one-time codes must be exchanged with an EMPTY
        // redirect_uri (per Google's offline-access docs). The google-api-php-client
        // rejects an empty redirect_uri ("must be absolute") and 'postmessage'
        // yields redirect_uri_mismatch — so POST the exchange directly.
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'code' => $authCode,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => '',
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Google token exchange request failed: ' . $curlErr);
        }

        $token = json_decode($response, true);
        if (!is_array($token) || isset($token['error']) || empty($token['access_token'])) {
            error_log('GMAIL_EXCHANGE_DEBUG: ' . substr((string)$response, 0, 300));
            $detail = is_array($token) ? ($token['error_description'] ?? $token['error'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
            throw new Exception('Google token exchange failed: ' . $detail);
        }

        // The client lib computes access-token expiry from created + expires_in.
        if (!isset($token['created'])) {
            $token['created'] = time();
        }

        // A refresh_token is only returned on first consent / forced code. If
        // absent, keep any refresh token we already stored for this user.
        if (empty($token['refresh_token'])) {
            $existing = self::loadToken($userId);
            if ($existing && !empty($existing['refresh_token'])) {
                $token['refresh_token'] = $existing['refresh_token'];
            }
        }

        self::storeToken($userId, $token);

        // Best-effort: read the mailbox address for display.
        $email = null;
        try {
            $client = self::baseClient();
            $client->setAccessToken($token);
            $gmail = new \Google\Service\Gmail($client);
            $email = $gmail->users->getProfile('me')->getEmailAddress();
        } catch (Throwable $e) {
            error_log('GmailService: could not read profile email: ' . $e->getMessage());
        }

        return ['connected' => true, 'email' => $email];
    }

    /**
     * Build an authorized client for a user, refreshing the access token (and
     * persisting it) when expired. Returns null if the user has not connected
     * Gmail or the refresh failed (e.g. access revoked).
     */
    public static function getClientForUser(int $userId): ?\Google\Client
    {
        $token = self::loadToken($userId);
        if (!$token) {
            return null;
        }

        $client = self::baseClient();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $refresh = $client->getRefreshToken();
            if (!$refresh) {
                error_log("GmailService: no refresh token for user {$userId}");
                return null;
            }

            $new = $client->fetchAccessTokenWithRefreshToken($refresh);
            if (!is_array($new) || isset($new['error'])) {
                $detail = is_array($new) ? ($new['error_description'] ?? $new['error'] ?? 'unknown') : 'no response';
                error_log("GmailService: token refresh failed for user {$userId}: {$detail}");
                return null;
            }

            // Refresh responses omit the refresh_token; preserve the original.
            $merged = array_merge($token, $new);
            if (empty($merged['refresh_token'])) {
                $merged['refresh_token'] = $refresh;
            }
            self::storeToken($userId, $merged);
            $client->setAccessToken($merged);
        }

        return $client;
    }

    public static function isConnected(int $userId): bool
    {
        return self::loadToken($userId) !== null;
    }

    public static function getStatus(int $userId): array
    {
        $db = getDB();
        $row = $db->fetchOne("SELECT gmail_authorized_at FROM users WHERE id = ?", [$userId]);

        return [
            'connected' => self::isConnected($userId),
            'authorized_at' => $row['gmail_authorized_at'] ?? null,
        ];
    }

    public static function disconnect(int $userId): void
    {
        // Best-effort token revocation, then clear local storage regardless.
        try {
            $token = self::loadToken($userId);
            if ($token) {
                $client = self::baseClient();
                $client->setAccessToken($token);
                $client->revokeToken();
            }
        } catch (Throwable $e) {
            error_log('GmailService: revoke failed (continuing): ' . $e->getMessage());
        }

        $db = getDB();
        $db->execute("UPDATE users SET gmail_token = NULL, gmail_authorized_at = NULL WHERE id = ?", [$userId]);
    }

    /** Encrypt the token set and persist as a JSON envelope in users.gmail_token. */
    private static function storeToken(int $userId, array $token): void
    {
        $env = StatementPasswordVault::encrypt(json_encode($token));
        $payload = json_encode([
            'v' => 1,
            'enc' => $env['encrypted_password'],
            'iv' => $env['iv'],
            'tag' => $env['auth_tag'],
        ]);

        $db = getDB();
        $db->execute(
            "UPDATE users SET gmail_token = ?, gmail_authorized_at = NOW() WHERE id = ?",
            [$payload, $userId]
        );
    }

    /** Decrypt and return the stored token set, or null. Handles legacy plaintext. */
    private static function loadToken(int $userId): ?array
    {
        $db = getDB();
        $row = $db->fetchOne("SELECT gmail_token FROM users WHERE id = ?", [$userId]);
        if (!$row || empty($row['gmail_token'])) {
            return null;
        }

        $stored = json_decode((string)$row['gmail_token'], true);
        if (!is_array($stored)) {
            return null;
        }

        // Encrypted envelope (current format).
        if (isset($stored['enc'], $stored['iv'], $stored['tag'])) {
            try {
                $json = StatementPasswordVault::decrypt($stored['enc'], $stored['iv'], $stored['tag']);
                $token = json_decode($json, true);
                return is_array($token) ? $token : null;
            } catch (Throwable $e) {
                error_log('GmailService: failed to decrypt gmail_token: ' . $e->getMessage());
                return null;
            }
        }

        // Back-compat: legacy plaintext token JSON written before encryption.
        if (isset($stored['access_token']) || isset($stored['refresh_token'])) {
            return $stored;
        }

        return null;
    }
}
