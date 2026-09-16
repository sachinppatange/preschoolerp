<?php
/**
 * accounts/reports.php
 *
 * Accounts - Reports and exports for Pioneer Play School.
 *
 * Features:
 *  - Quick reports: daily collection, monthly collection, pending fees, expense summary
 *  - Date-range filter and class/school filters where applicable
 *  - Export CSV for the selected report and filters (?action=export&report=<name>&...)
 *  - View small detail fragments for selected rows (AJAX fragments)
 *
 * Place at: /pioneerplayschool01/accounts/reports.php
 *
 * Auth: $_SESSION['accounts_auth_user'] or $_SESSION['accounts_user_id'] required (role 'accounts' enforced if present).
 *
 * This file follows the same DB/helpers pattern used across the app (pdo_connect, safe_db_get_one, safe_db_get_all).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
$DEBUG = panel_debug();

function format_money($v): string { return '₹ ' . number_format((float)$v, 2); }

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

$accountsUserId = auth_user_id();

/* -------------------------
   Inputs & filters
   ------------------------- */
$report = trim((string)($_GET['report'] ?? 'daily')); // daily, monthly, pending_fees, expense_summary
$action = trim((string)($_GET['action'] ?? 'view')); // view or export
$from = trim((string)($_GET['from'] ?? '')); // yyyy-mm-dd
$to   = trim((string)($_GET['to'] ?? ''));
$class_id = !empty($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$school_id = !empty($_GET['school_id']) ? (int) $_GET['school_id'] : 0;

/* default date range: today for daily, this month for monthly, last 30 days for others */
if ($report === 'daily') {
    if ($from === '') $from = date('Y-m-d');
    if ($to === '') $to = $from;
} elseif ($report === 'monthly') {
    if ($from === '') $from = date('Y-m-01');
    if ($to === '') $to = date('Y-m-t');
} else {
    if ($from === '') $from = date('Y-m-d', strtotime('-30 days'));
    if ($to === '') $to = date('Y-m-d');
}

/* quick guards for tables */
$hasFees = table_exists('fees_records');
$hasExpenses = table_exists('expenses');
$hasStudents = table_exists('students');
$hasClasses = table_exists('classes');
$hasSchools = table_exists('schools');

/* -------------------------
   Build where clauses shared
   ------------------------- */
$where = []; $params = [];
if ($from !== '') { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) >= :from"; $params[':from'] = $from; }
if ($to !== '')   { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) <= :to";   $params[':to']   = $to; }
if ($class_id) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = $class_id; }
if ($school_id) { $where[] = "fr.school_id = :school_id"; $params[':school_id'] = $school_id; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* -------------------------
   Prepare report data
   ------------------------- */
$reportTitle = '';
$rows = [];
$summary = [];

try {
    if ($report === 'daily') {
        $reportTitle = 'Daily Collection';
        if ($hasFees) {
            // totals by day within range
            $sql = "SELECT DATE(COALESCE(fr.collected_at, fr.created_at)) AS day,
                           COUNT(*) AS count_records,
                           COALESCE(SUM(fr.paid_amount),0) AS total_paid,
                           COALESCE(SUM(fr.amount),0) AS total_amount
                    FROM fees_records fr
                    $whereSql
                    GROUP BY day
                    ORDER BY day ASC";
            $rows = safe_db_get_all($sql, $params);
            $s = safe_db_get_one("SELECT COALESCE(SUM(fr.paid_amount),0) AS total_paid, COALESCE(SUM(fr.amount),0) AS total_amount FROM fees_records fr " . ($whereSql ? $whereSql : ''), $params);
            $summary['total_paid'] = (float)($s['total_paid'] ?? 0);
            $summary['total_amount'] = (float)($s['total_amount'] ?? 0);
            $summary['days'] = count($rows);
        } else {
            $rows = [];
            $summary = ['total_paid'=>0,'total_amount'=>0,'days'=>0];
        }
    } elseif ($report === 'monthly') {
        $reportTitle = 'Monthly Collection (by day)';
        if ($hasFees) {
            $sql = "SELECT DATE(COALESCE(fr.collected_at, fr.created_at)) AS day,
                           COUNT(*) AS count_records,
                           COALESCE(SUM(fr.paid_amount),0) AS total_paid,
                           COALESCE(SUM(fr.amount),0) AS total_amount
                    FROM fees_records fr
                    $whereSql
                    GROUP BY day
                    ORDER BY day ASC";
            $rows = safe_db_get_all($sql, $params);
            $s = safe_db_get_one("SELECT COALESCE(SUM(fr.paid_amount),0) AS total_paid, COALESCE(SUM(fr.amount),0) AS total_amount FROM fees_records fr " . ($whereSql ? $whereSql : ''), $params);
            $summary['total_paid'] = (float)($s['total_paid'] ?? 0);
            $summary['total_amount'] = (float)($s['total_amount'] ?? 0);
        } else {
            $rows = []; $summary = ['total_paid'=>0,'total_amount'=>0];
        }
    } elseif ($report === 'pending_fees') {
        $reportTitle = 'Pending Fees';
        if ($hasFees) {
            // list unpaid or partial by student
            $sql = "SELECT fr.id, fr.student_id, COALESCE(st.first_name,'') AS first_name, COALESCE(st.last_name,'') AS last_name,
                           fr.amount, COALESCE(fr.paid_amount,0) AS paid_amount, (fr.amount - COALESCE(fr.paid_amount,0)) AS due_amount,
                           fr.due_date, fr.status
                    FROM fees_records fr
                    LEFT JOIN students st ON st.id = fr.student_id
                    WHERE (fr.amount - COALESCE(fr.paid_amount,0)) > 0
                    " . ($class_id ? " AND fr.class_id = :class_id" : "") . ($school_id ? " AND fr.school_id = :school_id" : "") . "
                    ORDER BY fr.due_date IS NULL, fr.due_date ASC, st.first_name ASC";
            // reuse params but only class_id/school_id might be present
            $rows = safe_db_get_all($sql, $params);
            $s = safe_db_get_one("SELECT COALESCE(SUM(GREATEST(0, amount - COALESCE(paid_amount,0))),0) AS total_due FROM fees_records fr " . ($whereSql ? $whereSql : ''), $params);
            // fallback if above returns null: compute unconditional
            if ($s === null) $s = safe_db_get_one("SELECT COALESCE(SUM(GREATEST(0, amount - COALESCE(paid_amount,0))),0) AS total_due FROM fees_records");
            $summary['total_due'] = (float)($s['total_due'] ?? 0);
            $summary['count'] = count($rows);
        } else {
            $rows = []; $summary = ['total_due'=>0,'count'=>0];
        }
    } elseif ($report === 'expense_summary') {
        $reportTitle = 'Expenses Summary';
        if ($hasExpenses) {
            // sum by category
            $sql = "SELECT COALESCE(category,'Uncategorized') AS category, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total_amount
                    FROM expenses ex
                    WHERE DATE(ex.expense_date) >= :from AND DATE(ex.expense_date) <= :to
                    GROUP BY category
                    ORDER BY total_amount DESC";
            $rows = safe_db_get_all($sql, [':from'=>$from, ':to'=>$to]);
            $s = safe_db_get_one("SELECT COALESCE(SUM(amount),0) AS total_amount FROM expenses WHERE DATE(expense_date) >= :from AND DATE(expense_date) <= :to", [':from'=>$from, ':to'=>$to]);
            $summary['total'] = (float)($s['total_amount'] ?? 0);
        } else {
            $rows = []; $summary = ['total'=>0];
        }
    } else {
        // unknown report - default to daily
        $report = 'daily';
        header('Location: ?report=daily');
        exit;
    }
} catch (Throwable $e) {
    if ($DEBUG) error_log('Report generation error: ' . $e->getMessage());
    $rows = []; $summary = [];
}

/* -------------------------
   Export CSV
   ------------------------- */
if ($action === 'export') {
    $filename = 'report_' . $report . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output','w');

    if ($report === 'daily' || $report === 'monthly') {
        fputcsv($out, ['Day','Records','Total Paid','Total Amount']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['day'] ?? '', $r['count_records'] ?? 0, $r['total_paid'] ?? 0, $r['total_amount'] ?? 0]);
        }
    } elseif ($report === 'pending_fees') {
        fputcsv($out, ['Record ID','Student ID','Student Name','Amount','Paid Amount','Due Amount','Due Date','Status']);
        foreach ($rows as $r) {
            $student = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            fputcsv($out, [
                $r['id'] ?? '', $r['student_id'] ?? '', $student,
                $r['amount'] ?? '', $r['paid_amount'] ?? '', $r['due_amount'] ?? '', $r['due_date'] ?? '', $r['status'] ?? ''
            ]);
        }
    } elseif ($report === 'expense_summary') {
        fputcsv($out, ['Category','Count','Total Amount']);
        foreach ($rows as $r) fputcsv($out, [$r['category'] ?? '', $r['cnt'] ?? 0, $r['total_amount'] ?? 0]);
    }
    fclose($out);
    exit;
}

