<?php
/**
 * Shared feature: school_profile
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'School Profile');

/**
 * Ensure the uploads directory exists and is writable.
 * Returns full filesystem path on success, or false on failure.
 */

/**
 * Save uploaded file and return web-accessible path (site_url prefixed).
 * On failure, returns null and appends an error message to $errors (by reference).
 */
function save_uploaded_image(string $field, ?string $existing = null, array &$errors = null): ?string {
    // No file uploaded -> keep existing
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return $existing;

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Upload error (code ' . intval($_FILES[$field]['error']) . ')';
        if (is_array($errors)) $errors[] = $msg;
        return $existing;
    }

    $dir = cms_upload_dir();
    if ($dir === false) {
        $msg = 'Upload directory not writable. Create writable folder: ' . realpath(__DIR__ . '/..') . '/assets/uploads and give Apache write permission.';
        if (is_array($errors)) $errors[] = $msg;
        return $existing;
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $origName = basename((string)($_FILES[$field]['name'] ?? 'upload'));
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
    $uniq = time() . '_' . bin2hex(random_bytes(4));
    $destName = $uniq . '_' . $safeName;
    $destPath = $dir . '/' . $destName;

    if (!move_uploaded_file($tmp, $destPath)) {
        $msg = 'Failed to move uploaded file to destination. Check directory permissions.';
        if (is_array($errors)) $errors[] = $msg;
        return $existing;
    }

    @chmod($destPath, 0644);
    if (!is_file($destPath) || filesize($destPath) <= 0) {
        @unlink($destPath);
        $msg = 'Upload was saved but the file is missing or empty. Check assets/uploads permissions (chmod 775).';
        if (is_array($errors)) $errors[] = $msg;
        return $existing;
    }

    // Store site-relative path (resolved at display via resolve_image_url)
    return '/assets/uploads/' . $destName;
}

/* -------------------------
   Load & Save profile
   ------------------------- */
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $tagline = trim((string)($_POST['tagline'] ?? ''));
    $hero_title = trim((string)($_POST['hero_title'] ?? ''));
    $hero_subtitle = trim((string)($_POST['hero_subtitle'] ?? ''));
    $hero_cta_text = trim((string)($_POST['hero_cta_text'] ?? ''));
    $hero_cta_url = trim((string)($_POST['hero_cta_url'] ?? ''));

    if ($name === '') $errors[] = 'School name required.';

    if (empty($errors)) {
        $logo_path = save_uploaded_image('logo', $school['logo_path'] ?? null, $errors);
        $hero_image = save_uploaded_image('hero_image', $school['hero_image'] ?? null, $errors);

        $sql = "INSERT INTO schools (id, name, slug, tagline, logo_path, hero_image, hero_title, hero_subtitle, hero_cta_text, hero_cta_url, updated_at)
                VALUES (1, :name, :slug, :tagline, :logo_path, :hero_image, :hero_title, :hero_subtitle, :hero_cta_text, :hero_cta_url, NOW())
                ON DUPLICATE KEY UPDATE
                  name=VALUES(name),
                  slug=VALUES(slug),
                  tagline=VALUES(tagline),
                  logo_path=VALUES(logo_path),
                  hero_image=VALUES(hero_image),
                  hero_title=VALUES(hero_title),
                  hero_subtitle=VALUES(hero_subtitle),
                  hero_cta_text=VALUES(hero_cta_text),
                  hero_cta_url=VALUES(hero_cta_url),
                  updated_at=NOW()";
        $params = [
            ':name' => $name,
            ':slug' => $slug,
            ':tagline' => $tagline,
            ':logo_path' => $logo_path,
            ':hero_image' => $hero_image,
            ':hero_title' => $hero_title,
            ':hero_subtitle' => $hero_subtitle,
            ':hero_cta_text' => $hero_cta_text,
            ':hero_cta_url' => $hero_cta_url,
        ];
        if (safe_db_run($sql, $params)) {
            $success = 'Profile updated successfully.';
            $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]);
        } else {
            $errors[] = 'Database error while updating profile.';
        }
    }
}

/* -------------------------
   Render
   ------------------------- */
$pageTitle = (string)($cfg['page_title'] ?? 'School Profile');
require_once __DIR__ . '/../header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card p-3">
    <input type="hidden" name="action" value="save_profile">

    <div class="mb-3">
      <label class="form-label">School Name</label>
      <input name="name" class="form-control" value="<?php echo e($school['name'] ?? ''); ?>" required>
    </div>

    <div class="mb-3">
      <label class="form-label">Slug (URL friendly)</label>
      <input name="slug" class="form-control" value="<?php echo e($school['slug'] ?? ''); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Tagline</label>
      <input name="tagline" class="form-control" value="<?php echo e($school['tagline'] ?? ''); ?>">
    </div>

    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Logo (image)</label>
        <input type="file" name="logo" class="form-control">
        <?php if (!empty($school['logo_path'])): ?>
          <div class="mt-2"><img src="<?php echo e(resolve_image_url((string)$school['logo_path'])); ?>" alt="logo" style="height:80px"></div>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label">Hero Image</label>
        <input type="file" name="hero_image" class="form-control">
        <?php if (!empty($school['hero_image'])): ?>
          <div class="mt-2"><img src="<?php echo e(resolve_image_url((string)$school['hero_image'])); ?>" alt="hero" style="height:80px"></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Hero Title</label>
      <input name="hero_title" class="form-control" value="<?php echo e($school['hero_title'] ?? ''); ?>">
    </div>

    <div class="mb-3">
      <label class="form-label">Hero Subtitle</label>
      <input name="hero_subtitle" class="form-control" value="<?php echo e($school['hero_subtitle'] ?? ''); ?>">
    </div>

    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Hero CTA Text</label>
        <input name="hero_cta_text" class="form-control" value="<?php echo e($school['hero_cta_text'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Hero CTA URL</label>
        <input name="hero_cta_url" class="form-control" value="<?php echo e($school['hero_cta_url'] ?? ''); ?>">
      </div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary">Save Profile</button>
      
    </div>
  </form>

<?php
require_once __DIR__ . '/../footer.php';
