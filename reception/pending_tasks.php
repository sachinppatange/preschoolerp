<?php
/**
 * reception/pending_tasks.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('pending_tasks', [
    'panel' => 'reception',
    'task_scope' => 'assigned_to_me',
    'page_title' => 'To-do',
    'show_assign_ui' => false,
    'allow_assign_action' => false,
    'allow_delete_ui' => false,
    'enforce_ownership' => true,
]);
