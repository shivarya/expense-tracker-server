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
    $seriesStart = (new DateTime('first day of -5 months'))->format('Y-m-d');

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

    $topCategoriesStmt = $db->prepare(
      "SELECT
          c.id AS category_id,
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

    $monthlySeriesStmt = $db->prepare(
      "SELECT
          DATE_FORMAT(transaction_date, '%Y-%m') AS month_key,
          COALESCE(SUM(amount), 0) AS amount
       FROM transactions
       WHERE user_id = :user_id
         AND transaction_type = 'debit'
         AND transaction_date >= :series_start
         AND transaction_date < :next_month_start
         {$deletedClause}
         {$statusClause}
       GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
       ORDER BY month_key ASC"
    );
    $monthlySeriesStmt->execute([
      ':user_id' => $userId,
      ':series_start' => $seriesStart,
      ':next_month_start' => $nextMonthStart,
    ]);
    $monthlySeriesRows = $monthlySeriesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $monthlySpendSeries = widgetBuildMonthlySpendSeries($seriesStart, $monthlySeriesRows);

    $monthSpent = (float) ($monthTotals['month_spent'] ?? 0);
    $monthIncome = (float) ($monthTotals['month_income'] ?? 0);
    $transactionCount = (int) ($monthTotals['transaction_count'] ?? 0);
    $monthSavings = $monthIncome - $monthSpent;
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
      'transaction_count' => $transactionCount,
      'trend_vs_last_month' => [
        'percentage' => $trendPercentage,
        'amount_change' => round($amountChange, 2),
        'previous_amount' => round($previousSpend, 2),
        'direction' => $trendDirection,
      ],
      'top_category' => $topCategories[0] ?? null,
      'top_categories' => $topCategories,
      'monthly_spend_series' => $monthlySpendSeries,
      'updated_at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
      'currency' => 'INR',
    ], 'Widget summary retrieved successfully');
  } catch (Exception $e) {
    error_log('Widget summary error: ' . $e->getMessage());
    Response::error('Failed to fetch widget summary: ' . $e->getMessage(), 500);
  }
}

// NOTE: `SHOW COLUMNS ... LIKE :column` with a bound parameter throws a
// syntax error on this MariaDB version ("SHOW ... LIKE" doesn't accept
// placeholders) -- the try/catch here silently swallowed that, so this
// always returned false and the widget's totals never actually excluded
// soft-deleted transactions. information_schema supports real bound params.
function widgetDetectColumn(PDO $db, $table, $column)
{
  try {
    $stmt = $db->prepare(
      "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column"
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return false;
  }
}

function widgetMapCategorySummary($row)
{
  return [
    'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
    'name' => $row['name'] ?? 'Uncategorized',
    'color' => $row['color'] ?? '#9E9E9E',
    'icon' => $row['icon'] ?? 'help-circle-outline',
    'count' => (int) ($row['count'] ?? 0),
    'amount' => round((float) ($row['amount'] ?? 0), 2),
  ];
}

function widgetBuildMonthlySpendSeries($seriesStart, $rows)
{
  $amountByMonth = [];
  foreach ($rows as $row) {
    $amountByMonth[$row['month_key']] = round((float) ($row['amount'] ?? 0), 2);
  }

  $series = [];
  $cursor = new DateTime($seriesStart);
  $end = new DateTime('first day of next month');

  while ($cursor < $end) {
    $key = $cursor->format('Y-m');
    $series[] = [
      'key' => $key,
      'label' => strtoupper($cursor->format('M')),
      'amount' => $amountByMonth[$key] ?? 0.0,
    ];
    $cursor->modify('+1 month');
  }

  return $series;
}