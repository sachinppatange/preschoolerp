<?php
/**
 * owner/feedbacks.php
 *
 * Owner/admin interface for managing feedbacks (table: `feedbacks`)
 *
 * Expected table schema (minimal):
 *  - id INT PRIMARY AUTO_INCREMENT
 *  - name VARCHAR(...)
 *  - phone VARCHAR(...)
 *  - email VARCHAR(...)
 *  - message TEXT
 *  - type VARCHAR(...)    -- "feedback" or "suggestion"
 *  - source VARCHAR(...)
 *  - created_at DATETIME DEFAULT CURRENT_TIMESTAMP
 *
 * Behavior:
 *  - Uses project includes when available:
 *      includes/config.php, includes/db.php, includes/functions.php
 *  - List with search and date filters, pagination
 *  - View modal (HTML fragment)
 *  - Edit modal (AJAX): updates name/phone/email/message/type (does not change created_at/source)
 *  - Delete (POST)
 *  - Export CSV (GET?action=export)
 *
 * Place at: /pioneerplayschool01/owner/feedbacks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional project includes (may define DB helpers/constants and header/footer) */
/* For static analysis if project lacks db_connect */
if (!function_exists('db_connect')) {
    function db_connect() { return null; }
}

/* Simple owner auth guard (adjust to your project) */
/* Debug flag (set DEV_SHOW_ERRORS=true in includes/config.php to return internal messages) */
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

/* Ensure feedbacks table exists (basic) */
if (!table_exists('feedbacks')) {
    // show friendly page / message
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Missing table</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body><div class="container py-5"><div class="alert alert-danger">Table <strong>feedbacks</strong> not found. Please create it.</div></div></body></html>';
    exit;
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Owner id (optional) */
$owner_user_id = auth_user_id();

/* Routing */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* Utility: return JSON and exit */
function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ----------------------------
   GET: supply record JSON for edit modal
   ---------------------------- */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) json_exit(['ok'=>false,'error'=>'Invalid id']);
    $row = db_get_one("SELECT id, name, phone, email, message, type, source FROM feedbacks WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) json_exit(['ok'=>false,'error'=>'Not found']);
    json_exit(['ok'=>true,'data'=>$row]);
}

