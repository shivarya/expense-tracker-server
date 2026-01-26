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

            // Calculate date range
            $startDate = $this->getStartDate($period);

            // Get database (PDO) connection and detect optional columns
            $db = getDB()->getConnection();

            // Detect if `status` column exists (some older DBs don't have it)
            try {
                $colStmt = $db->prepare("SHOW COLUMNS FROM transactions LIKE 'status'");
                $colStmt->execute();
                $hasStatus = (bool) $colStmt->fetch();
            } catch (Exception $e) {
                $hasStatus = false;
            }
            $statusClause = $hasStatus ? "AND status IN ('completed', 'pending')" : "";

            // Get total expenses and income
            $sql = "
                SELECT 
                    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_expenses,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_income
                FROM transactions
                WHERE user_id = :user_id 
                AND transaction_date >= :start_date
                " . $statusClause . "
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':start_date' => $startDate
            ]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get category breakdown (only debits/expenses)
            // If `categories` table/column doesn't exist in this DB, fall back to an "Uncategorized" aggregate
            try {
                $catColStmt = $db->prepare("SHOW COLUMNS FROM categories LIKE 'category_name'");
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
                        COALESCE(c.category_name, 'Uncategorized') as category,
                        SUM(t.amount) as amount,
                        ROUND((SUM(t.amount) / :total_expenses * 100), 2) as percentage
                    FROM transactions t
                    LEFT JOIN categories c ON t.category_id = c.id
                    WHERE t.user_id = :user_id 
                    AND t.transaction_date >= :start_date
                    AND t.transaction_type = 'debit'
                    " . $statusClause . "
                    GROUP BY COALESCE(c.id, 0), COALESCE(c.category_name, 'Uncategorized')
                    ORDER BY amount DESC
                    LIMIT 10
                ";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':start_date' => $startDate,
                    ':total_expenses' => $totalExpenses
                ]);
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
                    " . $statusClause . "
                    LIMIT 10
                ";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':start_date' => $startDate,
                    ':total_expenses' => $totalExpenses
                ]);
            }

            $categoryBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $categoryBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get monthly trends
            $sql = "
                SELECT 
                    DATE_FORMAT(transaction_date, '%Y-%m') as month,
                    SUM(amount) as total,
                    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debit,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credit
                FROM transactions
                WHERE user_id = :user_id 
                AND transaction_date >= :start_date
                " . $statusClause . "
                GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
                ORDER BY month ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':start_date' => $startDate
            ]);
            $monthlyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate net savings
            $netSavings = ($totals['total_income'] ?? 0) - ($totals['total_expenses'] ?? 0);

            Response::success([
                'total_expenses' => (float)($totals['total_expenses'] ?? 0),
                'total_income' => (float)($totals['total_income'] ?? 0),
                'net_savings' => (float)$netSavings,
                'by_category' => array_map(function($cat) {
                    return [
                        'category' => $cat['category'] ?? 'Uncategorized',
                        'amount' => (float)$cat['amount'],
                        'percentage' => (float)$cat['percentage']
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
                'start_date' => $startDate
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
