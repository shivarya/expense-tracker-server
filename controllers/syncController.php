<?php

require_once __DIR__ . '/../utils/azureOpenAI.php';
require_once __DIR__ . '/../utils/transactionDuplicateDetector.php';
require_once __DIR__ . '/../utils/merchantSubscriptionDetector.php';

function normalizeAccountType($rawType) {
  $value = strtolower(trim((string)$rawType));
  if ($value === 'credit_card' || $value === 'credit card' || $value === 'card') {
    return 'credit_card';
  }
  if ($value === 'current') {
    return 'current';
  }
  return 'savings';
}

function detectAccountTypeAndCardLastFour($txn) {
  $sourceData = isset($txn['source_data']) && is_array($txn['source_data']) ? $txn['source_data'] : [];

  $accountType = normalizeAccountType($txn['account_type'] ?? '');
  $paymentMethod = strtolower((string)($txn['payment_method'] ?? ''));

  if ($accountType !== 'credit_card') {
    if (
      str_contains($paymentMethod, 'card') ||
      isset($sourceData['card_last4']) ||
      isset($sourceData['card_last_four']) ||
      isset($sourceData['card_type'])
    ) {
      $accountType = 'credit_card';
    }
  }

  $cardLastFour = null;
  if ($accountType === 'credit_card') {
    $candidate = $sourceData['card_last4'] ?? $sourceData['card_last_four'] ?? $txn['card_last_four'] ?? $txn['account_number'] ?? '';
    $digits = preg_replace('/\D+/', '', (string)$candidate);
    if (!empty($digits)) {
      $cardLastFour = substr($digits, -4);
    }
  }

  return [$accountType, $cardLastFour];
}

