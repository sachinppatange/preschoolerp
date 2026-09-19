<?php
/**
 * School name, logo and homepage banner (public website).
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'School name & logo');
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('school_profile_save_image')) {
    /**
     * @param list<string> $errors
     */
    function school_profile_save_image(string $field, ?string $existing, array &$errors): ?string
    {
        if (empty($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $existing;
        }
        if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Could not upload that file. Try a smaller JPG or PNG.';
            return $existing;
        }
        $tmp = (string) $_FILES[$field]['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false) {
            $errors[] = 'That file is not a photo.';
            return $existing;
        }
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (!in_array((int) ($info[2] ?? 0), $allowed, true)) {
            $errors[] = 'Use a JPG, PNG, GIF or WEBP photo.';
            return $existing;
        }
        $dir = cms_upload_dir();
        if ($dir === false) {
            $errors[] = 'Photo folder is not writable. Ask support to fix uploads.';
            return $existing;
        }
        $orig = basename((string) ($_FILES[$field]['name'] ?? 'photo.jpg'));
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'photo.jpg';
        $destName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        $destPath = $dir . '/' . $destName;
        if (!move_uploaded_file($tmp, $destPath)) {
            $errors[] = 'Could not save the photo.';
            return $existing;
        }
        @chmod($destPath, 0644);
        return cms_public_upload_url($destName);
    }
}

$slugFromName = static function (string $name, string $existing): string {
    $s = strtolower(trim($existing));
    if ($s === '') {
        $s = strtolower($name);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
    $s = trim($s, '-');
    return $s !== '' ? $s : 'school';
};

$ctaChoiceFromUrl = static function (string $url): string {
    $u = strtolower(trim($url));
    if ($u === '' || str_contains($u, 'enquiry')) {
        return 'enquiry';
    }
    if (str_contains($u, 'about')) {
        return 'about';
    }
    if (str_contains($u, 'class')) {
        return 'classes';
    }
    return 'other';
};

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_profile') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $tagline = trim((string) ($_POST['tagline'] ?? ''));
        $heroTitle = trim((string) ($_POST['hero_title'] ?? ''));
        $heroSubtitle = trim((string) ($_POST['hero_subtitle'] ?? ''));
        $heroCtaText = trim((string) ($_POST['hero_cta_text'] ?? ''));
        $go = (string) ($_POST['cta_go'] ?? 'enquiry');
        $ctaUrl = '#enquiry';
        if ($go === 'about') {
            $ctaUrl = '#about';
        } elseif ($go === 'classes') {
            $ctaUrl = '#classes';
        } elseif ($go === 'other') {
            $ctaUrl = trim((string) ($_POST['cta_other'] ?? ''));
            if ($ctaUrl === '') {
                $ctaUrl = '#enquiry';
            }
        }
        $slug = $slugFromName($name, (string) ($school['slug'] ?? ''));

        if ($name === '') {
            $errors[] = 'Write the school name.';
        }

        $logoPath = (string) ($school['logo_path'] ?? '');
        $heroImage = (string) ($school['hero_image'] ?? '');
        if ($errors === []) {
            $logoPath = (string) (school_profile_save_image('logo', $logoPath !== '' ? $logoPath : null, $errors) ?? '');
            $heroImage = (string) (school_profile_save_image('hero_image', $heroImage !== '' ? $heroImage : null, $errors) ?? '');
        }

        if ($errors === []) {
            $sql = 'INSERT INTO schools (id, name, slug, tagline, logo_path, hero_image, hero_title, hero_subtitle, hero_cta_text, hero_cta_url, updated_at)
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
                      updated_at=NOW()';
            $ok = safe_db_run($sql, [
                ':name' => $name,
                ':slug' => $slug,
                ':tagline' => $tagline,
                ':logo_path' => $logoPath !== '' ? $logoPath : null,
                ':hero_image' => $heroImage !== '' ? $heroImage : null,
                ':hero_title' => $heroTitle,
                ':hero_subtitle' => $heroSubtitle,
                ':hero_cta_text' => $heroCtaText,
                ':hero_cta_url' => $ctaUrl,
            ]);
            if ($ok) {
                $success = 'Saved. Parents will see this on the website.';
                $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }
    }
}

$nameVal = (string) ($school['name'] ?? '');
$taglineVal = (string) ($school['tagline'] ?? '');
$heroTitleVal = (string) ($school['hero_title'] ?? '');
$heroSubVal = (string) ($school['hero_subtitle'] ?? '');
$ctaTextVal = (string) ($school['hero_cta_text'] ?? '');
$ctaUrlVal = (string) ($school['hero_cta_url'] ?? '#enquiry');
$ctaGo = $ctaChoiceFromUrl($ctaUrlVal);
$logoUrl = trim((string) ($school['logo_path'] ?? '')) !== '' && function_exists('resolve_image_url')
    ? resolve_image_url((string) $school['logo_path'])
    : '';
$heroUrl = trim((string) ($school['hero_image'] ?? '')) !== '' && function_exists('resolve_image_url')
    ? resolve_image_url((string) $school['hero_image'])
    : '';
$publicHome = function_exists('site_url') ? site_url('/') : '/';

