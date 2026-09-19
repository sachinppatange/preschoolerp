<?php
/**
 * parent/gallery.php — class photos from school, by day.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$parentId = panel_parent_context_id();
$today = date('Y-m-d');
$tableOk = table_exists('class_photos');

$childIds = [];
if ($parentId > 0 && table_exists('parents_children')) {
    $maps = safe_db_get_all(
        'SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC',
        [':pid' => $parentId]
    ) ?: [];
    foreach ($maps as $m) {
        $id = (int) ($m['child_student_id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
}
if ($childIds === [] && $parentId > 0 && table_exists('students')) {
    $or = ['parent_id = :pid'];
    $params = [':pid' => $parentId];
    if (function_exists('column_exists') && column_exists('students', 'father_id')) {
        $or[] = 'father_id = :pid';
    }
    if (function_exists('column_exists') && column_exists('students', 'mother_id')) {
        $or[] = 'mother_id = :pid';
    }
    $rows = safe_db_get_all('SELECT id FROM students WHERE ' . implode(' OR ', $or), $params) ?: [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
}
$childIds = array_values(array_unique($childIds));

$children = [];
if ($childIds !== [] && table_exists('students')) {
    $in = implode(',', array_map('intval', $childIds));
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
    $classSel = table_exists('classes') ? ', c.name AS class_name' : '';
    $rows = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id, s.photo_path{$classSel}
         FROM students s {$join}
         WHERE s.id IN ({$in})"
    ) ?: [];
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    foreach ($childIds as $id) {
        if (isset($byId[$id])) {
            $children[] = $byId[$id];
        }
    }
}

$selectedId = (int) ($_GET['student_id'] ?? 0);
if ($selectedId <= 0 && $childIds !== []) {
    $selectedId = $childIds[0];
}
if ($selectedId > 0 && !in_array($selectedId, $childIds, true)) {
    $selectedId = $childIds[0] ?? 0;
}

$selected = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $selectedId) {
        $selected = $c;
        break;
    }
}

$nameOf = static function (array $s): string {
    if (function_exists('student_full_name')) {
        $n = trim(student_full_name($s));
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['middle_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
};

$fmtDay = static function (string $raw): string {
    $d = substr($raw, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $ts = strtotime($d);
    if ($ts === false) {
        return $d;
    }
    if ($d === date('Y-m-d')) {
        return 'Today';
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('D, d M', $ts);
};

$photoUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (function_exists('resolve_image_url')) {
        $u = (string) resolve_image_url($path, '');
        if ($u !== '') {
            return $u;
        }
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (function_exists('asset_url')) {
        return (string) asset_url($path);
    }
    if (function_exists('site_url')) {
        return site_url('/' . ltrim($path, '/'));
    }
    return $path;
};

$classId = $selected ? (int) ($selected['class_id'] ?? 0) : 0;
$className = $selected ? trim((string) ($selected['class_name'] ?? '')) : '';
$childName = $selected ? $nameOf($selected) : 'Your child';
$photo = $selected && function_exists('student_photo_url')
    ? student_photo_url((string) ($selected['photo_path'] ?? ''))
    : '';

$ayFrom = $ayTo = $today;
if (function_exists('ay_limit_dates')) {
    [$ayFrom, $ayTo] = ay_limit_dates('2000-01-01', '2099-12-31');
}

$photoDates = [];
if ($tableOk && $classId > 0) {
    $photoDates = safe_db_get_all(
        'SELECT DATE(uploaded_at) AS d, COUNT(*) AS c
         FROM class_photos
         WHERE class_id = :c
           AND DATE(uploaded_at) BETWEEN :a AND :b
         GROUP BY DATE(uploaded_at)
         ORDER BY d DESC
         LIMIT 30',
        [':c' => $classId, ':a' => $ayFrom, ':b' => $ayTo]
    ) ?: [];
}

$latestDate = '';
foreach ($photoDates as $od) {
    $d = substr((string) ($od['d'] ?? ''), 0, 10);
    if ($d !== '') {
        $latestDate = $d;
        break;
    }
}

$photoDate = substr((string) ($_GET['date'] ?? ''), 0, 10);
if ($photoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $photoDate)) {
    $photoDate = $latestDate !== '' ? $latestDate : $today;
}
if ($photoDate < $ayFrom) {
    $photoDate = $ayFrom;
}
if ($photoDate > $ayTo) {
    $photoDate = $ayTo;
}

$dayPhotos = [];
$groups = [];
if ($tableOk && $classId > 0) {
    $dayPhotos = safe_db_get_all(
        'SELECT id, title, description, file_path, uploaded_at
         FROM class_photos
         WHERE class_id = :c AND DATE(uploaded_at) = :d
         ORDER BY title ASC, id DESC',
        [':c' => $classId, ':d' => $photoDate]
    ) ?: [];
    foreach ($dayPhotos as $p) {
        $g = trim((string) ($p['title'] ?? ''));
        if ($g === '') {
            $g = 'Class photos';
        }
        $groups[$g][] = $p;
    }
}

$countDay = count($dayPhotos);
$dateNice = $fmtDay($photoDate);
$heroText = $countDay === 0 ? 'No photos this day' : ($countDay === 1 ? '1 photo' : ($countDay . ' photos'));
$heroClass = $countDay > 0 ? 'has' : 'none';

$qs = static function (int $sid, string $date): string {
    return '?student_id=' . $sid . '&date=' . rawurlencode($date);
};

$page_title = 'Photos';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.gl-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.gl-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.gl-chip img, .gl-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.gl-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.gl-hero.has { border-color:#c4b5fd; background:#f5f3ff; }
.gl-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.gl-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.gl-datechip { display:inline-block; border:1px solid #e2e8f0; border-radius:10px; padding:.3rem .7rem; font-size:.85rem; text-decoration:none; color:#334155; margin:0 .35rem .4rem 0; background:#fff; }
.gl-datechip.active { border-color:#1d4ed8; color:#1d4ed8; background:#eff6ff; font-weight:700; }
.gl-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:12px 0 8px; }
.gl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; }
.gl-item { border-radius:12px; overflow:hidden; background:#e2e8f0; aspect-ratio:1; cursor:pointer; border:0; padding:0; }
.gl-item img { width:100%; height:100%; object-fit:cover; display:block; }
.gl-full { display:none; position:fixed; inset:0; background:rgba(15,23,42,.88); z-index:1080; align-items:center; justify-content:center; padding:16px; }
.gl-full.show { display:flex; }
.gl-full img { max-width:100%; max-height:88vh; border-radius:12px; }
.gl-full .cap { position:absolute; bottom:18px; left:16px; right:16px; text-align:center; color:#fff; font-weight:600; }
</style>

<p class="text-muted mb-2">Photos from class. Tap a picture to see it large.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Gallery is not set up yet.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="gl-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $photoDate)); ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="gl-hero <?php echo e($heroClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="gl-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="gl-photo"></div>
    <?php endif; ?>
    <div>
      <div class="gl-big"><?php echo e($heroText); ?></div>
      <div class="text-muted"><?php echo e($childName); ?><?php echo $className !== '' ? ' · ' . e($className) : ''; ?> · <?php echo e($dateNice); ?></div>
    </div>
  </div>

  <?php if ($classId <= 0): ?>
    <div class="alert alert-info mb-0">Class is not set for this child. Ask the school office.</div>
  <?php elseif ($photoDates === []): ?>
    <div class="alert alert-info mb-0">No class photos this year yet.</div>
  <?php else: ?>
    <div class="mb-3">
      <?php foreach ($photoDates as $od):
          $d = substr((string) ($od['d'] ?? ''), 0, 10);
          if ($d === '') {
              continue;
          }
          $lab = $fmtDay($d);
          ?>
        <a class="gl-datechip<?php echo $d === $photoDate ? ' active' : ''; ?>" href="<?php echo e($qs($selectedId, $d)); ?>"><?php echo e($lab); ?> · <?php echo (int) ($od['c'] ?? 0); ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($dayPhotos === []): ?>
      <div class="text-muted">No photos on this day. Pick another date above.</div>
    <?php else: ?>
      <?php foreach ($groups as $gName => $list): ?>
        <div class="gl-sec"><?php echo e((string) $gName); ?></div>
        <div class="gl-grid mb-3">
          <?php foreach ($list as $p):
              $url = $photoUrl((string) ($p['file_path'] ?? ''));
              $cap = trim((string) ($p['description'] ?? ''));
              if ($cap === '' || strcasecmp($cap, (string) $gName) === 0) {
                  $cap = (string) $gName;
              }
              if ($url === '') {
                  continue;
              }
              ?>
            <button type="button" class="gl-item" data-src="<?php echo e($url); ?>" data-cap="<?php echo e($cap); ?>">
              <img src="<?php echo e($url); ?>" alt="<?php echo e($cap); ?>" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>

<div class="gl-full" id="glFull" role="dialog" aria-label="Photo">
  <img id="glFullImg" alt="">
  <div class="cap" id="glFullCap"></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var box = document.getElementById('glFull');
  var img = document.getElementById('glFullImg');
  var cap = document.getElementById('glFullCap');
  if (!box || !img) return;
  document.querySelectorAll('.gl-item').forEach(function (btn) {
    btn.addEventListener('click', function () {
      img.src = btn.getAttribute('data-src') || '';
      cap.textContent = btn.getAttribute('data-cap') || '';
      box.classList.add('show');
    });
  });
  box.addEventListener('click', function () {
    box.classList.remove('show');
    img.src = '';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
