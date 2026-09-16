<?php
/**
 * Shared feature: content_about
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'About Content');

function save_uploaded_image(string $field, array &$errors = null): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error code: ' . intval($_FILES[$field]['error']);
        return null;
    }

    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) {
        $errors[] = 'Uploaded file is not a valid image.';
        return null;
    }
    $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($info[2], $allowedTypes, true)) {
        $errors[] = 'Only JPG, PNG, GIF or WEBP images are allowed.';
        return null;
    }

    $dir = cms_upload_dir();
    if ($dir === false) {
        $errors[] = 'Upload directory not writable. Create /assets/uploads and make it writable by the webserver.';
        return null;
    }

    $orig = basename((string)($_FILES[$field]['name'] ?? 'upload'));
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    $uniq = time() . '_' . bin2hex(random_bytes(5));
    $dest = $uniq . '_' . $safe;
    $destPath = $dir . '/' . $dest;

    if (!move_uploaded_file($tmp, $destPath)) {
        $errors[] = 'Failed to move uploaded file.';
        return null;
    }

    // Return web path under app base
    return cms_public_upload_url($dest);
}

/* -------------------------
   Load current school row
   ------------------------- */
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];
$settings = cms_decode_json_field($school['settings'] ?? null);

/* Determine where we will store about image: column or settings */
$has_about_image_column = column_exists('schools', 'about_image');

/* Current about image path (either column or settings) */
$current_about_image = '';
if ($has_about_image_column) {
    $current_about_image = $school['about_image'] ?? '';
} else {
    $current_about_image = $settings['about_image'] ?? '';
}

/* Decode why_choose_us */
$why_items = cms_decode_json_field($school['why_choose_us'] ?? null);
if (empty($why_items)) {
    $why_items = [
        ['title' => '', 'description' => ''],
        ['title' => '', 'description' => ''],
    ];
}

