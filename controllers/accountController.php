<?php

function handleAccountRoutes($uri, $method)
{
  $userId = 1;

  if ($uri === '/accounts' && $method === 'GET') {
    getAccounts($userId);
  } elseif ($uri === '/accounts' && $method === 'POST') {
    createAccount($userId);
  } elseif (preg_match('/^\/accounts\/(\d+)$/', $uri, $matches) && $method === 'GET') {
    getAccountDetails($userId, $matches[1]);
  } elseif (preg_match('/^\/accounts\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
    updateAccount($userId, $matches[1]);
  } elseif (preg_match('/^\/accounts\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteAccount($userId, $matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getAccounts($userId)
{
  try {
    $db = getDB();
    $accounts = $db->fetchAll(
      "SELECT * FROM bank_accounts WHERE user_id = ? ORDER BY is_primary DESC, bank, account_type",
      [$userId]
    );

    // Get transaction count and last sync for each account
    foreach ($accounts as &$account) {
      $stats = $db->fetchOne(
        "SELECT COUNT(*) as transaction_count, 
                MAX(transaction_date) as last_transaction_date
         FROM transactions WHERE account_id = ?",
        [$account['id']]
      );
      $account['transaction_count'] = $stats['transaction_count'] ?? 0;
      $account['last_transaction_date'] = $stats['last_transaction_date'];
    }

    Response::success($accounts, 'Accounts retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch accounts: ' . $e->getMessage(), 500);
  }
}

function getAccountDetails($userId, $accountId)
{
  try {
    $db = getDB();
    
    $account = $db->fetchOne(
      "SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?",
      [$accountId, $userId]
    );

    if (!$account) {
      Response::error('Account not found', 404);
    }

    // Get recent transactions
    $transactions = $db->fetchAll(
      "SELECT t.*, c.name as category_name, c.color as category_color
       FROM transactions t
       JOIN categories c ON t.category_id = c.id
       WHERE t.account_id = ?
       ORDER BY t.transaction_date DESC
       LIMIT 50",
      [$accountId]
    );

    // Get monthly spending summary
    $monthlySummary = $db->fetchAll(
      "SELECT 
         DATE_FORMAT(transaction_date, '%Y-%m') as month,
         SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as debit,
         SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as credit
       FROM transactions
       WHERE account_id = ? 
         AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
       GROUP BY month
       ORDER BY month DESC",
      [$accountId]
    );

    Response::success([
      'account' => $account,
      'transactions' => $transactions,
      'monthly_summary' => $monthlySummary
    ], 'Account details retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch account details: ' . $e->getMessage(), 500);
  }
}

function createAccount($userId)
{
  try {
    $input = getJsonInput();
    
    $errors = validateRequired($input, ['bank', 'account_type', 'account_number', 'account_name']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $db = getDB();

    $sql = "INSERT INTO bank_accounts (user_id, bank, account_type, account_number, account_name,
              ifsc_code, branch, balance, credit_limit, available_credit, billing_date, due_date,
              card_last_four, is_primary, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $id = $db->insert($sql, [
      $userId,
      $input['bank'],
      $input['account_type'],
      $input['account_number'],
      $input['account_name'],
      $input['ifsc_code'] ?? null,
      $input['branch'] ?? null,
      $input['balance'] ?? 0,
      $input['credit_limit'] ?? 0,
      $input['available_credit'] ?? 0,
      $input['billing_date'] ?? null,
      $input['due_date'] ?? null,
      $input['card_last_four'] ?? null,
      $input['is_primary'] ?? false,
      $input['status'] ?? 'active'
    ]);

    Response::success(['id' => $id], 'Account created successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to create account: ' . $e->getMessage(), 500);
  }
}

function updateAccount($userId, $accountId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    $sql = "UPDATE bank_accounts SET 
              account_name = ?, balance = ?, credit_limit = ?, available_credit = ?,
              billing_date = ?, due_date = ?, status = ?, is_primary = ?
            WHERE id = ? AND user_id = ?";
    
    $affected = $db->execute($sql, [
      $input['account_name'],
      $input['balance'] ?? 0,
      $input['credit_limit'] ?? 0,
      $input['available_credit'] ?? 0,
      $input['billing_date'] ?? null,
      $input['due_date'] ?? null,
      $input['status'] ?? 'active',
      $input['is_primary'] ?? false,
      $accountId,
      $userId
    ]);

    if ($affected > 0) {
      Response::success(['id' => $accountId], 'Account updated successfully');
    } else {
      Response::error('Account not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to update account: ' . $e->getMessage(), 500);
  }
}

function deleteAccount($userId, $accountId)
{
  try {
    $db = getDB();
    
    // Check if account has transactions
    $count = $db->fetchOne(
      "SELECT COUNT(*) as count FROM transactions WHERE account_id = ?",
      [$accountId]
    );

    if ($count['count'] > 0) {
      Response::error('Cannot delete account with existing transactions', 400);
    }

    $affected = $db->execute("DELETE FROM bank_accounts WHERE id = ? AND user_id = ?", [$accountId, $userId]);

    if ($affected > 0) {
      Response::success(null, 'Account deleted successfully');
    } else {
      Response::error('Account not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete account: ' . $e->getMessage(), 500);
  }
}

