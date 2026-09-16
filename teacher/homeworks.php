<?php
/**
 * teacher/homeworks.php
 *
 * Homework management for teachers.
 *
 * - Shows homeworks for classes assigned to the logged-in teacher.
 * - Create / edit / delete homeworks (server-side authorization).
 * - Reuses project includes when available (includes/config.php, includes/db.php, includes/functions.php, includes/csrf.php).
 * - Has safe PDO fallbacks and column-detection to avoid "unknown column" errors on varied schemas.
 *
 * Place at: /pioneerplayschool01/teacher/homeworks.php
 *
 * Required tables (at minimum):
 * - classes (may contain teacher_id) OR teacher_classes mapping table (teacher_id, class_id)
 * - homeworks (create with SQL shown at bottom)
 * - users (optional, for created_by FK)
 *
 * Notes:
 * - POST actions (create/update/delete) require a valid CSRF token.
 * - If you're seeing "Table homeworks not found" run the CREATE TABLE SQL (see bottom).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

if (!defined('DEV_SHOW_ERRORS')) define('DEV_SHOW_ERRORS', false);
$env = getenv('DEV_SHOW_ERRORS');
/* ---------------------------
   Teacher identity (from session)
   --------------------------- */
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ---------------------------
   Determine classes assigned to this teacher
   - Detect available columns in classes table to avoid "unknown column" errors
   --------------------------- */
$assignedClasses = panel_teacher_assigned_classes($teacherId);

/* Helper to render class title depending on available fields */
function class_title(array $c): string {
    if (!empty($c['short_name'])) return trim(($c['short_name'] ?? '') . ' ' . ($c['name'] ?? ''));
    if (!empty($c['name'])) {
        $s = $c['name'];
        if (!empty($c['section'])) $s .= ' - Section ' . $c['section'];
        return $s;
    }
    return 'Class #' . ($c['id'] ?? ''); 
}

/* ---------------------------
   Homeworks table detection
   --------------------------- */
$homeworksTable = 'homeworks';
$homeworksExists = table_exists($homeworksTable);

/* ---------------------------
   Actions: create / update / delete
   --------------------------- */
$messages = []; $errors = [];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$hwId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

