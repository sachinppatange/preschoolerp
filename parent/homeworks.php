<?php
/**
 * parent/homeworks.php
 *
 * Parent-facing Homework list and viewer.
 *
 * Features:
 * - Requires parent login ($_SESSION['parent_auth_user'])
 * - Finds classes for parent's linked children (parents_children.child_student_id -> students.class_id)
 * - Lists homeworks from homeworks table for those classes
 * - Filtering by class and date range
 * - Pagination
 * - View homework details in modal (AJAX fragment)
 * - Export visible homework list to CSV
 *
 * Expected homeworks table schema (you provided):
 * id, school_id, class_id, title, description, assigned_date, due_date, created_by, created_at, updated_at
 *
 * Save to: /pioneerplayschool01/parent/homeworks.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- Require parent login ---------- */
/* ---------- Table existence helper ---------- */

/* ---------- Page state ---------- */
$messages = [];
$errors = [];

/* ---------- Parent identity ---------- */
$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

/* ---------- Determine classes linked to parent via parents_children -> students.class_id ---------- */
$classIds = []; // unique list of class ids parent is interested in
$childIds = [];

if (table_exists('parents_children')) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid", [':pid'=>$parentId]);
    foreach ($maps as $m) {
        $childIds[] = (int)($m['child_student_id'] ?? 0);
    }
}

if (!empty($childIds) && table_exists('students')) {
    // fetch class_id for these student ids
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE id IN ($ph) AND class_id IS NOT NULL", $childIds);
    foreach ($rows as $r) {
        $cid = $r['class_id'] ?? null;
        if ($cid !== null) $classIds[] = (int)$cid;
    }
}

/* fallback: if no parents_children mapping, try students.parent_id */
if (empty($classIds) && table_exists('students')) {
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE parent_id = :pid AND class_id IS NOT NULL", [':pid'=>$parentId]);
    foreach ($rows as $r) {
        $cid = $r['class_id'] ?? null;
        if ($cid !== null) $classIds[] = (int)$cid;
    }
}

$classOptions = []; // map class_id => display name
if (!empty($classIds) && table_exists('classes')) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $rows = safe_db_get_all("SELECT id, name, short_name, section FROM classes WHERE id IN ($ph)", $classIds);
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $display = trim((($r['short_name'] ?? '') . ' ' . ($r['name'] ?? '')));
        if (!empty($r['section'])) $display .= ' • Sec: ' . $r['section'];
        $classOptions[$id] = $display;
    }
    // ensure any classIds not returned still appear as numeric labels
    foreach ($classIds as $cid) {
        if (!isset($classOptions[$cid])) $classOptions[$cid] = 'Class #' . $cid;
    }
}

/* ---------- Actions: view (modal) and export ---------- */
$action = $_REQUEST['action'] ?? 'list';

/* VIEW modal fragment - show full homework details */
if ($action === 'view' && !empty($_GET['id'])) {
    $hwId = (int)$_GET['id'];
    if ($hwId <= 0) { echo '<div class="p-3 text-danger">Invalid homework id.</div>'; exit; }
    if (!table_exists('homeworks')) { echo '<div class="p-3 text-muted">homeworks table not found.</div>'; exit; }

    // fetch the homework row
    $hw = safe_db_get_one("SELECT id, school_id, class_id, title, description, assigned_date, due_date, created_by, created_at, updated_at FROM homeworks WHERE id = :id LIMIT 1", [':id'=>$hwId]);
    if (!$hw) { echo '<div class="p-3 text-muted">Homework not found.</div>'; exit; }

    // authorize: ensure homework.class_id is in parent's classIds
    $hwClass = isset($hw['class_id']) ? (int)$hw['class_id'] : 0;
    if (!empty($classIds) && $hwClass !== 0 && !in_array($hwClass, $classIds, true)) {
        echo '<div class="p-3 text-muted">You are not authorized to view this homework.</div>'; exit;
    }

    // optionally fetch class name
    $className = '';
    if (table_exists('classes') && !empty($hwClass)) {
        $c = safe_db_get_one("SELECT name, short_name, section FROM classes WHERE id = :id LIMIT 1", [':id'=>$hwClass]);
        if ($c) $className = trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? ''))) . (!empty($c['section']) ? ' • Sec: ' . $c['section'] : '');
    }

    echo '<div class="p-3">';
    echo '<h5>' . e($hw['title'] ?? '—') . '</h5>';
    echo '<div class="small-muted mb-2">Class: ' . e($className ?: ($hwClass ?: '—')) . ' • Assigned: ' . e(substr((string)($hw['assigned_date'] ?? ''),0,10) ?: '—') . ' • Due: ' . e(substr((string)($hw['due_date'] ?? ''),0,10) ?: '—') . '</div>';
    echo '<div>' . nl2br(e((string)$hw['description'] ?? '')) . '</div>';
    echo '<hr>';
    echo '<div class="small-muted">Created by: ' . e($hw['created_by'] ?? '—') . ' • Created at: ' . e($hw['created_at'] ?? '—') . '</div>';
    echo '</div>';
    exit;
}

