<?php

/**
 * AlertEmailParsers
 *
 * Deterministic parsers for bank/wallet alert emails picked up by the Gmail sync (no AI: these are fixed
 * templates). Each email yields zero or more items:
 *
 *   ['kind' => 'transaction', 'bank' => 'hdfc', 'account_last4' => '1453', 'transaction_type' => 'debit',
 *    'amount' => 250.0, 'counterparty' => 'RAMESH K', 'vpa' => 'ramesh@ybl', 'date' => '2026-10-02',
 *    'upi_ref' => '412345678901']
 *   ['kind' => 'balance', 'bank' => 'hdfc', 'account_last4' => '1453', 'balance' => 12345.67, 'as_of' => '2026-10-02']
 *
 * Pluxee (meal card) items carry 'wallet' => 'PLUXEE' instead of bank + account_last4.
 *
 * Templates (Oct 2026):
 *  HDFC "You have done a UPI txn":   "Rs.250.00 is debited from your account ending 1453 towards VPA x@y (NAME)
 *                                     on 02-10-26. UPI transaction reference no.: 412345678901."
 *  HDFC "Account update" (credit):   "Rs.500.00 has been successfully credited to your HDFC Bank account ending in
 *                                     1453. ... Date: 02-10-26 ... Sender: NAME (VPA: x@y) ... UPI Reference No.: N"
 *  HDFC "Account update" (balance):  "The available balance in your account ending XX1453 is Rs. INR 12,345.67 as
 *                                     of 02-OCT-26."  (Other "Account update" mails — login notices — yield nothing.)
 *  Pluxee spend:  "You've made a payment of ₹341.00 at SWIGGY . ... Receipt ID: 123456 ... Updated Account Balance ₹1,234.56"
 *  Pluxee load:   "...loaded with ₹2200 towards Meal Card Wallet on Fri Sep 26 2026 10:00:00. Your current Meal Card
 *                  Wallet Balance is Rs.2,345.00"
 */
class AlertEmailParsers
{
    private const AMOUNT = '([\d,]+(?:\.\d{1,2})?)';
    private const CURRENCY = '(?:₹|Rs\.?|INR)\s*(?:INR\s*)?';

    public static function parse(string $from, string $subject, string $text): array
    {
        $flat = self::flatten($text);
        $sender = strtolower($from);

        if (str_contains($sender, 'hdfcbank')) {
            return self::parseHdfc($flat);
        }
        if (str_contains($sender, 'pluxee')) {
            return self::parsePluxee($flat);
        }
        return [];
    }

