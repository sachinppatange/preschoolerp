<?php
/**
 * parent/notices.php
 *
 * Parent-facing Notices board.
 *
 * Features
 * - Requires parent login ($_SESSION['parent_auth_user'])
 * - Determines classes linked to parent (parents_children -> students.class_id) and shows:
 *     - Global notices (class_id IS NULL)
 *     - Class-specific notices for parent's classes
 * - Filtering by class (including "All / Global + my classes"), date range, pagination
 * - View notice details in a modal (AJAX fragment via ?action=view&id=...)
 * - Export visible notices to CSV
 * - Defensive: checks for table existence, works with different DB setups
 *
 * Expected (optional) notices table schema (common):
 * - id (PK)
 * - school_id
 * - class_id         (nullable)  -- when NULL => global notice
 * - title
 * - body / description
 * - published_at     (datetime / date)
 * - expires_at       (datetime / date) optional
 * - created_by
 * - attachment_path  (optional)
 * - created_at
 * - updated_at
 *
 * Place this file at: /pioneerplayschool01/parent/notices.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ------------------------
   Require parent login
   ------------------------ */
/* ------------------------
   Helper functions & DB fallbacks
   ------------------------ */

/* ------------------------
   Identify user and tables
   ------------------------ */
$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

$hasParentsChildren = table_exists('parents_children');
$hasStudents = table_exists('students');
$hasNotices = table_exists('notices'); // primary data source
$hasClasses = table_exists('classes'); // optional metadata

/* ------------------------
   Determine class IDs for the parent
   ------------------------ */
$classIds = [];   // classes parent has children in
$childIds = [];

if ($hasParentsChildren) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid", [':pid' => $parentId]);
    foreach ($maps as $m) {
        $cid = (int)($m['child_student_id'] ?? 0);
        if ($cid) $childIds[] = $cid;
    }
}

if (!empty($childIds) && $hasStudents) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE id IN ($ph) AND class_id IS NOT NULL", $childIds);
    foreach ($rows as $r) {
        if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
    }
}

/* fallback: look for students.parent_id if no parents_children */
if (empty($classIds) && $hasStudents) {
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE parent_id = :pid AND class_id IS NOT NULL", [':pid' => $parentId]);
    foreach ($rows as $r) {
        if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
    }
}

/* Build class options for filter */
$classOptions = [];
if (!empty($classIds) && $hasClasses) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $crows = safe_db_get_all("SELECT id, name, short_name, section FROM classes WHERE id IN ($ph)", $classIds);
    foreach ($crows as $cr) {
        $id = (int)$cr['id'];
        $label = trim((($cr['short_name'] ?? '') . ' ' . ($cr['name'] ?? '')));
        if (!empty($cr['section'])) $label .= ' • Sec: ' . $cr['section'];
        $classOptions[$id] = $label;
    }
    foreach ($classIds as $cid) {
        if (!isset($classOptions[$cid])) $classOptions[$cid] = 'Class #' . $cid;
    }
}

/* ------------------------
   Handle actions: view (modal) and export
   ------------------------ */
$action = $_REQUEST['action'] ?? 'list';

if ($action === 'view' && !empty($_GET['id'])) {
    $nid = (int)$_GET['id'];
    if ($nid <= 0) { echo '<div class="p-3 text-danger">Invalid notice id.</div>'; exit; }
    if (!$hasNotices) { echo '<div class="p-3 text-muted">Notices table not present.</div>'; exit; }

    $notice = safe_db_get_one("SELECT id, school_id, class_id, title, body, description, published_at, expires_at, attachment_path, created_by, created_at, updated_at FROM notices WHERE id = :id LIMIT 1", [':id' => $nid]);
    if (!$notice) { echo '<div class="p-3 text-muted">Notice not found.</div>'; exit; }

    // authorization: show if global (class_id IS NULL) or if class_id in parent's classIds
    $nClass = isset($notice['class_id']) ? (int)$notice['class_id'] : 0;
    if ($nClass !== 0 && !in_array($nClass, $classIds, true)) {
        echo '<div class="p-3 text-muted">You are not authorized to view this notice.</div>'; exit;
    }

    // optional class name
    $classLabel = '';
    if ($nClass && $hasClasses) {
        $c = safe_db_get_one("SELECT name, short_name, section FROM classes WHERE id = :id LIMIT 1", [':id' => $nClass]);
        if ($c) $classLabel = trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? ''))) . (!empty($c['section']) ? ' • Sec: ' . $c['section'] : '');
    }

    echo '<div class="p-3">';
    echo '<h5>' . e($notice['title'] ?? '') . '</h5>';
    if ($nClass) echo '<div class="small-muted mb-2">Class: ' . e($classLabel ?: $nClass) . '</div>';
    echo '<div class="small-muted mb-2">Published: ' . e(substr((string)($notice['published_at'] ?? ''), 0, 10) ?: '—') . ' • Expires: ' . e(substr((string)($notice['expires_at'] ?? ''), 0, 10) ?: '—') . '</div>';
    echo '<div>' . nl2br(e((string)($notice['body'] ?? $notice['description'] ?? ''))) . '</div>';
    if (!empty($notice['attachment_path'])) {
        $att = e($notice['attachment_path']);
        echo '<div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="' . $att . '" target="_blank">Download attachment</a></div>';
    }
    echo '<hr>';
    echo '<div class="small-muted">Posted by: ' . e($notice['created_by'] ?? '—') . ' • Created: ' . e($notice['created_at'] ?? '—') . '</div>';
    echo '</div>';
    exit;
}

