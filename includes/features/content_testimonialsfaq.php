<?php
/**
 * Parent reviews and FAQs for the public website.
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'Parents’ words & FAQs');
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('content_tf_photo')) {
    /**
     * @param list<string> $errors
     */
    function content_tf_photo(string $field, array &$errors): ?string
    {
        if (empty($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Could not upload the photo.';
            return null;
        }
        $tmp = (string) $_FILES[$field]['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false || !in_array((int) ($info[2] ?? 0), [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            $errors[] = 'Use a JPG or PNG photo.';
            return null;
        }
        $dir = cms_upload_dir();
        if ($dir === false) {
            $errors[] = 'Photo folder is not writable.';
            return null;
        }
        $orig = basename((string) ($_FILES[$field]['name'] ?? 'photo.jpg'));
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig) ?: 'photo.jpg';
        $uniq = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        if (!move_uploaded_file($tmp, $dir . '/' . $uniq)) {
            $errors[] = 'Could not save the photo.';
            return null;
        }
        return cms_public_upload_url($uniq);
    }
}

$normT = static function (mixed $item): array {
    if (!is_array($item)) {
        return ['name' => '', 'role' => '', 'quote' => '', 'photo' => ''];
    }
    return [
        'name' => trim((string) ($item['name'] ?? '')),
        'role' => trim((string) ($item['role'] ?? '')),
        'quote' => trim((string) ($item['quote'] ?? $item['text'] ?? '')),
        'photo' => trim((string) ($item['photo'] ?? $item['image'] ?? '')),
    ];
};

$normF = static function (mixed $item): array {
    if (!is_array($item)) {
        return ['q' => '', 'a' => ''];
    }
    return [
        'q' => trim((string) ($item['q'] ?? $item['question'] ?? '')),
        'a' => trim((string) ($item['a'] ?? $item['answer'] ?? '')),
    ];
};

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$testimonials = [];
foreach (cms_decode_json_field($school['testimonials'] ?? null) as $t) {
    $row = $normT($t);
    if ($row['name'] !== '' || $row['quote'] !== '') {
        $testimonials[] = $row;
    }
}
$faqs = [];
foreach (cms_decode_json_field($school['faqs'] ?? null) as $f) {
    $row = $normF($f);
    if ($row['q'] !== '' || $row['a'] !== '') {
        $faqs[] = $row;
    }
}

$persist = static function (string $col, array $items) use (&$school): bool {
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
    $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
    if ($school !== []) {
        $ok = (bool) safe_db_run(
            'UPDATE schools SET `' . $col . '` = :v, updated_at = NOW() WHERE id = 1',
            [':v' => $json]
        );
    } else {
        $ok = (bool) safe_db_run(
            'INSERT INTO schools (id, name, `' . $col . '`, created_at, updated_at) VALUES (1, :name, :v, NOW(), NOW())',
            [':name' => $defaultName, ':v' => $json]
        );
    }
    if ($ok) {
        $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
    }
    return $ok;
};

$unlinkPhoto = static function (string $path): void {
    if ($path === '') {
        return;
    }
    $fs = cms_fs_path_from_url($path);
    if (is_file($fs)) {
        @unlink($fs);
    }
};

