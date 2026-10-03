<?php

require_once __DIR__ . '/merchantPattern.php';
require_once __DIR__ . '/paymentApps.php';

/**
 * CrossSourceMerger
 *
 * One real payment is often reported by several sources: the payment app's
 * notification (PhonePe, Google Pay, ...), the bank's SMS, sometimes the bank app's
 * own notification too. Whichever report arrives first is inserted; every later
 * report of the same payment is merged into that row instead of creating another.
 *
 * TransactionDuplicateDetector can't do this: it scores on merchant text, and a
 * payment app ("Paid to Ramesh Kumar") and a bank SMS ("UPI/P2A/ramesh@ybl") name
 * the payee differently, so it flags the pair but still inserts both.
 *
 * Each row records who reported it in source_data.evidence[] — one entry per
 * report with a "family" ('bank' for SMS/email, 'app:<package>' for a notification)
 * and the report's hash. That gives:
 *  - replay detection: the same SMS/notification sent again (the daily inbox sync
 *    re-sends every real-time SMS) is recognised by its hash, even on a deleted row;
 *  - merging: a report from a family the row hasn't heard from yet joins it when the
 *    UPI reference matches, or else same type, same amount, within WINDOW_SECONDS
 *    (the closest such row wins, so two same-amount payments pair up in order).
 * Bank-vs-bank pairs are left to TransactionDuplicateDetector as before.
 *
 * Incoming shape (built by the caller):
 *   family, source, package, transaction_type, amount, at ('Y-m-d H:i:s', the
 *   device-side event time), upi_ref, hash, instrument, account_type,
 *   merchant, reference_number, payment_method, evidence (the entry to append)
 */
class CrossSourceMerger
{
    public const WINDOW_SECONDS = 900;
    private const SEARCH_SECONDS = 86400;
    private const BANK_FAMILY = 'bank';

    public static function familyOf(string $source, ?string $package = null): string
    {
        if ($source === 'app_notification') {
            return 'app:' . (($package !== null && $package !== '') ? $package : '?');
        }
        return self::BANK_FAMILY;
    }

    public static function isAppFamily(string $family): bool
    {
        return str_starts_with($family, 'app:');
    }

