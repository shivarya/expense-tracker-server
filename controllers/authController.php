<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Google API client for ID token verification

function handleAuthRoutes($uri, $method)
{
  if ($uri === '/auth/login' && $method === 'POST') {
    login();
  } elseif ($uri === '/auth/google' && $method === 'POST') {
    googleLogin();
  } elseif ($uri === '/auth/me' && $method === 'GET') {
    getMe();
  } elseif ($uri === '/auth/account' && $method === 'DELETE') {
    deleteAccount();
  } else {
    Response::error('Route not found', 404);
  }
}

/**
 * Legacy dev-only login. Issues a session for a hardcoded user (id=1) with NO
 * authentication, so it must NEVER be reachable in production. Disabled by
 * default; enable locally with ALLOW_DEV_LOGIN=true in server/.env.
 */
function login()
{
  $allowDevLogin = filter_var(
    getenv('ALLOW_DEV_LOGIN') ?: ($_ENV['ALLOW_DEV_LOGIN'] ?? 'false'),
    FILTER_VALIDATE_BOOLEAN
  );

  if (!$allowDevLogin) {
    Response::error('This endpoint is disabled. Use POST /auth/google.', 410);
    return;
  }

  try {
    $db = getDB();
    $user = $db->fetchOne("SELECT * FROM users WHERE id = 1");

    if (!$user) {
      $db->execute("INSERT INTO users (id, email, name) VALUES (1, 'user@localhost', 'User')");
      $user = ['id' => 1, 'email' => 'user@localhost', 'name' => 'User'];
    }

    $token = JWTHandler::generate($user['id'], $user['email'], $user['name']);
    Response::success(['token' => $token, 'user' => $user], 'Login successful (dev)');
  } catch (Exception $e) {
    error_log('Dev login failed: ' . $e->getMessage());
    Response::error('Login failed', 500);
  }
}

/**
 * Audiences (OAuth client IDs) accepted on Google ID tokens. The React Native
 * Google Sign-In client mints tokens with aud = webClientId (GOOGLE_CLIENT_ID).
 * Additional native (Android/iOS) client IDs can be allowed via the
 * comma-separated GOOGLE_ALLOWED_AUDIENCES env var.
 */
function getAllowedGoogleAudiences(): array
{
  $ids = [];

  if (defined('GOOGLE_CLIENT_ID') && trim((string)GOOGLE_CLIENT_ID) !== '') {
    $ids[] = trim((string)GOOGLE_CLIENT_ID);
  }

  $extra = getenv('GOOGLE_ALLOWED_AUDIENCES') ?: ($_ENV['GOOGLE_ALLOWED_AUDIENCES'] ?? '');
  foreach (explode(',', (string)$extra) as $aud) {
    $aud = trim($aud);
    if ($aud !== '') {
      $ids[] = $aud;
    }
  }

  return array_values(array_unique($ids));
}

/**
 * Verify a Google ID token: checks signature against Google's public certs,
 * expiry, issuer, audience (against our allowed client IDs), and that the email
 * is verified. Returns the decoded payload on success, or null on any failure.
 */
function verifyGoogleIdToken(string $idToken): ?array
{
  $allowed = getAllowedGoogleAudiences();
  if (empty($allowed)) {
    error_log('Google auth not configured: GOOGLE_CLIENT_ID is empty');
    return null;
  }

  try {
    // No client_id set on the client so the library skips its single-audience
    // check; we validate aud ourselves against the allowed list below.
    $client = new Google\Client();
    $payload = $client->verifyIdToken($idToken); // validates signature, exp, iss

    if (!is_array($payload) || empty($payload['aud'])) {
      return null;
    }

    if (!in_array($payload['aud'], $allowed, true)) {
      error_log('Google ID token audience mismatch: ' . $payload['aud']);
      return null;
    }

    $emailVerified = $payload['email_verified'] ?? false;
    if ($emailVerified === false || $emailVerified === 'false' || $emailVerified === 0) {
      error_log('Google ID token email not verified');
      return null;
    }

    return $payload;
  } catch (Throwable $e) {
    error_log('Google ID token verification failed: ' . $e->getMessage());
    return null;
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

    $payload = verifyGoogleIdToken($idToken);
    if (!$payload) {
      Response::error('Invalid or unverified Google token', 401);
      return;
    }

    $email = $payload['email'] ?? null;
    if (!$email) {
      Response::error('Google token missing email', 400);
      return;
    }

    $name = $payload['name'] ?? $email;
    $googleId = $payload['sub'] ?? null;
    $picture = $payload['picture'] ?? null;

    $db = getDB();

    // Check if user exists
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

    if (!$user) {
      // Create new user
      $sql = "INSERT INTO users (email, name, google_id, profile_picture, created_at, updated_at)
              VALUES (?, ?, ?, ?, NOW(), NOW())";
      $userId = $db->insert($sql, [$email, $name, $googleId, $picture]);

      $user = [
        'id' => $userId,
        'email' => $email,
        'name' => $name,
        'google_id' => $googleId,
        'profile_picture' => $picture,
      ];
    } else {
      // Backfill google_id if it was missing (e.g. user created via legacy path)
      if (empty($user['google_id']) && $googleId) {
        $db->execute(
          "UPDATE users SET google_id = ?, updated_at = NOW() WHERE id = ?",
          [$googleId, $user['id']]
        );
        $user['google_id'] = $googleId;
      }
    }

    // Generate our own JWT (not Google's token)
    $token = JWTHandler::generate($user['id'], $user['email'], $user['name']);

    Response::success([
      'token' => $token,
      'user' => $user
    ], 'Google login successful');
  } catch (Exception $e) {
    error_log("Google login error: " . $e->getMessage());
    Response::error('Google login failed', 500);
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

function deleteAccount()
{
  try {
    $tokenData = JWTHandler::requireAuth();
    $userId = $tokenData['userId'];

    $db = getDB();

    // Verify user exists
    $user = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId]);
    if (!$user) {
      Response::error('User not found', 404);
      return;
    }

    // Delete in dependency-safe order, then let ON DELETE CASCADE handle the rest.
    // - transactions must go before categories  (categories.id <- transactions.category_id RESTRICT)
    // - emis must go before bank_accounts        (bank_accounts.id <- emis.account_id RESTRICT)
    // Deleting the user then cascades everything else: bank_accounts, categories,
    // stocks, mutual_funds, fixed_deposits, long_term_funds, statement_passwords,
    // statement_uploads, transaction_groups, manual_transaction_groups,
    // category_learning_rules, scrape_logs, scraper_sync_log, sync_jobs.
    $db->execute("DELETE FROM transactions WHERE user_id = ?", [$userId]);
    $db->execute("DELETE FROM emis WHERE user_id = ?", [$userId]);
    $db->execute("DELETE FROM users WHERE id = ?", [$userId]);

    Response::success(null, 'Account and all associated data deleted successfully');
  } catch (Exception $e) {
    error_log("Delete account error: " . $e->getMessage());
    Response::error('Failed to delete account', 500);
  }
}
