<?php

function handleCategoryRoutes($uri, $method)
{
  // Require authentication
  $tokenData = JWTHandler::requireAuth();
  $userId = $tokenData['userId'];

  if ($uri === '/categories' && $method === 'GET') {
    getCategories($userId);
  } elseif ($uri === '/categories' && $method === 'POST') {
    createCategory($userId);
  } elseif (preg_match('/^\/categories\/(\d+)$/', $uri, $matches) && $method === 'PUT') {
    updateCategory($userId, $matches[1]);
  } elseif (preg_match('/^\/categories\/(\d+)$/', $uri, $matches) && $method === 'DELETE') {
    deleteCategory($userId, $matches[1]);
  } else {
    Response::error('Route not found', 404);
  }
}

function getCategories($userId)
{
  try {
    $db = getDB();
    
    // Get all categories (system + user-specific)
    $categories = $db->fetchAll(
      "SELECT * FROM categories 
       WHERE user_id IS NULL OR user_id = ?
       ORDER BY display_order ASC, name ASC",
      [$userId]
    );

    Response::success($categories, 'Categories retrieved successfully');
  } catch (Exception $e) {
    Response::error('Failed to fetch categories: ' . $e->getMessage(), 500);
  }
}

function createCategory($userId)
{
  try {
    $input = getJsonInput();
    
    $errors = validateRequired($input, ['name', 'type']);
    if (!empty($errors)) {
      Response::error('Validation failed', 422, $errors);
    }

    $db = getDB();

    $sql = "INSERT INTO categories (user_id, name, icon, color, type, monthly_budget, parent_category_id, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $id = $db->insert($sql, [
      $userId,
      $input['name'],
      $input['icon'] ?? 'help-circle-outline',
      $input['color'] ?? '#2196F3',
      $input['type'],
      $input['monthly_budget'] ?? 0,
      $input['parent_category_id'] ?? null,
      $input['display_order'] ?? 999
    ]);

    Response::success(['id' => $id], 'Category created successfully', 201);
  } catch (Exception $e) {
    Response::error('Failed to create category: ' . $e->getMessage(), 500);
  }
}

function updateCategory($userId, $categoryId)
{
  try {
    $input = getJsonInput();
    $db = getDB();

    // Check if it's a system category
    $category = $db->fetchOne("SELECT is_system FROM categories WHERE id = ?", [$categoryId]);
    if ($category && $category['is_system']) {
      // Only allow updating budget for system categories
      $sql = "UPDATE categories SET monthly_budget = ? WHERE id = ?";
      $db->execute($sql, [$input['monthly_budget'] ?? 0, $categoryId]);
    } else {
      // Full update for user categories
      $sql = "UPDATE categories SET 
                name = ?, icon = ?, color = ?, monthly_budget = ?, display_order = ?
              WHERE id = ? AND user_id = ?";
      $db->execute($sql, [
        $input['name'],
        $input['icon'] ?? 'help-circle-outline',
        $input['color'] ?? '#2196F3',
        $input['monthly_budget'] ?? 0,
        $input['display_order'] ?? 999,
        $categoryId,
        $userId
      ]);
    }

    Response::success(['id' => $categoryId], 'Category updated successfully');
  } catch (Exception $e) {
    Response::error('Failed to update category: ' . $e->getMessage(), 500);
  }
}

function deleteCategory($userId, $categoryId)
{
  try {
    $db = getDB();
    
    // Check if it's a system category
    $category = $db->fetchOne("SELECT is_system FROM categories WHERE id = ?", [$categoryId]);
    if ($category && $category['is_system']) {
      Response::error('Cannot delete system category', 400);
    }

    // Check if category has transactions
    $count = $db->fetchOne(
      "SELECT COUNT(*) as count FROM transactions WHERE category_id = ?",
      [$categoryId]
    );

    if ($count['count'] > 0) {
      Response::error('Cannot delete category with existing transactions', 400);
    }

    $affected = $db->execute("DELETE FROM categories WHERE id = ? AND user_id = ?", [$categoryId, $userId]);

    if ($affected > 0) {
      Response::success(null, 'Category deleted successfully');
    } else {
      Response::error('Category not found', 404);
    }
  } catch (Exception $e) {
    Response::error('Failed to delete category: ' . $e->getMessage(), 500);
  }
}