/* ----------------------------
   GET: view fragment (HTML)
   ---------------------------- */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = db_get_one("SELECT * FROM feedbacks WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Feedback not found</div>'; exit; }
    echo '<div class="p-3"><dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.e($row['name'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Phone</dt><dd class="col-sm-9">'.e($row['phone'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Email</dt><dd class="col-sm-9">'.e($row['email'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Type</dt><dd class="col-sm-9">'.e(ucfirst($row['type'] ?? 'feedback')).'</dd>';
    echo '<dt class="col-sm-3">Source</dt><dd class="col-sm-9">'.e($row['source'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Submitted</dt><dd class="col-sm-9">'.e($row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Message</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['message'] ?? '').'</pre></dd>';
    echo '</dl></div>'; exit;
}

/* ----------------------------
   POST: update (AJAX-friendly)
   Updates name, phone, email, message, type
   ---------------------------- */
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
    $type = in_array($_POST['type'] ?? 'feedback', ['feedback','suggestion'], true) ? $_POST['type'] : 'feedback';

    $errs = [];
    if ($id <= 0) $errs[] = 'Invalid id';
    if ($message === '') $errs[] = 'Message is required';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Invalid email address';

    if (!empty($errs)) {
        if ($isAjax) json_exit(['ok'=>false,'errors'=>$errs]);
        $errors = array_merge($errors, $errs);
    } else {
        try {
            $ok = db_run("
                UPDATE feedbacks SET
                  name = :name,
                  phone = :phone,
                  email = :email,
                  message = :message,
                  type = :type
                WHERE id = :id
            ", [
                ':name' => $name !== '' ? $name : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':email' => $email !== '' ? $email : null,
                ':message' => $message,
                ':type' => $type,
                ':id' => $id
            ]);
            if ($ok) {
                if ($isAjax) json_exit(['ok'=>true,'message'=>'Updated']);
                $messages[] = 'Feedback updated';
                header('Location: ?');
                exit;
            } else {
                throw new RuntimeException('Update returned false');
            }
        } catch (Throwable $e) {
            error_log('Feedback update error: ' . $e->getMessage());
            if ($isAjax) {
                $resp = ['ok'=>false,'error'=>'Failed to update'];
                if ($DEBUG) { $resp['exception'] = $e->getMessage(); }
                json_exit($resp);
            } else {
                $errors[] = 'Failed to update. Check logs.';
            }
        }
    }
}

/* ----------------------------
   POST: delete
   ---------------------------- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { $errors[] = 'Invalid CSRF token'; }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) $errors[] = 'Invalid id';
    else {
        $ok = db_run("DELETE FROM feedbacks WHERE id = :id", [':id'=>$id]);
        if ($ok) { $messages[] = 'Feedback deleted'; header('Location: ?'); exit; }
        else $errors[] = 'Delete failed';
    }
}

/* ----------------------------
   GET: export CSV
   ---------------------------- */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(name LIKE :q OR phone LIKE :q OR email LIKE :q OR message LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['type'])) { $where[] = "type = :type"; $params[':type'] = $_GET['type']; }
    if (!empty($_GET['from'])) { $where[] = "created_at >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))   { $where[] = "created_at <= :to";   $params[':to']   = $_GET['to'] . ' 23:59:59'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = db_get_all("SELECT * FROM feedbacks $whereSql ORDER BY created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=feedbacks_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Phone','Email','Type','Message','Source','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['email'] ?? '',
            $r['type'] ?? '',
            preg_replace("/\r\n|\r|\n/", ' ', $r['message'] ?? ''),
            $r['source'] ?? '',
            $r['created_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* ----------------------------
   Listing: filters & pagination
   ---------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(f.name LIKE :q OR f.phone LIKE :q OR f.email LIKE :q OR f.message LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$typeFilter = trim((string)($_GET['type'] ?? ''));
if ($typeFilter !== '' && in_array($typeFilter, ['feedback','suggestion'], true)) { $where[] = "f.type = :type"; $params[':type'] = $typeFilter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "f.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to = trim((string)($_GET['to'] ?? '')); if ($to !== '') { $where[] = "f.created_at <= :to"; $params[':to'] = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = db_get_one("SELECT COUNT(*) AS c FROM feedbacks f " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed';
    if ($DEBUG) $errors[] = $e->getMessage();
}

$rows = [];
try {
    $sql = "SELECT f.* FROM feedbacks f " . ($whereSql ? $whereSql : '') . " ORDER BY f.created_at DESC LIMIT :limit OFFSET :offset";
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
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Optional header include */
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($qraw); ?>" placeholder="Name, phone, email or message"></div>
      <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select">
          <option value="">Any</option>
          <option value="feedback" <?php if(($typeFilter ?? '')==='feedback') echo 'selected'; ?>>Feedback</option>
          <option value="suggestion" <?php if(($typeFilter ?? '')==='suggestion') echo 'selected'; ?>>Suggestion</option>
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
            <th>Message / Type</th>
            <th style="width:240px">Actions</th>
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
              <td>
                <div><?php echo e(mb_strimwidth($r['message'] ?? '', 0, 140, '...')); ?></div>
                <div class="small text-muted"><?php echo e(ucfirst($r['type'] ?? 'feedback')); ?> <?php if(!empty($r['source'])) echo '• ' . e($r['source']); ?></div>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>

                <form method="post" class="d-inline" onsubmit="return confirm('Delete feedback?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">No feedbacks found.</td></tr>
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

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Feedback details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Edit modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="editForm">
        <div class="modal-header"><h5 class="modal-title">Edit Feedback</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit_id" value="">
          <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

          <div class="mb-3"><label class="form-label">Name</label><input id="edit_name" name="name" class="form-control"></div>
          <div class="row g-2 mb-3">
            <div class="col"><label class="form-label">Phone</label><input id="edit_phone" name="phone" class="form-control"></div>
            <div class="col"><label class="form-label">Email</label><input id="edit_email" name="email" class="form-control"></div>
          </div>

          <div class="mb-3"><label class="form-label">Type</label>
            <select id="edit_type" name="type" class="form-select mb-2">
              <option value="feedback">Feedback</option>
              <option value="suggestion">Suggestion</option>
            </select>
            <label class="form-label">Message</label>
            <textarea id="edit_message" name="message" rows="6" class="form-control"></textarea>
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
  // View modal load
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
      var id = event.relatedTarget.getAttribute('data-id');
      ['edit_id','edit_name','edit_phone','edit_email','edit_message','edit_type'].forEach(function(i){ var el=document.getElementById(i); if(el) el.value=''; });
      document.getElementById('editErrors').innerHTML = '';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (!json || !json.ok) { alert(json.error || 'Failed to load'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          document.getElementById('edit_name').value = d.name || '';
          document.getElementById('edit_phone').value = d.phone || '';
          document.getElementById('edit_email').value = d.email || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_type').value = d.type || 'feedback';
        })
        .catch(() => { alert('Failed to load record for edit.'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); });
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
