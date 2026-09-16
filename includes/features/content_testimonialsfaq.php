<?php
/**
 * Shared feature: content_testimonialsfaq
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'Testimonials & FAQ');

function save_uploaded_photo(string $field, array &$errors = null): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) { $errors[] = "Upload error ({$_FILES[$field]['error']})"; return null; }
    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) { $errors[] = 'File is not a valid image.'; return null; }
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($info[2], $allowed, true)) { $errors[] = 'Unsupported image type.'; return null; }
    $dir = cms_upload_dir();
    if ($dir === false) { $errors[] = 'Upload folder not writable.'; return null; }
    $orig = basename((string)($_FILES[$field]['name'] ?? 'photo'));
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    $uniq = time() . '_' . bin2hex(random_bytes(5)) . '_' . $safe;
    $dest = $dir . '/' . $uniq;
    if (!move_uploaded_file($tmp, $dest)) { $errors[] = 'Failed to move uploaded file.'; return null; }
    // Return path under site base
    return cms_public_upload_url($uniq);
}

/* -------------------------
   Load school row and decode fields
   ------------------------- */
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];
$testimonials = [];
$faqs = [];
if (!empty($school['testimonials'])) {
    $tmp = json_decode((string)$school['testimonials'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $testimonials = $tmp;
}
if (!empty($school['faqs'])) {
    $tmp = json_decode((string)$school['faqs'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $faqs = $tmp;
}

/* -------------------------
   Actions handling
   ------------------------- */
$errors = [];
$success = '';

// Add / Edit testimonial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_testimonial') {
    $idx = isset($_POST['idx']) && $_POST['idx'] !== '' ? (int)$_POST['idx'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $quote = trim((string)($_POST['quote'] ?? ''));

    if ($name === '') $errors[] = 'Testimonial name required.';
    if ($quote === '') $errors[] = 'Quote required.';

    // Handle photo upload
    $photo = null;
    $uploadErrors = [];
    $maybe = save_uploaded_photo('photo', $uploadErrors);
    if (!empty($uploadErrors)) $errors = array_merge($errors, $uploadErrors);
    if ($maybe !== null) $photo = $maybe;

    if (empty($errors)) {
        $item = ['name' => $name, 'role' => $role, 'quote' => $quote];
        if ($photo !== null) $item['photo'] = $photo;
        if ($idx !== null && isset($testimonials[$idx])) {
            // if replacing photo, remove old file (best effort)
            if (isset($photo) && !empty($testimonials[$idx]['photo']) && $testimonials[$idx]['photo'] !== $photo) {
                $old = $testimonials[$idx]['photo'];
                // derive filesystem path and unlink
                $appRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
                $relative = (strpos($old, site_url('')) === 0) ? substr($old, strlen(site_url(''))) : ltrim($old, '/');
                $fs = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relative, '/');
                if (is_file($fs)) @unlink($fs);
            }
            // update
            $testimonials[$idx] = array_merge($testimonials[$idx], $item);
        } else {
            // append
            $testimonials[] = $item;
        }

        // save to DB (UPDATE or INSERT)
        $json = json_encode(array_values($testimonials), JSON_UNESCAPED_UNICODE);
        if (!empty($school)) {
            $ok = safe_db_run("UPDATE schools SET testimonials = :t, updated_at = NOW() WHERE id = 1", [':t' => $json]);
        } else {
            $ok = safe_db_run("INSERT INTO schools (id, name, testimonials, created_at, updated_at) VALUES (1, :name, :t, NOW(), NOW())", [
                ':name' => 'Pioneer Play School',
                ':t' => $json
            ]);
        }
        if ($ok) {
            $success = 'Testimonial saved.';
            // reload school/testimonials
            $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: $school;
            $testimonials = json_decode((string)$school['testimonials'], true) ?: $testimonials;
        } else $errors[] = 'DB error saving testimonial.';
    }
}

// Delete testimonial
if (isset($_GET['delete_testimonial'])) {
    $d = (int)$_GET['delete_testimonial'];
    if (isset($testimonials[$d])) {
        $del = $testimonials[$d];
        array_splice($testimonials, $d, 1);
        $json = json_encode(array_values($testimonials), JSON_UNESCAPED_UNICODE);
        if (!empty($school)) {
            $ok = safe_db_run("UPDATE schools SET testimonials = :t, updated_at = NOW() WHERE id = 1", [':t' => $json]);
        } else {
            $ok = safe_db_run("INSERT INTO schools (id, name, testimonials, created_at, updated_at) VALUES (1, :name, :t, NOW(), NOW())", [':name'=>'Pioneer Play School', ':t'=>$json]);
        }
        if ($ok) {
            // remove photo file if present
            if (!empty($del['photo'])) {
                $old = $del['photo'];
                $relative = (strpos($old, site_url('')) === 0) ? substr($old, strlen(site_url(''))) : ltrim($old, '/');
                $fs = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relative, '/');
                if (is_file($fs)) @unlink($fs);
            }
            $success = 'Testimonial removed.';
            $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: $school;
            $testimonials = json_decode((string)$school['testimonials'], true) ?: $testimonials;
        } else $errors[] = 'DB error deleting testimonial.';
    } else {
        $errors[] = 'Testimonial not found.';
    }
}

// Reorder testimonials (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder_testimonials' && !empty($_POST['order']) && is_array($_POST['order'])) {
    // order contains integer indexes in current order — convert to new array
    $new = [];
    foreach ($_POST['order'] as $i) {
        $i = (int)$i;
        if (isset($testimonials[$i])) $new[] = $testimonials[$i];
    }
    // append any missing
    foreach ($testimonials as $t) if (!in_array($t, $new, true)) $new[] = $t;
    $json = json_encode(array_values($new), JSON_UNESCAPED_UNICODE);
    if (!empty($school)) $ok = safe_db_run("UPDATE schools SET testimonials = :t, updated_at = NOW() WHERE id = 1", [':t'=>$json]);
    else $ok = safe_db_run("INSERT INTO schools (id, name, testimonials, created_at, updated_at) VALUES (1, :name, :t, NOW(), NOW())", [':name'=>'Pioneer Play School', ':t'=>$json]);
    if ($ok) { $success = 'Order saved.'; $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: $school; $testimonials = json_decode((string)$school['testimonials'], true) ?: $testimonials; }
    else $errors[] = 'DB error saving order.';
}

/* -------------------------
   FAQs: add/edit/delete/reorder (similar approach)
   ------------------------- */
// Save FAQ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_faq') {
    $idx = isset($_POST['idx']) && $_POST['idx'] !== '' ? (int)$_POST['idx'] : null;
    $q = trim((string)($_POST['q'] ?? ''));
    $a = trim((string)($_POST['a'] ?? ''));
    if ($q === '') $errors[] = 'FAQ question required.';
    if ($a === '') $errors[] = 'FAQ answer required.';
    if (empty($errors)) {
        $item = ['q' => $q, 'a' => $a];
        if ($idx !== null && isset($faqs[$idx])) $faqs[$idx] = array_merge($faqs[$idx], $item);
        else $faqs[] = $item;
        $json = json_encode(array_values($faqs), JSON_UNESCAPED_UNICODE);
        if (!empty($school)) $ok = safe_db_run("UPDATE schools SET faqs = :f, updated_at = NOW() WHERE id = 1", [':f'=>$json]);
        else $ok = safe_db_run("INSERT INTO schools (id, name, faqs, created_at, updated_at) VALUES (1, :name, :f, NOW(), NOW())", [':name'=>'Pioneer Play School', ':f'=>$json]);
        if ($ok) { $success = 'FAQ saved.'; $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: $school; $faqs = json_decode((string)$school['faqs'], true) ?: $faqs; }
        else $errors[] = 'DB error saving FAQ.';
    }
}

