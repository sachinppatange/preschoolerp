<?php
/**
 * teacher/student_remarks.php — short classroom notes about a child.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
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

$kinds = [
    'commendation' => 'Good day',
    'note' => 'Note',
    'warning' => 'Needs care',
];

if (!table_exists('student_remarks')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                'CREATE TABLE IF NOT EXISTS student_remarks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    class_id INT NOT NULL,
                    teacher_id INT NOT NULL,
                    remark TEXT NOT NULL,
                    type VARCHAR(50) DEFAULT \'note\',
                    `date` DATE DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL,
                    INDEX idx_class_date (class_id, `date`),
                    INDEX idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    } catch (Throwable $e) {
        error_log('student_remarks create: ' . $e->getMessage());
    }
}
$tableOk = table_exists('student_remarks');
$hasUpdated = $tableOk && function_exists('column_exists') && column_exists('student_remarks', 'updated_at');

$messages = [];
$errors = [];

$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !$canClass($selectedClassId)) {
    $selectedClassId = 0;
}

$pickStudentId = (int) ($_POST['student_id'] ?? $_GET['student_id'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$statusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";
$classChildren = static function (int $classId) use ($ayStu, $statusSql): array {
    if ($classId <= 0 || !table_exists('students')) {
        return [];
    }
    $params = function_exists('ay_params_student') ? ay_params_student([':cid' => $classId]) : [':cid' => $classId];
    return safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id
         FROM students s
         WHERE s.class_id = :cid AND {$statusSql} AND {$ayStu}
         ORDER BY s.first_name ASC, s.last_name ASC",
        $params
    ) ?: [];
};

$childName = static function (array $r): string {
    if (function_exists('student_full_name')) {
        $n = student_full_name($r);
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
};

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get((string) ($_REQUEST['action'] ?? ''))) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0 && $tableOk) {
    $row = safe_db_get_one('SELECT id, class_id FROM student_remarks WHERE id = :id LIMIT 1', [':id' => $deleteId]);
    $cid = (int) ($row['class_id'] ?? 0);
    if (!$row || !$canClass($cid)) {
        $errors[] = 'You cannot delete this note.';
    } elseif (safe_db_run('DELETE FROM student_remarks WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?class_id=' . $cid . '&deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') !== 'delete') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$tableOk) {
        $errors[] = 'Remarks are not set up yet.';
    } else {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $remark = trim((string) ($_POST['remark'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'note');
        if (!isset($kinds[$type])) {
            $type = 'note';
        }
        $date = trim((string) ($_POST['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $inClass = false;
        foreach ($classChildren($classId) as $ch) {
            if ((int) $ch['id'] === $studentId) {
                $inClass = true;
                break;
            }
        }
        if (!$canClass($classId) || $classId <= 0) {
            $errors[] = 'Choose your class.';
        }
        if ($studentId <= 0 || !$inClass) {
            $errors[] = 'Choose a child from this class.';
        }
        if ($remark === '') {
            $errors[] = 'Write a short note.';
        }
        if ($errors === []) {
            try {
                if ($id > 0) {
                    $row = safe_db_get_one('SELECT id, class_id FROM student_remarks WHERE id = :id LIMIT 1', [':id' => $id]);
                    if (!$row || !$canClass((int) ($row['class_id'] ?? 0))) {
                        $errors[] = 'You cannot edit this note.';
                    } else {
                        $sql = 'UPDATE student_remarks SET student_id = :sid, class_id = :cid, remark = :remark, type = :type, `date` = :d';
                        $params = [
                            ':sid' => $studentId,
                            ':cid' => $classId,
                            ':remark' => $remark,
                            ':type' => $type,
                            ':d' => $date,
                            ':id' => $id,
                        ];
                        if ($hasUpdated) {
                            $sql .= ', updated_at = NOW()';
                        }
                        $sql .= ' WHERE id = :id';
                        if (safe_db_run($sql, $params)) {
                            header('Location: ?class_id=' . $classId . '&student_id=' . $studentId . '&saved=1');
                            exit;
                        }
                        $errors[] = 'Could not save.';
                    }
                } else {
                    $ok = safe_db_run(
                        'INSERT INTO student_remarks (student_id, class_id, teacher_id, remark, type, `date`, created_at)
                         VALUES (:sid, :cid, :tid, :remark, :type, :d, NOW())',
                        [
                            ':sid' => $studentId,
                            ':cid' => $classId,
                            ':tid' => $teacherId,
                            ':remark' => $remark,
                            ':type' => $type,
                            ':d' => $date,
                        ]
                    );
                    if ($ok) {
                        header('Location: ?class_id=' . $classId . '&student_id=' . $studentId . '&saved=1');
                        exit;
                    }
                    $errors[] = 'Could not save.';
                }
            } catch (Throwable $e) {
                $errors[] = $DEBUG ? $e->getMessage() : 'Could not save the note.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Note saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Note removed.';
}

$children = $selectedClassId > 0 ? $classChildren($selectedClassId) : [];
$childIds = array_map(static fn($s) => (int) $s['id'], $children);
if ($pickStudentId > 0 && !in_array($pickStudentId, $childIds, true)) {
    $pickStudentId = 0;
}

$editRow = null;
if ($editId > 0 && $tableOk) {
    $editRow = safe_db_get_one('SELECT * FROM student_remarks WHERE id = :id LIMIT 1', [':id' => $editId]);
    if ($editRow && $canClass((int) ($editRow['class_id'] ?? 0))) {
        $selectedClassId = (int) $editRow['class_id'];
        $pickStudentId = (int) $editRow['student_id'];
        $children = $classChildren($selectedClassId);
    } else {
        $editRow = null;
    }
}

$ayRange = function_exists('ay_range') ? ay_range() : ['start' => date('Y-m-01'), 'end' => date('Y-m-d')];
$notes = [];
if ($tableOk && $selectedClassId > 0) {
    $params = [
        ':cid' => $selectedClassId,
        ':a' => $ayRange['start'],
        ':b' => $ayRange['end'],
    ];
    $extra = '';
    if ($pickStudentId > 0) {
        $extra = ' AND r.student_id = :sid';
        $params[':sid'] = $pickStudentId;
    }
    $notes = safe_db_get_all(
        "SELECT r.*, s.first_name, s.middle_name, s.last_name
         FROM student_remarks r
         LEFT JOIN students s ON s.id = r.student_id
         WHERE r.class_id = :cid AND COALESCE(r.date, DATE(r.created_at)) BETWEEN :a AND :b {$extra}
         ORDER BY COALESCE(r.date, DATE(r.created_at)) DESC, r.id DESC
         LIMIT 80",
        $params
    ) ?: [];
}

$selectedName = '';
foreach ($assignedClasses as $c) {
    if ((int) $c['id'] === $selectedClassId) {
        $selectedName = (string) ($c['name'] ?? '');
        break;
    }
}

$fmt = static function (?string $d): string {
    $d = substr((string) $d, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M', $t) : $d;
};

$page_title = 'Remarks';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.rm-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.rm-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.rm-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.rm-title { font-weight:800; color:#1e3a5f; }
.rm-meta { font-size:.85rem; color:#64748b; }
.rm-kind { font-size:.75rem; font-weight:700; border-radius:999px; padding:.15rem .55rem; }
.rm-kind.good { background:#dcfce7; color:#166534; }
.rm-kind.note { background:#e2e8f0; color:#334155; }
.rm-kind.care { background:#ffedd5; color:#9a3412; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <div class="alert alert-info mb-0">No class is assigned yet. Ask the owner to assign you a class.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Remarks are not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">A short note for a child — good day, or something parents should know.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="rm-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="?class_id=<?php echo $cid; ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="rm-card">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>
    <div class="fw-bold mb-2"><?php echo $editRow ? 'Edit note' : 'Add a note for ' . e($selectedName !== '' ? $selectedName : 'this class'); ?></div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <select name="student_id" class="form-select" required style="max-width:240px">
        <option value="">Choose child</option>
        <?php foreach ($children as $s): ?>
          <option value="<?php echo (int) $s['id']; ?>" <?php echo $pickStudentId === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($childName($s)); ?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-select" style="max-width:150px">
        <?php $curType = (string) ($editRow['type'] ?? 'note'); foreach ($kinds as $k => $lab): ?>
          <option value="<?php echo e($k); ?>" <?php echo $curType === $k ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date" class="form-control" style="max-width:160px" value="<?php echo e(substr((string) ($editRow['date'] ?? date('Y-m-d')), 0, 10)); ?>">
    </div>
    <textarea class="form-control mb-2" name="remark" rows="3" required placeholder="e.g. Settled well today / Please send an extra set of clothes"><?php echo e((string) ($editRow['remark'] ?? '')); ?></textarea>
    <div class="d-flex gap-2">
      <button class="btn btn-success" type="submit"><?php echo $editRow ? 'Save' : 'Add note'; ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="?class_id=<?php echo (int) $selectedClassId; ?>">Cancel</a><?php endif; ?>
    </div>
  </form>

  <?php if ($children !== []): ?>
    <form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <label class="small text-muted mb-0">Show</label>
      <select name="student_id" class="form-select form-select-sm" style="max-width:240px" onchange="this.form.submit()">
        <option value="0">All children</option>
        <?php foreach ($children as $s): ?>
          <option value="<?php echo (int) $s['id']; ?>" <?php echo $pickStudentId === (int) $s['id'] ? 'selected' : ''; ?>><?php echo e($childName($s)); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <?php if ($notes === []): ?>
    <div class="text-muted">No notes yet this year<?php echo $pickStudentId > 0 ? ' for this child' : ''; ?>.</div>
  <?php else: ?>
    <?php foreach ($notes as $r):
        $rid = (int) ($r['id'] ?? 0);
        $kind = (string) ($r['type'] ?? 'note');
        $kindClass = $kind === 'commendation' ? 'good' : ($kind === 'warning' ? 'care' : 'note');
        $when = $fmt((string) ($r['date'] ?? $r['created_at'] ?? ''));
        ?>
      <div class="rm-card">
        <div class="d-flex justify-content-between gap-2 flex-wrap">
          <div>
            <div class="rm-title"><?php echo e($childName($r)); ?></div>
            <div class="rm-meta mb-1">
              <span class="rm-kind <?php echo $kindClass; ?>"><?php echo e($kinds[$kind] ?? 'Note'); ?></span>
              <?php echo $when !== '' ? ' · ' . e($when) : ''; ?>
            </div>
            <div style="white-space:pre-wrap"><?php echo e((string) ($r['remark'] ?? '')); ?></div>
          </div>
          <div class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?class_id=<?php echo (int) $selectedClassId; ?>&amp;edit=<?php echo $rid; ?>">Edit</a>
            <?php echo render_secure_delete_button($rid, 'Delete', 'Remove this note?', 'btn btn-sm btn-outline-danger', ['class_id' => $selectedClassId]); ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
