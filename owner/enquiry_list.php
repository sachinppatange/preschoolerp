<?php
/**
 * owner/enquiry_list.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('enquiry_list', [
    'panel' => 'owner',
    'page_title' => 'Enquiry List',
    'auth_roles' => 'owner',
    'source_default_add' => 'owner_added',
    'source_default_edit' => 'owner_added',
]);
