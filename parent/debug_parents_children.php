<?php
/**
 * Debug utility — parent/children mapping (development only, owner access).
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
echo "\n\n=== auth_user_id() ===\n";
var_export(auth_user_id());

if (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
}

$parentId = auth_user_id();
if ($parentId && function_exists('db_get_all')) {
    echo "\n\n=== parents_children for parent $parentId ===\n";
    try {
        var_export(db_get_all(
            'SELECT * FROM parents_children WHERE parent_user_id = :pid',
            [':pid' => $parentId]
        ));
    } catch (Throwable $e) {
        echo 'DB error: ' . $e->getMessage();
    }
}
