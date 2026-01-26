<?php
// Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
      list($name, $value) = explode('=', $line, 2);
      $_ENV[trim($name)] = trim($value);
      putenv(trim($name) . '=' . trim($value));
    }
  }
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'expense_tracker');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// JWT configuration
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-secret-key-change-in-production');
define('JWT_EXPIRES_IN', 30 * 24 * 60 * 60); // 30 days in seconds

// Google OAuth configuration (for future multi-user support)
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

// OpenAI/Claude API for SMS parsing
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('CLAUDE_API_KEY', getenv('CLAUDE_API_KEY') ?: '');

// Azure OpenAI Configuration
define('AZURE_OPENAI_ENDPOINT', getenv('AZURE_OPENAI_ENDPOINT') ?: '');
define('AZURE_OPENAI_API_KEY', getenv('AZURE_OPENAI_API_KEY') ?: '');
define('AZURE_OPENAI_DEPLOYMENT', getenv('AZURE_OPENAI_DEPLOYMENT') ?: 'gpt-4-turbo');
define('AZURE_OPENAI_API_VERSION', getenv('AZURE_OPENAI_API_VERSION') ?: '2024-08-01-preview');

// Gmail API configuration (for CAMS/KFintech statement download)
define('GMAIL_CLIENT_ID', getenv('GMAIL_CLIENT_ID') ?: '');
define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: '');
define('GMAIL_REDIRECT_URI', getenv('GMAIL_REDIRECT_URI') ?: 'http://localhost:3000/oauth2callback');

// Frontend URL (for future web app)
define('FRONTEND_URL', getenv('FRONTEND_URL') ?: 'http://localhost:19006');

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_errors.log');

// CORS settings
define('ALLOWED_ORIGINS', [
  'http://localhost:19006', // Expo web
  'http://localhost:8081',  // Metro bundler
  'exp://*',                // Expo Go
]);

