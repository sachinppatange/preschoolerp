<?php
/**
 * owner/attendance.php
 *
 * Attendance management (Owner).
 *
 * Table: attendance
 * Fields:
 *   id (PK)
 *   school_id
 *   student_id
 *   class_id
 *   date            (YYYY-MM-DD)
 *   status          ('present'|'absent'|'leave' or similar)
 *   recorded_by     (users.id)
 *   notes
 *   created_at
 *
 * Features:
 *  - Owner-only page (requires $_SESSION['owner_auth_user'])
 *  - List with filters (search, date, class, student, status), pagination
 *  - Add / Edit (modal) with server-side validation
 *  - View modal fragment (HTML)
 *  - Quick status change links
 *  - Delete and Export CSV
 *
 * Place at: /pioneerplayschool01/owner/attendance.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Ensure table exists */
if (!table_exists('attendance')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>attendance</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* -------------------------
   Prepare data lists for filters/forms
   ------------------------- */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$studentList = table_exists('students') ? safe_db_get_all("SELECT id, first_name, last_name, class_id FROM students ORDER BY first_name, last_name ASC") : [];
$staffList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC") : [];

/* Allowed status values (UI help) */
$statusOptions = ['present','absent','leave'];

/* Messages and errors */
$messages = []; $errors = [];

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id   = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $student_id  = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : null;
    $class_id    = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $date        = trim((string)($_POST['date'] ?? date('Y-m-d')));
    $status      = in_array($_POST['status'] ?? 'present', $statusOptions, true) ? $_POST['status'] : 'present';
    $recorded_by = isset($_POST['recorded_by']) && $_POST['recorded_by'] !== '' ? (int)$_POST['recorded_by'] : null;
    $notes       = trim((string)($_POST['notes'] ?? ''));

    if (!$student_id) $errors[] = 'Student is required.';
    if ($date === '') $errors[] = 'Date is required.';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO attendance (school_id, student_id, class_id, date, status, recorded_by, notes, created_at)
                           VALUES (:school_id, :student_id, :class_id, :date, :status, :recorded_by, :notes, NOW())", [
            ':school_id'=>$school_id,
            ':student_id'=>$student_id,
            ':class_id'=>$class_id,
            ':date'=>$date,
            ':status'=>$status,
            ':recorded_by'=>$recorded_by,
            ':notes'=>$notes
        ]);
        if ($ok) { $messages[] = 'Attendance recorded.'; header('Location: ?'); exit; }
        $errors[] = 'Failed to insert record.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $student_id  = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : null;
    $class_id    = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $date        = trim((string)($_POST['date'] ?? ''));
    $status      = in_array($_POST['status'] ?? 'present', $statusOptions, true) ? $_POST['status'] : 'present';
    $recorded_by = isset($_POST['recorded_by']) && $_POST['recorded_by'] !== '' ? (int)$_POST['recorded_by'] : null;
    $notes       = trim((string)($_POST['notes'] ?? ''));

    if ($id <= 0) $errors[] = 'Invalid id.';
    if (!$student_id) $errors[] = 'Student is required.';
    if ($date === '') $errors[] = 'Date is required.';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE attendance SET student_id = :student_id, class_id = :class_id, date = :date, status = :status, recorded_by = :recorded_by, notes = :notes WHERE id = :id", [
            ':student_id'=>$student_id,
            ':class_id'=>$class_id,
            ':date'=>$date,
            ':status'=>$status,
            ':recorded_by'=>$recorded_by,
            ':notes'=>$notes,
            ':id'=>$id
        ]);
        if ($ok) { $messages[] = 'Attendance updated.'; header('Location: ?'); exit; }
        $errors[] = 'Failed to update record.';
    }
}

