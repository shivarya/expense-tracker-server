<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Foreign-currency → INR conversion with a DB-backed daily-rate cache.
 *
 * The home currency for this app is INR: `transactions.amount` is always stored
 * in INR. This helper converts a foreign amount to INR using the reference rate
 * for a specific date (the transaction date), caching each (currency, date) rate
 * in `fx_rates` so the upstream API is hit at most once per rate.
 *
 * Rate source: Frankfurter (https://frankfurter.dev) — free, keyless, ECB
 * reference rates, historical-by-date. For weekends/holidays it returns the most
 * recent prior business day, which is the desired behaviour.
 *
 * Fail-soft: if the rate cannot be obtained the original amount is returned with
 * rate=null so a transaction insert is never blocked by an FX outage.
 *
 * @return array{inr: float, rate: float|null, currency: string, rate_date: string}
 */
function fxConvertToInr(float $amount, ?string $currency, ?string $date = null): array
{
    $currency = strtoupper(trim((string)$currency));
    if ($currency === '' || $currency === 'INR') {
        return ['inr' => round($amount, 2), 'rate' => 1.0, 'currency' => 'INR', 'rate_date' => (string)$date];
    }

    $rateDate = fxNormalizeDate($date);
    $rate = fxGetRate($currency, 'INR', $rateDate);

    if ($rate === null) {
        error_log("[fx] Could not resolve {$currency}->INR for {$rateDate}; storing raw amount.");
        return ['inr' => round($amount, 2), 'rate' => null, 'currency' => $currency, 'rate_date' => $rateDate];
    }

    return ['inr' => round($amount * $rate, 2), 'rate' => $rate, 'currency' => $currency, 'rate_date' => $rateDate];
}

/**
 * Resolve a base->quote rate for a date, using the fx_rates cache first and the
 * Frankfurter API on a miss. Returns null when unavailable.
 */
function fxGetRate(string $base, string $quote, string $rateDate): ?float
{
    $base = strtoupper($base);
    $quote = strtoupper($quote);
    if ($base === $quote) {
        return 1.0;
    }

    $db = getDB();

    // 1) cache lookup
    try {
        $row = $db->fetchOne(
            "SELECT rate FROM fx_rates WHERE base = ? AND quote = ? AND rate_date = ? LIMIT 1",
            [$base, $quote, $rateDate]
        );
        if ($row && isset($row['rate'])) {
            return (float)$row['rate'];
        }
    } catch (Throwable $e) {
        // fx_rates table may not exist yet (pre-migration) — fall through to API.
        error_log('[fx] cache read failed: ' . $e->getMessage());
    }

    // 2) API fetch
    $fetched = fxFetchFromApi($base, $quote, $rateDate);
    if ($fetched === null) {
        return null;
    }
    [$rate, $effectiveDate] = $fetched;

    // 3) cache write (store under the requested date so repeat lookups hit;
    //    weekend requests resolve to the same prior-business-day rate).
    try {
        $db->query(
            "INSERT INTO fx_rates (base, quote, rate_date, rate, source)
             VALUES (?, ?, ?, ?, 'frankfurter')
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)",
            [$base, $quote, $rateDate, $rate]
        );
    } catch (Throwable $e) {
        error_log('[fx] cache write failed: ' . $e->getMessage());
    }

    return $rate;
}

/**
 * Call Frankfurter for a historical rate. Returns [rate, effectiveDate] or null.
 */
function fxFetchFromApi(string $base, string $quote, string $rateDate): ?array
{
    $url = sprintf(
        'https://api.frankfurter.dev/v1/%s?base=%s&symbols=%s',
        rawurlencode($rateDate),
        rawurlencode($base),
        rawurlencode($quote)
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'expense-tracker-fx/1.0');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("[fx] API HTTP $httpCode for $url");
        return null;
    }

    $data = json_decode($response, true);
    $rate = $data['rates'][$quote] ?? null;
    if ($rate === null || !is_numeric($rate)) {
        error_log("[fx] API response missing {$quote} rate: $response");
        return null;
    }

    return [(float)$rate, (string)($data['date'] ?? $rateDate)];
}

/**
 * ISO codes we recognise inside statement / card descriptions. Deliberately a
 * closed list so country codes (e.g. "MYS") and noise never match.
 */
function fxKnownCurrencies(): array
{
    return ['MYR','USD','EUR','GBP','AED','THB','SGD','JPY','AUD','CAD','CHF',
            'HKD','IDR','VND','LKR','NPR','SAR','QAR','OMR','BHD','KWD','CNY',
            'KRW','NZD','ZAR','TRY','PHP','TWD','MVR','BDT','EGP'];
}

/**
 * Extract a foreign-currency figure embedded in a card/statement description,
 * e.g. "IBIS KLCC ... MYS( MYR 5.80 )" or "U MOBILE ... 22 MYR" or "USD 12.50".
 *
 * @return array{amount: float, currency: string}|null
 */
function fxExtractForeign(?string $text): ?array
{
    $text = (string)$text;
    if ($text === '') {
        return null;
    }

    foreach (fxKnownCurrencies() as $code) {
        // CODE before the number: "MYR 5.80", "MYR5.80"
        if (preg_match('/\b' . $code . '\s*([0-9][0-9,]*(?:\.[0-9]+)?)/', $text, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) {
                return ['amount' => round($amt, 2), 'currency' => $code];
            }
        }
        // number before CODE: "22 MYR", "12.50USD"
        if (preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)\s*' . $code . '\b/', $text, $m)) {
            $amt = (float)str_replace(',', '', $m[1]);
            if ($amt > 0) {
                return ['amount' => round($amt, 2), 'currency' => $code];
            }
        }
    }

    return null;
}

/**
 * Coerce an arbitrary date/datetime string to YYYY-MM-DD, defaulting to today
 * and clamping future dates to today (no forward rates exist).
 */
function fxNormalizeDate(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    if ($ts === false) {
        $ts = time();
    }
    if ($ts > time()) {
        $ts = time();
    }
    return date('Y-m-d', $ts);
}
