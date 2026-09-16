<?php
/**
 * Debug utility — fees collection error log (development only).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/../includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
}
require_once __DIR__ . '/../includes/auth.php';
require_owner_auth();

if (!defined('APP_DEBUG') || !APP_DEBUG) {
    http_response_code(404);
    exit('Not found');
}

echo '<pre>';
echo 'PHP version: ' . phpversion() . "\n";
echo 'SAPI: ' . php_sapi_name() . "\n";
echo 'sys_get_temp_dir(): ' . sys_get_temp_dir() . "\n\n";

$path = sys_get_temp_dir() . '/fees_collection_error.log';
echo 'fees_collection_error.log path: ' . $path . "\n\n";

if (file_exists($path)) {
    echo "=== fees_collection_error.log contents ===\n";
    echo file_get_contents($path);
} else {
    echo "File not found: $path\n";
}

echo "\n=== XAMPP logs (tail) ===\n";
$paths = [
    '/Applications/XAMPP/xamppfiles/logs/php_error_log',
    '/Applications/XAMPP/xamppfiles/logs/error_log',
    '/Applications/XAMPP/xamppfiles/logs/access_log',
];
foreach ($paths as $p) {
    echo "\n-- $p --\n";
    if (file_exists($p)) {
        $data = @file_get_contents($p);
        echo substr((string) $data, -2000);
    } else {
        echo "Not found\n";
    }
}
echo '</pre>';
