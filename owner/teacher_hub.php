<?php
/**
 * Owner Teacher Hub was a duplicate of the teacher dashboard.
 * Old bookmarks go to the real teacher dashboard (owner can open it).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$to = function_exists('site_url') ? site_url('/teacher/dashboard.php') : '../teacher/dashboard.php';
header('Location: ' . $to, true, 301);
exit;
