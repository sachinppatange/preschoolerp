<?php
/**
 * Shared feature: profile (My Profile)
 * Used by owner/teacher/parent/reception/accounts wrappers via feature_run('profile').
 *
 * Adapts to the live `users` schema: extra fields (email, address, avatar, bio)
 * are stored in dedicated columns when present, otherwise in `users.meta` JSON.
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$pageTitle = (string) ($cfg['page_title'] ?? 'My Profile');
$showTeacherFields = (bool) ($cfg['show_teacher_fields'] ?? ($panel === 'teacher'));
$avatarSubdir = (string) ($cfg['avatar_subdir'] ?? ($panel === 'parent' ? 'parents' : ($panel === 'teacher' ? 'teachers' : 'staff')));
$defaultRoleLabel = (string) ($cfg['default_role_label'] ?? $panel);
$panelBase = panel_base_url($panel);

$memberId = (int) (auth_user_id() ?? 0);
if ($memberId <= 0) {
    header('Location: ' . auth_login_url($panel));
    exit;
}

$messages = [];
$errors = [];

$userCols = function_exists('get_table_columns') ? get_table_columns('users') : [];
$hasCol = static function (string $col) use ($userCols): bool {
    return in_array(strtolower($col), $userCols, true);
};

$user = table_exists('users')
    ? (safe_db_get_one('SELECT * FROM users WHERE id = :id LIMIT 1', [':id' => $memberId]) ?: null)
    : null;

if (!$user) {
    $errors[] = 'Your account record was not found. Please log in again.';
}

$meta = [];
if (!empty($user['meta'])) {
    $decoded = json_decode((string) $user['meta'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$field = static function (array $user, array $meta, string $key, string $default = '') {
    $v = $user[$key] ?? null;
    if ($v !== null && $v !== '') {
        return (string) $v;
    }
    $m = $meta[$key] ?? null;
    return ($m !== null && $m !== '') ? (string) $m : $default;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save' && $user) {
    $csrfOk = function_exists('validate_csrf_token') && validate_csrf_token($_POST['csrf'] ?? '');
    if (!$csrfOk) {
        $errors[] = 'Invalid security token. Refresh the page and try again.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $phoneDigits = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
    $whatsapp_id = trim((string) ($_POST['whatsapp_id'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $subjects = $showTeacherFields ? trim((string) ($_POST['subjects'] ?? '')) : $field($user, $meta, 'subjects');
    $bio = $showTeacherFields ? trim((string) ($_POST['bio'] ?? '')) : $field($user, $meta, 'bio');

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    $len = strlen($phoneDigits);
    if ($len < 10 || $len > 15) {
        $errors[] = 'Enter a valid phone number (10–15 digits, with country code if needed).';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if ($whatsapp_id === '') {
        $whatsapp_id = $phoneDigits;
    }

    $new_password = (string) ($_POST['new_password'] ?? '');
    $new_password_confirm = (string) ($_POST['new_password_confirm'] ?? '');
    $current_password = (string) ($_POST['current_password'] ?? '');
    $set_password = false;
    if ($hasCol('password_hash') && ($new_password !== '' || $new_password_confirm !== '')) {
        if ($new_password !== $new_password_confirm) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($new_password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        } else {
            $set_password = true;
            $existingHash = (string) ($user['password_hash'] ?? '');
            if ($existingHash !== '') {
                if ($current_password === '') {
                    $errors[] = 'Current password is required to change password.';
                    $set_password = false;
                } elseif (!password_verify($current_password, $existingHash)) {
                    $errors[] = 'Current password is incorrect.';
                    $set_password = false;
                }
            }
        }
    }

    $avatar_path = $field($user, $meta, 'avatar');
    if (!empty($_FILES['avatar']['name']) && (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['avatar'];
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed.';
        } elseif ((int) $f['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = (string) finfo_file($finfo, $f['tmp_name']);
                finfo_close($finfo);
            } else {
                $info = @getimagesize($f['tmp_name']);
                $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            }
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                $errors[] = 'Photo must be JPG, PNG or WEBP.';
            } elseif (empty($errors)) {
                $dir = function_exists('cms_upload_dir') ? cms_upload_dir() : false;
                if ($dir === false) {
                    $errors[] = 'Upload folder is not writable (assets/uploads).';
                } else {
                    $basename = $avatarSubdir . '_' . $memberId . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                    $dest = $dir . '/' . $basename;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        $errors[] = 'Failed to save photo.';
                    } else {
                        @chmod($dest, 0644);
                        $avatar_path = '/assets/uploads/' . $basename;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $sets = ['name = :name', 'phone = :phone', 'whatsapp_id = :whatsapp_id', 'updated_at = NOW()'];
        $params = [
            ':name' => $name,
            ':phone' => $phoneDigits,
            ':whatsapp_id' => $whatsapp_id,
            ':id' => $memberId,
        ];

        $nextMeta = $meta;
        $nextMeta['email'] = $email;
        $nextMeta['address'] = $address;
        if ($showTeacherFields) {
            $nextMeta['subjects'] = $subjects;
            $nextMeta['bio'] = $bio;
        }
        if ($avatar_path !== '') {
            $nextMeta['avatar'] = $avatar_path;
        }

        if ($hasCol('email')) {
            $sets[] = 'email = :email';
            $params[':email'] = $email !== '' ? $email : null;
        }
        if ($hasCol('address')) {
            $sets[] = 'address = :address';
            $params[':address'] = $address !== '' ? $address : null;
        }
        if ($hasCol('avatar') && $avatar_path !== '') {
            $sets[] = 'avatar = :avatar';
            $params[':avatar'] = $avatar_path;
        }
        if ($showTeacherFields && $hasCol('subjects')) {
            $sets[] = 'subjects = :subjects';
            $params[':subjects'] = $subjects !== '' ? $subjects : null;
        }
        if ($showTeacherFields && $hasCol('bio')) {
            $sets[] = 'bio = :bio';
            $params[':bio'] = $bio !== '' ? $bio : null;
        }
        if ($set_password && $hasCol('password_hash')) {
            $sets[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
        }
        if ($hasCol('meta')) {
            $sets[] = 'meta = :meta';
            $params[':meta'] = json_encode($nextMeta, JSON_UNESCAPED_UNICODE);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $ok = safe_db_run($sql, $params);
        if ($ok) {
            $messages[] = 'Profile updated successfully.';
            $user = safe_db_get_one('SELECT * FROM users WHERE id = :id LIMIT 1', [':id' => $memberId]) ?: $user;
            $meta = $nextMeta;
            if (!empty($user['meta'])) {
                $decoded = json_decode((string) $user['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            if (function_exists('auth_refresh_session_user') && is_array($user)) {
                auth_refresh_session_user($user);
            }
        } else {
            $errors[] = 'Could not save profile. Please try again.';
        }
    }
}

$nameVal = $user ? $field($user, $meta, 'name') : '';
$phoneVal = $user ? $field($user, $meta, 'phone') : '';
$waVal = $user ? $field($user, $meta, 'whatsapp_id') : '';
$emailVal = $user ? $field($user, $meta, 'email') : '';
$addressVal = $user ? $field($user, $meta, 'address') : '';
$subjectsVal = $user ? $field($user, $meta, 'subjects') : '';
$bioVal = $user ? $field($user, $meta, 'bio') : '';
$avatarVal = $user ? $field($user, $meta, 'avatar') : '';
$avatarUrl = ($avatarVal !== '' && function_exists('resolve_image_url'))
    ? resolve_image_url($avatarVal)
    : $avatarVal;

$school = ($panel === 'owner')
    ? (safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => (int) ($user['school_id'] ?? 1)]) ?: [])
    : [];

$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$page_title = $pageTitle;

require_once __DIR__ . '/../header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h3 class="mb-0"><?php echo e($pageTitle); ?></h3>
    <div class="text-muted small">Update your login name, phone, WhatsApp and photo.</div>
  </div>
  <a class="btn btn-outline-secondary" href="<?php echo e($panelBase . '/dashboard.php'); ?>">Back to Dashboard</a>
</div>

<?php foreach ($messages as $m): ?>
  <div class="alert alert-success"><?php echo e($m); ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $er): ?>
  <div class="alert alert-danger"><?php echo e($er); ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="action" value="save">

          <div class="d-flex align-items-center gap-3 mb-4">
            <div>
              <?php if ($avatarUrl !== ''): ?>
                <img src="<?php echo e($avatarUrl); ?>" alt="Profile photo" width="72" height="72" style="object-fit:cover;border-radius:50%;border:1px solid #e6e6e6;">
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center bg-light text-muted" style="width:72px;height:72px;border-radius:50%;border:1px solid #e6e6e6;font-size:1.5rem;">
                  <?php echo e(strtoupper(substr($nameVal !== '' ? $nameVal : 'U', 0, 1))); ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="flex-grow-1">
              <label class="form-label mb-1">Profile photo</label>
              <input name="avatar" type="file" accept="image/jpeg,image/png,image/webp" class="form-control">
              <div class="form-text">JPG, PNG or WEBP, max 2MB.</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Full name</label>
            <input name="name" class="form-control" required value="<?php echo e($nameVal); ?>">
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input name="phone" class="form-control" required value="<?php echo e($phoneVal); ?>">
              <div class="form-text">Used for WhatsApp OTP login.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp number</label>
              <input name="whatsapp_id" class="form-control" value="<?php echo e($waVal); ?>" placeholder="919876543210">
            </div>
          </div>

          <div class="mb-3 mt-3">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" value="<?php echo e($emailVal); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Address</label>
            <textarea name="address" class="form-control" rows="3"><?php echo e($addressVal); ?></textarea>
          </div>

          <?php if ($showTeacherFields): ?>
            <div class="mb-3">
              <label class="form-label">Subjects (comma separated)</label>
              <input name="subjects" class="form-control" value="<?php echo e($subjectsVal); ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Bio / notes</label>
              <textarea name="bio" class="form-control" rows="4"><?php echo e($bioVal); ?></textarea>
            </div>
          <?php endif; ?>

          <?php if ($hasCol('password_hash')): ?>
            <hr>
            <h6>Password login</h6>
            <p class="small text-muted">Use User ID (your 10-digit mobile) and this password when OTP gateways are off. You can also open <a href="<?php echo e(function_exists('site_url') ? site_url('/account/password.php') : '/account/password.php'); ?>">User ID &amp; Password</a>.</p>
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
          <?php endif; ?>

          <div class="mt-4 text-end">
            <a class="btn btn-outline-secondary" href="<?php echo e($panelBase . '/dashboard.php'); ?>">Cancel</a>
            <button class="btn btn-primary" type="submit">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">Account</h6>
        <div class="small text-muted mb-1">User ID: <?php echo (int) ($user['id'] ?? 0); ?></div>
        <div class="small text-muted mb-1">Login User ID: <?php echo e(function_exists('user_login_id_from_row') ? user_login_id_from_row($user) : (string)($user['phone'] ?? '')); ?></div>
        <div class="small text-muted mb-1">Role: <?php echo e((string) ($user['role'] ?? $defaultRoleLabel)); ?></div>
        <div class="small text-muted mb-1">School ID: <?php echo e((string) ($user['school_id'] ?? '—')); ?></div>
        <div class="small text-muted mb-1">Status: <?php echo !empty($user['is_active']) ? 'Active' : 'Inactive'; ?></div>
        <div class="small text-muted">Updated: <?php echo e((string) ($user['updated_at'] ?? $user['created_at'] ?? '—')); ?></div>
      </div>
    </div>

    <?php if ($panel === 'owner'): ?>
      <?php
        $schoolName = (string) ($school['name'] ?? 'School');
        $schoolLogo = function_exists('resolve_image_url')
            ? resolve_image_url((string) ($school['logo_path'] ?? 'assets/images/logo.png'))
            : (string) ($school['logo_path'] ?? '');
        $owner = panel_base_url('owner');
      ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-3">School</h6>
          <div class="d-flex align-items-center gap-2 mb-2">
            <?php if ($schoolLogo !== ''): ?>
              <img src="<?php echo e($schoolLogo); ?>" alt="" width="40" height="40" style="object-fit:contain;">
            <?php endif; ?>
            <div>
              <div class="fw-semibold"><?php echo e($schoolName); ?></div>
              <div class="small text-muted"><?php echo e((string) ($school['tagline'] ?? '')); ?></div>
            </div>
          </div>
          <div class="small text-muted mb-1"><?php echo e((string) ($school['contact_phone'] ?? '')); ?></div>
          <div class="small text-muted"><?php echo e((string) ($school['contact_email'] ?? '')); ?></div>
        </div>
      </div>
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">Related settings</h6>
          <div class="d-grid gap-2">
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/school_profile.php'); ?>"><i class="bi bi-building me-1"></i> School profile (logo, hero)</a>
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/contact_details.php'); ?>"><i class="bi bi-telephone me-1"></i> Contact details</a>
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/staff_manage.php'); ?>"><i class="bi bi-person-badge me-1"></i> Staff</a>
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/parents.php'); ?>"><i class="bi bi-people me-1"></i> Parents</a>
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/popup_settings.php'); ?>"><i class="bi bi-window-stack me-1"></i> Popup settings</a>
            <a class="btn btn-outline-primary btn-sm text-start" href="<?php echo e($owner . '/sessions.php'); ?>"><i class="bi bi-journal-bookmark me-1"></i> Academic sessions</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
require_once __DIR__ . '/../footer.php';
