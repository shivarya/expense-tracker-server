<?php

function handleAuthRoutes($uri, $method)
{
  if ($uri === '/auth/login' && $method === 'POST') {
    login();
  } elseif ($uri === '/auth/google' && $method === 'POST') {
    googleLogin();
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

function googleLogin()
{
  try {
    $input = getJsonInput();
    
    // Accept both idToken and id_token for compatibility
    $idToken = $input['idToken'] ?? $input['id_token'] ?? null;
    
    if (!$idToken) {
      Response::error('ID token is required', 400);
      return;
    }
    
    // Verify Google ID token
    // For now, we'll skip actual verification and just decode the payload
    // In production, verify with Google's API
    
    // Parse JWT payload (simple base64 decode - not secure for production!)
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
      Response::error('Invalid token format', 400);
      return;
    }
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    
    if (!$payload || !isset($payload['email'])) {
      Response::error('Invalid token payload', 400);
      return;
    }

    $email = $payload['email'];
    $name = $payload['name'] ?? $email;
    $googleId = $payload['sub'] ?? null;

    $db = getDB();
    
    // Check if user exists
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    
    if (!$user) {
      // Create new user
      $sql = "INSERT INTO users (email, name, google_id, created_at, updated_at) 
              VALUES (?, ?, ?, NOW(), NOW())";
      $userId = $db->insert($sql, [$email, $name, $googleId]);
      
      $user = [
        'id' => $userId,
        'email' => $email,
        'name' => $name,
        'google_id' => $googleId
      ];
    } else {
      // Update google_id if not set
      if (!$user['google_id'] && $googleId) {
        $db->execute("UPDATE users SET google_id = ?, updated_at = NOW() WHERE id = ?", 
                     [$googleId, $user['id']]);
        $user['google_id'] = $googleId;
      }
    }

    // Generate JWT
    $token = JWTHandler::generate($user['id'], $user['email'], $user['name']);

    Response::success([
      'token' => $token,
      'user' => $user
    ], 'Google login successful');
  } catch (Exception $e) {
    error_log("Google login error: " . $e->getMessage());
    Response::error('Google login failed: ' . $e->getMessage(), 500);
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

