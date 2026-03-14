<?php
require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/jwt.php';

class ExpenseAnalyticsController {
    /**
     * Get expense summary with category breakdown and monthly trends
     * GET /api/expenses/summary?period=3m|6m|1y
     */
    public function getSummary() {
        try {
            // Require authentication
            $tokenData = JWTHandler::requireAuth();
            $userId = $tokenData['userId'];
            $period = $_GET['period'] ?? '6m';
            $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

            // Calculate date range
            $startDate = $this->getStartDate($period);

            // Get database (PDO) connection and detect optional columns
            $db = getDB()->getConnection();

            if ($groupId) {
                $groupStmt = $db->prepare("SELECT id FROM transaction_groups WHERE id = :group_id AND user_id = :user_id");
                $groupStmt->execute([
                    ':group_id' => $groupId,
                    ':user_id' => $userId,
                ]);

                if (!$groupStmt->fetch(PDO::FETCH_ASSOC)) {
                    Response::error('Invalid group_id', 422);
                }
            }

            // Detect if `status` column exists (some older DBs don't have it)
            try {
                $colStmt = $db->prepare("SHOW COLUMNS FROM transactions LIKE 'status'");
                $colStmt->execute();
                $hasStatus = (bool) $colStmt->fetch();
            } catch (Exception $e) {
                $hasStatus = false;
            }
            $statusClause = $hasStatus ? "AND t.status IN ('completed', 'pending')" : "";

            // Keep analytics in sync with transaction listing by ignoring soft-deleted rows.
            try {
                $deletedColStmt = $db->prepare("SHOW COLUMNS FROM transactions LIKE 'deleted_at'");
                $deletedColStmt->execute();
                $hasDeletedAt = (bool) $deletedColStmt->fetch();
            } catch (Exception $e) {
                $hasDeletedAt = false;
            }
            $deletedClause = $hasDeletedAt ? "AND t.deleted_at IS NULL" : "";

                        $groupClause = '';
                        if ($groupId) {
                                $groupClause = "
                                        AND EXISTS (
                                                SELECT 1
                                                FROM transaction_group_rules r
                                                WHERE r.group_id = :group_id
                                                    AND (
                                                        (r.rule_type = 'category_id' AND t.category_id = CAST(r.rule_value AS UNSIGNED))
                                                        OR (r.rule_type = 'account_id' AND t.account_id = CAST(r.rule_value AS UNSIGNED))
                                                        OR (
                                                            r.rule_type = 'account_type'
                                                            AND EXISTS (
                                                                    SELECT 1 FROM bank_accounts b2
                                                                    WHERE b2.id = t.account_id
                                                                        AND b2.account_type = r.rule_value
                                                            )
                                                        )
                                                        OR (
                                                            r.rule_type = 'payment_method_keyword'
                                                            AND COALESCE(t.payment_method, '') LIKE CONCAT('%', r.rule_value, '%')
                                                        )
                                                        OR (
                                                            r.rule_type = 'merchant_keyword'
                                                            AND (
                                                                    COALESCE(t.merchant, '') LIKE CONCAT('%', r.rule_value, '%')
                                                                    OR COALESCE(t.description, '') LIKE CONCAT('%', r.rule_value, '%')
                                                            )
                                                        )
                                                        OR (r.rule_type = 'transaction_type' AND t.transaction_type = r.rule_value)
                                                    )
                                        )
                                ";
                        }

            // Get total expenses and income
            $sql = "
                SELECT 
                                        SUM(CASE WHEN t.transaction_type = 'debit' THEN t.amount ELSE 0 END) as total_expenses,
                                        SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END) as total_income
                                FROM transactions t
                                WHERE t.user_id = :user_id 
                                AND t.transaction_date >= :start_date
                " . $deletedClause . "
                " . $statusClause . "
                                " . $groupClause . "
            ";
            $stmt = $db->prepare($sql);
                        $totalParams = [
                ':user_id' => $userId,
                ':start_date' => $startDate
                        ];
                        if ($groupId) {
                                $totalParams[':group_id'] = $groupId;
                        }
                        $stmt->execute($totalParams);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get category breakdown (only debits/expenses)
            // If `categories` table/column doesn't exist in this DB, fall back to an "Uncategorized" aggregate
            try {
                $catColStmt = $db->prepare("SHOW COLUMNS FROM categories LIKE 'name'");
                $catColStmt->execute();
                $hasCategoryName = (bool) $catColStmt->fetch();
            } catch (Exception $e) {
                $hasCategoryName = false;
            }

            $totalExpenses = floatval($totals['total_expenses'] ?? 0);
            if ($totalExpenses == 0) {
                $totalExpenses = 1; // Avoid division by zero
            }

            if ($hasCategoryName) {
                $sql = "
                    SELECT 
                        COALESCE(c.id, 0) as category_id,
                        COALESCE(c.name, 'Uncategorized') as category,
                        COALESCE(c.color, '#9E9E9E') as color,
                        COALESCE(c.icon, 'help-circle-outline') as icon,
                        SUM(t.amount) as amount,
                        ROUND((SUM(t.amount) / :total_expenses * 100), 2) as percentage,
                        COUNT(*) as transaction_count
                    FROM transactions t
                    LEFT JOIN categories c ON t.category_id = c.id
                    WHERE t.user_id = :user_id 
                    AND t.transaction_date >= :start_date
                    AND t.transaction_type = 'debit'
                    " . $deletedClause . "
                    " . $statusClause . "
                    " . $groupClause . "
                    GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Uncategorized'), COALESCE(c.color, '#9E9E9E'), COALESCE(c.icon, 'help-circle-outline')
                    ORDER BY amount DESC
                    LIMIT 10
                ";

                $stmt = $db->prepare($sql);
                $categoryParams = [
                    ':user_id' => $userId,
                    ':start_date' => $startDate,
                    ':total_expenses' => $totalExpenses
                ];
                if ($groupId) {
                    $categoryParams[':group_id'] = $groupId;
                }
                $stmt->execute($categoryParams);
            } else {
                // Fallback for DBs without a categories table
                $sql = "
                    SELECT 
                        'Uncategorized' as category,
                        SUM(t.amount) as amount,
                        ROUND((SUM(t.amount) / :total_expenses * 100), 2) as percentage
                    FROM transactions t
                    WHERE t.user_id = :user_id
                    AND t.transaction_date >= :start_date
                    AND t.transaction_type = 'debit'
                    " . $deletedClause . "
                    " . $statusClause . "
                    " . $groupClause . "
                    LIMIT 10
                ";

                $stmt = $db->prepare($sql);
                $categoryParams = [
                    ':user_id' => $userId,
                    ':start_date' => $startDate,
                    ':total_expenses' => $totalExpenses
                ];
                if ($groupId) {
                    $categoryParams[':group_id'] = $groupId;
                }
                $stmt->execute($categoryParams);
            }

            $categoryBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get monthly trends
            $sql = "
                SELECT 
                    DATE_FORMAT(t.transaction_date, '%Y-%m') as month,
                    SUM(t.amount) as total,
                    SUM(CASE WHEN t.transaction_type = 'debit' THEN t.amount ELSE 0 END) as debit,
                    SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END) as credit
                FROM transactions t
                WHERE t.user_id = :user_id 
                AND t.transaction_date >= :start_date
                " . $deletedClause . "
                " . $statusClause . "
                " . $groupClause . "
                GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $trendParams = [
                ':user_id' => $userId,
                ':start_date' => $startDate
            ];
            if ($groupId) {
                $trendParams[':group_id'] = $groupId;
            }
            $stmt->execute($trendParams);
            $monthlyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate net savings
            $netSavings = ($totals['total_income'] ?? 0) - ($totals['total_expenses'] ?? 0);

