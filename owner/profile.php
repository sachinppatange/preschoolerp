<?php
/**
 * owner/profile.php — logged-in owner's personal profile.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('profile', [
    'panel' => 'owner',
    'page_title' => 'My Profile',
    'show_teacher_fields' => false,
    'avatar_subdir' => 'owners',
    'default_role_label' => 'owner',
]);