// Helper function to get or create bank account
function getOrCreateBankAccount($db, $userId, $bankName, $accountNumber, $accountType = 'savings', $cardLastFour = null, $cardType = null) {
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

  $accountType = normalizeAccountType($accountType);
  $lastFour = substr($cleanAccountNumber, -4);

  // Look up strictly within the same account_type first. A credit card's synthetic
  // account_number ('XXXX'.last4) can coincidentally equal a real bank account's
  // masked digits, so account_type must always be part of the match -- dropping it
  // (as this used to) risks silently flipping a real bank account into a credit
  // card row or vice versa. See CLAUDE.md's account_type collision note.
  $existing = $db->fetchOne(
    "SELECT id, card_last_four, card_type
     FROM bank_accounts
     WHERE user_id = ? AND bank = ? AND account_type = ? AND account_number = ?",
    [$userId, $bank, $accountType, $cleanAccountNumber]
  );

  if (!$existing && $accountType === 'credit_card' && $cardLastFour) {
    $existing = $db->fetchOne(
      "SELECT id, card_last_four, card_type
       FROM bank_accounts
       WHERE user_id = ? AND bank = ? AND account_type = 'credit_card'
         AND (card_last_four = ? OR account_number LIKE ?)",
      [$userId, $bank, $cardLastFour, '%' . $cardLastFour]
    );
  } elseif (!$existing) {
    $existing = $db->fetchOne(
      "SELECT id, card_last_four, card_type
       FROM bank_accounts
       WHERE user_id = ? AND bank = ? AND account_type = ? AND account_number LIKE ?",
      [$userId, $bank, $accountType, '%' . $lastFour]
    );
  }

  if ($existing) {
    if ($cardLastFour && empty($existing['card_last_four'])) {
      $db->execute(
        "UPDATE bank_accounts SET card_last_four = ? WHERE id = ?",
        [$cardLastFour, $existing['id']]
      );
    }
    if ($cardType && empty($existing['card_type'])) {
      $db->execute(
        "UPDATE bank_accounts SET card_type = ?, account_name = ? WHERE id = ?",
        [$cardType, strtoupper($bank) . ' ' . $cardType, $existing['id']]
      );
    }
    return $existing['id'];
  }

  // Create new account
  $accountName = $cardType
    ? (strtoupper($bank) . ' ' . $cardType)
    : ($bankName . ($accountType === 'credit_card' ? ' Card' : ' Account'));

  try {
    $sql = "INSERT INTO bank_accounts (user_id, bank, account_type, account_number, account_name, card_last_four, card_type, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    return $db->insert($sql, [
      $userId,
      $bank,
      $accountType,
      $cleanAccountNumber,
      $accountName,
      $accountType === 'credit_card' ? $cardLastFour : null,
      $cardType,
      'active'
    ]);
  } catch (Exception $e) {
    // unique_account (user_id, bank, account_number) collision with a DIFFERENT
    // account_type -- e.g. a credit card's synthetic number happens to match a
    // real bank account's masked digits. Never silently repurpose that row (the
    // old behavior here); surface it instead so it gets investigated.
    throw new Exception("Cannot create {$accountType} account for {$bank}/{$cleanAccountNumber}: account_number is already used by a different-typed account for this user. Original error: " . $e->getMessage());
  }
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
  // POST /sync/long-term - Receive long-term funds data (NPS/PF/PPF/etc)
  elseif ($uri === '/sync/long-term' && $method === 'POST') {
    syncLongTermFunds($userId);
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

    $sourceHint = (string)($input['source'] ?? '');
    $isCreditCardScraperSource = $sourceHint === 'credit_card_scraper_ai';
    $skipThreshold = $isCreditCardScraperSource ? 70 : 76;
    $duplicateThreshold = $isCreditCardScraperSource ? 45 : 51;

    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['stocks'] as $stock) {
      try {
        $platform = strtolower(trim($stock['platform'] ?? 'other'));
        if (!in_array($platform, ['zerodha', 'groww', 'cdsl', 'other'])) {
          $platform = 'other';
        }

        $symbol = trim($stock['symbol'] ?? '');
        $isin = trim($stock['isin'] ?? '');

        // Check if stock exists (by platform + symbol)
        if ($isin !== '') {
          $existing = $db->fetchOne(
            "SELECT id FROM stocks WHERE user_id = ? AND platform = ? AND (isin = ? OR symbol = ?)",
            [$userId, $platform, $isin, $symbol]
          );
        } else {
          $existing = $db->fetchOne(
            "SELECT id FROM stocks WHERE user_id = ? AND platform = ? AND symbol = ?",
            [$userId, $platform, $symbol]
          );
        }

        if ($existing) {
          // Update
          $sql = "UPDATE stocks SET 
                    company_name = ?, quantity = ?, average_price = ?, invested_amount = ?,
                    current_value = ?, current_price = ?, isin = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $stock['company_name'] ?? null,
            $stock['quantity'] ?? 0,
            $stock['average_price'] ?? 0,
            $stock['invested_amount'],
            $stock['current_value'],
            $stock['current_price'] ?? null,
            $isin !== '' ? $isin : null,
            $existing['id']
          ]);
          $updated++;
        } else {
          // Insert
          $sql = "INSERT INTO stocks (user_id, platform, symbol, isin, company_name, quantity, average_price,
                    invested_amount, current_value, current_price)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $platform,
            $symbol,
            $isin !== '' ? $isin : null,
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
      strtolower(trim($input['platform'] ?? 'mixed')),
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
        // Normalize folio: strip trailing "/XX" suffixes (CAMS uses "16901684/49", MF Central uses "16901684")
        $rawFolio = trim($fund['folio_number']);
        $cleanFolio = preg_replace('/\/\d+$/', '', $rawFolio);

        // Check if fund exists (by clean folio + fund name, also try with suffix variants)
        $existing = $db->fetchOne(
          "SELECT id FROM mutual_funds WHERE user_id = ? AND (folio_number = ? OR folio_number = ? OR folio_number LIKE ?) AND fund_name = ?",
          [$userId, $cleanFolio, $rawFolio, $cleanFolio . '/%', $fund['fund_name']]
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
          // Insert — store clean folio (without /XX suffix)
          $sql = "INSERT INTO mutual_funds (user_id, fund_name, folio_number, amc, units, nav,
                    invested_amount, current_value, plan_type, option_type, portal_url)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $fund['fund_name'],
            $cleanFolio,
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
  $totalTransactions = 0;
  $created = 0;
  $skipped = 0;
  $flaggedPossible = 0;
  $aiChecked = 0;
  $fallbackUsed = 0;
  $failed = 0;
  $errors = [];

  try {
    $input = getJsonInput();
    
    if (!isset($input['transactions']) || !is_array($input['transactions'])) {
      Response::error('Invalid data format. Expected array of transactions', 400);
    }

    $totalTransactions = count($input['transactions']);

    $db = getDB();
    $duplicateDetector = new TransactionDuplicateDetector($db->getConnection(), new AzureOpenAI());
    
    // Ensure user exists
    $user = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId]);
    if (!$user) {
      // Create default user if doesn't exist
      $db->execute("INSERT IGNORE INTO users (id, email, name) VALUES (?, ?, ?)", 
        [$userId, 'default@expense-tracker.local', 'Default User']);
    }

    $db->beginTransaction();

    $sourceHint = (string)($input['source'] ?? '');
    $isCreditCardScraperSource = $sourceHint === 'credit_card_scraper_ai';
    $skipThreshold = $isCreditCardScraperSource ? 70 : 76;
    $duplicateThreshold = $isCreditCardScraperSource ? 45 : 51;

    foreach ($input['transactions'] as $txn) {
      try {
        // Get or create bank account with account type/card metadata when available.
        [$accountType, $cardLastFour] = detectAccountTypeAndCardLastFour($txn);

        // Verify card identity before resolving the account: the card last4 derived
        // from source_data must agree with the declared account_number. A mismatch is
        // the fingerprint of a batch-level identity bleeding onto the wrong card, so
        // surface it for observability before it silently mis-attributes the row.
        $declaredLast4 = preg_replace('/\D+/', '', (string)($txn['account_number'] ?? ''));
        $declaredLast4 = $declaredLast4 !== '' ? substr($declaredLast4, -4) : '';
        if ($cardLastFour && $declaredLast4 && $cardLastFour !== $declaredLast4) {
          error_log('[TX_SYNC][CARD_IDENTITY_MISMATCH] user_id=' . $userId
            . ' account_number_last4=' . $declaredLast4
            . ' source_data_card_last4=' . $cardLastFour
            . ' ref=' . (string)($txn['reference_number'] ?? ''));
        }

        $sourceCardType = trim((string)($txn['source_data']['card_type'] ?? ''));

        $accountId = getOrCreateBankAccount(
          $db,
          $userId,
          $txn['bank'],
          $txn['account_number'],
          $accountType,
          $cardLastFour,
          $sourceCardType !== '' ? $sourceCardType : null
        );
        
        // Get or create category
        $categoryId = getOrCreateCategory($db, $userId, $txn['category'], $txn['transaction_type']);

        // Re-run with resolved account id for higher confidence, but keep conservative fallback.
        $evaluateOptions = [
          'account_id' => $accountId,
          'expand_linked_accounts' => true,
          'source_hint' => $sourceHint,
          'ai_enabled' => true,
          'skip_threshold' => $skipThreshold,
          'duplicate_threshold' => $duplicateThreshold,
        ];
        try {
          $duplicateCheck = $duplicateDetector->evaluate($userId, $txn, $evaluateOptions);
        } catch (Exception $e) {
          $goneAwayMsg = strtolower($e->getMessage());
          if (!str_contains($goneAwayMsg, 'server has gone away') && !str_contains($goneAwayMsg, 'lost connection')) {
            throw $e;
          }

          // $duplicateDetector holds a raw PDO handle captured before this loop started
          // (see construction above); a wait_timeout drop during a slow AI-backed evaluate()
          // call kills that handle permanently, so every remaining row would fail identically
          // without rebuilding the detector against a fresh connection. Same pattern as
          // smsParserController::evaluateDuplicateTransactionSafely().
          error_log('[TX_SYNC][DB] Connection lost during duplicate evaluation. Reconnecting and retrying once.');
          $db->forceReconnect();
          $duplicateDetector = new TransactionDuplicateDetector($db->getConnection(), new AzureOpenAI());
          $duplicateCheck = $duplicateDetector->evaluate($userId, $txn, $evaluateOptions);
        }

        if (!empty($duplicateCheck['ai_used'])) {
          $aiChecked++;
        }
        if (!empty($duplicateCheck['fallback_used'])) {
          $fallbackUsed++;
        }

        if (!empty($duplicateCheck['should_skip'])) {
          $skipped++;
          continue;
        }

        if (!empty($duplicateCheck['possible_duplicate'])) {
          $flaggedPossible++;
        }

        // Insert transaction
        $sql = "INSERT INTO transactions (user_id, account_id, category_id, transaction_type, amount,
                  currency, original_amount, original_currency,
                  merchant, description, transaction_date, reference_number, source, payment_method, source_data, duplicate_score)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Determine source enum value
        $sourceEnum = 'web_scrape'; // Default for scraped transactions
        if (isset($input['source']) && $input['source'] === 'sms_parser') {
          $sourceEnum = 'sms';
        } elseif (isset($input['source']) && $input['source'] === 'email_parser') {
          $sourceEnum = 'email';
        }
        
        // Map transaction_type from 'expense'/'income' to 'debit'/'credit'
        $transactionType = $txn['transaction_type'];
        if ($transactionType === 'expense') {
          $transactionType = 'debit';
        } elseif ($transactionType === 'income') {
          $transactionType = 'credit';
        }
        
        // Extract payment method from the correct field
        $paymentMethod = isset($txn['payment_method']) && $txn['payment_method'] ? $txn['payment_method'] : null;
        
        // Prepare source_data JSON (without payment_method since it's now a separate column)
        $sourceDataArray = isset($txn['source_data']) ? json_decode(json_encode($txn['source_data']), true) : [];

        // Currency: amount is stored in INR (home currency). For foreign spend the
        // scraper/parser supplies original_amount + original_currency for display.
        $currency = strtoupper(trim((string)($txn['currency'] ?? 'INR'))) ?: 'INR';
        $originalCurrency = isset($txn['original_currency']) && trim((string)$txn['original_currency']) !== ''
          ? strtoupper(trim((string)$txn['original_currency'])) : null;
        $originalAmount = $originalCurrency !== null && isset($txn['original_amount']) && is_numeric($txn['original_amount'])
          ? round((float)$txn['original_amount'], 2) : null;

        $newTxnId = $db->insert($sql, [
          $userId,
          $accountId,
          $categoryId,
          $transactionType,
          $txn['amount'],
          $currency,
          $originalAmount,
          $originalCurrency,
          $txn['merchant'] ?? null,
          $txn['description'] ?? null,
          $txn['date'] ?? $txn['transaction_date'] ?? date('Y-m-d'),
          $txn['reference_number'] ?? null,
          $sourceEnum,
          $paymentMethod,
          !empty($sourceDataArray) ? json_encode($sourceDataArray) : null,
          (int)($duplicateCheck['confidence'] ?? 0)
        ]);
        $created++;

        MerchantSubscriptionDetector::evaluateTransaction($db, (int)$userId, (int)$newTxnId);
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
      $totalTransactions,
      $created,
      0,
      $failed,
      !empty($errors) ? implode('; ', $errors) : null
    ]);

    // A mid-loop reconnect (above, or transparently inside Database::query()) replaces
    // the connection with one that was never told beginTransaction() — committing it
    // would throw "There is no active transaction" even though rows already persisted
    // via autocommit on the new connection. Only commit if a transaction is still live.
    if ($db->getConnection()->inTransaction()) {
      $db->commit();
    }

    $duplicatesFound = $skipped + $flaggedPossible;
    error_log('[TX_SYNC_SUMMARY][SYNC_API] user_id=' . $userId
      . ' source=' . ($input['source'] ?? 'sms')
      . ' total_tx=' . $totalTransactions
      . ' duplicates_found=' . $duplicatesFound
      . ' duplicates_skipped=' . $skipped
      . ' possible_duplicates=' . $flaggedPossible
      . ' synced=' . $created
      . ' failed=' . $failed);

    Response::success([
      'created' => $created,
      'skipped' => $skipped,
      'skipped_duplicates' => $skipped,
      'skipped_high_confidence' => $skipped,
      'flagged_possible_duplicates' => $flaggedPossible,
      'ai_checked_transactions' => $aiChecked,
      'duplicate_fallback_used' => $fallbackUsed,
      'failed' => $failed,
      'errors' => $errors
    ], 'Transactions synced successfully');
  } catch (Exception $e) {
    if (isset($db) && $db->getConnection()->inTransaction()) {
      $db->rollback();
    }

    $duplicatesFound = $skipped + $flaggedPossible;
    error_log('[TX_SYNC_SUMMARY][SYNC_API][FAILED] user_id=' . $userId
      . ' total_tx=' . $totalTransactions
      . ' duplicates_found=' . $duplicatesFound
      . ' duplicates_skipped=' . $skipped
      . ' possible_duplicates=' . $flaggedPossible
      . ' synced=' . $created
      . ' failed=' . $failed
      . ' error=' . $e->getMessage());

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
                    account_number = ?, principal_amount = ?, interest_rate = ?, tenure_months = ?,
                    start_date = ?, maturity_date = ?, maturity_value = ?, status = ?, auto_renewal = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $fd['account_number'] ?? null,
            $fd['principal_amount'],
            $fd['interest_rate'],
            $fd['tenure_months'],
            $fd['start_date'],
            $fd['maturity_date'],
            $fd['maturity_value'],
            $fd['status'] ?? 'active',
            $fd['auto_renewal'] ?? false,
            $existing['id']
          ]);
          $updated++;
        } else {
          $sql = "INSERT INTO fixed_deposits (user_id, bank, fd_number, account_number, principal_amount, interest_rate,
                    tenure_months, start_date, maturity_date, maturity_value, status, auto_renewal)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $fd['bank'],
            $fd['fd_number'],
            $fd['account_number'] ?? null,
            $fd['principal_amount'],
            $fd['interest_rate'],
            $fd['tenure_months'],
            $fd['start_date'],
            $fd['maturity_date'],
            $fd['maturity_value'],
            $fd['status'] ?? 'active',
            $fd['auto_renewal'] ?? false
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

