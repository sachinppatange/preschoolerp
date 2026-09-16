<?php
/**
 * Shared feature: daily_collection
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? '');

$esc = function(string $v) { return e($v); };


/* ---------- ensure fees_records exists ---------- */
if (!table_exists('fees_records')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>fees_records</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* ---------- actions: view, export ---------- */
$action = $_REQUEST['action'] ?? 'list';

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }

    $row = safe_db_get_one("
        SELECT fr.*, 
               COALESCE(s.first_name, '') AS student_first, COALESCE(s.last_name,'') AS student_last,
               COALESCE(c.name,'') AS class_name,
               COALESCE(u.name,'') AS collector_name,
               COALESCE(sc.name,'') AS school_name
        FROM fees_records fr
        LEFT JOIN students s ON s.id = fr.student_id
        LEFT JOIN classes c ON c.id = fr.class_id
        LEFT JOIN users u ON u.id = fr.collected_by
        LEFT JOIN schools sc ON sc.id = fr.school_id
        WHERE fr.id = :id LIMIT 1
    ", [':id'=>$id]);

    if (!$row) { echo '<div class="text-muted">Record not found</div>'; exit; }

    // render details
    echo '<dl class="row">';
    echo '<dt class="col-sm-4">ID</dt><dd class="col-sm-8">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-4">School</dt><dd class="col-sm-8">'.($row['school_name'] ? e($row['school_name']) : '—').'</dd>';
    $studentFull = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
    echo '<dt class="col-sm-4">Student</dt><dd class="col-sm-8">'.($studentFull ? e($studentFull) : '—').'</dd>';
    echo '<dt class="col-sm-4">Class</dt><dd class="col-sm-8">'.($row['class_name'] ? e($row['class_name']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Amount (₹)</dt><dd class="col-sm-8">'.(isset($row['amount']) ? number_format((float)$row['amount'],2) : '').'</dd>';
    echo '<dt class="col-sm-4">Paid Amount (₹)</dt><dd class="col-sm-8">'.(isset($row['paid_amount']) ? number_format((float)$row['paid_amount'],2) : '').'</dd>';
    echo '<dt class="col-sm-4">Due Date</dt><dd class="col-sm-8">'.(!empty($row['due_date']) ? e($row['due_date']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Receipt No.</dt><dd class="col-sm-8">'.e($row['receipt_no'] ?? '').'</dd>';
    echo '<dt class="col-sm-4">Collected By</dt><dd class="col-sm-8">'.($row['collector_name'] ? e($row['collector_name']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Collected At</dt><dd class="col-sm-8">'.(!empty($row['collected_at']) ? e($row['collected_at']) : '—').'</dd>';
    echo '<dt class="col-sm-4">Status</dt><dd class="col-sm-8">'.e($row['status'] ?? '').'</dd>';
    echo '<dt class="col-sm-4">Created At</dt><dd class="col-sm-8">'.e($row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-4">Updated At</dt><dd class="col-sm-8">'.e($row['updated_at'] ?? '').'</dd>';
    echo '</dl>';
    exit;
}

/* EXPORT (CSV) */
if ($action === 'export') {
    $where = []; $params = [];

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where[] = "(s.first_name LIKE :q OR s.last_name LIKE :q OR fr.receipt_no LIKE :q)"; $params[':q'] = '%' . $q . '%'; }

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') { $where[] = "fr.status = :status"; $params[':status'] = $status; }

    if (!empty($_GET['class_id'])) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }

    $dateField = ($_GET['date_field'] ?? 'created_at') === 'due_date' ? 'due_date' : 'created_at';
    $from = $_GET['from'] ?? ''; if ($from!=='') { $where[] = "fr.$dateField >= :from"; $params[':from'] = $from . ' 00:00:00'; }
    $to = $_GET['to'] ?? ''; if ($to!=='') { $where[] = "fr.$dateField <= :to"; $params[':to'] = $to . ' 23:59:59'; }
    if (function_exists('ay_apply_student_filter')) {
        ay_apply_student_filter($where, $params, 's');
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("
        SELECT fr.*, COALESCE(s.first_name,'') AS student_first, COALESCE(s.last_name,'') AS student_last, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS collector_name
        FROM fees_records fr
        LEFT JOIN students s ON s.id = fr.student_id
        LEFT JOIN classes c ON c.id = fr.class_id
        LEFT JOIN users u ON u.id = fr.collected_by
        $whereSql
        ORDER BY fr.created_at DESC
    ", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=fees_records_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','Student','Class','Amount','Paid Amount','Due Date','Status','Receipt No','Collected By','Collected At','Created At','Updated At']);
    foreach ($rows as $r) {
        $student = trim(($r['student_first'] ?? '') . ' ' . ($r['student_last'] ?? ''));
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $student,
            $r['class_name'] ?? '',
            isset($r['amount']) ? number_format((float)$r['amount'],2) : '',
            isset($r['paid_amount']) ? number_format((float)$r['paid_amount'],2) : '',
            $r['due_date'] ?? '',
            $r['status'] ?? '',
            $r['receipt_no'] ?? '',
            $r['collector_name'] ?? '',
            $r['collected_at'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- list view ---------- */

/* Filters / pagination */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];

$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') {
    $where[] = "(s.first_name LIKE :q OR s.last_name LIKE :q OR fr.receipt_no LIKE :q)";
    $params[':q'] = '%' . $qraw . '%';
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($statusFilter !== '') { $where[] = "fr.status = :status"; $params[':status'] = $statusFilter; }

if (!empty($_GET['class_id'])) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }

$date_field = ($_GET['date_field'] ?? 'created_at') === 'due_date' ? 'due_date' : 'created_at';
$from = $_GET['from'] ?? ''; if ($from !== '') { $where[] = "fr.$date_field >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to = $_GET['to'] ?? ''; if ($to !== '') { $where[] = "fr.$date_field <= :to"; $params[':to'] = $to . ' 23:59:59'; }
if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($where, $params, 's');
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* total count */
try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records fr LEFT JOIN students s ON s.id = fr.student_id $whereSql", $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
}

/* fetch page */
$records = [];
try {
    $sql = "
        SELECT fr.*, 
               COALESCE(s.first_name,'') AS student_first, COALESCE(s.last_name,'') AS student_last,
               COALESCE(c.name,'') AS class_name,
               COALESCE(u.name,'') AS collector_name,
               COALESCE(sc.name,'') AS school_name
        FROM fees_records fr
        LEFT JOIN students s ON s.id = fr.student_id
        LEFT JOIN classes c ON c.id = fr.class_id
        LEFT JOIN users u ON u.id = fr.collected_by
        LEFT JOIN schools sc ON sc.id = fr.school_id
        $whereSql
        ORDER BY fr.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $records = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $records = [];
}

/* form data for filters */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$statusOptions = ['due','partial','paid','overdue'];

$totalPages = (int)ceil(max(0, $total) / $perPage);

/* stats: totals for today and sums */
$stats = [
    'total' => $total,
    'today_collected' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records WHERE DATE(collected_at) = CURDATE()", [])['c'] ?? 0)),
    'today_amount' => (float)(safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS s FROM fees_records WHERE DATE(collected_at) = CURDATE()", [])['s'] ?? 0),
    'due' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records WHERE status = 'due'", [])['c'] ?? 0)),
    'paid' => intval((safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records WHERE status = 'paid'", [])['c'] ?? 0)),
];

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null) unset($qs[$k]);
        else $qs[$k] = $v;
    }
    return http_build_query($qs);
}

/* header include if exists */
require_once __DIR__ . '/../header.php';
?>

  <!-- messages / stats -->
  <div class="row g-2 mb-3">
    <div class="col-sm-3"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo e((string)$stats['total']); ?></div><div class="small text-muted">Records</div></div></div>
    <div class="col-sm-3"><div class="card p-2 text-center"><div class="h5 mb-0">₹ <?php echo number_format($stats['today_amount'],2); ?></div><div class="small text-muted">Collected Today</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo e((string)$stats['today_collected']); ?></div><div class="small text-muted">Collected Today (count)</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo e((string)$stats['due']); ?></div><div class="small text-muted">Due</div></div></div>
    <div class="col-sm-2"><div class="card p-2 text-center"><div class="h5 mb-0"><?php echo e((string)$stats['paid']); ?></div><div class="small text-muted">Paid</div></div></div>
  </div>

  <!-- filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Search</label>
        <input name="q" class="form-control" value="<?php echo e($qraw); ?>" placeholder="Student name or receipt no.">
      </div>

      <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Any</option>
          <?php foreach ($statusOptions as $st): ?>
            <option value="<?php echo e($st); ?>" <?php if(($statusFilter ?? '') === $st) echo 'selected'; ?>><?php echo e(ucfirst($st)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="">Any</option>
          <?php foreach ($classList as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if((string)($_GET['class_id'] ?? '') === (string)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Date Field</label>
        <select name="date_field" class="form-select">
          <option value="created_at" <?php if($date_field === 'created_at') echo 'selected'; ?>>Created At</option>
          <option value="due_date" <?php if($date_field === 'due_date') echo 'selected'; ?>>Due Date</option>
        </select>
      </div>

      <div class="col-md-2 text-end">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="?">Reset</a>
      </div>

      <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($_GET['from'] ?? ''); ?>"></div>
      <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($_GET['to'] ?? ''); ?>"></div>
    </form>
  </div>

  <!-- records table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th style="width:140px">Created</th>
            <th>Student / Class</th>
            <th style="width:120px">Amount</th>
            <th style="width:120px">Paid</th>
            <th style="width:120px">Due Date</th>
            <th style="width:140px">Receipt / Collected</th>
            <th style="width:120px">Status</th>
            <th style="width:160px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($records)): foreach ($records as $r): ?>
            <?php
              $student = trim(($r['student_first'] ?? '') . ' ' . ($r['student_last'] ?? ''));
              $className = $r['class_name'] ?? '';
              $status = $r['status'] ?? '';
            ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo e(substr($r['created_at'] ?? '', 0, 16)); ?></td>
              <td>
                <div class="fw-semibold"><?php echo e($student ?: '—'); ?></div>
                <div class="small text-muted"><?php echo e($className ?: '—'); ?></div>
              </td>
              <td>₹ <?php echo number_format((float)($r['amount'] ?? 0), 2); ?></td>
              <td>₹ <?php echo number_format((float)($r['paid_amount'] ?? 0), 2); ?></td>
              <td><?php echo e($r['due_date'] ?? '—'); ?></td>
              <td>
                <div class="small"><?php echo e($r['receipt_no'] ?? ''); ?></div>
                <div class="small text-muted"><?php echo e($r['collector_name'] ?? ''); ?></div>
              </td>
              <td>
                <span class="badge <?php
                    echo ($status === 'paid' ? 'bg-success' : ($status === 'partial' ? 'bg-warning text-dark' : ($status === 'overdue' ? 'bg-danger' : 'bg-secondary')));
                ?>"><?php echo e(ucfirst(str_replace('_',' ',$status))); ?></span>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
                <!-- future: edit/delete buttons can go here -->
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="9" class="text-center text-muted">No records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset + 1) : 0; ?> - <?php echo min($total, $offset + count($records)); ?> of <?php echo $total; ?></div>
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

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Fees Record Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      const id = event.relatedTarget.getAttribute('data-id');
      const body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(resp => resp.ok ? resp.text() : Promise.reject())
        .then(html => { body.innerHTML = html; })
        .catch(() => { body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>