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
require_once __DIR__ . '/../includes/staff_user.php';
if (file_exists(__DIR__ . '/../includes/whatsapp_config.php')) {
    require_once __DIR__ . '/../includes/whatsapp_config.php';
}

$esc = function(string $v) { return e($v); };

$jsonOut = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

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

$roles = staff_all_roles();
$addRoles = staff_addable_roles();
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];
if (!empty($_GET['added'])) {
    $messages[] = 'Staff user added after OTP verification.';
}
if (!empty($_GET['updated'])) {
    $messages[] = 'User updated after OTP verification.';
}

/* OTP send / verify (AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['staff_otp_send', 'staff_otp_verify'], true)) {
    $tok = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $jsonOut(['ok' => false, 'error' => 'Invalid security token. Reload the page.'], 403);
    }
    if ($action === 'staff_otp_send') {
        $jsonOut(staff_send_add_otps(
            (string) ($_POST['phone'] ?? ''),
            (string) ($_POST['whatsapp_id'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (int) ($_POST['except_id'] ?? 0)
        ));
    }
    $jsonOut(staff_verify_add_otp(
        (string) ($_POST['channel'] ?? ''),
        (string) ($_POST['otp'] ?? '')
    ));
}

/* ADD — parent is created from admission; owner is not added here */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $errors[] = 'Invalid security token. Please reload the page.';
    }
    $school_id = function_exists('auth_school_id') ? auth_school_id() : 1;
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone10 = staff_phone_last10((string) ($_POST['phone'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? 'staff'));
    $whatsapp10 = staff_phone_last10((string) ($_POST['whatsapp_id'] ?? ''));
    if ($whatsapp10 === '') {
        $whatsapp10 = $phone10;
    }
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (strlen($phone10) !== 10) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    }
    if (strlen($whatsapp10) !== 10) {
        $errors[] = 'Enter a valid 10-digit WhatsApp number.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($role === '' || !isset($addRoles[$role])) {
        $errors[] = 'Choose Owner, Accounts, Teacher, Reception, or Staff. Parents are added from New Admission.';
    }
    if ($errors === [] && !staff_add_otp_matches($phone10, $whatsapp10, $email, 0)) {
        $errors[] = 'Verify mobile, WhatsApp, and email OTPs before adding this user.';
    }
    $dup = $errors === [] ? staff_find_by_phone10($phone10, 0) : null;
    if ($dup) {
        $errors[] = 'This mobile number is already in use.';
    }

    if ($errors === []) {
        try {
            $metaJson = staff_meta_with_email(null, $email);
            $waStore = '91' . $whatsapp10;
            if (staff_has_phone_last10_column()) {
                $ok = safe_db_run(
                    'INSERT INTO users (school_id, name, phone, phone_last10, role, whatsapp_id, is_active, meta, created_at, updated_at)
                     VALUES (:school_id, :name, :phone, :p10, :role, :whatsapp_id, :is_active, :meta, NOW(), NOW())',
                    [
                        ':school_id' => $school_id,
                        ':name' => $name,
                        ':phone' => $phone10,
                        ':p10' => $phone10,
                        ':role' => $role,
                        ':whatsapp_id' => $waStore,
                        ':is_active' => $is_active,
                        ':meta' => $metaJson,
                    ]
                );
            } else {
                $ok = safe_db_run(
                    'INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
                     VALUES (:school_id, :name, :phone, :role, :whatsapp_id, :is_active, :meta, NOW(), NOW())',
                    [
                        ':school_id' => $school_id,
                        ':name' => $name,
                        ':phone' => $phone10,
                        ':role' => $role,
                        ':whatsapp_id' => $waStore,
                        ':is_active' => $is_active,
                        ':meta' => $metaJson,
                    ]
                );
            }
            if ($ok) {
                staff_add_otp_clear();
                header('Location: ?added=1');
                exit;
            }
            $errors[] = 'Failed to add user.';
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
    $row['email'] = staff_user_email_from_meta($row['meta'] ?? null);
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
    echo '<dt class="col-sm-3">Email</dt><dd class="col-sm-9">'.e(staff_user_email_from_meta($row['meta'] ?? null) ?: '—').'</dd>';
    echo '<dt class="col-sm-3">Role</dt><dd class="col-sm-9">'.e(ucfirst($row['role'] ?? '')).'</dd>';
    echo '<dt class="col-sm-3">Active</dt><dd class="col-sm-9">'.((int)$row['is_active'] ? 'Yes' : 'No').'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e((string)($row['created_at'] ?? '')).'</dd>';
    echo '</dl>';
    exit;
}

/* EDIT (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $errors[] = 'Invalid security token. Please reload the page.';
    }
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = function_exists('auth_school_id') ? auth_school_id() : 1;
    $name = trim((string)($_POST['name'] ?? ''));
    $phone10 = staff_phone_last10((string)($_POST['phone'] ?? ''));
    $role = trim((string)($_POST['role'] ?? 'staff'));
    $whatsapp10 = staff_phone_last10((string)($_POST['whatsapp_id'] ?? ''));
    if ($whatsapp10 === '') {
        $whatsapp10 = $phone10;
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;

    if ($id <= 0) $errors[] = 'Invalid user id.';
    if ($name === '') $errors[] = 'Name is required.';
    if (strlen($phone10) !== 10) $errors[] = 'Enter a valid 10-digit mobile number.';
    if (strlen($whatsapp10) !== 10) $errors[] = 'Enter a valid 10-digit WhatsApp number.';
    if ($role === '' || !isset($addRoles[$role])) $errors[] = 'Choose Owner, Accounts, Teacher, Reception, or Staff.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';

    $existing = $id > 0 ? safe_db_get_one('SELECT meta, role, is_active FROM users WHERE id = :id LIMIT 1', [':id' => $id]) : null;
    if ($existing && strtolower((string) ($existing['role'] ?? '')) === 'parent') {
        $errors[] = 'Parent accounts are managed from the Parents page.';
    }
    $selfId = staff_current_user_id();
    if ($existing && strtolower((string) ($existing['role'] ?? '')) === 'owner' && $role !== 'owner' && staff_count_owners(true) <= 1) {
        $errors[] = 'Cannot change the last owner to another role.';
    }
    if ($id === $selfId && $role !== 'owner') {
        $errors[] = 'You cannot remove the owner role from your own login.';
    }
    if ($id === $selfId && $is_active === 0) {
        $errors[] = 'You cannot deactivate your own login.';
    }
    if ($existing && strtolower((string) ($existing['role'] ?? '')) === 'owner' && $is_active === 0 && staff_count_owners(true) <= 1) {
        $errors[] = 'Cannot deactivate the last owner.';
    }
    if ($errors === [] && !staff_add_otp_matches($phone10, $whatsapp10, $email, $id)) {
        $errors[] = 'Verify mobile, WhatsApp, and email OTPs before saving.';
    }
    $dup = ($errors === [] && $id > 0) ? staff_find_by_phone10($phone10, $id) : null;
    if ($dup) {
        $errors[] = 'This mobile number is already in use.';
    }

    if (empty($errors)) {
        try {
            $metaJson = staff_meta_with_email($existing['meta'] ?? null, $email);
            $sql = "UPDATE users SET school_id = :school_id, name = :name, phone = :phone, role = :role, whatsapp_id = :whatsapp_id, is_active = :is_active, meta = :meta, updated_at = NOW() WHERE id = :id";
            $params = [
                ':school_id' => $school_id,
                ':name' => $name,
                ':phone' => $phone10,
                ':role' => $role,
                ':whatsapp_id' => '91' . $whatsapp10,
                ':is_active' => $is_active,
                ':meta' => $metaJson,
                ':id' => $id
            ];
            if (staff_has_phone_last10_column()) {
                $sql = "UPDATE users SET school_id = :school_id, name = :name, phone = :phone, phone_last10 = :p10, role = :role, whatsapp_id = :whatsapp_id, is_active = :is_active, meta = :meta, updated_at = NOW() WHERE id = :id";
                $params[':p10'] = $phone10;
            }
            $ok = safe_db_run($sql, $params);
            if ($ok) {
                staff_add_otp_clear();
                header('Location: ?updated=1'); exit;
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
    $row = $id > 0 ? safe_db_get_one('SELECT id, role FROM users WHERE id = :id LIMIT 1', [':id' => $id]) : null;
    if ($id <= 0 || $set === null || !$row) {
        $errors[] = 'Invalid parameters.';
    } elseif (strtolower((string) ($row['role'] ?? '')) === 'parent') {
        $errors[] = 'Change parent login from the Parents page.';
    } elseif ($set === 0 && $id === staff_current_user_id()) {
        $errors[] = 'You cannot deactivate your own login.';
    } elseif ($set === 0 && strtolower((string) ($row['role'] ?? '')) === 'owner' && staff_count_owners(true) <= 1) {
        $errors[] = 'Cannot deactivate the last owner.';
    } else {
        $ok = safe_db_run("UPDATE users SET is_active = :a, updated_at = NOW() WHERE id = :id", [':a'=>$set, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.';
        else $errors[] = 'Failed to update status.';
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
    $block = staff_delete_block_reason($id);
    if ($block !== null) {
        $errors[] = $block;
    } else {
        $ok = safe_db_run("DELETE FROM users WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'User deleted.';
        else $errors[] = 'Failed to delete user.';
    }
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = ["role <> 'parent'"]; $params = [];
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
if ($qraw !== '') { $where[] = "(u.name LIKE :q OR u.phone LIKE :q OR IFNULL(u.whatsapp_id,'') LIKE :q OR IFNULL(u.meta,'') LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
$roleFilter = trim((string)($_GET['role'] ?? ''));
if ($roleFilter === 'parent') {
    header('Location: ' . (function_exists('site_url') ? site_url('/owner/parents.php') : 'parents.php'));
    exit;
}
$where[] = "u.role <> 'parent'";
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
$selfId = staff_current_user_id();

/* Render header/footer if available */
$pageTitle = 'Staff';
$page_title = 'Staff';
require_once __DIR__ . '/../includes/header.php';
?>

<?php staff_people_nav('staff'); ?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">Add staff / owner</button>
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
          <?php foreach ($rolesList as $rk=>$rv): if ($rk === 'parent') continue; ?>
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
            <th>Name / Phone / Email</th>
            <th style="width:140px">Role</th>
            <th style="width:140px">WhatsApp</th>
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
                <?php $uEmail = staff_user_email_from_meta($u['meta'] ?? null); if ($uEmail !== ''): ?>
                  <div class="small"><?php echo $esc($uEmail); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo $esc($roles[$u['role']] ?? $u['role']); ?></td>
              <td><?php echo $esc($u['whatsapp_id'] ?? ''); ?></td>
              <td><?php echo (int)$u['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
              <td><?php echo $esc(substr($u['created_at'] ?? '',0,16)); ?></td>
              <td><?php echo $esc(substr($u['updated_at'] ?? '',0,16)); ?></td>
              <td class="text-nowrap">
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewUserModal" data-id="<?php echo (int)$u['id']; ?>">View</button>
                <?php if (($u['role'] ?? '') !== 'parent'): ?>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editUserModal" data-id="<?php echo (int)$u['id']; ?>">Edit</button>
                <?php endif; ?>
                <?php if ((int)$u['is_active']): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="?action=toggle&id=<?php echo (int)$u['id']; ?>&to=0">Deactivate</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success" href="?action=toggle&id=<?php echo (int)$u['id']; ?>&to=1">Activate</a>
                <?php endif; ?>
                <?php if (staff_delete_block_reason((int)$u['id']) === null): ?>
                  <?php echo render_secure_delete_button((int)$u['id'], 'Delete', 'Delete this user? This cannot be undone.'); ?>
                <?php endif; ?>
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
      <form method="post" action="?action=add" id="addStaffForm">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <div class="modal-header">
          <h5 class="modal-title">Add staff / owner</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Add Owner, Accounts, Teacher, Reception, or Staff. Mobile, WhatsApp, and email must be verified by OTP. Parent logins are on the Parents page.</p>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="add_name" class="form-control" required></div>
            <div class="col-md-6">
              <label class="form-label">Role *</label>
              <select name="role" id="add_role" class="form-select" required>
                <?php foreach ($addRoles as $rk=>$rv): ?><option value="<?php echo $esc($rk); ?>"><?php echo $esc($rv); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Mobile number *</label><input name="phone" id="add_phone" class="form-control" required inputmode="numeric" maxlength="15" placeholder="10-digit mobile"></div>
            <div class="col-md-6"><label class="form-label">Email ID *</label><input type="email" name="email" id="add_email" class="form-control" required placeholder="name@example.com"></div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp number *</label>
              <input name="whatsapp_id" id="add_whatsapp" class="form-control" inputmode="numeric" maxlength="15" placeholder="10-digit WhatsApp">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <label class="form-check-label mb-2">
                <input class="form-check-input me-2" type="checkbox" id="add_wa_same" checked>
                WhatsApp is the same as mobile
              </label>
            </div>
            <div class="col-md-6"><label class="form-check-label"><input class="form-check-input me-2" type="checkbox" id="add_active" name="is_active" checked> Active</label></div>
          </div>
          <div class="border rounded-3 p-3 mt-3 bg-light">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
              <strong>OTP verification</strong>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btnSendStaffOtp">Send OTPs</button>
            </div>
            <div id="staffOtpMsg" class="small mb-2"></div>
            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label">Mobile OTP (SMS)</label>
                <div class="input-group">
                  <input class="form-control" id="otp_phone" maxlength="8" inputmode="numeric" autocomplete="one-time-code">
                  <button type="button" class="btn btn-outline-secondary btn-verify-otp" data-channel="phone">Verify</button>
                </div>
                <div class="small" id="otp_phone_status"></div>
              </div>
              <div class="col-md-4" id="otpWaWrap">
                <label class="form-label">WhatsApp OTP</label>
                <div class="input-group">
                  <input class="form-control" id="otp_whatsapp" maxlength="8" inputmode="numeric">
                  <button type="button" class="btn btn-outline-secondary btn-verify-otp" data-channel="whatsapp">Verify</button>
                </div>
                <div class="small" id="otp_whatsapp_status"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Email OTP</label>
                <div class="input-group">
                  <input class="form-control" id="otp_email" maxlength="8" inputmode="numeric">
                  <button type="button" class="btn btn-outline-secondary btn-verify-otp" data-channel="email">Verify</button>
                </div>
                <div class="small" id="otp_email_status"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-success" type="submit" id="btnAddStaff" disabled>Add after OTP verify</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editStaffForm">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h5 class="modal-title">Edit staff / owner</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Same as Add: verify mobile, WhatsApp, and email with OTP before saving. Existing users can complete OTP now.</p>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="edit_name" class="form-control" required></div>
            <div class="col-md-6">
              <label class="form-label">Role *</label>
              <select name="role" id="edit_role" class="form-select" required>
                <?php foreach ($addRoles as $rk=>$rv): ?><option value="<?php echo $esc($rk); ?>"><?php echo $esc($rv); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Mobile number *</label><input name="phone" id="edit_phone" class="form-control" required inputmode="numeric" maxlength="15" placeholder="10-digit mobile"></div>
            <div class="col-md-6"><label class="form-label">Email ID *</label><input type="email" name="email" id="edit_email" class="form-control" required placeholder="name@example.com"></div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp number *</label>
              <input name="whatsapp_id" id="edit_whatsapp" class="form-control" inputmode="numeric" maxlength="15" placeholder="10-digit WhatsApp">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <label class="form-check-label mb-2">
                <input class="form-check-input me-2" type="checkbox" id="edit_wa_same" checked>
                WhatsApp is the same as mobile
              </label>
            </div>
            <div class="col-md-6"><label class="form-check-label"><input class="form-check-input me-2" type="checkbox" id="edit_active" name="is_active"> Active</label></div>
          </div>
          <div class="border rounded-3 p-3 mt-3 bg-light">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
              <strong>OTP verification</strong>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btnSendEditOtp">Send OTPs</button>
            </div>
            <div id="editOtpMsg" class="small mb-2"></div>
            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label">Mobile OTP (SMS)</label>
                <div class="input-group">
                  <input class="form-control" id="eotp_phone" maxlength="8" inputmode="numeric" autocomplete="one-time-code">
                  <button type="button" class="btn btn-outline-secondary btn-verify-edit-otp" data-channel="phone">Verify</button>
                </div>
                <div class="small" id="eotp_phone_status"></div>
              </div>
              <div class="col-md-4" id="editOtpWaWrap">
                <label class="form-label">WhatsApp OTP</label>
                <div class="input-group">
                  <input class="form-control" id="eotp_whatsapp" maxlength="8" inputmode="numeric">
                  <button type="button" class="btn btn-outline-secondary btn-verify-edit-otp" data-channel="whatsapp">Verify</button>
                </div>
                <div class="small" id="eotp_whatsapp_status"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Email OTP</label>
                <div class="input-group">
                  <input class="form-control" id="eotp_email" maxlength="8" inputmode="numeric">
                  <button type="button" class="btn btn-outline-secondary btn-verify-edit-otp" data-channel="email">Verify</button>
                </div>
                <div class="small" id="eotp_email_status"></div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit" id="btnSaveEdit" disabled>Save after OTP verify</button>
        </div>
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
  var csrf = <?php echo json_encode($csrf); ?>;

  function last10(v) {
    var d = String(v || '').replace(/\D+/g, '');
    return d.length >= 10 ? d.slice(-10) : d;
  }
  function postOtp(action, extra) {
    var body = new URLSearchParams(Object.assign({ action: action, csrf: csrf }, extra || {}));
    return fetch('staff_manage.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }
  function bindOtpPanel(opt) {
    var verified = { phone: false, whatsapp: false, email: false };
    function setStatus(id, ok, text) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = text || '';
      el.className = 'small ' + (ok ? 'text-success' : 'text-danger');
    }
    function refresh() {
      var all = verified.phone && verified.whatsapp && verified.email;
      var btn = document.getElementById(opt.saveBtn);
      if (btn) btn.disabled = !all;
    }
    function waSame() {
      return document.getElementById(opt.sameChk).checked;
    }
    function syncWa() {
      var phone = document.getElementById(opt.phone);
      var wa = document.getElementById(opt.whatsapp);
      var wrap = document.getElementById(opt.waWrap);
      if (!phone || !wa) return;
      if (waSame()) {
        wa.value = last10(phone.value);
        wa.readOnly = true;
        if (wrap) wrap.style.display = 'none';
      } else {
        wa.readOnly = false;
        if (wrap) wrap.style.display = '';
      }
    }
    document.getElementById(opt.sameChk).addEventListener('change', syncWa);
    document.getElementById(opt.phone).addEventListener('input', function () {
      if (waSame()) document.getElementById(opt.whatsapp).value = last10(this.value);
    });
    syncWa();
    document.getElementById(opt.sendBtn).addEventListener('click', function () {
      var msg = document.getElementById(opt.msg);
      msg.className = 'small mb-2 text-muted';
      msg.textContent = 'Sending OTPs…';
      verified = { phone: false, whatsapp: false, email: false };
      refresh();
      setStatus(opt.stPhone, false, '');
      setStatus(opt.stWa, false, '');
      setStatus(opt.stEmail, false, '');
      postOtp('staff_otp_send', {
        phone: document.getElementById(opt.phone).value,
        whatsapp_id: waSame() ? document.getElementById(opt.phone).value : document.getElementById(opt.whatsapp).value,
        email: document.getElementById(opt.email).value,
        except_id: opt.exceptId ? String(document.getElementById(opt.exceptId).value || '0') : '0'
      }).then(function (json) {
        if (json && json.ok) {
          msg.className = 'small mb-2 text-success';
          msg.textContent = json.info || 'OTPs sent.';
        } else {
          msg.className = 'small mb-2 text-danger';
          msg.textContent = (json && json.error) ? json.error : 'Failed to send OTPs.';
        }
      }).catch(function () {
        msg.className = 'small mb-2 text-danger';
        msg.textContent = 'Failed to send OTPs.';
      });
    });
    document.querySelectorAll(opt.verifyBtn).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var channel = btn.getAttribute('data-channel');
        var input = document.getElementById(opt.otpPrefix + channel);
        postOtp('staff_otp_verify', { channel: channel, otp: input ? input.value : '' }).then(function (json) {
          if (json && json.ok) {
            verified.phone = !!json.phone_ok;
            verified.whatsapp = !!json.wa_ok;
            verified.email = !!json.email_ok;
            setStatus(opt.otpPrefix + channel + '_status', true, 'Verified');
            if (channel === 'phone' && json.wa_ok) {
              setStatus(opt.stWa, true, 'Verified with mobile');
            }
            refresh();
          } else {
            setStatus(opt.otpPrefix + channel + '_status', false, (json && json.error) ? json.error : 'Failed');
          }
        });
      });
    });
    document.getElementById(opt.form).addEventListener('submit', function (ev) {
      if (waSame()) {
        document.getElementById(opt.whatsapp).value = last10(document.getElementById(opt.phone).value);
        document.getElementById(opt.whatsapp).readOnly = false;
      }
      if (!(verified.phone && verified.whatsapp && verified.email)) {
        ev.preventDefault();
        alert('Verify mobile, WhatsApp, and email OTPs first.');
      }
    });
    return { reset: function () { verified = { phone: false, whatsapp: false, email: false }; refresh(); syncWa(); } };
  }

  bindOtpPanel({
    form: 'addStaffForm',
    phone: 'add_phone',
    whatsapp: 'add_whatsapp',
    email: 'add_email',
    sameChk: 'add_wa_same',
    waWrap: 'otpWaWrap',
    sendBtn: 'btnSendStaffOtp',
    msg: 'staffOtpMsg',
    saveBtn: 'btnAddStaff',
    verifyBtn: '.btn-verify-otp',
    otpPrefix: 'otp_',
    stPhone: 'otp_phone_status',
    stWa: 'otp_whatsapp_status',
    stEmail: 'otp_email_status',
    exceptId: null
  });

  var editOtp = bindOtpPanel({
    form: 'editStaffForm',
    phone: 'edit_phone',
    whatsapp: 'edit_whatsapp',
    email: 'edit_email',
    sameChk: 'edit_wa_same',
    waWrap: 'editOtpWaWrap',
    sendBtn: 'btnSendEditOtp',
    msg: 'editOtpMsg',
    saveBtn: 'btnSaveEdit',
    verifyBtn: '.btn-verify-edit-otp',
    otpPrefix: 'eotp_',
    stPhone: 'eotp_phone_status',
    stWa: 'eotp_whatsapp_status',
    stEmail: 'eotp_email_status',
    exceptId: 'edit_id'
  });

  var editModal = document.getElementById('editUserModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget && event.relatedTarget.getAttribute('data-id');
      ['edit_id','edit_name','edit_phone','edit_role','edit_whatsapp','edit_email','eotp_phone','eotp_whatsapp','eotp_email'].forEach(function(idn){ var el = document.getElementById(idn); if (el) el.value = ''; });
      document.getElementById('edit_active').checked = false;
      document.getElementById('editOtpMsg').textContent = '';
      editOtp.reset();
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (json && json.ok && json.data) {
            var d = json.data;
            if (d.role === 'parent') {
              alert('Parent accounts are managed on the Parents page.');
              var mdl = bootstrap.Modal.getInstance(editModal);
              if (mdl) mdl.hide();
              return;
            }
            document.getElementById('edit_id').value = d.id || '';
            document.getElementById('edit_name').value = d.name || '';
            document.getElementById('edit_phone').value = last10(d.phone || '');
            document.getElementById('edit_role').value = d.role || '';
            document.getElementById('edit_whatsapp').value = last10(d.whatsapp_id || '');
            document.getElementById('edit_email').value = d.email || '';
            document.getElementById('edit_active').checked = (parseInt(d.is_active) === 1);
            document.getElementById('edit_wa_same').checked = last10(d.phone || '') === last10(d.whatsapp_id || '') || !d.whatsapp_id;
            editOtp.reset();
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