// Delete FAQ (GET)
if (isset($_GET['delete_faq'])) {
    $d = (int)$_GET['delete_faq'];
    if (isset($faqs[$d])) {
        array_splice($faqs, $d, 1);
        $json = json_encode(array_values($faqs), JSON_UNESCAPED_UNICODE);
        if (!empty($school)) $ok = safe_db_run("UPDATE schools SET faqs = :f, updated_at = NOW() WHERE id = 1", [':f'=>$json]);
        else $ok = safe_db_run("INSERT INTO schools (id, name, faqs, created_at, updated_at) VALUES (1, :name, :f, NOW(), NOW())", [':name'=>'Pioneer Play School', ':f'=>$json]);
        if ($ok) { $success = 'FAQ removed.'; $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: $school; $faqs = json_decode((string)$school['faqs'], true) ?: $faqs; }
        else $errors[] = 'DB error deleting FAQ.';
    } else $errors[] = 'FAQ not found.';
}

// Reorder FAQs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder_faqs' && !empty($_POST['order']) && is_array($_POST['order'])) {
    $new = [];
    foreach ($_POST['order'] as $i) {
        $i = (int)$i;
        if (isset($faqs[$i])) $new[] = $faqs[$i];
    }
    foreach ($faqs as $f) if (!in_array($f, $new, true)) $new[] = $f;
    $json = json_encode(array_values($new), JSON_UNESCAPED_UNICODE);
    if (!empty($school)) $ok = safe_db_run("UPDATE schools SET faqs = :f, updated_at = NOW() WHERE id = 1", [':f'=>$json]);
    else $ok = safe_db_run("INSERT INTO schools (id, name, faqs, created_at, updated_at) VALUES (1, :name, :f, NOW(), NOW())", [':name'=>'Pioneer Play School', ':f'=>$json]);
    if ($ok) { $success = 'FAQ order saved.'; $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id'=>1]) ?: $school; $faqs = json_decode((string)$school['faqs'], true) ?: $faqs; }
    else $errors[] = 'DB error saving FAQ order.';
}

