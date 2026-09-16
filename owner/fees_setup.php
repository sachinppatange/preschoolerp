<?php
/**
 * owner/fees_setup.php
 *
 * Owner interface to manage class fee setup (classes table).
 *
 * Features:
 *  - List classes (search, filter by school, pagination)
 *  - View class (modal fragment)
 *  - Add / Edit class (modal, AJAX-friendly)
 *  - Delete class (POST)
 *  - Export CSV
 *
 * Expected `classes` table columns:
 *   id INT PRIMARY AUTO_INCREMENT PRIMARY KEY
 *   school_id INT
 *   name VARCHAR(...)
 *   age_group VARCHAR(...)
 *   fees DECIMAL(10,2)
 *   created_at DATETIME
 *   updated_at DATETIME
 *
 * Place at: /pioneerplayschool01/owner/fees_setup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional project includes */
/* Provide harmless stub if project doesn't define db_connect() */
if (!function_exists('db_connect')) { function db_connect() { return null; } }

/* Require owner authentication (adjust to project's auth) */
/* Debug flag */
/* --------------------
   DB wrappers
   -------------------- */
if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) {
            try { return call_user_func('db_fetch_one', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return null;
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $r = $stmt->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_get_one: '.$e->getMessage().' SQL: '.$sql); return null; }
    }
}
if (!function_exists('db_get_all')) {
    function db_get_all(string $sql, array $params = []): array {
        if (is_callable('db_fetch_all')) {
            try { return call_user_func('db_fetch_all', $sql, $params) ?: []; } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return [];
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_get_all: '.$e->getMessage().' SQL: '.$sql); return []; }
    }
}
if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) {
            try { return (bool) call_user_func('db_execute', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return false;
        try { $stmt = $pdo->prepare($sql); return (bool)$stmt->execute($params); }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) error_log('db_run: '.$e->getMessage().' SQL: '.$sql); return false; }
    }
}

function table_exists(string $name): bool {
    $r = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
    return !empty($r['cnt']);
}

/* --------------------
   Ensure classes table exists (best-effort)
   -------------------- */
