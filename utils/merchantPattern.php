<?php

/**
 * MerchantPattern
 *
 * Normalizes raw merchant/description text into a compact, stable pattern
 * used to group transactions from the same real-world merchant together --
 * shared by CategoryLearning (merchant -> category rules) and
 * MerchantSubscriptionDetector (recurring-charge detection).
 */
class MerchantPattern
{
    private const MIN_PATTERN_LENGTH = 3;

    public static function normalize(string $text): string
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return '';
        }

        // Remove UPI handles / account-like IDs to keep pattern stable.
        $text = preg_replace('/\b[a-z0-9._%+-]+@[a-z0-9.-]+\b/i', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:txn|utr|rrn|ref|a\\/?c|account|card)\s*[:#-]?\s*[a-z0-9*_-]+\b/i', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{4,}\b/', ' ', $text) ?? $text;

        // Keep only simple alphanumeric words.
        $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $tokens = explode(' ', $text);
        $tokens = array_values(array_filter($tokens, function ($token) {
            return strlen($token) >= 2;
        }));

        if (empty($tokens)) {
            return '';
        }

        // Keep pattern compact to improve similarity matching.
        $tokens = array_slice($tokens, 0, 6);
        $pattern = implode(' ', $tokens);

        if (strlen($pattern) < self::MIN_PATTERN_LENGTH) {
            return '';
        }

        return $pattern;
    }

    public static function isGeneric(string $pattern): bool
    {
        $genericPhrases = [
            'upi payment',
            'upi transfer',
            'card payment',
            'bill payment',
            'transaction',
            'sms transaction',
            'online payment',
            'debit',
            'credit',
            'payment',
            'transfer',
        ];

        if (in_array($pattern, $genericPhrases, true)) {
            return true;
        }

        $genericTokens = [
            'upi', 'payment', 'transfer', 'txn', 'transaction',
            'debit', 'credit', 'bank', 'card', 'online', 'ref',
            'from', 'to', 'at', 'by', 'via', 'for'
        ];

        $tokens = explode(' ', $pattern);
        $informative = array_filter($tokens, function ($token) use ($genericTokens) {
            return strlen($token) >= 3 && !in_array($token, $genericTokens, true);
        });

        return empty($informative);
    }
}
