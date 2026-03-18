<?php

require_once __DIR__ . '/../utils/categoryLearning.php';
require_once __DIR__ . '/groupController.php';

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
  } elseif (preg_match('/^\/transactions\/(\d+)\/splits$/', $uri, $matches) && $method === 'GET') {
    getTransactionSplits($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/transactions\/(\d+)\/splits$/', $uri, $matches) && ($method === 'PUT' || $method === 'PATCH')) {
    updateTransactionSplits($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/transactions\/(\d+)\/refund-allocations$/', $uri, $matches) && $method === 'GET') {
    getTransactionRefundAllocations($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/transactions\/(\d+)\/refund-allocations$/', $uri, $matches) && ($method === 'PUT' || $method === 'PATCH')) {
    updateTransactionRefundAllocations($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/transactions\/(\d+)\/category$/', $uri, $matches) && ($method === 'PATCH' || $method === 'PUT')) {
    updateTransactionCategory($userId, $matches[1]);
  } elseif (preg_match('/^\/transactions\/(\d+)$/', $uri, $matches) && ($method === 'PUT' || $method === 'PATCH')) {
    updateTransaction($userId, (int)$matches[1]);
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
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;
    $type = $_GET['type'] ?? null;
    $limit = $_GET['limit'] ?? 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    if ((int)$limit <= 0) {
      Response::error('Invalid limit', 422);
    }

    if ($offset < 0) {
      Response::error('Invalid offset', 422);
    }

    if ($groupId) {
      $group = $db->fetchOne(
        "SELECT id FROM transaction_groups WHERE id = ? AND user_id = ?",
        [$groupId, $userId]
      );

      if (!$group) {
        Response::error('Invalid group_id', 422);
      }
    }

    $params = [$userId];
    $sql = "SELECT t.*, c.name as category_name, c.color as category_color, c.icon as category_icon,
                   ba.bank, ba.account_type, ba.account_name
            FROM transactions t
            JOIN categories c ON t.category_id = c.id
            JOIN bank_accounts ba ON t.account_id = ba.id
            WHERE t.user_id = ? AND t.deleted_at IS NULL";

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

    $sql .= buildGroupFilterSql($groupId, $params, 't');

    $sql .= " ORDER BY t.transaction_date DESC, t.id DESC LIMIT ? OFFSET ?";
    $params[] = (int)$limit;
    $params[] = $offset;

    $transactions = $db->fetchAll($sql, $params);
    enrichTransactionsWithOverlayMeta($db, (int)$userId, $transactions);

    // Get summary
    $summaryParams = [$userId];
    $summarySQL = "SELECT
                    SUM(CASE WHEN t.transaction_type = 'debit' THEN t.amount ELSE 0 END) as total_debit,
                    SUM(CASE WHEN t.transaction_type = 'credit' THEN t.amount ELSE 0 END) as total_credit,
                    COUNT(*) as total_count
                   FROM transactions t
                   WHERE t.user_id = ? AND t.deleted_at IS NULL";

    if ($startDate) {
      $summarySQL .= " AND t.transaction_date >= ?";
      $summaryParams[] = $startDate;
    }
    if ($endDate) {
      $summarySQL .= " AND t.transaction_date <= ?";
      $summaryParams[] = $endDate . ' 23:59:59';
    }
    if ($accountId) {
      $summarySQL .= " AND t.account_id = ?";
      $summaryParams[] = $accountId;
    }
    if ($categoryId) {
      $summarySQL .= " AND t.category_id = ?";
      $summaryParams[] = $categoryId;
    }
    if ($type) {
      $summarySQL .= " AND t.transaction_type = ?";
      $summaryParams[] = $type;
    }

    $summarySQL .= buildGroupFilterSql($groupId, $summaryParams, 't');

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

function updateTransaction($userId, $id)
{
  try {
    $input = getJsonInput();

    if (!array_key_exists('merchant', $input)) {
      Response::error('merchant is required', 422);
    }

    $merchant = trim((string)$input['merchant']);
    if ($merchant === '') {
      Response::error('merchant cannot be empty', 422);
    }

    if (strlen($merchant) > 500) {
      Response::error('merchant must be 500 characters or less', 422);
    }

    $db = getDB();

    $existing = $db->fetchOne(
      "SELECT id FROM transactions WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [$id, $userId]
    );

    if (!$existing) {
      Response::error('Transaction not found', 404);
    }

    $db->execute(
      "UPDATE transactions SET merchant = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [$merchant, $id, $userId]
    );

    Response::success([
      'id' => (int)$id,
      'merchant' => $merchant,
    ], 'Transaction name updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update transaction: ' . $e->getMessage(), 500);
  }
}

function deleteTransaction($userId, $id)
{
  try {
    $db = getDB();
    $db->beginTransaction();

    // Soft delete: mark deleted_at so SMS re-sync won't recreate this transaction
    $affected = $db->execute(
      "UPDATE transactions SET deleted_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [$id, $userId]
    );

    if ($affected <= 0) {
      $db->rollback();
      Response::error('Transaction not found', 404);
    }

    if (overlayTableExists($db, 'transaction_splits')) {
      $db->execute(
        "UPDATE transaction_splits
         SET deleted_at = NOW()
         WHERE user_id = ? AND parent_transaction_id = ? AND deleted_at IS NULL",
        [$userId, $id]
      );
    }

    if (overlayTableExists($db, 'transaction_refund_allocations')) {
      $db->execute(
        "UPDATE transaction_refund_allocations
         SET deleted_at = NOW()
         WHERE user_id = ?
           AND deleted_at IS NULL
           AND (refund_transaction_id = ? OR expense_transaction_id = ?)",
        [$userId, $id, $id]
      );
    }

    $db->commit();
    Response::success(null, 'Transaction deleted successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }
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
            WHERE t.user_id = ? AND t.deleted_at IS NULL AND t.duplicate_score >= ?
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

function updateTransactionCategory($userId, $transactionId)
{
  try {
    $input = getJsonInput();

    if (empty($input['category_id'])) {
      Response::error('category_id is required', 422);
    }

    $db = getDB();

    // Verify transaction belongs to user and is not deleted
    $txn = $db->fetchOne(
      "SELECT id, merchant, description, category_id FROM transactions WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [$transactionId, $userId]
    );
    if (!$txn) {
      Response::error('Transaction not found', 404);
    }

    if (overlayTableExists($db, 'transaction_splits')) {
      $splitCount = (int)($db->fetchOne(
        "SELECT COUNT(*) as count
         FROM transaction_splits
         WHERE user_id = ? AND parent_transaction_id = ? AND deleted_at IS NULL",
        [$userId, $transactionId]
      )['count'] ?? 0);

      if ($splitCount > 0) {
        Response::error('Cannot update parent category on a split transaction. Update split lines instead.', 409);
      }
    }

    // Verify category exists and belongs to user (or is system)
    $cat = $db->fetchOne(
      "SELECT id, name, icon, color FROM categories WHERE id = ? AND (user_id IS NULL OR user_id = ?)",
      [$input['category_id'], $userId]
    );
    if (!$cat) {
      Response::error('Category not found', 404);
    }

    $newCategoryId = (int)$input['category_id'];
    $oldCategoryId = (int)$txn['category_id'];

    // Update the transaction category
    $db->execute(
      "UPDATE transactions SET category_id = ? WHERE id = ? AND user_id = ?",
      [$newCategoryId, $transactionId, $userId]
    );

    $learning = [
      'learned' => false,
      'reason' => 'unchanged_category'
    ];

    // Learn only when user changed category so future similar transactions auto-match.
    if ($newCategoryId !== $oldCategoryId) {
      $learning = CategoryLearning::learnFromTransaction(
        $db,
        (int)$userId,
        $txn,
        $newCategoryId,
        (int)$transactionId
      );
    }

    Response::success([
      'id' => (int)$transactionId,
      'category_id' => $newCategoryId,
      'category_name' => $cat['name'],
      'category_color' => $cat['color'],
      'category_icon' => $cat['icon'],
      'learning_applied' => (bool)($learning['learned'] ?? false),
      'learning_pattern' => $learning['pattern'] ?? null,
      'learning_reason' => $learning['reason'] ?? null
    ], 'Transaction category updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update transaction category: ' . $e->getMessage(), 500);
  }
}

function getTransactionSplits($userId, $transactionId)
{
  try {
    $db = getDB();
    $txn = getOwnedTransactionOrNull($db, (int)$userId, (int)$transactionId);

    if (!$txn) {
      Response::error('Transaction not found', 404);
    }

    if (!overlayTableExists($db, 'transaction_splits')) {
      Response::success([
        'transaction_id' => (int)$transactionId,
        'parent_amount' => round((float)$txn['amount'], 2),
        'is_split' => false,
        'split_count' => 0,
        'split_total' => 0,
        'splits' => [],
      ]);
    }

    $splits = $db->fetchAll(
      "SELECT s.id, s.category_id, s.amount, s.display_order, s.notes,
              c.name as category_name, c.color as category_color, c.icon as category_icon
       FROM transaction_splits s
       JOIN categories c ON c.id = s.category_id
       WHERE s.user_id = ? AND s.parent_transaction_id = ? AND s.deleted_at IS NULL
       ORDER BY s.display_order ASC, s.id ASC",
      [$userId, $transactionId]
    );

    $splitTotal = 0.0;
    foreach ($splits as &$split) {
      $split['id'] = (int)$split['id'];
      $split['category_id'] = (int)$split['category_id'];
      $split['display_order'] = (int)$split['display_order'];
      $split['amount'] = round((float)$split['amount'], 2);
      $splitTotal += (float)$split['amount'];
    }

    Response::success([
      'transaction_id' => (int)$transactionId,
      'parent_amount' => round((float)$txn['amount'], 2),
      'is_split' => count($splits) > 0,
      'split_count' => count($splits),
      'split_total' => round($splitTotal, 2),
      'splits' => $splits,
    ], 'Transaction splits retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch transaction splits: ' . $e->getMessage(), 500);
  }
}

function updateTransactionSplits($userId, $transactionId)
{
  try {
    $db = getDB();

    if (!overlayTableExists($db, 'transaction_splits')) {
      Response::error('Transaction split feature is not enabled. Please run database migration 012.', 409);
    }

    $txn = getOwnedTransactionOrNull($db, (int)$userId, (int)$transactionId);
    if (!$txn) {
      Response::error('Transaction not found', 404);
    }

    if (($txn['transaction_type'] ?? '') !== 'debit') {
      Response::error('Only debit transactions can be split.', 422);
    }

    $input = getJsonInput();
    $splitInput = $input['splits'] ?? null;
    if (!is_array($splitInput)) {
      Response::error('splits array is required', 422);
    }

    $validatedSplits = [];
    $splitTotal = 0.0;

    foreach ($splitInput as $index => $split) {
      if (!is_array($split)) {
        Response::error('Each split entry must be an object', 422);
      }

      $categoryId = (int)($split['category_id'] ?? 0);
      if ($categoryId <= 0) {
        Response::error('Each split requires category_id', 422);
      }

      $amount = normalizeMoney((float)($split['amount'] ?? 0));
      if ($amount <= 0) {
        Response::error('Each split requires a positive amount', 422);
      }

      $category = $db->fetchOne(
        "SELECT id FROM categories WHERE id = ? AND (user_id IS NULL OR user_id = ?)",
        [$categoryId, $userId]
      );
      if (!$category) {
        Response::error('Invalid category_id in split entry', 422);
      }

      $validatedSplits[] = [
        'category_id' => $categoryId,
        'amount' => $amount,
        'display_order' => $index,
        'notes' => isset($split['notes']) ? trim((string)$split['notes']) : null,
      ];
      $splitTotal += $amount;
    }

    $parentAmount = normalizeMoney((float)$txn['amount']);

    // Allow empty array to clear splits.
    if (!empty($validatedSplits) && abs($splitTotal - $parentAmount) > 0.01) {
      Response::error('Split amounts must sum to the parent amount exactly.', 422, [
        'parent_amount' => $parentAmount,
        'split_total' => round($splitTotal, 2),
      ]);
    }

    $db->beginTransaction();

    $db->execute(
      "UPDATE transaction_splits
       SET deleted_at = NOW()
       WHERE user_id = ? AND parent_transaction_id = ? AND deleted_at IS NULL",
      [$userId, $transactionId]
    );

    foreach ($validatedSplits as $split) {
      $db->insert(
        "INSERT INTO transaction_splits (user_id, parent_transaction_id, category_id, amount, display_order, notes)
         VALUES (?, ?, ?, ?, ?, ?)",
        [
          $userId,
          $transactionId,
          $split['category_id'],
          $split['amount'],
          $split['display_order'],
          $split['notes'],
        ]
      );
    }

    $db->commit();

    $fresh = buildSplitPayload($db, (int)$userId, (int)$transactionId, (float)$txn['amount']);
    Response::success($fresh, 'Transaction splits saved successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }
    Response::error('Failed to save transaction splits: ' . $e->getMessage(), 500);
  }
}

function getTransactionRefundAllocations($userId, $transactionId)
{
  try {
    $db = getDB();
    $txn = getOwnedTransactionOrNull($db, (int)$userId, (int)$transactionId);

    if (!$txn) {
      Response::error('Transaction not found', 404);
    }

    if (!overlayTableExists($db, 'transaction_refund_allocations')) {
      Response::success([
        'transaction_id' => (int)$transactionId,
        'transaction_type' => $txn['transaction_type'],
        'mode' => ($txn['transaction_type'] === 'credit') ? 'from_refund' : 'to_expense',
        'total_allocated' => 0,
        'remaining_refundable' => ($txn['transaction_type'] === 'credit') ? round((float)$txn['amount'], 2) : null,
        'allocations' => [],
      ]);
    }

    if ($txn['transaction_type'] === 'credit') {
      $rows = $db->fetchAll(
        "SELECT a.id, a.refund_transaction_id, a.expense_transaction_id, a.amount, a.notes,
                e.merchant as expense_merchant, e.description as expense_description,
                e.transaction_date as expense_transaction_date,
                c.name as expense_category_name
         FROM transaction_refund_allocations a
         JOIN transactions e ON e.id = a.expense_transaction_id
         LEFT JOIN categories c ON c.id = e.category_id
         WHERE a.user_id = ?
           AND a.refund_transaction_id = ?
           AND a.deleted_at IS NULL
           AND e.deleted_at IS NULL
         ORDER BY a.id ASC",
        [$userId, $transactionId]
      );

      $totalAllocated = 0.0;
      foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['refund_transaction_id'] = (int)$row['refund_transaction_id'];
        $row['expense_transaction_id'] = (int)$row['expense_transaction_id'];
        $row['amount'] = round((float)$row['amount'], 2);
        $totalAllocated += (float)$row['amount'];
      }

      $refundAmount = round((float)$txn['amount'], 2);
      Response::success([
        'transaction_id' => (int)$transactionId,
        'transaction_type' => 'credit',
        'mode' => 'from_refund',
        'refund_amount' => $refundAmount,
        'total_allocated' => round($totalAllocated, 2),
        'remaining_refundable' => round(max(0, $refundAmount - $totalAllocated), 2),
        'allocations' => $rows,
      ], 'Refund allocations retrieved successfully');
    }

    if ($txn['transaction_type'] === 'debit') {
      $rows = $db->fetchAll(
        "SELECT a.id, a.refund_transaction_id, a.expense_transaction_id, a.amount, a.notes,
                r.merchant as refund_merchant, r.description as refund_description,
                r.transaction_date as refund_transaction_date
         FROM transaction_refund_allocations a
         JOIN transactions r ON r.id = a.refund_transaction_id
         WHERE a.user_id = ?
           AND a.expense_transaction_id = ?
           AND a.deleted_at IS NULL
           AND r.deleted_at IS NULL
         ORDER BY a.id ASC",
        [$userId, $transactionId]
      );

      $totalAllocated = 0.0;
      foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['refund_transaction_id'] = (int)$row['refund_transaction_id'];
        $row['expense_transaction_id'] = (int)$row['expense_transaction_id'];
        $row['amount'] = round((float)$row['amount'], 2);
        $totalAllocated += (float)$row['amount'];
      }

      Response::success([
        'transaction_id' => (int)$transactionId,
        'transaction_type' => 'debit',
        'mode' => 'to_expense',
        'total_allocated' => round($totalAllocated, 2),
        'allocations' => $rows,
      ], 'Refund allocations retrieved successfully');
    }

    Response::error('Refund allocations are supported only for debit/credit transactions.', 422);
  } catch (Exception $e) {
    Response::error('Failed to fetch refund allocations: ' . $e->getMessage(), 500);
  }
}

function updateTransactionRefundAllocations($userId, $transactionId)
{
  try {
    $db = getDB();

    if (!overlayTableExists($db, 'transaction_refund_allocations')) {
      Response::error('Refund allocation feature is not enabled. Please run database migration 012.', 409);
    }

    $refundTxn = getOwnedTransactionOrNull($db, (int)$userId, (int)$transactionId);
    if (!$refundTxn) {
      Response::error('Transaction not found', 404);
    }

    if (($refundTxn['transaction_type'] ?? '') !== 'credit') {
      Response::error('Refund allocations can only be edited on credit transactions.', 422);
    }

    $input = getJsonInput();
    $allocInput = $input['allocations'] ?? null;
    if (!is_array($allocInput)) {
      Response::error('allocations array is required', 422);
    }

    $validated = [];
    $seenExpenseIds = [];
    $totalAllocated = 0.0;

    foreach ($allocInput as $entry) {
      if (!is_array($entry)) {
        Response::error('Each allocation entry must be an object', 422);
      }

      $expenseId = (int)($entry['expense_transaction_id'] ?? 0);
      if ($expenseId <= 0) {
        Response::error('Each allocation requires expense_transaction_id', 422);
      }

      if (isset($seenExpenseIds[$expenseId])) {
        Response::error('Duplicate expense_transaction_id is not allowed in allocations', 422);
      }
      $seenExpenseIds[$expenseId] = true;

      $amount = normalizeMoney((float)($entry['amount'] ?? 0));
      if ($amount <= 0) {
        Response::error('Each allocation requires a positive amount', 422);
      }

      $expenseTxn = $db->fetchOne(
        "SELECT id, transaction_type, amount
         FROM transactions
         WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
        [$expenseId, $userId]
      );
      if (!$expenseTxn) {
        Response::error('Invalid expense_transaction_id in allocation', 422);
      }
      if (($expenseTxn['transaction_type'] ?? '') !== 'debit') {
        Response::error('Allocations must target debit transactions only', 422);
      }

      $validated[] = [
        'expense_transaction_id' => $expenseId,
        'amount' => $amount,
        'notes' => isset($entry['notes']) ? trim((string)$entry['notes']) : null,
      ];
      $totalAllocated += $amount;
    }

    $refundAmount = normalizeMoney((float)$refundTxn['amount']);
    if ($totalAllocated - $refundAmount > 0.01) {
      Response::error('Allocated refund amount exceeds the refund transaction amount.', 422, [
        'refund_amount' => $refundAmount,
        'allocated_total' => round($totalAllocated, 2),
      ]);
    }

    $db->beginTransaction();

    $db->execute(
      "UPDATE transaction_refund_allocations
       SET deleted_at = NOW()
       WHERE user_id = ? AND refund_transaction_id = ? AND deleted_at IS NULL",
      [$userId, $transactionId]
    );

    foreach ($validated as $entry) {
      $db->insert(
        "INSERT INTO transaction_refund_allocations (user_id, refund_transaction_id, expense_transaction_id, amount, notes)
         VALUES (?, ?, ?, ?, ?)",
        [
          $userId,
          $transactionId,
          $entry['expense_transaction_id'],
          $entry['amount'],
          $entry['notes'],
        ]
      );
    }

    $db->commit();

    $freshRows = $db->fetchAll(
      "SELECT a.id, a.refund_transaction_id, a.expense_transaction_id, a.amount, a.notes,
              e.merchant as expense_merchant, e.description as expense_description,
              e.transaction_date as expense_transaction_date,
              c.name as expense_category_name
       FROM transaction_refund_allocations a
       JOIN transactions e ON e.id = a.expense_transaction_id
       LEFT JOIN categories c ON c.id = e.category_id
       WHERE a.user_id = ?
         AND a.refund_transaction_id = ?
         AND a.deleted_at IS NULL
         AND e.deleted_at IS NULL
       ORDER BY a.id ASC",
      [$userId, $transactionId]
    );

    $allocatedTotal = 0.0;
    foreach ($freshRows as &$row) {
      $row['id'] = (int)$row['id'];
      $row['refund_transaction_id'] = (int)$row['refund_transaction_id'];
      $row['expense_transaction_id'] = (int)$row['expense_transaction_id'];
      $row['amount'] = round((float)$row['amount'], 2);
      $allocatedTotal += (float)$row['amount'];
    }

    Response::success([
      'transaction_id' => (int)$transactionId,
      'transaction_type' => 'credit',
      'mode' => 'from_refund',
      'refund_amount' => round($refundAmount, 2),
      'total_allocated' => round($allocatedTotal, 2),
      'remaining_refundable' => round(max(0, $refundAmount - $allocatedTotal), 2),
      'allocations' => $freshRows,
    ], 'Refund allocations saved successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }
    Response::error('Failed to save refund allocations: ' . $e->getMessage(), 500);
  }
}

function enrichTransactionsWithOverlayMeta($db, int $userId, array &$transactions): void
{
  if (empty($transactions)) {
    return;
  }

  $ids = array_values(array_map(static fn($txn) => (int)$txn['id'], $transactions));
  $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
  if (empty($ids)) {
    return;
  }

  $splitMap = [];
  if (overlayTableExists($db, 'transaction_splits')) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
      "SELECT parent_transaction_id, COUNT(*) as split_count, COALESCE(SUM(amount), 0) as split_total
       FROM transaction_splits
       WHERE user_id = ?
         AND deleted_at IS NULL
         AND parent_transaction_id IN ($in)
       GROUP BY parent_transaction_id",
      array_merge([$userId], $ids)
    );

    foreach ($rows as $row) {
      $splitMap[(int)$row['parent_transaction_id']] = [
        'split_count' => (int)$row['split_count'],
        'split_total' => round((float)$row['split_total'], 2),
      ];
    }
  }

  $incomingRefundMap = [];
  $outgoingRefundMap = [];

  if (overlayTableExists($db, 'transaction_refund_allocations')) {
    $in = implode(',', array_fill(0, count($ids), '?'));

    $incoming = $db->fetchAll(
      "SELECT expense_transaction_id, COUNT(*) as allocation_count, COALESCE(SUM(amount), 0) as allocated_amount
       FROM transaction_refund_allocations
       WHERE user_id = ?
         AND deleted_at IS NULL
         AND expense_transaction_id IN ($in)
       GROUP BY expense_transaction_id",
      array_merge([$userId], $ids)
    );

    foreach ($incoming as $row) {
      $incomingRefundMap[(int)$row['expense_transaction_id']] = [
        'allocation_count' => (int)$row['allocation_count'],
        'allocated_amount' => round((float)$row['allocated_amount'], 2),
      ];
    }

    $outgoing = $db->fetchAll(
      "SELECT refund_transaction_id, COUNT(*) as allocation_count, COALESCE(SUM(amount), 0) as allocated_amount
       FROM transaction_refund_allocations
       WHERE user_id = ?
         AND deleted_at IS NULL
         AND refund_transaction_id IN ($in)
       GROUP BY refund_transaction_id",
      array_merge([$userId], $ids)
    );

    foreach ($outgoing as $row) {
      $outgoingRefundMap[(int)$row['refund_transaction_id']] = [
        'allocation_count' => (int)$row['allocation_count'],
        'allocated_amount' => round((float)$row['allocated_amount'], 2),
      ];
    }
  }

  foreach ($transactions as &$txn) {
    $id = (int)$txn['id'];

    $splitMeta = $splitMap[$id] ?? ['split_count' => 0, 'split_total' => 0.0];
    $incomingRefundMeta = $incomingRefundMap[$id] ?? ['allocation_count' => 0, 'allocated_amount' => 0.0];
    $outgoingRefundMeta = $outgoingRefundMap[$id] ?? ['allocation_count' => 0, 'allocated_amount' => 0.0];

    $txn['has_split'] = $splitMeta['split_count'] > 0;
    $txn['split_count'] = (int)$splitMeta['split_count'];
    $txn['split_total'] = round((float)$splitMeta['split_total'], 2);

    $txn['refund_allocation_count'] = (int)$incomingRefundMeta['allocation_count'];
    $txn['refund_allocated_amount'] = round((float)$incomingRefundMeta['allocated_amount'], 2);

    $txn['refund_targets_count'] = (int)$outgoingRefundMeta['allocation_count'];
    $txn['refund_allocated_out'] = round((float)$outgoingRefundMeta['allocated_amount'], 2);
  }
}

function buildSplitPayload($db, int $userId, int $transactionId, float $parentAmount): array
{
  $splits = $db->fetchAll(
    "SELECT s.id, s.category_id, s.amount, s.display_order, s.notes,
            c.name as category_name, c.color as category_color, c.icon as category_icon
     FROM transaction_splits s
     JOIN categories c ON c.id = s.category_id
     WHERE s.user_id = ? AND s.parent_transaction_id = ? AND s.deleted_at IS NULL
     ORDER BY s.display_order ASC, s.id ASC",
    [$userId, $transactionId]
  );

  $splitTotal = 0.0;
  foreach ($splits as &$split) {
    $split['id'] = (int)$split['id'];
    $split['category_id'] = (int)$split['category_id'];
    $split['display_order'] = (int)$split['display_order'];
    $split['amount'] = round((float)$split['amount'], 2);
    $splitTotal += (float)$split['amount'];
  }

  return [
    'transaction_id' => $transactionId,
    'parent_amount' => round($parentAmount, 2),
    'is_split' => count($splits) > 0,
    'split_count' => count($splits),
    'split_total' => round($splitTotal, 2),
    'splits' => $splits,
  ];
}

function getOwnedTransactionOrNull($db, int $userId, int $transactionId): ?array
{
  $txn = $db->fetchOne(
    "SELECT id, user_id, account_id, category_id, transaction_type, amount, transaction_date, merchant, description
     FROM transactions
     WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
    [$transactionId, $userId]
  );

  return $txn ?: null;
}

function overlayTableExists($db, string $table): bool
{
  static $cache = [];

  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
    $cache[$table] = false;
    return false;
  }

  try {
    $result = $db->fetchOne(
      "SELECT COUNT(*) as table_count
       FROM information_schema.tables
       WHERE table_schema = DATABASE()
         AND table_name = ?",
      [$table]
    );

    $cache[$table] = ((int)($result['table_count'] ?? 0)) > 0;
  } catch (Exception $e) {
    try {
      // Fallback probe for environments where information_schema visibility is restricted.
      $db->fetchOne("SELECT 1 FROM `{$table}` LIMIT 1");
      $cache[$table] = true;
    } catch (Exception $inner) {
      $cache[$table] = false;
    }
  }

  return $cache[$table];
}

function normalizeMoney(float $amount): float
{
  return round($amount, 2);
}
