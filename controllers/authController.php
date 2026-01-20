<?php

function handleAuthRoutes($uri, $method)
{
  // Simple auth for single user. For future multi-user support.
  
  if ($uri === '/auth/login' && $method === 'POST') {
    login();
  } elseif ($uri === '/auth/me' && $method === 'GET') {
    getMe();
  } else {
    Response::error('Route not found', 404);
  }
}

function login()
{
  try {
    $input = getJsonInput();
    
    // For now, just create/get user with ID 1
    $db = getDB();
    
    $user = $db->fetchOne("SELECT * FROM users WHERE id = 1");
    
    if (!$user) {
      // Create default user
      $sql = "INSERT INTO users (id, email, name) VALUES (1, 'user@localhost', 'User')";
      $db->execute($sql);
      $user = ['id' => 1, 'email' => 'user@localhost', 'name' => 'User'];
    }

    // Generate JWT
    $token = JWTHandler::generate($user['id'], $user['email'], $user['name']);

    Response::success([
      'token' => $token,
      'user' => $user
    ], 'Login successful');
  } catch (Exception $e) {
    Response::error('Login failed: ' . $e->getMessage(), 500);
  }
}

function getMe()
{
  try {
    $tokenData = JWTHandler::requireAuth();
    
    $db = getDB();
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$tokenData['userId']]);
    
    if (!$user) {
      Response::error('User not found', 404);
    }

    Response::success($user, 'User data retrieved');
  } catch (Exception $e) {
    Response::error('Failed to get user: ' . $e->getMessage(), 500);
  }
}

