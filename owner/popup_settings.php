<?php
/**
 * owner/popup_settings.php
 *
 * Admin page to edit site popup (image + title + text + show_once).
 * - Provides image upload (stores in /assets/uploads).
 * - Saves popup config inside schools.settings JSON (school id = 1) under key "popup".
 * - Mirrors upload behavior and helpers used in owner/notices_publish.php.
 *
 * Place at: /path/to/your/project/owner/popup_settings.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

session_start();

if (!function_exists('app_base')) {
    function app_base(): string {
        return defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    }
}

if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) return call_user_func('db_fetch_one', $sql, $params);
        $pdo = ensure_pdo();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        }
        return null;
    }
}
if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) return (bool) call_user_func('db_execute', $sql, $params);
        $pdo = ensure_pdo();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            return (bool)$stmt->execute($params);
        }
        return false;
    }
}

if (!function_exists('decode_json_field')) {
    function decode_json_field($val) {
        if ($val === null || $val === '') return [];
        if (is_array($val)) return $val;
        $decoded = json_decode((string)$val, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
    }
}

function ensure_upload_dir(): string|false {
    $appRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $dir = $appRoot . '/assets/uploads';
    if (is_dir($dir) && is_writable($dir)) return $dir;
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0775, true)) { @chmod($dir, 0775); return $dir; }
        return false;
    }
    return is_writable($dir) ? $dir : false;
}
function is_valid_image(string $tmp): bool {
    $info = @getimagesize($tmp);
    if ($info === false) return false;
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    return in_array($info[2], $allowed, true);
}
function web_path_for_upload(string $filename): string {
    return '/assets/uploads/' . ltrim($filename, '/');
}

/* -------------------------
   Load current popup settings from schools.settings (school id = 1)
   ------------------------- */
$schoolId = 1;
$errors = [];
$success = '';

$currentSettings = [];
try {
    $row = db_get_one("SELECT settings FROM schools WHERE id = :id LIMIT 1", [':id' => $schoolId]);
    $currentSettings = decode_json_field($row['settings'] ?? null);
} catch (Throwable $t) {
    $currentSettings = [];
}

/* default popup structure */
$popup = $currentSettings['popup'] ?? [
    'enabled' => false,
    'image' => '',
    'title' => '',
    'text' => '',
    'show_once' => true
];

/* -------------------------
   Handle POST save (upload or use existing image)
   ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled = isset($_POST['enabled']) ? true : false;
    $title = trim((string)($_POST['title'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    $show_once = isset($_POST['show_once']) ? true : false;

    // Validate length
    if (strlen($title) > 250) $title = mb_substr($title, 0, 250);
    if (strlen($text) > 10000) $text = mb_substr($text, 0, 10000);

    // Handle upload
    $new_image_path = null;
    if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload error code: ' . intval($_FILES['image']['error']);
        } else {
            $tmp = $_FILES['image']['tmp_name'];
            if (!is_valid_image($tmp)) {
                $errors[] = 'Uploaded file is not a valid image (jpg, png, gif, webp allowed).';
            } else {
                $dir = ensure_upload_dir();
                if ($dir === false) {
                    $errors[] = 'Upload directory not writable / could not be created.';
                } else {
                    $orig = basename((string)($_FILES['image']['name'] ?? 'upload'));
                    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                    try {
                        $uniq = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safe;
                    } catch (Exception $e) {
                        $uniq = time() . '_' . bin2hex(substr(md5(uniqid('', true)),0,6)) . '_' . $safe;
                    }
                    $dest = $dir . '/' . $uniq;
                    if (@move_uploaded_file($tmp, $dest)) {
                        // optional: set file permissions
                        @chmod($dest, 0644);
                        $new_image_path = web_path_for_upload($uniq);
                    } else {
                        $errors[] = 'Failed to move uploaded file.';
                    }
                }
            }
        }
    } elseif (!empty($_POST['image_url'])) {
        // allow manual URL input as alternative to upload
        $maybe = trim((string)$_POST['image_url']);
        if ($maybe !== '') {
            // simple validation: accept if starts with http(s) or with /
            if (preg_match('#^https?://#i', $maybe) || strpos($maybe, '/') === 0) {
                $new_image_path = $maybe;
            } else {
                $errors[] = 'Image URL must be absolute (https://) or an absolute path starting with /.';
            }
        }
    }

    // If no new image provided, keep existing
    $final_image = $new_image_path ?? ($popup['image'] ?? '');

    if (empty($errors)) {
        $currentSettings['popup'] = [
            'enabled' => (bool)$enabled,
            'image' => $final_image,
            'title' => $title,
            'text' => $text,
            'show_once' => (bool)$show_once
        ];
        $json = json_encode($currentSettings, JSON_UNESCAPED_UNICODE);
        try {
            $ok = db_run("UPDATE schools SET settings = :s WHERE id = :id", [':s' => $json, ':id' => $schoolId]);
            if ($ok) {
                $success = 'Popup settings saved.';
                // If replacing an uploaded image, optionally delete the old uploaded file (if it was in uploads)
                if (!empty($new_image_path) && !empty($popup['image']) && $popup['image'] !== $new_image_path) {
                    // try to delete only if old image is inside /assets/uploads
                    $old = $popup['image'];
                    if (strpos($old, '/assets/uploads/') !== false) {
                        $rel = $old;
                        // convert to filesystem path
                        $fs = realpath(__DIR__ . '/..') . '/' . ltrim(preg_replace('#^' . preg_quote(app_base(), '#') . '#', '', $rel), '/');
                        if ($fs && is_file($fs)) @unlink($fs);
                    }
                }
                // reload popup var
                $popup = $currentSettings['popup'];
                // redirect to avoid double-post
                header('Location: ' . site_url('/owner/popup_settings.php'));
                exit;
            } else {
                $errors[] = 'Database update failed.';
            }
        } catch (Throwable $t) {
            $errors[] = 'Database error: ' . $t->getMessage();
        }
    }
}

/* If settings exist, refresh $popup from DB (safe)
   (in case the page was loaded without POST) */
