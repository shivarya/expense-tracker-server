<?php

/**
 * SelfTransfer
 *
 * Money the user moves between their own accounts (a UPI payment to their own UPI ID,
 * a transfer to their other bank) is a Transfer, not spending or income. Trusted
 * contacts catch it when the user has added themselves; this catches the common case
 * without setup: the payee's name is the account holder's own name.
 *
 * Deliberately strict: whole-name equality (ignoring case, word order, titles and a
 * bracketed nickname like "Ashish Tripathi (Shivam)"), at least two words, so a single
 * shared first name never matches.
 */
class SelfTransfer
{
    public static function isOwnName(string $payee, string $ownerName): bool
    {
        $payeeWords = self::words($payee);
        $ownerWords = self::words($ownerName);
        if (count($payeeWords) < 2 || count($ownerWords) < 2) {
            return false;
        }
        sort($payeeWords);
        sort($ownerWords);
        return $payeeWords === $ownerWords;
    }

    /** Lower-case name words, without a bracketed nickname, titles or punctuation. */
    public static function words(string $name): array
    {
        $name = preg_replace('/\([^)]*\)/', ' ', $name) ?? '';
        $name = strtolower(preg_replace('/[^A-Za-z ]+/', ' ', $name) ?? '');
        $words = array_values(array_filter(explode(' ', $name), static fn($w) => $w !== ''));
        while ($words && in_array($words[0], ['mr', 'mrs', 'ms', 'miss', 'dr', 'shri', 'smt'], true)) {
            array_shift($words);
        }
        return $words;
    }
}
