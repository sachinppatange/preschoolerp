<?php
/**
 * Folded into To-do — one list for owner and staff work.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$dest = function_exists('site_url') ? site_url('/owner/pending_tasks.php') : 'pending_tasks.php';
header('Location: ' . $dest, true, 301);
exit;
