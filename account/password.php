<?php
/**
 * Create / update User ID & password (login without OTP).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session_start.php';
require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/db_compat.php')) {
    require_once __DIR__ . '/../includes/db_compat.php';
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
if (file_exists(__DIR__ . '/../includes/password_login.php')) {
    require_once __DIR__ . '/../includes/password_login.php';
}
if (file_exists(__DIR__ . '/../includes/functions.php')) {
    require_once __DIR__ . '/../includes/functions.php';
}

$uid = function_exists('auth_user_id') ? auth_user_id() : null;
$role = function_exists('auth_role') ? auth_role() : null;
if (!$uid) {
    $login = function_exists('site_url') ? site_url('/login.php') : '/login.php';
    header('Location: ' . $login);
    exit;
}

user_password_column_ready();
$user = db_fetch_one('SELECT id, name, phone, role, password_hash FROM users WHERE id = ? LIMIT 1', [$uid]);
if (!$user) {
    auth_clear_session();
    header('Location: ' . (function_exists('site_url') ? site_url('/login.php') : '/login.php'));
    exit;
}

$errors = [];
$messages = [];
$loginId = user_login_id_from_row($user);
$minLen = login_password_min_length();
$hasPw = !empty($user['password_hash']);
$dash = auth_role_dashboard_path((string) ($user['role'] ?? $role));
$dashUrl = function_exists('site_url') ? site_url($dash) : $dash;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($token)) {
        $errors[] = 'Invalid security token. Reload the page.';
    } else {
        $old = (string) ($_POST['old_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        if ($hasPw) {
            if ($old === '' || !password_verify($old, (string) $user['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            }
        }
        if ($new !== $confirm) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($new) < $minLen) {
            $errors[] = 'Password must be at least ' . $minLen . ' characters.';
        }
        if ($errors === []) {
            if (login_set_user_password((int) $user['id'], $new)) {
                unset($_SESSION['force_set_password']);
                $dest = function_exists('site_url')
                    ? site_url((string) ($_SESSION['post_login_redirect'] ?? $dash))
                    : $dash;
                unset($_SESSION['post_login_redirect']);
                header('Location: ' . $dest);
                exit;
            }
            $errors[] = 'Could not save password. Try again.';
        }
    }
}

$login_role = (string) ($user['role'] ?? 'owner');
$login_title = 'User ID & Password';
$csrf_token = get_csrf_token();
require_once __DIR__ . '/../includes/login_shell.php';
$h = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<div class="login-wrap">
  <div class="login-card" style="max-width:520px">
    <div class="login-card-header text-start">
      <h1 class="text-start">User ID &amp; Password</h1>
      <p class="text-start">Create your User ID &amp; Password to login without OTP</p>
    </div>
    <div class="login-card-body">
      <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $h($m); ?></div><?php endforeach; ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?php echo $h($er); ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf_token); ?>">
        <div class="mb-3">
          <div class="small text-muted">User ID</div>
          <div class="fw-semibold"><?php echo $h($loginId !== '' ? $loginId : '—'); ?></div>
        </div>
        <?php if ($hasPw): ?>
          <label class="form-label">Old Password <span class="text-danger">*</span></label>
          <input name="old_password" type="password" class="form-control mb-3" required autocomplete="current-password" placeholder="Enter current password">
        <?php endif; ?>
        <label class="form-label">Password <span class="text-danger">*</span></label>
        <input name="new_password" type="password" class="form-control mb-3" required minlength="<?php echo (int)$minLen; ?>" autocomplete="new-password" placeholder="Enter new password">
        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
        <input name="new_password_confirm" type="password" class="form-control mb-3" required minlength="<?php echo (int)$minLen; ?>" autocomplete="new-password" placeholder="Enter confirm password">
        <button class="btn btn-success" type="submit"><i class="bi bi-save me-1"></i>Update</button>
        <?php if (empty($_SESSION['force_set_password'])): ?>
          <a class="btn btn-outline-secondary ms-2" href="<?php echo $h($dashUrl); ?>">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/login_shell_end.php'; ?>