    /** HTML or plain text → one line of single-spaced text (templates wrap and indent unpredictably). */
    public static function flatten(string $text): string
    {
        if (preg_match('/<\s*(html|body|div|table|p|br)\b/i', $text)) {
            $text = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $text) ?? $text;
            $text = preg_replace('/<br\s*\/?>|<\/(p|div|tr|td|li|h\d)>/i', ' ', $text) ?? $text;
            $text = strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\u{2019}"], [' ', "'"], $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function amount(string $raw): float
    {
        return round((float)str_replace(',', '', $raw), 2);
    }

    private static function parseHdfc(string $t): array
    {
        $items = [];
        $a = self::AMOUNT;
        $c = self::CURRENCY;

        // UPI debit.
        if (preg_match("/{$c}{$a} (?:is|has been) debited from (?:your )?account (?:ending |\*+)?(\d{4}) (?:towards|to) (?:VPA )?(\S+?)(?: \(([^)]*)\))? on (\d{2}-\d{2}-\d{2})/i", $t, $m)) {
            $items[] = [
                'kind' => 'transaction',
                'bank' => 'hdfc',
                'account_last4' => $m[2],
                'transaction_type' => 'debit',
                'amount' => self::amount($m[1]),
                'counterparty' => trim($m[4] ?? '') !== '' ? trim($m[4]) : $m[3],
                'vpa' => $m[3],
                'date' => self::ddmmyy($m[5]),
                'upi_ref' => self::match('/reference (?:no\.?|number)\s*:?\s*(\d{12})/i', $t),
            ];
        }

        // UPI credit.
        if (preg_match("/{$c}{$a} has been (?:successfully )?credited to your HDFC Bank account ending (?:in )?(\d{4})/i", $t, $m)) {
            $sender = null;
            $vpa = null;
            if (preg_match('/Sender: (.+?) \(VPA: ([^)\s]+)\)/i', $t, $s)) {
                $sender = trim($s[1]);
                $vpa = $s[2];
            }
            $date = self::match('/Date: (\d{2}-\d{2}-\d{2})/i', $t);
            $items[] = [
                'kind' => 'transaction',
                'bank' => 'hdfc',
                'account_last4' => $m[2],
                'transaction_type' => 'credit',
                'amount' => self::amount($m[1]),
                'counterparty' => $sender ?? $vpa,
                'vpa' => $vpa,
                'date' => $date !== null ? self::ddmmyy($date) : null,
                'upi_ref' => self::match('/UPI Reference No\.?\s*:?\s*(\d{12})/i', $t),
            ];
        }

        // Daily available balance.
        if (preg_match("/available balance in your account ending X*(\d{4}) is {$c}{$a} as of (\d{2}-[A-Za-z]{3}-\d{2,4})/i", $t, $m)) {
            $items[] = [
                'kind' => 'balance',
                'bank' => 'hdfc',
                'account_last4' => $m[1],
                'balance' => self::amount($m[2]),
                'as_of' => self::ddMonYy($m[3]),
            ];
        }

        return $items;
    }

    private static function parsePluxee(string $t): array
    {
        $items = [];
        $a = self::AMOUNT;
        $c = self::CURRENCY;

        if (preg_match("/payment of {$c}{$a} at (.+?) ?\. Please/i", $t, $m)) {
            $date = null;
            if (preg_match('/(\d{1,2}):(\d{2}), ?(\d{1,2}) ([A-Za-z]{3})\b/', $t, $d)) {
                $date = self::dayMonth((int)$d[3], $d[4]) . sprintf(' %02d:%s:00', (int)$d[1], $d[2]);
            }
            $items[] = [
                'kind' => 'transaction',
                'wallet' => 'PLUXEE',
                'transaction_type' => 'debit',
                'amount' => self::amount($m[1]),
                'counterparty' => trim($m[2]),
                'date' => $date,
                'reference' => self::match('/Receipt ID: ?(\d+)/i', $t),
            ];
            $balance = self::match("/Updated Account Balance {$c}{$a}/i", $t);
            if ($balance !== null) {
                $items[] = ['kind' => 'balance', 'wallet' => 'PLUXEE', 'balance' => self::amount($balance), 'as_of' => $date];
            }
        }

        if (preg_match("/loaded with {$c}{$a} towards Meal Card Wallet on \w{3} (\w{3}) (\d{1,2}) (\d{4}) (\d{2}:\d{2}:\d{2})/i", $t, $m)) {
            $date = sprintf('%s-%s-%02d %s', $m[4], self::monthNumber($m[2]), (int)$m[3], $m[5]);
            $items[] = [
                'kind' => 'transaction',
                'wallet' => 'PLUXEE',
                'transaction_type' => 'credit',
                'amount' => self::amount($m[1]),
                'counterparty' => 'Pluxee meal allowance',
                'date' => $date,
                'reference' => null,
            ];
            $balance = self::match("/Wallet Balance is {$c}{$a}/i", $t);
            if ($balance !== null) {
                $items[] = ['kind' => 'balance', 'wallet' => 'PLUXEE', 'balance' => self::amount($balance), 'as_of' => $date];
            }
        }

        return $items;
    }

    private static function match(string $re, string $t): ?string
    {
        return preg_match($re, $t, $m) ? $m[1] : null;
    }

    /** "02-10-26" → "2026-10-02" */
    private static function ddmmyy(string $v): string
    {
        [$d, $m, $y] = explode('-', $v);
        return sprintf('20%s-%s-%s', $y, $m, $d);
    }

    /** "02-OCT-26" / "02-Oct-2026" → "2026-10-02" */
    private static function ddMonYy(string $v): string
    {
        [$d, $mon, $y] = explode('-', $v);
        return sprintf('%s-%s-%s', strlen($y) === 2 ? '20' . $y : $y, self::monthNumber($mon), $d);
    }

    /** A "26 Sep" with no year: this year, or last year if that would be in the future. */
    private static function dayMonth(int $day, string $mon): string
    {
        $year = (int)date('Y');
        $candidate = sprintf('%d-%s-%02d', $year, self::monthNumber($mon), $day);
        if (strtotime($candidate) > strtotime('+1 day')) {
            $candidate = sprintf('%d-%s-%02d', $year - 1, self::monthNumber($mon), $day);
        }
        return $candidate;
    }

    private static function monthNumber(string $mon): string
    {
        $n = (int)date('n', strtotime('1 ' . $mon . ' 2000'));
        return sprintf('%02d', $n);
    }
}
