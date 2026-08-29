<?php

function handleDashboardRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/dashboard' && $method === 'GET') {
    getDashboardSummary($userId);
  } else {
    Response::error('Route not found', 404);
  }
}

function getDashboardSummary($userId)
{
  try {
    $db = getDB();

    // Get portfolio summary from view
    $portfolioSummary = $db->fetchAll(
      "SELECT category, invested_amount, current_value, gain_loss_percent, item_count 
       FROM v_asset_summary 
       WHERE user_id = ?",
      [$userId]
    );

    // Calculate totals
    $totalInvested = 0;
    $totalCurrent = 0;
    foreach ($portfolioSummary as $category) {
      $totalInvested += $category['invested_amount'];
      $totalCurrent += $category['current_value'];
    }

    $overallGainLoss = $totalInvested > 0 
      ? (($totalCurrent - $totalInvested) / $totalInvested * 100) 
      : 0;

    // Get bank accounts summary
    $bankAccounts = $db->fetchAll(
      "SELECT id, bank, account_type, account_name, balance, credit_limit, available_credit, status
       FROM bank_accounts 
       WHERE user_id = ? AND status = 'active'
       ORDER BY is_primary DESC, account_type, bank",
      [$userId]
    );

    // Get recent transactions (last 10)
    $recentTransactions = $db->fetchAll(
      "SELECT t.id, t.transaction_type, t.amount, t.merchant, t.description, 
              t.transaction_date, c.name as category_name, c.color as category_color,
              ba.bank, ba.account_type
       FROM transactions t
       JOIN categories c ON t.category_id = c.id
       JOIN bank_accounts ba ON t.account_id = ba.id
      WHERE t.user_id = ? AND t.deleted_at IS NULL
       ORDER BY t.transaction_date DESC
       LIMIT 10",
      [$userId]
    );

    // Get upcoming EMIs (next 30 days)
    $upcomingEmis = $db->fetchAll(
      "SELECT e.id, e.loan_name, e.loan_type, e.emi_amount, e.next_payment_date, e.bank,
              e.remaining_months, e.tenure_months,
              (e.tenure_months - e.remaining_months) AS paid_installments,
              e.tenure_months AS total_installments
       FROM emis e
       WHERE e.user_id = ? AND e.status = 'active'
         AND e.next_payment_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       ORDER BY e.next_payment_date ASC
       LIMIT 5",
      [$userId]
    );

    // Get current month expenses by category. Excludes Transfer-type categories
    // (e.g. a credit card bill payment) since those settle debt already counted
    // via the card's own line items -- counting the payment too would double it.
    // Also nets out allocated refunds/reimbursements (e.g. a spouse paying back
    // part of an expense), which otherwise only ever show as a display label.
    $monthlyExpenses = $db->fetchAll(
      "SELECT c.name, c.color, c.icon, COUNT(t.id) as count,
              SUM(t.amount - COALESCE(ra.allocated, 0)) as total,
              COALESCE(cb.monthly_budget, c.monthly_budget) AS monthly_budget
       FROM transactions t
       JOIN categories c ON t.category_id = c.id
       LEFT JOIN category_budgets cb ON cb.category_id = c.id AND cb.user_id = ?
       LEFT JOIN (
         SELECT expense_transaction_id, SUM(amount) as allocated
         FROM transaction_refund_allocations
         WHERE user_id = ? AND deleted_at IS NULL
         GROUP BY expense_transaction_id
       ) ra ON ra.expense_transaction_id = t.id
       WHERE t.user_id = ?
         AND t.deleted_at IS NULL
         AND t.transaction_type = 'debit'
         AND c.type != 'transfer'
         AND YEAR(t.transaction_date) = YEAR(CURDATE())
         AND MONTH(t.transaction_date) = MONTH(CURDATE())
       GROUP BY c.id, c.name, c.color, c.icon, c.monthly_budget, cb.monthly_budget
       ORDER BY total DESC",
      [$userId, $userId, $userId]
    );

    // Get investments maturing soon (next 90 days)
    $upcomingMaturities = [];

    // Fixed Deposits
    $fdMaturities = $db->fetchAll(
      "SELECT 'Fixed Deposit' as type, bank as institution, fd_number as reference, 
              maturity_date, maturity_value as amount
       FROM fixed_deposits
       WHERE user_id = ? AND status = 'active'
         AND maturity_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
       ORDER BY maturity_date ASC
       LIMIT 5",
      [$userId]
    );
    $upcomingMaturities = array_merge($upcomingMaturities, $fdMaturities);

    // Response
    Response::success([
      'portfolio' => [
        'summary' => $portfolioSummary,
        'total_invested' => round($totalInvested, 2),
        'total_current_value' => round($totalCurrent, 2),
        'overall_gain_loss' => round($overallGainLoss, 2),
        'overall_gain_loss_amount' => round($totalCurrent - $totalInvested, 2),
      ],
      'bank_accounts' => $bankAccounts,
      'recent_transactions' => $recentTransactions,
      'upcoming_emis' => $upcomingEmis,
      'monthly_expenses' => $monthlyExpenses,
      'upcoming_maturities' => $upcomingMaturities,
    ], 'Dashboard data retrieved successfully');
  } catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    Response::error('Failed to fetch dashboard data: ' . $e->getMessage(), 500);
  }
}

