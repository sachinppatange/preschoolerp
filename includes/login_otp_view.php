<?php
/**
 * Shared OTP login form body (use after login_shell.php).
 *
 * Expected variables:
 *   $login_role, $login_title, $login_subtitle, $login_hint (optional)
 *   $msg_info, $msg_error, $otp_active, $cooldownRemaining, $ctx
 *   $csrf_token, $OTP_LENGTH, $OTP_EXPIRY_SECONDS, $WA_COUNTRY_CODE, $APP_DEBUG
 */
declare(strict_types=1);

$h = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$logo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : (function_exists('site_url') ? site_url('/assets/images/logo.png') : '/assets/images/logo.png');
$defaultLogo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : (function_exists('site_url') ? site_url('/assets/images/default-logo.png') : '/assets/images/default-logo.png');
$hubUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
$hint = $login_hint ?? ('Only registered ' . ($login_role ?? 'user') . ' numbers can log in.');
?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-card-header">
      <a href="<?php echo $h($hubUrl); ?>" class="login-back d-inline-flex"><i class="bi bi-arrow-left"></i> All logins</a>
      <img src="<?php echo $h($logo); ?>" alt="Logo" class="logo" onerror="this.src='<?php echo $h($defaultLogo); ?>'">
      <span class="login-role-badge <?php echo $h($login_role ?? 'owner'); ?>"><?php echo $h(ucfirst((string)($login_role ?? 'User'))); ?></span>
      <h1><?php echo $h($login_title ?? 'Login'); ?></h1>
      <?php if (!empty($login_subtitle)): ?><p><?php echo $h($login_subtitle); ?></p><?php endif; ?>
    </div>
    <div class="login-card-body">
      <?php if (!empty($msg_info)): ?><div class="alert alert-success"><?php echo $h($msg_info); ?></div><?php endif; ?>
      <?php if (!empty($msg_error)): ?><div class="alert alert-danger"><?php echo $h($msg_error); ?></div><?php endif; ?>

      <?php if (empty($otp_active)): ?>
        <form method="post" class="mt-2" autocomplete="off" id="sendOtpForm">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="send_otp">
          <label class="form-label">Phone (10 digits)</label>
          <div class="input-group mb-3">
            <span class="input-group-text">+<?php echo $h($WA_COUNTRY_CODE ?? '91'); ?></span>
            <input id="phone" name="mobile" type="tel" class="form-control" maxlength="10" inputmode="numeric" pattern="\d{10}" placeholder="e.g. 9812345678" required>
          </div>
          <div class="d-grid">
            <button id="sendOtpBtn" type="submit" class="btn btn-primary btn-lg" disabled>Send OTP</button>
          </div>
          <div class="text-center small text-muted mt-3"><?php echo $h($hint); ?></div>
        </form>
      <?php else: ?>
        <div class="mt-2 mb-2">
          <div class="d-flex justify-content-between align-items-center otp-sent-box">
            <div>
              <div class="small text-muted">OTP sent to</div>
              <div class="fw-medium"><?php echo $h((string)($ctx['mobile_e164'] ?? '')); ?></div>
            </div>
            <form method="post" id="changePhoneForm" class="m-0">
              <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
              <input type="hidden" name="action" value="change_phone">
              <button class="btn btn-sm btn-outline-secondary" type="submit">Change</button>
            </form>
          </div>
        </div>
        <form method="post" id="otpForm" autocomplete="off" class="mb-3">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="login">
          <label class="form-label">Enter OTP</label>
          <input id="otp" name="otp" type="text" class="form-control mb-2" inputmode="numeric" maxlength="<?php echo (int)($OTP_LENGTH ?? 4); ?>" required placeholder="<?php echo (int)($OTP_LENGTH ?? 4); ?>-digit OTP">
          <div class="small text-muted mb-3">Check WhatsApp or use Resend. Valid for <?php echo (int)(($OTP_EXPIRY_SECONDS ?? 300) / 60); ?> minutes.</div>
          <button id="verifyBtn" type="submit" class="btn btn-success w-100">Verify &amp; Login</button>
        </form>
        <form id="resendForm" method="post" class="m-0">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="resend_otp">
          <button id="resendBtn" type="submit" class="btn btn-outline-secondary w-100" <?php echo ($cooldownRemaining ?? 0) > 0 ? 'disabled' : ''; ?>>
            <?php echo ($cooldownRemaining ?? 0) > 0 ? ('Resend in ' . sprintf('%02d', (int)$cooldownRemaining) . 's') : 'Resend OTP'; ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if (!empty($APP_DEBUG)): ?>
        <div class="mt-3 small text-muted">DEBUG: session <?php echo $h(session_id()); ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/login_otp_scripts.php'; ?>
