<?php
/**
 * owner/teacher_assign.php — pick which teacher looks after each class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

$page_title = 'Assign Teachers';
$pageTitle = $page_title;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$messages = [];
$errors = [];

$staffUrl = function_exists('site_url') ? site_url('/owner/staff_manage.php') : 'staff_manage.php';
$classUrl = function_exists('site_url') ? site_url('/owner/class_setup.php') : 'class_setup.php';

if (!function_exists('table_exists') || !table_exists('classes')) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Classes were not found. Set them up on <a href="' . e($classUrl) . '">Classes</a> first.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (!table_exists('teacher_classes')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                'CREATE TABLE IF NOT EXISTS teacher_classes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    teacher_id INT NOT NULL,
                    class_id INT NOT NULL,
                    role VARCHAR(64) DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_teacher_class (teacher_id, class_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    } catch (Throwable $e) {
        error_log('teacher_classes create: ' . $e->getMessage());
    }
}

$mappingExists = table_exists('teacher_classes');

$syncClassTeacher = static function (int $classId): void {
    if ($classId <= 0 || !function_exists('column_exists') || !column_exists('classes', 'teacher_id')) {
        return;
    }
    $row = safe_db_get_one(
        'SELECT teacher_id FROM teacher_classes WHERE class_id = :c ORDER BY id ASC LIMIT 1',
        [':c' => $classId]
    );
    $tid = (int) ($row['teacher_id'] ?? 0);
    if ($tid > 0) {
        safe_db_run('UPDATE classes SET teacher_id = :t WHERE id = :id', [':t' => $tid, ':id' => $classId]);
    } else {
        safe_db_run('UPDATE classes SET teacher_id = NULL WHERE id = :id', [':id' => $classId]);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$mappingExists) {
        $errors[] = 'Could not create the teacher assignment table. Ask support to add teacher_classes.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $teacherId = (int) ($_POST['teacher_id'] ?? 0);
            $classId = (int) ($_POST['class_id'] ?? 0);
            $teacher = $teacherId > 0
                ? safe_db_get_one("SELECT id, name, role FROM users WHERE id = :id AND role = 'teacher' LIMIT 1", [':id' => $teacherId])
                : null;
            $class = $classId > 0 ? safe_db_get_one('SELECT id, name FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]) : null;
            if (!$teacher) {
                $errors[] = 'Select a teacher.';
            }
            if (!$class) {
                $errors[] = 'Select a class.';
            }
            if ($errors === []) {
                $dup = safe_db_get_one(
                    'SELECT id FROM teacher_classes WHERE teacher_id = :t AND class_id = :c LIMIT 1',
                    [':t' => $teacherId, ':c' => $classId]
                );
                if ($dup) {
                    $errors[] = (string) $teacher['name'] . ' is already on ' . (string) $class['name'] . '.';
                } elseif (safe_db_run(
                    'INSERT INTO teacher_classes (teacher_id, class_id, created_at) VALUES (:t, :c, NOW())',
                    [':t' => $teacherId, ':c' => $classId]
                )) {
                    $syncClassTeacher($classId);
                    header('Location: ?saved=1');
                    exit;
                } else {
                    $errors[] = 'Could not assign this teacher.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = $id > 0 ? safe_db_get_one('SELECT id, class_id FROM teacher_classes WHERE id = :id LIMIT 1', [':id' => $id]) : null;
            if (!$row) {
                $errors[] = 'Assignment not found.';
            } elseif (safe_db_run('DELETE FROM teacher_classes WHERE id = :id LIMIT 1', [':id' => $id])) {
                $syncClassTeacher((int) $row['class_id']);
                header('Location: ?removed=1');
                exit;
            } else {
                $errors[] = 'Could not remove this teacher from the class.';
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Teacher assigned to the class.';
}
if (!empty($_GET['removed'])) {
    $messages[] = 'Teacher removed from the class.';
}

$teachers = table_exists('users')
    ? (safe_db_get_all("SELECT id, name FROM users WHERE role = 'teacher' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC") ?: [])
    : [];

$order = "CASE
  WHEN c.name LIKE 'Play%' THEN 1
  WHEN c.name LIKE 'Nurs%' THEN 2
  WHEN c.name LIKE 'L%' THEN 3
  WHEN c.name LIKE 'U%' THEN 4
  ELSE 9 END, c.name ASC";
$classes = safe_db_get_all("SELECT c.* FROM classes c ORDER BY {$order}") ?: [];

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$ayP = function_exists('ay_params_student') ? ay_params_student() : [];
$counts = [];
if (table_exists('students') && $classes !== []) {
    $rows = safe_db_get_all(
        "SELECT s.class_id, COUNT(*) AS c
         FROM students s
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') AND {$ayStu}
         GROUP BY s.class_id",
        $ayP
    ) ?: [];
    foreach ($rows as $r) {
        $counts[(int) ($r['class_id'] ?? 0)] = (int) ($r['c'] ?? 0);
    }
}

$byClass = [];
if ($mappingExists && $classes !== []) {
    $maps = safe_db_get_all(
        "SELECT tc.id, tc.teacher_id, tc.class_id, u.name AS teacher_name
         FROM teacher_classes tc
         LEFT JOIN users u ON u.id = tc.teacher_id
         ORDER BY u.name ASC"
    ) ?: [];
    foreach ($maps as $m) {
        $cid = (int) ($m['class_id'] ?? 0);
        $byClass[$cid][] = $m;
    }
}

$ayLabel = function_exists('ay_display_short') ? ay_display_short() : '';

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ta-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.ta-name { font-weight:800; color:#1e3a5f; font-size:1.05rem; }
.ta-meta { font-size:.85rem; color:#64748b; }
.ta-chip { display:inline-flex; align-items:center; gap:.4rem; background:#eff6ff; border:1px solid #bfdbfe; border-radius:999px; padding:.2rem .35rem .2rem .7rem; font-size:.9rem; margin:0 .35rem .35rem 0; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <p class="text-muted mb-0">Choose who looks after each class. That teacher then sees only their class on attendance, homework, and remarks<?php echo $ayLabel !== '' ? ' (' . e($ayLabel) . ')' : ''; ?>.</p>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($teachers === []): ?>
  <div class="alert alert-info">Add a teacher first on <a href="<?php echo e($staffUrl); ?>">Staff</a> (role Teacher), then come back here.</div>
<?php endif; ?>

<?php if ($classes === []): ?>
  <div class="alert alert-info">Add Playgroup / Nursery / L.K.G. / U.K.G. on <a href="<?php echo e($classUrl); ?>">Classes</a> first.</div>
<?php endif; ?>

<?php if (!$mappingExists): ?>
  <div class="alert alert-warning">Teacher assignment table is missing and could not be created automatically.</div>
<?php endif; ?>

<?php foreach ($classes as $c):
    $cid = (int) $c['id'];
    $assigned = $byClass[$cid] ?? [];
    $assignedIds = array_map(static fn($r) => (int) ($r['teacher_id'] ?? 0), $assigned);
    $available = [];
    foreach ($teachers as $t) {
        if (!in_array((int) $t['id'], $assignedIds, true)) {
            $available[] = $t;
        }
    }
    $kids = $counts[$cid] ?? 0;
    $age = trim((string) ($c['age_group'] ?? ''));
    ?>
  <div class="ta-card">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
      <div>
        <div class="ta-name"><?php echo e((string) $c['name']); ?></div>
        <div class="ta-meta">
          <?php echo $age !== '' ? e($age) : 'Age not set'; ?>
          · <?php echo (int) $kids; ?> child<?php echo $kids === 1 ? '' : 'ren'; ?>
        </div>
      </div>
    </div>
    <?php if ($assigned === []): ?>
      <div class="text-muted small mb-2">No teacher yet.</div>
    <?php else: ?>
      <div class="mb-2">
        <?php foreach ($assigned as $a): ?>
          <span class="ta-chip">
            <?php echo e((string) ($a['teacher_name'] ?? 'Teacher')); ?>
            <?php if ($mappingExists): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Remove this teacher from <?php echo e((string) $c['name']); ?>?');">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
              <button class="btn btn-sm btn-link text-danger p-0" type="submit" title="Remove" aria-label="Remove">&times;</button>
            </form>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($mappingExists && $available !== []): ?>
      <form method="post" class="d-flex flex-wrap gap-2">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="class_id" value="<?php echo $cid; ?>">
        <select name="teacher_id" class="form-select" required style="max-width:260px">
          <option value=""><?php echo $assigned === [] ? 'Select teacher' : 'Add another teacher'; ?></option>
          <?php foreach ($available as $t): ?>
            <option value="<?php echo (int) $t['id']; ?>"><?php echo e((string) $t['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-success" type="submit">Assign</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