    /**
     * Look up the existing row this report belongs to.
     *
     * @return array{action: string, row: array, reason?: string}|null
     *         action 'replay' = this exact report was already stored; 'merge' = fold into row.
     */
    public static function findTwin(PDO $pdo, int $userId, array $in): ?array
    {
        $at = strtotime((string)$in['at']);
        if ($at === false || (float)$in['amount'] <= 0) {
            return null;
        }

        $hash = (string)($in['hash'] ?? '');
        $sql = "SELECT t.id, t.source, t.transaction_type, t.amount, t.transaction_date, t.upi_ref,
                       t.reference_number, t.merchant, t.category_id, t.account_id, t.payment_method,
                       t.source_data, t.deleted_at, ba.account_type, ba.bank, ba.account_number
                FROM transactions t
                JOIN bank_accounts ba ON ba.id = t.account_id
                WHERE t.user_id = ?
                  AND t.source IN ('sms', 'sms_webhook', 'email', 'app_notification')
                  AND t.transaction_date BETWEEN ? AND ?
                  AND (
                        (t.deleted_at IS NULL AND ABS(t.amount - ?) <= 0.01)
                        OR (? <> '' AND t.source_data LIKE ?)
                  )
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, t.transaction_date, ?)) ASC
                LIMIT 25";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $userId,
            date('Y-m-d H:i:s', $at - self::SEARCH_SECONDS),
            date('Y-m-d H:i:s', $at + self::SEARCH_SECONDS),
            (float)$in['amount'],
            $hash,
            '%"' . $hash . '"%',
            date('Y-m-d H:i:s', $at),
        ]);

        return self::pickTwin($in, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Pure selection logic (no DB) — see the class doc. Candidates are transactions
     * rows joined with their account's account_type; source_data may be a JSON
     * string or an already-decoded array.
     */
    public static function pickTwin(array $in, array $candidates): ?array
    {
        $inAt = strtotime((string)$in['at']);
        $hash = (string)($in['hash'] ?? '');
        $best = null;
        $bestGap = PHP_INT_MAX;

        foreach ($candidates as $c) {
            $evidence = self::evidenceList($c);

            if ($hash !== '' && in_array($hash, array_column($evidence, 'hash'), true)) {
                return ['action' => 'replay', 'row' => $c, 'reason' => 'same_report'];
            }

            if (!empty($c['deleted_at'])) {
                continue;
            }
            if (strtolower((string)$c['transaction_type']) !== $in['transaction_type']) {
                continue;
            }
            if (abs((float)$c['amount'] - (float)$in['amount']) > 0.01) {
                continue;
            }

            $families = self::familiesOf($c, $evidence);
            if (in_array($in['family'], $families, true)) {
                continue; // this source already reported this row: a different payment
            }
            $rowHasApp = (bool)array_filter($families, [self::class, 'isAppFamily']);
            if (!self::isAppFamily($in['family']) && !$rowHasApp) {
                continue; // bank vs bank — TransactionDuplicateDetector's job
            }

            $rowRef = (string)($c['upi_ref'] ?? '');
            $inRef = (string)($in['upi_ref'] ?? '');
            if ($rowRef !== '' && $inRef !== '') {
                if ($rowRef === $inRef) {
                    return ['action' => 'merge', 'row' => $c, 'reason' => 'upi_ref'];
                }
                continue;
            }

            if (!self::cardCompatible($in, $c, $families, $evidence)) {
                continue;
            }

            $gap = abs(strtotime((string)$c['transaction_date']) - $inAt);
            if ($gap <= self::WINDOW_SECONDS && $gap < $bestGap) {
                $best = $c;
                $bestGap = $gap;
            }
        }

        return $best !== null ? ['action' => 'merge', 'row' => $best, 'reason' => 'amount_time'] : null;
    }

    /**
     * Fold the incoming report into the kept row: link the real bank account if the
     * row is still on a synthetic UPI/wallet account, fill missing references, take
     * the clearer payee name and the earliest precise time, and record the evidence.
     */
    public static function merge(PDO $pdo, int $userId, array $row, array $in, ?int $incomingAccountId = null, ?int $incomingCategoryId = null): void
    {
        $sets = [];
        $params = [];

        if ($incomingAccountId !== null && $incomingAccountId !== (int)$row['account_id'] && self::isSyntheticAccount($row)) {
            $sets[] = 'account_id = ?';
            $params[] = $incomingAccountId;
        }

        $inMerchant = trim((string)($in['merchant'] ?? ''));
        if ($inMerchant !== '' && self::isWeakMerchant((string)($row['merchant'] ?? '')) && !self::isWeakMerchant($inMerchant)) {
            $sets[] = 'merchant = ?';
            $params[] = mb_substr($inMerchant, 0, 500);
        }

        if ((int)$row['category_id'] === 18 && $incomingCategoryId !== null && $incomingCategoryId !== 18) {
            $sets[] = 'category_id = ?';
            $params[] = $incomingCategoryId;
        }

        foreach (['reference_number', 'upi_ref', 'payment_method'] as $field) {
            $value = trim((string)($in[$field] ?? ''));
            if ($value !== '' && trim((string)($row[$field] ?? '')) === '') {
                $sets[] = "$field = ?";
                $params[] = $value;
            }
        }

        $rowAt = (string)$row['transaction_date'];
        $inAt = (string)$in['at'];
        $rowMidnight = substr($rowAt, 11, 8) === '00:00:00';
        $inMidnight = substr($inAt, 11, 8) === '00:00:00';
        if (!$inMidnight && ($rowMidnight || strtotime($inAt) < strtotime($rowAt))) {
            $sets[] = 'transaction_date = ?';
            $params[] = $inAt;
        }

        $sourceData = self::decodeSourceData($row['source_data'] ?? null);
        $evidence = self::evidenceList($row);
        if (empty($sourceData['evidence'])) {
            // A row stored before evidence existed: record its original reporter first.
            $evidence = [['family' => self::familyOf((string)$row['source']), 'source' => (string)$row['source'], 'legacy' => true]];
        }
        $evidence[] = $in['evidence'];
        $sourceData['evidence'] = $evidence;
        $sets[] = 'source_data = ?';
        $params[] = json_encode($sourceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $params[] = (int)$row['id'];
        $params[] = $userId;
        $pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?')
            ->execute($params);
    }

    /** source_data for a newly inserted row: just its own evidence entry. */
    public static function initialSourceData(array $in): string
    {
        return json_encode(['evidence' => [$in['evidence']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A row on the "UPI (unlinked)" or a wallet account, waiting for its bank report. */
    public static function isSyntheticAccount(array $row): bool
    {
        $number = (string)($row['account_number'] ?? '');
        return ($row['bank'] ?? '') === 'other' && ($number === 'UPI' || str_starts_with($number, 'WALLET-'));
    }

    /** Empty, a bare VPA, or only generic tokens ("UPI", "UPI transfer", "IMPS"...). */
    public static function isWeakMerchant(string $merchant): bool
    {
        $merchant = trim($merchant);
        if ($merchant === '' || preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/i', $merchant)) {
            return true;
        }
        if (preg_match('/^(?:vpa|upi|imps|neft|rtgs)\b/i', $merchant)) {
            return true;
        }
        $pattern = MerchantPattern::normalize($merchant);
        return $pattern === '' || MerchantPattern::isGeneric($pattern);
    }

    /**
     * Don't fold a plain UPI-app notification into a credit-card row (or the reverse):
     * a same-amount card swipe minutes apart is a different payment. Only a card-paid
     * notification (UPI on a RuPay credit card, CRED) may pair with a card account.
     */
    private static function cardCompatible(array $in, array $c, array $families, array $evidence): bool
    {
        if (self::isAppFamily($in['family'])) {
            $rowIsBankCard = ($c['account_type'] ?? '') === 'credit_card' && in_array(self::BANK_FAMILY, $families, true);
            return !$rowIsBankCard || self::appMayUseCard((string)($in['instrument'] ?? ''), (string)($in['package'] ?? ''));
        }

        if (($in['account_type'] ?? '') !== 'credit_card') {
            return true;
        }
        foreach ($evidence as $entry) {
            if (self::isAppFamily((string)($entry['family'] ?? ''))
                && self::appMayUseCard((string)($entry['instrument'] ?? ''), (string)($entry['package'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private static function appMayUseCard(string $instrument, string $package): bool
    {
        return strtolower($instrument) === 'card' || PaymentApps::isCred($package);
    }

    private static function familiesOf(array $row, array $evidence): array
    {
        $families = array_values(array_unique(array_filter(array_map(
            static fn($entry) => (string)($entry['family'] ?? ''),
            $evidence
        ))));
        return $families ?: [self::familyOf((string)($row['source'] ?? ''))];
    }

    private static function evidenceList(array $row): array
    {
        $sourceData = self::decodeSourceData($row['source_data'] ?? null);
        $evidence = $sourceData['evidence'] ?? [];
        return is_array($evidence) ? array_values(array_filter($evidence, 'is_array')) : [];
    }

    private static function decodeSourceData($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
