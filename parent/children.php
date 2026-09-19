<?php
/**
 * parent/children.php — your children at preschool.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

$parentId = panel_parent_context_id();
$today = date('Y-m-d');

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

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
    $rows = safe_db_get_all(
        'SELECT id FROM students WHERE ' . implode(' OR ', $or),
        $params
    ) ?: [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
}
$childIds = array_values(array_unique($childIds));

$want = ['id', 'first_name', 'middle_name', 'last_name', 'class_id', 'photo_path', 'dob', 'admission_date', 'allergies', 'status'];
$cols = [];
foreach ($want as $c) {
    if (function_exists('column_exists') && column_exists('students', $c)) {
        $cols[] = 's.`' . $c . '`';
    }
}
if ($cols === []) {
    $cols = ['s.id', 's.first_name', 's.last_name'];
}

$children = [];
if ($childIds !== [] && table_exists('students')) {
    $in = implode(',', array_map('intval', $childIds));
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
    $classSel = table_exists('classes') ? ', c.name AS class_name' : '';
    $rows = safe_db_get_all(
        'SELECT ' . implode(', ', $cols) . $classSel . '
         FROM students s ' . $join . '
         WHERE s.id IN (' . $in . ')'
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

$attToday = [];
if ($children !== [] && table_exists('attendance')) {
    $in = implode(',', array_map(static fn($c) => (int) $c['id'], $children));
    $rows = safe_db_get_all(
        'SELECT student_id, LOWER(status) AS st FROM attendance WHERE `date` = :d AND student_id IN (' . $in . ')',
        [':d' => $today]
    ) ?: [];
    foreach ($rows as $r) {
        $attToday[(int) ($r['student_id'] ?? 0)] = (string) ($r['st'] ?? '');
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
$ageOf = static function (array $s): string {
    $dob = substr((string) ($s['dob'] ?? ''), 0, 10);
    if ($dob === '' || $dob === '0000-00-00') {
        return '';
    }
    try {
        $d = new DateTime($dob);
        $n = (new DateTime('today'))->diff($d);
        if ($n->y < 1) {
            return $n->m . ' mo';
        }
        return $n->y . ($n->m > 0 ? ' yrs ' . $n->m . ' mo' : ' yrs');
    } catch (Throwable $e) {
        return '';
    }
};
$attLabel = static function (string $st): string {
    if (in_array($st, ['present', 'late'], true)) {
        return 'In school today';
    }
    if ($st === 'absent') {
        return 'Absent today';
    }
    if (in_array($st, ['leave', 'excused'], true)) {
        return 'Leave today';
    }
    return '';
};

$page_title = 'My children';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.ch-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:16px; margin-bottom:14px; }
.ch-top { display:flex; gap:14px; align-items:flex-start; }
.ch-photo { width:72px; height:72px; border-radius:18px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.ch-name { font-weight:800; font-size:1.15rem; color:#1e3a5f; }
.ch-meta { font-size:.9rem; color:#64748b; }
.ch-pill { display:inline-block; border-radius:999px; padding:.2rem .6rem; font-size:.75rem; font-weight:700; margin-top:.35rem; }
.ch-pill.in { background:#dcfce7; color:#166534; }
.ch-pill.out { background:#fee2e2; color:#991b1b; }
.ch-pill.leave { background:#ffedd5; color:#9a3412; }
.ch-btns { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.ch-alert { background:#fff7ed; border-radius:10px; padding:8px 10px; margin-top:10px; font-size:.88rem; color:#9a3412; }
</style>

<p class="text-muted mb-3">Your children at school. Open attendance, homework or fees for each child.</p>

<?php if ($parentId <= 0): ?>
  <?php /* owner gate already shown */ ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office to connect your child.</div>
<?php else: ?>
  <?php foreach ($children as $c):
      $cid = (int) ($c['id'] ?? 0);
      $nm = $nameOf($c);
      $photo = function_exists('student_photo_url') ? student_photo_url((string) ($c['photo_path'] ?? '')) : '';
      $class = trim((string) ($c['class_name'] ?? ''));
      $age = $ageOf($c);
      $st = $attToday[$cid] ?? '';
      $attTxt = $attLabel($st);
      $pill = in_array($st, ['present', 'late'], true) ? 'in' : ($st === 'absent' ? 'out' : ($st !== '' ? 'leave' : ''));
      $allergy = trim((string) ($c['allergies'] ?? ''));
      ?>
    <div class="ch-card">
      <div class="ch-top">
        <?php if ($photo !== ''): ?>
          <img class="ch-photo" src="<?php echo e($photo); ?>" alt="">
        <?php else: ?>
          <div class="ch-photo"></div>
        <?php endif; ?>
        <div>
          <div class="ch-name"><?php echo e($nm !== '' ? $nm : ('Child #' . $cid)); ?></div>
          <div class="ch-meta">
            <?php echo e($class !== '' ? $class : 'Class not set'); ?>
            <?php echo $age !== '' ? ' · ' . e($age) : ''; ?>
          </div>
          <?php if ($attTxt !== ''): ?>
            <span class="ch-pill <?php echo e($pill); ?>"><?php echo e($attTxt); ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($allergy !== '' && strtolower($allergy) !== 'none' && $allergy !== '—'): ?>
        <div class="ch-alert">Allergy: <?php echo e($allergy); ?></div>
      <?php endif; ?>
      <div class="ch-btns">
        <a class="btn btn-success" href="<?php echo e($u('/parent/attendance.php') . '?student_id=' . $cid); ?>">Attendance</a>
        <a class="btn btn-outline-primary" href="<?php echo e($u('/parent/homeworks.php') . '?student_id=' . $cid); ?>">Homework</a>
        <a class="btn btn-outline-primary" href="<?php echo e($u('/parent/fees.php') . '?student_id=' . $cid); ?>">Fees</a>
        <a class="btn btn-outline-secondary" href="<?php echo e($u('/parent/gallery.php')); ?>">Photos</a>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
