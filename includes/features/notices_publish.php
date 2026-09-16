<?php
/**
 * Shared feature: notices_publish
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$esc = function(string $v) { return e($v); };

/* Ensure notices table exists before proceeding */
if (!table_exists('notices')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>notices</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Actions: add, edit, delete, publish, unpublish, get (json), view (fragment), export
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $audience = trim((string)($_POST['audience'] ?? 'all'));
    $published_by = isset($_POST['published_by']) && $_POST['published_by'] !== '' ? (int)$_POST['published_by'] : null;
    $published_at = trim((string)($_POST['published_at'] ?? '')) ?: null;
    $expires_at = trim((string)($_POST['expires_at'] ?? '')) ?: null;

    if ($title === '') $errors[] = 'Title required.';
    if ($message === '') $errors[] = 'Message required.';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO notices (school_id,title,message,audience,published_by,published_at,expires_at,created_at)
                           VALUES (:school_id,:title,:message,:audience,:published_by,:published_at,:expires_at,NOW())",
            [
                ':school_id'=>$school_id,
                ':title'=>$title,
                ':message'=>$message,
                ':audience'=>$audience,
                ':published_by'=>$published_by,
                ':published_at'=>$published_at,
                ':expires_at'=>$expires_at
            ]);
        if ($ok) { $messages[] = 'Notice added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $audience = trim((string)($_POST['audience'] ?? 'all'));
    $published_by = isset($_POST['published_by']) && $_POST['published_by'] !== '' ? (int)$_POST['published_by'] : null;
    $published_at = trim((string)($_POST['published_at'] ?? '')) ?: null;
    $expires_at = trim((string)($_POST['expires_at'] ?? '')) ?: null;

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($title === '') $errors[] = 'Title required.';
    if ($message === '') $errors[] = 'Message required.';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE notices SET school_id=:school_id, title=:title, message=:message, audience=:audience, published_by=:published_by, published_at=:published_at, expires_at=:expires_at, created_at = created_at WHERE id=:id",
            [
                ':school_id'=>$school_id,
                ':title'=>$title,
                ':message'=>$message,
                ':audience'=>$audience,
                ':published_by'=>$published_by,
                ':published_at'=>$published_at,
                ':expires_at'=>$expires_at,
                ':id'=>$id
            ]);
        if ($ok) { $messages[] = 'Notice updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* PUBLISH (set published_at to now) */
if ($action === 'publish' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $by = auth_user_id();
    $ok = safe_db_run("UPDATE notices SET published_by = :by, published_at = NOW() WHERE id = :id", [':by'=>$by, ':id'=>$id]);
    if ($ok) $messages[] = 'Notice published.'; else $errors[] = 'Publish failed.';
}

/* UNPUBLISH (clear published_at) */
if ($action === 'unpublish' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $ok = safe_db_run("UPDATE notices SET published_at = NULL WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Notice unpublished.'; else $errors[] = 'Unpublish failed.';
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
    $ok = safe_db_run("DELETE FROM notices WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Notice deleted.'; else $errors[] = 'Delete failed.';
}

/* GET for edit (JSON) */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    $row = safe_db_get_one("SELECT id, school_id, title, message, audience, published_by, DATE_FORMAT(published_at,'%Y-%m-%d') AS published_at, DATE_FORMAT(expires_at,'%Y-%m-%d') AS expires_at, created_at FROM notices WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Notice not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]); exit;
}

/* VIEW modal fragment */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT n.*, COALESCE(u.name,'') AS publisher_name FROM notices n LEFT JOIN users u ON u.id = n.published_by WHERE n.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Notice not found</div>'; exit; }

    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Title</dt><dd class="col-sm-9">'.e($row['title']).'</dd>';
    echo '<dt class="col-sm-3">Audience</dt><dd class="col-sm-9">'.e($row['audience']).'</dd>';
    echo '<dt class="col-sm-3">Message</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['message']).'</pre></dd>';
    echo '<dt class="col-sm-3">Published by</dt><dd class="col-sm-9">'.($row['publisher_name'] ? e($row['publisher_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Published at</dt><dd class="col-sm-9">'.($row['published_at'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Expires at</dt><dd class="col-sm-9">'.($row['expires_at'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e($row['created_at']).'</dd>';
    echo '</dl>';
    exit;
}

/* EXPORT CSV */
if ($action === 'export') {
    $where=[]; $params=[];
    if (!empty($_GET['q'])) { $where[] = "(title LIKE :q OR message LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['audience'])) { $where[] = "audience = :audience"; $params[':audience'] = trim($_GET['audience']); }
    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
    $rows = safe_db_get_all("SELECT n.*, COALESCE(u.name,'') AS publisher_name FROM notices n LEFT JOIN users u ON u.id = n.published_by $whereSql ORDER BY n.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=notices_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','School ID','Title','Message','Audience','Published By','Published At','Expires At','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['school_id'] ?? '', $r['title'] ?? '', preg_replace("/\r\n|\r|\n/"," ", $r['message'] ?? ''), $r['audience'] ?? '',
            $r['publisher_name'] ?? '', $r['published_at'] ?? '', $r['expires_at'] ?? '', $r['created_at'] ?? ''
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
if ($qraw !== '') { $where[] = "(n.title LIKE :q OR n.message LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$audFilter = trim((string)($_GET['audience'] ?? ''));
if ($audFilter !== '') { $where[] = "n.audience = :audience"; $params[':audience'] = $audFilter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "n.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = trim((string)($_GET['to'] ?? ''));   if ($to   !== '') { $where[] = "n.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM notices n " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$notices = [];
try {
    $sql = "SELECT n.*, COALESCE(u.name,'') AS publisher_name FROM notices n LEFT JOIN users u ON u.id = n.published_by $whereSql ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $notices = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $notices = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

/* Data for forms (users list) */
$staffList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC") : [];
$audienceOptions = ['all'=>'All','students'=>'Students','parents'=>'Parents','staff'=>'Staff'];

$totalPages = (int)ceil(max(0, $total) / $perPage);

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = 'Notices — Publish';
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add Notice</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Title or message"></div>
      <div class="col-md-2"><label class="form-label">Audience</label>
        <select name="audience" class="form-select">
          <option value="">Any</option>
          <?php foreach ($audienceOptions as $k=>$v): ?>
            <option value="<?php echo e($k); ?>" <?php if(($audFilter ?? '')===$k) echo 'selected'; ?>><?php echo e($v); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo $esc($from); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo $esc($to); ?>"></div>
      <div class="col-md-2 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Title / Message</th>
            <th style="width:160px">Audience / Published</th>
            <th style="width:140px">Expires / Created</th>
            <th style="width:240px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($notices)): foreach ($notices as $n): ?>
            <tr>
              <td><?php echo (int)$n['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($n['title']); ?></div>
                <div class="small text-muted"><?php echo $esc(mb_strimwidth(strip_tags($n['message'] ?? ''), 0, 180, '...')); ?></div>
              </td>
              <td>
                <div class="small text-muted">Audience: <?php echo $esc($n['audience'] ?? 'all'); ?></div>
                <div class="small text-muted">By: <?php echo $esc($n['publisher_name'] ?? '—'); ?></div>
                <div class="small text-muted">Published: <?php echo $esc($n['published_at'] ?? '—'); ?></div>
              </td>
              <td>
                <div class="small text-muted">Expires: <?php echo $esc($n['expires_at'] ?? '—'); ?></div>
                <div class="small text-muted">Created: <?php echo $esc(substr($n['created_at'] ?? '',0,16)); ?></div>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$n['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$n['id']; ?>">Edit</button>

                <?php if (empty($n['published_at'])): ?>
                  <a class="btn btn-sm btn-primary" href="?action=publish&id=<?php echo (int)$n['id']; ?>">Publish</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-secondary" href="?action=unpublish&id=<?php echo (int)$n['id']; ?>">Unpublish</a>
                <?php endif; ?>

                <?php echo render_secure_delete_button((int)$n['id'], 'Delete', 'Delete notice?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="text-center text-muted">No notices found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($notices)); ?> of <?php echo $total; ?></div>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header"><h5 class="modal-title">Add Notice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label">Audience</label>
              <select name="audience" class="form-select">
                <?php foreach ($audienceOptions as $k=>$v): ?><option value="<?php echo e($k); ?>"><?php echo e($v); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-9"><label class="form-label">Title</label><input name="title" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Message</label><textarea name="message" rows="6" class="form-control" required></textarea></div>
            <div class="col-md-4"><label class="form-label">Published by (optional)</label>
              <select name="published_by" class="form-select">
                <option value="">--</option>
                <?php foreach ($staffList as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Published at (optional)</label><input type="date" name="published_at" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Expires at (optional)</label><input type="date" name="expires_at" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add Notice</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal (populated via JS by GET action) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editForm">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Notice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Notice Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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

  // Edit modal: fetch JSON and build form
  var editModal = document.getElementById('editModal');
  var staff = <?php echo json_encode($staffList, JSON_UNESCAPED_UNICODE); ?>;
  var audienceOptions = <?php echo json_encode($audienceOptions, JSON_UNESCAPED_UNICODE); ?>;
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load notice.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';

          var html = '';
          html += '<div class="row g-2">';
          // audience
          html += '<div class="col-md-3"><label class="form-label">Audience</label><select name="audience" id="edit_audience" class="form-select">';
          for (var k in audienceOptions) {
            html += '<option value="'+k+'">'+audienceOptions[k]+'</option>';
          }
          html += '</select></div>';
          // title
          html += '<div class="col-md-9"><label class="form-label">Title</label><input id="edit_title" name="title" class="form-control"></div>';
          // message
          html += '<div class="col-12"><label class="form-label">Message</label><textarea id="edit_message" name="message" rows="6" class="form-control"></textarea></div>';
          // published_by
          html += '<div class="col-md-4"><label class="form-label">Published by</label><select id="edit_published_by" name="published_by" class="form-select"><option value="">--</option>';
          staff.forEach(function(s){ html += '<option value="'+s.id+'">'+s.name+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-4"><label class="form-label">Published at</label><input id="edit_published_at" name="published_at" type="date" class="form-control"></div>';
          html += '<div class="col-md-4"><label class="form-label">Expires at</label><input id="edit_expires_at" name="expires_at" type="date" class="form-control"></div>';
          html += '</div>';
          body.innerHTML = html;

          // populate fields
          document.getElementById('edit_audience').value = d.audience || 'all';
          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_published_by').value = d.published_by || '';
          document.getElementById('edit_published_at').value = d.published_at || '';
          document.getElementById('edit_expires_at').value = d.expires_at || '';
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>