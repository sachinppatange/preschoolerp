<?php
/**
 * reception/enquiry_list.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('enquiry_list', [
    'panel' => 'reception',
    'page_title' => 'Enquiries',
    'auth_roles' => ['reception', 'staff'],
    'source_default_add' => 'reception_added',
    'source_default_edit' => 'reception_updated',
]);
