<?php
/**
 * Public website gallery photos.
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'Website photos');
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('content_photos_is_image')) {
    function content_photos_is_image(string $tmp): bool
    {
        $info = @getimagesize($tmp);
        if ($info === false) {
            return false;
        }
        return in_array((int) ($info[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    }
}

if (!function_exists('content_photos_path')) {
    function content_photos_path(mixed $item): string
    {
        if (is_string($item)) {
            return trim($item);
        }
        if (is_array($item)) {
            return trim((string) ($item['url'] ?? $item['path'] ?? $item['src'] ?? ''));
        }
        return '';
    }
}

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$gallery = [];
foreach (cms_decode_json_field($school['gallery'] ?? null) as $g) {
    $p = content_photos_path($g);
    if ($p !== '') {
        $gallery[] = $p;
    }
}

$saveGallery = static function (array $items) use (&$school): bool {
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
    if ($school !== []) {
        $ok = (bool) safe_db_run(
            'UPDATE schools SET gallery = :g, updated_at = NOW() WHERE id = 1',
            [':g' => $json]
        );
    } else {
        $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
        $ok = (bool) safe_db_run(
            'INSERT INTO schools (id, name, gallery, created_at, updated_at) VALUES (1, :name, :g, NOW(), NOW())',
            [':name' => $defaultName, ':g' => $json]
        );
    }
    if ($ok) {
        $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
    }
    return $ok;
};

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'upload') {
            $files = $_FILES['photos'] ?? null;
            if (!is_array($files) || empty($files['name'])) {
                $errors[] = 'Choose one or more photos first.';
            } else {
                $dir = cms_upload_dir();
                if ($dir === false) {
                    $errors[] = 'Photo folder is not writable. Ask support to fix uploads.';
                } else {
                    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
                    $tmps = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
                    $errs = is_array($files['error']) ? $files['error'] : [$files['error']];
                    $added = 0;
                    $n = count($names);
                    for ($i = 0; $i < $n; $i++) {
                        if ((int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        $tmp = (string) ($tmps[$i] ?? '');
                        $orig = basename((string) ($names[$i] ?? 'photo.jpg'));
                        if ($tmp === '' || !content_photos_is_image($tmp)) {
                            $errors[] = $orig . ' is not a photo.';
                            continue;
                        }
                        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'photo.jpg';
                        $uniq = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
                        if (@move_uploaded_file($tmp, $dir . '/' . $uniq)) {
                            $gallery[] = cms_public_upload_url($uniq);
                            $added++;
                        } else {
                            $errors[] = 'Could not save ' . $orig . '.';
                        }
                    }
                    if ($added > 0) {
                        if ($saveGallery($gallery)) {
                            $success = $added === 1 ? 'Photo added.' : ($added . ' photos added.');
                        } else {
                            $errors[] = 'Could not save the gallery.';
                        }
                    } elseif ($errors === []) {
                        $errors[] = 'No photos were added.';
                    }
                }
            }
        }

        if ($action === 'delete') {
            $img = (string) ($_POST['img'] ?? '');
            $new = [];
            foreach ($gallery as $g) {
                if ($g !== $img) {
                    $new[] = $g;
                }
            }
            if ($img !== '') {
                $fs = cms_fs_path_from_url($img);
                if (is_file($fs)) {
                    @unlink($fs);
                }
            }
            if ($saveGallery($new)) {
                $gallery = $new;
                $success = 'Photo removed.';
            } else {
                $errors[] = 'Could not remove the photo.';
            }
        }

        if ($action === 'move') {
            $img = (string) ($_POST['img'] ?? '');
            $dirMove = (string) ($_POST['dir'] ?? '');
            $idx = array_search($img, $gallery, true);
            if ($idx !== false) {
                $swap = $dirMove === 'up' ? $idx - 1 : $idx + 1;
                if (isset($gallery[$swap])) {
                    $tmp = $gallery[$idx];
                    $gallery[$idx] = $gallery[$swap];
                    $gallery[$swap] = $tmp;
                    $gallery = array_values($gallery);
                    if ($saveGallery($gallery)) {
                        $success = 'Order updated.';
                    } else {
                        $errors[] = 'Could not change the order.';
                    }
                }
            }
        }
    }
}

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
$gallery = [];
foreach (cms_decode_json_field($school['gallery'] ?? null) as $g) {
    $p = content_photos_path($g);
    if ($p !== '') {
        $gallery[] = $p;
    }
}

$publicGal = function_exists('site_url') ? site_url('/#gallery') : '/#gallery';
$count = count($gallery);

require_once __DIR__ . '/../header.php';
?>
<style>
.gp-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.gp-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.gp-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.gp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; }
.gp-item { background:#f8fafc; border:1px solid #dbe7fb; border-radius:14px; overflow:hidden; }
.gp-item img { width:100%; height:140px; object-fit:cover; display:block; background:#e2e8f0; }
.gp-item .acts { display:flex; gap:6px; padding:8px; }
.gp-item .acts form { margin:0; flex:1; }
.gp-item .acts .btn { width:100%; }
.gp-empty { border:2px dashed #dbe7fb; border-radius:16px; padding:28px 16px; text-align:center; color:#64748b; background:#f8fafc; }
</style>

<div class="gp-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">Website photos</div>
    <div class="text-muted">Happy school moments for the public gallery. Use photos you have permission to show.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicGal); ?>" target="_blank" rel="noopener">See on website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<div class="gp-card">
  <div class="gp-sec">Add photos</div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="form-control mb-2">
    <button class="btn btn-success" type="submit">Add photos</button>
    <div class="form-text mt-2">You can select many photos at once. JPG or PNG.</div>
  </form>
</div>

<div class="gp-card">
  <div class="gp-sec"><?php echo $count === 0 ? 'Gallery' : ($count . ' photo' . ($count === 1 ? '' : 's')); ?></div>
  <?php if ($gallery === []): ?>
    <div class="gp-empty">No photos yet. Add a classroom or festival photo above.</div>
  <?php else: ?>
    <div class="form-text mb-2">First photo shows first on the website. Use ← → to change order.</div>
    <div class="gp-grid">
      <?php foreach ($gallery as $i => $img):
          $src = function_exists('cms_resolve_image_url') ? cms_resolve_image_url($img) : $img;
          ?>
        <div class="gp-item">
          <img src="<?php echo e($src); ?>" alt="">
          <div class="acts">
            <?php if ($i > 0): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="dir" value="up">
                <input type="hidden" name="img" value="<?php echo e($img); ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Earlier">←</button>
              </form>
            <?php endif; ?>
            <?php if ($i < $count - 1): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="dir" value="down">
                <input type="hidden" name="img" value="<?php echo e($img); ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit" title="Later">→</button>
              </form>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('Remove this photo from the website?');">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="img" value="<?php echo e($img); ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/../footer.php';
