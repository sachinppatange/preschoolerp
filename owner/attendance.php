<?php
/**
 * owner/attendance.php — who came on this date (owner check).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

$ownerId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$today = date('Y-m-d');
$tableOk = table_exists('attendance');

$uiStatuses = ['present', 'absent', 'leave'];
$normStatus = static function (string $raw): string {
    $s = strtolower(trim($raw));
    if (in_array($s, ['present', 'late'], true)) {
        return 'present';
    }
    if ($s === 'absent') {
        return 'absent';
    }
    if (in_array($s, ['leave', 'excused'], true)) {
        return 'leave';
    }
    return 'present';
};

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

$classes = [];
if (table_exists('classes')) {
    $classes = safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [];
    usort($classes, static function (array $a, array $b) use ($classRank): int {
        $d = $classRank($a) <=> $classRank($b);
        return $d !== 0 ? $d : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
}

$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
$classIds = array_map(static fn($c) => (int) ($c['id'] ?? 0), $classes);
if ($selectedClassId > 0 && !in_array($selectedClassId, $classIds, true)) {
    $selectedClassId = 0;
}

$selectedDate = trim((string) ($_POST['date'] ?? $_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}

$messages = [];
$errors = [];

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$statusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";
$schoolSql = '';
$baseParams = [];
if (function_exists('column_exists') && column_exists('students', 'school_id') && $schoolId > 0) {
    $schoolSql = ' AND s.school_id = :sid';
    $baseParams[':sid'] = $schoolId;
}

$loadStudents = static function (int $classId) use ($ayStu, $statusSql, $schoolSql, $baseParams): array {
    if (!table_exists('students')) {
        return [];
    }
    $where = "WHERE {$statusSql} AND {$ayStu}{$schoolSql}";
    $params = function_exists('ay_params_student') ? ay_params_student($baseParams) : $baseParams;
    if ($classId > 0) {
        $where .= ' AND s.class_id = :cid';
        $params[':cid'] = $classId;
    }
    return safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.photo_path, s.class_id, s.school_id
         FROM students s
         {$where}
         ORDER BY s.first_name ASC, s.last_name ASC",
        $params
    ) ?: [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$tableOk) {
        $errors[] = 'Attendance is not set up yet.';
    } elseif ($selectedClassId <= 0) {
        $errors[] = 'Open one class to save corrections.';
    } else {
        $submitted = isset($_POST['status']) && is_array($_POST['status']) ? $_POST['status'] : [];
        $allowed = array_map(static fn($s) => (int) $s['id'], $loadStudents($selectedClassId));
        $pdo = function_exists('pdo_connect') ? pdo_connect() : null;
        if (!($pdo instanceof PDO)) {
            $errors[] = 'Could not save. Try again.';
        } else {
            try {
                $pdo->beginTransaction();
                $selStmt = $pdo->prepare('SELECT id FROM attendance WHERE class_id = :class_id AND student_id = :student_id AND `date` = :date LIMIT 1');
                $updStmt = $pdo->prepare('UPDATE attendance SET status = :status, recorded_by = :recorded_by WHERE id = :id');
                $insStmt = $pdo->prepare('INSERT INTO attendance (school_id, student_id, class_id, `date`, status, recorded_by, notes, created_at) VALUES (:school_id, :student_id, :class_id, :date, :status, :recorded_by, :notes, NOW())');
                foreach ($allowed as $studentId) {
                    if ($studentId <= 0) {
                        continue;
                    }
                    $status = $normStatus((string) ($submitted[(string) $studentId] ?? 'present'));
                    if (!in_array($status, $uiStatuses, true)) {
                        $status = 'present';
                    }
                    $selStmt->execute([':class_id' => $selectedClassId, ':student_id' => $studentId, ':date' => $selectedDate]);
                    $found = $selStmt->fetch(PDO::FETCH_ASSOC);
                    if ($found && !empty($found['id'])) {
                        $updStmt->execute([':status' => $status, ':recorded_by' => $ownerId, ':id' => $found['id']]);
                    } else {
                        $srow = safe_db_get_one('SELECT school_id FROM students WHERE id = :id LIMIT 1', [':id' => $studentId]);
                        $insStmt->execute([
                            ':school_id' => $srow['school_id'] ?? ($schoolId > 0 ? $schoolId : null),
                            ':student_id' => $studentId,
                            ':class_id' => $selectedClassId,
                            ':date' => $selectedDate,
                            ':status' => $status,
                            ':recorded_by' => $ownerId,
                            ':notes' => '',
                        ]);
                    }
                }
                $pdo->commit();
                header('Location: ?class_id=' . $selectedClassId . '&date=' . rawurlencode($selectedDate) . '&saved=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $DEBUG ? ('Could not save: ' . $e->getMessage()) : 'Could not save. Try again.';
            }
        }
    }
}

if ((string) ($_GET['action'] ?? '') === 'export' && $tableOk) {
    $students = $loadStudents($selectedClassId);
    $attRows = safe_db_get_all(
        'SELECT student_id, class_id, status FROM attendance WHERE `date` = :d',
        [':d' => $selectedDate]
    ) ?: [];
    $byStu = [];
    foreach ($attRows as $r) {
        $byStu[(int) ($r['student_id'] ?? 0)] = $normStatus((string) ($r['status'] ?? ''));
    }
    $classNames = [];
    foreach ($classes as $c) {
        $classNames[(int) $c['id']] = (string) ($c['name'] ?? '');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_' . $selectedDate . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Class', 'Child', 'Status']);
    foreach ($students as $s) {
        $sid = (int) ($s['id'] ?? 0);
        $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
        $cid = (int) ($s['class_id'] ?? 0);
        $st = $byStu[$sid] ?? 'not marked';
        fputcsv($out, [$selectedDate, $classNames[$cid] ?? '', $nm, $st]);
    }
    fclose($out);
    exit;
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Attendance updated for this class.';
}

$allStudents = $loadStudents(0);
$attRows = [];
if ($tableOk) {
    $attRows = safe_db_get_all(
        'SELECT student_id, class_id, status FROM attendance WHERE `date` = :d',
        [':d' => $selectedDate]
    ) ?: [];
}
$byStu = [];
foreach ($attRows as $r) {
    $byStu[(int) ($r['student_id'] ?? 0)] = $normStatus((string) ($r['status'] ?? ''));
}

$counts = ['present' => 0, 'absent' => 0, 'leave' => 0, 'none' => 0];
$byClass = [];
foreach ($classes as $c) {
    $cid = (int) ($c['id'] ?? 0);
    $byClass[$cid] = [
        'name' => (string) ($c['name'] ?? 'Class'),
        'students' => [],
        'present' => 0,
        'absent' => 0,
        'leave' => 0,
        'none' => 0,
    ];
}
foreach ($allStudents as $s) {
    $sid = (int) ($s['id'] ?? 0);
    $cid = (int) ($s['class_id'] ?? 0);
    $st = $byStu[$sid] ?? '';
    $key = $st !== '' ? $st : 'none';
    $counts[$key] = ($counts[$key] ?? 0) + 1;
    if (!isset($byClass[$cid])) {
        $byClass[$cid] = [
            'name' => 'Class',
            'students' => [],
            'present' => 0,
            'absent' => 0,
            'leave' => 0,
            'none' => 0,
        ];
    }
    $byClass[$cid]['students'][] = $s;
    $byClass[$cid][$key]++;
}

$recentDates = [];
if ($tableOk) {
    $recentDates = safe_db_get_all(
        'SELECT `date` AS d, COUNT(*) AS c
         FROM attendance
         GROUP BY `date`
         ORDER BY `date` DESC
         LIMIT 14'
    ) ?: [];
}

$viewStudents = $selectedClassId > 0
    ? ($byClass[$selectedClassId]['students'] ?? [])
    : [];

$selectedName = $selectedClassId > 0 ? (string) ($byClass[$selectedClassId]['name'] ?? '') : 'All classes';
$dateLabel = $selectedDate === $today ? 'Today' : date('D, d M Y', strtotime($selectedDate) ?: time());
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day') ?: time());
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day') ?: time());
$qs = static function (int $classId, string $date): string {
    $q = '?date=' . rawurlencode($date);
    if ($classId > 0) {
        $q .= '&class_id=' . $classId;
    }
    return $q;
};
$totalChildren = count($allStudents);
$marked = $counts['present'] + $counts['absent'] + $counts['leave'];

$page_title = 'Who came today';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.att-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.att-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.att-stat { background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:12px 14px; min-width:110px; flex:1; }
.att-stat .n { font-size:1.5rem; font-weight:800; line-height:1.1; }
.att-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:12px; }
.att-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #eef3fb; flex-wrap:wrap; }
.att-row:last-child { border-bottom:0; }
.att-photo { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.att-name { font-weight:700; color:#1e3a5f; min-width:120px; flex:1; }
.att-btns { display:flex; gap:6px; flex-wrap:wrap; }
.att-btns label { margin:0; }
.att-btns input { position:absolute; opacity:0; pointer-events:none; }
.att-btns span { display:inline-block; border-radius:999px; padding:.35rem .7rem; font-size:.82rem; font-weight:700; border:1px solid #cbd5e1; color:#475569; cursor:pointer; user-select:none; }
.att-btns input:checked + span.p { background:#16a34a; border-color:#16a34a; color:#fff; }
.att-btns input:checked + span.a { background:#dc2626; border-color:#dc2626; color:#fff; }
.att-btns input:checked + span.l { background:#d97706; border-color:#d97706; color:#fff; }
.att-badge { display:inline-block; border-radius:999px; padding:.2rem .55rem; font-size:.75rem; font-weight:700; }
.att-badge.p { background:#dcfce7; color:#166534; }
.att-badge.a { background:#fee2e2; color:#991b1b; }
.att-badge.l { background:#ffedd5; color:#9a3412; }
.att-badge.n { background:#f1f5f9; color:#64748b; }
.att-datechip { display:inline-block; border:1px solid #e2e8f0; border-radius:10px; padding:.25rem .6rem; font-size:.82rem; text-decoration:none; color:#334155; margin:0 .35rem .35rem 0; background:#f8fafc; }
.att-datechip.active { border-color:#1d4ed8; color:#1d4ed8; background:#eff6ff; font-weight:700; }
.att-sticky { position:sticky; bottom:12px; background:#1e3a5f; color:#fff; border-radius:14px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; z-index:5; }
@media print {
  .att-sticky, .btn, form#attForm .d-flex.gap-2, a.att-chip, a.att-datechip, .no-print { display:none !important; }
}
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Attendance is not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">Check <strong>who came to school</strong> on a date. Teachers mark the class; you can open a class and correct it.</p>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3 no-print">
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $prevDate)); ?>">←</a>
    <form method="get" class="d-flex gap-2 align-items-center">
      <?php if ($selectedClassId > 0): ?>
        <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <?php endif; ?>
      <input type="date" name="date" class="form-control form-control-sm" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()" style="max-width:160px">
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $nextDate)); ?>">→</a>
    <?php if ($selectedDate !== $today): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($selectedClassId, $today)); ?>">Today</a>
    <?php endif; ?>
    <span class="text-muted small"><?php echo e($dateLabel); ?></span>
    <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?php echo e($qs($selectedClassId, $selectedDate)); ?>&amp;action=export">CSV</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>
  </div>

  <?php if ($recentDates !== []): ?>
    <div class="mb-3 no-print">
      <?php foreach ($recentDates as $od):
          $d = substr((string) ($od['d'] ?? ''), 0, 10);
          if ($d === '') {
              continue;
          }
          $lab = $d === $today ? 'Today' : date('d M', strtotime($d) ?: time());
          ?>
        <a class="att-datechip<?php echo $d === $selectedDate ? ' active' : ''; ?>" href="<?php echo e($qs($selectedClassId, $d)); ?>"><?php echo e($lab); ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <div class="att-stat"><div class="n text-success"><?php echo (int) $counts['present']; ?></div><div class="small text-muted">Present</div></div>
    <div class="att-stat"><div class="n text-danger"><?php echo (int) $counts['absent']; ?></div><div class="small text-muted">Absent</div></div>
    <div class="att-stat"><div class="n" style="color:#d97706"><?php echo (int) $counts['leave']; ?></div><div class="small text-muted">Leave</div></div>
    <div class="att-stat"><div class="n text-secondary"><?php echo (int) $counts['none']; ?></div><div class="small text-muted">Not marked</div></div>
  </div>
  <div class="small text-muted mb-3"><?php echo (int) $marked; ?> of <?php echo (int) $totalChildren; ?> children marked on <?php echo e($dateLabel); ?>.</div>

  <div class="mb-3 no-print">
    <a class="att-chip<?php echo $selectedClassId === 0 ? ' active' : ''; ?>" href="<?php echo e($qs(0, $selectedDate)); ?>">All classes</a>
    <?php foreach ($classes as $c):
        $cid = (int) $c['id'];
        $n = (int) ($byClass[$cid]['none'] ?? 0);
        $tot = count($byClass[$cid]['students'] ?? []);
        $lab = (string) ($c['name'] ?? 'Class');
        if ($tot > 0) {
            $lab .= ' · ' . ($tot - $n) . '/' . $tot;
        }
        ?>
      <a class="att-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $selectedDate)); ?>"><?php echo e($lab); ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($selectedClassId === 0): ?>
    <?php if ($classes === []): ?>
      <div class="text-muted">No classes yet.</div>
    <?php else: ?>
      <?php foreach ($classes as $c):
          $cid = (int) $c['id'];
          $block = $byClass[$cid] ?? null;
          if (!$block) {
              continue;
          }
          $kids = $block['students'];
          ?>
        <div class="att-card">
          <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div class="fw-bold"><?php echo e((string) $block['name']); ?> · <?php echo count($kids); ?></div>
            <div class="small text-muted"><?php echo (int) $block['present']; ?> present · <?php echo (int) $block['absent']; ?> absent · <?php echo (int) $block['leave']; ?> leave<?php echo $block['none'] ? ' · ' . (int) $block['none'] . ' not marked' : ''; ?></div>
          </div>
          <?php if ($kids === []): ?>
            <div class="text-muted small">No children in this class this year.</div>
          <?php else: ?>
            <?php foreach ($kids as $s):
                $sid = (int) $s['id'];
                $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
                $st = $byStu[$sid] ?? '';
                $cls = $st === 'present' ? 'p' : ($st === 'absent' ? 'a' : ($st === 'leave' ? 'l' : 'n'));
                $lab = $st === 'present' ? 'Present' : ($st === 'absent' ? 'Absent' : ($st === 'leave' ? 'Leave' : 'Not marked'));
                ?>
              <div class="att-row">
                <div class="att-name"><?php echo e($nm !== '' ? $nm : ('Child #' . $sid)); ?></div>
                <span class="att-badge <?php echo e($cls); ?>"><?php echo e($lab); ?></span>
              </div>
            <?php endforeach; ?>
            <div class="mt-2 no-print"><a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($cid, $selectedDate)); ?>">Correct this class</a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($viewStudents === []): ?>
      <div class="text-muted">No children in this class for this year.</div>
    <?php else: ?>
      <form method="post" id="attForm">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
        <input type="hidden" name="date" value="<?php echo e($selectedDate); ?>">
        <div class="d-flex flex-wrap gap-2 mb-2 no-print">
          <button type="button" class="btn btn-sm btn-success" id="allPresent">All present</button>
          <button type="button" class="btn btn-sm btn-outline-danger" id="allAbsent">All absent</button>
        </div>
        <div class="att-card">
          <div class="fw-bold mb-2"><?php echo e($selectedName); ?> · <?php echo e($dateLabel); ?></div>
          <?php foreach ($viewStudents as $s):
              $sid = (int) $s['id'];
              $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
              $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
              $st = $byStu[$sid] ?? 'present';
              if (!in_array($st, $uiStatuses, true)) {
                  $st = 'present';
              }
              ?>
            <div class="att-row">
              <?php if ($photo !== ''): ?>
                <img class="att-photo" src="<?php echo e($photo); ?>" alt="">
              <?php else: ?>
                <div class="att-photo"></div>
              <?php endif; ?>
              <div class="att-name"><?php echo e($nm !== '' ? $nm : ('Child #' . $sid)); ?></div>
              <div class="att-btns">
                <label><input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="present" <?php echo $st === 'present' ? 'checked' : ''; ?>><span class="p">Present</span></label>
                <label><input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="absent" <?php echo $st === 'absent' ? 'checked' : ''; ?>><span class="a">Absent</span></label>
                <label><input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="leave" <?php echo $st === 'leave' ? 'checked' : ''; ?>><span class="l">Leave</span></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="att-sticky no-print">
          <div class="small" id="attCount">Correct if needed, then save.</div>
          <button type="submit" class="btn btn-light">Save this class</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>

<script>
(function () {
  function recount() {
    var p = 0, a = 0, l = 0;
    document.querySelectorAll('.att-status:checked').forEach(function (el) {
      if (el.value === 'present') p++;
      else if (el.value === 'absent') a++;
      else l++;
    });
    var el = document.getElementById('attCount');
    if (el) el.textContent = p + ' present · ' + a + ' absent' + (l ? (' · ' + l + ' leave') : '');
  }
  function setAll(val) {
    document.querySelectorAll('.att-row').forEach(function (row) {
      var inp = row.querySelector('.att-status[value="' + val + '"]');
      if (inp) inp.checked = true;
    });
    recount();
  }
  var ap = document.getElementById('allPresent');
  var aa = document.getElementById('allAbsent');
  if (ap) ap.addEventListener('click', function () { setAll('present'); });
  if (aa) aa.addEventListener('click', function () { setAll('absent'); });
  document.querySelectorAll('.att-status').forEach(function (el) {
    el.addEventListener('change', recount);
  });
  recount();
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
