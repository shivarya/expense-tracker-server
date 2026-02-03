<?php

function handleTransactionRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/transactions' && $method === 'GET') {
    getTransactions($userId);
  } elseif ($uri === '/transactions/duplicates' && $method === 'GET') {
    getDuplicateTransactions($userId);
  } elseif ($uri === '/transactions' && $method === 'POST') {
    createTransaction($userId);
  } elseif (preg_match('/^\/transactions\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteTransaction($userId, $matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getTransactions($userId)
{
  try {
    $db = getDB();
    
    // Get query parameters
    $startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
    $endDate = $_GET['end_date'] ?? date('Y-m-t');     // Last day of current month
    $accountId = $_GET['account_id'] ?? null;
    $categoryId = $_GET['category_id'] ?? null;
    $type = $_GET['type'] ?? null;
    $limit = $_GET['limit'] ?? 100;

    $params = [$userId];
    $sql = "SELECT t.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                   ba.bank, ba.account_type, ba.account_name
            FROM transactions t
            JOIN categories c ON t.category_id = c.id
            JOIN bank_accounts ba ON t.account_id = ba.id
            WHERE t.user_id = ?";

    if ($startDate) {
      $sql .= " AND t.transaction_date >= ?";
      $params[] = $startDate;
    }
    if ($endDate) {
      $sql .= " AND t.transaction_date <= ?";
      $params[] = $endDate . ' 23:59:59';
    }
    if ($accountId) {
      $sql .= " AND t.account_id = ?";
      $params[] = $accountId;
    }
    if ($categoryId) {
      $sql .= " AND t.category_id = ?";
      $params[] = $categoryId;
    }
    if ($type) {
      $sql .= " AND t.transaction_type = ?";
      $params[] = $type;
    }

    $sql .= " ORDER BY t.transaction_date DESC LIMIT ?";
    $params[] = (int)$limit;

    $transactions = $db->fetchAll($sql, $params);

    // Get summary
    $summarySQL = "SELECT 
                    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debit,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credit,
                    COUNT(*) as total_count
                   FROM transactions
                   WHERE user_id = ? AND transaction_date BETWEEN ? AND ?";
    $summaryParams = [$userId, $startDate, $endDate . ' 23:59:59'];
    $summary = $db->fetchOne($summarySQL, $summaryParams);

    Response::success([
      'transactions' => $transactions,
      'summary' => $summary
    ], 'Transactions retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch transactions: ' . $e->getMessage(), 500);
  }
}

function createTransaction($userId)
{
  try {
    $input = getJsonInput();
    
    // Validate required fields
    $errors = validateRequired($input, ['account_id', 'category_id', 'transaction_type', 'amount', 'transaction_date']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $db = getDB();

    $sql = "INSERT INTO transactions (user_id, account_id, category_id, transaction_type, amount,
              merchant, description, transaction_date, reference_number, source, source_data, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $id = $db->insert($sql, [
      $userId,
      $input['account_id'],
      $input['category_id'],
      $input['transaction_type'],
      $input['amount'],
      $input['merchant'] ?? null,
      $input['description'] ?? null,
      $input['transaction_date'],
      $input['reference_number'] ?? null,
      $input['source'] ?? 'manual',
      isset($input['source_data']) ? json_encode($input['source_data']) : null,
      $input['notes'] ?? null
    ]);

    Response::success(['id' => $id], 'Transaction created successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to create transaction: ' . $e->getMessage(), 500);
  }
}

function deleteTransaction($userId, $id)
{
  try {
    $db = getDB();
    $affected = $db->execute("DELETE FROM transactions WHERE id = ? AND user_id = ?", [$id, $userId]);

    if ($affected > 0) {
      Response::success(null, 'Transaction deleted successfully');
    } else {
      Response::error('Transaction not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete transaction: ' . $e->getMessage(), 500);
  }
}

function getDuplicateTransactions($userId)
{
  try {
    $db = getDB();
    
    // Get query parameter for minimum duplicate score threshold
    $minScore = $_GET['min_score'] ?? 51; // Default: 51% (possible duplicates)
    $limit = $_GET['limit'] ?? 100;

    $sql = "SELECT t.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                   ba.bank, ba.account_type, ba.account_name
            FROM transactions t
            JOIN categories c ON t.category_id = c.id
            JOIN bank_accounts ba ON t.account_id = ba.id
            WHERE t.user_id = ? AND t.duplicate_score >= ?
            ORDER BY t.duplicate_score DESC, t.transaction_date DESC
            LIMIT ?";

    $transactions = $db->fetchAll($sql, [$userId, (int)$minScore, (int)$limit]);

    // Group by duplicate score ranges for better UX
    $grouped = [
      'high_confidence' => [], // 76-100
      'medium_confidence' => [], // 51-75
      'low_confidence' => [] // 21-50
    ];

    foreach ($transactions as $txn) {
      $score = $txn['duplicate_score'];
      if ($score >= 76) {
        $grouped['high_confidence'][] = $txn;
      } elseif ($score >= 51) {
        $grouped['medium_confidence'][] = $txn;
      } else {
        $grouped['low_confidence'][] = $txn;
      }
    }

    Response::success([
      'transactions' => $transactions,
      'grouped' => $grouped,
      'summary' => [
        'total' => count($transactions),
        'high_confidence' => count($grouped['high_confidence']),
        'medium_confidence' => count($grouped['medium_confidence']),
        'low_confidence' => count($grouped['low_confidence'])
      ]
    ], 'Duplicate transactions retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch duplicate transactions: ' . $e->getMessage(), 500);
  }
}

