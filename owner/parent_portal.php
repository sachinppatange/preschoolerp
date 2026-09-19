<?php
/**
 * Removed: this was a “preview as parent” copy of the parent app.
 * Owner manages parent logins on Parents.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$dest = function_exists('site_url') ? site_url('/owner/parents.php') : 'parents.php';
header('Location: ' . $dest, true, 301);
exit;
