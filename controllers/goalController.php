<?php
require_once __DIR__ . '/../utils/loanMath.php';
require_once __DIR__ . '/../utils/goalMath.php';
require_once __DIR__ . '/../utils/portfolioMath.php';

function handleGoalRoutes($uri, $method)
{
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/goals' && $method === 'GET') {
    getGoals($userId);
  } elseif ($uri === '/goals' && $method === 'POST') {
    createGoal($userId);
  } elseif ($uri === '/goals/plan' && $method === 'GET') {
    getMonthlyPlan($userId);
  } elseif ($uri === '/goals/plan' && $method === 'PUT') {
    updateMonthlyPlanSettings($userId);
  } elseif (preg_match('#^/goals/(\d+)$#', $uri, $m) && $method === 'GET') {
    getGoal($userId, (int)$m[1]);
  } elseif (preg_match('#^/goals/(\d+)$#', $uri, $m) && $method === 'PUT') {
    updateGoal($userId, (int)$m[1]);
  } elseif (preg_match('#^/goals/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    deleteGoal($userId, (int)$m[1]);
  } elseif (preg_match('#^/goals/(\d+)/contributions$#', $uri, $m) && $method === 'GET') {
    getGoalContributions($userId, (int)$m[1]);
  } elseif (preg_match('#^/goals/(\d+)/contributions$#', $uri, $m) && $method === 'POST') {
    addGoalContribution($userId, (int)$m[1]);
  } elseif (preg_match('#^/goals/(\d+)/contributions/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    deleteGoalContribution($userId, (int)$m[1], (int)$m[2]);
  } else {
    Response::error('Route not found', 404);
  }
}

// ============================================
// Progress computation (per goal_type)
// ============================================

function computeGoalProgress($db, int $userId, array $goal): array
{
  switch ($goal['goal_type']) {
    case 'debt_payoff':
      return computeDebtPayoffProgress($db, $userId, $goal);
    case 'savings':
      return computeSavingsProgress($db, $userId, $goal);
    case 'net_worth':
      return computeNetWorthProgress($db, $userId, $goal);
    case 'spend_cap':
      return computeSpendCapProgress($db, $userId, $goal);
  }
  return [];
}

function computeDebtPayoffProgress($db, $userId, $goal)
{
  if (!$goal['emi_id']) {
    return ['progress_percent' => 0, 'is_achieved' => false, 'linked_loan_missing' => true];
  }

  $emi = $db->fetchOne("SELECT * FROM emis WHERE id = ? AND user_id = ?", [$goal['emi_id'], $userId]);
  if (!$emi) {
    return ['progress_percent' => 0, 'is_achieved' => false, 'linked_loan_missing' => true];
  }

  $principal = (float)$emi['principal_amount'];
  $remaining = (float)$emi['remaining_principal'];
  $progressPercent = $principal > 0 ? max(0, min(1, ($principal - $remaining) / $principal)) : 0;

  $projectedPayoffDate = (new DateTime())->modify('+' . (int)$emi['remaining_months'] . ' months')->format('Y-m-d');

  $result = [
    'progress_percent' => $progressPercent,
    'principal_amount' => $principal,
    'remaining_principal' => $remaining,
    'amount_paid_off' => $principal - $remaining,
    'remaining_months' => (int)$emi['remaining_months'],
    'current_emi' => (float)$emi['emi_amount'],
    'loan_name' => $emi['loan_name'],
    'projected_payoff_date' => $projectedPayoffDate,
    'is_achieved' => $progressPercent >= 1,
  ];

  if ($goal['target_date']) {
    $n = monthsBetweenDates($goal['target_date']);
    if ($n > 0) {
      $result['is_on_track'] = $projectedPayoffDate <= $goal['target_date'];

      $requiredEmi = calculateRequiredEmi($remaining, (float)$emi['interest_rate'], $n);
      $result['required_emi'] = round($requiredEmi, 2);
      $result['emi_increase_needed'] = round($requiredEmi - (float)$emi['emi_amount'], 2);

      $lumpsum = calculatePrepaymentForTargetTenure($remaining, (float)$emi['emi_amount'], (float)$emi['interest_rate'], $n);
      $result['lumpsum_needed'] = round(max(0, $lumpsum), 2);
      $result['already_on_track_without_prepayment'] = $lumpsum <= 0;
    } else {
      $result['is_on_track'] = $progressPercent >= 1;
      $result['required_emi'] = null;
      $result['lumpsum_needed'] = null;
      $result['target_date_passed'] = true;
    }
  }

  return $result;
}

function computeSavingsProgress($db, $userId, $goal)
{
  $sum = $db->fetchOne(
    "SELECT COALESCE(SUM(amount), 0) as total FROM goal_contributions WHERE goal_id = ? AND user_id = ?",
    [$goal['id'], $userId]
  );
  $current = (float)$goal['start_amount'] + (float)$sum['total'];
  $target = (float)$goal['target_amount'];
  $progressPercent = $target > 0 ? max(0, min(1, $current / $target)) : 0;
  $monthsRemaining = $goal['target_date'] ? monthsBetweenDates($goal['target_date']) : 0;

  return [
    'progress_percent' => $progressPercent,
    'current_amount' => $current,
    'target_amount' => $target,
    'months_remaining' => $monthsRemaining,
    'required_monthly_contribution' => $monthsRemaining > 0
      ? round(max(0, $target - $current) / $monthsRemaining, 2)
      : null,
    'is_achieved' => $current >= $target,
  ];
}

// Sums only the specifically-linked mutual_funds / long_term_funds rows
// instead of the whole portfolio -- for purpose-scoped goals (e.g. a child's
// corpus made of SSY + a couple of growth funds, not retirement EPF/NPS/PPF
// or debt/gold funds held for other reasons). Both id lists are validated
// against user_id so a goal can never read another user's holdings.
function getScopedAssetTotal($db, int $userId, ?array $mutualFundIds, ?array $longTermFundIds): float
{
  $total = 0.0;
  if (!empty($mutualFundIds)) {
    $placeholders = implode(',', array_fill(0, count($mutualFundIds), '?'));
    $row = $db->fetchOne(
      "SELECT COALESCE(SUM(current_value), 0) as total FROM mutual_funds WHERE user_id = ? AND id IN ($placeholders)",
      array_merge([$userId], $mutualFundIds)
    );
    $total += (float)$row['total'];
  }
  if (!empty($longTermFundIds)) {
    $placeholders = implode(',', array_fill(0, count($longTermFundIds), '?'));
    $row = $db->fetchOne(
      "SELECT COALESCE(SUM(current_value), 0) as total FROM long_term_funds WHERE user_id = ? AND id IN ($placeholders)",
      array_merge([$userId], $longTermFundIds)
    );
    $total += (float)$row['total'];
  }
  return $total;
}

function computeNetWorthProgress($db, $userId, $goal)
{
  $mfIds = $goal['linked_mutual_fund_ids'] ?? null;
  $ltfIds = $goal['linked_long_term_fund_ids'] ?? null;
  $isScoped = !empty($mfIds) || !empty($ltfIds);

  if ($isScoped) {
    $current = getScopedAssetTotal($db, $userId, $mfIds, $ltfIds);
  } else {
    $totals = getPortfolioTotals($db, $userId);
    $current = (float)$totals['totalValue'];
  }

  $target = (float)$goal['target_amount'];
  $progressPercent = $target > 0 ? max(0, min(1, $current / $target)) : 0;
  $monthsRemaining = $goal['target_date'] ? monthsBetweenDates($goal['target_date']) : 0;

  $rate = $goal['assumed_annual_return_percent'] !== null ? (float)$goal['assumed_annual_return_percent'] : 10.0;
  $pmt = $goal['assumed_monthly_contribution'] !== null ? (float)$goal['assumed_monthly_contribution'] : 0.0;
  $projected = calculateFutureValue($current, $pmt, $rate, $monthsRemaining);

  $result = [
    'progress_percent' => $progressPercent,
    'current_amount' => $current,
    'target_amount' => $target,
    'is_scoped' => $isScoped,
    'months_remaining' => $monthsRemaining,
    'assumed_annual_return_percent' => $rate,
    'assumed_monthly_contribution' => $pmt,
    'projected_value_at_target_date' => round($projected, 2),
    'is_on_track' => $projected >= $target,
    'is_achieved' => $current >= $target,
  ];

  if ($monthsRemaining > 0) {
    // Total level monthly investment needed to hit target exactly, and how
    // much of that is ON TOP OF whatever's already assumed to be going in --
    // this is the "invest more" suggestion, not just a projection.
    $requiredTotal = calculateRequiredMonthlyContribution($current, $target, $rate, $monthsRemaining);
    $result['required_monthly_contribution'] = round($requiredTotal, 2);
    $result['additional_monthly_contribution_needed'] = round(max(0, $requiredTotal - $pmt), 2);
  }

  return $result;
}

function computeSpendCapProgress($db, $userId, $goal)
{
  if ($goal['linked_category_ids']) {
    $categoryIds = json_decode($goal['linked_category_ids'], true) ?? [];
  } else {
    // Default: all expense-type categories except the fixed EMI/rent bucket.
    // Investments/Transfer are already excluded because they're type != 'expense'.
    $rows = $db->fetchAll(
      "SELECT id FROM categories WHERE (user_id = ? OR user_id IS NULL) AND type = 'expense' AND name != 'Rent/EMI'",
      [$userId]
    );
    $categoryIds = array_column($rows, 'id');
  }
  if (empty($categoryIds)) $categoryIds = [0]; // no matching categories -> zero spend, not a SQL error

  $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
  $sum = $db->fetchOne(
    "SELECT COALESCE(SUM(t.amount), 0) as total
     FROM transactions t
     WHERE t.user_id = ?
       AND t.deleted_at IS NULL
       AND t.transaction_type = 'debit'
       AND YEAR(t.transaction_date) = YEAR(CURDATE())
       AND MONTH(t.transaction_date) = MONTH(CURDATE())
       AND t.category_id IN ($placeholders)",
    array_merge([$userId], $categoryIds)
  );

  // Use the same "now" the transaction filter above used (MySQL CURDATE()),
  // not PHP's date() -- they can disagree by a day right around midnight IST
  // if the DB server's timezone isn't Asia/Kolkata, which previously made
  // days_elapsed/days_in_month describe a different month than current_amount
  // actually summed, producing a nonsensical run-rate (e.g. a full month's
  // spend divided by 1 "day elapsed" of the *new* month).
  $today = $db->fetchOne("SELECT DAY(CURDATE()) as d, DAY(LAST_DAY(CURDATE())) as dim");
  $daysInMonth = (int)$today['dim'];
  $daysElapsed = (int)$today['d'];

  $current = (float)$sum['total'];
  $cap = (float)$goal['target_amount'];
  $runRate = $daysElapsed > 0 ? $current / $daysElapsed * $daysInMonth : $current;

  return [
    'progress_percent' => $cap > 0 ? $current / $cap : 0, // intentionally NOT clamped -- >1 means over cap
    'current_amount' => $current,
    'target_amount' => $cap,
    'days_in_month' => $daysInMonth,
    'days_elapsed' => $daysElapsed,
    'days_remaining' => $daysInMonth - $daysElapsed,
    'run_rate_projection' => round($runRate, 2),
    'is_over_cap' => $current > $cap,
    'is_projected_to_exceed' => $runRate > $cap,
    'is_achieved' => false, // ongoing/recurring; never "achieved", just in/out of cap each month
  ];
}

// ============================================
// Route handlers
// ============================================

function getGoals($userId)
{
  try {
    $db = getDB();
    $goals = $db->fetchAll("SELECT * FROM goals WHERE user_id = ? ORDER BY status ASC, created_at DESC", [$userId]);

    foreach ($goals as &$goal) {
      $goal['linked_category_ids'] = $goal['linked_category_ids'] ? json_decode($goal['linked_category_ids'], true) : null;
      $goal['linked_mutual_fund_ids'] = $goal['linked_mutual_fund_ids'] ? json_decode($goal['linked_mutual_fund_ids'], true) : null;
      $goal['linked_long_term_fund_ids'] = $goal['linked_long_term_fund_ids'] ? json_decode($goal['linked_long_term_fund_ids'], true) : null;
      $goal['progress'] = computeGoalProgress($db, $userId, $goal);
      maybeMarkAchieved($db, $goal);
    }

    Response::success($goals, 'Goals retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch goals: ' . $e->getMessage(), 500);
  }
}

function getGoal($userId, $goalId)
{
  try {
    $db = getDB();
    $goal = $db->fetchOne("SELECT * FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);
    if (!$goal) {
      Response::error('Goal not found', 404);
    }

    $goal['linked_category_ids'] = $goal['linked_category_ids'] ? json_decode($goal['linked_category_ids'], true) : null;
    $goal['linked_mutual_fund_ids'] = $goal['linked_mutual_fund_ids'] ? json_decode($goal['linked_mutual_fund_ids'], true) : null;
    $goal['linked_long_term_fund_ids'] = $goal['linked_long_term_fund_ids'] ? json_decode($goal['linked_long_term_fund_ids'], true) : null;
    $goal['progress'] = computeGoalProgress($db, $userId, $goal);
    maybeMarkAchieved($db, $goal);

    Response::success($goal, 'Goal retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch goal: ' . $e->getMessage(), 500);
  }
}

// Cheap convenience: flip status to 'achieved' once a one-shot goal type
// crosses its target, so the list can visually separate done vs active
// without a cron. spend_cap is recurring/ongoing and never auto-achieves.
function maybeMarkAchieved($db, $goal)
{
  if ($goal['status'] !== 'active') return;
  if (empty($goal['progress']['is_achieved'])) return;
  if ($goal['goal_type'] === 'spend_cap') return;
  $db->execute("UPDATE goals SET status = 'achieved' WHERE id = ?", [$goal['id']]);
}

function createGoal($userId)
{
  try {
    $input = getJsonInput();

    $errors = validateRequired($input, ['goal_type', 'name']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $goalType = $input['goal_type'];
    if (!in_array($goalType, ['debt_payoff', 'savings', 'net_worth', 'spend_cap'], true)) {
      Response::error('Invalid goal_type', 422);
    }

    $db = getDB();

    $emiId = null;
    $targetAmount = null;
    $targetDate = null;
    $startAmount = 0;
    $assumedReturn = null;
    $assumedContribution = null;
    $linkedCategoryIds = null;

    if ($goalType === 'debt_payoff') {
      if (empty($input['emi_id'])) {
        Response::error('Validation failed', 422, ['emi_id' => 'emi_id is required for debt_payoff goals']);
      }
      $emi = $db->fetchOne("SELECT id FROM emis WHERE id = ? AND user_id = ?", [$input['emi_id'], $userId]);
      if (!$emi) {
        Response::error('Validation failed', 422, ['emi_id' => 'No such EMI for this user']);
      }
      $emiId = (int)$input['emi_id'];
      $targetDate = $input['target_date'] ?? null;
    } elseif ($goalType === 'savings') {
      if (empty($input['target_amount']) || empty($input['target_date'])) {
        Response::error('Validation failed', 422, ['target_amount' => 'target_amount and target_date are required for savings goals']);
      }
      $targetAmount = (float)$input['target_amount'];
      $targetDate = $input['target_date'];
      $startAmount = isset($input['start_amount']) ? (float)$input['start_amount'] : 0;
    } elseif ($goalType === 'net_worth') {
      if (empty($input['target_amount']) || empty($input['target_date'])) {
        Response::error('Validation failed', 422, ['target_amount' => 'target_amount and target_date are required for net_worth goals']);
      }
      $targetAmount = (float)$input['target_amount'];
      $targetDate = $input['target_date'];
      $assumedReturn = isset($input['assumed_annual_return_percent']) ? (float)$input['assumed_annual_return_percent'] : 10.0;
      $assumedContribution = isset($input['assumed_monthly_contribution']) ? (float)$input['assumed_monthly_contribution'] : 0.0;
    } elseif ($goalType === 'spend_cap') {
      if (empty($input['target_amount'])) {
        Response::error('Validation failed', 422, ['target_amount' => 'target_amount (the monthly cap) is required for spend_cap goals']);
      }
      $targetAmount = (float)$input['target_amount'];
      if (!empty($input['linked_category_ids']) && is_array($input['linked_category_ids'])) {
        $ids = array_map('intval', $input['linked_category_ids']);
        $valid = $db->fetchAll(
          "SELECT id FROM categories WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND (user_id = ? OR user_id IS NULL)",
          array_merge($ids, [$userId])
        );
        if (count($valid) !== count($ids)) {
          Response::error('Validation failed', 422, ['linked_category_ids' => 'One or more category ids are invalid for this user']);
        }
        $linkedCategoryIds = json_encode($ids);
      }
    }

    $id = $db->insert(
      "INSERT INTO goals (user_id, goal_type, name, emi_id, target_amount, start_amount, target_date,
              assumed_annual_return_percent, assumed_monthly_contribution, linked_category_ids, notes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
      [
        $userId,
        $goalType,
        $input['name'],
        $emiId,
        $targetAmount,
        $startAmount,
        $targetDate,
        $assumedReturn,
        $assumedContribution,
        $linkedCategoryIds,
        $input['notes'] ?? null,
      ]
    );

    Response::success(['id' => $id], 'Goal created successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to create goal: ' . $e->getMessage(), 500);
  }
}

function updateGoal($userId, $goalId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    $goal = $db->fetchOne("SELECT * FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);
    if (!$goal) {
      Response::error('Goal not found', 404);
    }

    // goal_type is intentionally not editable -- changing it would invalidate
    // whichever type-specific fields (emi_id vs target_amount vs
    // linked_category_ids) were already set. Create a new goal instead.

    // net_worth only: re-scope which specific mutual_funds/long_term_funds
    // rows this goal tracks. Validated against user_id so a goal can never
    // be pointed at another user's holdings. Sending an empty array clears
    // the scoping back to whole-portfolio tracking.
    $linkedMfIdsJson = null;
    $linkedLtfIdsJson = null;
    if ($goal['goal_type'] === 'net_worth') {
      if (array_key_exists('linked_mutual_fund_ids', $input)) {
        $ids = is_array($input['linked_mutual_fund_ids']) ? array_map('intval', $input['linked_mutual_fund_ids']) : [];
        if (!empty($ids)) {
          $valid = $db->fetchAll(
            "SELECT id FROM mutual_funds WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND user_id = ?",
            array_merge($ids, [$userId])
          );
          if (count($valid) !== count($ids)) {
            Response::error('Validation failed', 422, ['linked_mutual_fund_ids' => 'One or more fund ids are invalid for this user']);
          }
        }
        $linkedMfIdsJson = json_encode($ids);
      } else {
        $linkedMfIdsJson = $goal['linked_mutual_fund_ids'];
      }

      if (array_key_exists('linked_long_term_fund_ids', $input)) {
        $ids = is_array($input['linked_long_term_fund_ids']) ? array_map('intval', $input['linked_long_term_fund_ids']) : [];
        if (!empty($ids)) {
          $valid = $db->fetchAll(
            "SELECT id FROM long_term_funds WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND user_id = ?",
            array_merge($ids, [$userId])
          );
          if (count($valid) !== count($ids)) {
            Response::error('Validation failed', 422, ['linked_long_term_fund_ids' => 'One or more fund ids are invalid for this user']);
          }
        }
        $linkedLtfIdsJson = json_encode($ids);
      } else {
        $linkedLtfIdsJson = $goal['linked_long_term_fund_ids'];
      }
    }

    $db->execute(
      "UPDATE goals SET
        name = ?,
        target_amount = COALESCE(?, target_amount),
        start_amount = COALESCE(?, start_amount),
        target_date = ?,
        assumed_annual_return_percent = COALESCE(?, assumed_annual_return_percent),
        assumed_monthly_contribution = COALESCE(?, assumed_monthly_contribution),
        linked_mutual_fund_ids = ?,
        linked_long_term_fund_ids = ?,
        status = COALESCE(?, status),
        notes = ?
       WHERE id = ? AND user_id = ?",
      [
        $input['name'] ?? $goal['name'],
        $input['target_amount'] ?? null,
        $input['start_amount'] ?? null,
        array_key_exists('target_date', $input) ? $input['target_date'] : $goal['target_date'],
        $input['assumed_annual_return_percent'] ?? null,
        $input['assumed_monthly_contribution'] ?? null,
        $linkedMfIdsJson,
        $linkedLtfIdsJson,
        $input['status'] ?? null,
        array_key_exists('notes', $input) ? $input['notes'] : $goal['notes'],
        $goalId,
        $userId,
      ]
    );

    Response::success(['id' => $goalId], 'Goal updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update goal: ' . $e->getMessage(), 500);
  }
}

function deleteGoal($userId, $goalId)
{
  try {
    $db = getDB();
    $affected = $db->execute("DELETE FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);

    if ($affected > 0) {
      Response::success(null, 'Goal deleted successfully');
    } else {
      Response::error('Goal not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete goal: ' . $e->getMessage(), 500);
  }
}

function getGoalContributions($userId, $goalId)
{
  try {
    $db = getDB();
    $goal = $db->fetchOne("SELECT id FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);
    if (!$goal) {
      Response::error('Goal not found', 404);
    }

    $contributions = $db->fetchAll(
      "SELECT * FROM goal_contributions WHERE goal_id = ? AND user_id = ? ORDER BY contributed_at DESC, id DESC",
      [$goalId, $userId]
    );

    Response::success($contributions, 'Contributions retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch contributions: ' . $e->getMessage(), 500);
  }
}

function addGoalContribution($userId, $goalId)
{
  try {
    $input = getJsonInput();
    $errors = validateRequired($input, ['amount', 'contributed_at']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $db = getDB();
    $goal = $db->fetchOne("SELECT id, goal_type FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);
    if (!$goal) {
      Response::error('Goal not found', 404);
    }
    if ($goal['goal_type'] !== 'savings') {
      Response::error('Contributions can only be logged against savings goals', 422);
    }

    $id = $db->insert(
      "INSERT INTO goal_contributions (goal_id, user_id, amount, contributed_at, note) VALUES (?, ?, ?, ?, ?)",
      [$goalId, $userId, (float)$input['amount'], $input['contributed_at'], $input['note'] ?? null]
    );

    Response::success(['id' => $id], 'Contribution logged successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to log contribution: ' . $e->getMessage(), 500);
  }
}

function deleteGoalContribution($userId, $goalId, $contributionId)
{
  try {
    $db = getDB();
    $affected = $db->execute(
      "DELETE FROM goal_contributions WHERE id = ? AND goal_id = ? AND user_id = ?",
      [$contributionId, $goalId, $userId]
    );

    if ($affected > 0) {
      Response::success(null, 'Contribution deleted successfully');
    } else {
      Response::error('Contribution not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete contribution: ' . $e->getMessage(), 500);
  }
}

// ============================================
// Monthly action plan -- "how much should I save/invest this month"
// ============================================
// Ties income + real EMI/goal data together into a concrete monthly split,
// rather than leaving the user to infer it from separate progress numbers.

function computeMonthlyPlan($db, int $userId): array
{
  $user = $db->fetchOne("SELECT monthly_income, monthly_other_commitments FROM users WHERE id = ?", [$userId]);
  $monthlyIncome = $user['monthly_income'] !== null ? (float)$user['monthly_income'] : null;
  $otherCommitments = (float)$user['monthly_other_commitments'];

  // Active EMI burden is read live from emis, not hardcoded -- it drops on
  // its own as short-term card-EMI conversions finish, without this plan
  // going stale. Split out home/reno loans (permanent) from everything
  // else (short-term card-EMI conversions) so a tight month is explained,
  // not just reported as a flat number.
  $emiRow = $db->fetchOne(
    "SELECT
      COALESCE(SUM(emi_amount), 0) as total,
      COALESCE(SUM(CASE WHEN loan_type = 'home' THEN emi_amount ELSE 0 END), 0) as housing_total
     FROM emis WHERE user_id = ? AND status = 'active'",
    [$userId]
  );
  $activeEmiTotal = (float)$emiRow['total'];
  $housingLoanEmiTotal = (float)$emiRow['housing_total'];
  $shortTermEmiTotal = $activeEmiTotal - $housingLoanEmiTotal;

  $goals = $db->fetchAll(
    "SELECT * FROM goals WHERE user_id = ? AND status = 'active' ORDER BY target_date IS NULL, target_date ASC",
    [$userId]
  );
  foreach ($goals as &$g) {
    $g['linked_category_ids'] = $g['linked_category_ids'] ? json_decode($g['linked_category_ids'], true) : null;
    $g['linked_mutual_fund_ids'] = $g['linked_mutual_fund_ids'] ? json_decode($g['linked_mutual_fund_ids'], true) : null;
    $g['linked_long_term_fund_ids'] = $g['linked_long_term_fund_ids'] ? json_decode($g['linked_long_term_fund_ids'], true) : null;
    $g['progress'] = computeGoalProgress($db, $userId, $g);
  }
  unset($g);

  $spendCapTarget = 0.0;
  foreach ($goals as $g) {
    if ($g['goal_type'] === 'spend_cap') $spendCapTarget += (float)$g['target_amount'];
  }

  $totalCommitted = $activeEmiTotal + $otherCommitments + $spendCapTarget;
  $availableSurplus = $monthlyIncome !== null ? $monthlyIncome - $totalCommitted : null;

  $allocations = [];
  $remaining = $availableSurplus !== null ? max(0, $availableSurplus) : 0.0;

  // Priority 1: savings goals not yet hit, earliest deadline first (matches
  // the sequencing already chosen when the goals were set up).
  foreach ($goals as $g) {
    if ($g['goal_type'] !== 'savings' || !empty($g['progress']['is_achieved'])) continue;
    $need = (float)($g['progress']['required_monthly_contribution'] ?? 0);
    if ($need <= 0) continue;
    $alloc = min($remaining, $need);
    $allocations[] = [
      'goal_id' => (int)$g['id'],
      'name' => $g['name'],
      'goal_type' => 'savings',
      'monthly_need' => round($need, 2),
      'suggested_amount' => round($alloc, 2),
      'note' => 'monthly contribution toward target',
    ];
    $remaining -= $alloc;
  }

  // Priority 2: debt_payoff goals not on track, earliest deadline first --
  // spreads the lumpsum-needed figure evenly across the remaining months as
  // a monthly prepayment-fund target.
  foreach ($goals as $g) {
    if ($g['goal_type'] !== 'debt_payoff') continue;
    $p = $g['progress'];
    $lumpsum = (float)($p['lumpsum_needed'] ?? 0);
    if ($lumpsum <= 0 || !$g['target_date']) continue;
    $monthsLeft = monthsBetweenDates($g['target_date']);
    if ($monthsLeft <= 0) continue;
    $need = $lumpsum / $monthsLeft;
    $alloc = min($remaining, $need);
    $allocations[] = [
      'goal_id' => (int)$g['id'],
      'name' => $g['name'],
      'goal_type' => 'debt_payoff',
      'monthly_need' => round($need, 2),
      'suggested_amount' => round($alloc, 2),
      'lumpsum_target' => round($lumpsum, 2),
      'note' => 'set aside toward a one-time prepayment',
    ];
    $remaining -= $alloc;
  }

  return [
    'is_configured' => $monthlyIncome !== null,
    'monthly_income' => $monthlyIncome,
    'monthly_other_commitments' => $otherCommitments,
    'active_emi_total' => $activeEmiTotal,
    'housing_loan_emi_total' => $housingLoanEmiTotal,
    'short_term_emi_total' => $shortTermEmiTotal,
    'spend_cap_target' => $spendCapTarget,
    'total_committed' => $totalCommitted,
    'available_surplus' => $availableSurplus,
    'allocations' => $allocations,
    'leftover_to_buffer' => round($remaining, 2),
  ];
}

function getMonthlyPlan($userId)
{
  try {
    $db = getDB();
    $plan = computeMonthlyPlan($db, $userId);
    Response::success($plan, 'Monthly plan computed successfully');
  } catch (Exception $e) {
    Response::error('Failed to compute monthly plan: ' . $e->getMessage(), 500);
  }
}

function updateMonthlyPlanSettings($userId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    $db->execute(
      "UPDATE users SET
        monthly_income = COALESCE(?, monthly_income),
        monthly_other_commitments = COALESCE(?, monthly_other_commitments)
       WHERE id = ?",
      [
        $input['monthly_income'] ?? null,
        $input['monthly_other_commitments'] ?? null,
        $userId,
      ]
    );

    $plan = computeMonthlyPlan($db, $userId);
    Response::success($plan, 'Monthly plan settings updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update monthly plan settings: ' . $e->getMessage(), 500);
  }
}
