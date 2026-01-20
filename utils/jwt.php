<?php
require_once __DIR__ . '/../vendor/autoload.php'; // For Firebase JWT library

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class JWTHandler
{
  public static function generate($userId, $email, $name = null)
  {
    $issuedAt = time();
    $expirationTime = $issuedAt + JWT_EXPIRES_IN;

    $payload = [
      'userId' => $userId,
      'email' => $email,
      'name' => $name,
      'iat' => $issuedAt,
      'exp' => $expirationTime
    ];

    return JWT::encode($payload, JWT_SECRET, 'HS256');
  }

  public static function verify($token)
  {
    try {
      $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
      return (array) $decoded;
    } catch (Exception $e) {
      return null;
    }
  }

  public static function getUserFromToken()
  {
    $token = getBearerToken();

    if (!$token) {
      return null;
    }

    $decoded = self::verify($token);

    if (!$decoded) {
      return null;
    }

    return $decoded;
  }

  public static function requireAuth()
  {
    $tokenData = self::getUserFromToken();
    if (!$tokenData) {
      Response::error('Unauthorized - Invalid or expired token', 401);
    }
    return $tokenData;
  }
}

