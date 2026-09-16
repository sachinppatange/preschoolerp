<?php
/**
 * owner/staff_manage.php
 *
 * Staff / Users management for owner.
 *
 * Table: users
 * Columns:
 *   id (PK), school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at
 *
 * Features:
 * - List with search / filters / pagination
 * - Add (modal), Edit (modal), View (modal)
 * - Activate / Deactivate
 * - Delete
 * - Export CSV
 * - AJAX endpoints: get (JSON) and view (HTML fragment)
 *
 * Place at: /pioneerplayschool01/owner/staff_manage.php
 *
 * Requires session auth: $_SESSION['owner_auth_user']
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional includes */
/* Auth */
/* Debug flag */
$esc = function(string $v) { return e($v); };

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

/* Ensure users table exists */
if (!table_exists('users')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>users</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* roles supported */
$roles = ['owner'=>'Owner','accounts'=>'Accounts','teacher'=>'Teacher','reception'=>'Reception','parent'=>'Parent','staff'=>'Staff'];

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $role = trim((string)($_POST['role'] ?? 'staff'));
    $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;
    $meta_in = trim((string)($_POST['meta'] ?? ''));

    if ($name === '') $errors[] = 'Name is required.';
    if ($phone === '') $errors[] = 'Phone is required.';
    if ($role === '' || !isset($roles[$role])) $errors[] = 'Invalid role selected.';

    // normalize phone
    $phoneNorm = preg_replace('/\s+/', '', $phone);

    // meta JSON validation
    $metaObj = null;
    if ($meta_in !== '') {
        $decoded = json_decode($meta_in, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $metaObj = $decoded;
        else $errors[] = 'Meta must be valid JSON.';
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
                    VALUES (:school_id, :name, :phone, :role, :whatsapp_id, :is_active, :meta, NOW(), NOW())";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':name' => $name,
                ':phone' => $phoneNorm,
                ':role' => $role,
                ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
                ':is_active' => $is_active,
                ':meta' => $metaObj !== null ? json_encode($metaObj, JSON_UNESCAPED_UNICODE) : null
            ]);
            if ($ok) {
                $messages[] = 'User added successfully.';
                header('Location: ?'); exit;
            } else {
                $errors[] = 'Failed to add user.';
            }
        } catch (Throwable $e) {
            error_log('user add error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to add user.';
        }
    }
}

