<?php
/**
 * Shared login page shell (OTP + hub pages).
 *
 * Set before include:
 *   $login_role      — owner|accounts|teacher|reception|parent|hub
 *   $login_title     — page heading
 *   $login_subtitle  — optional subtext
 *   $login_back_url  — back link (default: site home or login hub)
 */
declare(strict_types=1);

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/config.php';
}
if (!function_exists('resolve_image_url') && file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

$login_role = $login_role ?? 'owner';
$login_title = $login_title ?? 'Login';
$login_subtitle = $login_subtitle ?? '';
$base = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool App';
$logo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : $base . '/assets/images/logo.png';
$defaultLogo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : $base . '/assets/images/default-logo.png';

if (empty($login_back_url)) {
    $login_back_url = ($login_role === 'hub')
        ? (function_exists('site_url') ? site_url('/') : $base . '/')
        : (function_exists('site_url') ? site_url('/login.php') : $base . '/login.php');
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($login_title . ' — ' . $appName); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo htmlspecialchars($base); ?>/assets/css/login.css" rel="stylesheet">
</head>
<body class="login-page">
