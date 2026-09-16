<?php
/**
 * parent/events.php
 *
 * Parent-facing Events page.
 *
 * Features:
 * - Requires parent login ($_SESSION['parent_auth_user'])
 * - Determines classes linked to parent (parents_children -> students.class_id)
 * - Lists events from `events` table (supports class-specific and global events)
 * - Filtering by class and date range, pagination
 * - View event details in modal (AJAX fragment via ?action=view&id=...)
 * - Export visible events to CSV
 *
 * The code is defensive: it checks for table existence and tolerates missing optional fields.
 * Place at: /pioneerplayschool01/parent/events.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- Require parent login ---------- */
/* CSRF helpers (not strictly needed here but useful) */

/* ---------- Identify tables & user ---------- */
$hasParentsChildren = table_exists('parents_children');
$hasStudents = table_exists('students');
$hasEvents = table_exists('events');
$hasClasses = table_exists('classes');

$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

/* ---------- Determine class IDs parent is interested in ---------- */
$classIds = [];    // numeric list
$childIds = [];

if ($hasParentsChildren) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid", [':pid'=>$parentId]);
    foreach ($maps as $m) {
        $childIds[] = (int)($m['child_student_id'] ?? 0);
    }
}

if (!empty($childIds) && $hasStudents) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE id IN ($ph) AND class_id IS NOT NULL", $childIds);
    foreach ($rows as $r) {
        if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
    }
}

/* fallback: students.parent_id if no mapping available */
if (empty($classIds) && $hasStudents) {
    $rows = safe_db_get_all("SELECT DISTINCT class_id FROM students WHERE parent_id = :pid AND class_id IS NOT NULL", [':pid'=>$parentId]);
    foreach ($rows as $r) {
        if (!empty($r['class_id'])) $classIds[] = (int)$r['class_id'];
    }
}

/* Build class options for filter UI */
$classOptions = [];
if (!empty($classIds) && $hasClasses) {
    $ph = implode(',', array_fill(0, count($classIds), '?'));
    $crow = safe_db_get_all("SELECT id, name, short_name, section FROM classes WHERE id IN ($ph)", $classIds);
    foreach ($crow as $c) {
        $id = (int)$c['id'];
        $label = trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')));
        if (!empty($c['section'])) $label .= ' • Sec: ' . $c['section'];
        $classOptions[$id] = $label;
    }
    foreach ($classIds as $cid) if (!isset($classOptions[$cid])) $classOptions[$cid] = 'Class #' . $cid;
}

/* ---------- Actions: view event, export CSV ---------- */
$action = $_REQUEST['action'] ?? 'list';

/* VIEW modal fragment: show event details */
if ($action === 'view' && !empty($_GET['id'])) {
    $eid = (int)$_GET['id'];
    if ($eid <= 0) { echo '<div class="p-3 text-danger">Invalid event id.</div>'; exit; }
    if (!$hasEvents) { echo '<div class="p-3 text-muted">Events table not present.</div>'; exit; }

    $event = safe_db_get_one("SELECT * FROM events WHERE id = :id LIMIT 1", [':id'=>$eid]);
    if (!$event) { echo '<div class="p-3 text-muted">Event not found.</div>'; exit; }

    // authorize: allow global (class_id NULL) or class-specific if in parent's classIds
    $evtClass = isset($event['class_id']) ? (int)$event['class_id'] : 0;
    if ($evtClass !== 0 && !in_array($evtClass, $classIds, true)) {
        echo '<div class="p-3 text-muted">You are not authorized to view this event.</div>'; exit;
    }

    // Render details (use common fields if present)
    $title = $event['title'] ?? ($event['name'] ?? 'Event');
    $desc = $event['description'] ?? $event['body'] ?? '';
    $loc = $event['location'] ?? '';
    $start = $event['start_date'] ?? $event['date'] ?? '';
    $end = $event['end_date'] ?? '';
    $start_time = $event['start_time'] ?? '';
    $end_time = $event['end_time'] ?? '';
    $created_by = $event['created_by'] ?? '';
    $attachment = $event['attachment_path'] ?? $event['attachment'] ?? '';

    echo '<div class="p-3">';
    echo '<h4>' . e($title) . '</h4>';
    echo '<div class="small-muted mb-2">';
    if ($evtClass && isset($classOptions[$evtClass])) echo 'Class: ' . e($classOptions[$evtClass]) . ' • ';
    echo 'From: ' . e(substr((string)$start, 0, 16) ?: '—') . ($start_time ? ' ' . e($start_time) : '') ;
    if (!empty($end)) echo ' — To: ' . e(substr((string)$end,0,16)) . ($end_time ? ' ' . e($end_time) : '');
    echo '</div>';
    if ($loc) echo '<div class="mb-2"><strong>Location:</strong> ' . e($loc) . '</div>';
    echo '<div>' . nl2br(e($desc)) . '</div>';
    if (!empty($attachment)) echo '<div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="' . e($attachment) . '" target="_blank">Download attachment</a></div>';
    echo '<hr>';
    echo '<div class="small-muted">Posted by: ' . e($created_by ?: ($event['created_at'] ?? '—')) . '</div>';
    echo '</div>';
    exit;
}

