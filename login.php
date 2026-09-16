<?php
/**
 * login.php — Role login hub (Quick Links)
 */
declare(strict_types=1);

if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
}
if (file_exists(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php';
}

$home = function_exists('site_url') ? site_url('/') : '/';
$logo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : (function_exists('site_url') ? site_url('/assets/images/logo.png') : '/assets/images/logo.png');
$defaultLogo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : (function_exists('site_url') ? site_url('/assets/images/default-logo.png') : '/assets/images/default-logo.png');
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool App';

$login_role = 'hub';
$login_title = 'Staff & Parent Portal';
$login_subtitle = 'Choose your login area';
$login_back_url = $home;
require_once __DIR__ . '/includes/login_shell.php';
?>

<div class="login-hub">
  <a href="<?php echo htmlspecialchars($home); ?>" class="login-back"><i class="bi bi-arrow-left"></i> Back to website</a>

  <div class="login-hub-card">
    <div class="text-center mb-3">
      <img src="<?php echo htmlspecialchars($logo); ?>" alt="School logo" class="login-card-header logo" style="width:72px;height:72px" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($defaultLogo); ?>'">
      <h1 style="font-family:var(--ps-font-display);font-size:1.5rem;font-weight:700"><?php echo htmlspecialchars($appName); ?></h1>
      <p class="text-muted mb-0">Staff &amp; Parent Login Portal</p>
    </div>

    <div class="btn-grid" role="navigation" aria-label="Quick login links">
      <a class="ps-btn ps-pink" href="<?php echo htmlspecialchars(site_url('/owner/login.php')); ?>"><i class="bi bi-shield-lock"></i> Owner</a>
      <a class="ps-btn ps-blue" href="<?php echo htmlspecialchars(site_url('/accounts/login.php')); ?>"><i class="bi bi-calculator"></i> Accounts</a>
      <a class="ps-btn ps-green" href="<?php echo htmlspecialchars(site_url('/teacher/login.php')); ?>"><i class="bi bi-mortarboard"></i> Teacher</a>
      <a class="ps-btn ps-yellow" href="<?php echo htmlspecialchars(site_url('/reception/login.php')); ?>"><i class="bi bi-headset"></i> Reception / Staff</a>
      <a class="ps-btn ps-purple" href="<?php echo htmlspecialchars(site_url('/parent/login.php')); ?>"><i class="bi bi-people"></i> Parent</a>
      <a class="ps-btn ps-orange" href="<?php echo htmlspecialchars(site_url('/complaint.php')); ?>"><i class="bi bi-chat-square-text"></i> Complaint</a>
      <a class="ps-btn ps-blue" href="<?php echo htmlspecialchars(site_url('/feedback.php')); ?>"><i class="bi bi-stars"></i> Feedback</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/login_shell_end.php'; ?>
