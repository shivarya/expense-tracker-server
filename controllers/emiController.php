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

    // Use Azure AI to detect duplicate EMIs intelligently
    $existingEmis = $db->fetchAll(
      "SELECT id, loan_name, loan_type, bank, principal_amount, tenure_months, emi_amount, start_date 
       FROM emis WHERE user_id = ? AND account_id = ?",
      [$userId, $input['account_id']]
    );

    if (!empty($existingEmis)) {
      $isDuplicate = checkDuplicateEmiWithAI($input, $existingEmis);
      if ($isDuplicate) {
        Response::error('EMI already exists', 422, ['duplicate' => 'A similar EMI plan already exists for this account']);
      }
    }

    // Calculate end date
    $startDate = new DateTime($input['start_date']);
    $endDate = clone $startDate;
    $endDate->modify('+' . $input['tenure_months'] . ' months');

    $sql = "INSERT INTO emis (user_id, account_id, loan_name, loan_type, bank, principal_amount,
              interest_rate, tenure_months, emi_amount, start_date, end_date, remaining_months,
              remaining_principal, due_date, status, auto_debit, next_payment_date, last_payment_date, total_paid)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
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
      $input['remaining_months'] ?? $input['tenure_months'],
      $input['remaining_principal'] ?? $input['principal_amount'],
      $input['due_date'],
      $input['status'] ?? 'active',
      $input['auto_debit'] ?? true,
      $input['next_payment_date'] ?? null,
      $input['last_payment_date'] ?? null,
      $input['total_paid'] ?? 0
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

function checkDuplicateEmiWithAI($newEmi, $existingEmis)
{
  try {
    // Prepare context for Azure AI
    $existingEmisList = array_map(function($emi) {
      return sprintf(
        "- %s (%s) - Principal: ₹%.2f, Tenure: %d months, EMI: ₹%.2f, Start: %s",
        $emi['loan_name'],
        $emi['loan_type'],
        $emi['principal_amount'],
        $emi['tenure_months'],
        $emi['emi_amount'],
        $emi['start_date']
      );
    }, $existingEmis);

    $prompt = sprintf(
      "You are a financial data analyst. Determine if the following EMI is a duplicate of any existing EMIs.\n\n" .
      "NEW EMI:\n" .
      "- Loan: %s (%s)\n" .
      "- Bank: %s\n" .
      "- Principal: ₹%.2f\n" .
      "- Tenure: %d months\n" .
      "- EMI Amount: ₹%.2f\n" .
      "- Start Date: %s\n\n" .
      "EXISTING EMIs:\n%s\n\n" .
      "Consider these factors:\n" .
      "1. Same loan name and similar amounts (within 10%% variance)\n" .
      "2. Same start date or within 1 month\n" .
      "3. Similar tenure and EMI amounts\n" .
      "4. Merchant name variations (e.g., 'AMAZON' vs 'Amazon Pay')\n\n" .
      "Respond with ONLY 'YES' if it's a duplicate, or 'NO' if it's a new EMI. No explanation needed.",
      $newEmi['loan_name'],
      $newEmi['loan_type'],
      $newEmi['bank'],
      $newEmi['principal_amount'],
      $newEmi['tenure_months'],
      $newEmi['emi_amount'],
      $newEmi['start_date'],
      implode("\n", $existingEmisList)
    );

    // Call Azure OpenAI
    $endpoint = getenv('AZURE_OPENAI_ENDPOINT');
    $apiKey = getenv('AZURE_OPENAI_API_KEY');
    $deployment = getenv('AZURE_OPENAI_DEPLOYMENT');

    if (!$endpoint || !$apiKey || !$deployment) {
      error_log('Azure OpenAI not configured, falling back to simple duplicate check');
      // Fallback: simple exact match
      foreach ($existingEmis as $existing) {
        if ($existing['loan_name'] === $newEmi['loan_name'] && 
            $existing['start_date'] === $newEmi['start_date']) {
          return true;
        }
      }
      return false;
    }

    $url = rtrim($endpoint, '/') . '/openai/deployments/' . $deployment . '/chat/completions?api-version=2024-02-15-preview';
    
    $data = [
      'messages' => [
        ['role' => 'system', 'content' => 'You are a precise financial data analyst.'],
        ['role' => 'user', 'content' => $prompt]
      ],
      'max_tokens' => 10,
      'temperature' => 0
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'api-key: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      error_log('Azure OpenAI API error: ' . $response);
      // Fallback on API error
      foreach ($existingEmis as $existing) {
        if ($existing['loan_name'] === $newEmi['loan_name'] && 
            $existing['start_date'] === $newEmi['start_date']) {
          return true;
        }
      }
      return false;
    }

    $result = json_decode($response, true);
    $answer = trim($result['choices'][0]['message']['content'] ?? 'NO');
    
    return strtoupper($answer) === 'YES';

  } catch (Exception $e) {
    error_log('Error in AI duplicate check: ' . $e->getMessage());
    // Fallback to simple check on exception
    foreach ($existingEmis as $existing) {
      if ($existing['loan_name'] === $newEmi['loan_name'] && 
          $existing['start_date'] === $newEmi['start_date']) {
        return true;
      }
    }
    return false;
  }
}
