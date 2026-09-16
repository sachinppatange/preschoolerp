<?php
/**
 * Shared feature: content_photos
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'Gallery');

function is_valid_image(string $tmp): bool {
    $info = @getimagesize($tmp);
    if ($info === false) return false;
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    return in_array($info[2], $allowed, true);
}

function web_path_for_upload(string $filename): string {
    return cms_public_upload_url($filename);
}

/* -------------------------
   Load current gallery from schools.gallery
   ------------------------- */
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];
$gallery = [];
if (!empty($school['gallery'])) {
    $tmp = json_decode((string)$school['gallery'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $gallery = $tmp;
}

/* -------------------------
   Process actions: upload, delete, reorder
   ------------------------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload action
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        if (empty($_FILES['photos'])) {
            $errors[] = 'No files uploaded.';
        } else {
            $dir = cms_upload_dir();
            if ($dir === false) {
                $errors[] = 'Upload directory not writable. Create assets/uploads and give webserver write permission.';
            } else {
                $added = 0;
                $files = $_FILES['photos'];
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $tmp = $files['tmp_name'][$i];
                    if (!is_valid_image($tmp)) { $errors[] = $files['name'][$i] . ' is not a valid image.'; continue; }
                    $orig = basename($files['name'][$i]);
                    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                    $uniq = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
                    $dest = $dir . '/' . $uniq;
                    if (@move_uploaded_file($tmp, $dest)) {
                        $gallery[] = web_path_for_upload($uniq);
                        $added++;
                    } else {
                        $errors[] = 'Failed to save ' . $orig;
                    }
                }

                if ($added > 0) {
                    $newGalleryJson = json_encode(array_values($gallery));
                    // Use UPDATE if row exists, otherwise INSERT with minimal required columns
                    if (!empty($school)) {
                        $ok = safe_db_run("UPDATE schools SET gallery = :g, updated_at = NOW() WHERE id = 1", [':g' => $newGalleryJson]);
                    } else {
                        $default_name = 'Pioneer Play School';
                        $ok = safe_db_run("INSERT INTO schools (id, name, gallery, created_at, updated_at) VALUES (1, :name, :g, NOW(), NOW())", [':name' => $default_name, ':g' => $newGalleryJson]);
                    }

                    if ($ok) {
                        $success = "Uploaded $added image(s).";
                    } else {
                        $errors[] = 'DB error saving gallery.';
                    }
                } elseif (empty($errors)) {
                    $errors[] = 'No files were uploaded.';
                }
            }
        }
    }

    // Delete image
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && !empty($_POST['img'])) {
        $img = (string)$_POST['img'];
        $new = [];
        foreach ($gallery as $g) {
            if ($g === $img) continue;
            $new[] = $g;
        }
        // delete file from disk if inside uploads
        $appRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        // derive filesystem path
        $fs = null;
        if (strpos($img, site_url('')) === 0) {
            $relative = substr($img, strlen(site_url('')));
            $fs = $appRoot . '/' . ltrim($relative, '/');
        } elseif (strpos($img, '/') === 0) {
            $fs = $_SERVER['DOCUMENT_ROOT'] . $img;
        }
        if ($fs && is_file($fs)) @unlink($fs);

        $newGalleryJson = json_encode(array_values($new));
        if (!empty($school)) {
            $ok = safe_db_run("UPDATE schools SET gallery = :g, updated_at = NOW() WHERE id = 1", [':g' => $newGalleryJson]);
        } else {
            $default_name = 'Pioneer Play School';
            $ok = safe_db_run("INSERT INTO schools (id, name, gallery, created_at, updated_at) VALUES (1, :name, :g, NOW(), NOW())", [':name' => $default_name, ':g' => $newGalleryJson]);
        }
        if ($ok) {
            $gallery = $new;
            $success = 'Image removed.';
        } else {
            $errors[] = 'DB error removing image.';
        }
    }

    // Reorder images - expects order[] values (image paths)
    if (isset($_POST['action']) && $_POST['action'] === 'reorder' && !empty($_POST['order']) && is_array($_POST['order'])) {
        $order = array_values(array_filter($_POST['order'], 'is_string'));
        $newOrder = [];
        foreach ($order as $o) {
            if (in_array($o, $gallery, true)) $newOrder[] = $o;
        }
        foreach ($gallery as $g) if (!in_array($g, $newOrder, true)) $newOrder[] = $g;
        $newGalleryJson = json_encode(array_values($newOrder));
        if (!empty($school)) {
            $ok = safe_db_run("UPDATE schools SET gallery = :g, updated_at = NOW() WHERE id = 1", [':g' => $newGalleryJson]);
        } else {
            $default_name = 'Pioneer Play School';
            $ok = safe_db_run("INSERT INTO schools (id, name, gallery, created_at, updated_at) VALUES (1, :name, :g, NOW(), NOW())", [':name' => $default_name, ':g' => $newGalleryJson]);
        }
        if ($ok) {
            $gallery = $newOrder;
            $success = 'Order updated.';
        } else {
            $errors[] = 'DB error updating order.';
        }
    }
}

// Re-fetch gallery from DB (in case changed)
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: $school;
if (!empty($school['gallery'])) {
    $tmp = json_decode((string)$school['gallery'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $gallery = $tmp;
}


$pageTitle = (string)($cfg['page_title'] ?? 'Gallery');
require_once __DIR__ . '/../header.php';
?>
<style>.thumb{height:140px;object-fit:cover;width:100%;border-radius:8px;display:block}.card-photo{position:relative}.photo-actions{position:absolute;top:8px;right:8px;display:flex;gap:6px}.drag-handle{cursor:grab}.placeholder{border:2px dashed #e2e8f0;padding:30px;text-align:center;color:#94a3b8;border-radius:8px}</style>

<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div><?php endif; ?>

  <div class="card p-3 mb-4">
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="action" value="upload">
      <div class="mb-3">
        <label class="form-label">Upload Photos (multiple)</label>
        <input type="file" name="photos[]" accept="image/*" multiple class="form-control">
      </div>
      <div><button class="btn btn-primary">Upload</button></div>
    </form>
  </div>

  <div class="mb-3">
    <button id="saveOrderBtn" class="btn btn-sm btn-success me-2">Save Order</button>
    <button id="refreshBtn" class="btn btn-sm btn-outline-secondary">Refresh</button>
  </div>

  <?php if (empty($gallery)): ?>
    <div class="placeholder">No gallery images yet. Upload images above to create gallery.</div>
  <?php else: ?>
    <div id="galleryGrid" class="row g-3">
      <?php foreach ($gallery as $idx => $img): ?>
        <div class="col-6 col-md-3" data-src="<?php echo e($img); ?>">
          <div class="card card-photo">
            <div class="photo-actions">
              <button class="btn btn-sm btn-light drag-handle" title="Drag to reorder">≡</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this image?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="img" value="<?php echo e($img); ?>">
                <button class="btn btn-sm btn-danger" type="submit" title="Delete">🗑</button>
              </form>
            </div>
            <img src="<?php echo e(cms_resolve_image_url($img)); ?>" alt="photo" class="thumb">
            <div class="card-body p-2 small text-muted text-truncate"><?php echo e(basename($img)); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script>
  const grid = document.getElementById('galleryGrid');
  let dragEl = null;

  if (grid) {
    grid.querySelectorAll('[data-src]').forEach(card => {
      card.draggable = true;
      card.addEventListener('dragstart', (e) => {
        dragEl = card; card.style.opacity = '0.5'; e.dataTransfer.effectAllowed = 'move';
      });
      card.addEventListener('dragend', () => { if (dragEl) dragEl.style.opacity='1'; dragEl = null; });
      card.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
      card.addEventListener('drop', (e) => {
        e.preventDefault(); if (!dragEl || dragEl === card) return;
        const rect = card.getBoundingClientRect();
        const offset = e.clientY - rect.top;
        if (offset > rect.height / 2) card.parentNode.insertBefore(dragEl, card.nextSibling);
        else card.parentNode.insertBefore(dragEl, card);
      });
    });
  }

  document.getElementById('saveOrderBtn').addEventListener('click', function () {
    const orderInputs = Array.from(document.querySelectorAll('[data-src]')).map(n => n.getAttribute('data-src'));
    const form = document.createElement('form'); form.method = 'post';
    orderInputs.forEach(v => { const inp = document.createElement('input'); inp.type='hidden'; inp.name='order[]'; inp.value = v; form.appendChild(inp); });
    const action = document.createElement('input'); action.type='hidden'; action.name='action'; action.value='reorder'; form.appendChild(action);
    document.body.appendChild(form); form.submit();
  });

  document.getElementById('refreshBtn').addEventListener('click', function () { location.reload(); });
</script>


<?php
require_once __DIR__ . '/../footer.php';
