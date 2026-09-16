<?php
/**
 * Shared feature: monthly_summary
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? '');

/* Debug flag */
$esc = function(string $v){ return e($v); };


/* -------------------------
   Validate fees_records table exists
   ------------------------- */
if (!table_exists('fees_records')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>fees_records</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Page inputs (month/year) and filters
   ------------------------- */
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n')); // 1-12

// normalize
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

$fromDate = sprintf('%04d-%02d-01', $year, $month);
$toDate = date('Y-m-d', strtotime($fromDate . ' +1 month -1 day'));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
// restrict by selected month on COALESCE(collected_at, created_at)
$where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) BETWEEN :from_date AND :to_date";
$params[':from_date'] = $fromDate;
$params[':to_date'] = $toDate;

// optional filters: school_id, class_id, status, q (receipt_no / student name / receipt_no / amount)
if (!empty($_GET['school_id'])) { $where[] = "fr.school_id = :school_id"; $params[':school_id'] = (int)$_GET['school_id']; }
if (!empty($_GET['class_id'])) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = (int)$_GET['class_id']; }
if (!empty($_GET['status'])) { $where[] = "fr.status = :status"; $params[':status'] = $_GET['status']; }
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') {
    $where[] = "(fr.receipt_no LIKE :q OR CAST(fr.amount AS CHAR) LIKE :q OR CAST(fr.paid_amount AS CHAR) LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q)";
    $params[':q'] = '%' . $qraw . '%';
}
if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($where, $params, 'st');
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* -------------------------
   Totals for the month
   ------------------------- */
try {
    $totRow = safe_db_get_one("
        SELECT
          COUNT(*) AS records_count,
          SUM(fr.amount) AS total_amount,
          SUM(fr.paid_amount) AS total_paid,
          SUM(fr.amount - fr.paid_amount) AS total_due
        FROM fees_records fr
        LEFT JOIN students st ON st.id = fr.student_id
        $whereSql
    ", $params);
} catch (Throwable $e) {
    $totRow = null;
    $errors[] = 'Totals query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}
$records_count = intval($totRow['records_count'] ?? 0);
$total_amount = $totRow['total_amount'] !== null ? (float)$totRow['total_amount'] : 0.0;
$total_paid = $totRow['total_paid'] !== null ? (float)$totRow['total_paid'] : 0.0;
$total_due = $totRow['total_due'] !== null ? (float)$totRow['total_due'] : 0.0;

/* -------------------------
   Fetch records list (with joins)
   ------------------------- */
$fees = [];
try {
    $sql = "SELECT fr.*, 
                   COALESCE(s.name,'') AS school_name,
                   CONCAT(COALESCE(st.first_name,''), ' ', COALESCE(st.last_name,'')) AS student_name,
                   COALESCE(c.name,'') AS class_name,
                   COALESCE(u.name,'') AS collector_name,
                   COALESCE(fr.collected_at, fr.created_at) AS effective_collected_at
            FROM fees_records fr
            LEFT JOIN schools s ON s.id = fr.school_id
            LEFT JOIN students st ON st.id = fr.student_id
            LEFT JOIN classes c ON c.id = fr.class_id
            LEFT JOIN users u ON u.id = fr.collected_by
            $whereSql
            ORDER BY effective_collected_at DESC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $fees = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $fees = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

/* -------------------------
   Count for pagination
   ------------------------- */
try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records fr LEFT JOIN students st ON st.id = fr.student_id $whereSql", $params);
    $totalRows = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $totalRows = 0;
}

/* -------------------------
   Data for filters (schools, classes)
   ------------------------- */
$schoolList = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$statusOptions = ['due','partial','paid','overdue'];

/* -------------------------
   Export CSV for the selected month
   ------------------------- */
if (!empty($_GET['action']) && $_GET['action'] === 'export') {
    $rows = safe_db_get_all("SELECT fr.*, COALESCE(s.name,'') AS school_name, CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS student_name, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS collector_name, COALESCE(fr.collected_at, fr.created_at) AS effective_collected_at
                             FROM fees_records fr
                             LEFT JOIN schools s ON s.id = fr.school_id
                             LEFT JOIN students st ON st.id = fr.student_id
                             LEFT JOIN classes c ON c.id = fr.class_id
                             LEFT JOIN users u ON u.id = fr.collected_by
                             $whereSql
                             ORDER BY effective_collected_at DESC", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=monthly_collection_' . $year . sprintf('%02d',$month) . '.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','School','Student','Class','Amount','Paid Amount','Due','Status','Receipt No','Collected By','Collected At','Created At','Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['school_name'] ?? '',
            $r['student_name'] ?? '',
            $r['class_name'] ?? '',
            $r['amount'] ?? '',
            $r['paid_amount'] ?? '',
            (isset($r['amount']) && isset($r['paid_amount'])) ? ($r['amount'] - $r['paid_amount']) : '',
            $r['status'] ?? '',
            $r['receipt_no'] ?? '',
            $r['collector_name'] ?? '',
            $r['collected_at'] ?? '',
            $r['created_at'] ?? '',
            preg_replace("/\r\n|\r|\n/"," ", $r['notes'] ?? '')
        ]);
    }
    fclose($out);
    exit;
}

