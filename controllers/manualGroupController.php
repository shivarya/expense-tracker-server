<?php

function handleManualGroupRoutes($uri, $method)
{
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/manual-groups' && $method === 'GET') {
    getManualTransactionGroups($userId);
  } elseif ($uri === '/manual-groups' && $method === 'POST') {
    createManualTransactionGroup($userId);
  } elseif (preg_match('/^\/manual-groups\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
    updateManualTransactionGroup($userId, (int)$matches[1]);
  } elseif (preg_match('/^\/manual-groups\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteManualTransactionGroup($userId, (int)$matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getManualTransactionGroups($userId)
{
  try {
    $db = getDB();

    if (!manualGroupTableExists($db, 'manual_transaction_groups')) {
      Response::success([], 'Manual groups are not initialized yet');
    }

    $groups = $db->fetchAll(
      "SELECT g.*, (
          SELECT COUNT(*)
          FROM manual_transaction_group_transactions mgt
          JOIN transactions t ON t.id = mgt.transaction_id
          WHERE mgt.manual_group_id = g.id
            AND t.deleted_at IS NULL
        ) AS transaction_count
       FROM manual_transaction_groups g
       WHERE g.user_id = ? AND g.deleted_at IS NULL
       ORDER BY g.name ASC",
      [$userId]
    );

    foreach ($groups as &$group) {
      $group['id'] = (int)$group['id'];
      $group['transaction_count'] = (int)($group['transaction_count'] ?? 0);
    }

    Response::success($groups, 'Manual groups retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch manual groups: ' . $e->getMessage(), 500);
  }
}

function createManualTransactionGroup($userId)
{
  try {
    $input = getJsonInput();
    $name = trim((string)($input['name'] ?? ''));

    if ($name === '') {
      Response::error('name is required', 422);
    }

    $db = getDB();

    if (!manualGroupTableExists($db, 'manual_transaction_groups')) {
      Response::error('Manual groups are not initialized yet. Run latest migration.', 409);
    }

    $id = $db->insert(
      "INSERT INTO manual_transaction_groups (user_id, name, description, icon, color)
       VALUES (?, ?, ?, ?, ?)",
      [
        $userId,
        $name,
        isset($input['description']) ? trim((string)$input['description']) : null,
        trim((string)($input['icon'] ?? 'flag-outline')),
        trim((string)($input['color'] ?? '#FF7043')),
      ]
    );

    Response::success(['id' => (int)$id], 'Manual group created successfully', 201);
  } catch (Exception $e) {
    if (str_contains($e->getMessage(), 'uniq_user_manual_group_name')) {
      Response::error('A manual group with this name already exists', 409);
    }

    Response::error('Failed to create manual group: ' . $e->getMessage(), 500);
  }
}

function updateManualTransactionGroup($userId, $groupId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    $existing = getOwnedManualGroupOrNull($db, (int)$userId, (int)$groupId);
    if (!$existing) {
      Response::error('Manual group not found', 404);
    }

    $name = trim((string)($input['name'] ?? $existing['name']));
    if ($name === '') {
      Response::error('name is required', 422);
    }

    $db->execute(
      "UPDATE manual_transaction_groups
       SET name = ?, description = ?, icon = ?, color = ?
       WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [
        $name,
        array_key_exists('description', $input) ? trim((string)$input['description']) : $existing['description'],
        trim((string)($input['icon'] ?? $existing['icon'] ?? 'flag-outline')),
        trim((string)($input['color'] ?? $existing['color'] ?? '#FF7043')),
        (int)$groupId,
        (int)$userId,
      ]
    );

    Response::success(['id' => (int)$groupId], 'Manual group updated successfully');
  } catch (Exception $e) {
    if (str_contains($e->getMessage(), 'uniq_user_manual_group_name')) {
      Response::error('A manual group with this name already exists', 409);
    }

    Response::error('Failed to update manual group: ' . $e->getMessage(), 500);
  }
}

function deleteManualTransactionGroup($userId, $groupId)
{
  try {
    $db = getDB();

    $existing = getOwnedManualGroupOrNull($db, (int)$userId, (int)$groupId);
    if (!$existing) {
      Response::error('Manual group not found', 404);
    }

    $db->beginTransaction();

    $db->execute(
      "UPDATE manual_transaction_groups
       SET deleted_at = NOW()
       WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
      [(int)$groupId, (int)$userId]
    );

    if (manualGroupTableExists($db, 'manual_transaction_group_transactions')) {
      $db->execute(
        "DELETE FROM manual_transaction_group_transactions
         WHERE user_id = ? AND manual_group_id = ?",
        [(int)$userId, (int)$groupId]
      );
    }

    $db->commit();

    Response::success(null, 'Manual group deleted successfully');
  } catch (Exception $e) {
    if (isset($db)) {
      $db->rollback();
    }

    Response::error('Failed to delete manual group: ' . $e->getMessage(), 500);
  }
}

function getOwnedManualGroupOrNull($db, int $userId, int $groupId): ?array
{
  if (!manualGroupTableExists($db, 'manual_transaction_groups')) {
    return null;
  }

  $group = $db->fetchOne(
    "SELECT * FROM manual_transaction_groups WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
    [$groupId, $userId]
  );

  return $group ?: null;
}

function manualGroupTableExists($db, string $table): bool
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
      $db->fetchOne("SELECT 1 FROM `{$table}` LIMIT 1");
      $cache[$table] = true;
    } catch (Exception $inner) {
      $cache[$table] = false;
    }
  }

  return $cache[$table];
}
