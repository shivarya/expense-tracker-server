<?php

/**
 * StatementBalanceExtractor
 *
 * Closing balance (+ account last-4 and statement end date) of a savings-account statement PDF's text, for
 * banks whose statements we read only for the balance (IDFC FIRST). Deterministic first — "Closing Balance: X"
 * on one line, or a summary table whose values sit under their column headers (pdftotext -layout keeps the
 * columns) — then the AI as a fallback; an AI answer is accepted only if that number is literally in the text.
 */
class StatementBalanceExtractor
{
    private const AMOUNT = '-?[\d,]+\.\d{2}';

    /** @return array{account_last4: ?string, balance: float, as_of: ?string}|null */
    public static function closingBalance(string $text, ?AzureOpenAI $ai = null): ?array
    {
        if (trim($text) === '') {
            return null;
        }
        $balance = self::inlineClosingBalance($text) ?? self::tabularClosingBalance($text);
        $last4 = self::accountLast4($text);
        $asOf = self::periodEnd($text);

        if (($balance === null || $last4 === null) && $ai !== null) {
            $fromAi = self::askAi($ai, $text);
            if ($fromAi !== null) {
                $balance ??= $fromAi['balance'];
                $last4 ??= $fromAi['account_last4'];
                $asOf ??= $fromAi['as_of'];
            }
        }

        return $balance === null ? null : ['account_last4' => $last4, 'balance' => $balance, 'as_of' => $asOf];
    }

    /** "Closing Balance : 12,345.67" / "Closing Balance (INR) ₹ 12,345.67" — the last occurrence wins. */
    public static function inlineClosingBalance(string $text): ?float
    {
        $a = self::AMOUNT;
        // Same line only ([ \t], not \s): in a summary table the next line's first number is a different column.
        if (preg_match_all("/Closing[ \t]+Bal(?:ance)?\.?[ \t]*(?:\((?:INR|Rs\.?|₹)\))?[ \t]*[:\-]?[ \t]*(?:INR|Rs\.?|₹)?[ \t]*({$a})/iu", $text, $m)) {
            return self::number(end($m[1]));
        }
        return null;
    }

    /**
     * Summary table: a header line containing "Closing Balance" and, on one of the next few lines, amounts laid
     * out under the headers. Pick the amount whose column overlaps the "Closing Balance" header.
     */
    public static function tabularClosingBalance(string $text): ?float
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        foreach ($lines as $i => $line) {
            $col = stripos($line, 'Closing Balance');
            if ($col === false || preg_match('/' . self::AMOUNT . '/', $line)) {
                continue; // not a pure header line (the inline form is handled elsewhere)
            }
            $headerEnd = $col + strlen('Closing Balance');
            for ($j = $i + 1; $j <= min($i + 4, count($lines) - 1); $j++) {
                if (!preg_match_all('/' . self::AMOUNT . '/', $lines[$j], $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $best = null;
                $bestDistance = PHP_INT_MAX;
                foreach ($m[0] as [$value, $offset]) {
                    $end = $offset + strlen($value);
                    $distance = ($end >= $col && $offset <= $headerEnd) ? 0 : min(abs($offset - $headerEnd), abs($end - $col));
                    if ($distance < $bestDistance) {
                        $best = $value;
                        $bestDistance = $distance;
                    }
                }
                if ($best !== null && $bestDistance <= 12) {
                    return self::number($best);
                }
                break;
            }
        }
        return null;
    }

    public static function accountLast4(string $text): ?string
    {
        if (preg_match('/(?:Account|A\/C)\s*(?:No\.?|Number|#)?\s*[:\-]?\s*[X\*]*(\d{4,18})\b/i', $text, $m)) {
            return substr($m[1], -4);
        }
        return null;
    }

    /** End of the statement period: "01-Sep-2026 to 30-Sep-2026", "01/09/2026 - 30/09/2026", "... To 30 Sep 2026". */
    public static function periodEnd(string $text): ?string
    {
        $date = '(\d{1,2}[\-\/ ](?:\d{1,2}|[A-Za-z]{3,9})[\-\/ ,]+\d{2,4})';
        if (preg_match("/{$date}\s*(?:to|-|–)\s*{$date}/i", $text, $m)) {
            $ts = strtotime(str_replace('/', '-', $m[2]));
            return $ts !== false ? date('Y-m-d', $ts) : null;
        }
        return null;
    }

    private static function askAi(AzureOpenAI $ai, string $text): ?array
    {
        $result = $ai->chatCompletion([
            ['role' => 'system', 'content' => 'You read Indian bank savings-account statements. Return ONLY JSON: '
                . '{"account_last4": "", "closing_balance": 0, "statement_end_date": "YYYY-MM-DD"}. '
                . 'closing_balance is the account balance at the end of the statement period exactly as printed (plain number, no commas). '
                . 'Use null for anything not printed.'],
            ['role' => 'user', 'content' => mb_substr($text, 0, 12000)],
        ], 0.0, true);
        if (!is_array($result) || !is_numeric($result['closing_balance'] ?? null)) {
            return null;
        }
        $balance = round((float)$result['closing_balance'], 2);
        // Hallucination guard: the number must be printed in the statement.
        $printed = preg_match_all('/' . self::AMOUNT . '/', $text, $m) ? array_map([self::class, 'number'], $m[0]) : [];
        if (!in_array($balance, $printed, true)) {
            return null;
        }
        $last4 = preg_replace('/\D/', '', (string)($result['account_last4'] ?? '')) ?? '';
        $asOf = (string)($result['statement_end_date'] ?? '');
        return [
            'balance' => $balance,
            'account_last4' => strlen($last4) >= 4 ? substr($last4, -4) : null,
            'as_of' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : null,
        ];
    }

    private static function number(string $raw): float
    {
        return round((float)str_replace(',', '', $raw), 2);
    }
}
