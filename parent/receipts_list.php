<?php
/**
 * Merged into parent/fees.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$dest = function_exists('site_url') ? site_url('/parent/fees.php') : 'fees.php';
header('Location: ' . $dest, true, 301);
exit;
