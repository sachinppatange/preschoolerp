<?php
/**
 * Money report for a date range: fees in, expenses out, leftover, by class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');

$page_title = 'Reports';
$pageTitle = $page_title;

$selfUrl = function_exists('site_url') ? site_url('/accounts/reports.php') : 'reports.php';
$dailyUrl = function_exists('site_url') ? site_url('/accounts/daily_collection.php') : 'daily_collection.php';
$monthUrl = function_exists('site_url') ? site_url('/accounts/monthly_summary.php') : 'monthly_summary.php';
$pendingUrl = function_exists('site_url') ? site_url('/accounts/pending_fees.php') : 'pending_fees.php';
$expUrl = function_exists('site_url') ? site_url('/accounts/expenses.php') : 'expenses.php';
$collectUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : 'fees_collection.php';

$categoryLabels = [
    'Rent' => 'Rent / premises',
    'Salary' => 'Staff salary',
    'Electricity' => 'Electricity',
    'Water' => 'Water',
    'Housekeeping' => 'Housekeeping / cleaning',
    'Food' => 'Food / snacks / milk',
    'Learning' => 'Toys & learning material',
    'Stationery' => 'Stationery / printing',
    'Events' => 'Events / celebrations',
    'Transport' => 'Transport',
    'Maintenance' => 'Maintenance / repairs',
    'Internet' => 'Internet / phone',
    'Medical' => 'Medical / first aid',
    'Uniforms' => 'Uniforms / ID cards',
    'Marketing' => 'Marketing',
    'Licence' => 'Licence / government fees',
    'Other' => 'Other',
];

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));
$yearStart = date('Y-01-01');

$preset = trim((string) ($_GET['preset'] ?? ''));
if ($preset === 'today') {
    $from = $to = $today;
} elseif ($preset === 'month') {
    $from = $monthStart;
    $to = $monthEnd;
} elseif ($preset === 'last') {
    $from = $lastMonthStart;
    $to = $lastMonthEnd;
} elseif ($preset === 'year') {
    if (function_exists('ay_range')) {
        $ayr = ay_range();
        $from = $ayr['start'];
        $to = min($today, $ayr['end']);
    } else {
        $from = $yearStart;
        $to = $today;
    }
} else {
    $from = trim((string) ($_GET['from'] ?? $monthStart));
    $to = trim((string) ($_GET['to'] ?? $monthEnd));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $monthStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $monthEnd;
}
if ($to < $from) {
    $to = $from;
}
if (function_exists('ay_limit_dates')) {
    [$from, $to] = ay_limit_dates($from, $to);
}

$classId = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : 0;

$hasFees = function_exists('table_exists') && table_exists('fees_records');
$hasExp = function_exists('table_exists') && table_exists('expenses');
$hasStudents = function_exists('table_exists') && table_exists('students');
$hasDues = $hasStudents && function_exists('column_exists') && column_exists('students', 'dues_status');

$classes = (function_exists('table_exists') && table_exists('classes'))
    ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [])
    : [];

$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$feeWhere = ['fr.collected_at IS NOT NULL', 'fr.collected_at >= :from', 'fr.collected_at <= :to'];
$feeParams = [':from' => $fromDt, ':to' => $toDt];
if ($classId > 0) {
    $feeWhere[] = 's.class_id = :cid';
    $feeParams[':cid'] = $classId;
}
if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($feeWhere, $feeParams, 's');
}
$feeSql = 'WHERE ' . implode(' AND ', $feeWhere);

$receipts = $hasFees
    ? (safe_db_get_all(
        "SELECT fr.id, fr.receipt_no, fr.paid_amount, fr.collected_at,
                COALESCE(s.first_name,'') AS first_name,
                COALESCE(s.middle_name,'') AS middle_name,
                COALESCE(s.last_name,'') AS last_name,
                COALESCE(c.name,'') AS class_name
         FROM fees_records fr
         INNER JOIN students s ON s.id = fr.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         {$feeSql}
         ORDER BY fr.collected_at ASC, fr.id ASC",
        $feeParams
    ) ?: [])
    : [];

$expWhere = 'WHERE expense_date BETWEEN :from AND :to';
$expParams = [':from' => $from, ':to' => $to];
$expenses = $hasExp
    ? (safe_db_get_all(
        "SELECT id, title, amount, category, expense_date, payment_method
         FROM expenses {$expWhere}
         ORDER BY expense_date ASC, id ASC",
        $expParams
    ) ?: [])
    : [];

$totalIn = 0.0;
$byClass = [];
$byPay = [];
$byDay = [];
foreach ($receipts as $r) {
    $amt = (float) ($r['paid_amount'] ?? 0);
    $totalIn += $amt;
    $cls = trim((string) ($r['class_name'] ?? ''));
    if ($cls === '') {
        $cls = 'No class';
    }
    $byClass[$cls] = ($byClass[$cls] ?? 0) + $amt;
    $method = 'Other';
    $raw = (string) ($r['receipt_no'] ?? '');
    if (preg_match('/METHOD:([^|]+)/', $raw, $mm)) {
        $method = trim($mm[1]) ?: 'Other';
    }
    $byPay[$method] = ($byPay[$method] ?? 0) + $amt;
    $day = substr((string) ($r['collected_at'] ?? ''), 0, 10);
    if ($day === '') {
        $day = $from;
    }
    if (!isset($byDay[$day])) {
        $byDay[$day] = ['n' => 0, 'amt' => 0.0];
    }
    $byDay[$day]['n']++;
    $byDay[$day]['amt'] += $amt;
}
arsort($byClass);
arsort($byPay);
ksort($byDay);

$totalOut = 0.0;
$byCat = [];
foreach ($expenses as $ex) {
    $amt = (float) ($ex['amount'] ?? 0);
    $totalOut += $amt;
    $cat = trim((string) ($ex['category'] ?? ''));
    if ($cat === '') {
        $cat = 'Other';
    }
    $byCat[$cat] = ($byCat[$cat] ?? 0) + $amt;
}
arsort($byCat);

$net = $totalIn - $totalOut;

$pendingNow = 0.0;
$pendingKids = 0;
$pendingByClass = [];
if ($hasStudents && $hasFees) {
    $duesSql = $hasDues ? " AND LOWER(COALESCE(s.dues_status,'open')) <> 'written_off'" : '';
    $classSql = $classId > 0 ? ' AND s.class_id = :cid' : '';
    $pendParams = $classId > 0 ? [':cid' => $classId] : [];
    $ayPend = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
    $pendParams = function_exists('ay_params_student') ? ay_params_student($pendParams) : $pendParams;
    $pendRows = safe_db_get_all(
        "SELECT COALESCE(c.name,'No class') AS class_name,
                COALESCE(s.total_fees,0) AS total_fees,
                COALESCE((SELECT SUM(fr.paid_amount) FROM fees_records fr WHERE fr.student_id = s.id),0) AS paid
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') {$duesSql} {$classSql}
           AND {$ayPend}",
        $pendParams
    ) ?: [];
    foreach ($pendRows as $pr) {
        $due = max(0, (float) $pr['total_fees'] - (float) $pr['paid']);
        if ($due > 0.009) {
            $pendingNow += $due;
            $pendingKids++;
            $cn = (string) $pr['class_name'];
            $pendingByClass[$cn] = ($pendingByClass[$cn] ?? 0) + $due;
        }
    }
    arsort($pendingByClass);
}

$admissions = 0;
$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
if ($hasStudents) {
    $admCol = function_exists('column_exists') && column_exists('students', 'admission_date')
        ? 'admission_date' : 'created_at';
    $admParams = [':from' => $from, ':to' => $to];
    $classAdm = $classId > 0 ? ' AND s.class_id = :cid' : '';
    if ($classId > 0) {
        $admParams[':cid'] = $classId;
    }
    $admParams = function_exists('ay_params_student') ? ay_params_student($admParams) : $admParams;
    $adm = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s
         WHERE DATE({$admCol}) BETWEEN :from AND :to
           AND {$ayStu}
           {$classAdm}",
        $admParams
    );
    $admissions = (int) ($adm['c'] ?? 0);
}

$qs = static function (array $extra = []) use ($from, $to, $classId): string {
    $q = ['from' => $from, 'to' => $to];
    if ($classId > 0) {
        $q['class_id'] = $classId;
    }
    return http_build_query(array_merge($q, $extra));
};

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=accounts_report_' . $from . '_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Accounts report', $from, $to]);
    fputcsv($out, ['Fees collected', number_format($totalIn, 2, '.', ''), count($receipts) . ' receipts']);
    fputcsv($out, ['Expenses', number_format($totalOut, 2, '.', ''), count($expenses) . ' bills']);
    fputcsv($out, ['Leftover', number_format($net, 2, '.', '')]);
    fputcsv($out, ['Pending now', number_format($pendingNow, 2, '.', ''), $pendingKids . ' children']);
    fputcsv($out, ['Admissions in range', $admissions]);
    fputcsv($out, []);
    fputcsv($out, ['By day']);
    fputcsv($out, ['Date', 'Receipts', 'Collected']);
    foreach ($byDay as $d => $v) {
        fputcsv($out, [$d, $v['n'], number_format($v['amt'], 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['By class']);
    fputcsv($out, ['Class', 'Collected']);
    foreach ($byClass as $k => $v) {
        fputcsv($out, [$k, number_format($v, 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['How parents paid']);
    fputcsv($out, ['Method', 'Collected']);
    foreach ($byPay as $k => $v) {
        fputcsv($out, [$k, number_format($v, 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Expenses']);
    fputcsv($out, ['Type', 'Amount']);
    foreach ($byCat as $k => $v) {
        fputcsv($out, [$categoryLabels[$k] ?? $k, number_format($v, 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Pending now by class']);
    fputcsv($out, ['Class', 'Pending']);
    foreach ($pendingByClass as $k => $v) {
        fputcsv($out, [$k, number_format($v, 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$money = static function ($v): string {
    return function_exists('format_money') ? format_money($v) : ('₹ ' . number_format((float) $v, 2));
};

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.rp-stat { border: 0; border-radius: 12px; }
.rp-stat .n { font-size: 1.35rem; font-weight: 700; }
.rp-in { background: #ecfdf3; }
.rp-out { background: #fef2f2; }
.rp-net { background: #eff6ff; }
.rp-pend { background: #fff7ed; }
@media print {
  .rp-no-print, .sidebar, nav, .navbar, footer { display: none !important; }
}
</style>
<?php if ($isPrint): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Money report</h1>
    <p class="text-muted mb-0"><?php echo e(date('d M Y', strtotime($from))); ?> – <?php echo e(date('d M Y', strtotime($to))); ?>. Pick dates, then print or Excel.</p>
  </div>
  <div class="rp-no-print d-flex flex-wrap gap-2">
    <a class="btn btn-outline-success" href="<?php echo e($selfUrl . '?' . $qs(['export' => 'csv'])); ?>">Excel</a>
    <a class="btn btn-outline-primary" href="<?php echo e($selfUrl . '?' . $qs(['print' => '1'])); ?>" target="_blank" rel="noopener">Print</a>
  </div>
</div>

<form method="get" class="card card-body mb-3 rp-no-print">
  <div class="row g-2 align-items-end">
    <div class="col-sm-6 col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
    </div>
    <div class="col-sm-6 col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
    </div>
    <div class="col-sm-6 col-md-3">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select">
        <option value="">All classes</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int) $c['id']; ?>" <?php echo $classId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e((string) $c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6 col-md-5">
      <button class="btn btn-primary">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?preset=today'); ?>">Today</a>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?preset=month'); ?>">This month</a>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?preset=last'); ?>">Last month</a>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?preset=year'); ?>">This year</a>
    </div>
  </div>
</form>

<p class="small text-muted rp-no-print mb-3">
  Day-by-day list → <a href="<?php echo e($dailyUrl); ?>">Daily Collection</a>
  · Month snapshot → <a href="<?php echo e($monthUrl); ?>">Monthly Summary</a>
  · Who still owes → <a href="<?php echo e($pendingUrl); ?>">Pending Fees</a>
  · Bills → <a href="<?php echo e($expUrl); ?>">Expenses</a>
  · Take payment → <a href="<?php echo e($collectUrl); ?>">Collect Fees</a>
</p>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card rp-stat rp-in p-3 h-100">
      <div class="small text-muted">Fees collected</div>
      <div class="n text-success"><?php echo e($money($totalIn)); ?></div>
      <div class="small"><?php echo count($receipts); ?> receipts</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat rp-out p-3 h-100">
      <div class="small text-muted">Expenses</div>
      <div class="n text-danger"><?php echo e($money($totalOut)); ?></div>
      <div class="small"><?php echo count($expenses); ?> bills</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat rp-net p-3 h-100">
      <div class="small text-muted"><?php echo $net >= 0 ? 'Leftover' : 'Short'; ?></div>
      <div class="n <?php echo $net >= 0 ? 'text-primary' : 'text-danger'; ?>"><?php echo e($money($net)); ?></div>
      <div class="small text-muted">Fees minus expenses</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card rp-stat rp-pend p-3 h-100">
      <div class="small text-muted">Still pending now</div>
      <div class="n"><?php echo e($money($pendingNow)); ?></div>
      <div class="small"><?php echo (int) $pendingKids; ?> children · <?php echo (int) $admissions; ?> admissions in this range</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Collected by day</div>
      <div class="table-responsive" style="max-height:320px">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th class="text-end">Receipts</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php if ($byDay === []): ?>
            <tr><td colspan="3" class="text-muted p-3">No fees in this range.</td></tr>
          <?php else: foreach ($byDay as $d => $v): ?>
            <tr>
              <td><?php echo e(date('d M Y', strtotime((string) $d))); ?></td>
              <td class="text-end"><?php echo (int) $v['n']; ?></td>
              <td class="text-end"><?php echo e($money($v['amt'])); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Collected by class</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($byClass === []): ?>
            <tr><td class="text-muted p-3">No collections.</td></tr>
          <?php else: foreach ($byClass as $k => $v): ?>
            <tr><td><?php echo e($k); ?></td><td class="text-end"><?php echo e($money($v)); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">How parents paid</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($byPay === []): ?>
            <tr><td class="text-muted p-3">No payments.</td></tr>
          <?php else: foreach ($byPay as $k => $v): ?>
            <tr><td><?php echo e($k); ?></td><td class="text-end"><?php echo e($money($v)); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Where money went</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($byCat === []): ?>
            <tr><td class="text-muted p-3">No expenses in this range.</td></tr>
          <?php else: foreach ($byCat as $k => $v): ?>
            <tr><td><?php echo e($categoryLabels[$k] ?? $k); ?></td><td class="text-end"><?php echo e($money($v)); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Pending now by class</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($pendingByClass === []): ?>
            <tr><td class="text-muted p-3">Nobody owes fees.</td></tr>
          <?php else: foreach ($pendingByClass as $k => $v): ?>
            <tr><td><?php echo e($k); ?></td><td class="text-end"><?php echo e($money($v)); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
