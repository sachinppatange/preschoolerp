<?php
/**
 * Shared feature: member_profile
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'My Profile');
$memberId = (int)(auth_user_id() ?? 0);
$showTeacherFields = (bool)($cfg['show_teacher_fields'] ?? true);
$avatarSubdir = (string)($cfg['avatar_subdir'] ?? 'teachers');
$defaultRoleLabel = (string)($cfg['default_role_label'] ?? 'teacher');
$panelBase = panel_base_url($panel);

if (!function_exists('validate_csrf_token') && file_exists(__DIR__ . '/../csrf.php')) {
    require_once __DIR__ . '/../csrf.php';
}

/* PDO fallback and safe DB helpers */

/* ---------- Page state ---------- */
$messages = [];
$errors = [];

$memberId = (int)(auth_user_id() ?? 0);
$teacherSession = auth_user() ?? [];

/* Load teacher record (users table) */
$user = null;
if (table_exists('users')) {
    $user = safe_db_get_one("SELECT * FROM users WHERE id = :id LIMIT 1", [':id'=>$memberId]);
}

/* POST: update profile */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save')) {
    // CSRF
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid security token.';
    }

    // Collect inputs
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
    $subjects = $showTeacherFields ? trim((string)($_POST['subjects'] ?? '')) : trim((string)($user['subjects'] ?? ''));
    $bio = $showTeacherFields ? trim((string)($_POST['bio'] ?? '')) : trim((string)($user['bio'] ?? ''));

    if ($name === '') $errors[] = 'Name is required.';
    if ($phone === '' || !preg_match('/^\d{10}$/', $phone)) $errors[] = 'Enter a valid 10-digit phone number.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    // Password change handling
    $new_password = trim((string)($_POST['new_password'] ?? ''));
    $new_password_confirm = trim((string)($_POST['new_password_confirm'] ?? ''));
    $current_password = trim((string)($_POST['current_password'] ?? ''));
    $set_password = false;
    if ($new_password !== '' || $new_password_confirm !== '') {
        if ($new_password !== $new_password_confirm) $errors[] = 'New password and confirmation do not match.';
        elseif (strlen($new_password) < 6) $errors[] = 'Password must be at least 6 characters.';
        else $set_password = true;
        if ($set_password && !empty($user['password_hash'])) {
            if ($current_password === '') $errors[] = 'Current password required to change password.';
            elseif (!password_verify($current_password, $user['password_hash'])) $errors[] = 'Current password is incorrect.';
        }
    }

    // Avatar upload
    $avatar_path = $user['avatar'] ?? null;
    if (!empty($_FILES['avatar']['name'])) {
        $f = $_FILES['avatar'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Avatar upload failed (error ' . (int)$f['error'] . ').';
        } else {
            $maxBytes = 2 * 1024 * 1024;
            if ($f['size'] > $maxBytes) $errors[] = 'Avatar must be under 2MB.';
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) $errors[] = 'Avatar must be a JPG, PNG or WEBP image.';
            if (empty($errors)) {
                $ext = $allowed[$mime];
                $storageDir = __DIR__ . '/../storage/' . $avatarSubdir;
                if (!is_dir($storageDir)) @mkdir($storageDir, 0755, true);
                $basename = $avatarSubdir.'_'.$memberId.'_'.bin2hex(random_bytes(6)).'.'.$ext;
                $dest = $storageDir . '/' . $basename;
                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $errors[] = 'Failed to move uploaded avatar.';
                } else {
                    if (!empty($avatar_path) && str_contains((string)$avatar_path, '/storage/')) {
                        $old = __DIR__ . '/../' . ltrim((string)$avatar_path, '/');
                        if (is_file($old)) @unlink($old);
                    }
                    $avatar_path = '/storage/' . $avatarSubdir . '/' . $basename;
                }
            }
        }
    }

    if (empty($errors)) {
        $params = [
            ':name' => $name,
            ':phone' => $phone,
            ':email' => $email !== '' ? $email : null,
            ':address' => $address !== '' ? $address : null,
            ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
            ':id' => $memberId
        ];
        $sql = "UPDATE users SET name = :name, phone = :phone, email = :email, address = :address, whatsapp_id = :whatsapp_id";
        if ($showTeacherFields) {
            $params[':subjects'] = $subjects !== '' ? $subjects : null;
            $params[':bio'] = $bio !== '' ? $bio : null;
            $sql .= ", subjects = :subjects, bio = :bio";
        }

        if ($set_password) {
            $params[':password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password_hash = :password_hash";
        }
        if (!empty($avatar_path)) {
            $sql .= ", avatar = :avatar";
            $params[':avatar'] = $avatar_path;
        }
        $sql .= ", updated_at = NOW() WHERE id = :id";

        $ok = safe_db_run($sql, $params);
        if ($ok) {
            $messages[] = 'Profile updated successfully.';
            $user = safe_db_get_one("SELECT * FROM users WHERE id = :id LIMIT 1", [':id'=>$memberId]);
            auth_refresh_session_user($user);
        } else {
            $errors[] = 'Failed to update profile. Please try again later.';
        }
    }
}

