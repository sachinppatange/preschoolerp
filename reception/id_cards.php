<?php
/**
 * reception/id_cards.php — CR80 student ID cards.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
require_once __DIR__ . '/../includes/features/feature_run.php';

feature_run('id_cards', [
    'panel' => 'reception',
]);