/* -------------------------
   Prepare JSON/text for forms
   ------------------------- */
$testimonial_count = count($testimonials);
$faq_count = count($faqs);

/* -------------------------
   Render UI
   ------------------------- */
$pageTitle = (string)($cfg['page_title'] ?? 'Testimonials & FAQ');
require_once __DIR__ . '/../header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div><?php endif; ?>

  <div class="row g-4">
    <!-- Testimonials column -->
    <div class="col-lg-7">
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Testimonials (<?php echo $testimonial_count; ?>)</h5>
          <button class="btn btn-sm btn-outline-primary" onclick="showTestimonialForm()">Add Testimonial</button>
        </div>

        <!-- Add/Edit form (hidden toggle) -->
        <div id="testimonialFormWrap" class="mb-3" style="display:none;">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_testimonial">
            <input type="hidden" name="idx" id="testimonial_idx" value="">
            <div class="row g-2">
              <div class="col-md-4"><input name="name" id="testimonial_name" class="form-control" placeholder="Name"></div>
              <div class="col-md-4"><input name="role" id="testimonial_role" class="form-control" placeholder="Role (e.g., Parent)"></div>
              <div class="col-md-4"><input type="file" name="photo" class="form-control"></div>
              <div class="col-12"><textarea name="quote" id="testimonial_quote" class="form-control" rows="3" placeholder="Quote"></textarea></div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-success">Save</button>
                <button type="button" class="btn btn-outline-secondary" onclick="hideTestimonialForm()">Cancel</button>
              </div>
            </div>
          </form>
        </div>

        <!-- Testimonials list (draggable) -->
        <?php if (!empty($testimonials)): ?>
          <div id="testimonialsList" class="list-group">
            <?php foreach ($testimonials as $i => $t): ?>
              <div class="list-group-item item-card d-flex align-items-center" data-index="<?php echo $i; ?>" draggable="true">
                <div class="me-3">
                  <?php if (!empty($t['photo'])): ?><img src="<?php echo e($t['photo']); ?>" class="thumb" alt="photo"><?php else: ?><div class="thumb bg-secondary"></div><?php endif; ?>
                </div>
                <div class="flex-grow-1">
                  <div class="fw-semibold"><?php echo e($t['name'] ?? ''); ?> <?php if (!empty($t['role'])) echo '<span class="small-muted">• ' . e($t['role']) . '</span>'; ?></div>
                  <div class="small text-muted"><?php echo e(mb_substr($t['quote'] ?? '', 0, 180)); ?></div>
                </div>
                <div class="ms-3 d-flex flex-column gap-2">
                  <button class="btn btn-sm btn-outline-primary" onclick='editTestimonial(<?php echo $i; ?>)'>Edit</button>
                  <a class="btn btn-sm btn-danger" href="?delete_testimonial=<?php echo $i; ?>" onclick="return confirm('Delete testimonial?')">Delete</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <form id="reorderTestimonialsForm" method="post" class="mt-2">
            <input type="hidden" name="action" value="reorder_testimonials">
            <!-- dynamic order inputs will be appended by JS -->
            <button id="saveTestOrderBtn" type="button" class="btn btn-sm btn-success mt-2">Save Order</button>
          </form>
        <?php else: ?>
          <div class="text-muted">No testimonials yet. Add some using the button above.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- FAQs column -->
    <div class="col-lg-5">
      <div class="card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">FAQs (<?php echo $faq_count; ?>)</h5>
          <button class="btn btn-sm btn-outline-primary" onclick="showFaqForm()">Add FAQ</button>
        </div>

        <div id="faqFormWrap" style="display:none;" class="mb-3">
          <form method="post">
            <input type="hidden" name="action" value="save_faq">
            <input type="hidden" name="idx" id="faq_idx" value="">
            <div class="mb-2"><input name="q" id="faq_q" class="form-control" placeholder="Question"></div>
            <div class="mb-2"><textarea name="a" id="faq_a" class="form-control" rows="3" placeholder="Answer"></textarea></div>
            <div class="d-flex gap-2">
              <button class="btn btn-success">Save FAQ</button>
              <button type="button" class="btn btn-outline-secondary" onclick="hideFaqForm()">Cancel</button>
            </div>
          </form>
        </div>

        <?php if (!empty($faqs)): ?>
          <div id="faqsList" class="list-group">
            <?php foreach ($faqs as $i => $f): ?>
              <div class="list-group-item item-card d-flex align-items-start" data-index="<?php echo $i; ?>" draggable="true">
                <div class="flex-grow-1">
                  <div class="fw-semibold"><?php echo e($f['q'] ?? ''); ?></div>
                  <div class="small text-muted"><?php echo e(mb_substr($f['a'] ?? '', 0, 220)); ?></div>
                </div>
                <div class="ms-3 d-flex flex-column gap-2">
                  <button class="btn btn-sm btn-outline-primary" onclick='editFaq(<?php echo $i; ?>)'>Edit</button>
                  <a class="btn btn-sm btn-danger" href="?delete_faq=<?php echo $i; ?>" onclick="return confirm('Delete FAQ?')">Delete</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <form id="reorderFaqsForm" method="post" class="mt-2">
            <input type="hidden" name="action" value="reorder_faqs">
            <button id="saveFaqOrderBtn" type="button" class="btn btn-sm btn-success mt-2">Save Order</button>
          </form>
        <?php else: ?>
          <div class="text-muted">No FAQs yet. Add one using the button above.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  // Testimonials form toggles & edit population
  function showTestimonialForm() { document.getElementById('testimonialFormWrap').style.display = ''; window.scrollTo({top:0,behavior:'smooth'}); }
  function hideTestimonialForm() {
    document.getElementById('testimonialFormWrap').style.display = 'none';
    document.getElementById('testimonial_idx').value = '';
    document.getElementById('testimonial_name').value = '';
    document.getElementById('testimonial_role').value = '';
    document.getElementById('testimonial_quote').value = '';
  }
  const testimonials = <?php echo json_encode($testimonials, JSON_UNESCAPED_UNICODE); ?>;
  function editTestimonial(i) {
    const t = testimonials[i];
    if (!t) return;
    document.getElementById('testimonial_idx').value = i;
    document.getElementById('testimonial_name').value = t.name || '';
    document.getElementById('testimonial_role').value = t.role || '';
    document.getElementById('testimonial_quote').value = t.quote || '';
    showTestimonialForm();
  }

  // FAQs form toggles & edit
  function showFaqForm() { document.getElementById('faqFormWrap').style.display = ''; window.scrollTo({top:0,behavior:'smooth'}); }
  function hideFaqForm() {
    document.getElementById('faqFormWrap').style.display = 'none';
    document.getElementById('faq_idx').value = '';
    document.getElementById('faq_q').value = '';
    document.getElementById('faq_a').value = '';
  }
  const faqs = <?php echo json_encode($faqs, JSON_UNESCAPED_UNICODE); ?>;
  function editFaq(i) {
    const f = faqs[i];
    if (!f) return;
    document.getElementById('faq_idx').value = i;
    document.getElementById('faq_q').value = f.q || '';
    document.getElementById('faq_a').value = f.a || '';
    showFaqForm();
  }

  // Drag & drop reorder for testimonials and faqs
  function enableDragList(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    let dragEl = null;
    container.querySelectorAll('[data-index]').forEach(el => {
      el.draggable = true;
      el.addEventListener('dragstart', (e) => { dragEl = el; el.style.opacity='0.5'; e.dataTransfer.effectAllowed='move'; });
      el.addEventListener('dragend', () => { if (dragEl) dragEl.style.opacity='1'; dragEl=null; });
      el.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect='move'; });
      el.addEventListener('drop', (e) => {
        e.preventDefault(); if (!dragEl || dragEl === el) return;
        const rect = el.getBoundingClientRect(); const offset = e.clientY - rect.top;
        if (offset > rect.height/2) el.parentNode.insertBefore(dragEl, el.nextSibling);
        else el.parentNode.insertBefore(dragEl, el);
      });
    });
  }
  enableDragList('testimonialsList');
  enableDragList('faqsList');

  // Save testimonials order
  document.getElementById('saveTestOrderBtn').addEventListener('click', function() {
    const items = Array.from(document.querySelectorAll('#testimonialsList [data-index]')).map(n => n.getAttribute('data-index'));
    const form = document.createElement('form'); form.method='post';
    form.innerHTML = '<input type="hidden" name="action" value="reorder_testimonials">';
    items.forEach(i => { const inp = document.createElement('input'); inp.type='hidden'; inp.name='order[]'; inp.value=i; form.appendChild(inp); });
    document.body.appendChild(form); form.submit();
  });

  // Save FAQs order
  document.getElementById('saveFaqOrderBtn').addEventListener('click', function() {
    const items = Array.from(document.querySelectorAll('#faqsList [data-index]')).map(n => n.getAttribute('data-index'));
    const form = document.createElement('form'); form.method='post';
    form.innerHTML = '<input type="hidden" name="action" value="reorder_faqs">';
    items.forEach(i => { const inp = document.createElement('input'); inp.type='hidden'; inp.name='order[]'; inp.value=i; form.appendChild(inp); });
    document.body.appendChild(form); form.submit();
  });
</script>


<?php
require_once __DIR__ . '/../footer.php';
