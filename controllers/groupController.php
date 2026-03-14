<?php

function handleGroupRoutes($uri, $method)
{
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/groups' && $method === 'GET') {
    getTransactionGroups($userId);
  } elseif ($uri === '/groups' && $method === 'POST') {
    createTransactionGroup($userId);
  } elseif ($uri === '/groups/presets' && $method === 'POST') {
    createTransactionGroupPresets($userId);
  } elseif (preg_match('/^\/groups\/(\d+)\/preview$/', $uri, $matches) && $method === 'GET') {
    previewTransactionGroup($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/groups\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
    updateTransactionGroup($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/groups\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteTransactionGroup($userId, (int)$matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getAllowedGroupRuleTypes()
{
  return [
    'category_id',
    'account_id',
    'account_type',
    'payment_method_keyword',
    'merchant_keyword',
    'transaction_type',
  ];
}

function getDefaultPresetGroupDefinitions()
{
  return [
    [
      'name' => 'Credit Cards',
      'description' => 'All transactions that look like credit card spends or credits.',
      'icon' => 'card-outline',
      'color' => '#EF5350',
      'rules' => [
        ['rule_type' => 'account_type', 'rule_value' => 'credit_card'],
        ['rule_type' => 'payment_method_keyword', 'rule_value' => 'card'],
        ['rule_type' => 'payment_method_keyword', 'rule_value' => 'credit'],
      ],
    ],
    [
      'name' => 'Home',
      'description' => 'Rent, utilities and home-related spends.',
      'icon' => 'home-outline',
      'color' => '#42A5F5',
      'rules' => [
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'rent'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'electricity'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'maintenance'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'gas'],
      ],
    ],
    [
      'name' => 'Travel',
      'description' => 'Flights, trains, cabs and hotels.',
      'icon' => 'airplane-outline',
      'color' => '#26A69A',
      'rules' => [
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'uber'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'ola'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'irctc'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'hotel'],
        ['rule_type' => 'merchant_keyword', 'rule_value' => 'flight'],
      ],
    ],
  ];
}

function seedDefaultTransactionGroups($db, $userId)
{
  $existingCount = $db->fetchOne(
    "SELECT COUNT(*) as total FROM transaction_groups WHERE user_id = ?",
    [$userId]
  );

  if ((int)($existingCount['total'] ?? 0) > 0) {
    return;
  }

  foreach (getDefaultPresetGroupDefinitions() as $preset) {
    $groupId = $db->insert(
      "INSERT INTO transaction_groups (user_id, name, description, icon, color, is_preset)
       VALUES (?, ?, ?, ?, ?, 1)",
      [$userId, $preset['name'], $preset['description'], $preset['icon'], $preset['color']]
    );

    foreach (normalizeGroupRules($preset['rules']) as $rule) {
      $db->insert(
        "INSERT INTO transaction_group_rules (group_id, rule_type, rule_value) VALUES (?, ?, ?)",
        [$groupId, $rule['rule_type'], $rule['rule_value']]
      );
    }
  }
}

function normalizeGroupRules(array $rules)
{
  $normalized = [];
  $seen = [];

  foreach ($rules as $rule) {
    if (!is_array($rule)) {
      continue;
    }

    $type = trim((string)($rule['rule_type'] ?? ''));
    $valueRaw = trim((string)($rule['rule_value'] ?? ''));

    if ($type === '' || $valueRaw === '' || !in_array($type, getAllowedGroupRuleTypes(), true)) {
      continue;
    }

    $value = $valueRaw;

    if (($type === 'category_id' || $type === 'account_id') && !ctype_digit($valueRaw)) {
      continue;
    }

    if ($type === 'category_id' || $type === 'account_id') {
      $value = (string)((int)$valueRaw);
      if ((int)$value <= 0) {
        continue;
      }
    }

    if ($type === 'account_type' && !in_array($valueRaw, ['savings', 'current', 'credit_card'], true)) {
      continue;
    }

    if ($type === 'transaction_type' && !in_array($valueRaw, ['debit', 'credit', 'transfer'], true)) {
      continue;
    }

    if ($type === 'merchant_keyword' || $type === 'payment_method_keyword') {
      $value = mb_strtolower($valueRaw);
    }

    $key = $type . '|' . $value;
    if (isset($seen[$key])) {
      continue;
    }

    $seen[$key] = true;
    $normalized[] = [
      'rule_type' => $type,
      'rule_value' => $value,
    ];
  }

  return $normalized;
}

function getOwnedGroupOr404($db, $userId, $groupId)
{
  $group = $db->fetchOne(
    "SELECT * FROM transaction_groups WHERE id = ? AND user_id = ?",
    [$groupId, $userId]
  );

  if (!$group) {
    Response::error('Group not found', 404);
  }

  return $group;
}

function buildGroupFilterSql($groupId, &$params, $transactionAlias = 't')
{
  if (!$groupId) {
    return '';
  }

  $params[] = (int)$groupId;

  return "
    AND EXISTS (
      SELECT 1
      FROM transaction_group_rules r
      WHERE r.group_id = ?
        AND (
          (r.rule_type = 'category_id' AND {$transactionAlias}.category_id = CAST(r.rule_value AS UNSIGNED))
          OR (r.rule_type = 'account_id' AND {$transactionAlias}.account_id = CAST(r.rule_value AS UNSIGNED))
          OR (
            r.rule_type = 'account_type'
            AND EXISTS (
              SELECT 1 FROM bank_accounts b2
              WHERE b2.id = {$transactionAlias}.account_id
                AND b2.account_type = r.rule_value
            )
          )
          OR (
            r.rule_type = 'payment_method_keyword'
            AND COALESCE({$transactionAlias}.payment_method, '') LIKE CONCAT('%', r.rule_value, '%')
          )
          OR (
            r.rule_type = 'merchant_keyword'
            AND (
              COALESCE({$transactionAlias}.merchant, '') LIKE CONCAT('%', r.rule_value, '%')
              OR COALESCE({$transactionAlias}.description, '') LIKE CONCAT('%', r.rule_value, '%')
            )
          )
          OR (r.rule_type = 'transaction_type' AND {$transactionAlias}.transaction_type = r.rule_value)
        )
    )
  ";
}

function getTransactionGroups($userId)
{
  try {
    $db = getDB();

    // First-time experience: seed editable presets when user has zero groups.
    seedDefaultTransactionGroups($db, $userId);

    $groups = $db->fetchAll(
      "SELECT g.*, (
          SELECT COUNT(*) FROM transaction_group_rules r WHERE r.group_id = g.id
        ) as rule_count
       FROM transaction_groups g
       WHERE g.user_id = ?
       ORDER BY g.is_preset DESC, g.name ASC",
      [$userId]
    );

    foreach ($groups as &$group) {
      $group['id'] = (int)$group['id'];
      $group['is_preset'] = (bool)$group['is_preset'];
      $group['rules'] = $db->fetchAll(
        "SELECT id, rule_type, rule_value FROM transaction_group_rules WHERE group_id = ? ORDER BY id ASC",
        [(int)$group['id']]
      );
    }

    Response::success($groups, 'Groups retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch groups: ' . $e->getMessage(), 500);
  }
}

function createTransactionGroup($userId)
{
  try {
    $input = getJsonInput();
    $name = trim((string)($input['name'] ?? ''));

    if ($name === '') {
      Response::error('name is required', 422);
    }

    $rulesInput = is_array($input['rules'] ?? null) ? $input['rules'] : [];
    $rules = normalizeGroupRules($rulesInput);

    if (count($rules) === 0) {
      Response::error('At least one valid rule is required', 422);
    }

    $db = getDB();
    $db->beginTransaction();

    $groupId = $db->insert(
      "INSERT INTO transaction_groups (user_id, name, description, icon, color, is_preset)
       VALUES (?, ?, ?, ?, ?, ?)",
      [
        $userId,
        $name,
        isset($input['description']) ? trim((string)$input['description']) : null,
        trim((string)($input['icon'] ?? 'layers-outline')),
        trim((string)($input['color'] ?? '#5B5FEF')),
        !empty($input['is_preset']) ? 1 : 0,
      ]
    );

    foreach ($rules as $rule) {
      $db->insert(
        "INSERT INTO transaction_group_rules (group_id, rule_type, rule_value) VALUES (?, ?, ?)",
        [$groupId, $rule['rule_type'], $rule['rule_value']]
      );
    }

    $db->commit();

    Response::success(['id' => (int)$groupId], 'Group created successfully', 201);
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }

    if (str_contains($e->getMessage(), 'uniq_user_group_name')) {
      Response::error('A group with this name already exists', 409);
    }

    Response::error('Failed to create group: ' . $e->getMessage(), 500);
  }
}

