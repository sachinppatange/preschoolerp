<?php
/**
 * login.php — Who are you? Parent or school staff.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
if (file_exists(__DIR__ . '/includes/session_start.php')) {
    require_once __DIR__ . '/includes/session_start.php';
}
if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}
if (file_exists(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
}
if (file_exists(__DIR__ . '/includes/auth.php')) {
    require_once __DIR__ . '/includes/auth.php';
}
if (file_exists(__DIR__ . '/includes/password_login.php')) {
    require_once __DIR__ . '/includes/password_login.php';
}

$home = function_exists('site_url') ? site_url('/') : '/';
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool';
$logo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : (function_exists('site_url') ? site_url('/assets/images/logo.png') : '/assets/images/logo.png');
$defaultLogo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : (function_exists('site_url') ? site_url('/assets/images/default-logo.png') : '/assets/images/default-logo.png');

if (function_exists('auth_role') && auth_role() && empty($_GET['switch'])) {
    $dash = function_exists('auth_role_dashboard_path')
        ? auth_role_dashboard_path(auth_role())
        : '/login.php';
    $to = function_exists('site_url') ? site_url($dash) : $dash;
    header('Location: ' . $to);
    exit;
}

try {
    if (function_exists('db_fetch_one')) {
        $school = db_fetch_one('SELECT name, logo_path FROM schools WHERE id = 1 LIMIT 1');
        if (is_array($school)) {
            if (trim((string) ($school['name'] ?? '')) !== '') {
                $appName = trim((string) $school['name']);
            }
            $lp = trim((string) ($school['logo_path'] ?? ''));
            if ($lp !== '' && function_exists('resolve_image_url')) {
                $logo = resolve_image_url($lp);
            }
        }
    }
} catch (Throwable $e) {
}

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

$login_role = 'hub';
$login_title = 'Log in';
$login_subtitle = '';
$login_back_url = $home;
require_once __DIR__ . '/includes/login_shell.php';
?>

<div class="login-hub">
  <a href="<?php echo htmlspecialchars($home); ?>" class="login-back">← School website</a>

  <div class="login-hub-card">
    <div class="text-center mb-4">
      <img src="<?php echo htmlspecialchars($logo); ?>" alt="" class="logo" width="72" height="72" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($defaultLogo); ?>'">
      <h1><?php echo htmlspecialchars($appName); ?></h1>
      <p class="text-muted mb-0">Who is logging in?</p>
    </div>

    <a class="login-hub-parent" href="<?php echo htmlspecialchars($u('/parent/login.php')); ?>">
      <span class="login-hub-parent-title">I am a parent</span>
      <span class="login-hub-parent-sub">Fees, attendance, notices</span>
    </a>

    <p class="login-hub-staff-label">I work at the school</p>
    <div class="login-hub-staff">
      <a href="<?php echo htmlspecialchars($u('/owner/login.php')); ?>">Owner</a>
      <a href="<?php echo htmlspecialchars($u('/teacher/login.php')); ?>">Teacher</a>
      <a href="<?php echo htmlspecialchars($u('/reception/login.php')); ?>">Reception</a>
      <a href="<?php echo htmlspecialchars($u('/accounts/login.php')); ?>">Accounts</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/login_shell_end.php'; ?>