require_once __DIR__ . '/../header.php';
?>
<style>
.sp-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.sp-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.sp-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.sp-logo { width:88px; height:88px; object-fit:contain; background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:8px; }
.sp-banner { width:100%; max-width:360px; height:180px; object-fit:cover; border-radius:14px; background:#e2e8f0; border:1px solid #dbe7fb; }
.sp-ph { width:100%; max-width:360px; height:180px; border-radius:14px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#64748b; }
.sp-preview { background:#143a7a; color:#fff; border-radius:16px; padding:18px; min-height:140px; }
.sp-preview .btn-like { display:inline-block; background:#f59e0b; color:#1e3a5f; font-weight:800; border-radius:999px; padding:.4rem 1rem; margin-top:10px; font-size:.9rem; }
.sp-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; z-index:2; }
.sp-opt { display:flex; flex-wrap:wrap; gap:8px; }
.sp-opt label { border:1px solid #dbe7fb; background:#f8fafc; border-radius:999px; padding:.35rem .85rem; cursor:pointer; font-weight:600; }
.sp-opt input { margin-right:.35rem; }
</style>

<div class="sp-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">School name &amp; logo</div>
    <div class="text-muted">This is the top of your public website — name, logo and the first big picture.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicHome); ?>" target="_blank" rel="noopener">See website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save_profile">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

  <div class="sp-card">
    <div class="sp-sec">School</div>
    <label class="form-label">School name</label>
    <input name="name" class="form-control mb-3" value="<?php echo e($nameVal); ?>" required placeholder="Pioneer Play School">
    <label class="form-label">Few words under the name</label>
    <input name="tagline" class="form-control" value="<?php echo e($taglineVal); ?>" maxlength="120" placeholder="Where little minds grow">
    <div class="form-text">Shows next to the logo at the top of the website.</div>
  </div>

  <div class="sp-card">
    <div class="sp-sec">Logo</div>
    <div class="d-flex flex-wrap gap-3 align-items-center">
      <?php if ($logoUrl !== ''): ?>
        <img class="sp-logo" src="<?php echo e($logoUrl); ?>" alt="">
      <?php else: ?>
        <div class="sp-logo d-flex align-items-center justify-content-center text-muted small">No logo</div>
      <?php endif; ?>
      <div class="flex-grow-1">
        <input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
        <div class="form-text">Square PNG with a clear school mark works best. Leave empty to keep the current logo.</div>
      </div>
    </div>
  </div>

  <div class="sp-card">
    <div class="sp-sec">Homepage banner</div>
    <p class="text-muted mb-3">The first big picture parents see when they open the website.</p>
    <div class="d-flex flex-wrap gap-3 mb-3">
      <?php if ($heroUrl !== ''): ?>
        <img class="sp-banner" src="<?php echo e($heroUrl); ?>" alt="">
      <?php else: ?>
        <div class="sp-ph">No banner photo yet</div>
      <?php endif; ?>
      <div class="flex-grow-1">
        <label class="form-label">Banner photo</label>
        <input type="file" name="hero_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
        <div class="form-text">Children playing or the school gate. Wide photo is best.</div>
      </div>
    </div>
    <label class="form-label">Big heading</label>
    <input name="hero_title" class="form-control mb-3" value="<?php echo e($heroTitleVal); ?>" placeholder="A happy start for your child">
    <label class="form-label">One line under it</label>
    <input name="hero_subtitle" class="form-control mb-3" value="<?php echo e($heroSubVal); ?>" placeholder="Safe, joyful learning every day">
    <label class="form-label">Button text</label>
    <input name="hero_cta_text" class="form-control mb-3" value="<?php echo e($ctaTextVal); ?>" placeholder="Admission Enquiry">
    <div class="form-label">Button opens</div>
    <div class="sp-opt mb-2">
      <label><input type="radio" name="cta_go" value="enquiry" <?php echo $ctaGo === 'enquiry' ? 'checked' : ''; ?>> Enquiry form</label>
      <label><input type="radio" name="cta_go" value="about" <?php echo $ctaGo === 'about' ? 'checked' : ''; ?>> About</label>
      <label><input type="radio" name="cta_go" value="classes" <?php echo $ctaGo === 'classes' ? 'checked' : ''; ?>> Classes</label>
      <label><input type="radio" name="cta_go" value="other" <?php echo $ctaGo === 'other' ? 'checked' : ''; ?>> Other</label>
    </div>
    <input name="cta_other" class="form-control" value="<?php echo $ctaGo === 'other' ? e($ctaUrlVal) : ''; ?>" placeholder="Only if Other — e.g. https://wa.me/91…">
  </div>

  <div class="sp-card">
    <div class="sp-sec">How the banner looks</div>
    <div class="sp-preview">
      <div style="font-size:1.35rem;font-weight:800"><?php echo e($heroTitleVal !== '' ? $heroTitleVal : ($nameVal !== '' ? $nameVal : 'School name')); ?></div>
      <div class="mt-1" style="opacity:.9"><?php echo e($heroSubVal !== '' ? $heroSubVal : ($taglineVal !== '' ? $taglineVal : 'Your one line will show here')); ?></div>
      <span class="btn-like"><?php echo e($ctaTextVal !== '' ? $ctaTextVal : 'Admission Enquiry'); ?></span>
    </div>
  </div>

  <div class="sp-bar">
    <button class="btn btn-success" type="submit">Save</button>
  </div>
</form>
<?php
require_once __DIR__ . '/../footer.php';
