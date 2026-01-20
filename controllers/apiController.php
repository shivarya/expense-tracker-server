<?php

function handleApiTransactionRoutes($uri, $method)
{
  $userId = 1;

  // GET /api/transactions - Get transactions with filters
  if ($uri === '/api/transactions' && $method === 'GET') {
    getTransactions($userId);
  }
  else {
    Response::error('Route not found', 404);
  }
}

function getTransactions($userId)
{
  try {
    $db = getDB();
    
    // Get query parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $type = isset($_GET['type']) ? $_GET['type'] : null;
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    
    // Build query
    $sql = "SELECT 
              t.id,
              t.transaction_type,
              t.amount,
              t.merchant,
              t.description,
              t.transaction_date,
              t.reference_number,
              t.source,
              c.name as category_name,
              c.icon as category_icon,
              c.color as category_color,
              ba.bank,
              ba.account_number
            FROM transactions t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN bank_accounts ba ON t.account_id = ba.id
            WHERE t.user_id = ?";
    
    $params = [$userId];
    
    // Add type filter
    if ($type && in_array($type, ['debit', 'credit', 'transfer'])) {
      $sql .= " AND t.transaction_type = ?";
      $params[] = $type;
    }
    
    // Add date filter
    if ($days > 0) {
      $sql .= " AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
      $params[] = $days;
    }
    
    $sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $transactions = $db->fetchAll($sql, $params);
    
    Response::success($transactions);
  } catch (Exception $e) {
    Response::error('Failed to get transactions: ' . $e->getMessage(), 500);
  }
}
