<?php

/**
 * What a spend cap was in a given month (migration 033). goals.target_amount and excluded_category_ids are
 * overwritten on edit, so every create/edit also writes this month's row of spend_cap_history; a row's terms apply
 * from its effective_month until a later row. Editing mid-month rewrites that month's row, so a month keeps the cap
 * in force when it closed. Months before a goal's first row use that first row — nothing was recorded before it, and
 * migration 033 seeded it with the terms in force then — and a goal with no rows at all uses its own current terms.
 */

/** The cap page and the widget step through the current month plus the five before it. */
const SPEND_CAP_HISTORY_MONTHS = 6;

/** A category id list as stored (JSON string) or decoded (array); null when there is none. */
function spendCapIdList($value): ?array
{
  if (is_string($value)) {
    $value = json_decode($value, true);
  }
  return is_array($value) ? array_values(array_map('intval', $value)) : null;
}

/**
 * Upserts this month's terms for a spend_cap goal row — MySQL's month, the same "now" computeSpendCapProgress uses.
 * Never throws: a history problem must not fail the goal edit itself.
 */
function recordSpendCapTerms($db, array $goal): void
{
  if (($goal['goal_type'] ?? null) !== 'spend_cap' || $goal['target_amount'] === null) {
    return;
  }
  try {
    $excluded = spendCapIdList($goal['excluded_category_ids'] ?? null);
    $db->execute(
      "INSERT INTO spend_cap_history (goal_id, user_id, effective_month, target_amount, excluded_category_ids)
       VALUES (?, ?, DATE_FORMAT(CURDATE(), '%Y-%m-01'), ?, ?)
       ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount), excluded_category_ids = VALUES(excluded_category_ids)",
      [(int)$goal['id'], (int)$goal['user_id'], (float)$goal['target_amount'], $excluded === null ? null : json_encode($excluded)]
    );
  } catch (Throwable $e) {
    error_log('Spend cap history write failed for goal ' . ($goal['id'] ?? '?') . ': ' . $e->getMessage());
  }
}

/** A goal's recorded terms as [{month: 'YYYY-MM', target_amount, excluded_category_ids}]. Never throws. */
function loadSpendCapHistory($db, int $goalId): array
{
  try {
    return $db->fetchAll(
      "SELECT DATE_FORMAT(effective_month, '%Y-%m') AS month, target_amount, excluded_category_ids
       FROM spend_cap_history WHERE goal_id = ? ORDER BY effective_month",
      [$goalId]
    );
  } catch (Throwable $e) {
    error_log('Spend cap history read failed for goal ' . $goalId . ': ' . $e->getMessage());
    return [];
  }
}

/**
 * The terms for $month ('YYYY-MM') out of $rows (loadSpendCapHistory's shape, any order); $fallback is the goal row.
 *
 * @return array{target_amount: float, excluded_category_ids: ?array}
 */
function resolveSpendCapTerms(array $rows, string $month, array $fallback): array
{
  $atOrBefore = null;
  $first = null;
  foreach ($rows as $row) {
    if ($row['month'] <= $month && ($atOrBefore === null || $row['month'] > $atOrBefore['month'])) {
      $atOrBefore = $row;
    }
    if ($first === null || $row['month'] < $first['month']) {
      $first = $row;
    }
  }
  $terms = $atOrBefore ?? $first ?? $fallback;
  return [
    'target_amount' => (float)$terms['target_amount'],
    'excluded_category_ids' => spendCapIdList($terms['excluded_category_ids'] ?? null),
  ];
}
