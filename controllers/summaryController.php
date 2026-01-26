<?php

function handleSummaryRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  // GET /summary/balance - Get portfolio balance
  if ($uri === '/summary/balance' && $method === 'GET') {
    getBalanceSummary($userId);
  }
  // GET /summary/portfolio - Get detailed portfolio
  elseif ($uri === '/summary/portfolio' && $method === 'GET') {
    getPortfolioSummary($userId);
  }
  else {
    Response::error('Route not found', 404);
  }
}

function getBalanceSummary($userId)
{
  try {
    $db = getDB();
    
    // Get stocks summary
    $stocksData = $db->fetchOne(
      "SELECT 
        SUM(invested_amount) as invested,
        SUM(current_value) as total_value,
        SUM(gain_loss_amount) as gain_loss,
        AVG(gain_loss_percent) as gain_loss_percent
       FROM stocks WHERE user_id = ?",
      [$userId]
    );
    
    // Get mutual funds summary
    $mfData = $db->fetchOne(
      "SELECT 
        SUM(invested_amount) as invested,
        SUM(current_value) as total_value,
        SUM(gain_loss_amount) as gain_loss,
        AVG(gain_loss_percent) as gain_loss_percent
       FROM mutual_funds WHERE user_id = ?",
      [$userId]
    );
    
    // Get fixed deposits summary
    $fdData = $db->fetchOne(
      "SELECT 
        SUM(principal_amount) as invested,
        SUM(maturity_value) as total_value
       FROM fixed_deposits WHERE user_id = ? AND status = 'active'",
      [$userId]
    );
    
    // Get long term funds summary
    $ltfData = $db->fetchOne(
      "SELECT 
        SUM(invested_amount) as invested,
        SUM(current_value) as total_value
       FROM long_term_funds WHERE user_id = ? AND status = 'active'",
      [$userId]
    );
    
    $totalInvested = 
      ($stocksData['invested'] ?? 0) + 
      ($mfData['invested'] ?? 0) + 
      ($fdData['invested'] ?? 0) + 
      ($ltfData['invested'] ?? 0);
    
    $totalValue = 
      ($stocksData['total_value'] ?? 0) + 
      ($mfData['total_value'] ?? 0) + 
      ($fdData['total_value'] ?? 0) + 
      ($ltfData['total_value'] ?? 0);
    
    $totalGainLoss = $totalValue - $totalInvested;
    $totalGainLossPercent = $totalInvested > 0 ? ($totalGainLoss / $totalInvested * 100) : 0;
    
    Response::success([
      'stocks' => [
        'invested' => (float)($stocksData['invested'] ?? 0),
        'total_value' => (float)($stocksData['total_value'] ?? 0),
        'gain_loss' => (float)($stocksData['gain_loss'] ?? 0),
        'gain_loss_percent' => (float)($stocksData['gain_loss_percent'] ?? 0)
      ],
      'mutual_funds' => [
        'invested' => (float)($mfData['invested'] ?? 0),
        'total_value' => (float)($mfData['total_value'] ?? 0),
        'gain_loss' => (float)($mfData['gain_loss'] ?? 0),
        'gain_loss_percent' => (float)($mfData['gain_loss_percent'] ?? 0)
      ],
      'fixed_deposits' => [
        'invested' => (float)($fdData['invested'] ?? 0),
        'total_value' => (float)($fdData['total_value'] ?? 0)
      ],
      'long_term_funds' => [
        'invested' => (float)($ltfData['invested'] ?? 0),
        'total_value' => (float)($ltfData['total_value'] ?? 0)
      ],
      'total_invested' => (float)$totalInvested,
      'total_value' => (float)$totalValue,
      'total_gain_loss' => (float)$totalGainLoss,
      'total_gain_loss_percent' => (float)$totalGainLossPercent
    ]);
  } catch (Exception $e) {
    Response::error('Failed to get balance: ' . $e->getMessage(), 500);
  }
}

function getPortfolioSummary($userId)
{
  try {
    $db = getDB();
    
    // Get all stocks
    $stocks = $db->fetchAll(
      "SELECT symbol, company_name, platform, quantity, average_price, current_price, 
              invested_amount, current_value, gain_loss_amount, gain_loss_percent
       FROM stocks WHERE user_id = ?
       ORDER BY current_value DESC",
      [$userId]
    );
    
    // Get all mutual funds
    $mutualFunds = $db->fetchAll(
      "SELECT fund_name, amc, folio_number, units, nav, 
              invested_amount, current_value, gain_loss_amount, gain_loss_percent, plan_type
       FROM mutual_funds WHERE user_id = ?
       ORDER BY current_value DESC",
      [$userId]
    );
    
    // Get all fixed deposits
    $fixedDeposits = $db->fetchAll(
      "SELECT bank, fd_number, principal_amount, interest_rate, tenure_months,
              start_date, maturity_date, maturity_value, status
       FROM fixed_deposits WHERE user_id = ?
       ORDER BY maturity_date ASC",
      [$userId]
    );
    
    // Get all long term funds
    $longTermFunds = $db->fetchAll(
      "SELECT fund_type, account_name, account_number, invested_amount, current_value,
              employer_contribution, maturity_date, status
       FROM long_term_funds WHERE user_id = ?
       ORDER BY current_value DESC",
      [$userId]
    );
    
    $totalValue = 
      array_sum(array_column($stocks, 'current_value')) +
      array_sum(array_column($mutualFunds, 'current_value')) +
      array_sum(array_column($fixedDeposits, 'maturity_value')) +
      array_sum(array_column($longTermFunds, 'current_value'));
    
    Response::success([
      'stocks' => $stocks,
      'mutual_funds' => $mutualFunds,
      'fixed_deposits' => $fixedDeposits,
      'long_term_funds' => $longTermFunds,
      'total_value' => (float)$totalValue
    ]);
  } catch (Exception $e) {
    Response::error('Failed to get portfolio: ' . $e->getMessage(), 500);
  }
}
