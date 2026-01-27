<?php

// Helper function to get or create bank account
function getOrCreateBankAccount($db, $userId, $bankName, $accountNumber) {
  // Normalize bank name to enum value
  $bankMap = [
    'HDFC Bank' => 'hdfc',
    'HDFC' => 'hdfc',
    'SBI' => 'sbi',
    'State Bank of India' => 'sbi',
    'ICICI Bank' => 'icici',
    'ICICI' => 'icici',
    'IDFC FIRST Bank' => 'idfc',
    'IDFC' => 'idfc',
    'RBL Bank' => 'rbl',
    'RBL' => 'rbl',
    'Axis Bank' => 'axis',
    'AXIS' => 'axis',
    'Kotak Bank' => 'kotak',
    'Kotak Mahindra Bank' => 'kotak',
  ];
  
  $bank = $bankMap[$bankName] ?? 'other';
  
  // Clean account number (remove masking)
  $cleanAccountNumber = preg_replace('/[*X\s-]/', '', $accountNumber);
  if (empty($cleanAccountNumber)) {
    $cleanAccountNumber = 'AUTO_' . substr(md5($bankName . $accountNumber), 0, 10);
  }
  
  // Check if account exists
  $existing = $db->fetchOne(
    "SELECT id FROM bank_accounts WHERE user_id = ? AND bank = ? AND account_number LIKE ?",
    [$userId, $bank, '%' . substr($cleanAccountNumber, -4)]
  );
  
  if ($existing) {
    return $existing['id'];
  }
  
  // Create new account
  $sql = "INSERT INTO bank_accounts (user_id, bank, account_type, account_number, account_name, status)
          VALUES (?, ?, ?, ?, ?, ?)";
  return $db->insert($sql, [
    $userId,
    $bank,
    'savings',
    $cleanAccountNumber,
    $bankName . ' Account',
    'active'
  ]);
}

// Helper function to get or create category
function getOrCreateCategory($db, $userId, $categoryName, $transactionType) {
  // Map category names to system categories
  $categoryMap = [
    'Food & Dining' => ['name' => 'Food & Dining', 'icon' => 'restaurant-outline', 'color' => '#FF9800'],
    'Shopping' => ['name' => 'Shopping', 'icon' => 'cart-outline', 'color' => '#E91E63'],
    'Transport' => ['name' => 'Transport', 'icon' => 'car-outline', 'color' => '#2196F3'],
    'Entertainment' => ['name' => 'Entertainment', 'icon' => 'film-outline', 'color' => '#9C27B0'],
    'Electronics' => ['name' => 'Electronics', 'icon' => 'laptop-outline', 'color' => '#607D8B'],
    'Salary' => ['name' => 'Salary', 'icon' => 'cash-outline', 'color' => '#4CAF50'],
    'Transfer' => ['name' => 'Transfer', 'icon' => 'swap-horizontal-outline', 'color' => '#00BCD4'],
    'Wallet / UPI' => ['name' => 'UPI/Wallet', 'icon' => 'wallet-outline', 'color' => '#FF5722'],
    'Bills & Utilities' => ['name' => 'Bills', 'icon' => 'receipt-outline', 'color' => '#795548'],
    'Health' => ['name' => 'Health', 'icon' => 'medkit-outline', 'color' => '#F44336'],
    'Other' => ['name' => 'Other', 'icon' => 'ellipsis-horizontal-outline', 'color' => '#9E9E9E'],
  ];
  
  $categoryInfo = $categoryMap[$categoryName] ?? ['name' => $categoryName, 'icon' => 'pricetag-outline', 'color' => '#757575'];
  
  // Determine type based on transaction type
  $type = $transactionType === 'credit' ? 'income' : 'expense';
  if ($categoryName === 'Transfer' || $transactionType === 'transfer') {
    $type = 'transfer';
  }
  
  // Check if category exists (system or user-specific)
  $existing = $db->fetchOne(
    "SELECT id FROM categories WHERE (user_id = ? OR user_id IS NULL) AND name = ? AND type = ?",
    [$userId, $categoryInfo['name'], $type]
  );
  
  if ($existing) {
    return $existing['id'];
  }
  
  // Create new category for this user
  $sql = "INSERT INTO categories (user_id, name, icon, color, type, is_system)
          VALUES (?, ?, ?, ?, ?, ?)";
  return $db->insert($sql, [
    $userId,
    $categoryInfo['name'],
    $categoryInfo['icon'],
    $categoryInfo['color'],
    $type,
    0
  ]);
}

function handleSyncRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  // GET /sync/status/{jobId} - Get sync job status
  if (preg_match('/^\/sync\/status\/(\d+)$/', $uri, $matches) && $method === 'GET') {
    getSyncJobStatus($matches[1]);
  }
  // GET /sync/latest - Get latest sync job
  elseif ($uri === '/sync/latest' && $method === 'GET') {
    getLatestSyncJob();
  }
  // POST /sync/stocks - Receive scraped stock data
  elseif ($uri === '/sync/stocks' && $method === 'POST') {
    syncStocks($userId);
  }
  // POST /sync/mutual-funds - Receive parsed CAMS data
  elseif ($uri === '/sync/mutual-funds' && $method === 'POST') {
    syncMutualFunds($userId);
  }
  // POST /sync/transactions - Receive parsed SMS/email transactions
  elseif ($uri === '/sync/transactions' && $method === 'POST') {
    syncTransactions($userId);
  }
  // POST /sync/fixed-deposits - Receive FD data
  elseif ($uri === '/sync/fixed-deposits' && $method === 'POST') {
    syncFixedDeposits($userId);
  }
  // GET /sync/logs - Get sync history
  elseif ($uri === '/sync/logs' && $method === 'GET') {
    getSyncLogs($userId);
  }
  else {
    Response::error('Route not found', 404);
  }
}

function syncStocks($userId)
{
  try {
    $input = getJsonInput();
    
    if (!isset($input['stocks']) || !is_array($input['stocks'])) {
      Response::error('Invalid data format. Expected array of stocks', 400);
    }

    $db = getDB();
    $db->beginTransaction();

    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['stocks'] as $stock) {
      try {
        // Check if stock exists (by platform + symbol)
        $existing = $db->fetchOne(
          "SELECT id FROM stocks WHERE user_id = ? AND platform = ? AND symbol = ?",
          [$userId, $stock['platform'], $stock['symbol']]
        );

        if ($existing) {
          // Update
          $sql = "UPDATE stocks SET 
                    company_name = ?, quantity = ?, average_price = ?, invested_amount = ?,
                    current_value = ?, current_price = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $stock['company_name'] ?? null,
            $stock['quantity'] ?? 0,
            $stock['average_price'] ?? 0,
            $stock['invested_amount'],
            $stock['current_value'],
            $stock['current_price'] ?? null,
            $existing['id']
          ]);
          $updated++;
        } else {
          // Insert
          $sql = "INSERT INTO stocks (user_id, platform, symbol, company_name, quantity, average_price,
                    invested_amount, current_value, current_price)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $stock['platform'],
            $stock['symbol'],
            $stock['company_name'] ?? null,
            $stock['quantity'] ?? 0,
            $stock['average_price'] ?? 0,
            $stock['invested_amount'],
            $stock['current_value'],
            $stock['current_price'] ?? null
          ]);
          $created++;
        }
      } catch (Exception $e) {
        $failed++;
        $errors[] = $stock['symbol'] . ': ' . $e->getMessage();
      }
    }

    // Log sync activity
    $logSql = "INSERT INTO scrape_logs (user_id, source_type, source_name, status, records_processed,
                 records_created, records_updated, records_failed, error_message)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $db->insert($logSql, [
      $userId,
      'stocks',
      $input['platform'] ?? 'unknown',
      $failed > 0 ? 'partial' : 'success',
      count($input['stocks']),
      $created,
      $updated,
      $failed,
      !empty($errors) ? implode('; ', $errors) : null
    ]);

    $db->commit();

    Response::success([
      'created' => $created,
      'updated' => $updated,
      'failed' => $failed,
      'errors' => $errors
    ], 'Stocks synced successfully');
  } catch (Exception $e) {
    $db->rollback();
    Response::error('Failed to sync stocks: ' . $e->getMessage(), 500);
  }
}

