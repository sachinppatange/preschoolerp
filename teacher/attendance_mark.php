<?php
/**
 * teacher/attendance_mark.php — mark who came today.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$validStatuses = ['present', 'absent', 'late', 'excused'];
$today = date('Y-m-d');
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
$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedIds, true)) {
    $selectedClassId = 0;
}

$selectedDate = trim((string) ($_POST['date'] ?? $_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = $today;
}

$messages = [];
$errors = [];
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$statusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";
$classStudents = static function (int $classId) use ($ayStu, $statusSql): array {
    if ($classId <= 0 || !table_exists('students')) {
        return [];
    }
    $params = function_exists('ay_params_student') ? ay_params_student([':cid' => $classId]) : [':cid' => $classId];
    return safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.photo_path
         FROM students s
         WHERE s.class_id = :cid AND {$statusSql} AND {$ayStu}
         ORDER BY s.first_name ASC, s.last_name ASC",
        $params
    ) ?: [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif ($selectedClassId <= 0) {
        $errors[] = 'Choose a class.';
    } elseif (!table_exists('attendance')) {
        $errors[] = 'Attendance is not set up yet.';
    } else {
        $submitted = isset($_POST['status']) && is_array($_POST['status']) ? $_POST['status'] : [];
        $allowedStudentIds = array_map(static fn($s) => (int) $s['id'], $classStudents($selectedClassId));
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) {
            $errors[] = 'Could not save. Try again.';
        } else {
            try {
                $pdo->beginTransaction();
                $selStmt = $pdo->prepare('SELECT id FROM attendance WHERE class_id = :class_id AND student_id = :student_id AND `date` = :date LIMIT 1');
                $updStmt = $pdo->prepare('UPDATE attendance SET status = :status, recorded_by = :recorded_by WHERE id = :id');
                $insStmt = $pdo->prepare('INSERT INTO attendance (school_id, student_id, class_id, `date`, status, recorded_by, notes, created_at) VALUES (:school_id, :student_id, :class_id, :date, :status, :recorded_by, :notes, NOW())');
                $saved = 0;
                foreach ($allowedStudentIds as $student_id) {
                    if ($student_id <= 0) {
                        continue;
                    }
                    $status = strtolower(trim((string) ($submitted[(string) $student_id] ?? 'present')));
                    if (!in_array($status, $validStatuses, true)) {
                        $status = 'present';
                    }
                    $selStmt->execute([':class_id' => $selectedClassId, ':student_id' => $student_id, ':date' => $selectedDate]);
                    $found = $selStmt->fetch(PDO::FETCH_ASSOC);
                    if ($found && !empty($found['id'])) {
                        $updStmt->execute([':status' => $status, ':recorded_by' => $teacherId, ':id' => $found['id']]);
                    } else {
                        $srow = safe_db_get_one('SELECT school_id FROM students WHERE id = :id LIMIT 1', [':id' => $student_id]);
                        $insStmt->execute([
                            ':school_id' => $srow['school_id'] ?? null,
                            ':student_id' => $student_id,
                            ':class_id' => $selectedClassId,
                            ':date' => $selectedDate,
                            ':status' => $status,
                            ':recorded_by' => $teacherId,
                            ':notes' => '',
                        ]);
                    }
                    $saved++;
                }
                $pdo->commit();
                header('Location: ?class_id=' . $selectedClassId . '&date=' . rawurlencode($selectedDate) . '&saved=' . $saved);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $DEBUG ? ('Could not save: ' . $e->getMessage()) : 'Could not save attendance. Try again.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Attendance saved.';
}

$students = $selectedClassId > 0 ? $classStudents($selectedClassId) : [];
$existing = [];
if ($students !== [] && table_exists('attendance')) {
    $ids = array_map(static fn($s) => (int) $s['id'], $students);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = safe_db_get_all(
        "SELECT student_id, status FROM attendance WHERE class_id = ? AND `date` = ? AND student_id IN ($ph)",
        array_merge([$selectedClassId, $selectedDate], $ids)
    ) ?: [];
    foreach ($rows as $r) {
        $existing[(int) $r['student_id']] = strtolower((string) ($r['status'] ?? 'present'));
    }
}

$selectedName = '';
foreach ($assignedClasses as $c) {
    if ((int) $c['id'] === $selectedClassId) {
        $selectedName = (string) ($c['name'] ?? '');
        break;
    }
}

$focusId = (int) ($_GET['student_id'] ?? 0);
$already = $existing !== [];
$dateLabel = $selectedDate === $today ? 'Today' : date('d M Y', strtotime($selectedDate) ?: time());
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day') ?: time());
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day') ?: time());
$qs = static function (int $classId, string $date): string {
    return '?class_id=' . $classId . '&date=' . rawurlencode($date);
};

$page_title = 'Attendance';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.att-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.att-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.att-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; }
.att-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #eef3fb; flex-wrap:wrap; }
.att-row:last-child { border-bottom:0; }
.att-row.focus { background:#eff6ff; margin:0 -8px; padding:10px 8px; border-radius:12px; }
.att-photo { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.att-name { font-weight:700; color:#1e3a5f; min-width:120px; flex:1; }
.att-btns { display:flex; gap:6px; flex-wrap:wrap; }
.att-btns label { margin:0; }
.att-btns input { position:absolute; opacity:0; pointer-events:none; }
.att-btns span { display:inline-block; border-radius:999px; padding:.35rem .7rem; font-size:.82rem; font-weight:700; border:1px solid #cbd5e1; color:#475569; cursor:pointer; user-select:none; }
.att-btns input:checked + span.p { background:#16a34a; border-color:#16a34a; color:#fff; }
.att-btns input:checked + span.a { background:#dc2626; border-color:#dc2626; color:#fff; }
.att-btns input:checked + span.l { background:#d97706; border-color:#d97706; color:#fff; }
.att-sticky { position:sticky; bottom:12px; background:#1e3a5f; color:#fff; border-radius:14px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; z-index:5; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <div class="alert alert-info mb-0">No class is assigned yet. Ask the owner to assign you a class.</div>
<?php else: ?>

  <div class="mb-2">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="att-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $selectedDate)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $prevDate)); ?>">←</a>
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <input type="date" name="date" class="form-control form-control-sm" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()" style="max-width:160px">
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $nextDate)); ?>">→</a>
    <?php if ($selectedDate !== $today): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($selectedClassId, $today)); ?>">Today</a>
    <?php endif; ?>
    <span class="text-muted small"><?php echo e($dateLabel); ?><?php echo $already ? ' · already marked' : ''; ?></span>
  </div>

  <?php if ($students === []): ?>
    <div class="text-muted">No children in this class for this year.</div>
  <?php else: ?>
    <form method="post" id="attForm">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <input type="hidden" name="date" value="<?php echo e($selectedDate); ?>">

      <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-sm btn-success" id="allPresent">All present</button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="allAbsent">All absent</button>
      </div>

      <div class="att-card mb-3">
        <div class="fw-bold mb-2"><?php echo e($selectedName !== '' ? $selectedName : 'Class'); ?> · <?php echo count($students); ?> children</div>
        <?php foreach ($students as $s):
            $sid = (int) $s['id'];
            $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
            $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
            $st = $existing[$sid] ?? 'present';
            if (!in_array($st, $validStatuses, true)) {
                $st = 'present';
            }
            $leaveVal = ($st === 'late') ? 'late' : 'excused';
            $leaveOn = ($st === 'excused' || $st === 'late');
            ?>
          <div class="att-row<?php echo $focusId === $sid ? ' focus' : ''; ?>" id="stu-<?php echo $sid; ?>">
            <?php if ($photo !== ''): ?>
              <img class="att-photo" src="<?php echo e($photo); ?>" alt="">
            <?php else: ?>
              <div class="att-photo"></div>
            <?php endif; ?>
            <div class="att-name"><?php echo e($nm !== '' ? $nm : ('Child #' . $sid)); ?></div>
            <div class="att-btns" role="group" aria-label="Attendance">
              <label>
                <input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="present" <?php echo $st === 'present' ? 'checked' : ''; ?>>
                <span class="p">Present</span>
              </label>
              <label>
                <input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="absent" <?php echo $st === 'absent' ? 'checked' : ''; ?>>
                <span class="a">Absent</span>
              </label>
              <label>
                <input class="att-status" type="radio" name="status[<?php echo $sid; ?>]" value="<?php echo e($leaveVal); ?>" <?php echo $leaveOn ? 'checked' : ''; ?>>
                <span class="l"><?php echo $st === 'late' ? 'Late' : 'Leave'; ?></span>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="att-sticky">
        <div class="small" id="attCount">Tap Present or Absent, then save.</div>
        <button type="submit" class="btn btn-light">Save attendance</button>
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
  var focus = document.querySelector('.att-row.focus');
  if (focus) focus.scrollIntoView({ block: 'center' });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
