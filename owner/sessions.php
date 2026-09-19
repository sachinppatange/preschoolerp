<?php
/**
 * Removed: this was login-session dump, not school terms.
 * Academic year is already in the top bar.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$dest = function_exists('site_url') ? site_url('/owner/dashboard.php') : 'dashboard.php';
header('Location: ' . $dest, true, 301);
exit;
