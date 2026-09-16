<?php
/**
 * reception/add_parents.php
 *
 * Reception-only page to add/manage Parent users.
 *
 * Behavior:
 * - List parents (role = 'parent') with search, filters, pagination
 * - Add parent (modal). Form has "Open admission after create" checkbox (name="admit").
 *   If checked, after parent is created (or existing parent found) server redirects to:
 *       /reception/admission.php?parent_id=NNN
 *   When the add request is done via AJAX (modal), server returns JSON including admit_url:
 *       { ok:true, id:NNN, name:"...", admit_url:"/reception/admission.php?parent_id=NNN" }
 *
 * - Edit, View, Toggle active, Delete, Export CSV
 *
 * Place at: /pioneerplayschool01/reception/add_parents.php
 *
 * Requires reception login: $_SESSION['reception_auth_user']
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth: reception only */
/* Optional includes (project helpers) */
/* Debug flag */
/**
 * Escape any value for HTML output.
 * Accepts mixed input (avoids static analysis P1006 warnings).
 *
 * @param mixed $v
 * @return string
 */

$esc = function($v = '') { return e($v); };

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

/* -------------------------
   Page behavior: manage only parents
   ------------------------- */

$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* CSRF token */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* -------------------------
   ADD parent (POST) - supports AJAX and normal POST
   ------------------------- */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $errors[] = 'Invalid CSRF token.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit; }
    } else {
        $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
        $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
        $is_active = isset($_POST['is_active']) && ($_POST['is_active'] === '1' || $_POST['is_active'] === 'on') ? 1 : 0;
        $meta_in = trim((string)($_POST['meta'] ?? ''));
        $admit = isset($_POST['admit']) && ($_POST['admit'] === '1' || $_POST['admit'] === 'on');

        if ($name === '') $errors[] = 'Name is required.';
        if ($phone === '') $errors[] = 'Phone is required.';

        $metaObj = null;
        if ($meta_in !== '') {
            $decoded = json_decode($meta_in, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $metaObj = $decoded;
            else $metaObj = ['note' => $meta_in];
        }

        if (empty($errors)) {
            try {
                // check if parent exists (phone or whatsapp). Prefer same school if provided.
                $existsSql = "SELECT id, name FROM users WHERE (phone = :p OR whatsapp_id = :p)";
                $params = [':p' => $phone];
                if ($school_id !== null) { $existsSql .= " AND school_id = :s LIMIT 1"; $params[':s'] = $school_id; } else $existsSql .= " LIMIT 1";
                $found = safe_db_get_one($existsSql, $params);

                if ($found) {
                    $parentId = (int)$found['id'];
                    // if AJAX return JSON including admit_url if requested
                    if ($isAjax) {
                        $resp = ['ok'=>true,'existing'=>true,'id'=>$parentId,'name'=>$found['name']];
                        if ($admit) $resp['admit_url'] = '../reception/admission.php?parent_id=' . urlencode((string)$parentId);
                        header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp); exit;
                    }
                    // normal POST: redirect to admission if requested, otherwise show message
                    if ($admit) { header('Location: ../reception/admission.php?parent_id=' . urlencode((string)$parentId)); exit; }
                    $messages[] = 'Parent already exists.';
                } else {
                    // insert parent
                    $pdo = pdo_connect();
                    if ($pdo instanceof \PDO) {
                        $stmt = $pdo->prepare("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
                                               VALUES (:school_id, :name, :phone, 'parent', :whatsapp_id, :is_active, :meta, NOW(), NOW())");
                        $stmt->execute([
                            ':school_id' => $school_id,
                            ':name' => $name,
                            ':phone' => $phone,
                            ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
                            ':is_active' => $is_active,
                            ':meta' => $metaObj !== null ? json_encode($metaObj, JSON_UNESCAPED_UNICODE) : null
                        ]);
                        $parentId = (int)$pdo->lastInsertId();
                    } else {
                        $ok = safe_db_run("INSERT INTO users (school_id, name, phone, role, whatsapp_id, is_active, meta, created_at, updated_at)
                                           VALUES (:school_id, :name, :phone, 'parent', :whatsapp_id, :is_active, :meta, NOW(), NOW())", [
                            ':school_id' => $school_id,
                            ':name' => $name,
                            ':phone' => $phone,
                            ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
                            ':is_active' => $is_active,
                            ':meta' => $metaObj !== null ? json_encode($metaObj, JSON_UNESCAPED_UNICODE) : null
                        ]);
                        if (!$ok) throw new RuntimeException('Insert failed');
                        // try to find id by phone
                        $r = safe_db_get_one("SELECT id FROM users WHERE phone = :p ORDER BY id DESC LIMIT 1", [':p'=>$phone]);
                        $parentId = $r['id'] ?? null;
                    }

                    if ($parentId) {
                        if ($isAjax) {
                            $resp = ['ok'=>true,'id'=>$parentId,'name'=>$name];
                            if ($admit) $resp['admit_url'] = '../reception/admission.php?parent_id=' . urlencode((string)$parentId);
                            header('Content-Type: application/json; charset=utf-8'); echo json_encode($resp); exit;
                        }
                        if ($admit) { header('Location: ../reception/admission.php?parent_id=' . urlencode((string)$parentId)); exit; }
                        $messages[] = 'Parent added successfully.';
                        header('Location: ?'); exit;
                    } else {
                        throw new RuntimeException('Could not determine new parent id.');
                    }
                }
            } catch (Throwable $e) {
                error_log('parent add error: ' . $e->getMessage());
                $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to add parent.';
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>end($errors)]); exit; }
            }
        } else {
            if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }
        }
    }
}

