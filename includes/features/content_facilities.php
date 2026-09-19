<?php
/**
 * Website facilities and classes offered (what parents see).
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'Facilities & classes');
$page_title = $pageTitle;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

$normFac = static function (mixed $item): array {
    if (is_string($item)) {
        $t = trim($item);
        return ['title' => $t, 'description' => ''];
    }
    if (!is_array($item)) {
        return ['title' => '', 'description' => ''];
    }
    $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
    $desc = trim((string) ($item['description'] ?? $item['desc'] ?? ''));
    return ['title' => $title, 'description' => $desc];
};

$normClass = static function (mixed $item): array {
    if (!is_array($item)) {
        return ['name' => '', 'age' => '', 'fees' => ''];
    }
    $fees = $item['fees'] ?? '';
    if ($fees === null || $fees === false) {
        $fees = '';
    } elseif (is_numeric($fees)) {
        $fees = (string) (abs((float) $fees - (int) $fees) < 0.001 ? (int) $fees : $fees);
    } else {
        $fees = trim((string) $fees);
    }
    return [
        'name' => trim((string) ($item['name'] ?? '')),
        'age' => trim((string) ($item['age'] ?? '')),
        'fees' => $fees,
    ];
};

$school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: [];
$facilities = [];
foreach (cms_decode_json_field($school['facilities'] ?? null) as $f) {
    $row = $normFac($f);
    if ($row['title'] !== '' || $row['description'] !== '') {
        $facilities[] = $row;
    }
}
$classes = [];
foreach (cms_decode_json_field($school['classes_offered'] ?? null) as $c) {
    $row = $normClass($c);
    if ($row['name'] !== '') {
        $classes[] = $row;
    }
}
if ($facilities === []) {
    $facilities = [
        ['title' => '', 'description' => ''],
        ['title' => '', 'description' => ''],
    ];
}
if ($classes === []) {
    $classes = [
        ['name' => '', 'age' => '', 'fees' => ''],
        ['name' => '', 'age' => '', 'fees' => ''],
    ];
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_facilities') {
    if (function_exists('validate_csrf_token') && !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $facTitles = $_POST['fac_title'] ?? [];
        $facDescs = $_POST['fac_desc'] ?? [];
        $newFac = [];
        if (is_array($facTitles)) {
            foreach ($facTitles as $i => $t) {
                $row = $normFac([
                    'title' => $t,
                    'description' => is_array($facDescs) ? ($facDescs[$i] ?? '') : '',
                ]);
                if ($row['title'] === '' && $row['description'] === '') {
                    continue;
                }
                $newFac[] = $row;
            }
        }

        $clsNames = $_POST['class_name'] ?? [];
        $clsAges = $_POST['class_age'] ?? [];
        $clsFees = $_POST['class_fees'] ?? [];
        $newCls = [];
        if (is_array($clsNames)) {
            foreach ($clsNames as $i => $n) {
                $feesRaw = is_array($clsFees) ? trim((string) ($clsFees[$i] ?? '')) : '';
                $feesVal = null;
                if ($feesRaw !== '') {
                    $feesVal = (float) preg_replace('/[^\d.]/', '', $feesRaw);
                }
                $row = $normClass([
                    'name' => $n,
                    'age' => is_array($clsAges) ? ($clsAges[$i] ?? '') : '',
                    'fees' => $feesVal,
                ]);
                if ($row['name'] === '') {
                    continue;
                }
                $newCls[] = [
                    'name' => $row['name'],
                    'age' => $row['age'],
                    'fees' => $feesVal,
                ];
            }
        }

        if ($errors === []) {
            $facJson = json_encode($newFac, JSON_UNESCAPED_UNICODE);
            $clsJson = json_encode($newCls, JSON_UNESCAPED_UNICODE);
            $ok = false;
            if ($school !== []) {
                $ok = (bool) safe_db_run(
                    'UPDATE schools SET facilities = :fac, classes_offered = :classes, updated_at = NOW() WHERE id = 1',
                    [':fac' => $facJson, ':classes' => $clsJson]
                );
            } else {
                $defaultName = defined('APP_NAME') ? (string) APP_NAME : 'Preschool';
                $ok = (bool) safe_db_run(
                    'INSERT INTO schools (id, name, facilities, classes_offered, created_at, updated_at)
                     VALUES (1, :name, :fac, :classes, NOW(), NOW())',
                    [':name' => $defaultName, ':fac' => $facJson, ':classes' => $clsJson]
                );
            }
            if ($ok) {
                $success = 'Saved. Parents will see this on the website.';
                $school = safe_db_get_one('SELECT * FROM schools WHERE id = :id LIMIT 1', [':id' => 1]) ?: $school;
                $facilities = [];
                foreach (cms_decode_json_field($school['facilities'] ?? null) as $f) {
                    $row = $normFac($f);
                    if ($row['title'] !== '' || $row['description'] !== '') {
                        $facilities[] = $row;
                    }
                }
                $classes = [];
                foreach (cms_decode_json_field($school['classes_offered'] ?? null) as $c) {
                    $row = $normClass($c);
                    if ($row['name'] !== '') {
                        $classes[] = $row;
                    }
                }
                if ($facilities === []) {
                    $facilities = [['title' => '', 'description' => '']];
                }
                if ($classes === []) {
                    $classes = [['name' => '', 'age' => '', 'fees' => '']];
                }
            } else {
                $errors[] = 'Could not save. Try again.';
            }
        }
    }
}

$publicFac = function_exists('site_url') ? site_url('/#facilities') : '/#facilities';
$inr = static function ($n): string {
    if ($n === '' || $n === null) {
        return '';
    }
    $f = (float) $n;
    if ($f <= 0) {
        return '';
    }
    return function_exists('format_money') ? format_money($f) : ('₹ ' . number_format($f, 0));
};

require_once __DIR__ . '/../header.php';
?>
<style>
.fc-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px 18px; margin-bottom:14px; }
.fc-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px 18px; margin-bottom:12px; }
.fc-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin-bottom:10px; }
.fc-row { border:1px dashed #dbe7fb; border-radius:14px; padding:12px; margin-bottom:8px; background:#f8fafc; }
.fc-preview { background:#f8fafc; border-radius:14px; padding:14px; }
.fc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; }
.fc-tile { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px; }
.fc-bar { position:sticky; bottom:0; background:#fff; border-top:1px solid #dbe7fb; padding:10px 0; z-index:2; }
</style>

<div class="fc-hero d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <div class="fw-bold" style="font-size:1.15rem">Facilities &amp; classes</div>
    <div class="text-muted">What parents read on the website — not the class list inside the app.</div>
  </div>
  <a class="btn btn-outline-primary" href="<?php echo e($publicFac); ?>" target="_blank" rel="noopener">See on website</a>
</div>

<?php if ($success !== ''): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<form method="post">
  <input type="hidden" name="action" value="save_facilities">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">

  <div class="fc-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fc-sec mb-0">School facilities</div>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addFac">Add one more</button>
    </div>
    <div class="form-text mb-2">CCTV, outdoor play, meals, transport — title plus one short line.</div>
    <div id="facList">
      <?php foreach ($facilities as $f): ?>
        <div class="fc-row">
          <div class="row g-2">
            <div class="col-md-4">
              <input name="fac_title[]" class="form-control" placeholder="Title" value="<?php echo e($f['title']); ?>">
            </div>
            <div class="col-md-7">
              <input name="fac_desc[]" class="form-control" placeholder="One short line" value="<?php echo e($f['description']); ?>">
            </div>
            <div class="col-md-1">
              <button type="button" class="btn btn-outline-secondary w-100" onclick="removeRow(this,'.fc-row','facList')">×</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fc-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fc-sec mb-0">Classes on the website</div>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addCls">Add class</button>
    </div>
    <div class="form-text mb-2">Nursery, LKG, UKG — age and yearly fees if you want them public. Leave fees blank to hide the amount.</div>
    <div id="clsList">
      <?php foreach ($classes as $c): ?>
        <div class="fc-row fc-cls">
          <div class="row g-2">
            <div class="col-md-4">
              <input name="class_name[]" class="form-control" placeholder="Class name" value="<?php echo e($c['name']); ?>">
            </div>
            <div class="col-md-4">
              <input name="class_age[]" class="form-control" placeholder="Age, e.g. 3–4 years" value="<?php echo e($c['age']); ?>">
            </div>
            <div class="col-md-3">
              <input name="class_fees[]" class="form-control" inputmode="numeric" placeholder="Fees (optional)" value="<?php echo e((string) $c['fees']); ?>">
            </div>
            <div class="col-md-1">
              <button type="button" class="btn btn-outline-secondary w-100" onclick="removeRow(this,'.fc-cls','clsList')">×</button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fc-card">
    <div class="fc-sec">How it looks</div>
    <div class="fc-preview">
      <div class="fw-bold mb-2">Facilities</div>
      <div class="fc-grid mb-3">
        <?php
        $shownFac = array_values(array_filter($facilities, static fn(array $r): bool => $r['title'] !== '' || $r['description'] !== ''));
        ?>
        <?php if ($shownFac === []): ?>
          <div class="text-muted">Add a facility and save to preview.</div>
        <?php else: ?>
          <?php foreach ($shownFac as $f): ?>
            <div class="fc-tile">
              <div class="fw-bold"><?php echo e($f['title'] !== '' ? $f['title'] : 'Facility'); ?></div>
              <div class="small text-muted"><?php echo e($f['description']); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="fw-bold mb-2">Classes</div>
      <div class="fc-grid">
        <?php
        $shownCls = array_values(array_filter($classes, static fn(array $r): bool => $r['name'] !== ''));
        ?>
        <?php if ($shownCls === []): ?>
          <div class="text-muted">Add a class and save to preview.</div>
        <?php else: ?>
          <?php foreach ($shownCls as $c): ?>
            <div class="fc-tile">
              <div class="fw-bold"><?php echo e($c['name']); ?></div>
              <div class="small text-muted"><?php echo e($c['age']); ?></div>
              <?php $feeTxt = $inr($c['fees']); ?>
              <?php if ($feeTxt !== ''): ?><div class="small fw-bold mt-1"><?php echo e($feeTxt); ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="fc-bar">
    <button class="btn btn-success" type="submit">Save</button>
  </div>
</form>

<script>
document.getElementById('addFac').addEventListener('click', function () {
  var wrap = document.getElementById('facList');
  var div = document.createElement('div');
  div.className = 'fc-row';
  div.innerHTML = '<div class="row g-2"><div class="col-md-4"><input name="fac_title[]" class="form-control" placeholder="Title"></div><div class="col-md-7"><input name="fac_desc[]" class="form-control" placeholder="One short line"></div><div class="col-md-1"><button type="button" class="btn btn-outline-secondary w-100" onclick="removeRow(this,\'.fc-row\',\'facList\')">×</button></div></div>';
  wrap.appendChild(div);
  div.querySelector('input').focus();
});
document.getElementById('addCls').addEventListener('click', function () {
  var wrap = document.getElementById('clsList');
  var div = document.createElement('div');
  div.className = 'fc-row fc-cls';
  div.innerHTML = '<div class="row g-2"><div class="col-md-4"><input name="class_name[]" class="form-control" placeholder="Class name"></div><div class="col-md-4"><input name="class_age[]" class="form-control" placeholder="Age, e.g. 3–4 years"></div><div class="col-md-3"><input name="class_fees[]" class="form-control" inputmode="numeric" placeholder="Fees (optional)"></div><div class="col-md-1"><button type="button" class="btn btn-outline-secondary w-100" onclick="removeRow(this,\'.fc-cls\',\'clsList\')">×</button></div></div>';
  wrap.appendChild(div);
  div.querySelector('input').focus();
});
function removeRow(btn, sel, wrapId) {
  var item = btn.closest(sel);
  var wrap = document.getElementById(wrapId);
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
