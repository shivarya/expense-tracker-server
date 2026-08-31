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
    $statusClause = $hasStatus ? "AND t.status IN ('completed', 'pending')" : '';
    $deletedClause = $hasDeletedAt ? 'AND t.deleted_at IS NULL' : '';

    // Income + transaction count come straight from transactions -- credits
    // are never split, and "how many transactions" is a distinct concept from
    // the per-category spend breakdown below.
    $monthTotalsStmt = $db->prepare(
      "SELECT
          SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END) AS month_income,
          SUM(CASE WHEN t.transaction_type = 'debit' THEN 1 ELSE 0 END) AS transaction_count
       FROM transactions t
       WHERE t.user_id = :user_id
         AND t.transaction_date >= :month_start
         AND t.transaction_date < :next_month_start
         {$deletedClause}
         {$statusClause}"
    );
    $monthTotalsStmt->execute([
      ':user_id' => $userId,
      ':month_start' => $monthStart,
      ':next_month_start' => $nextMonthStart,
    ]);
    $monthTotals = $monthTotalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Debit sums are split-aware (v_effective_debit_lines) and exclude
    // Transfer-type categories (e.g. a credit card bill payment, which
    // settles debt already counted via the card's own line items). Refund/
    // reimbursement allocations are netted out via a separate query merged
    // in PHP -- not a JOIN here -- because a JOIN would repeat (and thus
    // over-subtract) the same allocation once per split line on a
    // transaction that's both split and refunded.
    $refundStmt = $db->prepare(
      "SELECT e.category_id, e.transaction_date, SUM(a.amount) AS allocated
       FROM transaction_refund_allocations a
       JOIN transactions e ON e.id = a.expense_transaction_id
       LEFT JOIN categories c ON c.id = e.category_id
       WHERE a.user_id = :user_id
         AND a.deleted_at IS NULL
         AND e.deleted_at IS NULL
         AND e.transaction_type = 'debit'
         AND (c.type IS NULL OR c.type != 'transfer')
         AND e.transaction_date >= :series_start
         AND e.transaction_date < :next_month_start
       GROUP BY e.category_id, e.transaction_date"
    );
    $refundStmt->execute([
      ':user_id' => $userId,
      ':series_start' => $seriesStart,
      ':next_month_start' => $nextMonthStart,
    ]);
    $refundRows = $refundStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $categoryMetaStmt = $db->prepare("SELECT id, name, color, icon FROM categories WHERE user_id = :user_id OR user_id IS NULL");
    $categoryMetaStmt->execute([':user_id' => $userId]);
    $categoryMeta = [];
    foreach ($categoryMetaStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $categoryMeta[(int)$row['id']] = $row;
    }

    $inMonthRange = static fn(string $date) => $date >= $monthStart && $date < $nextMonthStart;
    $inPreviousMonthRange = static fn(string $date) => $date >= $previousMonthStart && $date < $monthStart;

    $monthCategoryTotals = []; // category_id => ['amount' => .., 'count' => ..]
    $previousSpend = 0.0;
    $monthlyAmountByKey = []; // 'Y-m' => amount

    // Row-per-effective-line (not pre-grouped) since month/previous-month/
    // top-categories/series all need different slices of the same window.
    $grossByDateStmt = $db->prepare(
      "SELECT ed.category_id, ed.transaction_date, ed.amount
       FROM v_effective_debit_lines ed
       LEFT JOIN categories c ON c.id = ed.category_id
       WHERE ed.user_id = :user_id
         AND ed.deleted_at IS NULL
         AND (c.type IS NULL OR c.type != 'transfer')
         AND ed.transaction_date >= :series_start
         AND ed.transaction_date < :next_month_start"
    );
    $grossByDateStmt->execute([
      ':user_id' => $userId,
      ':series_start' => $seriesStart,
      ':next_month_start' => $nextMonthStart,
    ]);

    $monthSpent = 0.0;
    foreach ($grossByDateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $date = substr((string)$row['transaction_date'], 0, 10);
      $monthKey = substr((string)$row['transaction_date'], 0, 7);
      $amount = (float)$row['amount'];
      $categoryId = $row['category_id'] !== null ? (int)$row['category_id'] : 0;

      $monthlyAmountByKey[$monthKey] = ($monthlyAmountByKey[$monthKey] ?? 0.0) + $amount;

      if ($inMonthRange($date)) {
        $monthSpent += $amount;
        if (!isset($monthCategoryTotals[$categoryId])) {
          $monthCategoryTotals[$categoryId] = ['amount' => 0.0, 'count' => 0];
        }
        $monthCategoryTotals[$categoryId]['amount'] += $amount;
        $monthCategoryTotals[$categoryId]['count'] += 1;
      } elseif ($inPreviousMonthRange($date)) {
        $previousSpend += $amount;
      }
    }

    foreach ($refundRows as $row) {
      $date = substr((string)$row['transaction_date'], 0, 10);
      $monthKey = substr((string)$row['transaction_date'], 0, 7);
      $allocated = (float)$row['allocated'];
      $categoryId = $row['category_id'] !== null ? (int)$row['category_id'] : 0;

      $monthlyAmountByKey[$monthKey] = ($monthlyAmountByKey[$monthKey] ?? 0.0) - $allocated;

      if ($inMonthRange($date)) {
        $monthSpent -= $allocated;
        if (!isset($monthCategoryTotals[$categoryId])) {
          $monthCategoryTotals[$categoryId] = ['amount' => 0.0, 'count' => 0];
        }
        $monthCategoryTotals[$categoryId]['amount'] -= $allocated;
      } elseif ($inPreviousMonthRange($date)) {
        $previousSpend -= $allocated;
      }
    }

    $topCategories = [];
    foreach ($monthCategoryTotals as $categoryId => $data) {
      $meta = $categoryMeta[$categoryId] ?? null;
      $topCategories[] = [
        'category_id' => $categoryId > 0 ? $categoryId : null,
        'name' => $meta['name'] ?? 'Uncategorized',
        'color' => $meta['color'] ?? '#9E9E9E',
        'icon' => $meta['icon'] ?? 'help-circle-outline',
        'count' => $data['count'],
        'amount' => round($data['amount'], 2),
      ];
    }
    usort($topCategories, static fn($a, $b) => $b['amount'] <=> $a['amount']);
    $topCategories = array_slice($topCategories, 0, 3);

    $monthlySpendSeries = widgetBuildMonthlySpendSeries($seriesStart, array_map(
      static fn($key, $amount) => ['month_key' => $key, 'amount' => $amount],
      array_keys($monthlyAmountByKey),
      array_values($monthlyAmountByKey)
    ));

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