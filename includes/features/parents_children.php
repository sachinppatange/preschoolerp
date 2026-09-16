<?php
/**
 * Shared feature: parents_children
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$esc = function(string $v) { return e($v); };

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) {
        if ($v === null) unset($qs[$k]); else $qs[$k] = $v;
    }
    return http_build_query($qs);
}

/* -------------------------
   Ensure parents_children table exists
   ------------------------- */
if (!table_exists('parents_children')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>parents_children</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Prepare lists: parents (users) and students
   ------------------------- */
$parentList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC") : [];
$studentList = table_exists('students') ? safe_db_get_all("SELECT id, CONCAT(first_name, ' ', COALESCE(last_name,'')) AS name FROM students ORDER BY first_name, last_name ASC") : [];

/* Allowed relations (UI choices) */
$relationOptions = ['mother','father','guardian','other'];

/* messages/errors */
$messages = []; $errors = [];

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent_user_id = isset($_POST['parent_user_id']) && $_POST['parent_user_id'] !== '' ? (int)$_POST['parent_user_id'] : null;
    $child_student_id = isset($_POST['child_student_id']) && $_POST['child_student_id'] !== '' ? (int)$_POST['child_student_id'] : null;
    $relation = trim((string)($_POST['relation'] ?? ''));

    if (!$parent_user_id) $errors[] = 'Parent is required.';
    if (!$child_student_id) $errors[] = 'Child/student is required.';
    if ($relation === '') $relation = 'guardian';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO parents_children (parent_user_id, child_student_id, relation, created_at) VALUES (:p, :c, :r, NOW())",
            [':p'=>$parent_user_id, ':c'=>$child_student_id, ':r'=>$relation]);
        if ($ok) { $messages[] = 'Relationship added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $parent_user_id = isset($_POST['parent_user_id']) && $_POST['parent_user_id'] !== '' ? (int)$_POST['parent_user_id'] : null;
    $child_student_id = isset($_POST['child_student_id']) && $_POST['child_student_id'] !== '' ? (int)$_POST['child_student_id'] : null;
    $relation = trim((string)($_POST['relation'] ?? ''));

    if ($id <= 0) $errors[] = 'Invalid id.';
    if (!$parent_user_id) $errors[] = 'Parent is required.';
    if (!$child_student_id) $errors[] = 'Child/student is required.';
    if ($relation === '') $relation = 'guardian';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE parents_children SET parent_user_id = :p, child_student_id = :c, relation = :r WHERE id = :id",
            [':p'=>$parent_user_id, ':c'=>$child_student_id, ':r'=>$relation, ':id'=>$id]);
        if ($ok) { $messages[] = 'Relationship updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* VIEW fragment */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT pc.*, COALESCE(u.name,'') AS parent_name, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS child_name
                            FROM parents_children pc
                            LEFT JOIN users u ON u.id = pc.parent_user_id
                            LEFT JOIN students s ON s.id = pc.child_student_id
                            WHERE pc.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">Record not found</div>'; exit; }

    echo '<dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Parent</dt><dd class="col-sm-9">'.e($row['parent_name'] ?: ('#'.$row['parent_user_id'])).'</dd>';
    echo '<dt class="col-sm-3">Child</dt><dd class="col-sm-9">'.e($row['child_name'] ?: ('#'.$row['child_student_id'])).'</dd>';
    echo '<dt class="col-sm-3">Relation</dt><dd class="col-sm-9">'.e(ucfirst((string)$row['relation'])).'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e((string)$row['created_at']).'</dd>';
    echo '</dl>';
    exit;
}

