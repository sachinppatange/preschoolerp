<?php
/**
 * Global search JSON API (staff panels).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/panel/bootstrap.php';
panel_bootstrap(null, ['skip_auth' => true]);

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('auth_is_logged_in') || !auth_is_logged_in(['owner', 'teacher', 'reception', 'accounts', 'staff'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$limit = min(12, max(3, (int) ($_GET['limit'] ?? 8)));

if (strlen($q) < 2) {
    echo json_encode(['ok' => true, 'q' => $q, 'results' => []]);
    exit;
}

try {
    $results = panel_global_search($q, $limit);
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'count' => count($results),
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => panel_debug() ? $e->getMessage() : 'Search error',
    ], JSON_UNESCAPED_UNICODE);
}
