<?php
/**
 * owner/teacher_assign.php
 *
 * Teacher-Class assignment UI (mapping table).
 *
 * Features:
 * - Requires an "owner" user to be logged in (checks $_SESSION['owner_auth_user']).
 * - Shows existing mappings from teacher_classes table (teacher_id, class_id, role, created_at).
 * - Allows adding a mapping: select Teacher (users.role='teacher') and Class (classes).
 * - Allows removing a mapping.
 * - Uses safe DB helpers and CSRF protection (falls back to local implementations if project includes absent).
 *
 * Place at: /pioneerplayschool01/owner/teacher_assign.php
 *
 * NOTE:
 * - This script expects a mapping table named `teacher_classes`. If it doesn't exist, an SQL snippet is provided below
 *   (run it using phpMyAdmin / CLI) to create the table.
 *
 * CREATE TABLE (run once):
 * CREATE TABLE teacher_classes (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   teacher_id INT NOT NULL,
 *   class_id INT NOT NULL,
 *   role VARCHAR(64) DEFAULT NULL,
 *   created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   UNIQUE KEY uk_teacher_class (teacher_id, class_id)
 * );
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* ---------- Ensure mapping table exists? (we don't auto-create, but show helpful message) ---------- */
$mappingTable = 'teacher_classes';
$mappingExists = table_exists($mappingTable);

/* ---------- UI state ---------- */
$errors = [];
$messages = [];
$CSRF = get_csrf_token();

/* ---------- Handle POST actions: add / delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // validate CSRF
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $teacher_id = isset($_POST['teacher_id']) && $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : 0;
            $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : 0;
            $role = trim((string)($_POST['role'] ?? ''));
            if ($teacher_id <= 0) $errors[] = 'Select a teacher.';
            if ($class_id <= 0) $errors[] = 'Select a class.';
            if (!$mappingExists) $errors[] = "Mapping table `{$mappingTable}` does not exist. Create it first (SQL provided below).";
            if (empty($errors)) {
                // Insert if not exists (respect UNIQUE constraint)
                try {
                    $ok = safe_db_run("INSERT INTO {$mappingTable} (teacher_id, class_id, role, created_at) VALUES (:tid, :cid, :role, NOW())", [
                        ':tid' => $teacher_id,
                        ':cid' => $class_id,
                        ':role' => $role !== '' ? $role : null
                    ]);
                    if ($ok) $messages[] = 'Teacher assigned to class.';
                    else $errors[] = 'Failed to assign teacher. Possibly duplicate assignment.';
                } catch (Throwable $e) {
                    // detect duplicate key
                    $msg = $e->getMessage();
                    if (strpos($msg, 'Duplicate') !== false || strpos($msg, 'duplicate') !== false) {
                        $errors[] = 'This teacher is already assigned to the selected class.';
                    } else {
                        $errors[] = 'DB error: ' . ($GLOBALS['DEBUG'] ? $msg : 'Failed to assign teacher.');
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) $errors[] = 'Invalid id to remove.';
            if (!$mappingExists) $errors[] = "Mapping table `{$mappingTable}` does not exist.";
            if (empty($errors)) {
                $ok = safe_db_run("DELETE FROM {$mappingTable} WHERE id = :id LIMIT 1", [':id'=>$id]);
                if ($ok) $messages[] = 'Assignment removed.';
                else $errors[] = 'Failed to remove assignment.';
            }
        }
    }
}

$teachers = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users WHERE role = 'teacher' AND (is_active IS NULL OR is_active = 1) ORDER BY name ASC") : [];
$classes = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];

/* ---------- Load existing mappings ---------- */
$mappings = [];
if ($mappingExists) {
    // join with users and classes for readable output (if those tables exist)
    if (table_exists('users') && table_exists('classes')) {
        $mappings = safe_db_get_all("
            SELECT tc.id, tc.teacher_id, tc.class_id, tc.role, tc.created_at,
                   u.name AS teacher_name, c.name AS class_name
            FROM {$mappingTable} tc
            LEFT JOIN users u ON u.id = tc.teacher_id
            LEFT JOIN classes c ON c.id = tc.class_id
            ORDER BY tc.created_at DESC
            LIMIT 200
        ");
    } else {
        // fallback: raw mapping rows
        $mappings = safe_db_get_all("SELECT id, teacher_id, class_id, role, created_at FROM {$mappingTable} ORDER BY created_at DESC LIMIT 200");
    }
}

/* ---------- Page render ---------- */
$pageTitle = 'Assign Teachers to Classes';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-10">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0"><?php echo e($pageTitle); ?></h3>
      <a class="btn btn-secondary btn-sm" href="/owner/dashboard.php">Back to Owner Dashboard</a>
    </div>

    <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
    <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

    <?php if (!$mappingExists): ?>
      <div class="alert alert-warning">
        Mapping table <code><?php echo e($mappingTable); ?></code> not found. Create it with the SQL shown below, then reload this page.
        <details class="mt-2"><summary>SQL to create teacher_classes table</summary>
          <pre class="mt-2">
CREATE TABLE teacher_classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  role VARCHAR(64) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_teacher_class (teacher_id, class_id)
);
          </pre>
        </details>
      </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
          <input type="hidden" name="action" value="add">

          <div class="col-md-5">
            <label class="form-label">Teacher</label>
            <select name="teacher_id" class="form-select" required>
              <option value="">-- select teacher --</option>
              <?php foreach ($teachers as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-5">
            <label class="form-label">Class</label>
            <select name="class_id" class="form-select" required>
              <option value="">-- select class --</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Role (optional)</label>
            <input name="role" class="form-control" placeholder="e.g. Class Teacher">
          </div>

          <div class="col-12 text-end">
            <button class="btn btn-primary">Assign</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h6 class="mb-3">Existing Assignments</h6>
        <?php if (!empty($mappings)): ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Teacher</th>
                  <th>Class</th>
                  <th>Role</th>
                  <th>Assigned At</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($mappings as $row): ?>
                  <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo e($row['teacher_name'] ?? ('#' . (int)($row['teacher_id'] ?? 0))); ?></td>
                    <td><?php echo e($row['class_name'] ?? ('#' . (int)($row['class_id'] ?? 0))); ?></td>
                    <td><?php echo e($row['role'] ?? '—'); ?></td>
                    <td><?php echo e(substr($row['created_at'] ?? '', 0, 19)); ?></td>
                    <td class="text-end">
                      <form method="post" style="display:inline;" onsubmit="return confirm('Remove this assignment?');">
                        <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="small-muted">No assignments yet.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php
/* footer */
require_once __DIR__ . '/../includes/footer.php';

?>