/* -------------------------
   Data for filters (classes, schools)
   ------------------------- */
$classList = $hasClasses ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$schoolList = $hasSchools ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];

$pageTitle = 'Accounts — Reports';
require_once __DIR__ . '/../includes/header.php';
?>

  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="report" value="<?php echo e($report); ?>">
      <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($from); ?>"></div>
      <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($to); ?>"></div>
      <div class="col-md-3"><label class="form-label">Class</label>
        <select name="class_id" class="form-select">
          <option value="">All</option>
          <?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if($class_id===(int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label">School</label>
        <select name="school_id" class="form-select">
          <option value="">All</option>
          <?php foreach ($schoolList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if($school_id===(int)$s['id']) echo 'selected'; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 text-end mt-2">
        <button class="btn btn-primary">Generate</button>
        <a class="btn btn-outline-secondary" href="?report=<?php echo e($report); ?>">Reset</a>
        <a class="btn btn-success" href="?action=export&report=<?php echo urlencode($report); ?>&<?php echo http_build_query(['from'=>$from,'to'=>$to,'class_id'=>$class_id,'school_id'=>$school_id]); ?>">Export CSV</a>
      </div>
    </form>
  </div>

  <!-- Summary -->
  <div class="row g-3 mb-3">
    <?php if ($report === 'daily' || $report === 'monthly'): ?>
      <div class="col-md-4">
        <div class="summary-box">
          <div class="small-muted">Total Paid</div>
          <div class="h4"><?php echo e(format_money($summary['total_paid'] ?? 0)); ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="summary-box">
          <div class="small-muted">Total Expected</div>
          <div class="h4"><?php echo e(format_money($summary['total_amount'] ?? 0)); ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="summary-box">
          <div class="small-muted">Days</div>
          <div class="h4"><?php echo e((int)($summary['days'] ?? count($rows))); ?></div>
        </div>
      </div>
    <?php elseif ($report === 'pending_fees'): ?>
      <div class="col-md-4">
        <div class="summary-box"><div class="small-muted">Total Due</div><div class="h4"><?php echo e(format_money($summary['total_due'] ?? 0)); ?></div></div>
      </div>
      <div class="col-md-4">
        <div class="summary-box"><div class="small-muted">Pending Records</div><div class="h4"><?php echo e((int)($summary['count'] ?? count($rows))); ?></div></div>
      </div>
      <div class="col-md-4">
        <div class="summary-box"><div class="small-muted">Filter Range</div><div class="h6"><?php echo e($from); ?> — <?php echo e($to); ?></div></div>
      </div>
    <?php elseif ($report === 'expense_summary'): ?>
      <div class="col-md-4">
        <div class="summary-box"><div class="small-muted">Total Expenses</div><div class="h4"><?php echo e(format_money($summary['total'] ?? 0)); ?></div></div>
      </div>
      <div class="col-md-8">
        <div class="summary-box"><div class="small-muted">Period</div><div class="h6"><?php echo e($from); ?> — <?php echo e($to); ?></div></div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Report table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <?php if ($report === 'daily' || $report === 'monthly'): ?>
            <tr><th>Day</th><th>Records</th><th>Total Paid</th><th>Total Amount</th></tr>
          <?php elseif ($report === 'pending_fees'): ?>
            <tr><th>Record ID</th><th>Student</th><th>Amount</th><th>Paid</th><th>Due</th><th>Due Date</th><th>Actions</th></tr>
          <?php elseif ($report === 'expense_summary'): ?>
            <tr><th>Category</th><th>Count</th><th>Total Amount</th></tr>
          <?php endif; ?>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="99" class="text-center text-muted">No data available for the selected filters.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php if ($report === 'daily' || $report === 'monthly'): ?>
                <tr>
                  <td><?php echo e($r['day'] ?? ''); ?></td>
                  <td><?php echo (int)($r['count_records'] ?? 0); ?></td>
                  <td><?php echo e(format_money($r['total_paid'] ?? 0)); ?></td>
                  <td><?php echo e(format_money($r['total_amount'] ?? 0)); ?></td>
                </tr>
              <?php elseif ($report === 'pending_fees'): ?>
                <tr>
                  <td><?php echo (int)($r['id'] ?? 0); ?></td>
                  <td><?php echo e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></td>
                  <td><?php echo e(format_money($r['amount'] ?? 0)); ?></td>
                  <td><?php echo e(format_money($r['paid_amount'] ?? 0)); ?></td>
                  <td><?php echo e(format_money($r['due_amount'] ?? 0)); ?></td>
                  <td><?php echo e($r['due_date'] ?? '—'); ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-info" href="/accounts/receipt_print.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Receipt</a>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#detailModal" data-type="fee" data-id="<?php echo (int)$r['id']; ?>">View</button>
                  </td>
                </tr>
              <?php elseif ($report === 'expense_summary'): ?>
                <tr>
                  <td><?php echo e($r['category'] ?? 'Uncategorized'); ?></td>
                  <td><?php echo (int)($r['cnt'] ?? 0); ?></td>
                  <td><?php echo e(format_money($r['total_amount'] ?? 0)); ?></td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 small-muted">Report: <?php echo e(ucfirst(str_replace('_',' ',$report))); ?> • Range: <?php echo e($from); ?> — <?php echo e($to); ?></div>
  </div>

</div>

<!-- Detail Modal for pending fees / items -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="detailModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var modal = document.getElementById('detailModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', function(event){
    var btn = event.relatedTarget;
    var id = btn.getAttribute('data-id');
    var type = btn.getAttribute('data-type'); // 'fee' etc
    var body = document.getElementById('detailModalBody');
    body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
    if (!id) { body.innerHTML = '<div class="text-danger">Invalid id</div>'; return; }

    var url = '';
    if (type === 'fee') url = '/accounts/receipt_print.php?id=' + encodeURIComponent(id);
    else url = '?action=view&id=' + encodeURIComponent(id);

    // For receipts, open as fragment via fetch; receipt_print outputs full HTML; we'll load via fetch and wrap
    fetch(url, { credentials: 'same-origin' })
      .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
      .then(function(html){ body.innerHTML = html; })
      .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
  });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>