<?php
// Simple health check without dependencies
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'success' => true,
    'message' => 'Server is working!',
    'data' => [
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => phpversion(),
        'script_path' => __FILE__
    ]
]);