/* VIEW fragment (HTML) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT a.*, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS student_name, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS recorder_name
                            FROM attendance a
                            LEFT JOIN students s ON s.id = a.student_id
                            LEFT JOIN classes c ON c.id = a.class_id
                            LEFT JOIN users u ON u.id = a.recorded_by
                            WHERE a.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Record not found</div>'; exit; }

    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Date</dt><dd class="col-sm-9">'.e($row['date']).'</dd>';
    echo '<dt class="col-sm-3">Student</dt><dd class="col-sm-9">'.e($row['student_name'] ?: ('#'.$row['student_id'])).'</dd>';
    echo '<dt class="col-sm-3">Class</dt><dd class="col-sm-9">'.e($row['class_name'] ?: ('#'.$row['class_id'])).'</dd>';
    echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.e(ucfirst((string)$row['status'])).'</dd>';
    echo '<dt class="col-sm-3">Recorded by</dt><dd class="col-sm-9">'.($row['recorder_name'] ? e($row['recorder_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e((string)$row['notes']).'</pre></dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e((string)$row['created_at']).'</dd>';
    echo '</dl>';
    exit;
}

/* GET (for edit) returns JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, student_id, class_id, DATE_FORMAT(date,'%Y-%m-%d') AS date, status, recorded_by, notes FROM attendance WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$row]); exit;
}

/* Quick status change */
if ($action === 'status' && !empty($_GET['id']) && !empty($_GET['to'])) {
    $id = (int)$_GET['id'];
    $to = in_array($_GET['to'], $statusOptions, true) ? $_GET['to'] : null;
    if ($id <= 0 || $to === null) { $errors[] = 'Invalid status change.'; }
    else {
        $ok = safe_db_run("UPDATE attendance SET status = :s WHERE id = :id", [':s'=>$to, ':id'=>$id]);
        if ($ok) { $messages[] = 'Status updated.'; header('Location: ?'); exit; } else $errors[] = 'Status update failed.';
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
        $ok = safe_db_run("DELETE FROM attendance WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'Record deleted.'; else $errors[] = 'Delete failed.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) LIKE :q OR a.notes LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['date'])) { $where[] = "a.date = :date"; $params[':date'] = $_GET['date']; }
    if (!empty($_GET['class_id'])) { $where[] = "a.class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }
    if (!empty($_GET['student_id'])) { $where[] = "a.student_id = :student_id"; $params[':student_id'] = (int)$_GET['student_id']; }
    if (!empty($_GET['status']) && in_array($_GET['status'],$statusOptions,true)) { $where[] = "a.status = :status"; $params[':status'] = $_GET['status']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT a.*, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS student_name, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS recorder_name
                             FROM attendance a
                             LEFT JOIN students s ON s.id = a.student_id
                             LEFT JOIN classes c ON c.id = a.class_id
                             LEFT JOIN users u ON u.id = a.recorded_by
                             $whereSql
                             ORDER BY a.date DESC, a.id DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','Date','Student','Class','Status','Recorded By','Notes','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['date'] ?? '',
            $r['student_name'] ?: ($r['student_id'] ?? ''),
            $r['class_name'] ?: ($r['class_id'] ?? ''),
            $r['status'] ?? '',
            $r['recorder_name'] ?: ($r['recorded_by'] ?? ''),
            preg_replace("/\r\n|\r|\n/"," ", $r['notes'] ?? ''),
            $r['created_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   List: Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) LIKE :q OR a.notes LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$filterDate = trim((string)($_GET['date'] ?? ''));
if ($filterDate !== '') { $where[] = "a.date = :date"; $params[':date'] = $filterDate; }
if (!empty($_GET['class_id'])) { $where[] = "a.class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }
if (!empty($_GET['student_id'])) { $where[] = "a.student_id = :student_id"; $params[':student_id'] = (int)$_GET['student_id']; }
$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($statusFilter !== '' && in_array($statusFilter, $statusOptions, true)) { $where[] = "a.status = :status"; $params[':status'] = $statusFilter; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM attendance a LEFT JOIN students s ON s.id = a.student_id " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$rows = [];
try {
    $sql = "SELECT a.*, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS student_name, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS recorder_name
            FROM attendance a
            LEFT JOIN students s ON s.id = a.student_id
            LEFT JOIN classes c ON c.id = a.class_id
            LEFT JOIN users u ON u.id = a.recorded_by
            $whereSql
            ORDER BY a.date DESC, a.id DESC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int)ceil(max(0, $total) / $perPage);

/* Helper to build QS */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* -------------------------
   Render page
   ------------------------- */
$pageTitle = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add Record</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($qraw ?? ''); ?>" placeholder="Student name or notes"></div>
      <div class="col-md-2"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?php echo e($filterDate ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">Class</label>
        <select name="class_id" class="form-select"><option value="">Any</option><?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if(($_GET['class_id'] ?? '')===(string)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Student</label>
        <select name="student_id" class="form-select"><option value="">Any</option><?php foreach ($studentList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if(($_GET['student_id'] ?? '')===(string)$s['id']) echo 'selected'; ?>><?php echo e($s['first_name'] . ' ' . ($s['last_name'] ?? '')); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">Any</option><?php foreach ($statusOptions as $st): ?><option value="<?php echo e($st); ?>" <?php if(($statusFilter ?? '')===$st) echo 'selected'; ?>><?php echo e(ucfirst($st)); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-1 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th style="width:120px">Date</th>
            <th>Student</th>
            <th style="width:220px">Class / Status</th>
            <th>Recorded By / Notes</th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['date'] ?? ''); ?></td>
              <td><div class="fw-semibold"><?php echo e($r['student_name'] ?: ('#' . ($r['student_id'] ?? ''))); ?></div></td>
              <td>
                <div class="small text-muted"><?php echo e($r['class_name'] ?: ('#' . ($r['class_id'] ?? ''))); ?></div>
                <div class="mt-1"><span class="badge <?php echo ($r['status']==='present'?'bg-success':($r['status']==='absent'?'bg-danger':'bg-secondary')); ?>"><?php echo e(ucfirst($r['status'] ?? '')); ?></span></div>
                <div class="mt-2">
                  <?php foreach ($statusOptions as $st): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?action=status&id=<?php echo (int)$r['id']; ?>&to=<?php echo e($st); ?>"><?php echo e(ucfirst($st)); ?></a>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <div class="small text-muted"><?php echo e($r['recorder_name'] ?? '—'); ?></div>
                <div class="small"><?php echo e(mb_strimwidth($r['notes'] ?? '', 0, 120, '...')); ?></div>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <?php echo render_secure_delete_button((int)$r['id'], 'Delete', 'Delete record?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No attendance records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($rows)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1,$totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header"><h5 class="modal-title">Add Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?php echo e(date('Y-m-d')); ?>" required></div>
            <div class="col-md-4"><label class="form-label">Class</label>
              <select name="class_id" id="add_class_id" class="form-select"><option value="">Select class (optional)</option><?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-4"><label class="form-label">Student</label>
              <select name="student_id" class="form-select" required><option value="">Select student</option><?php foreach ($studentList as $s): ?><option value="<?php echo (int)$s['id']; ?>" data-class="<?php echo (int)$s['class_id']; ?>"><?php echo e($s['first_name'] . ' ' . ($s['last_name'] ?? '')); ?></option><?php endforeach; ?></select>
            </div>

            <div class="col-md-4"><label class="form-label">Status</label>
              <select name="status" class="form-select"><?php foreach ($statusOptions as $st): ?><option value="<?php echo e($st); ?>"><?php echo e(ucfirst($st)); ?></option><?php endforeach; ?></select>
            </div>

            <div class="col-md-4"><label class="form-label">Recorded by</label>
              <select name="recorded_by" class="form-select"><option value="">--</option><?php foreach ($staffList as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['name']); ?></option><?php endforeach; ?></select>
            </div>

            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="3" class="form-control"></textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editForm">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Attendance Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // Filter students by selected class (Add modal)
  var addClass = document.getElementById('add_class_id');
  if (addClass) addClass.addEventListener('change', function(){
    var cls = this.value;
    var sel = document.querySelector('select[name="student_id"]');
    if (!sel) return;
    Array.from(sel.options).forEach(function(opt){
      if (!opt.dataset.class) return;
      opt.style.display = (cls === '' || opt.dataset.class === cls) ? '' : 'none';
    });
  });

  // View: load fragment
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id'), body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), {credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load details.</div>'; });
    });
  }

  // Edit: fetch json and build form
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id'), body = document.getElementById('editBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), {credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; return; }
          var d = json.data; document.getElementById('edit_id').value = d.id || '';
          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-4"><label class="form-label">Date</label><input type="date" id="edit_date" name="date" class="form-control" value="'+(d.date||'')+'"></div>';
          html += '<div class="col-md-4"><label class="form-label">Class</label><select id="edit_class" name="class_id" class="form-select"><option value="">--</option>';
          <?php foreach ($classList as $c): ?> html += '<option value="<?php echo (int)$c['id']; ?>"><?php echo e(addslashes($c['name'])); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Student</label><select id="edit_student" name="student_id" class="form-select"><option value="">--</option>';
          <?php foreach ($studentList as $s): ?> html += '<option value="<?php echo (int)$s['id']; ?>" data-class="<?php echo (int)$s['class_id']; ?>"><?php echo e(addslashes($s['first_name'] . ' ' . ($s['last_name'] ?? ''))); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Status</label><select id="edit_status" name="status" class="form-select"><?php foreach ($statusOptions as $st): ?> html += \'<option value="<?php echo e($st); ?>"><?php echo e(ucfirst($st)); ?></option>\'; <?php endforeach; ?></select></div>';
          html += '<div class="col-md-4"><label class="form-label">Recorded by</label><select id="edit_rec" name="recorded_by" class="form-select"><option value="">--</option>';
          <?php foreach ($staffList as $u): ?> html += '<option value="<?php echo (int)$u['id']; ?>"><?php echo e(addslashes($u['name'])); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '<div class="col-12"><label class="form-label">Notes</label><textarea id="edit_notes" name="notes" rows="3" class="form-control"></textarea></div>';
          html += '</div>';
          body.innerHTML = html;
          document.getElementById('edit_class').value = d.class_id || '';
          document.getElementById('edit_student').value = d.student_id || '';
          document.getElementById('edit_status').value = d.status || '';
          document.getElementById('edit_rec').value = d.recorded_by || '';
          document.getElementById('edit_notes').value = d.notes || '';
          document.getElementById('edit_date').value = d.date || '';
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>