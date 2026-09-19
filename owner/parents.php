<?php
/**
 * owner/parents.php — all parent login accounts and their children in one place.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();
require_once __DIR__ . '/../includes/staff_user.php';

$esc = function (string $v): string {
    return e($v);
};

if (!table_exists('users')) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Users table not found.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$pwReady = function_exists('user_password_column_ready') && user_password_column_ready();
$pwMin = function_exists('login_password_min_length') ? login_password_min_length() : 6;
$action = (string) ($_REQUEST['action'] ?? 'list');
$messages = [];
$errors = [];

$parentPostedPassword = static function (array &$errors, int $minLen): string {
    $pw = (string) ($_POST['new_password'] ?? '');
    $cf = (string) ($_POST['new_password_confirm'] ?? '');
    if ($pw === '' && $cf === '') {
        return '';
    }
    if ($pw !== $cf) {
        $errors[] = 'New password and confirmation do not match.';
        return '';
    }
    if (strlen($pw) < $minLen) {
        $errors[] = 'Password must be at least ' . $minLen . ' characters.';
        return '';
    }
    return $pw;
};

if ($action === 'toggle' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    $set = isset($_GET['to']) && ($_GET['to'] === '1' || $_GET['to'] === '0') ? (int) $_GET['to'] : null;
    $row = $id > 0 ? safe_db_get_one("SELECT id, role, meta FROM users WHERE id = :id LIMIT 1", [':id' => $id]) : null;
    if (!$row || ($row['role'] ?? '') !== 'parent' || $set === null) {
        $errors[] = 'Invalid parent account.';
    } elseif ($set === 1 && !parent_has_child_in_year($id)) {
        $errors[] = 'This parent has no child in the current academic year, so login cannot be enabled.';
    } else {
        $metaJson = parent_merge_meta($row['meta'] ?? null, ['login_locked' => $set === 0]);
        $ok = safe_db_run('UPDATE users SET is_active = :a, meta = :m, updated_at = NOW() WHERE id = :id AND role = :r', [
            ':a' => $set,
            ':m' => $metaJson,
            ':id' => $id,
            ':r' => 'parent',
        ]);
        if ($ok) {
            header('Location: ?updated=1');
            exit;
        }
        $errors[] = 'Failed to update status.';
    }
}

if ($action === 'get' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) $_GET['id'];
    $row = $id > 0 ? safe_db_get_one("SELECT id, name, phone, whatsapp_id, is_active, meta FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id' => $id]) : null;
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Parent not found.']);
        exit;
    }
    $emails = function_exists('parent_emails_from_students') ? parent_emails_from_students([$id]) : [];
    $row['email'] = function_exists('parent_display_email') ? parent_display_email($row, $emails) : staff_user_email_from_meta($row['meta'] ?? null);
    $row['has_password'] = false;
    if ($pwReady) {
        $ph = safe_db_get_one('SELECT password_hash FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
        $row['has_password'] = !empty($ph['password_hash']);
    }
    echo json_encode(['ok' => true, 'data' => $row]);
    exit;
}

if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $errors[] = 'Invalid security token. Please reload the page.';
    }
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone10 = parent_phone_last10((string) ($_POST['phone'] ?? ''));
    $whatsapp10 = parent_phone_last10((string) ($_POST['whatsapp_id'] ?? ''));
    if ($whatsapp10 === '') {
        $whatsapp10 = $phone10;
    }
    $emailRaw = strtolower(trim((string) ($_POST['email'] ?? '')));
    $email = parent_normalize_email($emailRaw);
    $is_active = isset($_POST['is_active']) && ($_POST['is_active'] === '1' || $_POST['is_active'] === 'on') ? 1 : 0;
    $existing = $id > 0 ? safe_db_get_one("SELECT * FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id' => $id]) : null;

    if (!$existing) {
        $errors[] = 'Parent not found.';
    }
    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (strlen($phone10) !== 10) {
        $errors[] = 'Enter a valid 10-digit mobile number.';
    }
    if (strlen($whatsapp10) !== 10) {
        $errors[] = 'Enter a valid 10-digit WhatsApp number.';
    }
    if ($emailRaw !== '' && $email === '') {
        $errors[] = 'Enter a valid email address.';
    }
    if ($errors === [] && $is_active === 1 && !parent_has_child_in_year($id)) {
        $errors[] = 'This parent has no child in the current academic year, so login cannot be enabled.';
    }
    $dup = ($errors === [] && strlen($phone10) === 10) ? staff_find_by_phone10($phone10, $id) : null;
    if ($dup) {
        $errors[] = 'This mobile number is already in use.';
    }
    $plainPw = $parentPostedPassword($errors, $pwMin);
    if ($plainPw !== '' && !$pwReady) {
        $errors[] = 'Password login is not available on this database yet.';
    }

    if ($errors === []) {
        try {
            $metaJson = parent_meta_with_email($existing['meta'] ?? null, $email);
            $metaJson = parent_merge_meta($metaJson, ['login_locked' => $is_active === 0]);
            $sql = 'UPDATE users SET name = :name, phone = :phone, whatsapp_id = :wa, is_active = :a, meta = :m, updated_at = NOW() WHERE id = :id AND role = :r';
            $params = [
                ':name' => $name,
                ':phone' => $phone10,
                ':wa' => '91' . $whatsapp10,
                ':a' => $is_active,
                ':m' => $metaJson,
                ':id' => $id,
                ':r' => 'parent',
            ];
            if (function_exists('staff_has_phone_last10_column') && staff_has_phone_last10_column()) {
                $sql = 'UPDATE users SET name = :name, phone = :phone, phone_last10 = :p10, whatsapp_id = :wa, is_active = :a, meta = :m, updated_at = NOW() WHERE id = :id AND role = :r';
                $params[':p10'] = $phone10;
            }
            $ok = safe_db_run($sql, $params);
            if ($ok) {
                $pwQs = '';
                if ($plainPw !== '') {
                    $pwQs = (function_exists('login_set_user_password') && login_set_user_password($id, $plainPw))
                        ? '&pw=1'
                        : '&pw=0';
                }
                header('Location: ?saved=1' . $pwQs);
                exit;
            }
            $errors[] = 'Failed to save parent.';
        } catch (Throwable $e) {
            error_log('parent edit: ' . $e->getMessage());
            $errors[] = $DEBUG ? $e->getMessage() : 'Failed to save parent.';
        }
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $block = parent_delete_block_reason($deleteId);
    if ($block !== null) {
        $errors[] = $block;
    } else {
        $ok = safe_db_run("DELETE FROM users WHERE id = :id AND role = 'parent'", [':id' => $deleteId]);
        if ($ok) {
            header('Location: ?deleted=1');
            exit;
        }
        $errors[] = 'Failed to delete parent.';
    }
}

if ($action === 'export') {
    $ay = function_exists('ay_selected') ? ay_selected() : parent_login_academic_year();
    $yearIds = parent_ids_with_child_in_year($ay);
    $qraw = trim((string) ($_GET['q'] ?? ''));
    $where = ["u.role = 'parent'"];
    $params = [];
    if ($yearIds === []) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'u.id IN (' . implode(',', array_map('intval', $yearIds)) . ')';
    }
    if ($qraw !== '') {
        $where[] = '(u.name LIKE :q OR u.phone LIKE :q OR IFNULL(u.whatsapp_id,\'\') LIKE :q OR IFNULL(u.meta,\'\') LIKE :q)';
        $params[':q'] = '%' . $qraw . '%';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $rows = safe_db_get_all("SELECT u.id, u.name, u.phone, u.whatsapp_id, u.is_active, u.meta, u.created_at FROM users u $whereSql ORDER BY u.name ASC", $params) ?: [];
    $exportIds = array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows);
    $kids = staff_children_by_parent_ids($exportIds, $ay);
    $studentEmails = function_exists('parent_emails_from_students') ? parent_emails_from_students($exportIds) : [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=parents_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Parent name', 'Mobile', 'WhatsApp', 'Email', 'Children', 'Child count', 'Active', 'Created']);
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        $labels = array_map('staff_child_label', $kids[$id] ?? []);
        fputcsv($out, [
            $id,
            $r['name'] ?? '',
            $r['phone'] ?? '',
            $r['whatsapp_id'] ?? '',
            function_exists('parent_display_email') ? parent_display_email($r, $studentEmails) : staff_user_email_from_meta($r['meta'] ?? null),
            implode('; ', $labels),
            count($labels),
            !empty($r['is_active']) ? 'Yes' : 'No',
            $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    $row = safe_db_get_one("SELECT * FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id' => $id]);
    if (!$row) {
        echo '<div class="text-muted">Parent not found.</div>';
        exit;
    }
    $kids = staff_children_by_parent_ids([$id], function_exists('ay_selected') ? ay_selected() : null);
    $viewUrl = function_exists('site_url') ? rtrim(site_url('/owner/students_view.php'), '?') : 'students_view.php';
    echo '<dl class="row mb-0">';
    echo '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">' . e((string) $row['name']) . '</dd>';
    echo '<dt class="col-sm-3">Mobile</dt><dd class="col-sm-9">' . e((string) $row['phone']) . '</dd>';
    echo '<dt class="col-sm-3">WhatsApp</dt><dd class="col-sm-9">' . e((string) ($row['whatsapp_id'] ?? '')) . '</dd>';
    $viewEmails = function_exists('parent_emails_from_students') ? parent_emails_from_students([$id]) : [];
    $viewEmail = function_exists('parent_display_email') ? parent_display_email($row, $viewEmails) : staff_user_email_from_meta($row['meta'] ?? null);
    echo '<dt class="col-sm-3">Email</dt><dd class="col-sm-9">' . e($viewEmail !== '' ? $viewEmail : '—') . '</dd>';
    echo '<dt class="col-sm-3">Login User ID</dt><dd class="col-sm-9">' . e(parent_phone_last10((string) $row['phone']) ?: '—') . '</dd>';
    $hasPwView = $pwReady && !empty($row['password_hash']);
    echo '<dt class="col-sm-3">Password</dt><dd class="col-sm-9">' . ($hasPwView ? 'Set — owner can reset from Edit' : 'Not set — set from Edit') . '</dd>';
    echo '<dt class="col-sm-3">Login</dt><dd class="col-sm-9">' . ((int) $row['is_active'] ? 'Active' : 'Inactive') . '</dd>';
    echo '<dt class="col-sm-3">Children</dt><dd class="col-sm-9">';
    $list = $kids[$id] ?? [];
    if ($list === []) {
        echo '<span class="text-muted">No linked students.</span>';
    } else {
        echo '<ul class="mb-0 ps-3">';
        foreach ($list as $ch) {
            $href = $viewUrl . '?id=' . (int) $ch['id'];
            echo '<li><a href="' . e($href) . '">' . e(staff_child_label($ch)) . '</a>';
            if (!empty($ch['status'])) {
                echo ' <span class="text-muted">· ' . e((string) $ch['status']) . '</span>';
            }
            if (!empty($ch['academic_year'])) {
                echo ' <span class="text-muted">· ' . e((string) $ch['academic_year']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</dd></dl>';
    exit;
}

if (!empty($_GET['updated'])) {
    $messages[] = 'Parent login status updated.';
}
if (!empty($_GET['saved'])) {
    $messages[] = 'Parent details saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Parent login removed.';
}
if (!empty($_GET['pw']) && (string) $_GET['pw'] === '1') {
    $messages[] = 'Login password saved. They sign in with User ID (10-digit mobile) and this password.';
}
if (isset($_GET['pw']) && (string) $_GET['pw'] === '0') {
    $errors[] = 'Details saved, but the password could not be set. Open Edit and try again.';
}

$panelAy = function_exists('ay_selected') ? ay_selected() : parent_login_academic_year();
$loginAy = parent_login_academic_year();
if ($action === 'list' || $action === '') {
    $backfill = parent_backfill_missing_logins($panelAy);
    if (($backfill['fixed'] ?? 0) > 0) {
        $messages[] = 'Created/linked ' . (int) $backfill['fixed'] . ' missing parent login' . ((int) $backfill['fixed'] === 1 ? '' : 's') . ' from student admission details.';
    }
    $sync = parent_sync_logins_for_year($loginAy);
    if (($sync['disabled'] ?? 0) > 0 || ($sync['enabled'] ?? 0) > 0) {
        $messages[] = 'Parent logins synced for ' . $loginAy . ': enabled ' . (int) $sync['enabled'] . ', auto-disabled ' . (int) $sync['disabled'] . ' (no child this year).';
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$qraw = trim((string) ($_GET['q'] ?? ''));
$activeFilter = (string) ($_GET['is_active'] ?? '');

$yearIds = parent_ids_with_child_in_year($panelAy);
$where = ["u.role = 'parent'"];
$params = [];
if ($yearIds === []) {
    $where[] = '1 = 0';
} else {
    $where[] = 'u.id IN (' . implode(',', array_map('intval', $yearIds)) . ')';
}
if ($qraw !== '') {
    $search = '(u.name LIKE :q OR u.phone LIKE :q OR IFNULL(u.whatsapp_id,\'\') LIKE :q OR IFNULL(u.meta,\'\') LIKE :q)';
    if (table_exists('students')) {
        $search = '(' . $search . ' OR u.id IN (
            SELECT parent_id FROM students
            WHERE parent_id IS NOT NULL AND (
                first_name LIKE :q2 OR last_name LIKE :q2
                OR CONCAT(IFNULL(first_name,\'\'), \' \', IFNULL(last_name,\'\')) LIKE :q2
            )
        ))';
        $params[':q2'] = '%' . $qraw . '%';
    }
    $where[] = $search;
    $params[':q'] = '%' . $qraw . '%';
}
if ($activeFilter === '1' || $activeFilter === '0') {
    $where[] = 'u.is_active = :ia';
    $params[':ia'] = (int) $activeFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM users u $whereSql", $params);
$total = (int) ($cRow['c'] ?? 0);

$parents = [];
$pdo = pdo_connect();
$sql = "SELECT u.id, u.name, u.phone, u.whatsapp_id, u.is_active, u.meta, u.created_at" . ($pwReady ? ", u.password_hash" : "") . "
        FROM users u
        $whereSql
        ORDER BY u.name ASC
        LIMIT :limit OFFSET :offset";
try {
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $parents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $errors[] = $DEBUG ? $e->getMessage() : 'Could not load parents.';
}

$parentIdsOnPage = array_map(static fn($r) => (int) ($r['id'] ?? 0), $parents);
$kids = staff_children_by_parent_ids($parentIdsOnPage, $panelAy);
$studentEmails = function_exists('parent_emails_from_students') ? parent_emails_from_students($parentIdsOnPage) : [];
$totalPages = (int) ceil(max(0, $total) / $perPage);
$studentView = function_exists('site_url') ? site_url('/owner/students_view.php') : 'students_view.php';
$admissionUrl = function_exists('site_url') ? site_url('/reception/admission.php') : '../reception/admission.php';

function parents_qs(array $over = []): string
{
    $qs = $_GET;
    unset($qs['action']);
    foreach ($over as $k => $v) {
        if ($v === null) {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return http_build_query($qs);
}

$page_title = 'Parents';
require_once __DIR__ . '/../includes/header.php';
?>
<?php staff_people_nav('parents'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <p class="text-muted mb-0">
    <?php echo e(function_exists('ay_display_long') ? ay_display_long($panelAy) : ('Academic Year ' . $panelAy)); ?>
    — only parents who have a child in this year.
    Edit to change name/mobile or set a forgotten password. Disable login to block the parent portal without deleting the family.
  </p>
  <div class="d-flex gap-2">
    <a class="btn btn-success" href="<?php echo $esc($admissionUrl); ?>">New admission</a>
    <a class="btn btn-outline-secondary" href="?">Refresh</a>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; ?>

<div class="card mb-3 p-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-6">
      <label class="form-label">Search</label>
      <input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Parent name, mobile, or child name">
    </div>
    <div class="col-md-3">
      <label class="form-label">Login</label>
      <select name="is_active" class="form-select">
        <option value="">Any</option>
        <option value="1" <?php echo $activeFilter === '1' ? 'selected' : ''; ?>>Active</option>
        <option value="0" <?php echo $activeFilter === '0' ? 'selected' : ''; ?>>Inactive</option>
      </select>
    </div>
    <div class="col-md-3 text-md-end">
      <button class="btn btn-primary">Search</button>
      <a class="btn btn-outline-secondary" href="parents.php">Reset</a>
      <a class="btn btn-outline-success" href="?action=export&amp;<?php echo e(parents_qs()); ?>">CSV</a>
    </div>
  </form>
</div>

<div class="card card-soft">
  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>Parent</th>
          <th>Mobile / WhatsApp</th>
          <th>Email</th>
          <th>Children</th>
          <th>Login</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($parents): foreach ($parents as $p):
          $pid = (int) $p['id'];
          $clist = $kids[$pid] ?? [];
      ?>
        <tr>
          <td>
            <div class="fw-semibold"><?php echo $esc((string) $p['name']); ?></div>
            <div class="small text-muted">ID <?php echo $pid; ?> · User ID <?php echo $esc(parent_phone_last10((string) $p['phone'])); ?></div>
            <?php if ($pwReady): ?>
              <div class="small"><?php echo !empty($p['password_hash']) ? '<span class="text-success">Password set</span>' : '<span class="text-muted">No password — set in Edit</span>'; ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div><?php echo $esc((string) $p['phone']); ?></div>
            <?php if (!empty($p['whatsapp_id'])): ?>
              <div class="small text-muted"><?php echo $esc((string) $p['whatsapp_id']); ?></div>
            <?php endif; ?>
          </td>
          <td><?php echo $esc((function_exists('parent_display_email') ? parent_display_email($p, $studentEmails) : staff_user_email_from_meta($p['meta'] ?? null)) ?: '—'); ?></td>
          <td>
            <?php if ($clist === []): ?>
              <span class="text-muted">None linked</span>
            <?php else: ?>
              <div class="small">
                <?php foreach ($clist as $ch): ?>
                  <div>
                    <a href="<?php echo $esc($studentView . '?id=' . (int) $ch['id']); ?>"><?php echo $esc(staff_child_label($ch)); ?></a>
                  </div>
                <?php endforeach; ?>
              </div>
              <span class="badge bg-light text-dark"><?php echo count($clist); ?> child<?php echo count($clist) === 1 ? '' : 'ren'; ?></span>
            <?php endif; ?>
          </td>
          <td><?php echo (int) $p['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewParentModal" data-id="<?php echo $pid; ?>">View</button>
            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editParentModal" data-id="<?php echo $pid; ?>">Edit</button>
            <?php if ((int) $p['is_active']): ?>
              <a class="btn btn-sm btn-outline-secondary" href="?action=toggle&amp;id=<?php echo $pid; ?>&amp;to=0">Disable login</a>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-success" href="?action=toggle&amp;id=<?php echo $pid; ?>&amp;to=1">Enable login</a>
            <?php endif; ?>
            <?php echo render_secure_delete_button($pid, 'Delete', 'Delete this parent login? If this parent still has children, use Disable login instead.'); ?>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No parents for this academic year. They appear after New Admission for the selected year.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="p-3 d-flex justify-content-between align-items-center">
    <div>Showing <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($total, $offset + count($parents)); ?> of <?php echo $total; ?></div>
    <ul class="pagination mb-0">
      <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo e(parents_qs(['page' => $p])); ?>"><?php echo $p; ?></a></li>
      <?php endfor; ?>
    </ul>
  </div>
</div>

<div class="modal fade" id="viewParentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Parent details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="viewParentBody"><div class="text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="editParentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editParentForm">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h5 class="modal-title">Edit parent</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Change name or mobile. User ID for parent login is the 10-digit mobile. Set a password here if they forgot it.</p>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label">Name *</label><input name="name" id="edit_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Mobile number *</label><input name="phone" id="edit_phone" class="form-control" required inputmode="numeric" maxlength="15" placeholder="10-digit mobile"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-control" placeholder="optional"></div>
            <div class="col-md-6">
              <label class="form-label">WhatsApp number</label>
              <input name="whatsapp_id" id="edit_whatsapp" class="form-control" inputmode="numeric" maxlength="15" placeholder="10-digit WhatsApp">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <label class="form-check-label mb-2">
                <input class="form-check-input me-2" type="checkbox" id="edit_wa_same" checked>
                WhatsApp is the same as mobile
              </label>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <label class="form-check-label mb-2">
                <input class="form-check-input me-2" type="checkbox" name="is_active" id="edit_active" value="1">
                Login active
              </label>
            </div>
          </div>
          <?php if ($pwReady): ?>
          <div class="border rounded-3 p-3 mt-3">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
              <strong>Set / reset login password</strong>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnGenParentPw">Make a password</button>
            </div>
            <p class="small text-muted mb-2" id="editPwHint">Leave blank to keep the current password. Fill both boxes to reset a forgotten password.</p>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">New password</label>
                <input type="password" name="new_password" id="edit_new_password" class="form-control" minlength="<?php echo (int) $pwMin; ?>" autocomplete="new-password">
              </div>
              <div class="col-md-6">
                <label class="form-label">Confirm password</label>
                <input type="password" name="new_password_confirm" id="edit_new_password_confirm" class="form-control" minlength="<?php echo (int) $pwMin; ?>" autocomplete="new-password">
              </div>
            </div>
            <div class="small mt-2" id="editPwNote"></div>
          </div>
          <?php endif; ?>
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
(function () {
  function last10(v) {
    var d = String(v || '').replace(/\D+/g, '');
    return d.length >= 10 ? d.slice(-10) : d;
  }
  function syncWa() {
    var phone = document.getElementById('edit_phone');
    var wa = document.getElementById('edit_whatsapp');
    var same = document.getElementById('edit_wa_same');
    if (!phone || !wa || !same) return;
    if (same.checked) {
      wa.value = last10(phone.value);
      wa.readOnly = true;
    } else {
      wa.readOnly = false;
    }
  }
  document.getElementById('edit_wa_same')?.addEventListener('change', syncWa);
  document.getElementById('edit_phone')?.addEventListener('input', function () {
    if (document.getElementById('edit_wa_same')?.checked) syncWa();
  });
  document.getElementById('btnGenParentPw')?.addEventListener('click', function () {
    var made = 'Parent' + String(Math.floor(1000 + Math.random() * 9000));
    var pw = document.getElementById('edit_new_password');
    var cf = document.getElementById('edit_new_password_confirm');
    var note = document.getElementById('editPwNote');
    if (pw) { pw.type = 'text'; pw.value = made; }
    if (cf) { cf.type = 'text'; cf.value = made; }
    if (note) {
      note.className = 'small mt-2 text-success';
      note.textContent = 'Password: ' + made + ' — tell this to the parent, then Save.';
    }
  });
  document.getElementById('editParentForm')?.addEventListener('submit', function (ev) {
    syncWa();
    var wa = document.getElementById('edit_whatsapp');
    if (wa) wa.readOnly = false;
    var pw = document.getElementById('edit_new_password');
    var cf = document.getElementById('edit_new_password_confirm');
    if (pw && cf && (pw.value !== '' || cf.value !== '')) {
      if (pw.value !== cf.value) {
        ev.preventDefault();
        alert('New password and confirmation do not match.');
        return;
      }
      if (pw.value.length < 6) {
        ev.preventDefault();
        alert('Password must be at least 6 characters.');
      }
    }
  });
  document.getElementById('viewParentModal')?.addEventListener('show.bs.modal', function (event) {
    var id = event.relatedTarget.getAttribute('data-id');
    var body = document.getElementById('viewParentBody');
    body.innerHTML = '<div class="text-muted">Loading…</div>';
    fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function () { body.innerHTML = '<div class="text-danger">Failed to load.</div>'; });
  });
  document.getElementById('editParentModal')?.addEventListener('show.bs.modal', function (event) {
    var id = event.relatedTarget && event.relatedTarget.getAttribute('data-id');
    ['edit_id','edit_name','edit_phone','edit_whatsapp','edit_email','edit_new_password','edit_new_password_confirm'].forEach(function (idn) {
      var el = document.getElementById(idn);
      if (el) el.value = '';
    });
    var note = document.getElementById('editPwNote');
    if (note) note.textContent = '';
    var hint = document.getElementById('editPwHint');
    fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (json) {
        if (!(json && json.ok && json.data)) {
          alert((json && json.error) || 'Failed to load parent.');
          return;
        }
        var d = json.data;
        document.getElementById('edit_id').value = d.id || '';
        document.getElementById('edit_name').value = d.name || '';
        document.getElementById('edit_phone').value = last10(d.phone || '');
        document.getElementById('edit_whatsapp').value = last10(d.whatsapp_id || '');
        document.getElementById('edit_email').value = d.email || '';
        document.getElementById('edit_active').checked = parseInt(d.is_active, 10) === 1;
        document.getElementById('edit_wa_same').checked = last10(d.phone || '') === last10(d.whatsapp_id || '') || !d.whatsapp_id;
        syncWa();
        if (hint) {
          hint.textContent = d.has_password
            ? 'Password is already set. Leave blank to keep it, or type a new one to reset. User ID: ' + last10(d.phone || '')
            : 'No password yet. Type one below so they can login with User ID ' + last10(d.phone || '') + '.';
        }
      })
      .catch(function () { alert('Failed to load parent.'); });
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
