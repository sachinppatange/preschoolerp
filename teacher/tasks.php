<?php
/**
 * teacher/tasks.php
 *
 * Tasks management for teachers.
 *
 * - Shows tasks from `tasks` table (columns as provided):
 *   id, school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at
 * - Teacher sees tasks relevant to their assigned classes:
 *   - Tasks assigned to class: assigned_to = 'class:<class_id>'
 *   - Tasks assigned to students in that class: assigned_to = '<student_id>'
 * - Teacher can create a task for entire class or selected students (server-side checks).
 * - Supports add (modal), edit (modal via AJAX), view (modal fragment), delete, filters, pagination.
 * - Uses CSRF protection and safe PDO prepared statements. Reuses project includes if present.
 *
 * Place at: /pioneerplayschool01/teacher/tasks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* Optional project includes */
/* Debug flag */
/* ----------------------
   DB helper fallbacks
   ---------------------- */

$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ----------------------
   Table check
   ---------------------- */
$tasksTable = 'tasks';
if (!table_exists($tasksTable)) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Table <strong>tasks</strong> not found. Create it before using this page.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ----------------------
   Find assigned classes for teacher
   ---------------------- */
$assignedClasses = panel_teacher_assigned_classes($teacherId);
$assignedClassIds = array();
foreach ($assignedClasses as $c) $assignedClassIds[] = (int)$c['id'];

/* ----------------------
   Selected class & students
   ---------------------- */
$selectedClassId = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
if ($selectedClassId === 0 && !empty($assignedClassIds)) $selectedClassId = $assignedClassIds[0];
if ($selectedClassId > 0 && !in_array($selectedClassId, $assignedClassIds, true)) $selectedClassId = ($assignedClassIds[0] ?? 0);

$students = array();
if ($selectedClassId > 0 && table_exists('students')) {
    $students = safe_db_get_all("SELECT id, first_name, middle_name, last_name, form_no FROM students WHERE class_id = :cid AND (status IS NULL OR status = 'active') ORDER BY form_no ASC, first_name ASC", array(':cid' => $selectedClassId));
}
$studentsCount = count($students);

/* ----------------------
   Handle create task
   ---------------------- */
$messages = array();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
        $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $due_date = isset($_POST['due_date']) && $_POST['due_date'] !== '' ? trim((string)$_POST['due_date']) : null;
        $priority = isset($_POST['priority']) ? trim((string)$_POST['priority']) : 'medium';
        $assign_mode = isset($_POST['assign_mode']) ? trim((string)$_POST['assign_mode']) : 'class';
        $selected_students = array();
        if (isset($_POST['students']) && is_array($_POST['students'])) {
            foreach ($_POST['students'] as $sid) $selected_students[] = (int)$sid;
        }

        if ($class_id <= 0) $errors[] = 'Select a class.';
        if (!in_array($class_id, $assignedClassIds, true)) $errors[] = 'You are not assigned to the selected class.';
        if ($title === '') $errors[] = 'Title is required.';
        if ($assign_mode === 'students' && empty($selected_students)) $errors[] = 'Select at least one student or choose assign to class.';
        if ($due_date !== null && $due_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) $errors[] = 'Due date must be YYYY-MM-DD.';

        if (empty($errors)) {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) {
                $errors[] = 'Database connection failed.';
            } else {
                try {
                    if ($assign_mode === 'class') {
                        $assigned_to = 'class:' . $class_id;
                        $sql = "INSERT INTO `{$tasksTable}` (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
                                VALUES (:school_id, :title, :description, :assigned_to, :due_date, :priority, :status, NOW(), NOW())";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(array(
                            ':school_id' => null,
                            ':title' => $title,
                            ':description' => $description,
                            ':assigned_to' => $assigned_to,
                            ':due_date' => $due_date,
                            ':priority' => $priority,
                            ':status' => 'pending'
                        ));
                    } else {
                        $sql = "INSERT INTO `{$tasksTable}` (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
                                VALUES (:school_id, :title, :description, :assigned_to, :due_date, :priority, :status, NOW(), NOW())";
                        $stmt = $pdo->prepare($sql);
                        foreach ($selected_students as $sid) {
                            $ok = safe_db_get_one("SELECT id FROM students WHERE id = :sid AND class_id = :cid LIMIT 1", array(':sid' => $sid, ':cid' => $class_id));
                            if (!$ok) continue;
                            $stmt->execute(array(
                                ':school_id' => null,
                                ':title' => $title,
                                ':description' => $description,
                                ':assigned_to' => (string)$sid,
                                ':due_date' => $due_date,
                                ':priority' => $priority,
                                ':status' => 'pending'
                            ));
                        }
                    }
                    $messages[] = 'Task(s) created.';
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?class_id=' . $class_id);
                    exit;
                } catch (Throwable $e) {
                    $errors[] = 'Database error: ' . ($DEBUG ? $e->getMessage() : 'Failed to create task(s).');
                }
            }
        }
    }
}

