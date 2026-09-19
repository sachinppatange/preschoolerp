<?php
/**
 * Website About: short line, story, photo, why parents choose us.
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'About our school');
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('content_about_save_image')) {
    /**
     * @param list<string>|null $errors
     */
    function content_about_save_image(string $field, ?array &$errors = null): ?string
    {
        if (empty($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Could not upload the photo. Try a smaller JPG or PNG.';
            return null;
        }
        $tmp = (string) $_FILES[$field]['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false) {
            $errors[] = 'That file is not a photo.';
            return null;
        }
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array((int) ($info[2] ?? 0), $allowed, true)) {
            $errors[] = 'Use a JPG, PNG, GIF or WEBP photo.';
            return null;
        }
        $dir = cms_upload_dir();
        if ($dir === false) {
            $errors[] = 'Photo folder is not writable. Ask support to fix uploads.';
            return null;
        }
        $orig = basename((string) ($_FILES[$field]['name'] ?? 'photo.jpg'));
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'photo.jpg';
        $dest = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        if (!move_uploaded_file($tmp, $dir . '/' . $dest)) {
            $errors[] = 'Could not save the photo.';
            return null;
        }
        return cms_public_upload_url($dest);
    }
}

$aboutToPlain = static function (string $html): string {
    $t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
    $t = preg_replace('/<\/\s*(p|div|h[1-6]|li)\s*>/i', "\n\n", $t) ?? $t;
    $t = strip_tags($t);
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
    $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
    return trim($t);
};