            Response::success([
                'total_expenses' => (float)($totals['total_expenses'] ?? 0),
                'total_income' => (float)($totals['total_income'] ?? 0),
                'net_savings' => (float)$netSavings,
                'by_category' => array_map(function($cat) {
                    return [
                        'category_id' => isset($cat['category_id']) ? (int)$cat['category_id'] : null,
                        'category' => $cat['category'] ?? 'Uncategorized',
                        'color' => $cat['color'] ?? '#9E9E9E',
                        'icon' => $cat['icon'] ?? 'help-circle-outline',
                        'amount' => (float)$cat['amount'],
                        'percentage' => (float)$cat['percentage'],
                        'transaction_count' => (int)($cat['transaction_count'] ?? 0)
                    ];
                }, $categoryBreakdown),
                'monthly_trends' => array_map(function($trend) {
                    return [
                        'month' => $trend['month'],
                        'total' => (float)$trend['total'],
                        'debit' => (float)$trend['debit'],
                        'credit' => (float)$trend['credit']
                    ];
                }, $monthlyTrends),
                'period' => $period,
                'start_date' => $startDate,
                'group_id' => $groupId,
            ]);

        } catch (PDOException $e) {
            error_log("ExpenseAnalytics Error: " . $e->getMessage());
            Response::error('Failed to fetch expense analytics: ' . $e->getMessage(), 500);
        } catch (Exception $e) {
            error_log("ExpenseAnalytics General Error: " . $e->getMessage());
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
}
