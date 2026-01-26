<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Load configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/jwt.php';

// Handle CORS
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
// In development, allow all origins; in production, check ALLOWED_ORIGINS
$isDev = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1');
if ($isDev) {
  header("Access-Control-Allow-Origin: *");
} elseif (in_array($origin, ALLOWED_ORIGINS) || strpos($origin, 'exp://') === 0) {
  header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

// Get request URI and method
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove base path if API is in a subdirectory
$basePath = '/api';
if (strpos($requestUri, $basePath) === 0) {
  $requestUri = substr($requestUri, strlen($basePath));
}

// Remove trailing slash
$requestUri = rtrim($requestUri, '/');

// Error handler
set_exception_handler(function ($e) {
  error_log("Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
  Response::error('Internal server error: ' . $e->getMessage(), 500);
});

// Routing
try {
  // Health check
  if ($requestUri === '/health' || $requestUri === '') {
    Response::success([
      'status' => 'healthy',
      'timestamp' => date('Y-m-d H:i:s'),
      'version' => '1.0.0'
    ], 'Expense Tracker API is running');
  }

  // Dashboard
  if (strpos($requestUri, '/dashboard') === 0) {
    require_once __DIR__ . '/controllers/dashboardController.php';
    handleDashboardRoutes($requestUri, $requestMethod);
    exit;
  }

  // Investments (stocks, mutual funds, FDs, long-term)
  if (strpos($requestUri, '/investments') === 0) {
    require_once __DIR__ . '/controllers/investmentController.php';
    handleInvestmentRoutes($requestUri, $requestMethod);
    exit;
  }

  // Expense analytics endpoints (for mobile app charts) - BEFORE /transactions route
  if ($requestUri === '/expenses/summary' && $requestMethod === 'GET') {
    require_once __DIR__ . '/controllers/expenseAnalyticsController.php';
    $controller = new ExpenseAnalyticsController();
    $controller->getSummary();
    exit;
  }

  // Transactions (expenses/income)
  if (strpos($requestUri, '/transactions') === 0) {
    require_once __DIR__ . '/controllers/transactionController.php';
    handleTransactionRoutes($requestUri, $requestMethod);
    exit;
  }

  // Bank accounts
  if (strpos($requestUri, '/accounts') === 0) {
    require_once __DIR__ . '/controllers/accountController.php';
    handleAccountRoutes($requestUri, $requestMethod);
    exit;
  }

  // EMIs
  if (strpos($requestUri, '/emis') === 0) {
    require_once __DIR__ . '/controllers/emiController.php';
    handleEmiRoutes($requestUri, $requestMethod);
    exit;
  }

  // Categories
  if (strpos($requestUri, '/categories') === 0) {
    require_once __DIR__ . '/controllers/categoryController.php';
    handleCategoryRoutes($requestUri, $requestMethod);
    exit;
  }

  // Sync endpoints (for scraper to push data)
  if (strpos($requestUri, '/sync') === 0) {
    require_once __DIR__ . '/controllers/syncController.php';
    handleSyncRoutes($requestUri, $requestMethod);
    exit;
  }

  // Summary endpoints (for MCP server)
  if (strpos($requestUri, '/summary') === 0) {
    require_once __DIR__ . '/controllers/summaryController.php';
    handleSummaryRoutes($requestUri, $requestMethod);
    exit;
  }

  // Auth endpoints (for future multi-user support)
  if (strpos($requestUri, '/auth') === 0) {
    require_once __DIR__ . '/controllers/authController.php';
    handleAuthRoutes($requestUri, $requestMethod);
    exit;
  }

  // SMS parsing endpoints
  if (strpos($requestUri, '/parse/sms') === 0) {
    require_once __DIR__ . '/controllers/smsParserController.php';
    $controller = new SMSParserController();
    
    if ($requestUri === '/parse/sms' && $requestMethod === 'POST') {
      $controller->parseSMS();
    } elseif ($requestUri === '/parse/sms/webhook' && $requestMethod === 'POST') {
      $controller->smsWebhook();
    } else {
      Response::error('Invalid SMS parser route', 404);
    }
    exit;
  }

  // Email parsing endpoints
  if (strpos($requestUri, '/parse/email') === 0) {
    require_once __DIR__ . '/controllers/emailParserController.php';
    $controller = new EmailParserController();
    
    if ($requestUri === '/parse/email/setup' && $requestMethod === 'POST') {
      $controller->setupGmail();
    } elseif ($requestUri === '/parse/email/callback' && $requestMethod === 'POST') {
      $controller->gmailCallback();
    } elseif ($requestUri === '/parse/email/fetch' && $requestMethod === 'POST') {
      $controller->fetchEmails();
    } elseif ($requestUri === '/parse/email/webhook' && $requestMethod === 'POST') {
      $controller->gmailWebhook();
    } else {
      Response::error('Invalid email parser route', 404);
    }
    exit;
  }

  // If no route matched
  Response::error('Route not found: ' . $requestUri, 404);
} catch (Exception $e) {
  error_log("Error: " . $e->getMessage());
  Response::error('Internal server error', 500);
}

