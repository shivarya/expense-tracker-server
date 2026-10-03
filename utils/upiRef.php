<?php

/**
 * UpiRef
 *
 * Pulls the 12-digit UPI RRN/UTR out of free text (a parsed reference_number, an
 * SMS body, a notification). Every source writes it to transactions.upi_ref so the
 * same payment reported by a payment app and by the bank can be matched exactly,
 * whatever prefix or wording each source wraps the number in.
 *
 * $reference (a parsed reference_number) may be the bare 12 digits; in free
 * $texts only a run labelled UPI/Ref/RRN/UTR/Txn counts, because an unlabelled
 * 12-digit run there is as likely to be a 91-prefixed phone number — and a wrong
 * upi_ref would block a legitimate merge.
 */
class UpiRef
{
    public static function extract(?string $reference, ?string ...$texts): ?string
    {
        $reference = trim((string)$reference);
        if ($reference !== '' && preg_match('/^\D*(\d{12})\D*$/', $reference, $m)) {
            return $m[1];
        }

        foreach (array_merge([$reference], $texts) as $text) {
            $text = trim((string)$text);
            if ($text !== '' && preg_match('/\b(?:upi|ref|rrn|utr|txn).{0,20}?(?<!\d)(\d{12})(?!\d)/i', $text, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
