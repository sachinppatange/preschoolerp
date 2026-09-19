<?php
/**
 * reception/students_list.php
 *
 * Student management for Reception.
 * - Based on owner/students_list.php but adapted for reception area:
 *   - Requires $_SESSION['reception_auth_user']
 *   - Links and redirects point to /reception/*
 *   - Same features: list, search / filters / pagination, edit/view (modals), delete, export CSV
 *
 * UPDATE (2026-03-14):
 * - Added top links for:
 *     1) students_list_view.php
 *     2) students_list_edit.php
 * - Updated Actions column:
 *     - View button now links to students_list_view.php?id=...
 *     - Edit button now links to students_list_edit.php?id=...
 *     - (Delete kept as-is)
 * - No DB logic/functions changed.
 *
 * Place at: /pioneerplayschool01/reception/students_list.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/student_export.php';

/* Auth (reception) */
/* Optional includes (project helpers) */
$esc = function(string $v) { return e($v); };

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

/* Ensure students table exists */
if (!table_exists('students')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>students</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Status options */
$statusOptions = ['active','inactive','alumni','pending'];

/* Actions */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* ADD (server-side left intact but UI for add removed) */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? '')) ?: null;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $photo_path = trim((string)($_POST['photo_path'] ?? '')) ?: null;
    $admission_date = trim((string)($_POST['admission_date'] ?? '')) ?: null;
    $status = trim((string)($_POST['status'] ?? 'active'));

    if ($first_name === '') $errors[] = 'First name is required.';
    if ($status === '' || !in_array($status, $statusOptions, true)) $status = 'active';

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO students (school_id, first_name, last_name, dob, class_id, parent_id, photo_path, admission_date, status, created_at, updated_at)
                    VALUES (:school_id, :first_name, :last_name, :dob, :class_id, :parent_id, :photo_path, :admission_date, :status, NOW(), NOW())";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':first_name' => $first_name,
                ':last_name' => $last_name !== '' ? $last_name : null,
                ':dob' => $dob,
                ':class_id' => $class_id,
                ':parent_id' => $parent_id,
                ':photo_path' => $photo_path,
                ':admission_date' => $admission_date,
                ':status' => $status
            ]);
            if ($ok) { $messages[] = 'Student added.'; header('Location: ?'); exit; }
            else $errors[] = 'Failed to add student.';
        } catch (Throwable $e) {
            error_log('student add error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to add student.';
        }
    }
}