$errors = [];
$success = '';
$editT = (int) ($_GET['edit_t'] ?? -1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_testimonial') {
            $idx = ($_POST['idx'] ?? '') === '' ? null : (int) $_POST['idx'];
            $name = trim((string) ($_POST['name'] ?? ''));
            $role = trim((string) ($_POST['role'] ?? ''));
            $quote = trim((string) ($_POST['quote'] ?? ''));
            if ($role === '') {
                $role = 'Parent';
            }
            if ($name === '') {
                $errors[] = 'Write the parent’s name.';
            }
            if ($quote === '') {
                $errors[] = 'Write what they said.';
            }
            $photo = content_tf_photo('photo', $errors);
            if ($errors === []) {
                $item = ['name' => $name, 'role' => $role, 'quote' => $quote, 'photo' => ''];
                if ($idx !== null && isset($testimonials[$idx])) {
                    $item['photo'] = $testimonials[$idx]['photo'];
                    if ($photo !== null) {
                        $unlinkPhoto($item['photo']);
                        $item['photo'] = $photo;
                    }
                    $testimonials[$idx] = $item;
                } else {
                    $item['photo'] = $photo ?? '';
                    $testimonials[] = $item;
                }
                if ($persist('testimonials', $testimonials)) {
                    $success = 'Parent’s words saved.';
                    $editT = -1;
                } else {
                    $errors[] = 'Could not save.';
                }
            }
        }

        if ($action === 'delete_testimonial') {
            $d = (int) ($_POST['idx'] ?? -1);
            if (isset($testimonials[$d])) {
                $unlinkPhoto((string) ($testimonials[$d]['photo'] ?? ''));
                array_splice($testimonials, $d, 1);
                $testimonials = array_values($testimonials);
                if ($persist('testimonials', $testimonials)) {
                    $success = 'Removed.';
                } else {
                    $errors[] = 'Could not remove.';
                }
            }
        }

        if ($action === 'move_testimonial') {
            $idx = (int) ($_POST['idx'] ?? -1);
            $swap = (string) ($_POST['dir'] ?? '') === 'up' ? $idx - 1 : $idx + 1;
            if (isset($testimonials[$idx], $testimonials[$swap])) {
                $tmp = $testimonials[$idx];
                $testimonials[$idx] = $testimonials[$swap];
                $testimonials[$swap] = $tmp;
                $testimonials = array_values($testimonials);
                if ($persist('testimonials', $testimonials)) {
                    $success = 'Order updated.';
                } else {
                    $errors[] = 'Could not change the order.';
                }
            }
        }

        if ($action === 'save_faqs') {
            $qs = $_POST['faq_q'] ?? [];
            $as = $_POST['faq_a'] ?? [];
            $new = [];
            if (is_array($qs)) {
                foreach ($qs as $i => $q) {
                    $row = $normF(['q' => $q, 'a' => is_array($as) ? ($as[$i] ?? '') : '']);
                    if ($row['q'] === '' && $row['a'] === '') {
                        continue;
                    }
                    $new[] = $row;
                }
            }
            if ($persist('faqs', $new)) {
                $faqs = $new;
                if ($faqs === []) {
                    $faqs = [['q' => '', 'a' => '']];
                }
                $success = 'Questions saved.';
            } else {
                $errors[] = 'Could not save questions.';
            }
        }
    }
}

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
if ($success !== '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $testimonials = [];
    foreach (cms_decode_json_field($school['testimonials'] ?? null) as $t) {
        $row = $normT($t);
        if ($row['name'] !== '' || $row['quote'] !== '') {
            $testimonials[] = $row;
        }
    }
    if ((string) ($_POST['action'] ?? '') !== 'save_faqs') {
        $faqs = [];
        foreach (cms_decode_json_field($school['faqs'] ?? null) as $f) {
            $row = $normF($f);
            if ($row['q'] !== '' || $row['a'] !== '') {
                $faqs[] = $row;
            }
        }
    }
}
if ($faqs === []) {
    $faqs = [['q' => '', 'a' => '']];
}

$editRow = ($editT >= 0 && isset($testimonials[$editT])) ? $testimonials[$editT] : null;
$self = function_exists('site_url') ? site_url('/owner/content_testimonialsfaq.php') : 'content_testimonialsfaq.php';
$publicT = function_exists('site_url') ? site_url('/#testimonials') : '/#testimonials';

