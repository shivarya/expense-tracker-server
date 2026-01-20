<?php

function handleInvestmentRoutes($uri, $method)
{
  $userId = 1; // Single user for now

  // Parse URI
  $parts = explode('/', trim($uri, '/'));

  // GET /investments - Get all investments summary
  if ($uri === '/investments' && $method === 'GET') {
    getAllInvestments($userId);
  }
  // GET /investments/stocks - Get all stocks
  elseif ($uri === '/investments/stocks' && $method === 'GET') {
    getStocks($userId);
  }
  // POST /investments/stocks - Create/Update stock
  elseif ($uri === '/investments/stocks' && $method === 'POST') {
    saveStock($userId);
  }
  // GET /investments/mutual-funds - Get all mutual funds
  elseif ($uri === '/investments/mutual-funds' && $method === 'GET') {
    getMutualFunds($userId);
  }
  // POST /investments/mutual-funds - Create/Update mutual fund
  elseif ($uri === '/investments/mutual-funds' && $method === 'POST') {
    saveMutualFund($userId);
  }
  // GET /investments/fixed-deposits - Get all FDs
  elseif ($uri === '/investments/fixed-deposits' && $method === 'GET') {
    getFixedDeposits($userId);
  }
  // POST /investments/fixed-deposits - Create/Update FD
  elseif ($uri === '/investments/fixed-deposits' && $method === 'POST') {
    saveFixedDeposit($userId);
  }
  // GET /investments/long-term - Get long-term funds
  elseif ($uri === '/investments/long-term' && $method === 'GET') {
    getLongTermFunds($userId);
  }
  // POST /investments/long-term - Create/Update long-term fund
  elseif ($uri === '/investments/long-term' && $method === 'POST') {
    saveLongTermFund($userId);
  }
  // DELETE /investments/{type}/{id} - Delete investment
  elseif (count($parts) === 3 && $method === 'DELETE') {
    deleteInvestment($userId, $parts[1], $parts[2]);
  }
  else {
    Response::error('Route not found', 404);
  }
}

function getAllInvestments($userId)
{
  try {
    $db = getDB();

    $stocks = $db->fetchAll("SELECT * FROM stocks WHERE user_id = ? ORDER BY current_value DESC", [$userId]);
    $mutualFunds = $db->fetchAll("SELECT * FROM mutual_funds WHERE user_id = ? ORDER BY current_value DESC", [$userId]);
    $fixedDeposits = $db->fetchAll("SELECT * FROM fixed_deposits WHERE user_id = ? AND status = 'active' ORDER BY maturity_date ASC", [$userId]);
    $longTermFunds = $db->fetchAll("SELECT * FROM long_term_funds WHERE user_id = ? AND status = 'active' ORDER BY current_value DESC", [$userId]);

    Response::success([
      'stocks' => $stocks,
      'mutual_funds' => $mutualFunds,
      'fixed_deposits' => $fixedDeposits,
      'long_term_funds' => $longTermFunds,
    ], 'Investments retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch investments: ' . $e->getMessage(), 500);
  }
}

function getStocks($userId)
{
  try {
    $db = getDB();
    $stocks = $db->fetchAll(
      "SELECT * FROM stocks WHERE user_id = ? ORDER BY current_value DESC",
      [$userId]
    );
    Response::success($stocks, 'Stocks retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch stocks: ' . $e->getMessage(), 500);
  }
}

function saveStock($userId)
{
  try {
    $input = getJsonInput();
    
    $db = getDB();

    if (isset($input['id']) && $input['id']) {
      // Update existing stock
      $sql = "UPDATE stocks SET 
                platform = ?, symbol = ?, company_name = ?, quantity = ?, average_price = ?,
                invested_amount = ?, current_value = ?, current_price = ?
              WHERE id = ? AND user_id = ?";
      $db->execute($sql, [
        $input['platform'], $input['symbol'], $input['company_name'] ?? null,
        $input['quantity'] ?? 0, $input['average_price'] ?? 0,
        $input['invested_amount'], $input['current_value'], $input['current_price'] ?? null,
        $input['id'], $userId
      ]);
      Response::success(['id' => $input['id']], 'Stock updated successfully');
    } else {
      // Insert new stock
      $sql = "INSERT INTO stocks (user_id, platform, symbol, company_name, quantity, average_price, 
                invested_amount, current_value, current_price)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
      $id = $db->insert($sql, [
        $userId, $input['platform'], $input['symbol'], $input['company_name'] ?? null,
        $input['quantity'] ?? 0, $input['average_price'] ?? 0,
        $input['invested_amount'], $input['current_value'], $input['current_price'] ?? null
      ]);
      Response::success(['id' => $id], 'Stock created successfully', 201);
    }
  } catch (Exception $e) {
    Response::error('Failed to save stock: ' . $e->getMessage(), 500);
  }
}