/* ----------------------
   Handle edit (POST) and delete (GET) similarly to owner page
   For brevity: basic edit via POST when action=edit and id present
   ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (string)$_POST['assigned_to'] : null;
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $priority = trim((string)($_POST['priority'] ?? 'medium'));
    $status = trim((string)($_POST['status'] ?? 'pending'));

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($title === '') $errors[] = 'Title required.';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE `{$tasksTable}` SET title = :title, description = :description, assigned_to = :assigned_to, due_date = :due_date, priority = :priority, status = :status, updated_at = NOW() WHERE id = :id",
            array(':title' => $title, ':description' => $description, ':assigned_to' => $assigned_to, ':due_date' => $due_date, ':priority' => $priority, ':status' => $status, ':id' => $id));
        if ($ok) { $messages[] = 'Task updated.'; header('Location: ?class_id=' . $selectedClassId); exit; } else $errors[] = 'Update failed.';
    }
}

/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId;
    $ok = safe_db_run("DELETE FROM `{$tasksTable}` WHERE id = :id", array(':id'=>$id));
    if ($ok) { $messages[] = 'Task deleted.'; header('Location: ?class_id=' . $selectedClassId); exit; } else $errors[] = 'Delete failed.';
}

/* ----------------------
   Load tasks relevant to selected class:
   - class-level tasks: assigned_to = 'class:<id>'
   - student-level tasks: assigned_to IN (<student ids>)
   ---------------------- */
$tasks = array();
if ($selectedClassId > 0) {
    $sids = array();
    foreach ($students as $s) $sids[] = (int)$s['id'];

    if (!empty($sids)) {
        $ph = implode(',', array_fill(0, count($sids), '?'));
        $sql = "SELECT * FROM `{$tasksTable}` WHERE assigned_to = ? OR assigned_to IN ($ph) ORDER BY due_date ASC, priority DESC, created_at DESC";
        $params = array_merge(array('class:' . $selectedClassId), array_map('strval', $sids));
        $tasks = safe_db_get_all($sql, $params);
    } else {
        $tasks = safe_db_get_all("SELECT * FROM `{$tasksTable}` WHERE assigned_to = :class_assigned ORDER BY due_date ASC, priority DESC, created_at DESC", array(':class_assigned' => 'class:' . $selectedClassId));
    }
}

/* CSRF token */
$csrf = get_csrf_token();

/* ----------------------
   Render HTML
   ---------------------- */
