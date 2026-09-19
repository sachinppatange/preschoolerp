<?php
/**
 * teacher/my_classes.php — teacher's classes and children.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$assignedClasses = panel_teacher_assigned_classes($teacherId);

$classRank = static function (array $c): int {
    $n = strtolower((string) ($c['name'] ?? ''));
    if (str_starts_with($n, 'play')) {
        return 1;
    }
    if (str_starts_with($n, 'nurs')) {
        return 2;
    }
    if (str_starts_with($n, 'l')) {
        return 3;
    }
    if (str_starts_with($n, 'u')) {
        return 4;
    }
    return 9;
};
usort($assignedClasses, static function (array $a, array $b) use ($classRank): int {
    $d = $classRank($a) <=> $classRank($b);
    return $d !== 0 ? $d : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$allowedIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $assignedClasses)));
$selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedIds, true)) {
    $selectedClassId = 0;
}

$qraw = trim((string) ($_GET['q'] ?? ''));
$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$statusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";

$counts = [];
if ($allowedIds !== [] && function_exists('table_exists') && table_exists('students')) {
    $in = implode(',', array_map('intval', $allowedIds));
    $rows = safe_db_get_all(
        "SELECT s.class_id, COUNT(*) AS c
         FROM students s
         WHERE s.class_id IN ($in) AND {$statusSql} AND {$ayStu}
         GROUP BY s.class_id",
        function_exists('ay_params_student') ? ay_params_student() : []
    ) ?: [];
    foreach ($rows as $r) {
        $counts[(int) ($r['class_id'] ?? 0)] = (int) ($r['c'] ?? 0);
    }
}

$students = [];
if ($selectedClassId > 0 && function_exists('table_exists') && table_exists('students')) {
    $params = function_exists('ay_params_student') ? ay_params_student([':cid' => $selectedClassId]) : [':cid' => $selectedClassId];
    $searchSql = '';
    if ($qraw !== '') {
        $searchSql = ' AND CONCAT(IFNULL(s.first_name,\'\'), \' \', IFNULL(s.last_name,\'\')) LIKE :q';
        $params[':q'] = '%' . $qraw . '%';
    }
    $students = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.dob, s.father_phone, s.mother_phone, s.photo_path, s.status
         FROM students s
         WHERE s.class_id = :cid AND {$statusSql} AND {$ayStu}{$searchSql}
         ORDER BY s.first_name ASC, s.last_name ASC",
        $params
    ) ?: [];
}

$selectedName = '';
foreach ($assignedClasses as $c) {
    if ((int) $c['id'] === $selectedClassId) {
        $selectedName = trim((string) ($c['name'] ?? ''));
        break;
    }
}

$t = static function (string $path): string {
    return function_exists('site_url') ? site_url('/teacher/' . ltrim($path, '/')) : $path;
};
$assignUrl = function_exists('site_url') ? site_url('/owner/teacher_assign.php') : '../owner/teacher_assign.php';
$ayLabel = function_exists('ay_display_short') ? ay_display_short() : '';

$parentPhone = static function (array $s): string {
    $f = preg_replace('/\D+/', '', (string) ($s['father_phone'] ?? '')) ?? '';
    $m = preg_replace('/\D+/', '', (string) ($s['mother_phone'] ?? '')) ?? '';
    if (strlen($f) >= 10) {
        return substr($f, -10);
    }
    if (strlen($m) >= 10) {
        return substr($m, -10);
    }
    return trim((string) ($s['father_phone'] ?? $s['mother_phone'] ?? ''));
};

$ageLabel = static function (array $s): string {
    $dob = substr((string) ($s['dob'] ?? ''), 0, 10);
    if ($dob === '' || $dob === '0000-00-00') {
        return '';
    }
    try {
        $y = (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
        return $y > 0 ? $y . ' yrs' : '';
    } catch (Throwable $e) {
        return '';
    }
};

$page_title = (function_exists('auth_is_owner_super') && auth_is_owner_super()) ? 'Classes' : 'My Classes';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.mc-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.mc-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.mc-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; }
.mc-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #eef3fb; }
.mc-row:last-child { border-bottom:0; }
.mc-photo { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.mc-name { font-weight:700; color:#1e3a5f; }
.mc-meta { font-size:.82rem; color:#64748b; }
</style>

<?php if (function_exists('auth_is_owner_super') && auth_is_owner_super()): ?>
  <p class="small text-muted mb-2">You are owner — every class is here. Tap a class to open it.</p>
<?php endif; ?>

<?php if ($assignedClasses === []): ?>
  <?php echo function_exists('panel_teacher_empty_classes_html') ? panel_teacher_empty_classes_html() : '<div class="alert alert-info mb-0">No class is assigned yet.</div>'; ?>
<?php else: ?>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        $n = (int) ($counts[$cid] ?? 0);
        ?>
      <a class="mc-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="?class_id=<?php echo $cid; ?>">
        <?php echo e((string) ($c['name'] ?? 'Class')); ?>
        <span><?php echo $n; ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="mc-card">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="h5 mb-0"><?php echo e($selectedName !== '' ? $selectedName : 'Class'); ?></div>
        <div class="mc-meta"><?php echo count($students); ?> child<?php echo count($students) === 1 ? '' : 'ren'; ?><?php echo $ayLabel !== '' ? ' · ' . e($ayLabel) : ''; ?><?php echo $qraw !== '' ? ' · search' : ''; ?></div>
      </div>
      <?php if ($selectedClassId > 0): ?>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-primary" href="<?php echo e($t('attendance_mark.php?class_id=' . $selectedClassId)); ?>">Attendance</a>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($t('homeworks.php?class_id=' . $selectedClassId)); ?>">Homework</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($t('student_remarks.php?class_id=' . $selectedClassId)); ?>">Remarks</a>
        </div>
      <?php endif; ?>
    </div>

    <form method="get" class="d-flex gap-2 mb-3">
      <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <input class="form-control" name="q" value="<?php echo e($qraw); ?>" placeholder="Find a child by name" style="max-width:280px">
      <button class="btn btn-outline-primary" type="submit">Search</button>
      <?php if ($qraw !== ''): ?><a class="btn btn-outline-secondary" href="?class_id=<?php echo (int) $selectedClassId; ?>">Clear</a><?php endif; ?>
    </form>

    <?php if ($students === []): ?>
      <div class="text-muted"><?php echo $qraw !== '' ? 'No child matches that name.' : 'No children in this class for this year.'; ?></div>
    <?php else: ?>
      <?php foreach ($students as $s):
          $sid = (int) $s['id'];
          $name = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
          $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
          $phone = $parentPhone($s);
          $age = $ageLabel($s);
          ?>
        <div class="mc-row">
          <?php if ($photo !== ''): ?>
            <img class="mc-photo" src="<?php echo e($photo); ?>" alt="">
          <?php else: ?>
            <div class="mc-photo"></div>
          <?php endif; ?>
          <div class="flex-grow-1">
            <div class="mc-name"><?php echo e($name !== '' ? $name : ('Child #' . $sid)); ?></div>
            <div class="mc-meta">
              <?php echo $age !== '' ? e($age) : 'Age not set'; ?>
              <?php if ($phone !== ''): ?> · <?php echo e($phone); ?><?php endif; ?>
            </div>
          </div>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($t('student_view.php?id=' . $sid)); ?>">View</a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