/* EXPORT CSV for current filters */
if ($action === 'export') {
    if (!table_exists('homeworks')) { http_response_code(404); echo 'homeworks table not found'; exit; }
    // determine filters from GET
    $filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $from = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : '';
    $to = isset($_GET['to']) ? substr((string)$_GET['to'],0,10) : '';

    // Build WHERE based on available classIds (security) and requested filters
    if (empty($classIds)) {
        // parent has no classes -> export empty
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=homeworks_empty.csv');
        echo "No homeworks\n";
        exit;
    }

    $whereClauses = [];
    $params = [];

    // restrict to parent's classes
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $whereClauses[] = "class_id IN ($ph)";
    foreach ($classIds as $v) $params[] = $v;

    if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
        $whereClauses[] = "class_id = ?";
        $params[] = $filterClass;
    }

    if ($from !== '') { $whereClauses[] = "DATE(assigned_date) >= ?"; $params[] = $from; }
    if ($to !== '') { $whereClauses[] = "DATE(assigned_date) <= ?"; $params[] = $to; }

    $whereSql = count($whereClauses) ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, class_id, title, description, assigned_date, due_date, created_by, created_at, updated_at FROM homeworks $whereSql ORDER BY assigned_date DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=homeworks_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','school_id','class_id','title','description','assigned_date','due_date','created_by','created_at','updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['class_id'] ?? '',
            $r['title'] ?? '',
            $r['description'] ?? '',
            $r['assigned_date'] ?? '',
            $r['due_date'] ?? '',
            $r['created_by'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- List homeworks with pagination and filters ---------- */
$perPage = 20;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $perPage;

$filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$from = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : '';
$to = isset($_GET['to']) ? substr((string)$_GET['to'],0,10) : '';

// If parent has no classes, results will be empty
$homeworks = [];
$total = 0;
if (table_exists('homeworks') && !empty($classIds)) {
    $whereClauses = [];
    $params = [];

    // restrict to parent's classes
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $whereClauses[] = "class_id IN ($ph)";
    foreach ($classIds as $v) $params[] = $v;

    if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
        $whereClauses[] = "class_id = ?";
        $params[] = $filterClass;
    }

    if ($from !== '') { $whereClauses[] = "DATE(assigned_date) >= ?"; $params[] = $from; }
    if ($to !== '') { $whereClauses[] = "DATE(assigned_date) <= ?"; $params[] = $to; }

    $whereSql = count($whereClauses) ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

    // total count
    $countRow = safe_db_get_one("SELECT COUNT(*) AS cnt FROM homeworks $whereSql", $params);
    $total = intval($countRow['cnt'] ?? 0);

    if ($total > 0) {
        // append limit params
        $paramsWithLimit = $params;
        $paramsWithLimit[] = $perPage;
        $paramsWithLimit[] = $offset;
        // Use positional LIMIT ? OFFSET ? - safe_db_get_all will pass array_values
        $sql = "SELECT id, school_id, class_id, title, description, assigned_date, due_date, created_by, created_at, updated_at
                FROM homeworks $whereSql
                ORDER BY assigned_date DESC
                LIMIT ? OFFSET ?";
        $homeworks = safe_db_get_all($sql, $paramsWithLimit);
    }
}

/* ---------- Render page ---------- */
$pageTitle = 'Homeworks';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="../parent/profile.php">Profile</a>
    <?php if (!empty($classIds)): ?>
      <a class="btn btn-sm btn-success" href="?action=export&class_id=<?php echo e($filterClass); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3 p-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select">
        <option value="0">All classes</option>
        <?php foreach ($classOptions as $cid => $label): ?>
          <option value="<?php echo (int)$cid; ?>" <?php if ($cid === $filterClass) echo 'selected'; ?>><?php echo e($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Assigned from</label>
      <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Assigned to</label>
      <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
    </div>
    <div class="col-md-3 text-end">
      <button class="btn btn-primary">Filter</button>
    </div>
  </form>
</div>

<div class="card mb-3">
  <div class="card-body">
    <?php if (empty($classIds)): ?>
      <div class="small-muted">No classes linked to your account. Contact the school if this seems incorrect.</div>
    <?php elseif (empty($homeworks)): ?>
      <div class="small-muted">No homeworks found for the selected filters.</div>
    <?php else: ?>
      <div class="list-group">
        <?php foreach ($homeworks as $hw): $hid = (int)$hw['id']; ?>
          <div class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <div class="hw-title"><?php echo e($hw['title'] ?? '—'); ?></div>
              <div class="small-muted"><?php echo e(substr((string)($hw['description'] ?? ''), 0, 180)); ?><?php if (strlen((string)($hw['description'] ?? '')) > 180) echo '...'; ?></div>
              <div class="small-muted mt-1">Assigned: <?php echo e(substr((string)($hw['assigned_date'] ?? ''),0,10) ?: '—'); ?> • Due: <?php echo e(substr((string)($hw['due_date'] ?? ''),0,10) ?: '—'); ?></div>
            </div>
            <div class="text-end">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#hwViewModal" data-id="<?php echo $hid; ?>">View</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
      ?>
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small-muted">Showing <?php echo min($offset+1, $total); ?> - <?php echo min($offset + count($homeworks), $total); ?> of <?php echo $total; ?> homeworks</div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php if ($page <= 1) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['p'=>max(1,$page-1)])); ?>">Prev</a></li>
            <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo max(1,$totalPages); ?></span></li>
            <li class="page-item <?php if ($page >= $totalPages) echo 'disabled'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['p'=>min($totalPages,$page+1)])); ?>">Next</a></li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Homework view modal -->
<div class="modal fade" id="hwViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Homework</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="hwViewBody"><div class="text-center small-muted py-3">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var hwModal = document.getElementById('hwViewModal');
  if (hwModal) {
    hwModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('hwViewBody');
      body.innerHTML = '<div class="text-center small-muted py-3">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ if (!resp.ok) throw new Error('Network'); return resp.text(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load homework details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>