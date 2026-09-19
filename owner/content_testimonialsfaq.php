<?php
/**
 * owner/content_testimonialsfaq.php — thin wrapper for shared feature module.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('content_testimonialsfaq', [
    'panel' => 'owner',
    'page_title' => 'Parents’ words & FAQs',
]);