/* EXPORT CSV of visible events given current filters */
if ($action === 'export') {
    if (!$hasEvents) { http_response_code(404); echo "Events table missing."; exit; }

    $filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0; // 0 -> global + my classes
    $from = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : '';
    $to = isset($_GET['to']) ? substr((string)$_GET['to'],0,10) : '';

    if (empty($classIds)) {
        // parent has no classes -> only global events
        $where = ["class_id IS NULL"];
        $params = [];
    } else {
        if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
            $where = ["class_id = ?"];
            $params = [$filterClass];
        } else {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $where = ["(class_id IS NULL OR class_id IN ($ph))"];
            $params = $classIds;
        }
    }
    if ($from !== '') { $where[] = "DATE(start_date) >= ?"; $params[] = $from; }
    if ($to   !== '') { $where[] = "DATE(start_date) <= ?"; $params[] = $to; }
    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, class_id, title, description, start_date, end_date, start_time, end_time, location, attachment_path, created_by, created_at FROM events $whereSql ORDER BY start_date DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=events_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','school_id','class_id','title','description','start_date','end_date','start_time','end_time','location','attachment_path','created_by','created_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['class_id'] ?? '',
            $r['title'] ?? '',
            $r['description'] ?? '',
            $r['start_date'] ?? '',
            $r['end_date'] ?? '',
            $r['start_time'] ?? '',
            $r['end_time'] ?? '',
            $r['location'] ?? '',
            $r['attachment_path'] ?? '',
            $r['created_by'] ?? '',
            $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- Listing: filters, pagination ---------- */
$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0; // 0 => global + my classes
$from = isset($_GET['from']) ? substr((string)$_GET['from'],0,10) : '';
$to   = isset($_GET['to'])   ? substr((string)$_GET['to'],0,10) : '';

$events = [];
$total = 0;

if ($hasEvents) {
    $where = [];
    $params = [];

    if (empty($classIds)) {
        $where[] = "class_id IS NULL";
    } else {
        if ($filterClass > 0 && in_array($filterClass, $classIds, true)) {
            $where[] = "class_id = ?";
            $params[] = $filterClass;
        } else {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $where[] = "(class_id IS NULL OR class_id IN ($ph))";
            foreach ($classIds as $v) $params[] = $v;
        }
    }

    if ($from !== '') { $where[] = "DATE(start_date) >= ?"; $params[] = $from; }
    if ($to !== '')   { $where[] = "DATE(start_date) <= ?"; $params[] = $to; }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    // total count
    $countRow = safe_db_get_one("SELECT COUNT(*) AS cnt FROM events $whereSql", $params);
    $total = intval($countRow['cnt'] ?? 0);

    if ($total > 0) {
        $paramsWithLimit = $params;
        $paramsWithLimit[] = $perPage;
        $paramsWithLimit[] = $offset;
        $sql = "SELECT id, school_id, class_id, title, description, start_date, end_date, start_time, end_time, location, attachment_path, created_by, created_at
                FROM events
                $whereSql
                ORDER BY start_date DESC
                LIMIT ? OFFSET ?";
        $events = safe_db_get_all($sql, $paramsWithLimit);
    }
}

/* ---------- Render page ---------- */
$pageTitle = 'Events';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="../parent/profile.php">Profile</a>
    <?php if ($hasEvents): ?>
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
          <option value="<?php echo (int)$cid; ?>"<?php if ($filterClass === (int)$cid) echo ' selected'; ?>><?php echo e($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">From (start date)</label>
      <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">To (start date)</label>
      <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
    </div>

    <div class="col-md-3 text-end">
      <button class="btn btn-primary">Filter</button>
    </div>
  </form>
</div>

<div class="card mb-3">
  <div class="card-body">
    <?php if (!$hasEvents): ?>
      <div class="small-muted">Events table not found. Contact administrator.</div>
    <?php elseif (empty($events)): ?>
      <div class="small-muted">No events found for the selected filters.</div>
    <?php else: ?>
      <div class="list-group">
        <?php foreach ($events as $ev): $id = (int)$ev['id']; ?>
          <div class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <div class="evt-title"><?php echo e($ev['title'] ?? '—'); ?></div>
              <div class="small-muted"><?php echo e(substr((string)($ev['description'] ?? ''), 0, 200)); ?><?php if (strlen((string)($ev['description'] ?? '')) > 200) echo '...'; ?></div>
              <div class="small-muted mt-1">When: <?php echo e(substr((string)($ev['start_date'] ?? ''),0,16) ?: '—'); if (!empty($ev['start_time'])) echo ' • ' . e($ev['start_time']); if (!empty($ev['end_date'])) echo ' — ' . e(substr((string)$ev['end_date'],0,16)); ?></div>
              <?php if (!empty($ev['location'])): ?><div class="small-muted">Location: <?php echo e($ev['location']); ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#eventViewModal" data-id="<?php echo $id; ?>">View</button>
              <?php if (!empty($ev['attachment_path'])): ?>
                <a class="btn btn-sm btn-outline-secondary ms-1" href="<?php echo e($ev['attachment_path']); ?>" target="_blank">Attachment</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
      ?>
      <div class="mt-3 d-flex justify-content-between align-items-center">
        <div class="small-muted">Showing <?php echo min($offset+1, $total); ?> - <?php echo min($offset + count($events), $total); ?> of <?php echo $total; ?> events</div>
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

<!-- Event view modal -->
<div class="modal fade" id="eventViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Event</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="eventViewBody"><div class="text-center small-muted py-3">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var evModal = document.getElementById('eventViewModal');
  if (evModal) {
    evModal.addEventListener('show.bs.modal', function(event){
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('eventViewBody');
      body.innerHTML = '<div class="text-center small-muted py-3">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ if (!resp.ok) throw new Error('Network'); return resp.text(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load event details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>