function syncMutualFunds($userId)
{
  try {
    $input = getJsonInput();
    
    if (!isset($input['funds']) || !is_array($input['funds'])) {
      Response::error('Invalid data format. Expected array of funds', 400);
    }

    $db = getDB();
    $db->beginTransaction();

    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['funds'] as $fund) {
      try {
        // Check if fund exists (by folio + fund name)
        $existing = $db->fetchOne(
          "SELECT id FROM mutual_funds WHERE user_id = ? AND folio_number = ? AND fund_name = ?",
          [$userId, $fund['folio_number'], $fund['fund_name']]
        );

        if ($existing) {
          // Update
          $sql = "UPDATE mutual_funds SET 
                    amc = ?, units = ?, nav = ?, invested_amount = ?, current_value = ?,
                    plan_type = ?, option_type = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $fund['amc'],
            $fund['units'],
            $fund['nav'],
            $fund['invested_amount'],
            $fund['current_value'],
            $fund['plan_type'] ?? 'direct',
            $fund['option_type'] ?? 'growth',
            $existing['id']
          ]);
          $updated++;
        } else {
          // Insert
          $sql = "INSERT INTO mutual_funds (user_id, fund_name, folio_number, amc, units, nav,
                    invested_amount, current_value, plan_type, option_type, portal_url)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $fund['fund_name'],
            $fund['folio_number'],
            $fund['amc'],
            $fund['units'],
            $fund['nav'],
            $fund['invested_amount'],
            $fund['current_value'],
            $fund['plan_type'] ?? 'direct',
            $fund['option_type'] ?? 'growth',
            $fund['portal_url'] ?? null
          ]);
          $created++;
        }
      } catch (Exception $e) {
        $failed++;
        $errors[] = $fund['folio_number'] . ': ' . $e->getMessage();
      }
    }

    // Log sync
    $logSql = "INSERT INTO scrape_logs (user_id, source_type, source_name, status, records_processed,
                 records_created, records_updated, records_failed, error_message)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $db->insert($logSql, [
      $userId,
      'mutual_funds',
      'cams',
      $failed > 0 ? 'partial' : 'success',
      count($input['funds']),
      $created,
      $updated,
      $failed,
      !empty($errors) ? implode('; ', $errors) : null
    ]);

    $db->commit();

    Response::success([
      'created' => $created,
      'updated' => $updated,
      'failed' => $failed,
      'errors' => $errors
    ], 'Mutual funds synced successfully');
  } catch (Exception $e) {
    $db->rollback();
    Response::error('Failed to sync mutual funds: ' . $e->getMessage(), 500);
  }
}

function syncTransactions($userId)
{
  try {
    $input = getJsonInput();
    
    if (!isset($input['transactions']) || !is_array($input['transactions'])) {
      Response::error('Invalid data format. Expected array of transactions', 400);
    }

    $db = getDB();
    
    // Ensure user exists
    $user = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId]);
    if (!$user) {
      // Create default user if doesn't exist
      $db->execute("INSERT IGNORE INTO users (id, email, name) VALUES (?, ?, ?)", 
        [$userId, 'default@expense-tracker.local', 'Default User']);
    }

    $db->beginTransaction();

    $created = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['transactions'] as $txn) {
      try {
        // Check for duplicate (by reference number or exact match)
        if (isset($txn['reference_number']) && $txn['reference_number']) {
          $existing = $db->fetchOne(
            "SELECT id FROM transactions WHERE user_id = ? AND reference_number = ?",
            [$userId, $txn['reference_number']]
          );
          if ($existing) {
            $skipped++;
            continue;
          }
        }

        // Get or create bank account
        $accountId = getOrCreateBankAccount($db, $userId, $txn['bank'], $txn['account_number']);
        
        // Get or create category
        $categoryId = getOrCreateCategory($db, $userId, $txn['category'], $txn['transaction_type']);

        // Insert transaction
        $sql = "INSERT INTO transactions (user_id, account_id, category_id, transaction_type, amount,
                  merchant, description, transaction_date, reference_number, source, source_data)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $db->insert($sql, [
          $userId,
          $accountId,
          $categoryId,
          $txn['transaction_type'],
          $txn['amount'],
          $txn['merchant'] ?? null,
          $txn['description'] ?? null,
          $txn['date'] ?? $txn['transaction_date'] ?? date('Y-m-d'),
          $txn['reference_number'] ?? null,
          $txn['source'] ?? 'sms',
          isset($txn['source_data']) ? json_encode($txn['source_data']) : null
        ]);
        $created++;
      } catch (Exception $e) {
        $failed++;
        $errors[] = ($txn['reference_number'] ?? $txn['merchant'] ?? 'Unknown') . ': ' . $e->getMessage();
      }
    }

    // Log sync
    $logSql = "INSERT INTO scrape_logs (user_id, source_type, source_name, status, records_processed,
                 records_created, records_updated, records_failed, error_message)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $db->insert($logSql, [
      $userId,
      'bank_sms',
      $input['source'] ?? 'sms',
      $failed > 0 ? 'partial' : 'success',
      count($input['transactions']),
      $created,
      0,
      $failed,
      !empty($errors) ? implode('; ', $errors) : null
    ]);

    $db->commit();

    Response::success([
      'created' => $created,
      'skipped' => $skipped,
      'failed' => $failed,
      'errors' => $errors
    ], 'Transactions synced successfully');
  } catch (Exception $e) {
    $db->rollback();
    Response::error('Failed to sync transactions: ' . $e->getMessage(), 500);
  }
}

