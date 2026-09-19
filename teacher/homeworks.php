<?php
/**
 * teacher/homeworks.php — class work for parents to see.
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

$teacherHasClass = static function (int $classId) use ($assignedClasses): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    foreach ($assignedClasses as $c) {
        if ((int) ($c['id'] ?? 0) === $classId) {
            return true;
        }
    }
    return false;
};

if (!table_exists('homeworks')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                'CREATE TABLE IF NOT EXISTS homeworks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    school_id INT DEFAULT NULL,
                    class_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    assigned_date DATE NOT NULL,
                    due_date DATE DEFAULT NULL,
                    created_by INT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL,
                    INDEX idx_class_assigned (class_id, assigned_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    } catch (Throwable $e) {
        error_log('homeworks create: ' . $e->getMessage());
    }
}

$hwTableOk = table_exists('homeworks');
$col = static function (string $name) use ($hwTableOk): bool {
    return $hwTableOk && function_exists('column_exists') && column_exists('homeworks', $name);
};

$messages = [];
$errors = [];

$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $assignedClasses !== []) {
    $selectedClassId = (int) $assignedClasses[0]['id'];
}
if ($selectedClassId > 0 && !$teacherHasClass($selectedClassId)) {
    $selectedClassId = 0;
}

$editId = (int) ($_GET['edit'] ?? 0);

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get((string) ($_REQUEST['action'] ?? ''))) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0 && $hwTableOk) {
    $hw = safe_db_get_one('SELECT id, class_id, created_by FROM homeworks WHERE id = :id LIMIT 1', [':id' => $deleteId]);
    $hwClass = (int) ($hw['class_id'] ?? 0);
    $okDel = $hw && ($teacherHasClass($hwClass) || (int) ($hw['created_by'] ?? 0) === $teacherId);
    if (!$okDel) {
        $errors[] = 'You cannot delete this homework.';
    } elseif (safe_db_run('DELETE FROM homeworks WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?class_id=' . $hwClass . '&deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') !== 'delete') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$hwTableOk) {
        $errors[] = 'Homework is not set up yet.';
    } else {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $forDate = trim((string) ($_POST['for_date'] ?? date('Y-m-d')));
        $id = (int) ($_POST['id'] ?? 0);
        if ($classId <= 0 || !$teacherHasClass($classId)) {
            $errors[] = 'Choose your class.';
        }
        if ($title === '') {
            $errors[] = 'Write what the children should do.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $forDate)) {
            $forDate = date('Y-m-d');
        }
        if ($errors === []) {
            try {
                if ($id > 0) {
                    $hw = safe_db_get_one('SELECT id, class_id, created_by FROM homeworks WHERE id = :id LIMIT 1', [':id' => $id]);
                    $can = $hw && ($teacherHasClass((int) ($hw['class_id'] ?? 0)) || (int) ($hw['created_by'] ?? 0) === $teacherId);
                    if (!$can) {
                        $errors[] = 'You cannot edit this homework.';
                    } else {
                        $sql = 'UPDATE homeworks SET class_id = :class_id, title = :title, description = :description, assigned_date = :assigned_date, due_date = :due_date';
                        $params = [
                            ':class_id' => $classId,
                            ':title' => $title,
                            ':description' => $description !== '' ? $description : null,
                            ':assigned_date' => $forDate,
                            ':due_date' => $forDate,
                            ':id' => $id,
                        ];
                        if ($col('updated_at')) {
                            $sql .= ', updated_at = NOW()';
                        }
                        $sql .= ' WHERE id = :id';
                        if (safe_db_run($sql, $params)) {
                            header('Location: ?class_id=' . $classId . '&saved=1');
                            exit;
                        }
                        $errors[] = 'Could not save.';
                    }
                } else {
                    $fields = [
                        'class_id' => $classId,
                        'title' => $title,
                        'description' => $description !== '' ? $description : null,
                        'assigned_date' => $forDate,
                        'due_date' => $forDate,
                    ];
                    if ($col('school_id')) {
                        $fields['school_id'] = $schoolId > 0 ? $schoolId : null;
                    }
                    if ($col('created_by')) {
                        $fields['created_by'] = $teacherId;
                    } elseif ($col('assigned_by')) {
                        $fields['assigned_by'] = $teacherId;
                    }
                    $cols = [];
                    $ph = [];
                    $params = [];
                    foreach ($fields as $k => $v) {
                        $cols[] = $k;
                        $ph[] = ':' . $k;
                        $params[':' . $k] = $v;
                    }
                    if ($col('created_at')) {
                        $ok = safe_db_run(
                            'INSERT INTO homeworks (' . implode(', ', $cols) . ', created_at) VALUES (' . implode(', ', $ph) . ', NOW())',
                            $params
                        );
                    } else {
                        $ok = safe_db_run(
                            'INSERT INTO homeworks (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')',
                            $params
                        );
                    }
                    if ($ok) {
                        header('Location: ?class_id=' . $classId . '&saved=1');
                        exit;
                    }
                    $errors[] = 'Could not save.';
                }
            } catch (Throwable $e) {
                $errors[] = $DEBUG ? $e->getMessage() : 'Could not save homework.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Homework saved. Parents can see it.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Homework removed.';
}

$editRow = null;
if ($editId > 0 && $hwTableOk) {
    $editRow = safe_db_get_one('SELECT * FROM homeworks WHERE id = :id LIMIT 1', [':id' => $editId]);
    if ($editRow) {
        $hwClass = (int) ($editRow['class_id'] ?? 0);
        if (!$teacherHasClass($hwClass) && (int) ($editRow['created_by'] ?? 0) !== $teacherId) {
            $editRow = null;
        } elseif ($hwClass > 0) {
            $selectedClassId = $hwClass;
        }
    }
}

$ayRange = function_exists('ay_range') ? ay_range() : ['start' => date('Y-m-01'), 'end' => date('Y-m-d')];
$homeworks = [];
if ($selectedClassId > 0 && $hwTableOk) {
    $homeworks = safe_db_get_all(
        'SELECT * FROM homeworks
         WHERE class_id = :cid AND assigned_date BETWEEN :a AND :b
         ORDER BY assigned_date DESC, id DESC
         LIMIT 80',
        [':cid' => $selectedClassId, ':a' => $ayRange['start'], ':b' => $ayRange['end']]
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

$page_title = 'Homework';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.hw-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.hw-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.hw-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.hw-title { font-weight:800; color:#1e3a5f; }
.hw-meta { font-size:.85rem; color:#64748b; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <?php echo panel_teacher_empty_classes_html(); ?>
<?php elseif (!$hwTableOk): ?>
  <div class="alert alert-warning mb-0">Homework is not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">Parents see this on their homework page. Keep it short — one thing to do at home.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="hw-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="?class_id=<?php echo $cid; ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="hw-card">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>
    <div class="fw-bold mb-2"><?php echo $editRow ? 'Edit homework' : 'Add homework for ' . e($selectedName !== '' ? $selectedName : 'this class'); ?></div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" name="title" required maxlength="255" placeholder="e.g. Colour the apple worksheet" value="<?php echo e((string) ($editRow['title'] ?? '')); ?>" style="flex:1; min-width:200px">
      <input type="date" name="for_date" class="form-control" style="max-width:160px" value="<?php echo e(substr((string) ($editRow['assigned_date'] ?? date('Y-m-d')), 0, 10)); ?>">
      <button class="btn btn-success" type="submit"><?php echo $editRow ? 'Save' : 'Add'; ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="?class_id=<?php echo (int) $selectedClassId; ?>">Cancel</a><?php endif; ?>
    </div>
    <textarea class="form-control" name="description" rows="2" placeholder="Optional: how to do it (parents read this)"><?php echo e((string) ($editRow['description'] ?? '')); ?></textarea>
  </form>

  <?php if ($homeworks === []): ?>
    <div class="text-muted">No homework for this class yet this year.</div>
  <?php else: ?>
    <?php foreach ($homeworks as $hw):
        $hid = (int) ($hw['id'] ?? 0);
        $when = $fmt((string) ($hw['assigned_date'] ?? ''));
        $isToday = substr((string) ($hw['assigned_date'] ?? ''), 0, 10) === date('Y-m-d');
        ?>
      <div class="hw-card">
        <div class="d-flex justify-content-between gap-2 flex-wrap">
          <div>
            <div class="hw-title"><?php echo e((string) ($hw['title'] ?? '')); ?></div>
            <div class="hw-meta"><?php echo $isToday ? 'Today' : e($when); ?></div>
            <?php if (trim((string) ($hw['description'] ?? '')) !== ''): ?>
              <div class="mt-1" style="white-space:pre-wrap"><?php echo e((string) $hw['description']); ?></div>
            <?php endif; ?>
          </div>
          <div class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?class_id=<?php echo (int) $selectedClassId; ?>&amp;edit=<?php echo $hid; ?>">Edit</a>
            <?php echo render_secure_delete_button($hid, 'Delete', 'Remove this homework?', 'btn btn-sm btn-outline-danger', ['class_id' => $selectedClassId]); ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
