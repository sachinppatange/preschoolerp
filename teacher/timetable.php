<?php
/**
 * teacher/timetable.php — weekly class routine for this class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
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

$allowedIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $assignedClasses)));
$canClass = static function (int $classId) use ($allowedIds): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    return in_array($classId, $allowedIds, true);
};

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$todayName = date('l');
$defaultDay = in_array($todayName, $days, true) ? $todayName : 'Monday';

$activities = ['Circle time', 'Free play', 'Snack', 'Outdoor', 'Art', 'Story', 'Nap', 'Music'];

$ttExists = table_exists('timetable');
$hasCol = static function (string $name) use ($ttExists): bool {
    return $ttExists && function_exists('column_exists') && column_exists('timetable', $name);
};

$textCol = null;
foreach (['subject_name', 'subject_text', 'subject'] as $c) {
    if ($hasCol($c)) {
        $textCol = $c;
        break;
    }
}

$subjectTable = null;
$subjectDisplayCol = null;
$subjectIdCol = null;
if (table_exists('subjects')) {
    $subjectTable = 'subjects';
} elseif (table_exists('subject')) {
    $subjectTable = 'subject';
}
if ($subjectTable) {
    $subCols = function_exists('get_table_columns') ? get_table_columns($subjectTable) : [];
    foreach (['name', 'title', 'subject_name', 'subject'] as $c) {
        if (in_array($c, $subCols, true)) {
            $subjectDisplayCol = $c;
            break;
        }
    }
    $subjectIdCol = in_array('id', $subCols, true) ? 'id' : (in_array('subject_id', $subCols, true) ? 'subject_id' : null);
}

$fmtTime = static function (?string $t): string {
    $t = trim((string) $t);
    if ($t === '' || $t === '00:00:00') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $t);
    return $ts ? date('g:i a', $ts) : substr($t, 0, 5);
};

$messages = [];
$errors = [];

$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !$canClass($selectedClassId)) {
    $selectedClassId = 0;
}

$selectedDay = trim((string) ($_POST['day_of_week'] ?? $_GET['day'] ?? $defaultDay));
if (!in_array($selectedDay, $days, true)) {
    $selectedDay = $defaultDay;
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get((string) ($_REQUEST['action'] ?? ''))) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0 && $ttExists) {
    $row = safe_db_get_one('SELECT id, class_id, teacher_id FROM timetable WHERE id = :id LIMIT 1', [':id' => $deleteId]);
    $okDel = $row && $canClass((int) ($row['class_id'] ?? 0));
    if (!$okDel) {
        $errors[] = 'You cannot delete this slot.';
    } elseif (safe_db_run('DELETE FROM timetable WHERE id = :id', [':id' => $deleteId])) {
        $backDay = trim((string) ($_POST['day'] ?? $selectedDay));
        if (!in_array($backDay, $days, true)) {
            $backDay = $selectedDay;
        }
        header('Location: ?class_id=' . (int) ($row['class_id'] ?? 0) . '&day=' . rawurlencode($backDay) . '&deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$ttExists) {
        $errors[] = 'Timetable is not set up yet.';
    } elseif ($selectedClassId <= 0 || !$canClass($selectedClassId)) {
        $errors[] = 'Choose a class.';
    } else {
        $activity = trim((string) ($_POST['activity'] ?? ''));
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        $place = trim((string) ($_POST['room'] ?? ''));
        if ($activity === '') {
            $errors[] = 'Write what the children will do.';
        }
        if ($start === '') {
            $errors[] = 'Pick a start time.';
        } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start)) {
            $errors[] = 'Start time is not valid.';
        }
        if ($end !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            $errors[] = 'End time is not valid.';
        }
        if ($end === '') {
            $end = null;
        }

        if ($errors === []) {
            $useSubjectId = null;
            $useText = $activity;
            if ($textCol === null && $subjectTable && $subjectIdCol && $subjectDisplayCol && $hasCol('subject_id')) {
                $found = safe_db_get_one(
                    "SELECT `{$subjectIdCol}` AS id FROM `{$subjectTable}` WHERE `{$subjectDisplayCol}` = :val LIMIT 1",
                    [':val' => $activity]
                );
                if ($found && !empty($found['id'])) {
                    $useSubjectId = (int) $found['id'];
                } else {
                    try {
                        $pdo = function_exists('pdo_connect') ? pdo_connect() : null;
                        if ($pdo instanceof PDO) {
                            $ins = $pdo->prepare("INSERT INTO `{$subjectTable}` (`{$subjectDisplayCol}`) VALUES (:val)");
                            $ins->execute([':val' => $activity]);
                            $useSubjectId = (int) $pdo->lastInsertId();
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Could not save the activity name.';
                    }
                }
            }

            if ($errors === []) {
                $cols = ['class_id', 'day_of_week'];
                $ph = [':class_id', ':day'];
                $params = [
                    ':class_id' => $selectedClassId,
                    ':day' => $selectedDay,
                ];
                if ($hasCol('start_time')) {
                    $cols[] = 'start_time';
                    $ph[] = ':start_time';
                    $params[':start_time'] = $start;
                }
                if ($hasCol('end_time')) {
                    $cols[] = 'end_time';
                    $ph[] = ':end_time';
                    $params[':end_time'] = $end;
                }
                if ($hasCol('period')) {
                    $cols[] = 'period';
                    $ph[] = ':period';
                    $params[':period'] = $fmtTime($start);
                }
                if ($hasCol('teacher_id')) {
                    $cols[] = 'teacher_id';
                    $ph[] = ':teacher_id';
                    $params[':teacher_id'] = $teacherId > 0 ? $teacherId : null;
                }
                if ($hasCol('room')) {
                    $cols[] = 'room';
                    $ph[] = ':room';
                    $params[':room'] = $place !== '' ? $place : null;
                }
                if ($hasCol('school_id')) {
                    $cols[] = 'school_id';
                    $ph[] = ':school_id';
                    $params[':school_id'] = $schoolId > 0 ? $schoolId : null;
                }
                if ($textCol !== null) {
                    $cols[] = $textCol;
                    $ph[] = ':activity';
                    $params[':activity'] = $useText;
                }
                if ($hasCol('subject_id') && $useSubjectId !== null) {
                    $cols[] = 'subject_id';
                    $ph[] = ':subject_id';
                    $params[':subject_id'] = $useSubjectId;
                }
                if ($hasCol('created_at')) {
                    $cols[] = 'created_at';
                    $ph[] = ':created_at';
                    $params[':created_at'] = date('Y-m-d H:i:s');
                }

                $sql = 'INSERT INTO `timetable` (' . implode(',', array_map(static fn($c) => '`' . $c . '`', $cols)) . ') VALUES (' . implode(',', $ph) . ')';
                if (safe_db_run($sql, $params)) {
                    header('Location: ?class_id=' . $selectedClassId . '&day=' . rawurlencode($selectedDay) . '&saved=1');
                    exit;
                }
                $errors[] = 'Could not save this slot.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Slot added to the weekly routine.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Slot removed.';
}

$weekEntries = [];
$dayEntries = [];
$subjectMap = [];
if ($ttExists && $selectedClassId > 0) {
    $weekEntries = safe_db_get_all(
        "SELECT * FROM timetable
         WHERE class_id = :cid
         ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time ASC, id ASC",
        [':cid' => $selectedClassId]
    ) ?: [];
    $sids = [];
    foreach ($weekEntries as $r) {
        $d = (string) ($r['day_of_week'] ?? '');
        if ($d === $selectedDay) {
            $dayEntries[] = $r;
        }
        $sid = (int) ($r['subject_id'] ?? 0);
        if ($sid > 0) {
            $sids[$sid] = $sid;
        }
    }
    if ($sids !== [] && $subjectTable && $subjectDisplayCol && $subjectIdCol) {
        $in = implode(',', array_map('intval', array_values($sids)));
        $rows = safe_db_get_all("SELECT `{$subjectIdCol}` AS id, `{$subjectDisplayCol}` AS label FROM `{$subjectTable}` WHERE `{$subjectIdCol}` IN ({$in})");
        foreach ($rows ?: [] as $sr) {
            $subjectMap[(int) $sr['id']] = (string) ($sr['label'] ?? '');
        }
    }
}

$activityLabel = static function (array $r) use ($textCol, $subjectMap): string {
    if ($textCol && trim((string) ($r[$textCol] ?? '')) !== '') {
        return trim((string) $r[$textCol]);
    }
    foreach (['subject_name', 'subject_text', 'subject'] as $c) {
        if (!is_numeric((string) ($r[$c] ?? '')) && trim((string) ($r[$c] ?? '')) !== '') {
            return trim((string) $r[$c]);
        }
    }
    $sid = (int) ($r['subject_id'] ?? 0);
    if ($sid > 0 && isset($subjectMap[$sid]) && $subjectMap[$sid] !== '') {
        return $subjectMap[$sid];
    }
    return trim((string) ($r['period'] ?? '')) !== '' ? (string) $r['period'] : 'Activity';
};

$byDay = [];
foreach ($days as $d) {
    $byDay[$d] = [];
}
foreach ($weekEntries as $r) {
    $d = (string) ($r['day_of_week'] ?? '');
    if (isset($byDay[$d])) {
        $byDay[$d][] = $r;
    }
}

$selectedName = '';
foreach ($assignedClasses as $c) {
    if ((int) $c['id'] === $selectedClassId) {
        $selectedName = (string) ($c['name'] ?? '');
        break;
    }
}

$qs = static function (int $classId, string $day): string {
    return '?class_id=' . $classId . '&day=' . rawurlencode($day);
};

$page_title = 'Class routine';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.tt-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; cursor:pointer; }
.tt-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.tt-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.tt-slot { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; padding:10px 0; border-bottom:1px solid #eef2f7; }
.tt-slot:last-child { border-bottom:0; padding-bottom:0; }
.tt-time { font-weight:800; color:#1d4ed8; min-width:7.5rem; }
.tt-act { font-weight:700; color:#1e3a5f; }
.tt-week { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; }
.tt-weekcol { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:8px 10px; }
.tt-weekcol h6 { font-size:.78rem; margin:0 0 6px; color:#64748b; text-transform:uppercase; letter-spacing:.03em; }
.tt-mini { font-size:.8rem; margin-bottom:4px; color:#334155; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <div class="alert alert-info mb-0">No class is assigned yet. Ask the owner to assign you a class.</div>
<?php elseif (!$ttExists): ?>
  <div class="alert alert-warning mb-0">Class routine is not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">This is the <strong>weekly class routine</strong> — what children do each day (circle time, snack, outdoor). Fill it once; change a slot if the day changes.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="tt-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $selectedDay)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <div class="mb-3">
    <?php foreach ($days as $d):
        $short = substr($d, 0, 3);
        $isToday = $d === $todayName;
        ?>
      <a class="tt-chip<?php echo $d === $selectedDay ? ' active' : ''; ?>" href="<?php echo e($qs($selectedClassId, $d)); ?>"><?php echo e($short); ?><?php echo $isToday ? ' · today' : ''; ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="tt-card" id="ttAddForm">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <input type="hidden" name="day_of_week" value="<?php echo e($selectedDay); ?>">
    <div class="fw-bold mb-2">Add a slot · <?php echo e($selectedDay); ?><?php echo $selectedName !== '' ? ' · ' . e($selectedName) : ''; ?></div>
    <div class="mb-2">
      <?php foreach ($activities as $a): ?>
        <button type="button" class="tt-chip act-chip" data-act="<?php echo e($a); ?>"><?php echo e($a); ?></button>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" name="activity" id="activityField" required maxlength="120" placeholder="What will they do?" style="flex:1; min-width:180px">
      <input type="time" name="start_time" class="form-control" required style="max-width:130px" title="Start">
      <input type="time" name="end_time" class="form-control" style="max-width:130px" title="End">
      <input class="form-control" name="room" maxlength="64" placeholder="Place (optional)" style="max-width:160px">
      <button class="btn btn-success" type="submit">Add</button>
    </div>
    <div class="small text-muted">Tap a chip or type your own. Example: 9:00 – Circle time.</div>
  </form>

  <div class="tt-card">
    <div class="fw-bold mb-2"><?php echo e($selectedDay); ?> for <?php echo e($selectedName !== '' ? $selectedName : 'this class'); ?></div>
    <?php if ($dayEntries === []): ?>
      <div class="text-muted">No slots yet this day. Add the first one above.</div>
    <?php else: ?>
      <?php foreach ($dayEntries as $r):
          $eid = (int) ($r['id'] ?? 0);
          $st = $fmtTime((string) ($r['start_time'] ?? ''));
          $en = $fmtTime((string) ($r['end_time'] ?? ''));
          $when = $st !== '' ? ($en !== '' ? $st . ' – ' . $en : $st) : (string) ($r['period'] ?? '');
          $place = trim((string) ($r['room'] ?? ''));
          ?>
        <div class="tt-slot">
          <div>
            <div class="tt-time"><?php echo e($when !== '' ? $when : 'Time not set'); ?></div>
            <div class="tt-act"><?php echo e($activityLabel($r)); ?></div>
            <?php if ($place !== ''): ?><div class="small text-muted"><?php echo e($place); ?></div><?php endif; ?>
          </div>
          <div>
            <?php echo render_secure_delete_button($eid, 'Remove', 'Remove this slot from the routine?', 'btn btn-sm btn-outline-danger', ['class_id' => $selectedClassId, 'day' => $selectedDay]); ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="fw-bold mb-2 mt-3">Whole week</div>
  <div class="tt-week">
    <?php foreach ($days as $d): ?>
      <div class="tt-weekcol<?php echo $d === $selectedDay ? ' border-primary' : ''; ?>">
        <h6><?php echo e(substr($d, 0, 3)); ?><?php echo $d === $todayName ? ' · today' : ''; ?></h6>
        <?php if ($byDay[$d] === []): ?>
          <div class="text-muted small">—</div>
        <?php else: ?>
          <?php foreach ($byDay[$d] as $r):
              $st = $fmtTime((string) ($r['start_time'] ?? ''));
              ?>
            <div class="tt-mini"><?php echo e($st !== '' ? $st : ''); ?> <?php echo e($activityLabel($r)); ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<script>
document.querySelectorAll('.act-chip').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var field = document.getElementById('activityField');
    if (field) field.value = btn.getAttribute('data-act') || '';
    field && field.focus();
  });
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
