<?php
/**
 * Debug utility — children lookup (development only, owner access).
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

header('Content-Type: text/plain; charset=utf-8');

echo "=== auth_user() ===\n";
var_export(auth_user());
echo "\n\nparent id: " . (auth_user_id() ?? 'null') . "\n";
