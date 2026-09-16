<?php
/**
 * owner/sessions.php
 *
 * Manage sessions table (owner).
 *
 * Table: sessions
 * Fields:
 *  - id (primary)
 *  - user_id
 *  - data         (serialized or json)
 *  - last_activity (INT timestamp or DATETIME depending on schema)
 *
 * Features:
 *  - Owner-only page (requires $_SESSION['owner_auth_user'])
 *  - List with search / filters / pagination
 *  - View details (modal fragment) - displays raw data and attempts to decode JSON/serialized data
 *  - Delete single session
 *  - Export CSV
 *
 * Place at: /pioneerplayschool01/owner/sessions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Require owner authentication */
/* Optional includes (app-specific) */
/* -------------------------
   Ensure sessions table exists
   ------------------------- */
if (!table_exists('sessions')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>sessions</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* -------------------------
   Data for filters (users)
   ------------------------- */
$userList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC") : [];

/* messages/errors */
$messages = []; $errors = [];

/* Allowed actions: list, view, get, delete, export */
$action = $_REQUEST['action'] ?? 'list';

/* VIEW fragment */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = $_GET['id'];
    $row = safe_db_get_one(
        "SELECT s.*, COALESCE(u.name,'') AS user_name
         FROM sessions s
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.id = :id LIMIT 1",
        [':id' => $id]
    );
    if (!$row) { echo '<div class="text-muted p-3">Session not found</div>'; exit; }

    // try to decode data field (JSON or serialized)
    $raw = $row['data'] ?? '';
    $decoded = null;
    $is_json = false;
    $is_serialized = false;
    $parse_error = null;
    if ($raw !== '') {
        $trim = trim($raw);
        if ((substr($trim,0,1) === '{') || (substr($trim,0,1) === '[')) {
            $maybe = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) { $decoded = $maybe; $is_json = true; }
            else { $parse_error = 'JSON decode error: ' . json_last_error_msg(); }
        }
        if ($decoded === null) {
            // try PHP unserialize
            try {
                $un = @unserialize($raw);
                if ($un !== false || $raw === 'b:0;') { $decoded = $un; $is_serialized = true; }
            } catch (Throwable $t) {
                $parse_error = 'Unserialize failed: ' . $t->getMessage();
            }
        }
    }

    echo '<div class="p-3">';
    echo '<dl class="row">';
    echo '<dt class="col-sm-4">ID</dt><dd class="col-sm-8">'.e((string)$row['id']).'</dd>';
    echo '<dt class="col-sm-4">User</dt><dd class="col-sm-8">'.e($row['user_name'] ?: ('#'.$row['user_id'])).'</dd>';
    echo '<dt class="col-sm-4">Last activity</dt><dd class="col-sm-8">'.e((string)$row['last_activity']).'</dd>';
    echo '<dt class="col-sm-4">Raw Data</dt><dd class="col-sm-8"><pre style="white-space:pre-wrap; background:#f8f9fa; padding:8px; border-radius:4px;">'.e($raw).'</pre></dd>';
    if ($decoded !== null) {
        echo '<dt class="col-12">Parsed Data</dt><dd class="col-12"><pre style="white-space:pre-wrap; background:#fbfbfb; padding:8px; border-radius:4px;">';
        echo e(print_r($decoded, true));
        echo '</pre></dd>';
    } else {
        if ($parse_error) {
            echo '<dt class="col-12">Parse Error</dt><dd class="col-12"><div class="text-danger">'.e($parse_error).'</div></dd>';
        } else {
            echo '<dt class="col-12">Parsed Data</dt><dd class="col-12"><div class="text-muted">Could not parse (not JSON or serialized)</div></dd>';
        }
    }
    echo '</dl>';
    echo '</div>';
    exit;
}

/* GET for JSON (optional): returns session record as JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = $_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    $row = safe_db_get_one("SELECT * FROM sessions WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$row]); exit;
}

/* DELETE */
if ($action === 'delete' && !empty($_GET['id'])) {
    $id = $_GET['id'];
    $ok = safe_db_run("DELETE FROM sessions WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Session deleted.'; else $errors[] = 'Delete failed.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['user_id'])) { $where[] = 's.user_id = :uid'; $params[':uid'] = (int)$_GET['user_id']; }
    if (!empty($_GET['from'])) { $where[] = 's.last_activity >= :from'; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to'])) { $where[] = 's.last_activity <= :to'; $params[':to'] = $_GET['to'] . ' 23:59:59'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT s.*, COALESCE(u.name,'') AS user_name FROM sessions s LEFT JOIN users u ON u.id = s.user_id $whereSql ORDER BY s.last_activity DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sessions_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','User Name','Last Activity','Data']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['user_id'] ?? '',
            $r['user_name'] ?? '',
            $r['last_activity'] ?? '',
            preg_replace("/\r\n|\r|\n/"," ", $r['data'] ?? '')
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   List: filters & pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') {
    // search in id, data
    $where[] = "(s.id LIKE :q OR s.data LIKE :q)";
    $params[':q'] = '%' . $qraw . '%';
}
if (!empty($_GET['user_id'])) {
    $where[] = "s.user_id = :user_id";
    $params[':user_id'] = (int)$_GET['user_id'];
}
if (!empty($_GET['from'])) { $where[] = 's.last_activity >= :from'; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to']))   { $where[] = 's.last_activity <= :to';   $params[':to']   = $_GET['to'] . ' 23:59:59'; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM sessions s LEFT JOIN users u ON u.id = s.user_id " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$rows = [];
try {
    $sql = "SELECT s.*, COALESCE(u.name,'') AS user_name
            FROM sessions s
            LEFT JOIN users u ON u.id = s.user_id
            $whereSql
            ORDER BY s.last_activity DESC
            LIMIT :limit OFFSET :offset";
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

$totalPages = (int)ceil(max(0, $total) / $perPage);

/* -------------------------
   Render page
   ------------------------- */
$pageTitle = 'Sessions';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($qraw); ?>" placeholder="Session id or data"></div>
      <div class="col-md-3"><label class="form-label">User</label>
        <select name="user_id" class="form-select">
          <option value="">Any</option>
          <?php foreach ($userList as $u): ?>
            <option value="<?php echo (int)$u['id']; ?>" <?php if(($_GET['user_id'] ?? '')===(string)$u['id']) echo 'selected'; ?>><?php echo e($u['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($_GET['to'] ?? ''); ?>"></div>
      <div class="col-md-1 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th style="width:180px">User</th>
            <th>Data preview</th>
            <th style="width:180px">Last activity</th>
            <th style="width:180px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr>
              <td class="mono"><?php echo e((string)$r['id']); ?></td>
              <td>
                <div class="fw-semibold"><?php echo e($r['user_name'] ?: ('#' . ($r['user_id'] ?? ''))); ?></div>
                <div class="small text-muted">user_id: <?php echo e((string)($r['user_id'] ?? '')); ?></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo e(mb_strimwidth(strip_tags((string)$r['data']), 0, 200, '...')); ?></div>
              </td>
              <td><?php echo e((string)($r['last_activity'] ?? '')); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo e($r['id']); ?>">View</button>
                <?php /* replaced below */ ?><span class="d-none" onclick="return confirm('Delete session <?php echo e($r['id']); ?>?');">Delete</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">No sessions found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($rows)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1,$totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo e(build_qs(['page'=>$p])); ?>"><?php echo $p; ?></a></li>
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
      <div class="modal-header"><h5 class="modal-title">Session details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>