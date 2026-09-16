<?php
/**
 * Removed: bulk CSV admission is no longer used.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');

$dest = function_exists('site_url') ? site_url('/reception/admission.php') : 'admission.php';
header('Location: ' . $dest, true, 302);
exit;
