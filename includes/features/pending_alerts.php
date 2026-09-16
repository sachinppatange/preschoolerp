<?php
/**
 * Shared feature: pending_alerts
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$esc = function(string $v) { return e($v); };

/* Ensure alerts table exists */
if (!table_exists('alerts')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>alerts</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Allowed levels
   ------------------------- */
$levelOptions = ['info','warning','critical'];

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $message = trim((string)($_POST['message'] ?? ''));
    $level = in_array($_POST['level'] ?? 'info', $levelOptions, true) ? $_POST['level'] : 'info';
    $link = trim((string)($_POST['link'] ?? '')) ?: null;
    $is_read = !empty($_POST['is_read']) ? 1 : 0;

    if ($message === '') $errors[] = 'Message is required.';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO alerts (school_id,message,level,link,is_read,created_at) VALUES (:school_id,:message,:level,:link,:is_read,NOW())",
            [':school_id'=>$school_id, ':message'=>$message, ':level'=>$level, ':link'=>$link, ':is_read'=>$is_read]);
        if ($ok) { $messages[] = 'Alert added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $message = trim((string)($_POST['message'] ?? ''));
    $level = in_array($_POST['level'] ?? 'info', $levelOptions, true) ? $_POST['level'] : 'info';
    $link = trim((string)($_POST['link'] ?? '')) ?: null;
    $is_read = !empty($_POST['is_read']) ? 1 : 0;

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($message === '') $errors[] = 'Message required.';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE alerts SET school_id=:school_id, message=:message, level=:level, link=:link, is_read=:is_read WHERE id = :id",
            [':school_id'=>$school_id, ':message'=>$message, ':level'=>$level, ':link'=>$link, ':is_read'=>$is_read, ':id'=>$id]);
        if ($ok) { $messages[] = 'Alert updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* VIEW modal fragment */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT a.*, COALESCE(s.name,'') AS school_name FROM alerts a LEFT JOIN schools s ON s.id = a.school_id WHERE a.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Alert not found</div>'; exit; }

    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.($row['school_name'] ? e($row['school_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Level</dt><dd class="col-sm-9">'.e($row['level']).'</dd>';
    echo '<dt class="col-sm-3">Message</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['message']).'</pre></dd>';
    echo '<dt class="col-sm-3">Link</dt><dd class="col-sm-9">'.($row['link'] ? '<a href="'.e($row['link']).'" target="_blank">Open</a>' : '—').'</dd>';
    echo '<dt class="col-sm-3">Read</dt><dd class="col-sm-9">'.($row['is_read'] ? 'Yes' : 'No').'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e($row['created_at']).'</dd>';
    echo '</dl>';
    exit;
}

/* GET for edit (JSON) */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, message, level, link, is_read, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') AS created_at FROM alerts WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Alert not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]); exit;
}

/* MARK read/unread */
if ($action === 'mark' && !empty($_GET['id']) && isset($_GET['to'])) {
    $id = (int)$_GET['id']; $to = ($_GET['to'] === '1') ? 1 : 0;
    $ok = safe_db_run("UPDATE alerts SET is_read = :r WHERE id = :id", [':r'=>$to, ':id'=>$id]);
    if ($ok) $messages[] = 'Updated.'; else $errors[] = 'Update failed.';
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
    $ok = safe_db_run("DELETE FROM alerts WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Alert deleted.'; else $errors[] = 'Delete failed.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where=[]; $params=[];
    if (!empty($_GET['q'])) { $where[] = "message LIKE :q"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['level']) && in_array($_GET['level'], $levelOptions, true)) { $where[] = "level = :level"; $params[':level'] = $_GET['level']; }
    if (isset($_GET['is_read']) && ($_GET['is_read']==='0' || $_GET['is_read']==='1')) { $where[] = "is_read = :is_read"; $params[':is_read'] = (int)$_GET['is_read']; }
    if (!empty($_GET['from'])) { $where[] = "created_at >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to'])) { $where[] = "created_at <= :to"; $params[':to'] = $_GET['to'] . ' 23:59:59'; }
    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
    $rows = safe_db_get_all("SELECT a.*, COALESCE(s.name,'') AS school_name FROM alerts a LEFT JOIN schools s ON s.id = a.school_id $whereSql ORDER BY a.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=alerts_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','School','Message','Level','Link','Is Read','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['school_name'] ?? '',
            preg_replace("/\r\n|\r|\n/"," ", $r['message'] ?? ''),
            $r['level'] ?? '',
            $r['link'] ?? '',
            $r['is_read'] ? '1' : '0',
            $r['created_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;
$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "a.message LIKE :q"; $params[':q'] = '%'.$qraw.'%'; }
$levelFilter = $_GET['level'] ?? '';
if ($levelFilter !== '' && in_array($levelFilter, $levelOptions, true)) { $where[] = "a.level = :level"; $params[':level'] = $levelFilter; }
$is_read_filter = isset($_GET['is_read']) && ($_GET['is_read']==='0' || $_GET['is_read']==='1') ? (int)$_GET['is_read'] : null;
if ($is_read_filter !== null) { $where[] = "a.is_read = :is_read"; $params[':is_read'] = $is_read_filter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "a.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = trim((string)($_GET['to'] ?? ''));   if ($to   !== '') { $where[] = "a.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM alerts a " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$alerts = [];
try {
    $sql = "SELECT a.*, COALESCE(s.name,'') AS school_name FROM alerts a LEFT JOIN schools s ON s.id = a.school_id $whereSql ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $alerts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $alerts = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int)ceil(max(0, $total) / $perPage);
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = 'Alerts';
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAlertModal">Add Alert</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Message contains"></div>
      <div class="col-md-2"><label class="form-label">Level</label>
        <select name="level" class="form-select"><option value="">Any</option><?php foreach ($levelOptions as $lv): ?><option value="<?php echo e($lv); ?>" <?php if(($levelFilter ?? '')===$lv) echo 'selected'; ?>><?php echo e(ucfirst($lv)); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Read</label>
        <select name="is_read" class="form-select"><option value="">Any</option><option value="0" <?php if($is_read_filter === 0) echo 'selected'; ?>>Unread</option><option value="1" <?php if($is_read_filter === 1) echo 'selected'; ?>>Read</option></select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo $esc($from); ?>"></div>
      <div class="col-md-2 text-end"><label class="form-label d-block invisible">x</label><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Message</th>
            <th style="width:180px">Level / Link</th>
            <th style="width:100px">Read</th>
            <th style="width:160px">Created</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($alerts)): foreach ($alerts as $a): ?>
            <tr>
              <td><?php echo (int)$a['id']; ?></td>
              <td>
                <div class="small text-muted"><?php echo $esc($a['school_name'] ?? ''); ?></div>
                <div class="fw-semibold"><?php echo $esc(mb_strimwidth(strip_tags($a['message'] ?? ''), 0, 200, '...')); ?></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc(ucfirst($a['level'] ?? '')); ?></div>
                <div class="small mt-1"><?php echo $a['link'] ? '<a href="'.e($a['link']).'" target="_blank">Open link</a>' : '—'; ?></div>
              </td>
              <td>
                <?php if ($a['is_read']): ?><span class="badge bg-secondary">Read</span><?php else: ?><span class="badge bg-info">Unread</span><?php endif; ?>
              </td>
              <td><?php echo $esc(substr($a['created_at'] ?? '', 0, 16)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$a['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editAlertModal" data-id="<?php echo (int)$a['id']; ?>">Edit</button>

                <?php if ($a['is_read']): ?>
                  <a class="btn btn-sm btn-outline-primary" href="?action=mark&id=<?php echo (int)$a['id']; ?>&to=0">Mark unread</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success" href="?action=mark&id=<?php echo (int)$a['id']; ?>&to=1">Mark read</a>
                <?php endif; ?>

                <?php echo render_secure_delete_button((int)$a['id'], 'Delete', 'Delete alert?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No alerts found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($alerts)); ?> of <?php echo $total; ?></div>
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

<!-- Add Alert Modal -->
<div class="modal fade" id="addAlertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header"><h5 class="modal-title">Add Alert</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label">Level</label>
              <select name="level" class="form-select">
                <?php foreach ($levelOptions as $lv): ?><option value="<?php echo e($lv); ?>"><?php echo e(ucfirst($lv)); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">School ID</label><input name="school_id" class="form-control" value="1"></div>
            <div class="col-md-6"><label class="form-label">Link (optional)</label><input name="link" class="form-control"></div>
            <div class="col-12"><label class="form-label">Message *</label><textarea name="message" rows="6" class="form-control" required></textarea></div>
            <div class="col-md-3"><label class="form-check mt-2"><input type="checkbox" name="is_read" value="1" class="form-check-input"> Mark as read</label></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add Alert</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Alert Modal -->
<div class="modal fade" id="editAlertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editAlertForm">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Alert</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editAlertBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Alert Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal
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

  // Edit modal: fetch JSON and populate form
  var editModal = document.getElementById('editAlertModal');
  var levelOptions = <?php echo json_encode($levelOptions, JSON_UNESCAPED_UNICODE); ?>;
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editAlertBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load alert.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';

          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-3"><label class="form-label">Level</label><select name="level" id="edit_level" class="form-select">';
          levelOptions.forEach(function(l){ html += '<option value="'+l+'">'+(l.charAt(0).toUpperCase()+l.slice(1))+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-3"><label class="form-label">School ID</label><input id="edit_school_id" name="school_id" class="form-control"></div>';
          html += '<div class="col-md-6"><label class="form-label">Link</label><input id="edit_link" name="link" class="form-control"></div>';
          html += '<div class="col-12"><label class="form-label">Message</label><textarea id="edit_message" name="message" rows="6" class="form-control"></textarea></div>';
          html += '<div class="col-md-3"><label class="form-check mt-2"><input class="form-check-input" id="edit_is_read" name="is_read" type="checkbox" value="1"> Mark as read</label></div>';
          html += '</div>';
          body.innerHTML = html;

          document.getElementById('edit_level').value = d.level || 'info';
          document.getElementById('edit_school_id').value = d.school_id || 1;
          document.getElementById('edit_link').value = d.link || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_is_read').checked = !!d.is_read;
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>