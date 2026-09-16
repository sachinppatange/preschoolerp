<?php
/**
 * teacher/profile.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('profile', [
    'panel' => 'teacher',
    'page_title' => 'My Profile',
    'show_teacher_fields' => true,
    'avatar_subdir' => 'teachers',
    'default_role_label' => 'teacher',
]);