/* ---------- Render page ---------- */

require_once __DIR__ . '/../header.php';
$csrf = get_csrf_token();
?>

<div class="row justify-content-center">
  <div class="col-md-9 col-lg-7">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0"><?php echo e($pageTitle); ?></h3>
      <a class="btn btn-outline-secondary" href="<?php echo e($panelBase . '/dashboard.php'); ?>">Back to Dashboard</a>
    </div>

    <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="action" value="save">

          <div class="mb-3">
            <label class="form-label">Full name</label>
            <input name="name" class="form-control" required value="<?php echo e($user['name'] ?? ''); ?>">
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Phone (10 digits)</label>
              <input name="phone" class="form-control" maxlength="10" required value="<?php echo e(preg_replace('/\D+/', '', $user['phone'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp ID</label>
              <input name="whatsapp_id" class="form-control" value="<?php echo e($user['whatsapp_id'] ?? ''); ?>">
            </div>
          </div>

          <div class="mb-3 mt-3">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" value="<?php echo e($user['email'] ?? ''); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="3"><?php echo e($user['address'] ?? ''); ?></textarea>
          </div>

<?php if ($showTeacherFields): ?>
          <div class="mb-3">
            <label class="form-label">Subjects (comma separated)</label>
            <input name="subjects" class="form-control" value="<?php echo e($user['subjects'] ?? ''); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Bio / Notes</label>
            <textarea name="bio" class="form-control" rows="4"><?php echo e($user['bio'] ?? ''); ?></textarea>
          </div>

<?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Avatar (JPG / PNG / WEBP &lt; 2MB)</label>
            <div class="d-flex gap-3 align-items-center">
              <input name="avatar" type="file" accept="image/*" class="form-control-file">
              <?php if (!empty($user['avatar'])): ?>
                <img src="<?php echo e($user['avatar']); ?>" alt="avatar" style="height:56px;border-radius:6px;border:1px solid #e6e6e6;">
              <?php endif; ?>
            </div>
          </div>

          <hr>

          <h6>Change password (optional)</h6>
          <?php if (!empty($user['password_hash'])): ?>
            <div class="mb-3">
              <label class="form-label">Current password</label>
              <input name="current_password" type="password" class="form-control" autocomplete="current-password">
            </div>
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">New password</label>
              <input name="new_password" type="password" class="form-control" autocomplete="new-password">
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm new password</label>
              <input name="new_password_confirm" type="password" class="form-control" autocomplete="new-password">
            </div>
          </div>

          <div class="mt-4 text-end">
            <a class="btn btn-outline-secondary" href="<?php echo e($panelBase . '/dashboard.php'); ?>">Cancel</a>
            <button class="btn btn-primary">Save changes</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Account info</h6>
        <div class="small-muted mb-1">User ID: <?php echo (int)($user['id'] ?? 0); ?></div>
        <div class="small-muted mb-1">Role: <?php echo e($user['role'] ?? $defaultRoleLabel); ?></div>
        <div class="small-muted">Last updated: <?php echo e($user['updated_at'] ?? $user['created_at'] ?? '—'); ?></div>
      </div>
    </div>

  </div>
</div>

<?php
/* footer */
require_once __DIR__ . '/../footer.php';

?>