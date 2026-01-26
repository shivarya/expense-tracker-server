<?php
// Debug file to check what's happening
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "<br>";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "PHP_SELF: " . $_SERVER['PHP_SELF'] . "<br>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "__DIR__: " . __DIR__ . "<br><br>";

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "Parsed URI: " . $requestUri . "<br><br>";

// List all files in current directory
echo "<h3>Files in current directory (__DIR__):</h3>";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        $type = is_dir(__DIR__ . '/' . $file) ? '[DIR]' : '[FILE]';
        echo "$type $file<br>";
    }
}

echo "<br><h3>Checking important files:</h3>";

// Check vendor autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "✓ vendor/autoload.php EXISTS<br>";
} else {
    echo "✗ vendor/autoload.php NOT FOUND<br>";
}

// Check config directory
if (is_dir(__DIR__ . '/config')) {
    echo "✓ config/ directory EXISTS<br>";
    
    // List config files
    $configFiles = scandir(__DIR__ . '/config');
    echo "  Config files:<br>";
    foreach ($configFiles as $file) {
        if ($file !== '.' && $file !== '..') {
            echo "  - $file<br>";
        }
    }
} else {
    echo "✗ config/ directory NOT FOUND<br>";
}

// Check config.php
if (file_exists(__DIR__ . '/config/config.php')) {
    echo "✓ config/config.php EXISTS<br>";
} else {
    echo "✗ config/config.php NOT FOUND<br>";
}

// Check .env
if (file_exists(__DIR__ . '/.env')) {
    echo "✓ .env file EXISTS<br>";
    echo "  .env size: " . filesize(__DIR__ . '/.env') . " bytes<br>";
} else {
    echo "✗ .env file NOT FOUND<br>";
}

// Check index.php
if (file_exists(__DIR__ . '/index.php')) {
    echo "✓ index.php EXISTS<br>";
} else {
    echo "✗ index.php NOT FOUND<br>";
}

echo "<br><h3>PHP Info:</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Extensions: " . implode(', ', get_loaded_extensions()) . "<br>";