function syncFixedDeposits($userId)
{
  try {
    $input = getJsonInput();
    
    if (!isset($input['fixed_deposits']) || !is_array($input['fixed_deposits'])) {
      Response::error('Invalid data format. Expected array of fixed deposits', 400);
    }

    $db = getDB();
    $db->beginTransaction();

    $created = 0;
    $updated = 0;
    $failed = 0;

    foreach ($input['fixed_deposits'] as $fd) {
      try {
        // Check if FD exists
        $existing = $db->fetchOne(
          "SELECT id FROM fixed_deposits WHERE user_id = ? AND bank = ? AND fd_number = ?",
          [$userId, $fd['bank'], $fd['fd_number']]
        );

        if ($existing) {
          $sql = "UPDATE fixed_deposits SET 
                    principal_amount = ?, maturity_date = ?, maturity_value = ?, status = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $fd['principal_amount'],
            $fd['maturity_date'],
            $fd['maturity_value'],
            $fd['status'] ?? 'active',
            $existing['id']
          ]);
          $updated++;
        } else {
          $sql = "INSERT INTO fixed_deposits (user_id, bank, fd_number, principal_amount, interest_rate,
                    tenure_months, start_date, maturity_date, maturity_value, status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $fd['bank'],
            $fd['fd_number'],
            $fd['principal_amount'],
            $fd['interest_rate'],
            $fd['tenure_months'],
            $fd['start_date'],
            $fd['maturity_date'],
            $fd['maturity_value'],
            $fd['status'] ?? 'active'
          ]);
          $created++;
        }
      } catch (Exception $e) {
        $failed++;
      }
    }

    $db->commit();

    Response::success([
      'created' => $created,
      'updated' => $updated,
      'failed' => $failed
    ], 'Fixed deposits synced successfully');
  } catch (Exception $e) {
    $db->rollback();
    Response::error('Failed to sync fixed deposits: ' . $e->getMessage(), 500);
  }
}

function getSyncLogs($userId)
{
  try {
    $db = getDB();
    
    $limit = $_GET['limit'] ?? 50;
    $logs = $db->fetchAll(
      "SELECT * FROM scrape_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
      [$userId, (int)$limit]
    );

    Response::success($logs, 'Sync logs retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch sync logs: ' . $e->getMessage(), 500);
  }
}

/**
 * GET /api/sync/status/{jobId}
 * Get sync job status
 */
function getSyncJobStatus($jobId) {
  try {
    $tokenData = JWTHandler::requireAuth();
    $userId = $tokenData['userId'];
    $db = getDB();

    $query = "SELECT * FROM sync_jobs WHERE id = ? AND user_id = ?";
    $result = $db->fetchAll($query, [$jobId, $userId]);

    if (empty($result)) {
      Response::error('Sync job not found', 404);
      return;
    }

    $job = $result[0];
    Response::success([
      'id' => $job['id'],
      'type' => $job['type'],
      'status' => $job['status'],
      'progress' => $job['progress'],
      'totalItems' => $job['total_items'],
      'processedItems' => $job['processed_items'],
      'savedItems' => $job['saved_items'],
      'skippedItems' => $job['skipped_items'],
      'errorMessage' => $job['error_message'],
      'startedAt' => $job['started_at'],
      'completedAt' => $job['completed_at'],
      'createdAt' => $job['created_at']
    ]);
  } catch (Exception $e) {
    Response::error('Failed to fetch sync status: ' . $e->getMessage(), 500);
  }
}

/**
 * GET /api/sync/latest
 * Get latest sync job for user
 */
function getLatestSyncJob() {
  try {
    $tokenData = JWTHandler::requireAuth();
    $userId = $tokenData['userId'];
    $db = getDB();

    $query = "SELECT * FROM sync_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1";
    $result = $db->fetchAll($query, [$userId]);

    if (empty($result)) {
      Response::success(['job' => null]);
      return;
    }

    $job = $result[0];
    Response::success([
      'id' => $job['id'],
      'type' => $job['type'],
      'status' => $job['status'],
      'progress' => $job['progress'],
      'totalItems' => $job['total_items'],
      'processedItems' => $job['processed_items'],
      'savedItems' => $job['saved_items'],
      'skippedItems' => $job['skipped_items'],
      'errorMessage' => $job['error_message'],
      'startedAt' => $job['started_at'],
      'completedAt' => $job['completed_at'],
      'createdAt' => $job['created_at']
    ]);
  } catch (Exception $e) {
    Response::error('Failed to fetch latest sync: ' . $e->getMessage(), 500);
  }
}