/* simple function to check teacher assignment */
function teacher_has_class(int $classId, array $assignedClasses): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    foreach ($assignedClasses as $c) {
        if ((int) ($c['id'] ?? 0) === $classId) {
            return true;
        }
    }
    return false;
}

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming_csrf = $_POST['csrf'] ?? '';
    if (!validate_csrf_token($incoming_csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (!$homeworksExists) {
        $errors[] = "Table `{$homeworksTable}` not found. Create it first (see SQL at bottom).";
    } else {
        $postAction = $_POST['action'] ?? '';
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $assigned_date = trim((string)($_POST['assigned_date'] ?? date('Y-m-d')));
        $due_date = trim((string)($_POST['due_date'] ?? ''));

        if ($class_id <= 0 || !teacher_has_class($class_id, $assignedClasses)) $errors[] = 'Invalid class or you are not assigned to this class.';
        if ($title === '') $errors[] = 'Title is required.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $assigned_date)) $assigned_date = date('Y-m-d');
        if ($due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) $errors[] = 'Invalid due date format.';

        if (empty($errors)) {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) {
                $errors[] = 'Database connection failed.';
            } else {
                try {
                    if ($postAction === 'create') {
                        $stmt = $pdo->prepare("INSERT INTO {$homeworksTable} (school_id, class_id, title, description, assigned_date, due_date, created_by, created_at) VALUES (:school_id, :class_id, :title, :description, :assigned_date, :due_date, :created_by, NOW())");
                        $schoolId = null;
                        $stmt->execute([
                            ':school_id' => $schoolId,
                            ':class_id' => $class_id,
                            ':title' => $title,
                            ':description' => $description,
                            ':assigned_date' => $assigned_date,
                            ':due_date' => $due_date !== '' ? $due_date : null,
                            ':created_by' => $teacherId
                        ]);
                        $messages[] = 'Homework created.';
                    } elseif ($postAction === 'update' && $hwId > 0) {
                        $hw = safe_db_get_one("SELECT * FROM {$homeworksTable} WHERE id = :id LIMIT 1", [':id'=>$hwId]);
                        if (empty($hw)) $errors[] = 'Homework not found.';
                        else {
                            $hwClass = (int)($hw['class_id'] ?? 0);
                            $canEdit = ($hw['created_by'] == $teacherId) || teacher_has_class($hwClass, $assignedClasses);
                            if (!$canEdit) $errors[] = 'Not authorized to edit this homework.';
                            else {
                                $stmt = $pdo->prepare("UPDATE {$homeworksTable} SET class_id = :class_id, title = :title, description = :description, assigned_date = :assigned_date, due_date = :due_date, updated_at = NOW() WHERE id = :id");
                                $stmt->execute([
                                    ':class_id' => $class_id,
                                    ':title' => $title,
                                    ':description' => $description,
                                    ':assigned_date' => $assigned_date,
                                    ':due_date' => $due_date !== '' ? $due_date : null,
                                    ':id' => $hwId
                                ]);
                                $messages[] = 'Homework updated.';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'DB error: ' . ($DEBUG ? $e->getMessage() : 'Failed to save homework.');
                }
            }
        }
    }
}

/* Handle delete */
if ($action === 'delete' && $hwId > 0) {
    if (!$homeworksExists) {
        $errors[] = "Table `{$homeworksTable}` not found.";
    } else {
        $hw = safe_db_get_one("SELECT * FROM {$homeworksTable} WHERE id = :id LIMIT 1", [':id'=>$hwId]);
        if (empty($hw)) $errors[] = 'Homework not found.';
        else {
            $hwClass = (int)($hw['class_id'] ?? 0);
            $allow = ($hw['created_by'] == $teacherId) || teacher_has_class($hwClass, $assignedClasses);
            if (!$allow) $errors[] = 'Not authorized to delete.';
            else {
                $ok = safe_db_run("DELETE FROM {$homeworksTable} WHERE id = :id", [':id'=>$hwId]);
                if ($ok) $messages[] = 'Homework deleted.';
                else $errors[] = 'Failed to delete homework.';
            }
        }
    }
    $action = '';
}

/* ---------------------------
   Listing and pagination
   --------------------------- */
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId === 0 && !empty($assignedClasses)) $selectedClassId = (int)$assignedClasses[0]['id'];
if ($selectedClassId > 0 && !teacher_has_class($selectedClassId, $assignedClasses)) $selectedClassId = 0;

$perPage = 20;
$page = max(1, isset($_GET['p']) ? (int)$_GET['p'] : 1);
$offset = ($page - 1) * $perPage;

$homeworks = [];
$totalHomeworks = 0;
if ($selectedClassId > 0 && $homeworksExists) {
    $cnt = safe_db_get_one("SELECT COUNT(*) AS cnt FROM {$homeworksTable} WHERE class_id = :cid", [':cid'=>$selectedClassId]);
    $totalHomeworks = intval($cnt['cnt'] ?? 0);
    if ($totalHomeworks > 0) {
        // use positional LIMIT to avoid driver issues
        $homeworks = safe_db_get_all("SELECT * FROM {$homeworksTable} WHERE class_id = ? ORDER BY assigned_date DESC, created_at DESC LIMIT ? OFFSET ?", [$selectedClassId, $perPage, $offset]);
    }
}

/* Prepare edit row if requested */
$editRow = null;
if ($action === 'edit' && $hwId > 0 && $homeworksExists) {
    $editRow = safe_db_get_one("SELECT * FROM {$homeworksTable} WHERE id = :id LIMIT 1", [':id'=>$hwId]);
    if (!empty($editRow)) {
        $hwClass = (int)($editRow['class_id'] ?? 0);
        if (!teacher_has_class($hwClass, $assignedClasses) && ($editRow['created_by'] != $teacherId)) {
            $errors[] = 'Not authorized to edit this homework.';
            $editRow = null;
        }
    }
}

/* CSRF token */
$csrf = get_csrf_token();

/* ---------------------------
   Render HTML (uses includes/header.php if present)
   --------------------------- */
$pageTitle = 'Homeworks';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if (!$homeworksExists): ?>
  <div class="alert alert-warning">Table <code><?php echo e($homeworksTable); ?></code> not found. Create it with the SQL shown at the end of this file.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Select Class</h6>
        <?php if (empty($assignedClasses)): ?>
          <div class="small-muted">You are not assigned to any class yet. Contact administrator.</div>
        <?php else: ?>
          <form method="get">
            <div class="mb-2">
              <select name="class_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($assignedClasses as $c): $cid = (int)($c['id'] ?? 0); ?>
                  <option value="<?php echo $cid; ?>" <?php if ($cid === $selectedClassId) echo 'selected'; ?>><?php echo e(class_title($c)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        <?php endif; ?>

        <hr>
        <h6 class="mb-2"><?php echo $editRow ? 'Edit Homework' : 'Create Homework'; ?></h6>
        <?php if ($selectedClassId === 0): ?>
          <div class="small-muted">Select a class to create homework.</div>
        <?php else: ?>
          <form method="post" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>"><?php endif; ?>

            <div class="mb-2">
              <label class="form-label form-small">Class</label>
              <select name="class_id" class="form-select" required>
                <?php foreach ($assignedClasses as $c): $cid = (int)($c['id'] ?? 0); ?>
                  <option value="<?php echo $cid; ?>" <?php if ($cid === ($editRow['class_id'] ?? $selectedClassId)) echo 'selected'; ?>><?php echo e(class_title($c)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label form-small">Title</label>
              <input type="text" name="title" class="form-control" required value="<?php echo e($editRow['title'] ?? ''); ?>">
            </div>

            <div class="mb-2">
              <label class="form-label form-small">Description</label>
              <textarea name="description" rows="4" class="form-control"><?php echo e($editRow['description'] ?? ''); ?></textarea>
            </div>

            <div class="mb-2 row">
              <div class="col">
                <label class="form-label form-small">Assigned Date</label>
                <input type="date" name="assigned_date" class="form-control" value="<?php echo e(substr($editRow['assigned_date'] ?? date('Y-m-d'),0,10)); ?>">
              </div>
              <div class="col">
                <label class="form-label form-small">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?php echo e(substr($editRow['due_date'] ?? '',0,10)); ?>">
              </div>
            </div>

            <div class="d-grid mt-2">
              <button class="btn btn-primary"><?php echo $editRow ? 'Update' : 'Create'; ?></button>
              <?php if ($editRow): ?><a class="btn btn-outline-secondary mt-2" href="?class_id=<?php echo (int)$selectedClassId; ?>">Cancel</a><?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($DEBUG): ?>
      <div class="card mt-3"><div class="card-body small text-muted">Debug: assignedClasses=<?php echo e(json_encode($assignedClasses)); ?></div></div>
    <?php endif; ?>
  </div>

  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Homeworks <?php if ($selectedClassId>0): ?><small class="text-muted">for <?php echo e(class_title(array_filter($assignedClasses, function($c) use ($selectedClassId){ return (int)($c['id'] ?? 0) === $selectedClassId; })[0] ?? ['id'=>$selectedClassId] )); ?></small><?php endif; ?></h6>

        <?php if ($selectedClassId === 0): ?>
          <div class="small-muted">Select a class to view homeworks.</div>
        <?php else: ?>
          <?php if (empty($homeworks)): ?>
            <div class="small-muted">No homeworks found for this class.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($homeworks as $hw): ?>
                <div class="list-group-item">
                  <div class="d-flex justify-content-between">
                    <div>
                      <div class="fw-semibold"><?php echo e($hw['title']); ?></div>
                      <div class="small-muted"><?php echo e(substr($hw['assigned_date'] ?? '',0,10)); ?> — due <?php echo e(substr($hw['due_date'] ?? '—',0,10)); ?></div>
                    </div>
                    <div class="text-end">
                      <?php if (($hw['created_by'] ?? null) == $teacherId || teacher_has_class((int)$hw['class_id'], $assignedClasses)): ?>
                        <a class="btn btn-sm btn-outline-primary" href="?action=edit&id=<?php echo (int)$hw['id']; ?>&class_id=<?php echo (int)$selectedClassId; ?>">Edit</a>
                        <?php echo render_secure_delete_button((int)$hw['id'], 'Delete', 'Delete this homework?', 'btn btn-sm btn-outline-danger', ['class_id' => (int)$selectedClassId]); ?>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if (!empty($hw['description'])): ?><div class="mt-2"><?php echo nl2br(e($hw['description'])); ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($totalHomeworks > $perPage): $totalPages = (int) ceil($totalHomeworks / $perPage); ?>
              <nav class="mt-3" aria-label="pagination">
                <ul class="pagination pagination-sm">
                  <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="?class_id=<?php echo $selectedClassId; ?>&p=<?php echo max(1,$page-1); ?>">Prev</a></li>
                  <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                  <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>"><a class="page-link" href="?class_id=<?php echo $selectedClassId; ?>&p=<?php echo min($totalPages,$page+1); ?>">Next</a></li>
                </ul>
              </nav>
            <?php endif; ?>

          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
/* Footer include or fallback */
require_once __DIR__ . '/../includes/footer.php';

?>

<!--
CREATE TABLE SQL (run once if table missing)

-- Simple version (recommended for most setups)
CREATE TABLE IF NOT EXISTS `homeworks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT DEFAULT NULL,
  `class_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `assigned_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  INDEX `idx_class_assigned` (`class_id`, `assigned_date`),
  INDEX `idx_assigned_date` (`assigned_date`),
  INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If you want foreign keys, ensure referenced tables exist and engines/types match, then add constraints separately.
-->