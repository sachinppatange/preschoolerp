<?php
/**
 * Shared feature: staff_profile
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'My Profile');
$loggedInUserId = (int)(auth_user_id() ?? 0);
$id = $loggedInUserId;
$panelBase = panel_base_url($panel);

/* Auth (central) */

/*
  Include includes/csrf.php ONLY if CSRF helpers are not already defined.
  This prevents "Cannot redeclare" errors when includes/functions.php already
  defines CSRF helpers.
*/
if (!function_exists('validate_csrf_token') && file_exists(__DIR__ . '/../csrf.php')) {
    require_once __DIR__ . '/../csrf.php';
}




/* -------------------------
   CSRF fallbacks (define only if missing)
   ------------------------- */

/* -------------------------
   Load user record for logged-in id
   ------------------------- */
$id = $loggedInUserId;
$user = safe_db_get_one(
    "SELECT id, school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at
     FROM users WHERE id = :id LIMIT 1",
    [':id' => $id]
);

if (!$user) {
    // stale session: clear and redirect to login
    $_SESSION = [];
    @session_destroy();
    header('Location: ' . auth_login_url($panel));
    exit;
}

/* -------------------------
   Handle POST update for this user only
   ------------------------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $posted_id = (int)($_POST['id'] ?? 0);

    if ($posted_id !== $id) {
        $errors[] = 'Invalid request.';
    } elseif (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        // Collect & sanitize inputs
        $school_id = ($_POST['school_id'] ?? '') === '' ? null : (int)($_POST['school_id']);
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role = trim((string)($_POST['role'] ?? ''));
        $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $meta_raw = trim((string)($_POST['meta'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        // Parse meta (JSON or key:value lines)
        $meta_db = null;
        if ($meta_raw !== '') {
            $decoded = json_decode($meta_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || $decoded === null)) {
                $meta_db = $decoded === null ? null : $decoded;
            } else {
                $lines = preg_split("/\r?\n/", $meta_raw);
                $assoc = [];
                foreach ($lines as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    if (strpos($ln, ':') !== false) {
                        [$k, $v] = array_map('trim', explode(':', $ln, 2));
                        $assoc[$k] = $v;
                    } elseif (strpos($ln, '=') !== false) {
                        [$k, $v] = array_map('trim', explode('=', $ln, 2));
                        $assoc[$k] = $v;
                    } else {
                        $assoc[] = $ln;
                    }
                }
                $meta_db = $assoc;
            }
        }

        if (empty($errors)) {
            $meta_json = $meta_db === null ? null : json_encode($meta_db, JSON_UNESCAPED_UNICODE);

            $sql = "UPDATE users SET
                        school_id = :school_id,
                        name = :name,
                        phone = :phone,
                        role = :role,
                        whatsapp_id = :whatsapp_id,
                        is_active = :is_active,
                        meta = :meta,
                        updated_at = NOW()
                    WHERE id = :id";
            $params = [
                ':school_id' => $school_id,
                ':name' => $name,
                ':phone' => $phone,
                ':role' => $role,
                ':whatsapp_id' => $whatsapp_id,
                ':is_active' => $is_active,
                ':meta' => $meta_json,
                ':id' => $id,
            ];

            if (safe_db_run($sql, $params)) {
                $success = 'Profile updated successfully.';
                // refresh user
                $user = safe_db_get_one(
                    "SELECT id, school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at
                     FROM users WHERE id = :id LIMIT 1",
                    [':id' => $id]
                );
                // update session display name if stored as array
                auth_refresh_session_user($user);
            } else {
                $errors[] = 'Database error while updating profile.';
            }
        }
    }
}

/* -------------------------
   Prepare meta textarea content (pretty JSON if applicable)
   ------------------------- */
$meta_text = '';
if (!empty($user['meta'])) {
    $decoded = json_decode((string)$user['meta'], true);
    if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
        $meta_text = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        $meta_text = (string)$user['meta'];
    }
}

/* -------------------------
   Render HTML: follow owner/dashboard style and English labels
   ------------------------- */
/* Use project header if present, otherwise minimal header */

require_once __DIR__ . '/../header.php';
?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo e($success); ?></div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e((string)$err) . '</li>'; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="card p-3 mb-4" novalidate>
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="id" value="<?php echo e((string)$id); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo e(get_csrf_token()); ?>">

    <div class="row g-3">
      <div class="col-12 col-md-4">
        <div class="mb-3">
          <label class="form-label">ID</label>
          <input class="form-control" value="<?php echo e((string)($user['id'] ?? $id)); ?>" readonly>
        </div>

        <div class="mb-3 form-check">
          <input type="checkbox" name="is_active" class="form-check-input" id="is_active" <?php echo (!empty($user['is_active']) && $user['is_active']) ? 'checked' : ''; ?>>
          <label class="form-check-label" for="is_active">Active</label>
        </div>

        <div class="mb-3">
          <label class="form-label">Created at</label>
          <input class="form-control" value="<?php echo e((string)($user['created_at'] ?? '')); ?>" readonly>
        </div>

        <div class="mb-3">
          <label class="form-label">Updated at</label>
          <input class="form-control" value="<?php echo e((string)($user['updated_at'] ?? '')); ?>" readonly>
        </div>
      </div>

      <div class="col-12 col-md-8">
        <div class="mb-3">
          <label class="form-label">School ID</label>
          <input name="school_id" class="form-control" value="<?php echo e((string)($user['school_id'] ?? '')); ?>">
        </div>

        <div class="mb-3">
          <label class="form-label">Name</label>
          <input name="name" class="form-control" value="<?php echo e((string)($user['name'] ?? '')); ?>" required>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input name="phone" class="form-control" value="<?php echo e((string)($user['phone'] ?? '')); ?>">
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Whatsapp ID</label>
              <input name="whatsapp_id" class="form-control" value="<?php echo e((string)($user['whatsapp_id'] ?? '')); ?>">
              <div class="form-text">Enter full international number without + (e.g. 919876543210)</div>
            </div>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Role</label>
          <input name="role" class="form-control" value="<?php echo e((string)($user['role'] ?? '')); ?>">
          <div class="form-text">e.g. reception, staff, parent</div>
        </div>

        <div class="mb-3">
          <label class="form-label">Meta (JSON or key:value lines)</label>
          <textarea name="meta" class="form-control" rows="6" placeholder='{"key":"value"}'><?php echo e($meta_text); ?></textarea>
          <div class="form-text">You can paste JSON or simple "key:value" lines.</div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary">Save Profile</button>
          <a class="btn btn-secondary" href="<?php echo e($panelBase . '/dashboard.php'); ?>">Back</a>
        </div>
      </div>
    </div>
  </form>
</div>

<?php
/* If project footer exists, include it; otherwise render minimal footer */
require_once __DIR__ . '/../footer.php';
