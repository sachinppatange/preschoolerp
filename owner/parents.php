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
$action = (string) ($_REQUEST['action'] ?? 'list');
$messages = [];
$errors = [];

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
$sql = "SELECT u.id, u.name, u.phone, u.whatsapp_id, u.is_active, u.meta, u.created_at
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
    — only parents who have a child in this year (same filter as Students).
    Portal login is auto-disabled when there is no child in <?php echo e($loginAy); ?>.
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
            <div class="small text-muted">ID <?php echo $pid; ?></div>
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
            <?php if ((int) $p['is_active']): ?>
              <a class="btn btn-sm btn-outline-secondary" href="?action=toggle&amp;id=<?php echo $pid; ?>&amp;to=0">Disable login</a>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-success" href="?action=toggle&amp;id=<?php echo $pid; ?>&amp;to=1">Enable login</a>
            <?php endif; ?>
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
<script>
document.getElementById('viewParentModal')?.addEventListener('show.bs.modal', function (event) {
  var id = event.relatedTarget.getAttribute('data-id');
  var body = document.getElementById('viewParentBody');
  body.innerHTML = '<div class="text-muted">Loading…</div>';
  fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
    .then(function (html) { body.innerHTML = html; })
    .catch(function () { body.innerHTML = '<div class="text-danger">Failed to load.</div>'; });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