/* -------------------------
   View fragment (AJAX) - single record details
   ------------------------- */
if (!empty($_GET['action']) && $_GET['action'] === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT fr.*, COALESCE(s.name,'') AS school_name, CONCAT(COALESCE(st.first_name,''),' ',COALESCE(st.last_name,'')) AS student_name, COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS collector_name
                             FROM fees_records fr
                             LEFT JOIN schools s ON s.id = fr.school_id
                             LEFT JOIN students st ON st.id = fr.student_id
                             LEFT JOIN classes c ON c.id = fr.class_id
                             LEFT JOIN users u ON u.id = fr.collected_by
                             WHERE fr.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted">Record not found</div>'; exit; }

    echo '<dl class="row">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">School</dt><dd class="col-sm-9">'.($row['school_name'] ? $esc($row['school_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Student</dt><dd class="col-sm-9">'.($row['student_name'] ? $esc($row['student_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Class</dt><dd class="col-sm-9">'.($row['class_name'] ? $esc($row['class_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Receipt</dt><dd class="col-sm-9">'.($row['receipt_no'] ? $esc($row['receipt_no']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Amount</dt><dd class="col-sm-9">₹ '.number_format((float)($row['amount'] ?? 0),2).'</dd>';
    echo '<dt class="col-sm-3">Paid Amount</dt><dd class="col-sm-9">₹ '.number_format((float)($row['paid_amount'] ?? 0),2).'</dd>';
    $due = (isset($row['amount']) && isset($row['paid_amount'])) ? (float)$row['amount'] - (float)$row['paid_amount'] : 0;
    echo '<dt class="col-sm-3">Due</dt><dd class="col-sm-9">₹ '.number_format($due,2).'</dd>';
    echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.($row['status'] ? $esc($row['status']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Collected By</dt><dd class="col-sm-9">'.($row['collector_name'] ? $esc($row['collector_name']) : '—').'</dd>';
    echo '<dt class="col-sm-3">Collected At</dt><dd class="col-sm-9">'.($row['collected_at'] ?? $row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-sm-3">Created At</dt><dd class="col-sm-9">'.($row['created_at'] ?? '').'</dd>';
    echo '<dt class="col-12">Notes</dt><dd class="col-12"><pre style="white-space:pre-wrap;">'.e($row['notes'] ?? '').'</pre></dd>';
    echo '</dl>';
    exit;
}

/* -------------------------
   Pagination math
   ------------------------- */
$totalPages = (int)ceil(max(0, $totalRows) / $perPage);
function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

$pageTitle = (string)($cfg['page_title'] ?? 'Page');
require_once __DIR__ . '/../header.php';
?>

  <?php if (!empty($errors)): foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo $esc($err); ?></div><?php endforeach; endif; ?>

  <!-- Filters & month selector -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label">Year</label>
        <select name="year" class="form-select">
          <?php for ($y = date('Y')-3; $y <= date('Y')+1; $y++): ?>
            <option value="<?php echo $y; ?>" <?php if($y===$year) echo 'selected'; ?>><?php echo $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Month</label>
        <select name="month" class="form-select">
          <?php for ($m=1;$m<=12;$m++): $mlabel = date('F', mktime(0,0,0,$m,1)); ?>
            <option value="<?php echo $m; ?>" <?php if($m===$month) echo 'selected'; ?>><?php echo $mlabel; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">School</label>
        <select name="school_id" class="form-select">
          <option value="">All schools</option>
          <?php foreach ($schoolList as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php if(isset($_GET['school_id']) && (int)$_GET['school_id']===(int)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Search (student / receipt / amount)</label>
        <input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Student name, receipt no, amount">
      </div>
      <div class="col-md-2 text-end">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="?">Reset</a>
      </div>
    </form>
  </div>

  <!-- Summary cards -->
  <div class="row g-2 mb-3">
    <div class="col-md-3">
      <div class="card p-3 text-center summary-card">
        <div class="h6">Records</div>
        <div class="h4"><?php echo number_format($records_count); ?></div>
        <div class="small text-muted">for <?php echo e(date('F Y', strtotime($fromDate))); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 text-center summary-card">
        <div class="h6">Total Amount</div>
        <div class="h4">₹ <?php echo number_format($total_amount,2); ?></div>
        <div class="small text-muted">Expected</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 text-center summary-card">
        <div class="h6">Total Collected</div>
        <div class="h4">₹ <?php echo number_format($total_paid,2); ?></div>
        <div class="small text-muted">Received</div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 text-center summary-card">
        <div class="h6">Total Due</div>
        <div class="h4">₹ <?php echo number_format($total_due,2); ?></div>
        <div class="small text-muted">Pending</div>
      </div>
    </div>
  </div>

  <!-- Records table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th style="width:150px">Collected At</th>
            <th>Student</th>
            <th style="width:140px">Class</th>
            <th style="width:120px">Amount</th>
            <th style="width:120px">Paid</th>
            <th style="width:120px">Due</th>
            <th style="width:120px">Status</th>
            <th style="width:180px">Receipt / Collected By</th>
            <th style="width:120px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($fees)): foreach ($fees as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo $esc(substr($r['effective_collected_at'] ?? $r['created_at'] ?? '', 0, 16)); ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($r['student_name'] ?: '—'); ?></div>
                <div class="small text-muted"><?php echo $esc($r['school_name'] ?? ''); ?></div>
              </td>
              <td><?php echo $esc($r['class_name'] ?? '—'); ?></td>
              <td>₹ <?php echo number_format((float)($r['amount'] ?? 0),2); ?></td>
              <td>₹ <?php echo number_format((float)($r['paid_amount'] ?? 0),2); ?></td>
              <td>₹ <?php $due = (isset($r['amount']) && isset($r['paid_amount'])) ? (float)$r['amount'] - (float)$r['paid_amount'] : 0; echo number_format($due,2); ?></td>
              <td><span class="badge <?php echo ($r['status']==='paid' ? 'bg-success' : ($r['status']==='partial' ? 'bg-warning text-dark' : ($r['status']==='overdue' ? 'bg-danger' : 'bg-secondary'))); ?>"><?php echo $esc($r['status'] ?? ''); ?></span></td>
              <td>
                <div class="small"><?php echo $esc($r['receipt_no'] ?? '—'); ?></div>
                <div class="small text-muted"><?php echo $esc($r['collector_name'] ?? '—'); ?></div>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$r['id']; ?>">View</button>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="10" class="text-center text-muted">No records for selected month.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $totalRows ? ($offset+1) : 0; ?> - <?php echo min($totalRows, $offset + count($fees)); ?> of <?php echo $totalRows; ?></div>
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
        <h5 class="modal-title">Collection details</h5>
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
    viewModal.addEventListener('show.bs.modal', function(event){
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