try {
    $pdo = pdo_connect();
    if ($pdo instanceof PDO && !table_exists('classes')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS classes (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              school_id INT NULL,
              name VARCHAR(255) NOT NULL,
              age_group VARCHAR(100) DEFAULT NULL,
              fees DECIMAL(10,2) DEFAULT 0.00,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              INDEX (school_id),
              INDEX (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
} catch (Throwable $e) {
    if ($DEBUG) error_log('Could not ensure classes table: ' . $e->getMessage());
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Current user id (optional) */
$owner_user_id = auth_user_id();

/* Routing */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* Short JSON helper */
function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* --------------------
   GET: fetch single record for edit (JSON)
   action=get&id=#
   -------------------- */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($id <= 0) json_exit(['ok'=>false,'error'=>'Invalid id']);
    $row = db_get_one("SELECT id, school_id, name, age_group, fees FROM classes WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) json_exit(['ok'=>false,'error'=>'Not found']);
    json_exit(['ok'=>true,'data'=>$row]);
}

/* --------------------
   GET: view fragment
   action=view&id=#
   -------------------- */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $r = db_get_one("SELECT c.*, COALESCE(s.name,'') AS school_name FROM classes c LEFT JOIN schools s ON s.id = c.school_id WHERE c.id = :id LIMIT 1", [':id'=>$id]);
    if (!$r) { echo '<div class="text-muted p-3">Record not found</div>'; exit; }
    echo '<div class="p-3"><dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$r['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.e($r['school_name'] ?? ($r['school_id'] ?? '—')).'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.e($r['name']).'</dd>';
    echo '<dt class="col-sm-3">Age Group</dt><dd class="col-sm-9">'.e($r['age_group'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Fees</dt><dd class="col-sm-9">'.e(number_format((float)$r['fees'],2)).'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e($r['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Updated</dt><dd class="col-sm-9">'.e($r['updated_at'] ?? '—').'</dd>';
    echo '</dl></div>';
    exit;
}

/* --------------------
   POST: save (add or update) - AJAX-friendly
   action=save
   -------------------- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
              (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        if ($isAjax) json_exit(['ok'=>false,'error'=>'Invalid CSRF token']);
        $errors[] = 'Invalid CSRF token';
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && is_numeric($_POST['school_id']) ? (int)$_POST['school_id'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $fees_raw = trim((string)($_POST['fees'] ?? '0'));
    $fees = (float) str_replace(',', '', $fees_raw);

    $errs = [];
    if ($name === '') $errs[] = 'Class name is required.';
    if (!is_null($school_id) && $school_id <= 0) $school_id = null;
    if (!is_numeric($fees_raw) && !is_numeric(str_replace(',', '', $fees_raw))) $errs[] = 'Fees must be a numeric value.';
    // optional: further validation on age_group

    if (!empty($errs)) {
        if ($isAjax) json_exit(['ok'=>false,'errors'=>$errs]);
        $errors = array_merge($errors, $errs);
    } else {
        try {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) throw new RuntimeException('Database connection not available');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE classes SET school_id = :school_id, name = :name, age_group = :age_group, fees = :fees, updated_at = NOW() WHERE id = :id");
                $ok = $stmt->execute([
                    ':school_id' => $school_id,
                    ':name' => $name,
                    ':age_group' => $age_group !== '' ? $age_group : null,
                    ':fees' => $fees,
                    ':id' => $id
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO classes (school_id, name, age_group, fees) VALUES (:school_id, :name, :age_group, :fees)");
                $ok = $stmt->execute([
                    ':school_id' => $school_id,
                    ':name' => $name,
                    ':age_group' => $age_group !== '' ? $age_group : null,
                    ':fees' => $fees
                ]);
            }

            if ($ok) {
                if ($isAjax) json_exit(['ok'=>true,'message'=>'Saved']);
                $messages[] = $id>0 ? 'Record updated' : 'Record added';
                header('Location: ?'); exit;
            } else {
                throw new RuntimeException('Database returned false on save');
            }

        } catch (Throwable $e) {
            error_log('classes save error: ' . $e->getMessage());
            if ($isAjax) {
                $resp = ['ok'=>false,'error'=>'Failed to save'];
                if ($DEBUG) { $resp['exception'] = $e->getMessage(); $resp['trace'] = $e->getTraceAsString(); }
                json_exit($resp);
            } else {
                $errors[] = 'Failed to save. Check logs.';
            }
        }
    }
}

/* --------------------
   POST: delete
   action=delete
   -------------------- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { $errors[] = 'Invalid CSRF token'; }
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) $errors[] = 'Invalid id';
    else {
        $ok = db_run("DELETE FROM classes WHERE id = :id", [':id'=>$id]);
        if ($ok) { $messages[] = 'Record deleted'; header('Location: ?'); exit; } else $errors[] = 'Delete failed';
    }
}

/* --------------------
   GET: export CSV
   action=export
   -------------------- */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(c.name LIKE :q OR c.age_group LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['school_id']) && is_numeric($_GET['school_id'])) { $where[] = "c.school_id = :school_id"; $params[':school_id'] = (int)$_GET['school_id']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = db_get_all("SELECT c.*, COALESCE(s.name,'') AS school_name FROM classes c LEFT JOIN schools s ON s.id = c.school_id $whereSql ORDER BY c.name ASC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=classes_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','School Name','Name','Age Group','Fees','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['school_name'] ?? '',
            $r['name'] ?? '',
            $r['age_group'] ?? '',
            isset($r['fees']) ? number_format((float)$r['fees'],2) : '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* --------------------
   Listing: filters & pagination
   -------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') { $where[] = "(c.name LIKE :q OR c.age_group LIKE :q)"; $params[':q'] = '%'.$q.'%'; }
if (!empty($_GET['school_id']) && is_numeric($_GET['school_id'])) { $where[] = 'c.school_id = :school_id'; $params[':school_id'] = (int)$_GET['school_id']; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = db_get_one("SELECT COUNT(*) AS c FROM classes c " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$rows = [];
try {
    $sql = "SELECT c.*, COALESCE(s.name,'') AS school_name FROM classes c LEFT JOIN schools s ON s.id = c.school_id " . ($whereSql ? $whereSql : '') . " ORDER BY c.name ASC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int) ceil(max(0,$total) / $perPage);

/* Helper to preserve query params */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) {
        if ($v === null) unset($qs[$k]); else $qs[$k] = $v;
    }
    return http_build_query($qs);
}

/* Optional header include */
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Class name or age group"></div>
      <div class="col-md-3"><label class="form-label">School ID</label><input name="school_id" class="form-control" value="<?php echo e($_GET['school_id'] ?? ''); ?>" placeholder="school id"></div>
      <div class="col-md-3 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th>School</th>
            <th>Name</th>
            <th>Age Group</th>
            <th style="width:120px">Fees</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr id="row-<?php echo (int)$r['id']; ?>">
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['school_name'] ?: $r['school_id']); ?></td>
              <td><?php echo e($r['name']); ?></td>
              <td><?php echo e($r['age_group'] ?? ''); ?></td>
              <td><?php echo e(number_format((float)($r['fees'] ?? 0),2)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete record?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No classes found.</td></tr>
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

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Class details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="editForm">
        <div class="modal-header"><h5 class="modal-title">Add / Edit Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="edit_id" value="">
          <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

          <div class="mb-3"><label class="form-label">School ID</label><input id="edit_school_id" name="school_id" class="form-control" type="number" /></div>
          <div class="mb-3"><label class="form-label">Class name</label><input id="edit_name" name="name" class="form-control" required /></div>
          <div class="mb-3"><label class="form-label">Age group</label><input id="edit_age_group" name="age_group" class="form-control" /></div>
          <div class="mb-3"><label class="form-label">Fees</label><input id="edit_fees" name="fees" class="form-control" /></div>

          <div id="editErrors" class="text-danger small"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.text() : Promise.reject())
        .then(html => body.innerHTML = html)
        .catch(() => body.innerHTML = '<div class="text-danger">Failed to load details.</div>');
    });
  }

  // Edit modal populate & AJAX submit
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id') || '';
      document.getElementById('editErrors').innerHTML = '';
      ['edit_id','edit_school_id','edit_name','edit_age_group','edit_fees'].forEach(function(i){ var el=document.getElementById(i); if (el) el.value=''; });
      if (!id) return;
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (!json || !json.ok) { alert(json.error || 'Failed to load'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          document.getElementById('edit_school_id').value = d.school_id || '';
          document.getElementById('edit_name').value = d.name || '';
          document.getElementById('edit_age_group').value = d.age_group || '';
          document.getElementById('edit_fees').value = d.fees || '';
        })
        .catch(() => { alert('Failed to load record for edit.'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); });
    });

    var editForm = document.getElementById('editForm');
    editForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      document.getElementById('editErrors').innerHTML = '';
      var fd = new FormData(editForm);
      fetch('?action=save', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (json.ok) {
            var m = bootstrap.Modal.getInstance(editModal);
            if (m) m.hide();
            location.reload();
          } else {
            if (json.errors) document.getElementById('editErrors').innerHTML = json.errors.map(x=>'<div>'+x+'</div>').join('');
            else document.getElementById('editErrors').innerHTML = '<div>' + (json.error || 'Failed to save') + '</div>';
          }
        })
        .catch(err => {
          document.getElementById('editErrors').innerHTML = '<div class="text-danger">Failed to save changes.</div>';
          console.error(err);
        });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
