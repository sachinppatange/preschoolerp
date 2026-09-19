<?php
/**
 * parent/attendance.php — did my child come to school?
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

$parentId = panel_parent_context_id();
$today = date('Y-m-d');
$tableOk = table_exists('attendance');

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
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.photo_path{$classSel}
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

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthTs = strtotime($monthStart) ?: time();
$daysInMonth = (int) date('t', $monthTs);
$monthEnd = $month . '-' . sprintf('%02d', $daysInMonth);
if (function_exists('ay_limit_dates')) {
    $lim = ay_limit_dates($monthStart, $monthEnd);
    $monthStart = (string) ($lim[0] ?? $monthStart);
    $monthEnd = (string) ($lim[1] ?? $monthEnd);
}
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month') ?: time());
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month') ?: time());
$monthLabel = date('F Y', $monthTs);

$nameOf = static function (array $s): string {
    if (function_exists('student_full_name')) {
        $n = trim(student_full_name($s));
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
};
$norm = static function (string $raw): string {
    $s = strtolower(trim($raw));
    if (in_array($s, ['present', 'p', 'late'], true)) {
        return 'present';
    }
    if (in_array($s, ['absent', 'a'], true)) {
        return 'absent';
    }
    if (in_array($s, ['leave', 'excused'], true)) {
        return 'leave';
    }
    return $s !== '' ? 'leave' : '';
};

$byDay = [];
$counts = ['present' => 0, 'absent' => 0, 'leave' => 0];
$todayStatus = '';
if ($tableOk && $selectedId > 0) {
    $rows = safe_db_get_all(
        'SELECT `date`, status FROM attendance
         WHERE student_id = :sid AND `date` BETWEEN :a AND :b
         ORDER BY `date` ASC',
        [':sid' => $selectedId, ':a' => $monthStart, ':b' => $monthEnd]
    ) ?: [];
    foreach ($rows as $r) {
        $d = substr((string) ($r['date'] ?? ''), 0, 10);
        $st = $norm((string) ($r['status'] ?? ''));
        if ($d === '' || $st === '') {
            continue;
        }
        $byDay[$d] = $st;
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
    }
    $todayRow = safe_db_get_one(
        'SELECT status FROM attendance WHERE student_id = :sid AND `date` = :d LIMIT 1',
        [':sid' => $selectedId, ':d' => $today]
    );
    if ($todayRow) {
        $todayStatus = $norm((string) ($todayRow['status'] ?? ''));
    }
}

$selected = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $selectedId) {
        $selected = $c;
        break;
    }
}
$selectedName = $selected ? $nameOf($selected) : 'Your child';
$photo = $selected && function_exists('student_photo_url')
    ? student_photo_url((string) ($selected['photo_path'] ?? ''))
    : '';

$todayText = 'Not marked yet';
$todayClass = 'none';
if ($todayStatus === 'present') {
    $todayText = 'In school today';
    $todayClass = 'in';
} elseif ($todayStatus === 'absent') {
    $todayText = 'Absent today';
    $todayClass = 'out';
} elseif ($todayStatus === 'leave') {
    $todayText = 'Leave today';
    $todayClass = 'leave';
}

$firstDow = (int) date('N', strtotime($month . '-01') ?: time()); // 1=Mon
$qs = static function (int $sid, string $m): string {
    return '?student_id=' . $sid . '&month=' . rawurlencode($m);
};

$page_title = 'Attendance';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.att-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.att-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.att-chip img, .att-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.att-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.att-hero.in { border-color:#86efac; background:#f0fdf4; }
.att-hero.out { border-color:#fca5a5; background:#fef2f2; }
.att-hero.leave { border-color:#fdba74; background:#fff7ed; }
.att-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; }
.att-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.att-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:14px 16px; margin-bottom:12px; }
.att-stat { flex:1; min-width:90px; text-align:center; }
.att-stat .n { font-size:1.4rem; font-weight:800; }
.att-cal { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
.att-cal .hd { text-align:center; font-size:.7rem; font-weight:700; color:#94a3b8; }
.att-day { aspect-ratio:1; border-radius:10px; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; background:#f8fafc; color:#64748b; }
.att-day.p { background:#dcfce7; color:#166534; }
.att-day.a { background:#fee2e2; color:#991b1b; }
.att-day.l { background:#ffedd5; color:#9a3412; }
.att-day.today { outline:2px solid #1d4ed8; }
.att-day.empty { background:transparent; }
</style>

<p class="text-muted mb-2">See if your child came to school. Green = present, red = absent.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Attendance is not set up yet.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="att-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $month)); ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="att-hero <?php echo e($todayClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="att-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="att-photo"></div>
    <?php endif; ?>
    <div>
      <div class="att-big"><?php echo e($todayText); ?></div>
      <div class="text-muted"><?php echo e($selectedName); ?> · <?php echo e(date('D, d M Y')); ?></div>
    </div>
  </div>

  <div class="att-card">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedId, $prevMonth)); ?>">←</a>
      <span class="fw-bold"><?php echo e($monthLabel); ?></span>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedId, $nextMonth)); ?>">→</a>
      <?php if ($month !== date('Y-m')): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($selectedId, date('Y-m'))); ?>">This month</a>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <div class="att-stat"><div class="n text-success"><?php echo (int) $counts['present']; ?></div><div class="small text-muted">Present</div></div>
      <div class="att-stat"><div class="n text-danger"><?php echo (int) $counts['absent']; ?></div><div class="small text-muted">Absent</div></div>
      <div class="att-stat"><div class="n" style="color:#d97706"><?php echo (int) $counts['leave']; ?></div><div class="small text-muted">Leave</div></div>
    </div>
    <div class="att-cal">
      <?php foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $h): ?>
        <div class="hd"><?php echo e($h); ?></div>
      <?php endforeach; ?>
      <?php for ($i = 1; $i < $firstDow; $i++): ?>
        <div class="att-day empty"></div>
      <?php endfor; ?>
      <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $d = $month . '-' . sprintf('%02d', $day);
          $st = $byDay[$d] ?? '';
          $cls = $st === 'present' ? 'p' : ($st === 'absent' ? 'a' : ($st === 'leave' ? 'l' : ''));
          $isToday = $d === $today;
          $letter = $st === 'present' ? 'P' : ($st === 'absent' ? 'A' : ($st === 'leave' ? 'L' : ''));
          ?>
        <div class="att-day <?php echo e($cls); ?><?php echo $isToday ? ' today' : ''; ?>" title="<?php echo e($d); ?>">
          <span><?php echo (int) $day; ?></span>
          <?php if ($letter !== ''): ?><span style="font-size:.65rem"><?php echo e($letter); ?></span><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <div class="small text-muted mt-2">P = present · A = absent · L = leave · empty = not marked</div>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