function getMutualFunds($userId)
{
  try {
    $db = getDB();
    $funds = $db->fetchAll(
      "SELECT * FROM mutual_funds WHERE user_id = ? ORDER BY amc, fund_name",
      [$userId]
    );
    Response::success($funds, 'Mutual funds retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch mutual funds: ' . $e->getMessage(), 500);
  }
}

function saveMutualFund($userId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    if (isset($input['id']) && $input['id']) {
      // Update
      $sql = "UPDATE mutual_funds SET 
                fund_name = ?, folio_number = ?, amc = ?, scheme_code = ?, isin = ?,
                portal_url = ?, units = ?, nav = ?, invested_amount = ?, current_value = ?,
                plan_type = ?, option_type = ?
              WHERE id = ? AND user_id = ?";
      $db->execute($sql, [
        $input['fund_name'], $input['folio_number'], $input['amc'],
        $input['scheme_code'] ?? null, $input['isin'] ?? null, $input['portal_url'] ?? null,
        $input['units'] ?? 0, $input['nav'] ?? 0, $input['invested_amount'], $input['current_value'],
        $input['plan_type'] ?? 'direct', $input['option_type'] ?? 'growth',
        $input['id'], $userId
      ]);
      Response::success(['id' => $input['id']], 'Mutual fund updated successfully');
    } else {
      // Insert
      $sql = "INSERT INTO mutual_funds (user_id, fund_name, folio_number, amc, scheme_code, isin,
                portal_url, units, nav, invested_amount, current_value, plan_type, option_type)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
      $id = $db->insert($sql, [
        $userId, $input['fund_name'], $input['folio_number'], $input['amc'],
        $input['scheme_code'] ?? null, $input['isin'] ?? null, $input['portal_url'] ?? null,
        $input['units'] ?? 0, $input['nav'] ?? 0, $input['invested_amount'], $input['current_value'],
        $input['plan_type'] ?? 'direct', $input['option_type'] ?? 'growth'
      ]);
      Response::success(['id' => $id], 'Mutual fund created successfully', 201);
    }
  } catch (Exception $e) {
    Response::error('Failed to save mutual fund: ' . $e->getMessage(), 500);
  }
}

function getFixedDeposits($userId)
{
  try {
    $db = getDB();
    $fds = $db->fetchAll(
      "SELECT * FROM fixed_deposits WHERE user_id = ? ORDER BY maturity_date ASC",
      [$userId]
    );
    Response::success($fds, 'Fixed deposits retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch fixed deposits: ' . $e->getMessage(), 500);
  }
}

function saveFixedDeposit($userId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    if (isset($input['id']) && $input['id']) {
      // Update
      $sql = "UPDATE fixed_deposits SET 
                bank = ?, fd_number = ?, account_number = ?, principal_amount = ?, interest_rate = ?,
                tenure_months = ?, start_date = ?, maturity_date = ?, maturity_value = ?,
                status = ?, auto_renewal = ?
              WHERE id = ? AND user_id = ?";
      $db->execute($sql, [
        $input['bank'], $input['fd_number'] ?? null, $input['account_number'] ?? null,
        $input['principal_amount'], $input['interest_rate'], $input['tenure_months'],
        $input['start_date'], $input['maturity_date'], $input['maturity_value'],
        $input['status'] ?? 'active', $input['auto_renewal'] ?? false,
        $input['id'], $userId
      ]);
      Response::success(['id' => $input['id']], 'Fixed deposit updated successfully');
    } else {
      // Insert
      $sql = "INSERT INTO fixed_deposits (user_id, bank, fd_number, account_number, principal_amount,
                interest_rate, tenure_months, start_date, maturity_date, maturity_value, status, auto_renewal)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
      $id = $db->insert($sql, [
        $userId, $input['bank'], $input['fd_number'] ?? null, $input['account_number'] ?? null,
        $input['principal_amount'], $input['interest_rate'], $input['tenure_months'],
        $input['start_date'], $input['maturity_date'], $input['maturity_value'],
        $input['status'] ?? 'active', $input['auto_renewal'] ?? false
      ]);
      Response::success(['id' => $id], 'Fixed deposit created successfully', 201);
    }
  } catch (Exception $e) {
    Response::error('Failed to save fixed deposit: ' . $e->getMessage(), 500);
  }
}

