<?php
/**
 * accounts/monthly_summary.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('monthly_summary', [
    'panel' => 'accounts',
    'page_title' => 'Monthly Summary',
]);
