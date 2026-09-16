<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

require_once __DIR__ . '/../includes/features/feature_run.php';
feature_run('year_end_report', ['panel' => 'owner']);
