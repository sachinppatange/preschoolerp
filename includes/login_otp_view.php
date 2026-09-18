<?php
/**
 * Shared OTP + User ID/password login form.
 */
declare(strict_types=1);

$h = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$logo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : (function_exists('site_url') ? site_url('/assets/images/logo.png') : '/assets/images/logo.png');
$defaultLogo = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : (function_exists('site_url') ? site_url('/assets/images/default-logo.png') : '/assets/images/default-logo.png');
$hubUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
$hint = $login_hint ?? ('Only registered ' . ($login_role ?? 'user') . ' numbers can log in.');
$login_mode = $login_mode ?? ($otp['login_mode'] ?? 'otp');
$otp_gateways_ok = $otp_gateways_ok ?? ($otp['otp_gateways_ok'] ?? true);
$remembered_login_id = $remembered_login_id ?? ($otp['remembered_login_id'] ?? '');
$resetPending = !empty($otp_active) && (($ctx['purpose'] ?? '') === 'reset');
if ($resetPending) {
    $login_mode = 'forgot';
}
$self = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
$modeUrl = static function (string $mode) use ($self): string {
    $q = $mode === '' ? $self : ($self . '?mode=' . rawurlencode($mode));
    return $q !== '' ? $q : ('?mode=' . rawurlencode($mode));
};
$postedUserId = (string) ($_POST['user_id'] ?? $remembered_login_id);
$minPw = function_exists('login_password_min_length') ? login_password_min_length() : 6;
?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-card-header">
      <a href="<?php echo $h($hubUrl); ?>" class="login-back d-inline-flex"><i class="bi bi-arrow-left"></i> All logins</a>
      <img src="<?php echo $h($logo); ?>" alt="Logo" class="logo" onerror="this.src='<?php echo $h($defaultLogo); ?>'">
      <span class="login-role-badge <?php echo $h($login_role ?? 'owner'); ?>"><?php echo $h(ucfirst((string)($login_role ?? 'User'))); ?></span>
      <h1><?php echo $h($login_title ?? 'Login'); ?></h1>
      <?php if ($login_mode === 'password'): ?>
        <p>Sign in with your User ID and password. Use this when OTP is unavailable.</p>
      <?php elseif ($login_mode === 'forgot'): ?>
        <p>Reset your password with OTP, then login without OTP next time.</p>
      <?php elseif (!empty($login_subtitle)): ?>
        <p><?php echo $h($login_subtitle); ?></p>
      <?php endif; ?>
    </div>
    <div class="login-card-body">
      <?php if (!empty($msg_info)): ?><div class="alert alert-success"><?php echo $h($msg_info); ?></div><?php endif; ?>
      <?php if (!empty($msg_error)): ?><div class="alert alert-danger"><?php echo $h($msg_error); ?></div><?php endif; ?>
      <?php if (empty($otp_gateways_ok) && $login_mode !== 'password'): ?>
        <div class="alert alert-warning">OTP gateways are off. Use User ID &amp; password login.</div>
      <?php endif; ?>

      <?php if ($login_mode === 'password'): ?>
        <form method="post" class="mt-2" autocomplete="username" id="passwordLoginForm">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="password_login">
          <input type="hidden" name="login_mode" value="password">
          <label class="form-label">Please enter your user id <span class="text-danger">*</span></label>
          <div class="input-group mb-3">
            <span class="input-group-text"><i class="bi bi-person"></i></span>
            <input name="user_id" type="text" class="form-control" required value="<?php echo $h($postedUserId); ?>" placeholder="10-digit mobile or email" autocomplete="username">
          </div>
          <label class="form-label">Please enter your password <span class="text-danger">*</span></label>
          <div class="input-group mb-3">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input id="login_password" name="password" type="password" class="form-control" required placeholder="Password" autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" id="toggleLoginPw" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <label class="form-check-label small mb-0">
              <input class="form-check-input me-1" type="checkbox" name="remember_me" value="1" <?php echo $remembered_login_id !== '' ? 'checked' : ''; ?>>
              Remember me on this device
            </label>
            <a class="small" href="<?php echo $h($modeUrl('forgot')); ?>">Forgot password?</a>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-success btn-lg">Login</button>
          </div>
        </form>
        <div class="login-or"><span>OR</span></div>
        <a class="btn btn-outline-secondary w-100" href="<?php echo $h($modeUrl('otp')); ?>">Login using OTP</a>
        <div class="text-center small text-muted mt-3">User ID is your registered 10-digit mobile. Set a password from Profile after OTP login.</div>

      <?php elseif ($login_mode === 'forgot'): ?>
        <?php if ($resetPending): ?>
          <form method="post" class="mt-2" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
            <input type="hidden" name="action" value="forgot_save">
            <input type="hidden" name="login_mode" value="forgot">
            <div class="small text-muted mb-2">OTP sent to <?php echo $h((string)($ctx['mobile_e164'] ?? '')); ?></div>
            <label class="form-label">Enter OTP <span class="text-danger">*</span></label>
            <input name="otp" type="text" class="form-control mb-3" inputmode="numeric" maxlength="<?php echo (int)($OTP_LENGTH ?? 4); ?>" required placeholder="<?php echo (int)($OTP_LENGTH ?? 4); ?>-digit OTP">
            <label class="form-label">New password <span class="text-danger">*</span></label>
            <input name="new_password" type="password" class="form-control mb-3" required minlength="<?php echo (int)$minPw; ?>" autocomplete="new-password">
            <label class="form-label">Confirm password <span class="text-danger">*</span></label>
            <input name="new_password_confirm" type="password" class="form-control mb-3" required minlength="<?php echo (int)$minPw; ?>" autocomplete="new-password">
            <div class="d-grid"><button class="btn btn-success btn-lg" type="submit">Update &amp; Login</button></div>
          </form>
        <?php else: ?>
          <form method="post" class="mt-2" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
            <input type="hidden" name="action" value="forgot_send">
            <input type="hidden" name="login_mode" value="forgot">
            <label class="form-label">User ID <span class="text-danger">*</span></label>
            <input name="user_id" type="text" class="form-control mb-3" required value="<?php echo $h($postedUserId); ?>" placeholder="10-digit mobile or email">
            <div class="d-grid"><button class="btn btn-primary btn-lg" type="submit">Send OTP</button></div>
          </form>
        <?php endif; ?>
        <div class="text-center mt-3"><a class="small" href="<?php echo $h($modeUrl('password')); ?>">Back to User ID login</a></div>

      <?php elseif (empty($otp_active)): ?>
        <form method="post" class="mt-2" autocomplete="off" id="sendOtpForm">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="send_otp">
          <input type="hidden" name="login_mode" value="otp">
          <label class="form-label">Phone (10 digits) <span class="text-danger">*</span></label>
          <div class="input-group mb-3">
            <span class="input-group-text">+<?php echo $h($WA_COUNTRY_CODE ?? '91'); ?></span>
            <input id="phone" name="mobile" type="tel" class="form-control" maxlength="10" inputmode="numeric" pattern="\d{10}" placeholder="Enter your phone number" required>
          </div>
          <div class="d-grid">
            <button id="sendOtpBtn" type="submit" class="btn btn-primary btn-lg" disabled>Send OTP</button>
          </div>
        </form>
        <div class="login-or"><span>OR</span></div>
        <a class="btn btn-outline-secondary w-100" href="<?php echo $h($modeUrl('password')); ?>">Login using User ID</a>
        <div class="text-center small text-muted mt-3"><?php echo $h($hint); ?></div>

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
              <input type="hidden" name="login_mode" value="otp">
              <button class="btn btn-sm btn-outline-secondary" type="submit">Change</button>
            </form>
          </div>
        </div>
        <form method="post" id="otpForm" autocomplete="off" class="mb-3">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="login_mode" value="otp">
          <label class="form-label">Enter OTP</label>
          <input id="otp" name="otp" type="text" class="form-control mb-2" inputmode="numeric" maxlength="<?php echo (int)($OTP_LENGTH ?? 4); ?>" required placeholder="<?php echo (int)($OTP_LENGTH ?? 4); ?>-digit OTP">
          <div class="small text-muted mb-3">Valid for <?php echo (int)(($OTP_EXPIRY_SECONDS ?? 300) / 60); ?> minutes.</div>
          <button id="verifyBtn" type="submit" class="btn btn-success w-100">Verify &amp; Login</button>
        </form>
        <form id="resendForm" method="post" class="m-0">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf_token ?? ''); ?>">
          <input type="hidden" name="action" value="resend_otp">
          <input type="hidden" name="login_mode" value="otp">
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
