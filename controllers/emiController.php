<?php

function handleEmiRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/emis' && $method === 'GET') {
    getEmis($userId);
  } elseif ($uri === '/emis' && $method === 'POST') {
    createEmi($userId);
  } elseif (preg_match('/^\/emis\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
    updateEmi($userId, $matches[1]);
  } elseif (preg_match('/^\/emis\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteEmi($userId, $matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getEmis($userId)
{
  try {
    $db = getDB();
    
    $emis = $db->fetchAll(
      "SELECT e.*, ba.account_name, ba.bank as bank_name
       FROM emis e
       JOIN bank_accounts ba ON e.account_id = ba.id
       WHERE e.user_id = ?
       ORDER BY e.status ASC, e.next_payment_date ASC",
      [$userId]
    );

    Response::success($emis, 'EMIs retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch EMIs: ' . $e->getMessage(), 500);
  }
}

function createEmi($userId)
{
  try {
    $input = getJsonInput();
    
    $errors = validateRequired($input, ['account_id', 'loan_name', 'loan_type', 'bank', 
                                         'principal_amount', 'interest_rate', 'tenure_months', 
                                         'emi_amount', 'start_date', 'due_date']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $db = getDB();

    // Calculate end date
    $startDate = new DateTime($input['start_date']);
    $endDate = clone $startDate;
    $endDate->modify('+' . $input['tenure_months'] . ' months');

    $sql = "INSERT INTO emis (user_id, account_id, loan_name, loan_type, bank, principal_amount,
              interest_rate, tenure_months, emi_amount, start_date, end_date, remaining_months,
              remaining_principal, due_date, status, auto_debit, next_payment_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $id = $db->insert($sql, [
      $userId,
      $input['account_id'],
      $input['loan_name'],
      $input['loan_type'],
      $input['bank'],
      $input['principal_amount'],
      $input['interest_rate'],
      $input['tenure_months'],
      $input['emi_amount'],
      $input['start_date'],
      $endDate->format('Y-m-d'),
      $input['tenure_months'],
      $input['principal_amount'],
      $input['due_date'],
      'active',
      $input['auto_debit'] ?? true,
      $input['start_date']
    ]);

    Response::success(['id' => $id], 'EMI created successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to create EMI: ' . $e->getMessage(), 500);
  }
}

function updateEmi($userId, $emiId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    $sql = "UPDATE emis SET 
              remaining_months = ?, remaining_principal = ?, last_payment_date = ?,
              next_payment_date = ?, total_paid = ?, status = ?
            WHERE id = ? AND user_id = ?";
    
    $affected = $db->execute($sql, [
      $input['remaining_months'],
      $input['remaining_principal'],
      $input['last_payment_date'] ?? null,
      $input['next_payment_date'],
      $input['total_paid'] ?? 0,
      $input['status'] ?? 'active',
      $emiId,
      $userId
    ]);

    if ($affected > 0) {
      Response::success(['id' => $emiId], 'EMI updated successfully');
    } else {
      Response::error('EMI not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to update EMI: ' . $e->getMessage(), 500);
  }
}

function deleteEmi($userId, $emiId)
{
  try {
    $db = getDB();
    $affected = $db->execute("DELETE FROM emis WHERE id = ? AND user_id = ?", [$emiId, $userId]);

    if ($affected > 0) {
      Response::success(null, 'EMI deleted successfully');
    } else {
      Response::error('EMI not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete EMI: ' . $e->getMessage(), 500);
  }
}

