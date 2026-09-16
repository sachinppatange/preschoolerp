<?php
/**
 * teacher/student_remarks.php
 *
 * Student remarks management for teachers.
 *
 * Features:
 * - Requires teacher login ($_SESSION['teacher_auth_user']).
 * - Shows remarks for students in classes assigned to the teacher.
 * - Supports: list, add (modal), edit (modal via AJAX), view (modal fragment), delete, export CSV.
 * - Filters: class, student, type, date range, search text.
 * - Permissions: teachers can only add/edit/delete remarks for classes assigned to them.
 * - Optional DB table schema (adapt if needed):
 *     CREATE TABLE student_remarks (
 *       id INT AUTO_INCREMENT PRIMARY KEY,
 *       student_id INT NOT NULL,
 *       class_id INT NOT NULL,
 *       teacher_id INT NOT NULL,
 *       remark TEXT NOT NULL,
 *       type VARCHAR(50) DEFAULT 'note',
 *       date DATE DEFAULT NULL,
 *       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *       updated_at DATETIME DEFAULT NULL
 *     );
 *
 * Place at: /pioneerplayschool01/teacher/student_remarks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* misc helpers */

/* CSRF fallback */

$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* -----------------------------
   Table check
   ----------------------------- */
$remarksTable = 'student_remarks';
if (!table_exists($remarksTable)) {
    // If the table doesn't exist show a friendly message (no DB operations will run)
    $hasRemarksTable = false;
} else {
    $hasRemarksTable = true;
}

/* -----------------------------
   Find assigned classes (reuse pattern)
   ----------------------------- */
$assignedClasses = panel_teacher_assigned_classes($teacherId);
$assignedClassIds = array_map(fn($c)=>(int)$c['id'], $assignedClasses);

/* -----------------------------
   Handle actions: add/edit/delete/export/get/view
   ----------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];
$types = ['note','warning','commendation','behavior']; // example types

/* Helper: check teacher allowed for class */
function teacher_allowed_for_class(int $teacherId, int $classId, array $allowedIds): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    return in_array($classId, $allowedIds, true);
}

