<?php
/**
 * Shared feature: content_facilities
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'Facilities');

/* -------------------------
   JSON helper
   ------------------------- */

/* -------------------------
   Load school row
   ------------------------- */
$school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: [];

/* current values */
$current_facilities = cms_decode_json_field($school['facilities'] ?? null);
$current_classes = cms_decode_json_field($school['classes_offered'] ?? null);

/* prepare simple text versions for textareas */
$facilities_lines = '';
if (!empty($current_facilities) && is_array($current_facilities)) {
    $facilities_lines = implode("\n", $current_facilities);
}
$classes_lines = '';
$classes_json_text = '';
if (!empty($current_classes) && is_array($current_classes)) {
    $parts = [];
    foreach ($current_classes as $c) {
        $parts[] = (($c['name'] ?? '') . '|' . ($c['age'] ?? '') . '|' . (isset($c['fees']) ? $c['fees'] : ''));
    }
    $classes_lines = implode("\n", $parts);
    $classes_json_text = json_encode($current_classes, JSON_UNESCAPED_UNICODE);
}

/* -------------------------
   Handle POST
   ------------------------- */
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_facilities') {
    // Facilities: from textarea (one per line) OR from tag-style JS input (same field)
    $fac_input = trim((string)($_POST['facilities_lines'] ?? ''));
    $fac_items = [];
    if ($fac_input !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $fac_input);
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln !== '') $fac_items[] = $ln;
        }
    }

    // Classes: prefer JSON if provided, otherwise parse lines "Name|Age|Fees"
    $classes_json_input = trim((string)($_POST['classes_json'] ?? ''));
    $classes_lines_input = trim((string)($_POST['classes_lines'] ?? ''));

    $classes_arr = [];
    if ($classes_json_input !== '') {
        $decoded = json_decode($classes_json_input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $classes_arr = $decoded;
        } else {
            $errors[] = 'Classes JSON is invalid. Provide valid JSON or use the simple lines format.';
        }
    } else {
        if ($classes_lines_input !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $classes_lines_input);
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                $parts = array_map('trim', explode('|', $ln));
                $name = $parts[0] ?? '';
                $age  = $parts[1] ?? '';
                $fees = isset($parts[2]) ? (float)$parts[2] : null;
                if ($name !== '') $classes_arr[] = ['name'=>$name, 'age'=>$age, 'fees'=>$fees];
            }
        }
    }

    if (empty($errors)) {
        $fac_json = json_encode($fac_items, JSON_UNESCAPED_UNICODE);
        $classes_json = json_encode($classes_arr, JSON_UNESCAPED_UNICODE);

        // Update if row exists, else insert with minimal default name
        if (!empty($school)) {
            $ok = safe_db_run("UPDATE schools SET facilities = :fac, classes_offered = :classes, updated_at = NOW() WHERE id = 1", [
                ':fac' => $fac_json,
                ':classes' => $classes_json
            ]);
        } else {
            $default_name = 'Pioneer Play School';
            $ok = safe_db_run("INSERT INTO schools (id, name, facilities, classes_offered, created_at, updated_at) VALUES (1, :name, :fac, :classes, NOW(), NOW())", [
                ':name' => $default_name,
                ':fac' => $fac_json,
                ':classes' => $classes_json
            ]);
        }

        if ($ok) {
            $success = 'Facilities and classes saved.';
            $school = safe_db_get_one("SELECT * FROM schools WHERE id = :id LIMIT 1", [':id' => 1]) ?: $school;
            $current_facilities = cms_decode_json_field($school['facilities'] ?? null);
            $current_classes = cms_decode_json_field($school['classes_offered'] ?? null);
            // refresh textareas
            $facilities_lines = implode("\n", $current_facilities);
            $parts = [];
            foreach ($current_classes as $c) $parts[] = (($c['name'] ?? '') . '|' . ($c['age'] ?? '') . '|' . (isset($c['fees']) ? $c['fees'] : ''));
            $classes_lines = implode("\n", $parts);
            $classes_json_text = json_encode($current_classes, JSON_UNESCAPED_UNICODE);
        } else {
            $errors[] = 'Database error while saving.';
        }
    }
}
$pageTitle = (string)($cfg['page_title'] ?? 'Facilities');
require_once __DIR__ . '/../header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div><?php endif; ?>

  <form method="post" class="card p-3" id="facForm">
    <input type="hidden" name="action" value="save_facilities">

    <div class="mb-3">
      <label class="form-label">Facilities / Activities (one per line)</label>
      <textarea name="facilities_lines" id="facilities_lines" class="form-control" rows="6" placeholder="Art & Craft"><?php echo e($facilities_lines); ?></textarea>
      <div class="form-text">Enter one facility per line. You can also use the tag UI below to add/remove quickly.</div>
    </div>

    <div class="mb-3">
      <label class="form-label">Quick Edit (tags)</label>
      <div id="facTags" class="mb-2">
        <?php foreach ($current_facilities as $f): ?>
          <span class="tag"><?php echo e($f); ?> <a class="remove" data-value="<?php echo e($f); ?>">✖</a></span>
        <?php endforeach; ?>
      </div>
      <div class="input-group mb-2">
        <input id="facInput" class="form-control" placeholder="Add facility and press Add">
        <button type="button" id="addFacBtn" class="btn btn-outline-primary">Add</button>
      </div>
      <div class="form-text">Adding/removing tags will sync to the textarea when you Save.</div>
    </div>

    <hr>

    <h5>Classes Offered</h5>
    <p class="small text-muted">Either provide JSON (preferred) or use simple lines: Name|Age|Fees</p>

    <div class="mb-3">
      <label class="form-label">Classes (JSON)</label>
      <textarea name="classes_json" class="form-control" rows="6" placeholder='[{"name":"Nursery","age":"2.5-3.5","fees":3500}]'><?php echo e($classes_json_text); ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">Or Classes (lines: Name|Age|Fees)</label>
      <textarea name="classes_lines" class="form-control" rows="6" placeholder="Nursery|2.5-3.5|3500"><?php echo e($classes_lines); ?></textarea>
    </div>

    <div class="mt-3">
      <button class="btn btn-primary">Save Facilities & Classes</button>
      
    </div>
  </form>

  <?php if (!empty($current_classes)): ?>
    <div class="card mt-4 p-3">
      <h5>Preview: Classes Offered</h5>
      <div class="row g-2">
        <?php foreach ($current_classes as $c): ?>
          <div class="col-12 col-md-6 col-lg-3">
            <div class="card p-2">
              <div class="fw-semibold"><?php echo e($c['name'] ?? 'Class'); ?></div>
              <div class="small text-muted"><?php echo e($c['age'] ?? ''); ?></div>
              <?php if (!empty($c['fees'])): ?><div class="mt-1">₹ <?php echo number_format((float)$c['fees'], 2); ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
  // Tag UI syncing with textarea
  const facTags = document.getElementById('facTags');
  const facInput = document.getElementById('facInput');
  const addFacBtn = document.getElementById('addFacBtn');
  const facilitiesTextarea = document.getElementById('facilities_lines');

  function addTag(value) {
    value = (value || '').trim();
    if (!value) return;
    // avoid duplicates
    const existing = Array.from(facTags.querySelectorAll('.tag')).map(t => t.textContent.replace(' ✖','').trim());
    if (existing.indexOf(value) !== -1) return;
    const span = document.createElement('span');
    span.className = 'tag';
    span.innerHTML = escapeHtml(value) + ' <a class="remove" data-value="' + escapeHtml(value) + '">✖</a>';
    facTags.appendChild(span);
    syncTextareaFromTags();
  }

  addFacBtn.addEventListener('click', () => { addTag(facInput.value); facInput.value=''; facInput.focus(); });
  facInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(facInput.value); facInput.value=''; } });

  facTags.addEventListener('click', function (e) {
    if (e.target && e.target.matches('.remove')) {
      const val = e.target.getAttribute('data-value');
      const span = e.target.closest('.tag');
      if (span) span.remove();
      syncTextareaFromTags();
    }
  });

  function syncTextareaFromTags() {
    const items = Array.from(facTags.querySelectorAll('.tag')).map(t => t.textContent.replace(' ✖','').trim());
    facilitiesTextarea.value = items.join("\n");
  }

  // escape HTML helper
  function escapeHtml(str) {
    return str.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  // initialize: ensure textarea reflects tags (if user removed or added via raw textarea this will keep tags in sync on page load)
  (function initSync() {
    // if textarea has content, populate tags from it
    const txt = facilitiesTextarea.value.trim();
    if (txt.length > 0 && facTags.children.length === 0) {
      const lines = txt.split(/\r\n|\r|\n/).map(s=>s.trim()).filter(Boolean);
      facTags.innerHTML = '';
      lines.forEach(l => {
        const span = document.createElement('span');
        span.className = 'tag';
        span.innerHTML = escapeHtml(l) + ' <a class="remove" data-value="' + escapeHtml(l) + '">✖</a>';
        facTags.appendChild(span);
      });
    }
  })();
</script>


<?php
require_once __DIR__ . '/../footer.php';
