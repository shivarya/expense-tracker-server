<?php

/**
 * MccCategories
 *
 * Maps the merchant category code a UPI QR carries (its `mc` parameter) to one of
 * the canonical category IDs in CategoryResolver::CANONICAL. Only common codes are
 * listed; anything else returns null and the normal category resolution applies.
 * "0000" (or no code) is a person-to-person payment, which has no category of its own.
 */
class MccCategories
{
    private const EXACT = [
        '5411' => 9, '5499' => 9,                               // Groceries
        '5812' => 1, '5813' => 1, '5814' => 1,                  // Food & Dining
        '5541' => 2, '5542' => 2, '4121' => 2, '4111' => 2,     // Transportation (fuel, cabs, local transit)
        '4112' => 8, '4511' => 8, '7011' => 8,                  // Travel (rail, airlines, hotels)
        '5912' => 6,                                            // Healthcare (pharmacies)
        '4814' => 5, '4899' => 5, '4900' => 5,                  // Bills & Utilities
        '5651' => 58, '5691' => 58, '5699' => 58,               // Clothing
        '5311' => 3, '5399' => 3, '5732' => 3,                  // Shopping
        '7832' => 4, '7922' => 4,                               // Entertainment
        '7230' => 12, '7298' => 12,                             // Personal Care
        '6300' => 10,                                           // Insurance
    ];

    /** Two-digit prefixes covering a whole MCC range. */
    private const PREFIX = [
        '80' => 6, // 80xx medical services -> Healthcare
        '82' => 7, // 82xx schools -> Education
    ];

    public static function categoryFor(?string $mcc): ?int
    {
        $mcc = self::normalize($mcc);
        if ($mcc === null || $mcc === '0000') {
            return null;
        }
        return self::EXACT[$mcc] ?? self::PREFIX[substr($mcc, 0, 2)] ?? null;
    }

    /** The 4-digit code, or null when it isn't one. */
    public static function normalize(?string $mcc): ?string
    {
        $mcc = trim((string)$mcc);
        return preg_match('/^\d{4}$/', $mcc) ? $mcc : null;
    }
}