function syncLongTermFunds($userId)
{
  try {
    $input = getJsonInput();

    if (!isset($input['long_term_funds']) || !is_array($input['long_term_funds'])) {
      Response::error('Invalid data format. Expected array of long_term_funds', 400);
    }

    $db = getDB();
    $db->beginTransaction();

    $created = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($input['long_term_funds'] as $fund) {
      try {
        $fundType = strtolower(trim($fund['fund_type'] ?? 'nps'));
        if (!in_array($fundType, ['pf', 'nps', 'sukanya', 'ppf', 'vpf'])) {
          $fundType = 'nps';
        }

        $pran = trim($fund['pran_number'] ?? '');
        $accountNumber = trim($fund['account_number'] ?? '');
        $accountName = trim($fund['account_name'] ?? 'NPS Account');

        if ($pran !== '') {
          $existing = $db->fetchOne(
            "SELECT id FROM long_term_funds WHERE user_id = ? AND fund_type = ? AND pran_number = ?",
            [$userId, $fundType, $pran]
          );
        } elseif ($accountNumber !== '') {
          $existing = $db->fetchOne(
            "SELECT id FROM long_term_funds WHERE user_id = ? AND fund_type = ? AND account_number = ?",
            [$userId, $fundType, $accountNumber]
          );
        } else {
          $existing = $db->fetchOne(
            "SELECT id FROM long_term_funds WHERE user_id = ? AND fund_type = ? AND account_name = ?",
            [$userId, $fundType, $accountName]
          );
        }

        if ($existing) {
          $sql = "UPDATE long_term_funds SET
                    account_name = ?, account_number = ?, pran_number = ?, uan_number = ?,
                    invested_amount = ?, current_value = ?, employer_contribution = ?, interest_earned = ?,
                    maturity_date = ?, maturity_value = ?, lock_in_period_years = ?, start_date = ?,
                    last_contribution_date = ?, status = ?
                  WHERE id = ?";
          $db->execute($sql, [
            $accountName,
            $accountNumber !== '' ? $accountNumber : null,
            $pran !== '' ? $pran : null,
            $fund['uan_number'] ?? null,
            $fund['invested_amount'] ?? 0,
            $fund['current_value'] ?? 0,
            $fund['employer_contribution'] ?? 0,
            $fund['interest_earned'] ?? 0,
            $fund['maturity_date'] ?? null,
            $fund['maturity_value'] ?? null,
            $fund['lock_in_period_years'] ?? null,
            $fund['start_date'] ?? null,
            $fund['last_contribution_date'] ?? null,
            $fund['status'] ?? 'active',
            $existing['id']
          ]);
          $updated++;
        } else {
          $sql = "INSERT INTO long_term_funds (
                    user_id, fund_type, account_name, account_number, pran_number, uan_number,
                    invested_amount, current_value, employer_contribution, interest_earned,
                    maturity_date, maturity_value, lock_in_period_years, start_date, last_contribution_date, status
                  )
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
          $db->insert($sql, [
            $userId,
            $fundType,
            $accountName,
            $accountNumber !== '' ? $accountNumber : null,
            $pran !== '' ? $pran : null,
            $fund['uan_number'] ?? null,
            $fund['invested_amount'] ?? 0,
            $fund['current_value'] ?? 0,
            $fund['employer_contribution'] ?? 0,
            $fund['interest_earned'] ?? 0,
            $fund['maturity_date'] ?? null,
            $fund['maturity_value'] ?? null,
            $fund['lock_in_period_years'] ?? null,
            $fund['start_date'] ?? null,
            $fund['last_contribution_date'] ?? null,
            $fund['status'] ?? 'active'
          ]);
          $created++;
        }
      } catch (Exception $e) {
        $failed++;
        $errors[] = ($fund['account_name'] ?? 'NPS Account') . ': ' . $e->getMessage();
      }
    }

    $logSql = "INSERT INTO scrape_logs (user_id, source_type, source_name, status, records_processed,
                 records_created, records_updated, records_failed, error_message)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $db->insert($logSql, [
      $userId,
      'nps',
      $input['source'] ?? 'nps_email',
      $failed > 0 ? 'partial' : 'success',
      count($input['long_term_funds']),
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
    ], 'Long-term funds synced successfully');
  } catch (Exception $e) {
    $db->rollback();
    Response::error('Failed to sync long-term funds: ' . $e->getMessage(), 500);
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


