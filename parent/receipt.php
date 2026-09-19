<?php
/**
 * Merged into parent/fees.php (fees + receipts).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$q = [];
$sid = (int) ($_GET['student_id'] ?? 0);
$rid = (int) ($_GET['payment_id'] ?? $_GET['receipt'] ?? $_GET['id'] ?? 0);
if ($sid > 0) {
    $q['student_id'] = $sid;
}
if ($rid > 0) {
    $q['receipt'] = $rid;
}
$path = '/parent/fees.php' . ($q !== [] ? ('?' . http_build_query($q)) : '');
$dest = function_exists('site_url') ? site_url($path) : ('fees.php' . ($q !== [] ? ('?' . http_build_query($q)) : ''));
header('Location: ' . $dest, true, 301);
exit;
