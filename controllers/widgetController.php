<?php

function handleWidgetRoutes($uri, $method)
{
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/widget/summary' && $method === 'GET') {
    getWidgetSummary($userId);
    return;
  }

  Response::error('Route not found', 404);
}

function getWidgetSummary($userId)
{
  try {
    $db = getDB()->getConnection();
    $monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
    $nextMonthStart = (new DateTime('first day of next month'))->format('Y-m-d');
    $previousMonthStart = (new DateTime('first day of last month'))->format('Y-m-d');

    $hasStatus = widgetDetectColumn($db, 'transactions', 'status');
    $hasDeletedAt = widgetDetectColumn($db, 'transactions', 'deleted_at');
    $statusClause = $hasStatus ? "AND status IN ('completed', 'pending')" : '';
    $deletedClause = $hasDeletedAt ? 'AND deleted_at IS NULL' : '';

    $monthTotalsStmt = $db->prepare(
      "SELECT
          SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) AS month_spent,
          SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) AS month_income,
          SUM(CASE WHEN transaction_type = 'debit' THEN 1 ELSE 0 END) AS transaction_count
       FROM transactions
       WHERE user_id = :user_id
         AND transaction_date >= :month_start
         AND transaction_date < :next_month_start
         {$deletedClause}
         {$statusClause}"
    );
    $monthTotalsStmt->execute([
      ':user_id' => $userId,
      ':month_start' => $monthStart,
      ':next_month_start' => $nextMonthStart,
    ]);
    $monthTotals = $monthTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $previousSpendStmt = $db->prepare(
      "SELECT COALESCE(SUM(amount), 0) AS previous_spent
       FROM transactions
       WHERE user_id = :user_id
         AND transaction_type = 'debit'
         AND transaction_date >= :previous_month_start
         AND transaction_date < :month_start
         {$deletedClause}
         {$statusClause}"
    );
    $previousSpendStmt->execute([
      ':user_id' => $userId,
      ':previous_month_start' => $previousMonthStart,
      ':month_start' => $monthStart,
    ]);
    $previousSpend = (float) (($previousSpendStmt->fetch(PDO::FETCH_ASSOC)['previous_spent'] ?? 0));

    $portfolioStmt = $db->prepare(
      "SELECT
          COALESCE(SUM(invested_amount), 0) AS total_invested,
          COALESCE(SUM(current_value), 0) AS portfolio_value
       FROM v_asset_summary
       WHERE user_id = :user_id"
    );
    $portfolioStmt->execute([':user_id' => $userId]);
    $portfolio = $portfolioStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $topCategoriesStmt = $db->prepare(
      "SELECT
          COALESCE(c.name, 'Uncategorized') AS name,
          COALESCE(c.color, '#9E9E9E') AS color,
          COALESCE(c.icon, 'help-circle-outline') AS icon,
          COUNT(t.id) AS count,
          COALESCE(SUM(t.amount), 0) AS amount
       FROM transactions t
       LEFT JOIN categories c ON t.category_id = c.id
       WHERE t.user_id = :user_id
         AND t.transaction_type = 'debit'
         AND t.transaction_date >= :month_start
         AND t.transaction_date < :next_month_start
         {$deletedClause}
         {$statusClause}
       GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Uncategorized'), COALESCE(c.color, '#9E9E9E'), COALESCE(c.icon, 'help-circle-outline')
       ORDER BY amount DESC
       LIMIT 3"
    );
    $topCategoriesStmt->execute([
      ':user_id' => $userId,
      ':month_start' => $monthStart,
      ':next_month_start' => $nextMonthStart,
    ]);
    $topCategoriesRows = $topCategoriesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $topCategories = array_map('widgetMapCategorySummary', $topCategoriesRows);

    $upcomingEmiStmt = $db->prepare(
      "SELECT loan_name, emi_amount, next_payment_date, bank, remaining_months
       FROM emis
       WHERE user_id = :user_id
         AND status = 'active'
         AND next_payment_date >= CURDATE()
         AND next_payment_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       ORDER BY next_payment_date ASC
       LIMIT 1"
    );
    $upcomingEmiStmt->execute([':user_id' => $userId]);
    $upcomingEmi = $upcomingEmiStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $monthSpent = (float) ($monthTotals['month_spent'] ?? 0);
    $monthIncome = (float) ($monthTotals['month_income'] ?? 0);
    $transactionCount = (int) ($monthTotals['transaction_count'] ?? 0);
    $monthSavings = $monthIncome - $monthSpent;
    $portfolioValue = (float) ($portfolio['portfolio_value'] ?? 0);
    $totalInvested = (float) ($portfolio['total_invested'] ?? 0);
    $gainLossAmount = $portfolioValue - $totalInvested;
    $gainLossPercent = $totalInvested > 0
      ? round(($gainLossAmount / $totalInvested) * 100, 2)
      : 0.0;
    $amountChange = $monthSpent - $previousSpend;
    $trendPercentage = $previousSpend > 0
      ? round(($amountChange / $previousSpend) * 100, 2)
      : null;
    $trendDirection = 'flat';
    if ($amountChange > 0.009) {
      $trendDirection = 'up';
    } elseif ($amountChange < -0.009) {
      $trendDirection = 'down';
    }

    Response::success([
      'month_label' => (new DateTime())->format('F'),
      'month_spent' => round($monthSpent, 2),
      'month_income' => round($monthIncome, 2),
      'month_savings' => round($monthSavings, 2),
      'portfolio_value' => round($portfolioValue, 2),
      'total_invested' => round($totalInvested, 2),
      'gain_loss_amount' => round($gainLossAmount, 2),
      'gain_loss_percent' => $gainLossPercent,
      'transaction_count' => $transactionCount,
      'trend_vs_last_month' => [
        'percentage' => $trendPercentage,
        'amount_change' => round($amountChange, 2),
        'previous_amount' => round($previousSpend, 2),
        'direction' => $trendDirection,
      ],
      'top_category' => $topCategories[0] ?? null,
      'top_categories' => $topCategories,
      'upcoming_emi' => $upcomingEmi ? [
        'loan_name' => $upcomingEmi['loan_name'],
        'emi_amount' => round((float) $upcomingEmi['emi_amount'], 2),
        'next_payment_date' => $upcomingEmi['next_payment_date'],
        'bank' => $upcomingEmi['bank'],
        'remaining_months' => (int) $upcomingEmi['remaining_months'],
      ] : null,
      'updated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
      'currency' => 'INR',
    ], 'Widget summary retrieved successfully');
  } catch (Exception $e) {
    error_log('Widget summary error: ' . $e->getMessage());
    Response::error('Failed to fetch widget summary: ' . $e->getMessage(), 500);
  }
}

function widgetDetectColumn(PDO $db, $table, $column)
{
  try {
    $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
    $stmt->execute([':column' => $column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return false;
  }
}

function widgetMapCategorySummary($row)
{
  return [
    'name' => $row['name'] ?? 'Uncategorized',
    'color' => $row['color'] ?? '#9E9E9E',
    'icon' => $row['icon'] ?? 'help-circle-outline',
    'count' => (int) ($row['count'] ?? 0),
    'amount' => round((float) ($row['amount'] ?? 0), 2),
  ];
}