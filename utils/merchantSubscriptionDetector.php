<?php

require_once __DIR__ . '/merchantPattern.php';
require_once __DIR__ . '/cancelUrlMap.php';

/**
 * MerchantSubscriptionDetector
 *
 * Detects recurring merchant charges (Netflix, Spotify, gym, SaaS, insurance
 * premiums, ...) from a user's transaction history, based on merchant-name
 * grouping + amount regularity + interval regularity -- no explicit signal
 * like EMI's bank-supplied installment counter exists for subscriptions, so
 * this is inferred purely from the transactions themselves.
 *
 * evaluateOccurrences() is the single source of truth for "is this a
 * subscription" -- both runBulkScan() (one-time historical scan) and
 * evaluateTransaction() (incremental, called on every new transaction
 * insert) funnel through it, so there is only one detection algorithm, not
 * two divergent ones.
 */
class MerchantSubscriptionDetector
{
    private const TABLE_NAME = 'merchant_subscriptions';

    private const MIN_OCCURRENCES_DEFAULT = 3;
    private const MIN_OCCURRENCES_ANNUAL = 2;
    private const AMOUNT_CV_TOLERANCE = 0.15;
    private const BAND_COVERAGE_THRESHOLD = 0.70;

    // [min_days, max_days] gap between consecutive charges for each cycle.
    private const INTERVAL_BANDS = [
        'weekly' => [6, 8],
        'monthly' => [27, 32],
        'quarterly' => [88, 93],
        'annual' => [350, 380],
    ];

    private const NEXT_DATE_MODIFIER = [
        'weekly' => '+7 days',
        'monthly' => '+1 month',
        'quarterly' => '+3 months',
        'annual' => '+1 year',
    ];

    private static bool $tableEnsured = false;
    private static bool $tableUnavailable = false;

    /**
     * Pure detection logic: given every occurrence of one normalized merchant
     * pattern for one user, decide whether it looks like a subscription.
     *
     * @param array $occurrences [{id:int, amount:float, date:string, category_id:?int}, ...]
     * @return array{billing_cycle:string, average_amount:float, last_amount:float,
     *   amount_variance_percent:float, occurrence_count:int, category_id:?int,
     *   first_transaction_date:string, last_transaction_date:string,
     *   next_expected_date:string, transaction_ids:int[]}|null
     */
    public static function evaluateOccurrences(array $occurrences): ?array
    {
        if (count($occurrences) < 2) {
            return null;
        }

        $rows = array_map(static function ($o) {
            return [
                'id' => (int)$o['id'],
                'amount' => (float)$o['amount'],
                'date' => substr((string)$o['date'], 0, 10),
                'category_id' => isset($o['category_id']) ? (int)$o['category_id'] : null,
            ];
        }, array_values($occurrences));

        usort($rows, static fn($a, $b) => strcmp($a['date'], $b['date']));

        $dates = array_column($rows, 'date');
        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) {
            $gaps[] = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
        }

        $billingCycle = self::bestMatchingBand($gaps);
        if ($billingCycle === null) {
            return null;
        }

        // Annual subscriptions need far fewer confirmed occurrences than
        // monthly ones -- requiring 3 would mean waiting 3 years of history
        // before an insurance premium or domain renewal is ever detected.
        $minOccurrences = $billingCycle === 'annual' ? self::MIN_OCCURRENCES_ANNUAL : self::MIN_OCCURRENCES_DEFAULT;
        if (count($rows) < $minOccurrences) {
            return null;
        }

        $amounts = array_column($rows, 'amount');
        $cv = self::coefficientOfVariation($amounts);
        $varianceOk = $cv <= self::AMOUNT_CV_TOLERANCE;

        // A one-time genuine price change (plan upgrade, tax change) shouldn't
        // permanently block detection -- retry against just the most recent
        // occurrences if the full-history variance is too wide.
        if (!$varianceOk && count($amounts) > 4) {
            $recentAmounts = array_slice($amounts, -4);
            $cv = self::coefficientOfVariation($recentAmounts);
            $varianceOk = $cv <= self::AMOUNT_CV_TOLERANCE;
        }

        if (!$varianceOk) {
            return null;
        }