/* GET (for edit modal) - JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, first_name, last_name, dob, class_id, parent_id, photo_path, admission_date, status FROM students WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Student not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* VIEW fragment (kept intact) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("
        SELECT st.*, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS parent_name
        FROM students st
        LEFT JOIN classes c ON c.id = st.class_id
        LEFT JOIN users u ON u.id = st.parent_id
        WHERE st.id = :id LIMIT 1
    ", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">Student not found</div>'; exit; }

    echo '<div class="row g-3">';
    echo '<div class="col-md-4 text-center">';
    if (!empty($row['photo_path'])) {
        $p = e($row['photo_path']);
        echo '<img src="'. $p .'" alt="photo" class="img-fluid rounded" style="max-height:220px;">';
    } else {
        echo '<div class="border rounded p-4 text-muted">No photo</div>';
    }
    echo '</div>';
    echo '<div class="col-md-8">';
    echo '<dl class="row">';
    echo '<dt class="col-sm-4">ID</dt><dd class="col-sm-8">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-4">Name</dt><dd class="col-sm-8">'.e($row['first_name'] . ($row['last_name'] ? ' ' . $row['last_name'] : '')).'</dd>';
    echo '<dt class="col-sm-4">DOB</dt><dd class="col-sm-8">'.($row['dob'] ? e(date('d M Y', strtotime($row['dob']))) : '—').'</dd>';
    echo '<dt class="col-sm-4">Class</dt><dd class="col-sm-8">'.($row['class_name'] ? e($row['class_name']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Parent</dt><dd class="col-sm-8">'.($row['parent_name'] ? e($row['parent_name']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Admission Date</dt><dd class="col-sm-8">'.($row['admission_date'] ? e(date('d M Y', strtotime($row['admission_date']))) : '—').'</dd>';
    echo '<dt class="col-sm-4">Status</dt><dd class="col-sm-8">'.e(ucfirst($row['status'] ?? '')).'</dd>';
    echo '<dt class="col-sm-4">Created</dt><dd class="col-sm-8">'.e($row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-4">Updated</dt><dd class="col-sm-8">'.e($row['updated_at'] ?? '').'</dd>';
    echo '</dl>';
    echo '</div></div>';
    exit;
}

/* EDIT (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $dob = trim((string)($_POST['dob'] ?? '')) ?: null;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $photo_path = trim((string)($_POST['photo_path'] ?? '')) ?: null;
    $admission_date = trim((string)($_POST['admission_date'] ?? '')) ?: null;
    $status = trim((string)($_POST['status'] ?? 'active'));

    if ($id <= 0) $errors[] = 'Invalid student id.';
    if ($first_name === '') $errors[] = 'First name is required.';
    if ($status === '' || !in_array($status, $statusOptions, true)) $status = 'active';

    if (empty($errors)) {
        try {
            $sql = "UPDATE students SET school_id = :school_id, first_name = :first_name, last_name = :last_name, dob = :dob, class_id = :class_id, parent_id = :parent_id, photo_path = :photo_path, admission_date = :admission_date, status = :status, updated_at = NOW() WHERE id = :id";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':first_name' => $first_name,
                ':last_name' => $last_name !== '' ? $last_name : null,
                ':dob' => $dob,
                ':class_id' => $class_id,
                ':parent_id' => $parent_id,
                ':photo_path' => $photo_path,
                ':admission_date' => $admission_date,
                ':status' => $status,
                ':id' => $id
            ]);
            if ($ok) { $messages[] = 'Student updated.'; header('Location: ?'); exit; }
            else $errors[] = 'Failed to update student.';
        } catch (Throwable $e) {
            error_log('student edit error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to update student.';
        }
    }
}

/* DELETE */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId;
    if ($id > 0) {
        $ok = safe_db_run("DELETE FROM students WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'Student deleted.';
        else $errors[] = 'Failed to delete student.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV / Excel / PDF — same Academic Year + filters as the list */
if ($action === 'export') {
    student_handle_export();
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$listFilters = student_list_filters();
$where = $listFilters['where'];
$params = $listFilters['params'];
$qraw = $listFilters['q'];
$classFilter = $listFilters['class_id'];
$statusFilter = $listFilters['status'];
$genderFilter = $listFilters['gender'];

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM students s " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$students = [];
try {
    $sql = "SELECT s.id, s.school_id, s.first_name, s.last_name, s.dob, s.class_id, COALESCE(c.name,'') AS class_name, s.parent_id, COALESCE(u.name,'') AS parent_name, s.photo_path, s.admission_date, s.status, s.created_at, s.updated_at
            FROM students s
            LEFT JOIN classes c ON c.id = s.class_id
            LEFT JOIN users u ON u.id = s.parent_id
            $whereSql
            ORDER BY s.first_name ASC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $students = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $students = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int)ceil(max(0, $total) / $perPage);

/* Data for forms: classes & parents */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$parentList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users WHERE role = 'parent' ORDER BY name ASC") : [];

/* Helper to build querystring */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = 'Students (Reception)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex gap-2 flex-wrap justify-content-end mb-3">
  <a class="btn btn-success" href="admission.php">New Admission</a>
  <a class="btn btn-outline-secondary" href="?">Refresh</a>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; ?>

  <div class="alert alert-info py-2">
    <?php echo e(function_exists('ay_display_long') ? ay_display_long() : ''); ?> — only this year's students are listed and exported.
  </div>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Search</label>
        <input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Name, form no, phone">
      </div>

      <div class="col-md-2">
        <label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="">Any</option>
          <?php foreach ($classList as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if ($classFilter === (int)$c['id']) echo 'selected'; ?>>
              <?php echo $esc($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="all" <?php if (($statusFilter ?? '') === '') echo 'selected'; ?>>All</option>
          <option value="active" <?php if (($statusFilter ?? '') === 'active') echo 'selected'; ?>>Current</option>
          <option value="inactive" <?php if (($statusFilter ?? '') === 'inactive') echo 'selected'; ?>>Left</option>
          <option value="alumni" <?php if (($statusFilter ?? '') === 'alumni') echo 'selected'; ?>>Alumni</option>
          <option value="pending" <?php if (($statusFilter ?? '') === 'pending') echo 'selected'; ?>>Pending</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Gender</label>
        <select name="gender" class="form-select">
          <option value="">Any</option>
          <option value="male" <?php if (($genderFilter ?? '') === 'male') echo 'selected'; ?>>Male</option>
          <option value="female" <?php if (($genderFilter ?? '') === 'female') echo 'selected'; ?>>Female</option>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="?">Reset</a>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-success" href="?<?php echo e(student_export_query_string('csv')); ?>">Export CSV</a>
        <a class="btn btn-sm btn-outline-success" href="?<?php echo e(student_export_query_string('excel')); ?>">Export Excel</a>
        <a class="btn btn-sm btn-outline-primary" href="?<?php echo e(student_export_query_string('pdf')); ?>" target="_blank">Export PDF</a>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="card card-soft">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:64px">ID</th>
            <th>Name / DOB</th>
            <th>Class / Parent</th>
            <th>Admission</th>
            <th style="width:120px">Status</th>
            <th style="width:240px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($students)): foreach ($students as $s): ?>
            <tr>
              <td><?php echo (int)$s['id']; ?></td>

              <td>
                <div class="d-flex align-items-center gap-3">
                  <?php $photoUrl = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : ''; ?>
                  <?php if ($photoUrl !== ''): ?>
                    <img src="<?php echo $esc($photoUrl); ?>" class="student-photo" alt="photo">
                  <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:8px;background:#eef2ff"></div>
                  <?php endif; ?>
                  <div>
                    <div class="fw-semibold">
                      <?php echo $esc($s['first_name'] . ($s['last_name'] ? ' ' . $s['last_name'] : '')); ?>
                    </div>
                    <div class="small text-muted">
                      <?php echo $s['dob'] ? e(date('d M Y', strtotime($s['dob']))) : '—'; ?>
                    </div>
                  </div>
                </div>
              </td>

              <td>
                <div class="small text-muted"><?php echo $esc($s['class_name'] ?? ''); ?></div>
                <div><?php echo $esc($s['parent_name'] ?? ''); ?></div>
              </td>

              <td><?php echo $s['admission_date'] ? e(date('d M Y', strtotime($s['admission_date']))) : '—'; ?></td>

              <td>
                <span class="badge <?php echo ($s['status']==='active' ? 'bg-success' : ($s['status']==='pending' ? 'bg-warning text-dark' : 'bg-secondary')); ?>">
                  <?php echo $esc(ucfirst($s['status'])); ?>
                </span>
              </td>

              <td>
                <a class="btn btn-sm btn-outline-info" href="students_view.php?id=<?php echo (int)$s['id']; ?>">View</a>
                <a class="btn btn-sm btn-outline-warning" href="students_list_edit.php?id=<?php echo (int)$s['id']; ?>">Edit</a>
                <a class="btn btn-sm btn-outline-success" href="students_form_print.php?id=<?php echo (int)$s['id']; ?>" target="_blank">PDF</a>
                <a class="btn btn-sm btn-outline-primary" href="id_cards.php?id=<?php echo (int)$s['id']; ?>&amp;print=1" target="_blank">ID card</a>
                <?php echo function_exists('render_secure_delete_button') ? render_secure_delete_button((int)$s['id'], 'Delete', 'Delete student?') : ''; ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No students found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>
        Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($students)); ?>
        of <?php echo $total; ?>
      </div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>">
              <a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Modals kept intact (no logic removed). They won't be used if you click links, but left here to avoid changing logic. -->

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editStudentForm">
        <div class="modal-header"><h5 class="modal-title">Edit Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <input type="hidden" name="id" id="edit_id">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">First Name *</label><input name="first_name" id="edit_first_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Last Name</label><input name="last_name" id="edit_last_name" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">DOB</label><input type="date" name="dob" id="edit_dob" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Class</label>
              <select name="class_id" id="edit_class_id" class="form-select">
                <option value="">Select class</option>
                <?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo $esc($c['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Parent</label>
              <select name="parent_id" id="edit_parent_id" class="form-select">
                <option value="">Select parent</option>
                <?php foreach ($parentList as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo $esc($p['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Photo URL / Path</label><input name="photo_path" id="edit_photo_path" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Admission Date</label><input type="date" name="admission_date" id="edit_admission_date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <?php foreach ($statusOptions as $st): ?><option value="<?php echo $esc($st); ?>"><?php echo $esc(ucfirst($st)); ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View modal -->
<div class="modal fade" id="viewStudentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Student details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="viewStudentBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Edit modal: fetch JSON and populate
  var editModal = document.getElementById('editStudentModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      ['edit_id','edit_first_name','edit_last_name','edit_dob','edit_class_id','edit_parent_id','edit_photo_path','edit_admission_date','edit_status'].forEach(function(idn){
        var el = document.getElementById(idn);
        if (el) el.value = '';
      });
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (json && json.ok && json.data) {
            var d = json.data;
            document.getElementById('edit_id').value = d.id || '';
            document.getElementById('edit_first_name').value = d.first_name || '';
            document.getElementById('edit_last_name').value = d.last_name || '';
            document.getElementById('edit_dob').value = d.dob || '';
            document.getElementById('edit_class_id').value = d.class_id || '';
            document.getElementById('edit_parent_id').value = d.parent_id || '';
            document.getElementById('edit_photo_path').value = d.photo_path || '';
            document.getElementById('edit_admission_date').value = d.admission_date || '';
            document.getElementById('edit_status').value = d.status || '';
          } else {
            alert(json.error || 'Failed to load student for edit.');
            var mdl = bootstrap.Modal.getInstance(editModal);
            if (mdl) mdl.hide();
          }
        })
        .catch(function(){
          alert('Failed to load student for edit.');
          var mdl = bootstrap.Modal.getInstance(editModal);
          if (mdl) mdl.hide();
        });
    });
  }

  // View modal: load fragment
  var viewModal = document.getElementById('viewStudentModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewStudentBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>