/* GET (AJAX JSON) */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, name, phone, role, whatsapp_id, is_active, meta FROM users WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'User not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT u.*, COALESCE(s.name,'') AS school_name FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">User not found</div>'; exit; }
    echo '<dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.($row['school_name'] ? $esc($row['school_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.e($row['name']).'</dd>';
    echo '<dt class="col-sm-3">Phone</dt><dd class="col-sm-9">'.e($row['phone']).'</dd>';
    echo '<dt class="col-sm-3">WhatsApp</dt><dd class="col-sm-9">'.e($row['whatsapp_id'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Role</dt><dd class="col-sm-9">'.e(ucfirst($row['role'] ?? '')).'</dd>';
    echo '<dt class="col-sm-3">Active</dt><dd class="col-sm-9">'.((int)$row['is_active'] ? 'Yes' : 'No').'</dd>';
    $metaDisplay = '';
    if (!empty($row['meta'])) {
        $m = @json_decode($row['meta'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($m)) $metaDisplay = '<pre style="white-space:pre-wrap;">' . e(json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        else $metaDisplay = '<pre style="white-space:pre-wrap;">' . e($row['meta']) . '</pre>';
    } else $metaDisplay = '—';
    echo '<dt class="col-12">Meta</dt><dd class="col-12">' . $metaDisplay . '</dd>';
    echo '</dl>';
    exit;
}

/* EDIT (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $role = trim((string)($_POST['role'] ?? 'staff'));
    $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;
    $meta_in = trim((string)($_POST['meta'] ?? ''));

    if ($id <= 0) $errors[] = 'Invalid user id.';
    if ($name === '') $errors[] = 'Name is required.';
    if ($phone === '') $errors[] = 'Phone is required.';
    if ($role === '' || !isset($roles[$role])) $errors[] = 'Invalid role selected.';

    $metaObj = null;
    if ($meta_in !== '') {
        $decoded = json_decode($meta_in, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $metaObj = $decoded;
        else $errors[] = 'Meta must be valid JSON.';
    }

    if (empty($errors)) {
        try {
            $sql = "UPDATE users SET school_id = :school_id, name = :name, phone = :phone, role = :role, whatsapp_id = :whatsapp_id, is_active = :is_active, meta = :meta, updated_at = NOW() WHERE id = :id";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':name' => $name,
                ':phone' => preg_replace('/\s+/', '', $phone),
                ':role' => $role,
                ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
                ':is_active' => $is_active,
                ':meta' => $metaObj !== null ? json_encode($metaObj, JSON_UNESCAPED_UNICODE) : null,
                ':id' => $id
            ]);
            if ($ok) {
                $messages[] = 'User updated successfully.';
                header('Location: ?'); exit;
            } else {
                $errors[] = 'Failed to update user.';
            }
        } catch (Throwable $e) {
            error_log('user edit error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to update user.';
        }
    }
}

/* ACTIVATE / DEACTIVATE */
if ($action === 'toggle' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $set = isset($_GET['to']) && ($_GET['to'] === '1' || $_GET['to'] === '0') ? (int)$_GET['to'] : null;
    if ($id > 0 && $set !== null) {
        $ok = safe_db_run("UPDATE users SET is_active = :a, updated_at = NOW() WHERE id = :id", [':a'=>$set, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.';
        else $errors[] = 'Failed to update status.';
    } else $errors[] = 'Invalid parameters.';
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
        $ok = safe_db_run("DELETE FROM users WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'User deleted.';
        else $errors[] = 'Failed to delete user.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(name LIKE :q OR phone LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['role'])) { $where[] = "role = :role"; $params[':role'] = $_GET['role']; }
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') { $where[] = "is_active = :ia"; $params[':ia'] = (int)$_GET['is_active']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at FROM users $whereSql ORDER BY name ASC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=users_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','Name','Phone','Role','WhatsApp','Active','Meta','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['role'] ?? '',
            $r['whatsapp_id'] ?? '',
            isset($r['is_active']) ? ((int)$r['is_active'] ? '1' : '0') : '',
            $r['meta'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? ''
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
if ($qraw !== '') { $where[] = "(u.name LIKE :q OR u.phone LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
$roleFilter = trim((string)($_GET['role'] ?? ''));
if ($roleFilter !== '') { $where[] = "u.role = :role"; $params[':role'] = $roleFilter; }
if (isset($_GET['is_active']) && $_GET['is_active'] !== '') { $where[] = "u.is_active = :ia"; $params[':ia'] = (int)$_GET['is_active']; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM users u " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$users = [];
try {
    $sql = "SELECT u.id, u.school_id, u.name, u.phone, u.role, u.whatsapp_id, u.is_active, u.meta, u.created_at, u.updated_at
            FROM users u
            $whereSql
            ORDER BY u.name ASC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $users = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
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

/* Data for forms */
$rolesList = $roles;

/* Render header/footer if available */
$pageTitle = 'Staff Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">Add Staff</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Name or phone"></div>
      <div class="col-md-3"><label class="form-label">Role</label>
        <select name="role" class="form-select">
          <option value="">Any</option>
          <?php foreach ($rolesList as $rk=>$rv): ?>
            <option value="<?php echo $esc($rk); ?>" <?php if(($roleFilter ?? '')===$rk) echo 'selected'; ?>><?php echo $esc($rv); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Active</label>
        <select name="is_active" class="form-select">
          <option value="">Any</option>
          <option value="1" <?php if(isset($_GET['is_active']) && $_GET['is_active']==='1') echo 'selected'; ?>>Active</option>
          <option value="0" <?php if(isset($_GET['is_active']) && $_GET['is_active']==='0') echo 'selected'; ?>>Inactive</option>
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
            <th>Name / Phone</th>
            <th style="width:160px">Role</th>
            <th style="width:120px">WhatsApp</th>
            <th style="width:80px">Active</th>
            <th style="width:180px">Created</th>
            <th style="width:180px">Updated</th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($users)): foreach ($users as $u): ?>
            <tr>
              <td><?php echo (int)$u['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($u['name']); ?></div>
                <div class="small text-muted"><?php echo $esc($u['phone']); ?></div>
              </td>
              <td><?php echo $esc($roles[$u['role']] ?? $u['role']); ?></td>
              <td><?php echo $esc($u['whatsapp_id'] ?? ''); ?></td>
              <td><?php echo (int)$u['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
              <td><?php echo $esc(substr($u['created_at'] ?? '',0,16)); ?></td>
              <td><?php echo $esc(substr($u['updated_at'] ?? '',0,16)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewUserModal" data-id="<?php echo (int)$u['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editUserModal" data-id="<?php echo (int)$u['id']; ?>">Edit</button>
                <?php if ((int)$u['is_active']): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="?action=toggle&id=<?php echo (int)$u['id']; ?>&to=0">Deactivate</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success" href="?action=toggle&id=<?php echo (int)$u['id']; ?>&to=1">Activate</a>
                <?php endif; ?>
                <?php echo render_secure_delete_button((int)$u['id'], 'Delete', 'Delete user?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($users)); ?> of <?php echo $total; ?></div>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header">
          <h5 class="modal-title">Add Staff / User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" class="form-control" required></div>
            <div class="col-md-4">
              <label class="form-label">Role</label>
              <select name="role" class="form-select">
                <?php foreach ($rolesList as $rk=>$rv): ?><option value="<?php echo $esc($rk); ?>"><?php echo $esc($rv); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">WhatsApp ID</label><input name="whatsapp_id" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Active</label><div class="form-check"><input class="form-check-input" type="checkbox" id="add_active" name="is_active" checked><label class="form-check-label" for="add_active">Active</label></div></div>
            <div class="col-12"><label class="form-label">Meta (JSON)</label><textarea name="meta" class="form-control" rows="3" placeholder='{"key":"value"}'></textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-success" type="submit">Add User</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editUserForm">
        <div class="modal-header">
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="edit_id">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="edit_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" id="edit_phone" class="form-control" required></div>
            <div class="col-md-4">
              <label class="form-label">Role</label>
              <select name="role" id="edit_role" class="form-select">
                <?php foreach ($rolesList as $rk=>$rv): ?><option value="<?php echo $esc($rk); ?>"><?php echo $esc($rv); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">WhatsApp ID</label><input name="whatsapp_id" id="edit_whatsapp" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Active</label><div class="form-check"><input class="form-check-input" id="edit_active" name="is_active" type="checkbox"><label class="form-check-label" for="edit_active">Active</label></div></div>
            <div class="col-12"><label class="form-label">Meta (JSON)</label><textarea name="meta" id="edit_meta" class="form-control" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">User details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="viewUserBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Edit modal: load JSON
  var editModal = document.getElementById('editUserModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      ['edit_id','edit_name','edit_phone','edit_role','edit_whatsapp','edit_meta'].forEach(function(idn){ var el = document.getElementById(idn); if (el) el.value = ''; });
      document.getElementById('edit_active').checked = false;
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (json && json.ok && json.data) {
            var d = json.data;
            document.getElementById('edit_id').value = d.id || '';
            document.getElementById('edit_name').value = d.name || '';
            document.getElementById('edit_phone').value = d.phone || '';
            document.getElementById('edit_role').value = d.role || '';
            document.getElementById('edit_whatsapp').value = d.whatsapp_id || '';
            document.getElementById('edit_meta').value = d.meta || '';
            document.getElementById('edit_active').checked = (parseInt(d.is_active) === 1);
          } else {
            alert(json.error || 'Failed to load user for edit.');
            var mdl = bootstrap.Modal.getInstance(editModal);
            if (mdl) mdl.hide();
          }
        })
        .catch(function(){
          alert('Failed to load user for edit.');
          var mdl = bootstrap.Modal.getInstance(editModal);
          if (mdl) mdl.hide();
        });
    });
  }

  // View modal: load fragment
  var viewModal = document.getElementById('viewUserModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewUserBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>