        $lastRow = end($rows);

        return [
            'billing_cycle' => $billingCycle,
            'average_amount' => round(array_sum($amounts) / count($amounts), 2),
            'last_amount' => round($lastRow['amount'], 2),
            'amount_variance_percent' => round($cv * 100, 2),
            'occurrence_count' => count($rows),
            'category_id' => $lastRow['category_id'],
            'first_transaction_date' => $dates[0],
            'last_transaction_date' => end($dates),
            'next_expected_date' => self::projectNextDate(end($dates), $billingCycle),
            'transaction_ids' => array_column($rows, 'id'),
        ];
    }

    /**
     * One-time historical scan across every merchant the user has ever paid.
     * Backfills transactions.merchant_pattern for EVERY group (qualifying or
     * not) -- this is what lets a merchant with only 2 valid past occurrences
     * today still get picked up correctly the moment a 3rd one arrives via
     * evaluateTransaction() later, without re-scanning full history again.
     */
    public static function runBulkScan(object $db, int $userId): array
    {
        self::ensureTable($db);

        $txns = $db->fetchAll(
            "SELECT id, merchant, amount, transaction_date AS date, category_id
             FROM transactions
             WHERE user_id = ? AND deleted_at IS NULL AND transaction_type = 'debit'
               AND merchant IS NOT NULL AND merchant != ''
             ORDER BY transaction_date ASC",
            [$userId]
        );

        $groups = [];
        foreach ($txns as $txn) {
            $pattern = MerchantPattern::normalize((string)$txn['merchant']);
            if ($pattern === '' || MerchantPattern::isGeneric($pattern)) {
                continue;
            }
            if (!isset($groups[$pattern])) {
                $groups[$pattern] = ['occurrences' => [], 'display_name' => (string)$txn['merchant']];
            }
            $groups[$pattern]['occurrences'][] = [
                'id' => (int)$txn['id'],
                'amount' => (float)$txn['amount'],
                'date' => (string)$txn['date'],
                'category_id' => $txn['category_id'] !== null ? (int)$txn['category_id'] : null,
            ];
            // Most recent raw merchant text wins for display (rows are date-ascending).
            $groups[$pattern]['display_name'] = (string)$txn['merchant'];
        }

        $created = 0;
        $updated = 0;
        $skippedDismissed = 0;

        foreach ($groups as $pattern => $group) {
            $ids = array_column($group['occurrences'], 'id');
            self::backfillMerchantPattern($db, $userId, $pattern, $ids);

            $result = self::evaluateOccurrences($group['occurrences']);
            if ($result === null) {
                continue;
            }

            $outcome = self::upsertSubscription($db, $userId, $pattern, $group['display_name'], $result, 'bulk_scan');
            if ($outcome === 'created') {
                $created++;
            } elseif ($outcome === 'updated') {
                $updated++;
            } elseif ($outcome === 'skipped_dismissed') {
                $skippedDismissed++;
            }
        }

        return [
            'groups_evaluated' => count($groups),
            'created' => $created,
            'updated' => $updated,
            'skipped_dismissed' => $skippedDismissed,
        ];
    }

    /**
     * Incremental check for exactly one newly-inserted transaction. Bounded to
     * that one merchant's history via the indexed merchant_pattern lookup --
     * never rescans the user's full transaction history. Tolerant of any
     * failure (mirrors CategoryLearning::resolveFromTransaction()) so a
     * detector bug can never break the transaction-save flow that calls it.
     */
    public static function evaluateTransaction(object $db, int $userId, int $transactionId): ?array
    {
        try {
            self::ensureTable($db);

            $txn = $db->fetchOne(
                "SELECT id, merchant, transaction_type, deleted_at FROM transactions WHERE id = ? AND user_id = ?",
                [$transactionId, $userId]
            );
            if (!$txn || $txn['transaction_type'] !== 'debit' || $txn['deleted_at'] !== null) {
                return null;
            }

            $pattern = MerchantPattern::normalize((string)($txn['merchant'] ?? ''));
            if ($pattern === '' || MerchantPattern::isGeneric($pattern)) {
                return null;
            }

            $db->execute(
                "UPDATE transactions SET merchant_pattern = ? WHERE id = ? AND user_id = ?",
                [$pattern, $transactionId, $userId]
            );

            $existing = $db->fetchOne(
                "SELECT id, status FROM " . self::TABLE_NAME . " WHERE user_id = ? AND merchant_pattern = ?",
                [$userId, $pattern]
            );
            if ($existing && $existing['status'] === 'dismissed') {
                return null;
            }

            // Bounded to this one merchant only -- served by idx_merchant_pattern,
            // not a scan of the user's whole transaction history.
            $occurrences = $db->fetchAll(
                "SELECT id, amount, transaction_date AS date, category_id
                 FROM transactions
                 WHERE user_id = ? AND merchant_pattern = ? AND transaction_type = 'debit' AND deleted_at IS NULL",
                [$userId, $pattern]
            );

            $result = self::evaluateOccurrences($occurrences);
            if ($result === null) {
                return null;
            }

            $outcome = self::upsertSubscription($db, $userId, $pattern, (string)$txn['merchant'], $result, 'incremental');
            return ['outcome' => $outcome, 'merchant_pattern' => $pattern];
        } catch (Throwable $e) {
            error_log('[MerchantSubscriptionDetector] evaluateTransaction failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clears the subscription link off every transaction currently attached to
     * a row -- called when a subscription is dismissed ("not a subscription"),
     * since a dismissed row means these were never actually a subscription.
     */
    public static function unlinkTransactions(object $db, int $userId, int $subscriptionId): void
    {
        $db->execute(
            "UPDATE transactions SET is_subscription = FALSE, merchant_subscription_id = NULL
             WHERE merchant_subscription_id = ? AND user_id = ?",
            [$subscriptionId, $userId]
        );
    }

    private static function upsertSubscription(
        object $db,
        int $userId,
        string $pattern,
        string $displayName,
        array $result,
        string $detectionSource
    ): string {
        $existing = $db->fetchOne(
            "SELECT * FROM " . self::TABLE_NAME . " WHERE user_id = ? AND merchant_pattern = ?",
            [$userId, $pattern]
        );

        if ($existing && $existing['status'] === 'dismissed') {
            return 'skipped_dismissed';
        }

        if ($existing) {
            $newStatus = $existing['status'];
            $deactivatedAt = $existing['deactivated_at'];

            // A resumed subscription (re-subscribed after cancelling) should
            // reappear as active, but only once a genuinely new charge lands
            // after the deactivation, not from re-processing old history.
            if ($newStatus === 'deactivated'
                && $deactivatedAt !== null
                && strtotime($result['last_transaction_date']) > strtotime($deactivatedAt)) {
                $newStatus = 'active';
                $deactivatedAt = null;
            }

            $db->execute(
                "UPDATE " . self::TABLE_NAME . " SET
                    display_name = ?, category_id = ?, billing_cycle = ?, average_amount = ?, last_amount = ?,
                    amount_variance_percent = ?, occurrence_count = ?, first_transaction_date = ?, last_transaction_date = ?,
                    next_expected_date = ?, status = ?, deactivated_at = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $displayName, $result['category_id'], $result['billing_cycle'], $result['average_amount'], $result['last_amount'],
                    $result['amount_variance_percent'], $result['occurrence_count'], $result['first_transaction_date'], $result['last_transaction_date'],
                    $result['next_expected_date'], $newStatus, $deactivatedAt, (int)$existing['id'],
                ]
            );
            $subscriptionId = (int)$existing['id'];
            $outcome = 'updated';
        } else {
            $cancelUrl = CancelUrlMap::lookup($displayName);
            $subscriptionId = (int)$db->insert(
                "INSERT INTO " . self::TABLE_NAME . "
                    (user_id, merchant_pattern, display_name, category_id, billing_cycle, average_amount, last_amount,
                     amount_variance_percent, occurrence_count, first_transaction_date, last_transaction_date,
                     next_expected_date, status, detection_source, cancel_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
                [
                    $userId, $pattern, $displayName, $result['category_id'], $result['billing_cycle'], $result['average_amount'], $result['last_amount'],
                    $result['amount_variance_percent'], $result['occurrence_count'], $result['first_transaction_date'], $result['last_transaction_date'],
                    $result['next_expected_date'], $detectionSource, $cancelUrl,
                ]
            );
            $outcome = 'created';
        }

        $ids = $result['transaction_ids'];
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $db->execute(
                "UPDATE transactions SET is_subscription = TRUE, merchant_subscription_id = ? WHERE id IN ($in) AND user_id = ?",
                array_merge([$subscriptionId], $ids, [$userId])
            );
        }

        return $outcome;
    }

    private static function backfillMerchantPattern(object $db, int $userId, string $pattern, array $transactionIds): void
    {
        if (empty($transactionIds)) {
            return;
        }
        $in = implode(',', array_fill(0, count($transactionIds), '?'));
        $db->execute(
            "UPDATE transactions SET merchant_pattern = ? WHERE id IN ($in) AND user_id = ?",
            array_merge([$pattern], $transactionIds, [$userId])
        );
    }

    private static function bestMatchingBand(array $gaps): ?string
    {
        if (empty($gaps)) {
            return null;
        }

        $gapCount = count($gaps);
        $best = null;
        $bestMatching = -1;

        foreach (self::INTERVAL_BANDS as $name => [$min, $max]) {
            $matching = 0;
            foreach ($gaps as $gap) {
                if ($gap >= $min && $gap <= $max) {
                    $matching++;
                }
            }

            // A band qualifies at >=70% coverage, OR -- for 4+ occurrences --
            // if at most one gap falls outside it. Real transaction history
            // commonly has exactly one uncaptured cycle (a month before SMS
            // capture was reliable, a missed statement, ...); requiring a
            // strict percentage on only 3-4 samples would let one gap in old,
            // incomplete history permanently block detection of an otherwise
            // clearly regular charge.
            $coverage = $matching / $gapCount;
            $qualifies = $coverage >= self::BAND_COVERAGE_THRESHOLD
                || ($gapCount >= 3 && $matching >= $gapCount - 1);

            if ($qualifies && $matching > $bestMatching) {
                $bestMatching = $matching;
                $best = $name;
            }
        }

        return $best;
    }

    private static function coefficientOfVariation(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 1.0;
        }
        $mean = array_sum($values) / $n;
        if ($mean == 0.0) {
            return 1.0;
        }
        if ($n === 1) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= $n;

        return sqrt($variance) / $mean;
    }

    public static function projectNextDate(string $lastDate, string $cycle): string
    {
        $modifier = self::NEXT_DATE_MODIFIER[$cycle] ?? '+1 month';
        return (new DateTime($lastDate))->modify($modifier)->format('Y-m-d');
    }

    public static function ensureTable(object $db): void
    {
        if (self::$tableEnsured || self::$tableUnavailable) {
            return;
        }

        try {
            $db->execute(
                "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    merchant_pattern VARCHAR(255) NOT NULL,
                    display_name VARCHAR(255) NOT NULL,
                    category_id INT NULL,
                    billing_cycle ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
                    average_amount DECIMAL(15, 2) NOT NULL,
                    last_amount DECIMAL(15, 2) NOT NULL,
                    amount_variance_percent DECIMAL(6, 2) NOT NULL DEFAULT 0,
                    occurrence_count INT NOT NULL DEFAULT 0,
                    first_transaction_date DATE NOT NULL,
                    last_transaction_date DATE NOT NULL,
                    next_expected_date DATE NULL,
                    status ENUM('active','deactivated','dismissed') NOT NULL DEFAULT 'active',
                    detection_source ENUM('bulk_scan','incremental') NOT NULL DEFAULT 'bulk_scan',
                    cancel_url VARCHAR(1000) NULL,
                    notes VARCHAR(500) NULL,
                    dismissed_at TIMESTAMP NULL,
                    deactivated_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                    UNIQUE KEY uniq_user_merchant_pattern (user_id, merchant_pattern),
                    INDEX idx_merchant_subscriptions_user_status (user_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            self::$tableEnsured = true;
        } catch (Exception $e) {
            self::$tableUnavailable = true;
            throw $e;
        }
    }
}