/* GET (for edit) returns JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, parent_user_id, child_student_id, relation FROM parents_children WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$row]); exit;
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
        $ok = safe_db_run("DELETE FROM parents_children WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'Record deleted.'; else $errors[] = 'Delete failed.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['parent_user_id'])) { $where[] = "pc.parent_user_id = :p"; $params[':p'] = (int)$_GET['parent_user_id']; }
    if (!empty($_GET['child_student_id'])) { $where[] = "pc.child_student_id = :c"; $params[':c'] = (int)$_GET['child_student_id']; }
    if (!empty($_GET['relation'])) { $where[] = "pc.relation = :r"; $params[':r'] = $_GET['relation']; }
    if (!empty($_GET['from'])) { $where[] = "pc.created_at >= :from"; $params[':from'] = $_GET['from'] . ' 00:00:00'; }
    if (!empty($_GET['to'])) { $where[] = "pc.created_at <= :to"; $params[':to'] = $_GET['to'] . ' 23:59:59'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT pc.*, COALESCE(u.name,'') AS parent_name, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS child_name
                             FROM parents_children pc
                             LEFT JOIN users u ON u.id = pc.parent_user_id
                             LEFT JOIN students s ON s.id = pc.child_student_id
                             $whereSql
                             ORDER BY pc.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=parents_children_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','Parent','Parent ID','Child','Child ID','Relation','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['parent_name'] ?: '',
            $r['parent_user_id'] ?? '',
            $r['child_name'] ?: '',
            $r['child_student_id'] ?? '',
            $r['relation'] ?? '',
            $r['created_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination (list)
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(COALESCE(u.name,'') LIKE :q OR CONCAT(s.first_name,' ',COALESCE(s.last_name,'')) LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
if (!empty($_GET['parent_user_id'])) { $where[] = "pc.parent_user_id = :p"; $params[':p'] = (int)$_GET['parent_user_id']; }
if (!empty($_GET['child_student_id'])) { $where[] = "pc.child_student_id = :c"; $params[':c'] = (int)$_GET['child_student_id']; }
if (!empty($_GET['relation'])) { $where[] = "pc.relation = :r"; $params[':r'] = $_GET['relation']; }
$from = $_GET['from'] ?? ''; if ($from !== '') { $where[] = "pc.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to = $_GET['to'] ?? '';   if ($to !== '')   { $where[] = "pc.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($where, $params, 's');
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM parents_children pc LEFT JOIN users u ON u.id = pc.parent_user_id LEFT JOIN students s ON s.id = pc.child_student_id " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$rows = [];
try {
    $sql = "SELECT pc.*, COALESCE(u.name,'') AS parent_name, COALESCE(CONCAT(s.first_name,' ',COALESCE(s.last_name,'')),'') AS child_name
            FROM parents_children pc
            LEFT JOIN users u ON u.id = pc.parent_user_id
            LEFT JOIN students s ON s.id = pc.child_student_id
            $whereSql
            ORDER BY pc.created_at DESC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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
$pageTitle = 'Parent-Child Relationships';
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add Relation</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Parent or child name"></div>
      <div class="col-md-3"><label class="form-label">Parent</label>
        <select name="parent_user_id" class="form-select"><option value="">Any</option><?php foreach ($parentList as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php if(($_GET['parent_user_id'] ?? '')===(string)$p['id']) echo 'selected'; ?>><?php echo $esc($p['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-3"><label class="form-label">Child</label>
        <select name="child_student_id" class="form-select"><option value="">Any</option><?php foreach ($studentList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if(($_GET['child_student_id'] ?? '')===(string)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Relation</label>
        <select name="relation" class="form-select"><option value="">Any</option><?php foreach ($relationOptions as $rel): ?><option value="<?php echo $esc($rel); ?>" <?php if(($_GET['relation'] ?? '')===$rel) echo 'selected'; ?>><?php echo $esc(ucfirst($rel)); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-1 text-end"><button class="btn btn-primary">Filter</button></div>
      <div class="col-12 text-end mt-2"><small class="text-muted">Filter by created date range:</small></div>
      <div class="col-md-3"><input type="date" name="from" class="form-control" value="<?php echo $esc($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-3"><input type="date" name="to" class="form-control" value="<?php echo $esc($_GET['to'] ?? ''); ?>"></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Parent</th>
            <th>Child</th>
            <th style="width:140px">Relation</th>
            <th style="width:200px">Created</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo $esc($r['parent_name'] ?: ('#'.$r['parent_user_id'])); ?></td>
              <td><?php echo $esc($r['child_name'] ?: ('#'.$r['child_student_id'])); ?></td>
              <td><?php echo $esc(ucfirst((string)$r['relation'])); ?></td>
              <td><?php echo $esc(substr((string)($r['created_at'] ?? ''),0,19)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$r['id']; ?>">Edit</button>
                <?php echo render_secure_delete_button((int)$r['id'], 'Delete', 'Delete record?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No relationships found.</td></tr>
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
        <div class="modal-header"><h5 class="modal-title">Add Parent-Child Relation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Parent (User)</label>
              <select name="parent_user_id" class="form-select" required>
                <option value="">Select parent</option>
                <?php foreach ($parentList as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo $esc($p['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Child (Student)</label>
              <select name="child_student_id" class="form-select" required>
                <option value="">Select child</option>
                <?php foreach ($studentList as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Relation</label>
              <select name="relation" class="form-select">
                <?php foreach ($relationOptions as $rel): ?><option value="<?php echo $esc($rel); ?>"><?php echo $esc(ucfirst($rel)); ?></option><?php endforeach; ?>
              </select>
            </div>
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
        <div class="modal-header"><h5 class="modal-title">Edit Relation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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
      <div class="modal-header"><h5 class="modal-title">Relation details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // Edit modal: fetch JSON and populate fields
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-6"><label class="form-label">Parent (User)</label><select name="parent_user_id" id="edit_parent_user_id" class="form-select">';
          html += '<option value="">Select parent</option>';
          <?php foreach ($parentList as $p): ?> html += '<option value="<?php echo (int)$p['id']; ?>"><?php echo e(addslashes($p['name'])); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '<div class="col-md-6"><label class="form-label">Child (Student)</label><select name="child_student_id" id="edit_child_student_id" class="form-select">';
          html += '<option value="">Select child</option>';
          <?php foreach ($studentList as $s): ?> html += '<option value="<?php echo (int)$s['id']; ?>"><?php echo e(addslashes($s['name'])); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '<div class="col-md-6"><label class="form-label">Relation</label><select name="relation" id="edit_relation" class="form-select">';
          <?php foreach ($relationOptions as $rel): ?> html += '<option value="<?php echo e($rel); ?>"><?php echo e(ucfirst($rel)); ?></option>'; <?php endforeach; ?>
          html += '</select></div>';
          html += '</div>';
          body.innerHTML = html;

          document.getElementById('edit_parent_user_id').value = d.parent_user_id || '';
          document.getElementById('edit_child_student_id').value = d.child_student_id || '';
          document.getElementById('edit_relation').value = d.relation || '';
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }

  // View modal: load fragment
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>