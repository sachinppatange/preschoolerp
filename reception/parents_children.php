<?php
/**
 * Removed: parent–child links are created automatically on admission.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');

$dest = function_exists('site_url') ? site_url('/reception/students_list.php') : 'students_list.php';
header('Location: ' . $dest, true, 302);
exit;