$pageTitle = 'Tasks (Teacher)';
require_once __DIR__ . '/../includes/header.php';
?>

    <div>
      <a class="btn btn-outline-secondary btn-sm" href="/teacher/my_classes.php">My Classes</a>
      <a class="btn btn-danger btn-sm" href="/teacher/logout.php">Logout</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <div class="row g-3">
    <div class="col-md-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6>Create Task</h6>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="create">

            <div class="mb-2">
              <label class="form-label small">Class</label>
              <select name="class_id" class="form-select" required <?php if (empty($assignedClasses)) echo 'disabled'; ?>>
                <option value="">Select class</option>
                <?php foreach ($assignedClasses as $c): $cid=(int)$c['id']; $label = (!empty($c['short_name']) ? $c['short_name'].' ' : '') . ($c['name'] ?? ''); ?>
                  <option value="<?php echo $cid; ?>" <?php if ($cid === $selectedClassId) echo 'selected'; ?>><?php echo e($label ?: ('Class #'.$cid)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small">Title</label>
              <input name="title" class="form-control" required>
            </div>

            <div class="mb-2">
              <label class="form-label small">Description</label>
              <textarea name="description" rows="4" class="form-control"></textarea>
            </div>

            <div class="mb-2 row">
              <div class="col">
                <label class="form-label small">Due date</label>
                <input type="date" name="due_date" class="form-control">
              </div>
              <div class="col">
                <label class="form-label small">Priority</label>
                <select name="priority" class="form-select">
                  <option value="low">Low</option>
                  <option value="medium" selected>Medium</option>
                  <option value="high">High</option>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small">Assign to</label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="assign_mode" id="assign_class" value="class" checked>
                  <label class="form-check-label small" for="assign_class">Entire class</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="assign_mode" id="assign_students" value="students">
                  <label class="form-check-label small" for="assign_students">Selected students</label>
                </div>
              </div>
            </div>

            <div class="mb-2" id="studentsBox" style="display:none;">
              <label class="form-label small">Select students</label>
              <?php if ($studentsCount === 0): ?>
                <div class="small-muted">No students found in this class.</div>
              <?php else: ?>
                <div style="max-height:240px;overflow:auto;border:1px solid #eee;padding:.5rem;border-radius:.25rem;">
                  <?php foreach ($students as $s): $sid=(int)$s['id']; $sname = trim(($s['form_no'] ?? '') . ' ' . ($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')); ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="students[]" value="<?php echo $sid; ?>" id="st<?php echo $sid; ?>">
                      <label class="form-check-label small" for="st<?php echo $sid; ?>"><?php echo e($sname ?: 'Student #'.$sid); ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="d-grid mt-3">
              <button class="btn btn-primary" <?php if (empty($assignedClasses)) echo 'disabled'; ?>>Create Task</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-md-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6>Tasks for <?php $cn = array_values(array_filter($assignedClasses, function($c) use ($selectedClassId){ return (int)$c['id'] === $selectedClassId; })); echo e($cn[0]['name'] ?? ('Class #' . $selectedClassId)); ?></h6>

          <?php if (empty($tasks)): ?>
            <div class="small-muted">No tasks found for this class.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($tasks as $t): 
                $aid = $t['assigned_to'] ?? '';
                $assignedLabel = '';
                if (strpos((string)$aid, 'class:') === 0) {
                    $assignedLabel = 'Entire class';
                } elseif (is_numeric((string)$aid) && (int)$aid > 0) {
                    $st = safe_db_get_one("SELECT first_name, middle_name, last_name, form_no FROM students WHERE id = :id LIMIT 1", array(':id' => (int)$aid));
                    if ($st) $assignedLabel = trim(($st['form_no'] ?? '') . ' ' . ($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
                    else $assignedLabel = 'Student #' . (int)$aid;
                } else {
                    $assignedLabel = e((string)$aid);
                }
              ?>
                <div class="list-group-item">
                  <div class="d-flex justify-content-between">
                    <div>
                      <div class="fw-semibold"><?php echo e($t['title'] ?? ''); ?></div>
                      <div class="small-muted"><?php echo e(mb_strimwidth((string)($t['description'] ?? ''), 0, 200, '...')); ?></div>
                      <div class="small-muted mt-1">Due: <?php echo e($t['due_date'] ?? '—'); ?> · Priority: <?php echo e($t['priority'] ?? '—'); ?> · Assigned: <?php echo e($assignedLabel); ?></div>
                    </div>
                    <div class="text-end">
                      <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$t['id']; ?>">View</button>
                      <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-id="<?php echo (int)$t['id']; ?>">Edit</button>
                      <?php echo render_secure_delete_button((int)$t['id'], 'Delete', 'Delete this task?', 'btn btn-sm btn-outline-danger', ['class_id' => $selectedClassId]); ?>
                      <div class="small text-muted mt-1"><?php echo e(substr((string)$t['created_at'],0,16)); ?></div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- View / Edit modals (small JS injection like owner page) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Task details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editTaskForm">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editTaskBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Show/hide students box when choosing assign mode
  var assignStudentsRadio = document.getElementById('assign_students');
  var assignClassRadio = document.getElementById('assign_class');
  var studentsBox = document.getElementById('studentsBox');
  function updateStudentsBox(){
    if (assignStudentsRadio && assignStudentsRadio.checked) studentsBox.style.display = '';
    else studentsBox.style.display = 'none';
  }
  if (assignStudentsRadio && assignClassRadio) {
    assignStudentsRadio.addEventListener('change', updateStudentsBox);
    assignClassRadio.addEventListener('change', updateStudentsBox);
    updateStudentsBox();
  }

  // View modal loader
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }

  // Edit modal loader
  var editModal = document.getElementById('editTaskModal');
  var staff = <?php echo json_encode(table_exists('users') ? safe_db_get_all("SELECT id,name FROM users ORDER BY name ASC") : array(), JSON_UNESCAPED_UNICODE); ?>;
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editTaskBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load task.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-8"><label class="form-label">Title</label><input id="edit_title" name="title" class="form-control" required></div>';
          html += '<div class="col-md-4"><label class="form-label">Assign to</label><select id="edit_assigned_to" name="assigned_to" class="form-select"><option value="">Unassigned</option>';
          staff.forEach(function(s){ html += '<option value="'+s.id+'">'+s.name+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-12"><label class="form-label">Description</label><textarea id="edit_description" name="description" rows="5" class="form-control" required></textarea></div>';
          html += '<div class="col-md-3"><label class="form-label">Due date</label><input id="edit_due_date" name="due_date" type="date" class="form-control"></div>';
          html += '<div class="col-md-3"><label class="form-label">Priority</label><select id="edit_priority" name="priority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>';
          html += '<div class="col-md-3"><label class="form-label">Status</label><select id="edit_status" name="status" class="form-select"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="done">Done</option></select></div>';
          html += '<div class="col-md-3"><label class="form-label">School ID</label><input id="edit_school_id" name="school_id" class="form-control"></div>';
          html += '</div>';
          body.innerHTML = html;
          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_description').value = d.description || '';
          document.getElementById('edit_assigned_to').value = d.assigned_to || '';
          document.getElementById('edit_due_date').value = d.due_date || '';
          document.getElementById('edit_priority').value = d.priority || 'medium';
          document.getElementById('edit_status').value = d.status || 'pending';
          document.getElementById('edit_school_id').value = d.school_id || 1;
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>