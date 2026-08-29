<?php
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/jwt.php';
require_once __DIR__ . '/groupController.php';

class ExpenseAnalyticsController {
    /**
     * Get expense summary with category breakdown and monthly trends
     * GET /api/expenses/summary?period=cm|1m|3m|6m|1y
     */
    public function getSummary() {
        try {
            // Require authentication
            $tokenData = JWTHandler::requireAuth();
            $userId = (int)$tokenData['userId'];
            $period = $_GET['period'] ?? '6m';
            $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

            $startDate = $this->getStartDate($period);

            $db = getDB();
            $pdo = $db->getConnection();

            if ($groupId) {
                $group = $db->fetchOne(
                    "SELECT id FROM transaction_groups WHERE id = ? AND user_id = ?",
                    [$groupId, $userId]
                );

                if (!$group) {
                    Response::error('Invalid group_id', 422);
                }
            }

            $hasStatus = $this->tableHasColumn($pdo, 'transactions', 'status');
            $hasDeletedAt = $this->tableHasColumn($pdo, 'transactions', 'deleted_at');
            $hasSplits = $this->tableExists($pdo, 'transaction_splits');
            $hasRefundAllocations = $this->tableExists($pdo, 'transaction_refund_allocations');

            // 1) Collect all debit transactions in scope (group-aware). Excludes
            //    Transfer-type categories (e.g. a credit card bill payment) since
            //    those settle debt already counted via the card's own line items --
            //    counting the payment too would double-count the same spend.
            $debitParams = [$userId, $startDate];
            $debitSql = "SELECT t.id, t.account_id, t.category_id, t.amount, t.transaction_date
                         FROM transactions t
                         JOIN categories c ON c.id = t.category_id
                         WHERE t.user_id = ?
                           AND t.transaction_date >= ?
                           AND t.transaction_type = 'debit'
                           AND c.type != 'transfer'";

            $debitSql .= $this->buildTxnStateClause('t', $hasDeletedAt, $hasStatus);
            $debitSql .= buildGroupFilterSql($groupId, $debitParams, 't');

            $debitTransactions = $db->fetchAll($debitSql, $debitParams);

            // 2) Build effective debit lines (split-aware).
            $effectiveLines = [];
            $debitByMonth = [];
            $categoryStats = [];

            $splitByParent = [];
            if ($hasSplits && !empty($debitTransactions)) {
                $txnIds = array_map(static fn($row) => (int)$row['id'], $debitTransactions);
                $in = implode(',', array_fill(0, count($txnIds), '?'));
                $splitRows = $db->fetchAll(
                    "SELECT s.parent_transaction_id, s.category_id, s.amount
                     FROM transaction_splits s
                     WHERE s.user_id = ?
                       AND s.deleted_at IS NULL
                       AND s.parent_transaction_id IN ($in)
                     ORDER BY s.display_order ASC, s.id ASC",
                    array_merge([$userId], $txnIds)
                );

                foreach ($splitRows as $row) {
                    $parentId = (int)$row['parent_transaction_id'];
                    if (!isset($splitByParent[$parentId])) {
                        $splitByParent[$parentId] = [];
                    }
                    $splitByParent[$parentId][] = [
                        'category_id' => (int)$row['category_id'],
                        'amount' => round((float)$row['amount'], 2),
                    ];
                }
            }

            foreach ($debitTransactions as $txn) {
                $txnId = (int)$txn['id'];
                $month = $this->extractMonth($txn['transaction_date']);

                $splitLines = $splitByParent[$txnId] ?? [];
                if (!empty($splitLines)) {
                    foreach ($splitLines as $line) {
                        $effectiveLines[] = [
                            'category_id' => $line['category_id'],
                            'amount' => $line['amount'],
                            'month' => $month,
                        ];
                    }
                } else {
                    $effectiveLines[] = [
                        'category_id' => (int)$txn['category_id'],
                        'amount' => round((float)$txn['amount'], 2),
                        'month' => $month,
                    ];
                }
            }

            foreach ($effectiveLines as $line) {
                $categoryId = (int)$line['category_id'];
                $amount = round((float)$line['amount'], 2);
                $month = $line['month'];

                if (!isset($categoryStats[$categoryId])) {
                    $categoryStats[$categoryId] = [
                        'amount' => 0.0,
                        'transaction_count' => 0,
                    ];
                }

                $categoryStats[$categoryId]['amount'] += $amount;
                $categoryStats[$categoryId]['transaction_count'] += 1;

                if (!isset($debitByMonth[$month])) {
                    $debitByMonth[$month] = 0.0;
                }
                $debitByMonth[$month] += $amount;
            }

            $grossExpenses = array_sum($debitByMonth);

            // 3) Subtract allocated refunds in the refund month,
            //    targeted by linked expense transaction category/group.
            $refundOffsets = 0.0;
            if ($hasRefundAllocations) {
                $refundParams = [$userId, $userId, $userId, $startDate];
                $refundSql = "SELECT a.amount,
                                     e.category_id as expense_category_id,
                                     r.transaction_date as refund_transaction_date
                              FROM transaction_refund_allocations a
                              JOIN transactions r ON r.id = a.refund_transaction_id
                              JOIN transactions e ON e.id = a.expense_transaction_id
                              WHERE a.user_id = ?
                                AND r.user_id = ?
                                AND e.user_id = ?
                                AND a.deleted_at IS NULL
                                AND r.transaction_type = 'credit'
                                AND e.transaction_type = 'debit'
                                AND r.transaction_date >= ?";

                $refundSql .= $this->buildTxnStateClause('r', $hasDeletedAt, $hasStatus);
                $refundSql .= $this->buildTxnStateClause('e', $hasDeletedAt, $hasStatus);
                $refundSql .= buildGroupFilterSql($groupId, $refundParams, 'e');

                $refundRows = $db->fetchAll($refundSql, $refundParams);

                foreach ($refundRows as $row) {
                    $amount = round((float)$row['amount'], 2);
                    $refundMonth = $this->extractMonth($row['refund_transaction_date']);
                    $expenseCategoryId = (int)$row['expense_category_id'];

                    $refundOffsets += $amount;

                    if (!isset($debitByMonth[$refundMonth])) {
                        $debitByMonth[$refundMonth] = 0.0;
                    }
                    $debitByMonth[$refundMonth] -= $amount;

                    if (!isset($categoryStats[$expenseCategoryId])) {
                        $categoryStats[$expenseCategoryId] = [
                            'amount' => 0.0,
                            'transaction_count' => 0,
                        ];
                    }
                    $categoryStats[$expenseCategoryId]['amount'] -= $amount;
                }
            }

            $netExpenses = $grossExpenses - $refundOffsets;

            // 4) Total income in scope (group-aware, existing behavior).
            $incomeParams = [$userId, $startDate];
            $incomeSql = "SELECT COALESCE(SUM(t.amount), 0) as total_income
                          FROM transactions t
                          WHERE t.user_id = ?
                            AND t.transaction_date >= ?
                            AND t.transaction_type = 'credit'";
            $incomeSql .= $this->buildTxnStateClause('t', $hasDeletedAt, $hasStatus);
            $incomeSql .= buildGroupFilterSql($groupId, $incomeParams, 't');
            $totalIncome = (float)($db->fetchOne($incomeSql, $incomeParams)['total_income'] ?? 0);

            // 5) Monthly credit trends.
            $creditByMonth = [];
            $monthlyCreditParams = [$userId, $startDate];
            $monthlyCreditSql = "SELECT DATE_FORMAT(t.transaction_date, '%Y-%m') as month,
                                        COALESCE(SUM(t.amount), 0) as amount
                                 FROM transactions t
                                 WHERE t.user_id = ?
                                   AND t.transaction_date >= ?
                                   AND t.transaction_type = 'credit'";
            $monthlyCreditSql .= $this->buildTxnStateClause('t', $hasDeletedAt, $hasStatus);
            $monthlyCreditSql .= buildGroupFilterSql($groupId, $monthlyCreditParams, 't');
            $monthlyCreditSql .= " GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
                                   ORDER BY month ASC";

            $creditRows = $db->fetchAll($monthlyCreditSql, $monthlyCreditParams);
            foreach ($creditRows as $row) {
                $creditByMonth[$row['month']] = round((float)$row['amount'], 2);
            }

            // 6) Category metadata and response shaping.
            $categoryMeta = [];
            $categoryIds = array_values(array_filter(array_map('intval', array_keys($categoryStats)), static fn($id) => $id > 0));
            if (!empty($categoryIds)) {
                $in = implode(',', array_fill(0, count($categoryIds), '?'));
                $rows = $db->fetchAll(
                    "SELECT id, name, color, icon FROM categories WHERE id IN ($in)",
                    $categoryIds
                );

                foreach ($rows as $row) {
                    $categoryMeta[(int)$row['id']] = [
                        'name' => $row['name'] ?? 'Uncategorized',
                        'color' => $row['color'] ?? '#9E9E9E',
                        'icon' => $row['icon'] ?? 'help-circle-outline',
                    ];
                }
            }

            $byCategory = [];
            foreach ($categoryStats as $categoryId => $stats) {
                $meta = $categoryMeta[$categoryId] ?? [
                    'name' => 'Uncategorized',
                    'color' => '#9E9E9E',
                    'icon' => 'help-circle-outline',
                ];

                $amount = round((float)$stats['amount'], 2);
                $percentage = (abs($netExpenses) > 0.0001)
                    ? round(($amount / $netExpenses) * 100, 2)
                    : 0.0;

                $byCategory[] = [
                    'category_id' => (int)$categoryId,
                    'category' => $meta['name'],
                    'color' => $meta['color'],
                    'icon' => $meta['icon'],
                    'amount' => $amount,
                    'percentage' => $percentage,
                    'transaction_count' => (int)$stats['transaction_count'],
                ];
            }

            usort($byCategory, static function ($a, $b) {
                return ($b['amount'] <=> $a['amount']);
            });
            $byCategory = array_slice($byCategory, 0, 10);

            // 7) Monthly trends (debit already net of refund allocations).
            $allMonths = array_values(array_unique(array_merge(array_keys($debitByMonth), array_keys($creditByMonth))));
            sort($allMonths);

            $monthlyTrends = [];
            foreach ($allMonths as $month) {
                $debit = round((float)($debitByMonth[$month] ?? 0), 2);
                $credit = round((float)($creditByMonth[$month] ?? 0), 2);
                $monthlyTrends[] = [
                    'month' => $month,
                    'total' => round($debit + $credit, 2),
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            }

            $netSavings = $totalIncome - $netExpenses;

            Response::success([
                'total_expenses' => round($netExpenses, 2),
                'gross_expenses' => round($grossExpenses, 2),
                'refund_offsets' => round($refundOffsets, 2),
                'total_income' => round($totalIncome, 2),
                'net_savings' => round($netSavings, 2),
                'by_category' => $byCategory,
                'monthly_trends' => $monthlyTrends,
                'period' => $period,
                'start_date' => $startDate,
                'group_id' => $groupId,
            ]);
        } catch (PDOException $e) {
            error_log('ExpenseAnalytics Error: ' . $e->getMessage());
            Response::error('Failed to fetch expense analytics: ' . $e->getMessage(), 500);
        } catch (Exception $e) {
            error_log('ExpenseAnalytics General Error: ' . $e->getMessage());
            Response::error('Server error: ' . $e->getMessage(), 500);
        }
    }

    private function getStartDate($period) {
        $now = new DateTime();

        switch ($period) {
            case 'cm':
                return (new DateTime('first day of this month'))->format('Y-m-d');
            case '1m':
                return $now->modify('-1 month')->format('Y-m-d');
            case '3m':
                return $now->modify('-3 months')->format('Y-m-d');
            case '1y':
                return $now->modify('-1 year')->format('Y-m-d');
            case '6m':
            default:
                return $now->modify('-6 months')->format('Y-m-d');
        }
    }

    // NOTE: `SHOW COLUMNS ... LIKE :column` / `SHOW TABLES LIKE :table_name`
    // with a bound parameter throws a syntax error on this MariaDB version
    // ("SHOW ... LIKE" doesn't accept placeholders) -- the try/catch here
    // silently swallowed that, so hasDeletedAt/hasSplits/hasRefundAllocations
    // were ALWAYS false and this endpoint never actually filtered soft-deleted
    // transactions. information_schema queries support real bound params.
    private function tableHasColumn(PDO $db, string $table, string $column): bool {
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column"
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }

    private function tableExists(PDO $db, string $table): bool {
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name"
            );
            $stmt->execute([':table_name' => $table]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }

    private function buildTxnStateClause(string $alias, bool $hasDeletedAt, bool $hasStatus): string {
        $clause = '';
        if ($hasDeletedAt) {
            $clause .= " AND {$alias}.deleted_at IS NULL";
        }
        if ($hasStatus) {
            $clause .= " AND {$alias}.status IN ('completed', 'pending')";
        }
        return $clause;
    }

    private function extractMonth(string $dateTime): string {
        $ts = strtotime($dateTime);
        if ($ts === false) {
            return date('Y-m');
        }
        return date('Y-m', $ts);
    }
}
