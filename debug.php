<?php
// Debug file to check what's happening
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "<br>";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "PHP_SELF: " . $_SERVER['PHP_SELF'] . "<br>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "Parsed URI: " . $requestUri . "<br>";

// Check vendor autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "vendor/autoload.php EXISTS<br>";
} else {
    echo "vendor/autoload.php NOT FOUND<br>";
}

// Check config
if (file_exists(__DIR__ . '/config/config.php')) {
    echo "config/config.php EXISTS<br>";
    require_once __DIR__ . '/config/config.php';
    echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . "<br>";
} else {
    echo "config/config.php NOT FOUND<br>";
}

// Check .env
if (file_exists(__DIR__ . '/.env')) {
    echo ".env file EXISTS<br>";
} else {
    echo ".env file NOT FOUND<br>";
}

phpinfo();
