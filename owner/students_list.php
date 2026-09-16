<?php
/**
 * owner/students_list.php
 *
 * Student management for Owner.
 *
 * Table: students
 * Columns:
 *   id (PK), school_id, first_name, last_name, dob, class_id, parent_id, photo_path,
 *   admission_date, status, created_at, updated_at
 *
 * Features:
 * - List with search / filters / pagination
 * - Add (modal), Edit (modal), View (modal)
 * - Delete
 * - Export CSV
 * - AJAX endpoints: get (JSON) and view (HTML fragment)
 *
 * Place at: /pioneerplayschool01/owner/students_list.php
 *
 * Requires session auth: $_SESSION['owner_auth_user']
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional includes */
/* Auth */
/* Debug */
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

/* -------------------------
   Status options (from schema)
   ------------------------- */
$statusOptions = ['active','inactive','alumni','pending'];

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* Block edits when academic year is locked */
if (in_array($action, ['add', 'edit', 'delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('ay_can_edit') && !ay_can_edit()) {
    $errors[] = 'This academic year is locked. Changes are not allowed.';
    $action = 'list';
}

/* ADD (POST) */
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

/* GET (for edit modal) returns JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, first_name, last_name, dob, class_id, parent_id, photo_path, admission_date, status FROM students WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Student not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* VIEW (modal fragment) */
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

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(first_name LIKE :q OR last_name LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['class_id'])) { $where[] = "class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }
    if (!empty($_GET['status'])) { $where[] = "status = :status"; $params[':status'] = $_GET['status']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = safe_db_get_all("SELECT * FROM students $whereSql ORDER BY first_name ASC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','First Name','Last Name','DOB','Class ID','Parent ID','Photo','Admission Date','Status','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['first_name'] ?? '',
            $r['last_name'] ?? '',
            $r['dob'] ?? '',
            $r['class_id'] ?? '',
            $r['parent_id'] ?? '',
            $r['photo_path'] ?? '',
            $r['admission_date'] ?? '',
            $r['status'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(s.first_name LIKE :q OR s.last_name LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
if ($classFilter !== null) { $where[] = "s.class_id = :class_id"; $params[':class_id'] = $classFilter; }
$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($statusFilter !== '') { $where[] = "s.status = :status"; $params[':status'] = $statusFilter; }

if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($where, $params, 's');
}

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
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes WHERE school_id = 1 ORDER BY name ASC") : [];
$parentList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users WHERE role = 'parent' ORDER BY name ASC") : [];

/* Helper to build querystring */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = 'Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
      <a class="btn btn-success" href="<?php echo e(function_exists('site_url') ? site_url('/reception/admission.php') : '../reception/admission.php'); ?>">New Admission</a>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="First or last name"></div>
      <div class="col-md-3"><label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="">Any</option>
          <?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if($classFilter === (int)$c['id']) echo 'selected'; ?>><?php echo $esc($c['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Any</option>
          <?php foreach ($statusOptions as $st): ?><option value="<?php echo $esc($st); ?>" <?php if(($statusFilter ?? '')===$st) echo 'selected'; ?>><?php echo $esc(ucfirst($st)); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 text-end">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="?">Reset</a>
        <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
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
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($students)): foreach ($students as $s): ?>
            <tr>
              <td><?php echo (int)$s['id']; ?></td>
              <td>
                <div class="d-flex align-items-center gap-3">
                  <?php if (!empty($s['photo_path'])): ?><img src="<?php echo $esc($s['photo_path']); ?>" class="student-photo" alt="photo"><?php else: ?><div style="width:56px;height:56px;border-radius:8px;background:#eef2ff"></div><?php endif; ?>
                  <div>
                    <div class="fw-semibold"><?php echo $esc($s['first_name'] . ($s['last_name'] ? ' ' . $s['last_name'] : '')); ?></div>
                    <div class="small text-muted"><?php echo $s['dob'] ? e(date('d M Y', strtotime($s['dob']))) : '—'; ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc($s['class_name'] ?? ''); ?></div>
                <div><?php echo $esc($s['parent_name'] ?? ''); ?></div>
              </td>
              <td><?php echo $s['admission_date'] ? e(date('d M Y', strtotime($s['admission_date']))) : '—'; ?></td>
              <td><span class="badge <?php echo ($s['status']==='active' ? 'bg-success' : ($s['status']==='pending' ? 'bg-warning text-dark' : 'bg-secondary')); ?>"><?php echo $esc(ucfirst($s['status'])); ?></span></td>
              <td>
                <a class="btn btn-sm btn-outline-info" href="<?php echo e(function_exists('site_url') ? site_url('/owner/students_view.php?id=' . (int)$s['id']) : ('students_view.php?id=' . (int)$s['id'])); ?>">View</a>
                <a class="btn btn-sm btn-outline-warning" href="<?php echo e(function_exists('site_url') ? site_url('/owner/students_edit.php?id=' . (int)$s['id']) : ('students_edit.php?id=' . (int)$s['id'])); ?>">Edit</a>
                <a class="btn btn-sm btn-outline-success" href="<?php echo e(function_exists('site_url') ? site_url('/reception/students_form_print.php?id=' . (int)$s['id']) : ('../reception/students_form_print.php?id=' . (int)$s['id'])); ?>" target="_blank">PDF</a>
                <?php echo render_secure_delete_button((int)$s['id'], 'Delete', 'Delete student?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No students found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($students)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
