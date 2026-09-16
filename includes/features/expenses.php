<?php
/**
 * Shared feature: expenses
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'Expenses');
$createdByUserId = (int)(auth_user_id() ?? 0);

/* Provide harmless stub for static analyzers if project doesn't define db_connect() */
if (!function_exists('db_connect')) {
    function db_connect() { return null; }
}

/* Simple authentication guard (replace with your app's auth) */
/* Debug flag: define DEV_SHOW_ERRORS = true in includes/config.php to surface exceptions in JSON responses */

/* ---------------------------
   Ensure expenses table exists (best-effort)
   --------------------------- */
try {
    $pdo = pdo_connect();
    if ($pdo instanceof PDO && !table_exists('expenses')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expenses (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(255) NOT NULL,
              amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              category VARCHAR(100) DEFAULT NULL,
              expense_date DATE NOT NULL,
              payment_method VARCHAR(50) DEFAULT NULL,
              notes TEXT,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              created_by INT NULL,
              INDEX (expense_date),
              INDEX (category),
              INDEX (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
} catch (Throwable $e) {
    if ($DEBUG) error_log('Could not ensure expenses table: ' . $e->getMessage());
}

/* ---------------------------
   CSRF token
   --------------------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* ---------------------------
   Routing
   --------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* Helper send JSON and exit */
function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ---------------------------
   GET: JSON for edit modal
   --------------------------- */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($id <= 0) json_exit(['ok'=>false,'error'=>'Invalid id']);
    $row = safe_db_get_one("SELECT * FROM expenses WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) json_exit(['ok'=>false,'error'=>'Not found']);
    json_exit(['ok'=>true,'data'=>$row]);
}

/* ---------------------------
   GET: view fragment
   --------------------------- */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $r = safe_db_get_one("SELECT * FROM expenses WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$r) { echo '<div class="text-muted p-3">Expense not found</div>'; exit; }

    echo '<div class="p-3"><dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$r['id'].'</dd>';
    echo '<dt class="col-sm-3">Title</dt><dd class="col-sm-9">'.e($r['title']).'</dd>';
    echo '<dt class="col-sm-3">Amount</dt><dd class="col-sm-9">'.e(number_format((float)$r['amount'],2)).'</dd>';
    echo '<dt class="col-sm-3">Category</dt><dd class="col-sm-9">'.e($r['category'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Expense Date</dt><dd class="col-sm-9">'.e($r['expense_date']).'</dd>';
    echo '<dt class="col-sm-3">Payment Method</dt><dd class="col-sm-9">'.e($r['payment_method'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Created At</dt><dd class="col-sm-9">'.e($r['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Updated At</dt><dd class="col-sm-9">'.e($r['updated_at'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($r['notes'] ?? '').'</pre></dd>';
    echo '</dl></div>';
    exit;
}

/* ---------------------------
   POST: save (add or update) - AJAX-friendly
   --------------------------- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
              (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        if ($isAjax) json_exit(['ok'=>false,'error'=>'Invalid CSRF token']);
        $errors[] = 'Invalid CSRF token';
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $title = trim((string)($_POST['title'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? '0'));
    $category = trim((string)($_POST['category'] ?? ''));
    $expense_date = trim((string)($_POST['expense_date'] ?? ''));
    $payment_method = trim((string)($_POST['payment_method'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $errs = [];
    if ($title === '') $errs[] = 'Title is required.';
    if ($expense_date === '' || !DateTime::createFromFormat('Y-m-d', $expense_date)) $errs[] = 'Valid expense date (YYYY-MM-DD) required.';
    // allow comma thousand separators
    $amountClean = str_replace(',', '', $amount);
    if (!is_numeric($amountClean)) $errs[] = 'Amount must be a valid number.';
    $amountVal = (float) $amountClean;

    if (!empty($errs)) {
        if ($isAjax) json_exit(['ok'=>false,'errors'=>$errs]);
        $errors = array_merge($errors, $errs);
    } else {
        try {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) throw new RuntimeException('Database connection not available.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE expenses SET title = :title, amount = :amount, category = :category, expense_date = :expense_date, payment_method = :payment_method, notes = :notes, updated_at = NOW() WHERE id = :id");
                $ok = $stmt->execute([
                    ':title'=>$title,
                    ':amount'=>$amountVal,
                    ':category'=>$category !== '' ? $category : null,
                    ':expense_date'=>$expense_date,
                    ':payment_method'=>$payment_method !== '' ? $payment_method : null,
                    ':notes'=>$notes !== '' ? $notes : null,
                    ':id'=>$id
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO expenses (title, amount, category, expense_date, payment_method, notes, created_by) VALUES (:title, :amount, :category, :expense_date, :payment_method, :notes, :created_by)");
                $ok = $stmt->execute([
                    ':title'=>$title,
                    ':amount'=>$amountVal,
                    ':category'=>$category !== '' ? $category : null,
                    ':expense_date'=>$expense_date,
                    ':payment_method'=>$payment_method !== '' ? $payment_method : null,
                    ':notes'=>$notes !== '' ? $notes : null,
                    ':created_by'=>$createdByUserId
                ]);
            }

            if ($ok) {
                if ($isAjax) json_exit(['ok'=>true,'message'=>'Saved']);
                $messages[] = $id > 0 ? 'Expense updated.' : 'Expense added.';
                header('Location: ?'); exit;
            } else {
                throw new RuntimeException('Database returned false on save.');
            }
        } catch (Throwable $e) {
            error_log('Expense save error: ' . $e->getMessage());
            if ($isAjax) {
                $resp = ['ok'=>false,'error'=>'Failed to save expense'];
                if ($DEBUG) { $resp['exception'] = $e->getMessage(); $resp['trace'] = $e->getTraceAsString(); }
                json_exit($resp);
            } else {
                $errors[] = 'Failed to save expense. Check logs.';
            }
        }
    }
}

/* ---------------------------
   POST: delete
   --------------------------- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) { $errors[] = 'Invalid CSRF token'; }
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) $errors[] = 'Invalid id';
    else {
        $ok = safe_db_run("DELETE FROM expenses WHERE id = :id", [':id'=>$id]);
        if ($ok) { $messages[] = 'Expense deleted.'; header('Location: ?'); exit; } else $errors[] = 'Delete failed.';
    }
}

/* ---------------------------
   GET: export CSV
   --------------------------- */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['from'])) { $where[] = "expense_date >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to']))   { $where[] = "expense_date <= :to";   $params[':to']   = $_GET['to'] . ' 23:59:59'; }
    if (!empty($_GET['category'])) { $where[] = "category = :category"; $params[':category'] = trim($_GET['category']); }
    if (!empty($_GET['q'])) { $where[] = "(title LIKE :q OR notes LIKE :q)"; $params[':q'] = '%' . trim($_GET['q']) . '%'; }
    if (function_exists('ay_apply_date_filter')) {
        ay_apply_date_filter($where, $params, 'expense_date');
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $rows = safe_db_get_all("SELECT * FROM expenses $whereSql ORDER BY expense_date DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=expenses_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Title','Amount','Category','Expense Date','Payment Method','Notes','Created At','Updated At','Created By']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['title'] ?? '',
            isset($r['amount']) ? number_format((float)$r['amount'],2) : '',
            $r['category'] ?? '',
            $r['expense_date'] ?? '',
            $r['payment_method'] ?? '',
            preg_replace("/\r\n|\r|\n/"," ", $r['notes'] ?? ''),
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
            $r['created_by'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* ---------------------------
   Listing: filters & pagination
   --------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$filter_q = trim((string)($_GET['q'] ?? ''));
if ($filter_q !== '') { $where[] = "(title LIKE :q OR notes LIKE :q)"; $params[':q'] = '%' . $filter_q . '%'; }
if (!empty($_GET['category'])) { $where[] = "category = :category"; $params[':category'] = trim($_GET['category']); }
if (!empty($_GET['from'])) { $where[] = "expense_date >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to']))   { $where[] = "expense_date <= :to";   $params[':to']   = $_GET['to'] . ' 23:59:59'; }
if (function_exists('ay_apply_date_filter')) {
    ay_apply_date_filter($where, $params, 'expense_date');
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM expenses " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$rows = [];
try {
    $sql = "SELECT * FROM expenses " . ($whereSql ? $whereSql : '') . " ORDER BY expense_date DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
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

$totalPages = (int) ceil(max(0, $total) / $perPage);

/* Helper to build querystring preserving filters */
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) {
        if ($v === null) unset($qs[$k]); else $qs[$k] = $v;
    }
    return http_build_query($qs);
}

/* Optional header include */
require_once __DIR__ . '/../header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($filter_q); ?>" placeholder="Title or notes"></div>
      <div class="col-md-2"><label class="form-label">Category</label><input name="category" class="form-control" value="<?php echo e($_GET['category'] ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($_GET['to'] ?? ''); ?>"></div>
      <div class="col-md-3 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th>Date</th>
            <th>Title</th>
            <th style="width:130px">Amount</th>
            <th>Category</th>
            <th>Payment</th>
            <th style="width:240px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr id="row-<?php echo (int)$r['id']; ?>">
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e($r['expense_date'] ?? ''); ?></td>
              <td><?php echo e($r['title']); ?></td>
              <td><?php echo e(number_format((float)($r['amount'] ?? 0),2)); ?></td>
              <td><?php echo e($r['category'] ?? ''); ?></td>
              <td><?php echo e($r['payment_method'] ?? ''); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete expense?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No expenses found.</td></tr>
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
      <div class="modal-header"><h5 class="modal-title">Expense details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="editForm">
        <div class="modal-header"><h5 class="modal-title">Add / Edit Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="edit_id" value="">
          <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

          <div class="mb-3"><label class="form-label">Title</label><input id="edit_title" name="title" class="form-control" required></div>
          <div class="row g-2 mb-3">
            <div class="col"><label class="form-label">Amount</label><input id="edit_amount" name="amount" class="form-control" required></div>
            <div class="col"><label class="form-label">Date</label><input id="edit_expense_date" name="expense_date" type="date" class="form-control" required></div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col"><label class="form-label">Category</label><input id="edit_category" name="category" class="form-control"></div>
            <div class="col"><label class="form-label">Payment method</label><input id="edit_payment_method" name="payment_method" class="form-control"></div>
          </div>
          <div class="mb-3"><label class="form-label">Notes</label><textarea id="edit_notes" name="notes" rows="4" class="form-control"></textarea></div>

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
  // View modal: load detail fragment
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

  // Edit modal: populate fields for edit; clear for add
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id') || '';
      document.getElementById('editErrors').innerHTML = '';
      ['edit_id','edit_title','edit_amount','edit_category','edit_expense_date','edit_payment_method','edit_notes'].forEach(function(i){ var el=document.getElementById(i); if (el) el.value=''; });
      if (!id) return;
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.json() : Promise.reject())
        .then(json => {
          if (!json || !json.ok || !json.data) { alert(json.error || 'Failed to load'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_amount').value = d.amount || '';
          document.getElementById('edit_category').value = d.category || '';
          document.getElementById('edit_expense_date').value = d.expense_date || '';
          document.getElementById('edit_payment_method').value = d.payment_method || '';
          document.getElementById('edit_notes').value = d.notes || '';
        })
        .catch(() => { alert('Failed to load record for edit.'); var m = bootstrap.Modal.getInstance(editModal); if (m) m.hide(); });
    });

    // AJAX submit for save
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
            if (json.errors) document.getElementById('editErrors').innerHTML = json.errors.map(x => '<div>'+x+'</div>').join('');
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
require_once __DIR__ . '/../footer.php';
?>