function getLongTermFunds($userId)
{
  try {
    $db = getDB();
    $funds = $db->fetchAll(
      "SELECT * FROM long_term_funds WHERE user_id = ? ORDER BY fund_type, account_name",
      [$userId]
    );
    Response::success($funds, 'Long-term funds retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch long-term funds: ' . $e->getMessage(), 500);
  }
}

function saveLongTermFund($userId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    if (isset($input['id']) && $input['id']) {
      // Update
      $sql = "UPDATE long_term_funds SET 
                fund_type = ?, account_name = ?, account_number = ?, pran_number = ?, uan_number = ?,
                invested_amount = ?, current_value = ?, employer_contribution = ?, interest_earned = ?,
                maturity_date = ?, maturity_value = ?, lock_in_period_years = ?, start_date = ?,
                last_contribution_date = ?, status = ?
              WHERE id = ? AND user_id = ?";
      $db->execute($sql, [
        $input['fund_type'], $input['account_name'], $input['account_number'] ?? null,
        $input['pran_number'] ?? null, $input['uan_number'] ?? null,
        $input['invested_amount'], $input['current_value'], $input['employer_contribution'] ?? 0,
        $input['interest_earned'] ?? 0, $input['maturity_date'] ?? null, $input['maturity_value'] ?? null,
        $input['lock_in_period_years'] ?? null, $input['start_date'] ?? null,
        $input['last_contribution_date'] ?? null, $input['status'] ?? 'active',
        $input['id'], $userId
      ]);
      Response::success(['id' => $input['id']], 'Long-term fund updated successfully');
    } else {
      // Insert
      $sql = "INSERT INTO long_term_funds (user_id, fund_type, account_name, account_number, pran_number,
                uan_number, invested_amount, current_value, employer_contribution, interest_earned,
                maturity_date, maturity_value, lock_in_period_years, start_date, last_contribution_date, status)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
      $id = $db->insert($sql, [
        $userId, $input['fund_type'], $input['account_name'], $input['account_number'] ?? null,
        $input['pran_number'] ?? null, $input['uan_number'] ?? null,
        $input['invested_amount'], $input['current_value'], $input['employer_contribution'] ?? 0,
        $input['interest_earned'] ?? 0, $input['maturity_date'] ?? null, $input['maturity_value'] ?? null,
        $input['lock_in_period_years'] ?? null, $input['start_date'] ?? null,
        $input['last_contribution_date'] ?? null, $input['status'] ?? 'active'
      ]);
      Response::success(['id' => $id], 'Long-term fund created successfully', 201);
    }
  } catch (Exception $e) {
    Response::error('Failed to save long-term fund: ' . $e->getMessage(), 500);
  }
}

function deleteInvestment($userId, $type, $id)
{
  try {
    $db = getDB();
    $table = '';

    switch ($type) {
      case 'stocks':
        $table = 'stocks';
        break;
      case 'mutual-funds':
        $table = 'mutual_funds';
        break;
      case 'fixed-deposits':
        $table = 'fixed_deposits';
        break;
      case 'long-term':
        $table = 'long_term_funds';
        break;
      default:
        Response::error('Invalid investment type', 400);
    }

    $affected = $db->execute("DELETE FROM $table WHERE id = ? AND user_id = ?", [$id, $userId]);

    if ($affected > 0) {
      Response::success(null, 'Investment deleted successfully');
    } else {
      Response::error('Investment not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete investment: ' . $e->getMessage(), 500);
  }
}