/* -------------------------
   GET (AJAX JSON) - return parent details for edit
   ------------------------- */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, name, phone, role, whatsapp_id, is_active, meta FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Parent not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]); exit;
}

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT u.*, COALESCE(s.name,'') AS school_name FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.id = :id AND u.role = 'parent' LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">Parent not found</div>'; exit; }
    echo '<dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.($row['school_name'] ? $esc($row['school_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">'.e($row['name']).'</dd>';
    echo '<dt class="col-sm-3">Phone</dt><dd class="col-sm-9">'.e($row['phone']).'</dd>';
    echo '<dt class="col-sm-3">WhatsApp</dt><dd class="col-sm-9">'.e($row['whatsapp_id'] ?? '').'</dd>';
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

/* EDIT parent (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));
    $whatsapp_id = trim((string)($_POST['whatsapp_id'] ?? ''));
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;
    $meta_in = trim((string)($_POST['meta'] ?? ''));

    if ($id <= 0) $errors[] = 'Invalid parent id.';
    if ($name === '') $errors[] = 'Name is required.';
    if ($phone === '') $errors[] = 'Phone is required.';

    $metaObj = null;
    if ($meta_in !== '') {
        $decoded = json_decode($meta_in, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $metaObj = $decoded;
        else $metaObj = ['note' => $meta_in];
    }

    if (empty($errors)) {
        try {
            $sql = "UPDATE users SET school_id = :school_id, name = :name, phone = :phone, whatsapp_id = :whatsapp_id, is_active = :is_active, meta = :meta, updated_at = NOW()
                    WHERE id = :id AND role = 'parent'";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':name' => $name,
                ':phone' => $phone,
                ':whatsapp_id' => $whatsapp_id !== '' ? $whatsapp_id : null,
                ':is_active' => $is_active,
                ':meta' => $metaObj !== null ? json_encode($metaObj, JSON_UNESCAPED_UNICODE) : null,
                ':id' => $id
            ]);
            if ($ok) {
                $messages[] = 'Parent updated successfully.';
                header('Location: ?'); exit;
            } else {
                $errors[] = 'Failed to update parent.';
            }
        } catch (Throwable $e) {
            error_log('parent edit error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to update parent.';
        }
    }
}

/* TOGGLE active/inactive */
if ($action === 'toggle' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $set = isset($_GET['to']) && ($_GET['to'] === '1' || $_GET['to'] === '0') ? (int)$_GET['to'] : null;
    if ($id > 0 && $set !== null) {
        $ok = safe_db_run("UPDATE users SET is_active = :a, updated_at = NOW() WHERE id = :id AND role = 'parent'", [':a'=>$set, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.';
        else $errors[] = 'Failed to update status.';
    } else $errors[] = 'Invalid parameters.';
}

/* DELETE parent */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId;
    if ($id > 0) {
        $ok = safe_db_run("DELETE FROM users WHERE id = :id AND role = 'parent'", [':id'=>$id]);
        if ($ok) $messages[] = 'Parent deleted.';
        else $errors[] = 'Failed to delete parent.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV (parents only) */
if ($action === 'export') {
    $where = ["role = 'parent'"]; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(name LIKE :q OR phone LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') { $where[] = "is_active = :ia"; $params[':ia'] = (int)$_GET['is_active']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, name, phone, whatsapp_id, is_active, meta, created_at, updated_at FROM users $whereSql ORDER BY name ASC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=parents_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','Name','Phone','WhatsApp','Active','Meta','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['name'] ?? '',
            $r['phone'] ?? '',
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
   Listing (filters & pagination) - parents only
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$where = ["u.role = 'parent'"]; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(u.name LIKE :q OR u.phone LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
if (isset($_GET['is_active']) && $_GET['is_active'] !== '') { $where[] = "u.is_active = :ia"; $params[':ia'] = (int)$_GET['is_active']; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM users u " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$parents = [];
try {
    $sql = "SELECT u.id, u.school_id, u.name, u.phone, u.whatsapp_id, u.is_active, u.meta, u.created_at, u.updated_at
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
        $parents = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $parents = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
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

/* Render page (header/footer reuse if present) */
$pageTitle = 'Add / Manage Parents';
require_once __DIR__ . '/../includes/header.php';
?>

<div>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addParentModal">Add Parent</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Name or phone"></div>
      <div class="col-md-3"><label class="form-label">Active</label>
        <select name="is_active" class="form-select">
          <option value="">Any</option>
          <option value="1" <?php if(isset($_GET['is_active']) && $_GET['is_active']==='1') echo 'selected'; ?>>Active</option>
          <option value="0" <?php if(isset($_GET['is_active']) && $_GET['is_active']==='0') echo 'selected'; ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-5 text-end">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="?">Reset</a>
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
            <th style="width:120px">WhatsApp</th>
            <th style="width:80px">Active</th>
            <th style="width:180px">Created</th>
            <th style="width:180px">Updated</th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($parents)): foreach ($parents as $p): ?>
            <tr>
              <td><?php echo (int)$p['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($p['name']); ?></div>
                <div class="small text-muted"><?php echo $esc($p['phone']); ?></div>
              </td>
              <td><?php echo $esc($p['whatsapp_id'] ?? ''); ?></td>
              <td><?php echo (int)$p['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
              <td><?php echo $esc(substr($p['created_at'] ?? '',0,16)); ?></td>
              <td><?php echo $esc(substr($p['updated_at'] ?? '',0,16)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewParentModal" data-id="<?php echo (int)$p['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editParentModal" data-id="<?php echo (int)$p['id']; ?>">Edit</button>
                <?php if ((int)$p['is_active']): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="?action=toggle&id=<?php echo (int)$p['id']; ?>&to=0">Deactivate</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success" href="?action=toggle&id=<?php echo (int)$p['id']; ?>&to=1">Activate</a>
                <?php endif; ?>
                <?php echo render_secure_delete_button((int)$p['id'], 'Delete', 'Delete parent?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No parents found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($parents)); ?> of <?php echo $total; ?></div>
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

<!-- Add Parent Modal -->
<div class="modal fade" id="addParentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="addParentForm" method="post" action="?action=add">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
        <div class="modal-header">
          <h5 class="modal-title">Add Parent</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="add_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" id="add_phone" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">WhatsApp ID</label><input name="whatsapp_id" id="add_whatsapp" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Active</label><select name="is_active" class="form-select"><option value="1" selected>Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-3"><label class="form-label">School ID (optional)</label><input name="school_id" class="form-control" placeholder="school id"></div>
            <div class="col-12"><label class="form-label">Meta (JSON or notes)</label><textarea name="meta" class="form-control" rows="3" placeholder='{"key":"value"} or free text'></textarea></div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="admitAfterCreate" name="admit" value="1">
                <label class="form-check-label" for="admitAfterCreate">Open Admission form after creating parent</label>
              </div>
            </div>

            <div class="col-12">
              <div id="addParentErrors" class="text-danger small"></div>
              <div id="addParentSuccess" class="text-success small"></div>
            </div>

          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button id="addParentSubmit" class="btn btn-primary" type="submit">Add Parent</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Parent Modal -->
<div class="modal fade" id="editParentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editParentForm">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
        <input type="hidden" name="id" id="edit_parent_id">
        <div class="modal-header">
          <h5 class="modal-title">Edit Parent</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="edit_parent_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input name="phone" id="edit_parent_phone" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">WhatsApp ID</label><input name="whatsapp_id" id="edit_parent_whatsapp" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Active</label><select name="is_active" id="edit_parent_active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-md-3"><label class="form-label">School ID</label><input name="school_id" id="edit_parent_school" class="form-control"></div>
            <div class="col-12"><label class="form-label">Meta (JSON or notes)</label><textarea name="meta" id="edit_parent_meta" class="form-control" rows="3"></textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Parent Modal -->
<div class="modal fade" id="viewParentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Parent Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewParentBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Edit parent modal: fetch JSON and populate
  var editModal = document.getElementById('editParentModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      document.getElementById('edit_parent_id').value = '';
      document.getElementById('edit_parent_name').value = '';
      document.getElementById('edit_parent_phone').value = '';
      document.getElementById('edit_parent_whatsapp').value = '';
      document.getElementById('edit_parent_meta').value = '';
      document.getElementById('edit_parent_school').value = '';
      document.getElementById('edit_parent_active').value = '1';

      fetch('?action=get&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (json && json.ok && json.data) {
            var d = json.data;
            document.getElementById('edit_parent_id').value = d.id || '';
            document.getElementById('edit_parent_name').value = d.name || '';
            document.getElementById('edit_parent_phone').value = d.phone || '';
            document.getElementById('edit_parent_whatsapp').value = d.whatsapp_id || '';
            document.getElementById('edit_parent_meta').value = d.meta || '';
            document.getElementById('edit_parent_school').value = d.school_id || '';
            document.getElementById('edit_parent_active').value = (parseInt(d.is_active) === 1) ? '1' : '0';
          } else {
            alert(json.error || 'Failed to load parent for edit.');
            var mdl = bootstrap.Modal.getInstance(editModal);
            if (mdl) mdl.hide();
          }
        })
        .catch(function(){
          alert('Failed to load parent for edit.');
          var mdl = bootstrap.Modal.getInstance(editModal);
          if (mdl) mdl.hide();
        });
    });
  }

  // View parent modal: load fragment
  var viewModal = document.getElementById('viewParentModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewParentBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials:'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }

  // Add parent form: AJAX submit (modal). If you prefer full form POST (page reload) remove this block.
  var addForm = document.getElementById('addParentForm');
  if (addForm) {
    addForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      var fd = new FormData(addForm);
      // Ensure checkbox 'admit' is sent only if checked (FormData will handle it)
      var errEl = document.getElementById('addParentErrors');
      var okEl = document.getElementById('addParentSuccess');
      errEl.textContent = ''; okEl.textContent = '';
      var btn = document.getElementById('addParentSubmit');
      btn.disabled = true;
      btn.textContent = 'Saving...';

      fetch(location.pathname + '?action=add', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(resp){
        return resp.text().then(function(text){
          if (!resp.ok) {
            errEl.textContent = 'HTTP ' + resp.status + ': ' + (text || resp.statusText || 'Error');
            btn.disabled = false; btn.textContent = 'Add Parent';
            return;
          }
          var json;
          try { json = JSON.parse(text || '{}'); } catch (e) { errEl.textContent = 'Invalid JSON response'; btn.disabled = false; btn.textContent = 'Add Parent'; return; }
          if (!json.ok) {
            errEl.textContent = json.error || (json.errors ? json.errors.join('; ') : 'Failed');
            btn.disabled = false; btn.textContent = 'Add Parent';
            return;
          }
          okEl.textContent = 'Parent saved.';
          // If server provided admit_url redirect
          if (json.admit_url) {
            window.location.href = json.admit_url;
            return;
          }
          // Close modal and refresh listing
          setTimeout(function(){
            var modalEl = document.getElementById('addParentModal');
            var md = bootstrap.Modal.getInstance(modalEl);
            if (md) md.hide();
            window.location.reload();
          }, 600);
        });
      })
      .catch(function(err){
        console.error(err);
        errEl.textContent = 'Network/server error: ' + (err && err.message ? err.message : 'Unknown');
        btn.disabled = false; btn.textContent = 'Add Parent';
      });
    });
  }

});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>