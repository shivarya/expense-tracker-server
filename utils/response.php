<?php
class Response
{
  public static function success($data = null, $message = null, $statusCode = 200)
  {
    http_response_code($statusCode);
    echo json_encode([
      'success' => true,
      'data' => $data,
      'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  public static function error($message, $statusCode = 400, $errors = null)
  {
    http_response_code($statusCode);
    $response = [
      'success' => false,
      'error' => $message
    ];
    if ($errors !== null) {
      $response['errors'] = $errors;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  public static function json($data, $statusCode = 200)
  {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

// Helper function to get JSON input
function getJsonInput()
{
  $input = file_get_contents('php://input');
  return json_decode($input, true) ?? [];
}

// Helper function to get authorization header
function getBearerToken()
{
  // Try getallheaders first
  $headers = getallheaders();
  
  if (isset($headers['Authorization'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
      return $matches[1];
    }
  }
  
  // Fallback: try $_SERVER variables
  if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
      return $matches[1];
    }
  }
  
  // Apache specific
  if (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
      if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
        return $matches[1];
      }
    }
  }
  
  return null;
}

// Helper function to validate required fields
function validateRequired($data, $fields)
{
  $errors = [];
  foreach ($fields as $field) {
    if (!isset($data[$field]) || empty($data[$field])) {
      $errors[$field] = ucfirst($field) . ' is required';
    }
  }
  return $errors;
}

// Helper function to sanitize input
function sanitizeInput($data)
{
  if (is_array($data)) {
    return array_map('sanitizeInput', $data);
  }
  return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