$aboutToHtml = static function (string $plain): string {
    $plain = trim(str_replace(["\r\n", "\r"], "\n", $plain));
    if ($plain === '') {
        return '';
    }
    $blocks = preg_split("/\n\s*\n/", $plain) ?: [$plain];
    $out = [];
    foreach ($blocks as $b) {
        $b = trim($b);
        if ($b === '') {
            continue;
        }
        $out[] = '<p>' . nl2br(htmlspecialchars($b, ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }
    return implode("\n", $out);
};

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$settings = cms_decode_json_field($school['settings'] ?? null);
$hasAboutImageCol = function_exists('column_exists') && column_exists('schools', 'about_image');

$currentImage = $hasAboutImageCol
    ? (string) ($school['about_image'] ?? '')
    : (string) ($settings['about_image'] ?? '');

$whyItems = cms_decode_json_field($school['why_choose_us'] ?? null);
if ($whyItems === []) {
    $whyItems = [
        ['title' => '', 'description' => ''],
        ['title' => '', 'description' => ''],
        ['title' => '', 'description' => ''],
    ];
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_about') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $shortAbout = trim((string) ($_POST['short_about'] ?? ''));
        $longPlain = trim((string) ($_POST['long_about'] ?? ''));
        $longAbout = $aboutToHtml($longPlain);

        $whyTitles = $_POST['why_title'] ?? [];
        $whyDescs = $_POST['why_desc'] ?? [];
        $newWhy = [];
        if (is_array($whyTitles)) {
            foreach ($whyTitles as $i => $t) {
                $title = trim((string) $t);
                $desc = trim((string) ($whyDescs[$i] ?? ''));
                if ($title === '' && $desc === '') {
                    continue;
                }
                $newWhy[] = ['title' => $title, 'description' => $desc];
            }
        }

        $removeAbout = !empty($_POST['remove_about_image']);
        $uploadErrors = [];
        $uploadedPath = content_about_save_image('about_image', $uploadErrors);
        foreach ($uploadErrors as $ue) {
            $errors[] = $ue;
        }

        if ($shortAbout === '') {
            $errors[] = 'Write one short line about the school.';
        }

        if ($errors === []) {
            $whyJson = json_encode($newWhy, JSON_UNESCAPED_UNICODE);
            $ok = false;
            if ($school !== []) {
                $params = [
                    ':short_about' => $shortAbout,
                    ':long_about' => $longAbout,
                    ':why' => $whyJson,
                ];
                if ($hasAboutImageCol) {
                    if ($removeAbout && trim((string) ($school['about_image'] ?? '')) !== '') {
                        $fs = cms_fs_path_from_url((string) $school['about_image']);
                        if (is_file($fs)) {
                            @unlink($fs);
                        }
                        $params[':about_image'] = null;
                    } elseif ($uploadedPath !== null) {
                        if (trim((string) ($school['about_image'] ?? '')) !== '') {
                            $fs = cms_fs_path_from_url((string) $school['about_image']);
                            if (is_file($fs)) {
                                @unlink($fs);
                            }
                        }
                        $params[':about_image'] = $uploadedPath;
                    } else {
                        $params[':about_image'] = $school['about_image'] ?? null;
                    }
                    $ok = (bool) safe_db_run(
                        'UPDATE schools SET short_about = :short_about, long_about = :long_about, why_choose_us = :why, about_image = :about_image, updated_at = NOW() WHERE id = 1',
                        $params
                    );
                } else {
                    $settings = cms_decode_json_field($school['settings'] ?? null);
                    if ($removeAbout) {
                        if (!empty($settings['about_image'])) {
                            $fs = cms_fs_path_from_url((string) $settings['about_image']);
                            if (is_file($fs)) {
                                @unlink($fs);
                            }
                        }
                        unset($settings['about_image']);
                    } elseif ($uploadedPath !== null) {
                        if (!empty($settings['about_image'])) {
                            $fs = cms_fs_path_from_url((string) $settings['about_image']);
                            if (is_file($fs)) {
                                @unlink($fs);
                            }
                        }
                        $settings['about_image'] = $uploadedPath;
                    }
                    $params[':settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
                    $ok = (bool) safe_db_run(
                        'UPDATE schools SET short_about = :short_about, long_about = :long_about, why_choose_us = :why, settings = :settings, updated_at = NOW() WHERE id = 1',
                        $params
                    );
                }
            } else {
                $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
                if ($hasAboutImageCol) {
                    $ok = (bool) safe_db_run(
                        'INSERT INTO schools (id, name, short_about, long_about, why_choose_us, about_image, created_at, updated_at)
                         VALUES (1, :name, :short_about, :long_about, :why, :about_image, NOW(), NOW())',
                        [
                            ':name' => $defaultName,
                            ':short_about' => $shortAbout,
                            ':long_about' => $longAbout,
                            ':why' => $whyJson,
                            ':about_image' => $uploadedPath,
                        ]
                    );
                } else {
                    if ($uploadedPath !== null) {
                        $settings['about_image'] = $uploadedPath;
                    }
                    $ok = (bool) safe_db_run(
                        'INSERT INTO schools (id, name, short_about, long_about, why_choose_us, settings, created_at, updated_at)
                         VALUES (1, :name, :short_about, :long_about, :why, :settings, NOW(), NOW())',
                        [
                            ':name' => $defaultName,
                            ':short_about' => $shortAbout,
                            ':long_about' => $longAbout,
                            ':why' => $whyJson,
                            ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                        ]
                    );
                }
            }

            if ($ok) {
                $success = 'Saved. Parents will see this on the website.';
                $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
                $settings = cms_decode_json_field($school['settings'] ?? null);
                $currentImage = $hasAboutImageCol
                    ? (string) ($school['about_image'] ?? '')
                    : (string) ($settings['about_image'] ?? '');
                $whyItems = cms_decode_json_field($school['why_choose_us'] ?? null);
                if ($whyItems === []) {
                    $whyItems = [
                        ['title' => '', 'description' => ''],
                        ['title' => '', 'description' => ''],
                    ];
                }
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }
    }
}

$shortVal = (string) ($school['short_about'] ?? '');
$longVal = $aboutToPlain((string) ($school['long_about'] ?? ''));
$schoolName = trim((string) ($school['name'] ?? ''));
if ($schoolName === '') {
    $schoolName = defined('APP_NAME') ? (string) APP_NAME : 'School';
}
$imgUrl = '';
if (trim($currentImage) !== '') {
    $imgUrl = function_exists('cms_resolve_image_url')
        ? cms_resolve_image_url($currentImage)
        : (function_exists('resolve_image_url') ? resolve_image_url($currentImage) : $currentImage);
}
$publicAbout = function_exists('site_url') ? site_url('/#about') : '/#about';

require_once __DIR__ . '/../header.php';
?>
<style>
.ab-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.ab-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.ab-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.ab-photo { width:100%; max-width:280px; height:170px; object-fit:cover; border-radius:14px; background:#e2e8f0; border:1px solid #dbe7fb; }
.ab-why { border:1px dashed #dbe7fb; border-radius:14px; padding:12px; margin-bottom:8px; background:#f8fafc; }
.ab-preview { background:#f8fafc; border-radius:14px; padding:14px; }
.ab-preview .why-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; margin-top:10px; }
.ab-preview .why { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px; }
.ab-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; margin:12px -4px 0; z-index:2; }
</style>

<div class="ab-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">About our school</div>
    <div class="text-muted">Parents read this on the public website. Write in simple words — no codes.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicAbout); ?>" target="_blank" rel="noopener">See on website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save_about">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

  <div class="ab-card">
    <div class="ab-sec">Photo</div>
    <div class="d-flex flex-wrap gap-3 align-items-start">
      <?php if ($imgUrl !== ''): ?>
        <img class="ab-photo" src="<?php echo e($imgUrl); ?>" alt="">
      <?php else: ?>
        <div class="ab-photo d-flex align-items-center justify-content-center text-muted">No photo yet</div>
      <?php endif; ?>
      <div class="flex-grow-1">
        <label class="form-label">School photo for the About section</label>
        <input type="file" name="about_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
        <div class="form-text">A classroom or campus photo works well. JPG or PNG.</div>
        <?php if ($imgUrl !== ''): ?>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1" id="remove_about_image" name="remove_about_image">
            <label class="form-check-label" for="remove_about_image">Remove this photo</label>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="ab-card">
    <div class="ab-sec">Words</div>
    <label class="form-label">One short line</label>
    <input name="short_about" class="form-control mb-3" maxlength="180" value="<?php echo e($shortVal); ?>" placeholder="Safe, happy preschool in our neighbourhood" required>
    <label class="form-label">A little more for parents</label>
    <textarea name="long_about" class="form-control" rows="6" placeholder="Who you are, how children spend the day, what parents can trust."><?php echo e($longVal); ?></textarea>
    <div class="form-text">Leave a blank line between paragraphs. This shows next to the photo.</div>
  </div>

  <div class="ab-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="ab-sec mb-0">Why parents choose us</div>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addWhy">Add one more</button>
    </div>
    <div class="form-text mb-2">Short reasons — Safe campus, Play &amp; learn, Caring teachers…</div>
    <div id="whyList">
      <?php foreach ($whyItems as $w): ?>
        <div class="ab-why">
          <div class="row g-2">
            <div class="col-md-4">
              <input name="why_title[]" class="form-control" placeholder="Title" value="<?php echo e((string) ($w['title'] ?? '')); ?>">
            </div>
            <div class="col-md-7">
              <input name="why_desc[]" class="form-control" placeholder="One short line" value="<?php echo e((string) ($w['description'] ?? '')); ?>">
            </div>
            <div class="col-md-1">
              <button type="button" class="btn btn-outline-secondary w-100" onclick="removeWhy(this)">×</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ab-card">
    <div class="ab-sec">How it looks</div>
    <div class="ab-preview">
      <div class="fw-bold mb-1"><?php echo e($schoolName); ?></div>
      <?php if ($shortVal !== ''): ?><div class="text-muted mb-2"><?php echo e($shortVal); ?></div><?php endif; ?>
      <?php if ($longVal !== ''): ?><div style="white-space:pre-line"><?php echo e($longVal); ?></div><?php else: ?><div class="text-muted">Your longer text will show here after you save.</div><?php endif; ?>
      <?php
        $shownWhy = array_values(array_filter($whyItems, static function ($w): bool {
            return trim((string) ($w['title'] ?? '') . (string) ($w['description'] ?? '')) !== '';
        }));
      ?>
      <?php if ($shownWhy !== []): ?>
        <div class="why-grid">
          <?php foreach ($shownWhy as $w): ?>
            <div class="why">
              <div class="fw-bold"><?php echo e((string) ($w['title'] ?? '')); ?></div>
              <div class="small text-muted"><?php echo e((string) ($w['description'] ?? '')); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="ab-bar">
    <button class="btn btn-success" type="submit">Save About</button>
  </div>
</form>

<script>
document.getElementById('addWhy').addEventListener('click', function () {
  var wrap = document.getElementById('whyList');
  var div = document.createElement('div');
  div.className = 'ab-why';
  div.innerHTML = '<div class="row g-2"><div class="col-md-4"><input name="why_title[]" class="form-control" placeholder="Title"></div><div class="col-md-7"><input name="why_desc[]" class="form-control" placeholder="One short line"></div><div class="col-md-1"><button type="button" class="btn btn-outline-secondary w-100" onclick="removeWhy(this)">×</button></div></div>';
  wrap.appendChild(div);
  div.querySelector('input').focus();
});
function removeWhy(btn) {
  var item = btn.closest('.ab-why');
  var wrap = document.getElementById('whyList');
  if (!item || !wrap) return;
  if (wrap.children.length <= 1) {
    item.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    return;
  }
  item.remove();
}
</script>
<?php
require_once __DIR__ . '/../footer.php';