/* ADD remark */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasRemarksTable) { $errors[] = 'Remarks feature not available (no table).'; }
    elseif (!validate_csrf_token($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
    else {
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $remark = trim((string)($_POST['remark'] ?? ''));
        $type = in_array($_POST['type'] ?? 'note', $types, true) ? $_POST['type'] : 'note';
        $date = trim((string)($_POST['date'] ?? '')) ?: null;

        if ($class_id <= 0) $errors[] = 'Class required.';
        if ($student_id <= 0) $errors[] = 'Student required.';
        if ($remark === '') $errors[] = 'Remark required.';
        if (!teacher_allowed_for_class($teacherId, $class_id, $assignedClassIds)) $errors[] = 'Not allowed for this class.';

        if (empty($errors)) {
            $ok = safe_db_run("INSERT INTO {$remarksTable} (student_id,class_id,teacher_id,remark,type,date,created_at) VALUES (:sid,:cid,:tid,:remark,:type,:date,NOW())",
                [':sid'=>$student_id,':cid'=>$class_id,':tid'=>$teacherId,':remark'=>$remark,':type'=>$type,':date'=>$date]);
            if ($ok) { $messages[] = 'Remark added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
        }
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasRemarksTable) { $errors[] = 'Remarks feature not available.'; }
    elseif (!validate_csrf_token($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
    else {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $remark = trim((string)($_POST['remark'] ?? ''));
        $type = in_array($_POST['type'] ?? 'note', $types, true) ? $_POST['type'] : 'note';
        $date = trim((string)($_POST['date'] ?? '')) ?: null;

        if ($id <= 0) $errors[] = 'Invalid id.';
        if ($class_id <= 0) $errors[] = 'Class required.';
        if ($student_id <= 0) $errors[] = 'Student required.';
        if ($remark === '') $errors[] = 'Remark required.';
        if (!teacher_allowed_for_class($teacherId, $class_id, $assignedClassIds)) $errors[] = 'Not allowed for this class.';

        if (empty($errors)) {
            // ensure remark exists and belongs to class and teacher OR teacher of same class can edit (policy)
            $row = safe_db_get_one("SELECT * FROM {$remarksTable} WHERE id = :id LIMIT 1", [':id'=>$id]);
            if (!$row) { $errors[] = 'Remark not found.'; }
            elseif (!teacher_allowed_for_class($teacherId, (int)$row['class_id'], $assignedClassIds)) { $errors[] = 'Not allowed to edit this remark.'; }
            else {
                $ok = safe_db_run("UPDATE {$remarksTable} SET student_id = :sid, class_id = :cid, remark = :remark, type = :type, date = :date, updated_at = NOW() WHERE id = :id",
                    [':sid'=>$student_id,':cid'=>$class_id,':remark'=>$remark,':type'=>$type,':date'=>$date,':id'=>$id]);
                if ($ok) { $messages[] = 'Remark updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
            }
        }
    }
}

/* DELETE */
if ($action === 'delete' && !empty($_GET['id'])) {
    if (!$hasRemarksTable) { $errors[] = 'Remarks feature not available.'; }
    else {
        $id = (int)$_GET['id'];
        $row = safe_db_get_one("SELECT * FROM {$remarksTable} WHERE id = :id LIMIT 1", [':id'=>$id]);
        if (!$row) { $errors[] = 'Not found.'; }
        elseif (!teacher_allowed_for_class($teacherId, (int)$row['class_id'], $assignedClassIds)) { $errors[] = 'Not allowed to delete.'; }
        else {
            $ok = safe_db_run("DELETE FROM {$remarksTable} WHERE id = :id", [':id'=>$id]);
            if ($ok) { $messages[] = 'Remark deleted.'; header('Location: ?'); exit; } else $errors[] = 'Delete failed.';
        }
    }
}

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT r.*, s.first_name, s.last_name, COALESCE(c.name,'') AS class_name, u.name AS teacher_name
                            FROM {$remarksTable} r
                            LEFT JOIN students s ON s.id = r.student_id
                            LEFT JOIN classes c ON c.id = r.class_id
                            LEFT JOIN users u ON u.id = r.teacher_id
                            WHERE r.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Remark not found</div>'; exit; }
    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Student</dt><dd class="col-sm-9">'.e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))).'</dd>';
    echo '<dt class="col-sm-3">Class</dt><dd class="col-sm-9">'.e($row['class_name'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Type</dt><dd class="col-sm-9">'.e($row['type'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Date</dt><dd class="col-sm-9">'.e($row['date'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Remark</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['remark'] ?? '').'</pre></dd>';
    echo '<dt class="col-sm-3">By</dt><dd class="col-sm-9">'.e($row['teacher_name'] ?? '').' at '.e($row['created_at'] ?? '').'</dd>';
    echo '</dl>';
    exit;
}

/* GET remark JSON (for edit modal) */
if ($action === 'get' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, student_id, class_id, remark, type, DATE_FORMAT(date,'%Y-%m-%d') AS date FROM {$remarksTable} WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Remark not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* EXPORT CSV */
if ($action === 'export') {
    if (!$hasRemarksTable) { $errors[] = 'Remarks feature not available.'; }
    else {
        $where = []; $params = [];
        // restrict to teacher's classes
        if (!empty($assignedClassIds)) {
            $where[] = 'r.class_id IN (' . implode(',', array_map('intval', $assignedClassIds)) . ')';
        }
        if (!empty($_GET['class_id']) && in_array((int)$_GET['class_id'], $assignedClassIds, true)) { $where[] = 'r.class_id = :class_id'; $params[':class_id'] = (int)$_GET['class_id']; }
        if (!empty($_GET['student_id'])) { $where[] = 'r.student_id = :student_id'; $params[':student_id'] = (int)$_GET['student_id']; }
        if (!empty($_GET['type'])) { $where[] = 'r.type = :type'; $params[':type'] = $_GET['type']; }
        if (!empty($_GET['from'])) { $where[] = 'r.date >= :from'; $params[':from'] = $_GET['from']; }
        if (!empty($_GET['to'])) { $where[] = 'r.date <= :to'; $params[':to'] = $_GET['to']; }
        if (!empty($_GET['q'])) { $where[] = '(r.remark LIKE :q)'; $params[':q'] = '%' . trim((string)$_GET['q']) . '%'; }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = safe_db_get_all("SELECT r.*, s.first_name, s.last_name, COALESCE(c.name,'') AS class_name, u.name AS teacher_name
                                 FROM {$remarksTable} r
                                 LEFT JOIN students s ON s.id = r.student_id
                                 LEFT JOIN classes c ON c.id = r.class_id
                                 LEFT JOIN users u ON u.id = r.teacher_id
                                 $whereSql
                                 ORDER BY r.date DESC, r.created_at DESC", $params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=remarks_'.date('Ymd_His').'.csv');
        $out = fopen('php://output','w');
        fputcsv($out, ['ID','Class','Student','Type','Date','Remark','By','Created At']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'] ?? '',
                $r['class_name'] ?? $r['class_id'] ?? '',
                trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                $r['type'] ?? '',
                $r['date'] ?? '',
                preg_replace("/\r\n|\r|\n/"," ", $r['remark'] ?? ''),
                $r['teacher_name'] ?? '',
                $r['created_at'] ?? ''
            ]);
        }
        fclose($out); exit;
    }
}

/* -----------------------------
   Filters & pagination for list view
   ----------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$where = []; $params = [];

// restrict to classes teacher can see
if (!empty($assignedClassIds)) $where[] = 'r.class_id IN (' . implode(',', array_map('intval', $assignedClassIds)) . ')';
else $where[] = '0=1'; // teacher not assigned anywhere -> no results

$classFilter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($classFilter > 0 && in_array($classFilter, $assignedClassIds, true)) { $where[] = 'r.class_id = :class_id'; $params[':class_id'] = $classFilter; }

if (!empty($_GET['student_id'])) { $where[] = 'r.student_id = :student_id'; $params[':student_id'] = (int)$_GET['student_id']; }
if (!empty($_GET['type']) && in_array($_GET['type'], $types, true)) { $where[] = 'r.type = :type'; $params[':type'] = $_GET['type']; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = 'r.date >= :from'; $params[':from'] = $from; }
$to   = trim((string)($_GET['to'] ?? ''));   if ($to   !== '') { $where[] = 'r.date <= :to';   $params[':to']   = $to; }
$qraw = trim((string)($_GET['q'] ?? '')); if ($qraw !== '') { $where[] = '(r.remark LIKE :q)'; $params[':q'] = '%' . $qraw . '%'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0;
try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS cnt FROM {$remarksTable} r $whereSql", $params);
    $total = intval($cRow['cnt'] ?? 0);
} catch (Throwable $e) {
    $total = 0; $errors[] = 'Count failed.';
}

$remarks = [];
if ($total > 0) {
    try {
        $sql = "SELECT r.*, s.first_name, s.last_name, COALESCE(c.name,'') AS class_name, u.name AS teacher_name
                FROM {$remarksTable} r
                LEFT JOIN students s ON s.id = r.student_id
                LEFT JOIN classes c ON c.id = r.class_id
                LEFT JOIN users u ON u.id = r.teacher_id
                $whereSql
                ORDER BY r.date DESC, r.created_at DESC
                LIMIT :limit OFFSET :offset";
        $pdo = pdo_connect();
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $remarks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $remarks = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
        }
    } catch (Throwable $e) { $errors[] = 'List fetch failed.'; }
}

/* Data for add/edit forms */
$studentsList = table_exists('students') ? safe_db_get_all("SELECT id, first_name, middle_name, last_name, class_id FROM students WHERE class_id IN (" . (empty($assignedClassIds) ? "0" : implode(',', $assignedClassIds)) . ") ORDER BY first_name ASC") : [];
$classesForFilter = $assignedClasses;

/* small helpers */
function fullname(array $r): string { return trim((($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); }

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* render header if present */
$pageTitle = 'Student Remarks';
require_once __DIR__ . '/../includes/header.php';
?>

    <div>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addRemarkModal">Add Remark</button>
      <a class="btn btn-outline-secondary" href="?<?php echo build_qs(); ?>">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="">All</option>
          <?php foreach ($classesForFilter as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if((int)($classFilter ?? 0)===(int)$c['id']) echo 'selected'; ?>><?php echo e(trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')))); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">Student</label>
        <select name="student_id" class="form-select">
          <option value="">Any</option>
          <?php foreach ($studentsList as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php if(($_GET['student_id'] ?? '')===(string)$s['id']) echo 'selected'; ?>><?php echo e(fullname($s)); ?> (<?php echo (int)$s['id']; ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select"><option value="">Any</option><?php foreach ($types as $t): ?><option value="<?php echo e($t); ?>" <?php if(($_GET['type'] ?? '')===$t) echo 'selected'; ?>><?php echo e(ucfirst($t)); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input name="from" type="date" class="form-control" value="<?php echo e($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input name="to" type="date" class="form-control" value="<?php echo e($_GET['to'] ?? ''); ?>"></div>
      <div class="col-12 col-md-6 mt-2"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($qraw ?? ''); ?>" placeholder="Search remark text"></div>
      <div class="col-md-6 text-end mt-2"><button class="btn btn-primary">Filter</button> <a class="btn btn-sm btn-outline-secondary" href="?">Reset</a></div>
    </form>
  </div>

  <!-- Remarks table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Student</th>
            <th>Class</th>
            <th>Type / Date</th>
            <th>Remark</th>
            <th style="width:240px">By / Created</th>
            <th style="width:180px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($remarks)): foreach ($remarks as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e(trim((($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')))); ?><br><small class="small-muted">#<?php echo (int)($r['student_id'] ?? 0); ?></small></td>
              <td><?php echo e($r['class_name'] ?? ($r['class_id'] ?? '')); ?></td>
              <td><?php echo e(ucfirst($r['type'] ?? '')); ?><br><small class="small-muted"><?php echo e($r['date'] ?? '—'); ?></small></td>
              <td class="mrk-pre"><?php echo e(mb_strimwidth($r['remark'] ?? '', 0, 200, '...')); ?></td>
              <td><?php echo e($r['teacher_name'] ?? ''); ?><br><small class="small-muted"><?php echo e(substr($r['created_at'] ?? '',0,16)); ?></small></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editRemarkModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <?php if (teacher_allowed_for_class($teacherId, (int)$r['class_id'], $assignedClassIds)): ?>
                  <?php echo render_secure_delete_button((int)$r['id'], 'Delete', 'Delete remark?'); ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center small-muted">No remarks found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($remarks)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php $totalPages = max(1, (int)ceil($total / $perPage)); for ($p=1;$p<=$totalPages;$p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Add Remark Modal -->
<div class="modal fade" id="addRemarkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <input type="hidden" name="csrf" value="<?php echo e(get_csrf_token()); ?>">
        <div class="modal-header"><h5 class="modal-title">Add Remark</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Class *</label>
              <select name="class_id" class="form-select" required>
                <option value="">Select class</option>
                <?php foreach ($assignedClasses as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>"><?php echo e(trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')))); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Student *</label>
              <select name="student_id" class="form-select" required>
                <option value="">Select student</option>
                <?php foreach ($studentsList as $s): if (in_array((int)$s['class_id'], $assignedClassIds, true)): ?>
                  <option value="<?php echo (int)$s['id']; ?>"><?php echo e(fullname($s)); ?> (<?php echo (int)$s['id']; ?>)</option>
                <?php endif; endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <?php foreach ($types as $t): ?><option value="<?php echo e($t); ?>"><?php echo e(ucfirst($t)); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date</label>
              <input type="date" name="date" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Remark *</label>
              <textarea name="remark" rows="5" class="form-control" required></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Remark Modal -->
<div class="modal fade" id="editRemarkModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editRemarkForm">
        <input type="hidden" name="csrf" value="<?php echo e(get_csrf_token()); ?>">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Remark</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editRemarkBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Remark details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal: load fragment via fetch
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

  // Edit modal: fetch JSON data to populate form
  var editModal = document.getElementById('editRemarkModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editRemarkBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load remark.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-4"><label class="form-label">Class</label><select id="edit_class_id" name="class_id" class="form-select">';
          html += '<option value="">Select class</option>';
          // classes options from server - we embed a small JSON
          var classes = <?php echo json_encode($assignedClasses, JSON_UNESCAPED_UNICODE); ?>;
          classes.forEach(function(c){ html += '<option value="'+c.id+'">'+(c.short_name ? c.short_name+' ' : '')+c.name+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Student</label><select id="edit_student_id" name="student_id" class="form-select"><option value="">Select student</option>';
          // embed students list
          var students = <?php echo json_encode($studentsList, JSON_UNESCAPED_UNICODE); ?>;
          students.forEach(function(s){ html += '<option value="'+s.id+'">'+(s.first_name+' '+(s.last_name||''))+' ('+s.id+')</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Type</label><select id="edit_type" name="type" class="form-select">';
          var types = <?php echo json_encode($types, JSON_UNESCAPED_UNICODE); ?>;
          types.forEach(function(t){ html += '<option value="'+t+'">'+t.charAt(0).toUpperCase()+t.slice(1)+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Date</label><input id="edit_date" name="date" type="date" class="form-control"></div>';
          html += '<div class="col-12"><label class="form-label">Remark</label><textarea id="edit_remark" name="remark" rows="6" class="form-control"></textarea></div>';
          html += '</div>';
          body.innerHTML = html;
          document.getElementById('edit_class_id').value = d.class_id || '';
          document.getElementById('edit_student_id').value = d.student_id || '';
          document.getElementById('edit_type').value = d.type || '';
          document.getElementById('edit_date').value = d.date || '';
          document.getElementById('edit_remark').value = d.remark || '';
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>