try {
    $row2 = db_get_one("SELECT settings FROM schools WHERE id = :id LIMIT 1", [':id' => $schoolId]);
    $cs = decode_json_field($row2['settings'] ?? null);
    if (!empty($cs['popup']) && is_array($cs['popup'])) $popup = $cs['popup'];
} catch (Throwable $t) {
    // ignore
}

/* -------------------------
   Render page (owner style similar to notices_publish)
   ------------------------- */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Owner — Popup settings</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .preview { max-width:360px; border-radius:8px; overflow:hidden; border:1px solid #eee; box-shadow:0 6px 18px rgba(0,0,0,0.06); }
  .preview img{ width:100%; display:block; height:auto; }
  .helper { font-size:0.9rem; color:#666; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Popup settings</h2>
    <div>
      <a class="btn btn-outline-secondary" href="<?php echo e(site_url('/owner/dashboard.php')); ?>">Dashboard</a>
      <a class="btn btn-outline-primary" href="<?php echo e(site_url('/owner/notices_publish.php')); ?>">Manage Notices</a>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>

  <div class="card p-3 mb-4">
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <div class="col-12">
        <label class="form-check-label">
          <input type="checkbox" name="enabled" class="form-check-input me-2" <?php echo !empty($popup['enabled']) ? 'checked' : ''; ?>>
          Enable popup on site load
        </label>
      </div>

      <div class="col-md-8">
        <label class="form-label">Upload image (recommended)</label>
        <input type="file" name="image" accept="image/*" class="form-control">
        <div class="helper">Allowed types: jpg, png, gif, webp. If you upload, it will be stored in /assets/uploads and used for the popup.</div>
        <div class="mt-2">
          <label class="form-label">Or specify image URL (absolute or site-relative)</label>
          <input type="text" name="image_url" class="form-control" value="<?php echo e($popup['image'] ?? ''); ?>" placeholder="https://... or /assets/uploads/your.jpg">
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Preview</label>
        <div class="preview">
          <?php if (!empty($popup['image'])): ?>
            <img src="<?php echo e($popup['image']); ?>" alt="popup preview" onerror="this.onerror=null;this.src='<?php echo e(site_url('/assets/images/default-logo.png')); ?>'">
          <?php else: ?>
            <div style="padding:28px;text-align:center;color:#666">No image set</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" value="<?php echo e($popup['title'] ?? ''); ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Text / HTML (small)</label>
        <textarea name="text" rows="6" class="form-control"><?php echo e($popup['text'] ?? ''); ?></textarea>
        <div class="helper">Keep it short. Avoid &lt;script&gt; tags — frontend will sanitize embeds/JS if implemented.</div>
      </div>

      <div class="col-12">
        <label class="form-check-label">
          <input type="checkbox" name="show_once" class="form-check-input me-2" <?php echo !empty($popup['show_once']) ? 'checked' : ''; ?>>
          Only show once per browser (client localStorage)
        </label>
      </div>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Save popup settings</button>
        <a class="btn btn-secondary" href="<?php echo e(site_url('/owner')); ?>">Back</a>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <h6>Notes</h6>
    <ul class="small text-muted">
      <li>Settings are stored inside <code>schools.settings</code> JSON under key <code>popup</code> for school id = 1.</li>
      <li>Uploaded images are stored in <code>/assets/uploads</code>. Old uploaded images are automatically removed when replaced.</li>
      <li>Public site must render the popup using these settings (footer or index). If you need, I can provide the frontend modal snippet too.</li>
    </ul>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>