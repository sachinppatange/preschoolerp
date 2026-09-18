<?php
/**
 * Shared enquiry list for owner and reception.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Enquiries');
$authRoles = $cfg['auth_roles'] ?? $panel;
if (!is_array($authRoles)) {
    $authRoles = [$authRoles];
}
$sourceDefault = (string) ($cfg['source_default_add'] ?? 'owner_added');

require_once __DIR__ . '/../enquiry.php';
enquiry_ensure_schema();

if (!function_exists('table_exists') || !table_exists('enquiries')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The enquiries table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$esc = static fn(string $v): string => e($v);
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$messages = [];
$errors = [];
$statuses = ['new', 'contacted', 'converted', 'closed'];
$ageOptions = ['1-2' => '1–2 years', '2-3' => '2–3 years', '3-4' => '3–4 years', '4-5' => '4–5 years', '5-6' => '5–6 years'];
$hasAge = enquiry_has_age_column();
$self = function_exists('site_url') ? site_url('/' . $panel . '/enquiry_list.php') : '';
$waUrl = function_exists('site_url') ? site_url('/owner/whatsapp.php') : '/owner/whatsapp.php';

$csrfOk = static function () use ($csrf): bool {
    $tok = (string) ($_POST['csrf'] ?? '');
    return function_exists('validate_csrf_token') && validate_csrf_token($tok);
};

$action = (string) ($_REQUEST['action'] ?? 'list');

if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    $row = $id > 0 ? safe_db_get_one(
        "SELECT e.*, COALESCE(u.name,'') AS assignee_name
         FROM enquiries e LEFT JOIN users u ON u.id = e.assigned_to
         WHERE e.id = :id LIMIT 1",
        [':id' => $id]
    ) : null;
    if (!$row) {
        echo '<div class="text-muted">Enquiry not found.</div>';
        exit;
    }
    $age = enquiry_age_from_row($row);
    echo '<dl class="row mb-0">';
    echo '<dt class="col-sm-4">Parent / Guardian</dt><dd class="col-sm-8">' . $esc((string) $row['name']) . '</dd>';
    echo '<dt class="col-sm-4">Mobile</dt><dd class="col-sm-8">' . $esc(enquiry_phone((string) $row['phone'])) . '</dd>';
    echo '<dt class="col-sm-4">Child age</dt><dd class="col-sm-8">' . ($age !== '' ? $esc($age) : '—') . '</dd>';
    echo '<dt class="col-sm-4">Source</dt><dd class="col-sm-8">' . $esc(enquiry_source_label((string) ($row['source'] ?? ''))) . '</dd>';
    echo '<dt class="col-sm-4">Status</dt><dd class="col-sm-8">' . $esc(enquiry_status_label((string) ($row['status'] ?? ''))) . '</dd>';
    echo '<dt class="col-sm-4">Assigned</dt><dd class="col-sm-8">' . $esc((string) ($row['assignee_name'] ?: 'Unassigned')) . '</dd>';
    echo '<dt class="col-sm-4">Created</dt><dd class="col-sm-8">' . $esc((string) ($row['created_at'] ?? '')) . '</dd>';
    echo '<dt class="col-12">Message</dt><dd class="col-12"><pre class="mb-0" style="white-space:pre-wrap">' . $esc((string) ($row['message'] ?? '')) . '</pre></dd>';
    echo '</dl>';
    exit;
}

if ($action === 'get' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) $_GET['id'];
    $row = $id > 0 ? safe_db_get_one('SELECT * FROM enquiries WHERE id = :id LIMIT 1', [':id' => $id]) : null;
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    if (!$hasAge) {
        $row['age_group'] = enquiry_age_from_row($row);
        $row['message'] = preg_replace('/^Age group:\s*[^\r\n]+\r?\n?/i', '', (string) ($row['message'] ?? ''));
    }
    $row['phone'] = enquiry_phone((string) ($row['phone'] ?? ''));
    echo json_encode(['ok' => true, 'data' => $row]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk()) {
        $errors[] = 'Invalid security token. Please reload the page.';
    } elseif ($action === 'add') {
        $res = enquiry_create([
            'name' => (string) ($_POST['name'] ?? ''),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'source' => trim((string) ($_POST['source'] ?? $sourceDefault)) ?: $sourceDefault,
            'message' => (string) ($_POST['message'] ?? ''),
            'age_group' => (string) ($_POST['age_group'] ?? ''),
            'assigned_to' => $_POST['assigned_to'] ?? null,
            'school_id' => enquiry_school_id(),
        ]);
        if (!empty($res['ok'])) {
            header('Location: ?added=1');
            exit;
        }
        $errors[] = $res['error'] ?? 'Could not save enquiry.';
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = enquiry_phone((string) ($_POST['phone'] ?? ''));
        $source = trim((string) ($_POST['source'] ?? $sourceDefault));
        $message = trim((string) ($_POST['message'] ?? ''));
        $age = trim((string) ($_POST['age_group'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'new');
        if (!in_array($status, $statuses, true)) {
            $status = 'new';
        }
        $assigned = ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null;
        if ($id <= 0) {
            $errors[] = 'Invalid enquiry.';
        } elseif ($name === '' || strlen($phone) < 10) {
            $errors[] = 'Parent / Guardian name and 10-digit mobile are required.';
        } else {
            if ($age !== '' && !$hasAge) {
                $message = "Age group: {$age}\n" . $message;
            }
            $ok = $hasAge
                ? safe_db_run(
                    'UPDATE enquiries SET name=:n, phone=:p, source=:s, message=:m, age_group=:a, assigned_to=:u, status=:st, updated_at=NOW() WHERE id=:id',
                    [':n' => $name, ':p' => $phone, ':s' => $source, ':m' => $message, ':a' => $age !== '' ? $age : null, ':u' => $assigned, ':st' => $status, ':id' => $id]
                )
                : safe_db_run(
                    'UPDATE enquiries SET name=:n, phone=:p, source=:s, message=:m, assigned_to=:u, status=:st, updated_at=NOW() WHERE id=:id',
                    [':n' => $name, ':p' => $phone, ':s' => $source, ':m' => $message, ':u' => $assigned, ':st' => $status, ':id' => $id]
                );
            if ($ok) {
                header('Location: ?saved=1');
                exit;
            }
            $errors[] = 'Could not update enquiry.';
        }
    } elseif ($action === 'status') {
        $id = (int) ($_POST['id'] ?? 0);
        $to = (string) ($_POST['to'] ?? '');
        if ($id > 0 && in_array($to, $statuses, true) && safe_db_run('UPDATE enquiries SET status=:s, updated_at=NOW() WHERE id=:id', [':s' => $to, ':id' => $id])) {
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not update status.';
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if (safe_db_run('DELETE FROM enquiries WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    }
    $errors[] = 'Delete failed.';
}

if (!empty($_GET['added'])) {
    $messages[] = 'Enquiry added.';
}
if (!empty($_GET['saved'])) {
    $messages[] = 'Enquiry saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Enquiry deleted.';
}

if ($action === 'export') {
    $where = [];
    $params = [];
    $qraw = trim((string) ($_GET['q'] ?? ''));
    if ($qraw !== '') {
        $where[] = '(name LIKE :q OR phone LIKE :q OR message LIKE :q OR source LIKE :q)';
        $params[':q'] = '%' . $qraw . '%';
    }
    $st = (string) ($_GET['status'] ?? '');
    if (in_array($st, $statuses, true)) {
        $where[] = 'status = :status';
        $params[':status'] = $st;
    }
    if (function_exists('ay_apply_date_filter')) {
        ay_apply_date_filter($where, $params, 'created_at');
    }
    $sql = 'SELECT * FROM enquiries' . ($where ? (' WHERE ' . implode(' AND ', $where)) : '') . ' ORDER BY created_at DESC';
    $rows = safe_db_get_all($sql, $params) ?: [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=enquiries_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Parent / Guardian', 'Mobile', 'Age', 'Source', 'Status', 'Created', 'Message']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['name'] ?? '',
            enquiry_phone((string) ($r['phone'] ?? '')),
            enquiry_age_from_row($r),
            enquiry_source_label((string) ($r['source'] ?? '')),
            enquiry_status_label((string) ($r['status'] ?? '')),
            $r['created_at'] ?? '',
            preg_replace("/\r\n|\r|\n/", ' ', (string) ($r['message'] ?? '')),
        ]);
    }
    fclose($out);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$where = [];
$params = [];
$qraw = trim((string) ($_GET['q'] ?? ''));
if ($qraw !== '') {
    $where[] = '(e.name LIKE :q OR e.phone LIKE :q OR e.message LIKE :q OR e.source LIKE :q)';
    $params[':q'] = '%' . $qraw . '%';
}
$statusFilter = (string) ($_GET['status'] ?? '');
if (in_array($statusFilter, $statuses, true)) {
    $where[] = 'e.status = :status';
    $params[':status'] = $statusFilter;
}
$srcFilter = trim((string) ($_GET['source'] ?? ''));
if ($srcFilter !== '') {
    $where[] = 'e.source = :src';
    $params[':src'] = $srcFilter;
}
if (function_exists('ay_apply_date_filter')) {
    ay_apply_date_filter($where, $params, 'e.created_at');
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$total = (int) (safe_db_get_one('SELECT COUNT(*) AS c FROM enquiries e ' . $whereSql, $params)['c'] ?? 0);
$enquiries = [];
try {
    $pdo = pdo_connect();
    $sql = "SELECT e.*, COALESCE(u.name,'') AS assignee_name
            FROM enquiries e
            LEFT JOIN users u ON u.id = e.assigned_to
            {$whereSql}
            ORDER BY e.created_at DESC
            LIMIT :limit OFFSET :offset";
    if ($pdo instanceof PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $enquiries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $errors[] = 'Could not load enquiries.';
}

$staffList = [];
if (table_exists('users')) {
    try {
        $staffList = safe_db_get_all("SELECT id, name FROM users WHERE role <> 'parent' ORDER BY name ASC") ?: [];
    } catch (Throwable $e) {
        $staffList = safe_db_get_all('SELECT id, name FROM users ORDER BY name ASC') ?: [];
    }
}

$ayParams = function_exists('ay_range') ? [
    ':s' => ay_range()['start'],
    ':e' => ay_range()['end'] . ' 23:59:59',
] : [];
$ayW = $ayParams ? ' AND created_at BETWEEN :s AND :e' : '';
$stats = [
    'total' => (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE 1=1{$ayW}", $ayParams)['c'] ?? 0),
    'new' => (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE status='new'{$ayW}", $ayParams)['c'] ?? 0),
    'whatsapp' => (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE source='whatsapp'{$ayW}", $ayParams)['c'] ?? 0),
    'today' => (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM enquiries WHERE DATE(created_at)=CURDATE(){$ayW}", $ayParams)['c'] ?? 0),
];
$totalPages = (int) ceil(max(1, $total) / $perPage);
$qs = static function (array $over = []): string {
    $g = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null) {
            unset($g[$k]);
        } else {
            $g[$k] = $v;
        }
    }
    return http_build_query($g);
};

require_once __DIR__ . '/../header.php';
?>
<style>
.enq-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:1rem 1.15rem; margin-bottom:1rem; }
.enq-stat { background:#fff; border:1px solid #e8eef8; border-radius:14px; padding:.85rem; text-align:center; }
.enq-stat .n { font-size:1.35rem; font-weight:800; color:#0d3b8c; }
.enq-stat .l { color:#64748b; font-size:.8rem; }
.src-wa { background:#dcfce7; color:#166534; font-weight:700; font-size:.72rem; padding:.15rem .45rem; border-radius:999px; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="text-muted small">WhatsApp “I am interested” is added here automatically with the parent name and mobile.</div>
  <div class="d-flex gap-2">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addEnquiryModal">Add Enquiry</button>
    <a class="btn btn-outline-secondary" href="?<?php echo $esc($qs(['action' => 'export'])); ?>">Export CSV</a>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?php echo $esc($err); ?></div><?php endforeach; ?>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="enq-stat"><div class="n"><?php echo (int) $stats['total']; ?></div><div class="l">This year</div></div></div>
  <div class="col-6 col-md-3"><div class="enq-stat"><div class="n"><?php echo (int) $stats['new']; ?></div><div class="l">New</div></div></div>
  <div class="col-6 col-md-3"><div class="enq-stat"><div class="n"><?php echo (int) $stats['whatsapp']; ?></div><div class="l">WhatsApp</div></div></div>
  <div class="col-6 col-md-3"><div class="enq-stat"><div class="n"><?php echo (int) $stats['today']; ?></div><div class="l">Today</div></div></div>
</div>

<div class="enq-card">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Name or mobile"></div>
    <div class="col-md-2">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="">All</option>
        <?php foreach ($statuses as $opt): ?>
          <option value="<?php echo $esc($opt); ?>" <?php echo $statusFilter === $opt ? 'selected' : ''; ?>><?php echo $esc(enquiry_status_label($opt)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Source</label>
      <select name="source" class="form-select">
        <option value="">All</option>
        <?php foreach (['whatsapp' => 'WhatsApp', 'website_enquiry' => 'Website', 'owner_added' => 'Owner', 'reception_added' => 'Reception'] as $k => $lab): ?>
          <option value="<?php echo $esc($k); ?>" <?php echo $srcFilter === $k ? 'selected' : ''; ?>><?php echo $esc($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 text-md-end">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-outline-secondary" href="?">Reset</a>
    </div>
  </form>
</div>

<div class="enq-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Parent / Guardian</th>
          <th>Mobile</th>
          <th>Source</th>
          <th>Status</th>
          <th>When</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($enquiries === []): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No enquiries yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($enquiries as $r):
            $phone = enquiry_phone((string) ($r['phone'] ?? ''));
            $src = (string) ($r['source'] ?? '');
            $st = (string) ($r['status'] ?? 'new');
            $age = enquiry_age_from_row($r);
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo $esc((string) $r['name']); ?></div>
              <?php if ($age !== ''): ?><div class="small text-muted">Child age: <?php echo $esc($age); ?></div><?php endif; ?>
              <?php if (trim((string) ($r['message'] ?? '')) !== ''): ?>
                <div class="small text-muted"><?php echo $esc(mb_strimwidth(preg_replace('/^Age group:\s*[^\r\n]+\r?\n?/i', '', (string) $r['message']), 0, 80, '…')); ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><?php echo $esc($phone); ?></div>
              <?php if ($src === 'whatsapp' && $phone !== ''): ?>
                <a class="small" href="<?php echo $esc($waUrl . '?phone=91' . $phone); ?>">Open WhatsApp</a>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($src === 'whatsapp'): ?>
                <span class="src-wa">WhatsApp</span>
              <?php else: ?>
                <?php echo $esc(enquiry_source_label($src)); ?>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="d-flex gap-1">
                <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                <select name="to" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach ($statuses as $opt): ?>
                    <option value="<?php echo $esc($opt); ?>" <?php echo $st === $opt ? 'selected' : ''; ?>><?php echo $esc(enquiry_status_label($opt)); ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="small text-muted"><?php echo $esc(substr((string) ($r['created_at'] ?? ''), 0, 16)); ?></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int) $r['id']; ?>">View</button>
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editEnquiryModal" data-id="<?php echo (int) $r['id']; ?>">Edit</button>
              <?php echo function_exists('render_secure_delete_button') ? render_secure_delete_button((int) $r['id'], 'Delete', 'Delete this enquiry?') : ''; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="p-3 d-flex justify-content-between align-items-center">
    <div class="small text-muted"><?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($total, $offset + count($enquiries)); ?> of <?php echo $total; ?></div>
    <ul class="pagination pagination-sm mb-0">
      <?php for ($p = 1; $p <= min(20, $totalPages); $p++): ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo $esc($qs(['page' => $p])); ?>"><?php echo $p; ?></a></li>
      <?php endfor; ?>
    </ul>
  </div>
</div>

<div class="modal fade" id="addEnquiryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
      <input type="hidden" name="action" value="add">
      <div class="modal-header"><h5 class="modal-title">Add Enquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label">Parent / Guardian name *</label><input name="name" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Mobile *</label><input name="phone" class="form-control" maxlength="15" required placeholder="10-digit number"></div>
        <div class="col-md-6">
          <label class="form-label">Child age</label>
          <select name="age_group" class="form-select">
            <option value="">Optional</option>
            <?php foreach ($ageOptions as $k => $lab): ?><option value="<?php echo $esc($k); ?>"><?php echo $esc($lab); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Assign to</label>
          <select name="assigned_to" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach ($staffList as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <input type="hidden" name="source" value="<?php echo $esc($sourceDefault); ?>">
        <div class="col-12"><label class="form-label">Message</label><textarea name="message" class="form-control" rows="3"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="editEnquiryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" id="editEnquiryForm">
      <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header"><h5 class="modal-title">Edit Enquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label">Parent / Guardian name *</label><input name="name" id="edit_name" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Mobile *</label><input name="phone" id="edit_phone" class="form-control" required></div>
        <div class="col-md-6">
          <label class="form-label">Child age</label>
          <select name="age_group" id="edit_age_group" class="form-select">
            <option value="">Optional</option>
            <?php foreach ($ageOptions as $k => $lab): ?><option value="<?php echo $esc($k); ?>"><?php echo $esc($lab); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" id="edit_status" class="form-select">
            <?php foreach ($statuses as $opt): ?><option value="<?php echo $esc($opt); ?>"><?php echo $esc(enquiry_status_label($opt)); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Assign to</label>
          <select name="assigned_to" id="edit_assigned_to" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach ($staffList as $s): ?><option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Source</label><input name="source" id="edit_source" class="form-control"></div>
        <div class="col-12"><label class="form-label">Message</label><textarea name="message" id="edit_message" class="form-control" rows="3"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Enquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody">Loading…</div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (ev) {
      var id = ev.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.textContent = 'Loading…';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () { body.textContent = 'Could not load.'; });
    });
  }
  var editModal = document.getElementById('editEnquiryModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (ev) {
      var id = ev.relatedTarget.getAttribute('data-id');
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json || !json.ok) { alert('Could not load enquiry.'); return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          document.getElementById('edit_name').value = d.name || '';
          document.getElementById('edit_phone').value = d.phone || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_source').value = d.source || '';
          document.getElementById('edit_status').value = d.status || 'new';
          document.getElementById('edit_assigned_to').value = d.assigned_to || '';
          document.getElementById('edit_age_group').value = d.age_group || '';
        });
    });
  }
});
</script>
<?php require_once __DIR__ . '/../footer.php'; ?>
