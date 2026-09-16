<?php
/**
 * accounts/pending_fees.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts', ['skip_auth' => true]);
require_accounts_or_reception_auth();
$DEBUG = panel_debug();

require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('pending_fees', [
    'panel' => 'accounts',
    'page_title' => 'Pending Fees',
]);