/* Export CSV for current filters */
if ($action === 'export') {
    if (!$hasNotices) { http_response_code(404); echo "No notices table."; exit; }

    // determine filters
    $filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0; // 0 = global + my classes
    $from = isset($_GET['from']) ? substr((string)$_GET['from'], 0, 10) : '';
    $to = isset($_GET['to']) ? substr((string)$_GET['to'], 0, 10) : '';

    // Build where logic: show global notices (class_id IS NULL) and class-specific notices for parent's classes
    $where = [];
    $params = [];

    if (!empty($classIds)) {
        // if filterClass >0 and valid, restrict further to that class (or 0 for global+myclasses)
        if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
            $where[] = "class_id = ?";
            $params[] = $filterClass;
        } else {
            // show class_id IS NULL OR class_id IN (my classes)
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $where[] = "(class_id IS NULL OR class_id IN ($ph))";
            foreach ($classIds as $v) $params[] = $v;
        }
    } else {
        // parent has no classes: only global notices
        $where[] = "class_id IS NULL";
    }

    if ($from !== '') { $where[] = "DATE(published_at) >= ?"; $params[] = $from; }
    if ($to   !== '') { $where[] = "DATE(published_at) <= ?"; $params[] = $to; }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, class_id, title, body, published_at, expires_at, created_by, created_at, updated_at FROM notices $whereSql ORDER BY published_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=notices_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','school_id','class_id','title','body','published_at','expires_at','created_by','created_at','updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['class_id'] ?? '',
            $r['title'] ?? '',
            $r['body'] ?? '',
            $r['published_at'] ?? '',
            $r['expires_at'] ?? '',
            $r['created_by'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------
   Build listing (filters, pagination)
   ------------------------ */
$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0; // 0 == global + my classes
$from = isset($_GET['from']) ? substr((string)$_GET['from'], 0, 10) : '';
$to   = isset($_GET['to'])   ? substr((string)$_GET['to'], 0, 10) : '';

$notices = [];
$total = 0;

if ($hasNotices) {
    // Build where clauses similar to export
    $where = [];
    $params = [];

    if (!empty($classIds)) {
        if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
            $where[] = "class_id = ?";
            $params[] = $filterClass;
        } else {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $where[] = "(class_id IS NULL OR class_id IN ($ph))";
            foreach ($classIds as $v) $params[] = $v;
        }
    } else {
        $where[] = "class_id IS NULL";
    }

    if ($from !== '') { $where[] = "DATE(published_at) >= ?"; $params[] = $from; }
    if ($to   !== '') { $where[] = "DATE(published_at) <= ?"; $params[] = $to; }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    // count
    $countRow = safe_db_get_one("SELECT COUNT(*) AS cnt FROM notices $whereSql", $params);
    $total = intval($countRow['cnt'] ?? 0);

    if ($total > 0) {
        // append limit params
        $paramsWithLimit = $params;
        $paramsWithLimit[] = $perPage;
        $paramsWithLimit[] = $offset;
        $sql = "SELECT id, school_id, class_id, title, body, published_at, expires_at, attachment_path, created_by, created_at, updated_at
                FROM notices $whereSql
                ORDER BY published_at DESC
                LIMIT ? OFFSET ?";
        $notices = safe_db_get_all($sql, $paramsWithLimit);
    }
}

/* ------------------------
   Render page
   ------------------------ */
$pageTitle = 'Notices';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="../parent/profile.php">Profile</a>
    <?php if ($hasNotices): ?>
      <a class="btn btn-sm btn-success" href="?action=export&class_id=<?php echo e($filterClass); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3 p-3">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select">
        <option value="0"<?php if ($filterClass === 0) echo ' selected'; ?>>Global + My classes</option>
        <?php foreach ($classOptions as $cid => $label): ?>
          <option value="<?php echo (int)$cid; ?>"<?php if ($filterClass === $cid) echo ' selected'; ?>><?php echo e($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Published from</label>
      <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">Published to</label>
      <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
    </div>

    <div class="col-md-3 text-end">
      <button class="btn btn-primary">Filter</button>
    </div>
  </form>
</div>

<div class="card mb-3">
  <div class="card-body">
    <?php if (!$hasNotices): ?>
      <div class="small-muted">Notices table not found. Contact admin.</div>
    <?php elseif (empty($notices)): ?>
      <div class="small-muted">No notices found for the selected filters.</div>
    <?php else: ?>
      <div class="list-group">
        <?php foreach ($notices as $n): $nid = (int)$n['id']; ?>
          <div class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <div class="notice-title"><?php echo e($n['title'] ?? '—'); ?></div>
              <div class="small-muted"><?php echo e(substr((string)($n['body'] ?? ''), 0, 180)); ?><?php if (strlen((string)($n['body'] ?? '')) > 180) echo '...'; ?></div>
              <div class="small-muted mt-1">Published: <?php echo e(substr((string)($n['published_at'] ?? ''),0,10) ?: '—'); ?> • Expires: <?php echo e(substr((string)($n['expires_at'] ?? ''),0,10) ?: '—'); ?></div>
            </div>
            <div class="text-end">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#noticeViewModal" data-id="<?php echo $nid; ?>">View</button>
              <?php if (!empty($n['attachment_path'])): ?>
                <a class="btn btn-sm btn-outline-secondary ms-1" href="<?php echo e($n['attachment_path']); ?>" target="_blank">Attachment</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
      ?>
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small-muted">Showing <?php echo min($offset+1, $total); ?> - <?php echo min($offset + count($notices), $total); ?> of <?php echo $total; ?> notices</div>
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

<!-- Notice view modal -->
<div class="modal fade" id="noticeViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Notice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="noticeViewBody"><div class="text-center small-muted py-3">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var modal = document.getElementById('noticeViewModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('noticeViewBody');
      body.innerHTML = '<div class="text-center small-muted py-3">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ if (!resp.ok) throw new Error('Network'); return resp.text(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load notice details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>