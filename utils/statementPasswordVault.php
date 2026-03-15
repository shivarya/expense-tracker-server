<?php

class StatementPasswordVault
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 1;

    public static function encrypt(string $plainPassword): array
    {
        $plainPassword = trim($plainPassword);
        if ($plainPassword === '') {
            throw new Exception('Password cannot be empty');
        }

        $key = self::resolveKey();
        $iv = random_bytes(12);
        $authTag = '';

        $encrypted = openssl_encrypt(
            $plainPassword,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $authTag,
            '',
            16
        );

        if ($encrypted === false) {
            throw new Exception('Failed to encrypt statement password');
        }

        return [
            'encrypted_password' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
            'auth_tag' => base64_encode($authTag),
            'encryption_version' => self::VERSION,
        ];
    }

    public static function decrypt(string $encryptedPassword, string $iv, string $authTag): string
    {
        $key = self::resolveKey();

        $cipherText = base64_decode($encryptedPassword, true);
        $decodedIv = base64_decode($iv, true);
        $decodedAuthTag = base64_decode($authTag, true);

        if ($cipherText === false || $decodedIv === false || $decodedAuthTag === false) {
            throw new Exception('Invalid encrypted payload for statement password');
        }

        $decrypted = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $decodedIv,
            $decodedAuthTag,
            ''
        );

        if ($decrypted === false) {
            throw new Exception('Failed to decrypt statement password');
        }

        return $decrypted;
    }

    private static function resolveKey(): string
    {
        if (!defined('STATEMENT_PASSWORD_KEY') || trim((string)STATEMENT_PASSWORD_KEY) === '') {
            throw new Exception('STATEMENT_PASSWORD_KEY is not configured');
        }

        // Normalize to 32-byte binary key.
        return hash('sha256', STATEMENT_PASSWORD_KEY, true);
    }
}
