<?php

require_once __DIR__ . '/../utils/merchantSubscriptionDetector.php';

function handleSubscriptionRoutes($uri, $method)
{
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/subscriptions' && $method === 'GET') {
    getSubscriptions($userId);
  } elseif ($uri === '/subscriptions' && $method === 'POST') {
    createManualSubscription($userId);
  } elseif ($uri === '/subscriptions/scan' && $method === 'POST') {
    scanSubscriptions($userId);
  } elseif (preg_match('#^/subscriptions/(\d+)$#', $uri, $m) && $method === 'GET') {
    getSubscription($userId, (int)$m[1]);
  } elseif (preg_match('#^/subscriptions/(\d+)$#', $uri, $m) && ($method === 'PATCH' || $method === 'PUT')) {
    updateSubscription($userId, (int)$m[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

// Normalizes each row's cadence to a monthly-equivalent figure so the
// summary total is comparable across mixed weekly/monthly/quarterly/annual
// subscriptions.
function monthlyEquivalent(float $amount, string $billingCycle): float
{
  switch ($billingCycle) {
    case 'weekly':
      return $amount * 4.33;
    case 'quarterly':
      return $amount / 3;
    case 'annual':
      return $amount / 12;
    default:
      return $amount;
  }
}

function getSubscriptions($userId)
{
  try {
    $db = getDB();
    MerchantSubscriptionDetector::ensureTable($db);

    $status = $_GET['status'] ?? null;

    if ($status !== null) {
      if (!in_array($status, ['active', 'deactivated', 'dismissed'], true)) {
        Response::error('Invalid status filter', 422);
      }
      $subscriptions = $db->fetchAll(
        "SELECT * FROM merchant_subscriptions WHERE user_id = ? AND status = ?
         ORDER BY last_transaction_date DESC",
        [$userId, $status]
      );
    } else {
      // Default view excludes dismissed rows -- those are permanently
      // suppressed false positives, not something the user wants to see.
      $subscriptions = $db->fetchAll(
        "SELECT * FROM merchant_subscriptions WHERE user_id = ? AND status != 'dismissed'
         ORDER BY status ASC, last_transaction_date DESC",
        [$userId]
      );
    }

    $estimatedMonthlyTotal = 0.0;
    $activeCount = 0;
    foreach ($subscriptions as $sub) {
      if ($sub['status'] === 'active') {
        $activeCount++;
        $estimatedMonthlyTotal += monthlyEquivalent((float)$sub['average_amount'], $sub['billing_cycle']);
      }
    }

    Response::success([
      'subscriptions' => $subscriptions,
      'summary' => [
        'active_count' => $activeCount,
        'estimated_monthly_total' => round($estimatedMonthlyTotal, 2),
      ],
    ], 'Subscriptions retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch subscriptions: ' . $e->getMessage(), 500);
  }
}

function getSubscription($userId, $subscriptionId)
{
  try {
    $db = getDB();
    MerchantSubscriptionDetector::ensureTable($db);

    $subscription = $db->fetchOne(
      "SELECT * FROM merchant_subscriptions WHERE id = ? AND user_id = ?",
      [$subscriptionId, $userId]
    );
    if (!$subscription) {
      Response::error('Subscription not found', 404);
    }

    $subscription['transactions'] = $db->fetchAll(
      "SELECT id, merchant, amount, transaction_date, description
       FROM transactions
       WHERE merchant_subscription_id = ? AND user_id = ? AND deleted_at IS NULL
       ORDER BY transaction_date DESC",
      [$subscriptionId, $userId]
    );

    Response::success($subscription, 'Subscription retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch subscription: ' . $e->getMessage(), 500);
  }
}

function updateSubscription($userId, $subscriptionId)
{
  try {
    $input = getJsonInput();
    $db = getDB();
    MerchantSubscriptionDetector::ensureTable($db);

    $subscription = $db->fetchOne(
      "SELECT * FROM merchant_subscriptions WHERE id = ? AND user_id = ?",
      [$subscriptionId, $userId]
    );
    if (!$subscription) {
      Response::error('Subscription not found', 404);
    }

    $newStatus = $subscription['status'];
    if (array_key_exists('status', $input)) {
      if (!in_array($input['status'], ['active', 'deactivated', 'dismissed'], true)) {
        Response::error('Validation failed', 422, ['status' => 'Must be one of active, deactivated, dismissed']);
      }
      $newStatus = $input['status'];
    }

    $dismissedAt = $subscription['dismissed_at'];
    $deactivatedAt = $subscription['deactivated_at'];
    if ($newStatus === 'dismissed' && $subscription['status'] !== 'dismissed') {
      $dismissedAt = date('Y-m-d H:i:s');
    }
    if ($newStatus === 'deactivated' && $subscription['status'] !== 'deactivated') {
      $deactivatedAt = date('Y-m-d H:i:s');
    }
    if ($newStatus === 'active') {
      $dismissedAt = null;
      $deactivatedAt = null;
    }

    $db->execute(
      "UPDATE merchant_subscriptions SET
        status = ?,
        cancel_url = COALESCE(?, cancel_url),
        notes = ?,
        dismissed_at = ?,
        deactivated_at = ?,
        updated_at = NOW()
       WHERE id = ? AND user_id = ?",
      [
        $newStatus,
        $input['cancel_url'] ?? null,
        array_key_exists('notes', $input) ? $input['notes'] : $subscription['notes'],
        $dismissedAt,
        $deactivatedAt,
        $subscriptionId,
        $userId,
      ]
    );

    // A dismissed subscription was never actually a subscription -- unlink it
    // from every transaction currently tagged with it.
    if ($newStatus === 'dismissed') {
      MerchantSubscriptionDetector::unlinkTransactions($db, $userId, (int)$subscriptionId);
    }

    Response::success(['id' => $subscriptionId, 'status' => $newStatus], 'Subscription updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update subscription: ' . $e->getMessage(), 500);
  }
}

// For a subscription the user already knows about but that interval-based
// detection can't infer yet -- e.g. an annual insurance premium with only
// one payment in the transaction history so far, which is mathematically
// not enough to establish a recurring gap. Optionally anchored to a real
// transaction (inherits its merchant pattern/category/date, and links it),
// otherwise a bare merchant_subscriptions row keyed off the normalized name.
function createManualSubscription($userId)
{
  try {
    $input = getJsonInput();
    $errors = validateRequired($input, ['billing_cycle']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }
    if (!in_array($input['billing_cycle'], ['weekly', 'monthly', 'quarterly', 'annual'], true)) {
      Response::error('Validation failed', 422, ['billing_cycle' => 'Must be one of weekly, monthly, quarterly, annual']);
    }

    $db = getDB();
    MerchantSubscriptionDetector::ensureTable($db);

    $displayName = trim((string)($input['display_name'] ?? ''));
    $transactionId = isset($input['transaction_id']) ? (int)$input['transaction_id'] : null;
    $categoryId = isset($input['category_id']) && $input['category_id'] !== '' ? (int)$input['category_id'] : null;
    $amount = isset($input['average_amount']) ? (float)$input['average_amount'] : null;
    $anchorDate = date('Y-m-d');

    // display_name and average_amount are only optional when anchored to a
    // real transaction -- otherwise there's nothing to derive them from.
    if ($transactionId !== null) {
      $txn = $db->fetchOne(
        "SELECT id, merchant, amount, category_id, transaction_date FROM transactions WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
        [$transactionId, $userId]
      );
      if (!$txn) {
        Response::error('Validation failed', 422, ['transaction_id' => 'No such transaction for this user']);
      }
      $pattern = MerchantPattern::normalize((string)$txn['merchant']);
      if ($displayName === '') {
        $displayName = (string)$txn['merchant'];
      }
      if ($categoryId === null) {
        $categoryId = (int)$txn['category_id'];
      }
      if ($amount === null) {
        $amount = (float)$txn['amount'];
      }
      $anchorDate = substr((string)$txn['transaction_date'], 0, 10);
    } else {
      if ($displayName === '' || $amount === null) {
        $manualErrors = [];
        if ($displayName === '') $manualErrors['display_name'] = 'Required when not anchored to a transaction';
        if ($amount === null) $manualErrors['average_amount'] = 'Required when not anchored to a transaction';
        Response::error('Validation failed', 422, $manualErrors);
      }
      $pattern = MerchantPattern::normalize($displayName);
    }

    if ($amount <= 0) {
      Response::error('Validation failed', 422, ['average_amount' => 'Must be greater than 0']);
    }

    if ($pattern === '') {
      Response::error('Validation failed', 422, ['display_name' => 'Name is too generic to track — please be more specific']);
    }

    $nextExpected = MerchantSubscriptionDetector::projectNextDate($anchorDate, $input['billing_cycle']);
    $existing = $db->fetchOne(
      "SELECT id FROM merchant_subscriptions WHERE user_id = ? AND merchant_pattern = ?",
      [$userId, $pattern]
    );

    if ($existing) {
      // Re-adding something previously dismissed, or refreshing an existing
      // manual entry with new details -- either way this is an explicit
      // user action, so it always wins over whatever state was there.
      $db->execute(
        "UPDATE merchant_subscriptions SET
          display_name = ?, category_id = ?, billing_cycle = ?, average_amount = ?, last_amount = ?,
          amount_variance_percent = 0, occurrence_count = GREATEST(occurrence_count, 1),
          first_transaction_date = LEAST(first_transaction_date, ?), last_transaction_date = GREATEST(last_transaction_date, ?),
          next_expected_date = ?, status = 'active', detection_source = 'manual',
          cancel_url = COALESCE(?, cancel_url), notes = ?, dismissed_at = NULL, deactivated_at = NULL, updated_at = NOW()
         WHERE id = ?",
        [
          $displayName, $categoryId, $input['billing_cycle'], $amount, $amount,
          $anchorDate, $anchorDate, $nextExpected,
          $input['cancel_url'] ?? null, $input['notes'] ?? null,
          $existing['id'],
        ]
      );
      $subscriptionId = (int)$existing['id'];
    } else {
      $cancelUrl = (isset($input['cancel_url']) && $input['cancel_url'] !== '') ? $input['cancel_url'] : CancelUrlMap::lookup($displayName);
      $subscriptionId = (int)$db->insert(
        "INSERT INTO merchant_subscriptions
          (user_id, merchant_pattern, display_name, category_id, billing_cycle, average_amount, last_amount,
           amount_variance_percent, occurrence_count, first_transaction_date, last_transaction_date,
           next_expected_date, status, detection_source, cancel_url, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, 'active', 'manual', ?, ?)",
        [
          $userId, $pattern, $displayName, $categoryId, $input['billing_cycle'], $amount, $amount,
          $anchorDate, $anchorDate, $nextExpected,
          $cancelUrl, $input['notes'] ?? null,
        ]
      );
    }

    if ($transactionId !== null) {
      $db->execute(
        "UPDATE transactions SET is_subscription = TRUE, merchant_subscription_id = ?, merchant_pattern = ? WHERE id = ? AND user_id = ?",
        [$subscriptionId, $pattern, $transactionId, $userId]
      );
    }

    Response::success(['id' => $subscriptionId], 'Subscription added', 201);
  } catch (Exception $e) {
    Response::error('Failed to add subscription: ' . $e->getMessage(), 500);
  }
}

function scanSubscriptions($userId)
{
  try {
    $db = getDB();
    $result = MerchantSubscriptionDetector::runBulkScan($db, (int)$userId);
    Response::success($result, 'Subscription scan completed');
  } catch (Exception $e) {
    Response::error('Failed to scan for subscriptions: ' . $e->getMessage(), 500);
  }
}