require_once __DIR__ . '/../header.php';
?>
<style>
.tf-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.tf-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.tf-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.tf-item { display:flex; gap:10px; align-items:flex-start; border:1px solid #dbe7fb; border-radius:14px; padding:10px; margin-bottom:8px; background:#f8fafc; }
.tf-item img, .tf-ph { width:48px; height:48px; border-radius:12px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.tf-row { border:1px dashed #dbe7fb; border-radius:14px; padding:12px; margin-bottom:8px; background:#f8fafc; }
.tf-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; z-index:2; }
</style>

<div class="tf-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">Parents’ words &amp; FAQs</div>
    <div class="text-muted">Short parent reviews and answers visitors ask before admission.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicT); ?>" target="_blank" rel="noopener">See on website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<div class="tf-card">
  <div class="tf-sec"><?php echo $editRow ? 'Edit parent review' : 'Add a parent review'; ?></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save_testimonial">
    <input type="hidden" name="idx" value="<?php echo $editRow ? (int) $editT : ''; ?>">
    <div class="row g-2">
      <div class="col-md-4">
        <input name="name" class="form-control" required placeholder="Parent’s name" value="<?php echo e($editRow['name'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <input name="role" class="form-control" placeholder="Parent" value="<?php echo e($editRow['role'] ?? 'Parent'); ?>">
      </div>
      <div class="col-md-4">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
      </div>
      <div class="col-12">
        <textarea name="quote" class="form-control" rows="3" required placeholder="What they said about the school"><?php echo e($editRow['quote'] ?? ''); ?></textarea>
      </div>
    </div>
    <div class="mt-2 d-flex gap-2">
      <button class="btn btn-success" type="submit"><?php echo $editRow ? 'Update' : 'Add review'; ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="<?php echo e($self); ?>">Cancel</a><?php endif; ?>
    </div>
    <div class="form-text mt-1">Photo is optional. Leave empty to keep the current photo.</div>
  </form>
</div>

<div class="tf-card">
  <div class="tf-sec"><?php echo count($testimonials); ?> review<?php echo count($testimonials) === 1 ? '' : 's'; ?> on the website</div>
  <?php if ($testimonials === []): ?>
    <div class="text-muted">No parent reviews yet. Add one above.</div>
  <?php else: ?>
    <?php foreach ($testimonials as $i => $t):
        $photo = $t['photo'] !== '' && function_exists('cms_resolve_image_url') ? cms_resolve_image_url($t['photo']) : '';
        ?>
      <div class="tf-item">
        <?php if ($photo !== ''): ?><img src="<?php echo e($photo); ?>" alt=""><?php else: ?><div class="tf-ph"></div><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-bold"><?php echo e($t['name']); ?><?php if ($t['role'] !== ''): ?> <span class="small text-muted">· <?php echo e($t['role']); ?></span><?php endif; ?></div>
          <div class="small"><?php echo e($t['quote']); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-1">
          <?php if ($i > 0): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="move_testimonial"><input type="hidden" name="dir" value="up"><input type="hidden" name="idx" value="<?php echo $i; ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">↑</button></form>
          <?php endif; ?>
          <?php if ($i < count($testimonials) - 1): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="move_testimonial"><input type="hidden" name="dir" value="down"><input type="hidden" name="idx" value="<?php echo $i; ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">↓</button></form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($self . '?edit_t=' . $i); ?>">Edit</a>
          <form method="post" onsubmit="return confirm('Remove this review from the website?');">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="delete_testimonial">
            <input type="hidden" name="idx" value="<?php echo $i; ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<form method="post">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
  <input type="hidden" name="action" value="save_faqs">
  <div class="tf-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="tf-sec mb-0">Questions parents ask</div>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addFaq">Add question</button>
    </div>
    <div class="form-text mb-2">Fees, timings, admission — short answers in simple words.</div>
    <div id="faqList">
      <?php foreach ($faqs as $f): ?>
        <div class="tf-row">
          <input name="faq_q[]" class="form-control mb-2" placeholder="Question" value="<?php echo e($f['q']); ?>">
          <textarea name="faq_a[]" class="form-control" rows="2" placeholder="Answer"><?php echo e($f['a']); ?></textarea>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="removeFaq(this)">Remove</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="tf-bar">
    <button class="btn btn-success" type="submit">Save questions</button>
  </div>
</form>

<script>
document.getElementById('addFaq').addEventListener('click', function () {
  var wrap = document.getElementById('faqList');
  var div = document.createElement('div');
  div.className = 'tf-row';
  div.innerHTML = '<input name="faq_q[]" class="form-control mb-2" placeholder="Question"><textarea name="faq_a[]" class="form-control" rows="2" placeholder="Answer"></textarea><button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="removeFaq(this)">Remove</button>';
  wrap.appendChild(div);
  div.querySelector('input').focus();
});
function removeFaq(btn) {
  var item = btn.closest('.tf-row');
  var wrap = document.getElementById('faqList');
  if (!item || !wrap) return;
  if (wrap.children.length <= 1) {
    item.querySelectorAll('input,textarea').forEach(function (i) { i.value = ''; });
    return;
  }
  item.remove();
}
</script>
<?php
require_once __DIR__ . '/../footer.php';