function updateTransactionGroup($userId, $groupId)
{
  try {
    $input = getJsonInput();
    $db = getDB();
    $existing = getOwnedGroupOr404($db, $userId, $groupId);

    $name = trim((string)($input['name'] ?? $existing['name']));
    if ($name === '') {
      Response::error('name is required', 422);
    }

    $description = array_key_exists('description', $input)
      ? trim((string)$input['description'])
      : $existing['description'];

    $icon = trim((string)($input['icon'] ?? $existing['icon'] ?? 'layers-outline'));
    $color = trim((string)($input['color'] ?? $existing['color'] ?? '#5B5FEF'));

    $db->beginTransaction();

    $db->execute(
      "UPDATE transaction_groups
       SET name = ?, description = ?, icon = ?, color = ?
       WHERE id = ? AND user_id = ?",
      [$name, $description ?: null, $icon, $color, $groupId, $userId]
    );

    if (array_key_exists('rules', $input)) {
      $rulesInput = is_array($input['rules']) ? $input['rules'] : [];
      $rules = normalizeGroupRules($rulesInput);

      if (count($rules) === 0) {
        Response::error('At least one valid rule is required', 422);
      }

      $db->execute("DELETE FROM transaction_group_rules WHERE group_id = ?", [$groupId]);

      foreach ($rules as $rule) {
        $db->insert(
          "INSERT INTO transaction_group_rules (group_id, rule_type, rule_value) VALUES (?, ?, ?)",
          [$groupId, $rule['rule_type'], $rule['rule_value']]
        );
      }
    }

    $db->commit();

    Response::success(['id' => (int)$groupId], 'Group updated successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }

    if (str_contains($e->getMessage(), 'uniq_user_group_name')) {
      Response::error('A group with this name already exists', 409);
    }

    Response::error('Failed to update group: ' . $e->getMessage(), 500);
  }
}

function deleteTransactionGroup($userId, $groupId)
{
  try {
    $db = getDB();
    getOwnedGroupOr404($db, $userId, $groupId);

    $db->execute("DELETE FROM transaction_groups WHERE id = ? AND user_id = ?", [$groupId, $userId]);

    Response::success(null, 'Group deleted successfully');
  } catch (Exception $e) {
    Response::error('Failed to delete group: ' . $e->getMessage(), 500);
  }
}

function createTransactionGroupPresets($userId)
{
  try {
    $db = getDB();

    $created = 0;
    $skipped = 0;

    $db->beginTransaction();

    foreach (getDefaultPresetGroupDefinitions() as $preset) {
      $exists = $db->fetchOne(
        "SELECT id FROM transaction_groups WHERE user_id = ? AND name = ?",
        [$userId, $preset['name']]
      );

      if ($exists) {
        $skipped++;
        continue;
      }

      $groupId = $db->insert(
        "INSERT INTO transaction_groups (user_id, name, description, icon, color, is_preset)
         VALUES (?, ?, ?, ?, ?, 1)",
        [$userId, $preset['name'], $preset['description'], $preset['icon'], $preset['color']]
      );

      foreach (normalizeGroupRules($preset['rules']) as $rule) {
        $db->insert(
          "INSERT INTO transaction_group_rules (group_id, rule_type, rule_value) VALUES (?, ?, ?)",
          [$groupId, $rule['rule_type'], $rule['rule_value']]
        );
      }

      $created++;
    }

    $db->commit();

    Response::success([
      'created' => $created,
      'skipped' => $skipped,
    ], 'Preset groups processed successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }
    Response::error('Failed to create presets: ' . $e->getMessage(), 500);
  }
}

function previewTransactionGroup($userId, $groupId)
{
  try {
    $db = getDB();
    getOwnedGroupOr404($db, $userId, $groupId);

    $params = [$userId];
    $groupClause = buildGroupFilterSql($groupId, $params, 't');

    $count = $db->fetchOne(
      "SELECT COUNT(*) as total
       FROM transactions t
       WHERE t.user_id = ? AND t.deleted_at IS NULL
       {$groupClause}",
      $params
    );

    $sampleParams = [$userId];
    $sampleGroupClause = buildGroupFilterSql($groupId, $sampleParams, 't');

    $sample = $db->fetchAll(
      "SELECT t.id, t.amount, t.transaction_type, t.transaction_date, t.merchant, t.payment_method,
              c.name as category_name, ba.account_name
       FROM transactions t
       JOIN categories c ON t.category_id = c.id
       JOIN bank_accounts ba ON t.account_id = ba.id
       WHERE t.user_id = ? AND t.deleted_at IS NULL
       {$sampleGroupClause}
       ORDER BY t.transaction_date DESC
       LIMIT 10",
      $sampleParams
    );

    Response::success([
      'total' => (int)($count['total'] ?? 0),
      'sample' => $sample,
    ], 'Group preview generated successfully');
  } catch (Exception $e) {
    Response::error('Failed to preview group: ' . $e->getMessage(), 500);
  }
}
