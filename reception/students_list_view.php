<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');

$id = (int) ($_GET['id'] ?? 0);
$dest = $id > 0
    ? (function_exists('site_url') ? site_url('/reception/students_view.php?id=' . $id) : ('students_view.php?id=' . $id))
    : (function_exists('site_url') ? site_url('/reception/students_list.php') : 'students_list.php');
header('Location: ' . $dest, true, 302);
exit;
