<?php
/**
 * owner/pending_alerts.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('pending_alerts', [
    'panel' => 'owner',
]);