/* -------------------------
   Handle POST save
   ------------------------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_about') {
    $short_about = trim((string)($_POST['short_about'] ?? ''));
    $long_about = trim((string)($_POST['long_about'] ?? ''));

    // Collect Why items
    $why_titles = $_POST['why_title'] ?? [];
    $why_descs = $_POST['why_desc'] ?? [];
    $new_why = [];
    if (is_array($why_titles)) {
        for ($i = 0; $i < count($why_titles); $i++) {
            $t = trim((string)($why_titles[$i] ?? ''));
            $d = trim((string)($why_descs[$i] ?? ''));
            if ($t === '' && $d === '') continue;
            $new_why[] = ['title' => $t, 'description' => $d];
        }
    }

    // About image removal?
    $remove_about = !empty($_POST['remove_about_image']) ? true : false;

    // Handle upload
    $uploaded_path = null;
    $upload_errors = [];
    $maybe_uploaded = save_uploaded_image('about_image', $upload_errors);
    if (!empty($upload_errors)) foreach ($upload_errors as $ue) $errors[] = $ue;
    if ($maybe_uploaded !== null) $uploaded_path = $maybe_uploaded;

    // Validation (example)
    if ($short_about === '') {
        $errors[] = 'Short about cannot be empty.';
    }

    if (empty($errors)) {
        $why_json = json_encode($new_why, JSON_UNESCAPED_UNICODE);

        // If school row exists, UPDATE; else INSERT with minimal defaults
        if (!empty($school)) {
            // Prepare params
            $params = [
                ':short_about' => $short_about,
                ':long_about' => $long_about,
                ':why' => $why_json,
            ];

            // If about_image column exists handle it
            if ($has_about_image_column) {
                // delete existing file if remove_about and file present
                if ($remove_about && !empty($school['about_image'])) {
                    $fs = cms_fs_path_from_url($school['about_image'] ?? '');
                    if (is_file($fs)) @unlink($fs);
                    $params[':about_image'] = null;
                } elseif ($uploaded_path !== null) {
                    // if uploaded new, optionally remove old file
                    if (!empty($school['about_image'])) {
                        $fs = cms_fs_path_from_url($school['about_image'] ?? '');
                        if (is_file($fs)) @unlink($fs);
                    }
                    $params[':about_image'] = $uploaded_path;
                } else {
                    // keep existing
                    $params[':about_image'] = $school['about_image'] ?? null;
                }

                $sql = "UPDATE schools SET short_about = :short_about, long_about = :long_about, why_choose_us = :why, about_image = :about_image, updated_at = NOW() WHERE id = 1";
            } else {
                // store in settings JSON
                $settings = cms_decode_json_field($school['settings'] ?? null);
                if ($remove_about) {
                    if (!empty($settings['about_image'])) {
                        $fs = cms_fs_path_from_url((string)$settings['about_image']);
                        if (is_file($fs)) @unlink($fs);
                    }
                    unset($settings['about_image']);
                } elseif ($uploaded_path !== null) {
                    if (!empty($settings['about_image'])) {
                        $fs = cms_fs_path_from_url((string)$settings['about_image']);
                        if (is_file($fs)) @unlink($fs);
                    }
                    $settings['about_image'] = $uploaded_path;
                }
                $params[':settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
                $sql = "UPDATE schools SET short_about = :short_about, long_about = :long_about, why_choose_us = :why, settings = :settings, updated_at = NOW() WHERE id = 1";
            }

            $ok = safe_db_run($sql, $params);
        } else {
            // insert new row with minimal required columns; include about image accordingly
            $default_name = 'Pioneer Play School';
            if ($has_about_image_column) {
                $sql = "INSERT INTO schools (id, name, short_about, long_about, why_choose_us, about_image, created_at, updated_at)
                        VALUES (1, :name, :short_about, :long_about, :why, :about_image, NOW(), NOW())";
                $params = [
                    ':name' => $default_name,
                    ':short_about' => $short_about,
                    ':long_about' => $long_about,
                    ':why' => $why_json,
                    ':about_image' => $uploaded_path ?? null,
                ];
            } else {
                $settings['about_image'] = $uploaded_path ?? ($settings['about_image'] ?? null);
                $sql = "INSERT INTO schools (id, name, short_about, long_about, why_choose_us, settings, created_at, updated_at)
                        VALUES (1, :name, :short_about, :long_about, :why, :settings, NOW(), NOW())";
                $params = [
                    ':name' => $default_name,
                    ':short_about' => $short_about,
                    ':long_about' => $long_about,
                    ':why' => $why_json,
                    ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                ];
            }
            $ok = safe_db_run($sql, $params);
        }

        if ($ok) {
            $success = 'About content saved.';
            $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: $school;
            $settings = cms_decode_json_field($school['settings'] ?? null);
            $current_about_image = $has_about_image_column ? ($school['about_image'] ?? '') : ($settings['about_image'] ?? '');
            $why_items = cms_decode_json_field($school['why_choose_us'] ?? null);
        } else {
            $errors[] = 'Database error while saving.';
        }
    }
}

/* Determine current about image for display (after possible updates) */
$settings = cms_decode_json_field($school['settings'] ?? null);
$current_about_image = $has_about_image_column ? ($school['about_image'] ?? '') : ($settings['about_image'] ?? '');
$pageTitle = (string)($cfg['page_title'] ?? 'About Content');
require_once __DIR__ . '/../header.php';
?>
<style>.why-item{border:1px solid #e9ecef;padding:12px;border-radius:8px;margin-bottom:10px;background:#fff}.why-remove{cursor:pointer;color:#c00}.about-image-preview{max-width:220px;max-height:160px;object-fit:cover;border-radius:8px;border:1px solid #ddd}</style>

<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card p-3" id="aboutForm">
    <input type="hidden" name="action" value="save_about">

    <div class="mb-3">
      <label class="form-label">Short About (one-line)</label>
      <input name="short_about" class="form-control" value="<?php echo e($school['short_about'] ?? ''); ?>" required>
      <div class="form-text">A short description that appears in meta/hero area (keep it brief).</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Long About (HTML allowed)</label>
      <textarea name="long_about" class="form-control" rows="8" placeholder="You can include basic HTML"><?php echo e($school['long_about'] ?? ''); ?></textarea>
      <div class="form-text">You can paste basic HTML (paragraphs, lists). It will be rendered on public page.</div>
    </div>

    <hr>

    <div class="mb-3">
      <label class="form-label">About Image (separate from Hero)</label>
      <div class="mb-2">
        <?php if (!empty($current_about_image)): ?>
          <img src="<?php echo e($current_about_image); ?>" alt="About Image" class="about-image-preview me-2">
        <?php else: ?>
          <div class="text-muted small mb-2">No about image set.</div>
        <?php endif; ?>
      </div>
      <input type="file" name="about_image" accept="image/*" class="form-control mb-2">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" value="1" id="remove_about_image" name="remove_about_image">
        <label class="form-check-label" for="remove_about_image">Remove current about image</label>
      </div>
      <div class="form-text">Recommended size ~800x500. Supported: JPG/PNG/GIF/WEBP.</div>
    </div>

    <hr>

    <div class="mb-3 d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Why Choose Us</h5>
      <button type="button" id="addWhy" class="btn btn-sm btn-outline-primary">Add Item</button>
    </div>

    <div id="whyList">
      <?php foreach ($why_items as $idx => $w): ?>
        <div class="why-item" data-index="<?php echo $idx; ?>">
          <div class="row g-2 align-items-start">
            <div class="col-md-5">
              <label class="form-label small">Title</label>
              <input name="why_title[]" class="form-control" value="<?php echo e($w['title'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small">Description</label>
              <input name="why_desc[]" class="form-control" value="<?php echo e($w['description'] ?? ''); ?>">
            </div>
            <div class="col-md-1 d-flex align-items-start">
              <button type="button" class="btn btn-link p-0 why-remove" title="Remove item" onclick="removeWhy(this)">✖</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-3">
      <button class="btn btn-primary">Save About</button>
      
    </div>
  </form>

  <!-- Preview block -->
  <?php if (!empty($school['long_about'])): ?>
    <div class="card mt-4 p-3">
      <h5 class="mb-3">Long About Preview</h5>
      <div><?php echo $school['long_about']; ?></div>
    </div>
  <?php endif; ?>

</div>

<script>
  // Add/remove logic for Why Choose Us items
  document.getElementById('addWhy').addEventListener('click', function () {
    const container = document.getElementById('whyList');
    const idx = container.children.length;
    const div = document.createElement('div');
    div.className = 'why-item';
    div.dataset.index = idx;
    div.innerHTML = `
      <div class="row g-2 align-items-start">
        <div class="col-md-5">
          <label class="form-label small">Title</label>
          <input name="why_title[]" class="form-control" value="">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Description</label>
          <input name="why_desc[]" class="form-control" value="">
        </div>
        <div class="col-md-1 d-flex align-items-start">
          <button type="button" class="btn btn-link p-0 why-remove" title="Remove item" onclick="removeWhy(this)">✖</button>
        </div>
      </div>
    `;
    container.appendChild(div);
    div.scrollIntoView({behavior:'smooth', block:'center'});
  });

  function removeWhy(btn) {
    const item = btn.closest('.why-item');
    if (!item) return;
    const container = document.getElementById('whyList');
    if (container.children.length <= 1) {
      const inputs = item.querySelectorAll('input');
      inputs.forEach(i => i.value = '');
      return;
    }
    item.remove();
  }
</script>


<?php
require_once __DIR__ . '/../footer.php';
