<?php
/**
 * Removed: parent accounts are created from the admission form (same mobile = siblings).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$dest = function_exists('site_url') ? site_url('/owner/students_list.php') : '../owner/students_list.php';
header('Location: ' . $dest, true, 302);
exit;
