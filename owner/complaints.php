<?php
/**
 * owner/complaints.php
 *
 * Owner/admin interface for managing complaints.
 *
 * Features:
 *  - List (search, status filter, date filters, pagination)
 *  - View (modal HTML fragment)
 *  - Edit (AJAX): update name/phone/email/message/status and record "Action taken (note)" into complaint_actions
 *      - If an action note is provided but complaint_actions table doesn't exist, the update will be rolled back
 *        and a clear error returned.
 *  - Delete (POST)
 *  - Export CSV (GET?action=export)
 *
 * Expected complaints table columns:
 *   id, name, phone, email, message, source, created_at, status, updated_by, updated_at
 *
 * Place this file at: /pioneerplayschool01/owner/complaints.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional project includes */
/* Provide a harmless stub for static analysis if db_connect isn't defined in includes */
if (!function_exists('db_connect')) {
    function db_connect() { return null; }
}

/* Simple owner auth guard (adapt to your real auth) */
/* Debug flag: set DEV_SHOW_ERRORS = true in includes/config.php to return exception details in JSON */
/* DB wrappers (prefer project helpers if available) */
if (!function_exists('db_get_one')) {
    function db_get_one(string $sql, array $params = []) {
        if (is_callable('db_fetch_one')) {
            try { return call_user_func('db_fetch_one', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return null;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
if (!function_exists('db_get_all')) {
    function db_get_all(string $sql, array $params = []): array {
        if (is_callable('db_fetch_all')) {
            try { return call_user_func('db_fetch_all', $sql, $params) ?: []; } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return [];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
if (!function_exists('db_run')) {
    function db_run(string $sql, array $params = []): bool {
        if (is_callable('db_execute')) {
            try { return (bool) call_user_func('db_execute', $sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect();
        if (!($pdo instanceof PDO)) return false;
        $stmt = $pdo->prepare($sql);
        return (bool)$stmt->execute($params);
    }
}

/* small helpers */

function table_exists(string $name): bool {
    $r = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
    return !empty($r['cnt']);
}

/* Try to ensure complaint_actions table exists (best-effort). If cannot create, we continue with $has_actions_table=false. */
$has_actions_table = false;
try {
    $pdo = pdo_connect();
    if ($pdo instanceof PDO) {
        $tbl = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'complaint_actions'");
        if (!empty($tbl['cnt'])) {
            $has_actions_table = true;
        } else {
            // Try create
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS complaint_actions (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  complaint_id INT UNSIGNED NOT NULL,
                  action_note TEXT,
                  performed_by INT NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (complaint_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $tbl2 = db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'complaint_actions'");
            if (!empty($tbl2['cnt'])) $has_actions_table = true;
        }
    }
} catch (Throwable $e) {
    error_log('Unable to ensure complaint_actions table: ' . $e->getMessage());
    $has_actions_table = false;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Owner performing actions (if stored in session) */
$owner_user_id = auth_user_id();

/* Routing */
$action = $_REQUEST['action'] ?? 'list';
$messages = [];
$errors = [];

/* JSON helper */
function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* -------------------------
   GET: return JSON for edit modal
   ------------------------- */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) json_exit(['ok'=>false,'error'=>'Invalid id']);
    $row = db_get_one("SELECT id, name, phone, email, message, status FROM complaints WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) json_exit(['ok'=>false,'error'=>'Not found']);
    json_exit(['ok'=>true,'data'=>$row]);
}

/* -------------------------
   GET: view modal HTML fragment
   ------------------------- */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = db_get_one("SELECT c.*, COALESCE(u.name,'') AS updated_by_name FROM complaints c LEFT JOIN users u ON u.id = c.updated_by WHERE c.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Complaint not found</div>'; exit; }
    echo '<div class="p-3"><dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.e($row['name'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Phone</dt><dd class="col-sm-9">'.e($row['phone'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Email</dt><dd class="col-sm-9">'.e($row['email'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.e(ucfirst($row['status'] ?? 'open')).'</dd>';
    echo '<dt class="col-sm-3">Source</dt><dd class="col-sm-9">'.e($row['source'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Submitted</dt><dd class="col-sm-9">'.e($row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Updated</dt><dd class="col-sm-9">'.e($row['updated_at'] ?? '—') . ($row['updated_by_name'] ? ' by ' . e($row['updated_by_name']) : '') .'</dd>';
    echo '<dt class="col-sm-3">Message</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['message'] ?? '').'</pre></dd>';
    echo '</dl></div>';
    exit;
}

/* -------------------------
   GET: history JSON
   ------------------------- */
if ($action === 'history' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) json_exit(['ok'=>false,'error'=>'Invalid id']);
    $rows = db_get_all("SELECT a.*, COALESCE(u.name,'') AS perf_name FROM complaint_actions a LEFT JOIN users u ON u.id = a.performed_by WHERE a.complaint_id = :id ORDER BY a.created_at DESC", [':id'=>$id]);
    json_exit(['ok'=>true,'data'=>$rows]);
}

/* -------------------------
   POST: update (AJAX-friendly)
   - Updates complaint and inserts action_note if provided and table exists.
   - If action_note provided but table doesn't exist, rollback and return explicit error.
   ------------------------- */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        if ($isAjax) json_exit(['ok'=>false,'error'=>'Invalid CSRF token']);
        $errors[] = 'Invalid CSRF token';
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'open'));
    $action_note = trim((string)($_POST['action_note'] ?? ''));

    $errs = [];
    if ($id <= 0) $errs[] = 'Invalid id';
    if ($message === '') $errs[] = 'Message is required';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Invalid email';
    if (!in_array($status, ['open','pending','closed'], true)) $status = 'open';

    if (!empty($errs)) {
        if ($isAjax) json_exit(['ok'=>false,'errors'=>$errs]);
        $errors = array_merge($errors, $errs);
    } else {
        try {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) throw new RuntimeException('Database connection unavailable');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE complaints SET name = :name, phone = :phone, email = :email, message = :message, status = :status, updated_by = :updated_by, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                ':name' => $name !== '' ? $name : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':email' => $email !== '' ? $email : null,
                ':message' => $message,
                ':status' => $status,
                ':updated_by' => $owner_user_id,
                ':id' => $id
            ]);

            // Handle action note
            if ($action_note !== '') {
                if (!$has_actions_table) {
                    // rollback and return clear error
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = 'Action note could not be saved because table complaint_actions does not exist. Run the migration to create it.';
                    if ($isAjax) json_exit(['ok'=>false,'error'=>$msg]);
                    $errors[] = $msg;
                } else {
                    $stmt2 = $pdo->prepare("INSERT INTO complaint_actions (complaint_id, action_note, performed_by, created_at) VALUES (:cid, :note, :by, NOW())");
                    $stmt2->execute([':cid'=>$id, ':note'=>$action_note, ':by'=>$owner_user_id]);
                }
            }

            // If there was a pre-existing error (non-AJAX case) prevent commit
            if (!empty($errors)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            } else {
                $pdo->commit();
                if ($isAjax) json_exit(['ok'=>true,'message'=>'Updated']);
                $messages[] = 'Complaint updated';
                header('Location: ?');
                exit;
            }
        } catch (Throwable $e) {
            try { if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
            error_log('Complaint update error: ' . $e->getMessage());
            if ($isAjax) {
                $resp = ['ok'=>false,'error'=>'Failed to update'];
                if ($DEBUG) { $resp['exception'] = $e->getMessage(); $resp['trace'] = $e->getTraceAsString(); }
                json_exit($resp);
            } else {
                $errors[] = 'Failed to update. Check logs.';
            }
        }
    }
}

/* -------------------------
   POST: delete
   ------------------------- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { $errors[] = 'Invalid CSRF token'; }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) $errors[] = 'Invalid id';
    else {
        $ok = db_run("DELETE FROM complaints WHERE id = :id", [':id'=>$id]);
        if ($ok) { $messages[] = 'Complaint deleted'; header('Location: ?'); exit; } else $errors[] = 'Delete failed';
    }
}

/* -------------------------
   GET: export CSV
   ------------------------- */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['status'])) { $where[] = "status = :status"; $params[':status'] = $_GET['status']; }
    if (!empty($_GET['from'])) { $where[] = "created_at >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))   { $where[] = "created_at <= :to";   $params[':to']   = $_GET['to'] . ' 23:59:59'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = db_get_all("SELECT c.*, COALESCE(u.name,'') AS updated_by_name FROM complaints c LEFT JOIN users u ON u.id = c.updated_by $whereSql ORDER BY c.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=complaints_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Phone','Email','Message','Source','Status','Updated By','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            preg_replace("/\r\n|\r|\n/", ' ', $r['message'] ?? ''),
            $r['source'] ?? '',
            $r['status'] ?? '',
            $r['updated_by_name'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* -------------------------
   Listing: filters & pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(c.name LIKE :q OR c.phone LIKE :q OR c.email LIKE :q OR c.message LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($statusFilter !== '' && in_array($statusFilter, ['open','pending','closed'], true)) { $where[] = "c.status = :status"; $params[':status'] = $statusFilter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "c.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to = trim((string)($_GET['to'] ?? '')); if ($to !== '') { $where[] = "c.created_at <= :to"; $params[':to'] = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = db_get_one("SELECT COUNT(*) AS c FROM complaints c " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count failed';
    if ($DEBUG) $errors[] = $e->getMessage();
}

$rows = [];
try {
    $sql = "SELECT c.*, COALESCE(u.name,'') AS updated_by_name FROM complaints c LEFT JOIN users u ON u.id = c.updated_by $whereSql ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";
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
    $errors[] = 'List fetch failed';
    if ($DEBUG) $errors[] = $e->getMessage();
}

$totalPages = (int) ceil(max(0,$total) / $perPage);

/* helper to preserve query params */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k => $v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* optional header include */
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($qraw); ?>" placeholder="Name, phone, email or message"></div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Any</option>
          <option value="open" <?php if(($statusFilter ?? '')==='open') echo 'selected'; ?>>Open</option>
          <option value="pending" <?php if(($statusFilter ?? '')==='pending') echo 'selected'; ?>>Pending</option>
          <option value="closed" <?php if(($statusFilter ?? '')==='closed') echo 'selected'; ?>>Closed</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($_GET['to'] ?? ''); ?>"></div>
      <div class="col-md-2 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th style="width:160px">Submitted</th>
            <th>Name / Contact</th>
            <th>Message</th>
            <th style="width:300px">Status / Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr id="row-<?php echo (int)$r['id']; ?>">
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e(substr((string)($r['created_at'] ?? ''),0,16)); ?></td>
              <td>
                <div class="fw-semibold"><?php echo e($r['name'] ?? '—'); ?></div>
                <div class="small text-muted"><?php echo e(($r['phone'] ?? '') . ($r['email'] ? ' • ' . $r['email'] : '')); ?></div>
              </td>
              <td><?php echo e(mb_strimwidth($r['message'] ?? '', 0, 140, '...')); ?></td>
              <td>
                <?php $st = $r['status'] ?? 'open'; ?>
                <span id="status-badge-<?php echo (int)$r['id']; ?>" class="badge <?php echo 'badge-status-' . e($st); ?>"><?php echo e(ucfirst($st)); ?></span>
                <div class="mt-2">
                  <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                  <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete complaint?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">No complaints found.</td></tr>
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
      <div class="modal-header"><h5 class="modal-title">Complaint details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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
        <div class="modal-header"><h5 class="modal-title">Edit Complaint</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit_id" value="">
          <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

          <div class="mb-3"><label class="form-label">Name</label><input id="edit_name" name="name" class="form-control"></div>

          <div class="row g-2 mb-3">
            <div class="col"><label class="form-label">Phone</label><input id="edit_phone" name="phone" class="form-control"></div>
            <div class="col"><label class="form-label">Email</label><input id="edit_email" name="email" class="form-control"></div>
          </div>

          <div class="mb-3"><label class="form-label">Message</label><textarea id="edit_message" name="message" rows="6" class="form-control"></textarea></div>

          <div class="mb-3"><label class="form-label">Status</label>
            <select id="edit_status" name="status" class="form-select">
              <option value="open">Open</option>
              <option value="pending">Pending</option>
              <option value="closed">Closed</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Action taken (note)</label>
            <textarea id="edit_action_note" name="action_note" rows="3" class="form-control" placeholder="Describe action taken (optional)"></textarea>
            <div class="form-text">If provided, saved to complaint_actions table (must exist).</div>
          </div>

          <div id="editErrors" class="text-danger small"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal: load details + history
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.text() : Promise.reject())
        .then(html => {
          body.innerHTML = html + '<hr><div id="actionHistory" class="p-2 text-muted">Loading history…</div>';
          return fetch('?action=history&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
        })
        .then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          var h = document.getElementById('actionHistory');
          if (!json || !json.ok || !Array.isArray(json.data) || json.data.length === 0) { h.innerHTML = '<div class="text-muted">No history recorded.</div>'; return; }
          var out = '<ul class="list-unstyled mb-0">';
          json.data.forEach(function(a){
            out += '<li class="mb-2"><div class="small text-muted">'+(a.created_at||'')+' by '+(a.perf_name||('ID '+(a.performed_by||'')))+'</div><div>'+ (a.action_note ? a.action_note.replace(/\n/g,'<br>') : '') +'</div></li>';
          });
          out += '</ul>'; h.innerHTML = out;
        })
        .catch(() => { body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }

  // Edit modal: populate & AJAX submit
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      document.getElementById('editErrors').innerHTML = '';
      ['edit_id','edit_name','edit_phone','edit_email','edit_message','edit_status','edit_action_note'].forEach(function(i){ var el=document.getElementById(i); if(el) el.value=''; });
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (!json || !json.ok || !json.data) { alert(json.error || 'Failed to load'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          document.getElementById('edit_name').value = d.name || '';
          document.getElementById('edit_phone').value = d.phone || '';
          document.getElementById('edit_email').value = d.email || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_status').value = d.status || 'open';
        })
        .catch(() => { alert('Failed to load record.'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); });
    });

    var editForm = document.getElementById('editForm');
    editForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      document.getElementById('editErrors').innerHTML = '';
      var fd = new FormData(editForm);
      fetch('?action=update', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (json.ok) {
            var mb = bootstrap.Modal.getInstance(editModal);
            if (mb) mb.hide();
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
