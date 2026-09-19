<?php
/**
 * owner/popup_settings.php — welcome notice on the public website.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
require_once __DIR__ . '/../includes/cms/helpers.php';

$pageTitle = 'Website welcome notice';
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

$schoolId = 1;
$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => $schoolId]) ?: [];
$settings = cms_decode_json_field($school['settings'] ?? null);
$popup = is_array($settings['popup'] ?? null) ? $settings['popup'] : [];
$popup = array_merge([
    'enabled' => false,
    'image' => '',
    'title' => '',
    'text' => '',
    'show_once' => true,
], $popup);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_popup') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $enabled = !empty($_POST['enabled']);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 250);
        $text = mb_substr(trim((string) ($_POST['text'] ?? '')), 0, 2000);
        $showOnce = (string) ($_POST['show_once'] ?? '1') === '1';
        $removeImage = !empty($_POST['remove_image']);

        $image = trim((string) ($popup['image'] ?? ''));
        if ($removeImage && $image !== '') {
            $fs = cms_fs_path_from_url($image);
            if (is_file($fs) && str_contains($fs, '/assets/uploads/')) {
                @unlink($fs);
            }
            $image = '';
        }

        if (!empty($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Could not upload the photo. Try a smaller JPG or PNG.';
            } else {
                $tmp = (string) $_FILES['image']['tmp_name'];
                $info = @getimagesize($tmp);
                $okType = $info !== false && in_array((int) ($info[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
                if (!$okType) {
                    $errors[] = 'Use a JPG, PNG, GIF or WEBP photo.';
                } else {
                    $dir = cms_upload_dir();
                    if ($dir === false) {
                        $errors[] = 'Photo folder is not writable.';
                    } else {
                        $orig = basename((string) ($_FILES['image']['name'] ?? 'photo.jpg'));
                        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'photo.jpg';
                        $uniq = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
                        if (@move_uploaded_file($tmp, $dir . '/' . $uniq)) {
                            if ($image !== '') {
                                $fs = cms_fs_path_from_url($image);
                                if (is_file($fs) && str_contains($fs, '/assets/uploads/')) {
                                    @unlink($fs);
                                }
                            }
                            $image = cms_public_upload_url($uniq);
                        } else {
                            $errors[] = 'Could not save the photo.';
                        }
                    }
                }
            }
        }

        if ($enabled && $title === '' && $text === '' && $image === '') {
            $errors[] = 'Add a title, a short message or a photo — or turn the notice off.';
        }

        if ($errors === []) {
            $settings['popup'] = [
                'enabled' => $enabled,
                'image' => $image,
                'title' => $title,
                'text' => $text,
                'show_once' => $showOnce,
            ];
            $json = json_encode($settings, JSON_UNESCAPED_UNICODE);
            $ok = false;
            if ($school !== []) {
                $ok = (bool) safe_db_run(
                    'UPDATE schools SET settings = :s, updated_at = NOW() WHERE id = :id',
                    [':s' => $json, ':id' => $schoolId]
                );
            } else {
                $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
                $ok = (bool) safe_db_run(
                    'INSERT INTO schools (id, name, settings, created_at, updated_at) VALUES (1, :name, :s, NOW(), NOW())',
                    [':name' => $defaultName, ':s' => $json]
                );
            }
            if ($ok) {
                $success = $enabled ? 'Saved. Visitors will see this when they open the website.' : 'Saved. The notice is off.';
                $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => $schoolId]) ?: $school;
                $settings = cms_decode_json_field($school['settings'] ?? null);
                $popup = is_array($settings['popup'] ?? null) ? array_merge($popup, $settings['popup']) : $settings['popup'];
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }
    }
}

$imgUrl = trim((string) ($popup['image'] ?? ''));
if ($imgUrl !== '' && function_exists('resolve_image_url')) {
    $imgUrl = resolve_image_url($imgUrl);
}
$enabled = !empty($popup['enabled']);
$showOnce = !empty($popup['show_once']);
$publicHome = function_exists('site_url') ? site_url('/') : '/';

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.pp-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.pp-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.pp-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.pp-on { display:flex; align-items:center; gap:10px; background:#f0fdf4; border:1px solid #86efac; border-radius:14px; padding:12px 14px; }
.pp-off { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1px solid #dbe7fb; border-radius:14px; padding:12px 14px; }
.pp-photo { width:100%; max-width:280px; height:160px; object-fit:cover; border-radius:14px; background:#e2e8f0; border:1px solid #dbe7fb; }
.pp-mock { max-width:360px; background:#fff; border:1px solid #dbe7fb; border-radius:16px; overflow:hidden; box-shadow:0 8px 24px rgba(20,58,122,.1); }
.pp-mock img { width:100%; height:160px; object-fit:cover; display:block; background:#e2e8f0; }
.pp-mock .bd { padding:14px; }
.pp-opt { display:flex; flex-wrap:wrap; gap:8px; }
.pp-opt label { border:1px solid #dbe7fb; background:#f8fafc; border-radius:999px; padding:.35rem .85rem; cursor:pointer; font-weight:600; }
.pp-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; z-index:2; }
</style>

<div class="pp-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">Website welcome notice</div>
    <div class="text-muted">A photo and a few words when someone first opens the school website (admission, holiday, event).</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicHome); ?>" target="_blank" rel="noopener">See website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save_popup">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

  <div class="pp-card">
    <label class="<?php echo $enabled ? 'pp-on' : 'pp-off'; ?>">
      <input type="checkbox" name="enabled" value="1" class="form-check-input m-0" <?php echo $enabled ? 'checked' : ''; ?>>
      <span><strong>Show this notice on the website</strong><br><span class="small text-muted">Turn off after the event or admission drive is over.</span></span>
    </label>
  </div>

  <div class="pp-card">
    <div class="pp-sec">Photo</div>
    <div class="d-flex flex-wrap gap-3 align-items-start">
      <?php if ($imgUrl !== ''): ?>
        <img class="pp-photo" src="<?php echo e($imgUrl); ?>" alt="">
      <?php else: ?>
        <div class="pp-photo d-flex align-items-center justify-content-center text-muted">No photo</div>
      <?php endif; ?>
      <div class="flex-grow-1">
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
        <div class="form-text">A wide photo of children or a poster works well. Optional.</div>
        <?php if ($imgUrl !== ''): ?>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1" id="remove_image" name="remove_image">
            <label class="form-check-label" for="remove_image">Remove this photo</label>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="pp-card">
    <div class="pp-sec">Words</div>
    <label class="form-label">Heading</label>
    <input name="title" class="form-control mb-3" maxlength="250" value="<?php echo e((string) ($popup['title'] ?? '')); ?>" placeholder="Admissions open">
    <label class="form-label">Short message</label>
    <textarea name="text" class="form-control" rows="4" maxlength="2000" placeholder="Visit the school this week, or send an enquiry."><?php echo e((string) ($popup['text'] ?? '')); ?></textarea>
    <div class="form-text">Keep it to a few lines. Parents can close it with the ×.</div>
  </div>

  <div class="pp-card">
    <div class="pp-sec">How often</div>
    <div class="pp-opt">
      <label><input type="radio" name="show_once" value="1" <?php echo $showOnce ? 'checked' : ''; ?>> Once, until they close it</label>
      <label><input type="radio" name="show_once" value="0" <?php echo $showOnce ? '' : 'checked'; ?>> Every visit</label>
    </div>
    <div class="form-text mt-2">“Once” is gentler. Use every visit only for an urgent notice.</div>
  </div>

  <div class="pp-card">
    <div class="pp-sec">How it looks</div>
    <div class="pp-mock">
      <?php if ($imgUrl !== ''): ?><img src="<?php echo e($imgUrl); ?>" alt=""><?php endif; ?>
      <div class="bd">
        <div class="fw-bold"><?php echo e(trim((string) ($popup['title'] ?? '')) !== '' ? (string) $popup['title'] : 'Heading'); ?></div>
        <div class="small text-muted mt-1" style="white-space:pre-line"><?php echo e(trim((string) ($popup['text'] ?? '')) !== '' ? (string) $popup['text'] : 'Your short message will show here.'); ?></div>
      </div>
    </div>
  </div>

  <div class="pp-bar">
    <button class="btn btn-success" type="submit">Save</button>
  </div>
</form>
<?php
require_once __DIR__ . '/../includes/footer.php';
