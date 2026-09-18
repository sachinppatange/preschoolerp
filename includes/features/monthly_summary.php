<?php
/**
 * One-month preschool money snapshot: fees in, expenses out, leftover.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Monthly Summary');
$pageTitle = $page_title;

$selfPath = $panel === 'accounts' ? '/accounts/monthly_summary.php' : '/owner/monthly_summary.php';
$selfUrl = function_exists('site_url') ? site_url($selfPath) : $selfPath;
$dailyUrl = function_exists('site_url') ? site_url($panel === 'accounts' ? '/accounts/daily_collection.php' : '/owner/daily_collection.php') : '../accounts/daily_collection.php';
$feesUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : '../accounts/fees_collection.php';
$expPath = $panel === 'accounts' ? '/accounts/expenses.php' : '/owner/expense.php';
$expUrl = function_exists('site_url') ? site_url($expPath) : $expPath;
$pendingUrl = function_exists('site_url') ? site_url($panel === 'accounts' ? '/accounts/pending_fees.php' : '/owner/pending_fees.php') : '../accounts/pending_fees.php';

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

$ym = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
    $ym = date('Y-m');
    $year = (int) date('Y');
    $month = (int) date('n');
} else {
    $year = (int) $m[1];
    $month = (int) $m[2];
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        $year = (int) date('Y');
        $month = (int) date('n');
        $ym = sprintf('%04d-%02d', $year, $month);
    }
}

$fromDate = sprintf('%04d-%02d-01', $year, $month);
$toDate = date('Y-m-t', strtotime($fromDate));
$monthLabel = date('F Y', strtotime($fromDate));
$prevYm = date('Y-m', strtotime($fromDate . ' -1 month'));
$nextYm = date('Y-m', strtotime($fromDate . ' +1 month'));
$fromDt = $fromDate . ' 00:00:00';
$toDt = $toDate . ' 23:59:59';

$hasFees = function_exists('table_exists') && table_exists('fees_records');
$hasExp = function_exists('table_exists') && table_exists('expenses');
$hasStudents = function_exists('table_exists') && table_exists('students');

$feeReceipts = $hasFees
    ? (safe_db_get_all(
        "SELECT fr.id, fr.receipt_no, fr.paid_amount, fr.collected_at,
                COALESCE(s.first_name,'') AS first_name,
                COALESCE(s.middle_name,'') AS middle_name,
                COALESCE(s.last_name,'') AS last_name,
                COALESCE(c.name,'') AS class_name
         FROM fees_records fr
         LEFT JOIN students s ON s.id = fr.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE fr.collected_at IS NOT NULL
           AND fr.collected_at >= :from
           AND fr.collected_at <= :to
         ORDER BY fr.collected_at DESC, fr.id DESC",
        [':from' => $fromDt, ':to' => $toDt]
    ) ?: [])
    : [];

$expenses = $hasExp
    ? (safe_db_get_all(
        "SELECT id, title, amount, category, expense_date, payment_method, notes
         FROM expenses
         WHERE expense_date BETWEEN :from AND :to
         ORDER BY expense_date DESC, id DESC",
        [':from' => $fromDate, ':to' => $toDate]
    ) ?: [])
    : [];

$totalIn = 0.0;
$byClass = [];
$byPay = [];
foreach ($feeReceipts as $r) {
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
}
arsort($byClass);
arsort($byPay);

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
$receiptCount = count($feeReceipts);
$expenseCount = count($expenses);

$admissions = 0;
$activeKids = 0;
$pendingNow = 0.0;
$pendingKids = 0;
if ($hasStudents) {
    $admCol = function_exists('column_exists') && column_exists('students', 'admission_date')
        ? 'admission_date' : 'created_at';
    $admRow = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students
         WHERE DATE({$admCol}) BETWEEN :from AND :to
           AND LOWER(COALESCE(status,'active')) IN ('active','pending')",
        [':from' => $fromDate, ':to' => $toDate]
    );
    $admissions = (int) ($admRow['c'] ?? 0);

    $actRow = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students WHERE LOWER(COALESCE(status,'active')) = 'active'"
    );
    $activeKids = (int) ($actRow['c'] ?? 0);

    $hasDues = function_exists('column_exists') && column_exists('students', 'dues_status');
    $duesSql = $hasDues ? " AND LOWER(COALESCE(s.dues_status,'open')) <> 'written_off'" : '';
    $pendRows = $hasFees
        ? (safe_db_get_all(
            "SELECT s.id, COALESCE(s.total_fees,0) AS total_fees,
                    COALESCE((SELECT SUM(fr.paid_amount) FROM fees_records fr WHERE fr.student_id = s.id),0) AS paid
             FROM students s
             WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') {$duesSql}"
        ) ?: [])
        : [];
    foreach ($pendRows as $pr) {
        $due = max(0, (float) $pr['total_fees'] - (float) $pr['paid']);
        if ($due > 0.009) {
            $pendingNow += $due;
            $pendingKids++;
        }
    }
}

$stuName = static function (array $r): string {
    return trim(preg_replace('/\s+/', ' ', trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))));
};

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=monthly_summary_' . $ym . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Monthly summary', $monthLabel]);
    fputcsv($out, ['Fees collected', number_format($totalIn, 2, '.', ''), $receiptCount . ' receipts']);
    fputcsv($out, ['Expenses', number_format($totalOut, 2, '.', ''), $expenseCount . ' bills']);
    fputcsv($out, ['Leftover', number_format($net, 2, '.', '')]);
    fputcsv($out, ['New admissions this month', $admissions]);
    fputcsv($out, ['Active children now', $activeKids]);
    fputcsv($out, ['Pending fees now', number_format($pendingNow, 2, '.', ''), $pendingKids . ' children']);
    fputcsv($out, []);
    fputcsv($out, ['Fees by class']);
    fputcsv($out, ['Class', 'Amount']);
    foreach ($byClass as $k => $v) {
        fputcsv($out, [$k, number_format($v, 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Fees by payment']);
    fputcsv($out, ['Method', 'Amount']);
    foreach ($byPay as $k => $v) {
        fputcsv($out, [$k, number_format($v, 2, '.', '')]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Expenses by type']);
    fputcsv($out, ['Type', 'Amount']);
    foreach ($byCat as $k => $v) {
        fputcsv($out, [$categoryLabels[$k] ?? $k, number_format($v, 2, '.', '')]);
    }
    fclose($out);
    exit;
}

$printQs = http_build_query(['month' => $ym, 'print' => '1']);
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

require_once __DIR__ . '/../header.php';
?>
<style>
.ms-stat { border: 0; border-radius: 12px; }
.ms-stat .n { font-size: 1.45rem; font-weight: 700; }
.ms-in { background: #ecfdf3; }
.ms-out { background: #fef2f2; }
.ms-net-ok { background: #eff6ff; }
.ms-net-bad { background: #fff7ed; }
.ms-mini { background: #f8fafc; }
@media print {
  .ms-no-print, .sidebar, nav, .navbar, footer { display: none !important; }
  .ms-stat { border: 1px solid #ccc !important; }
}
</style>

<?php if ($isPrint): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Monthly summary</h1>
    <p class="text-muted mb-0">How much came in and went out in <strong><?php echo e($monthLabel); ?></strong>.</p>
  </div>
  <div class="ms-no-print d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?month=' . $prevYm); ?>">← Previous</a>
    <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl . '?month=' . $nextYm); ?>">Next →</a>
    <a class="btn btn-outline-success" href="<?php echo e($selfUrl . '?' . http_build_query(['month' => $ym, 'export' => 'csv'])); ?>">Excel</a>
    <a class="btn btn-outline-primary" href="<?php echo e($selfUrl . '?' . $printQs); ?>" target="_blank" rel="noopener">Print</a>
  </div>
</div>

<form method="get" class="card card-body mb-3 ms-no-print">
  <div class="row g-2 align-items-end">
    <div class="col-sm-4 col-md-3">
      <label class="form-label">Month</label>
      <input type="month" name="month" class="form-control" value="<?php echo e($ym); ?>">
    </div>
    <div class="col-sm-8 col-md-9">
      <button class="btn btn-primary">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">This month</a>
      <a class="btn btn-outline-primary" href="<?php echo e($feesUrl); ?>">Collect fees</a>
      <a class="btn btn-outline-danger" href="<?php echo e($expUrl); ?>">Add expense</a>
    </div>
  </div>
</form>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card ms-stat ms-in p-3 h-100">
      <div class="text-muted small">Fees collected</div>
      <div class="n text-success">₹ <?php echo number_format($totalIn, 2); ?></div>
      <div class="small"><?php echo (int) $receiptCount; ?> receipts
        · <a href="<?php echo e($dailyUrl . '?' . http_build_query(['from' => $fromDate, 'to' => $toDate])); ?>">See list</a>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card ms-stat ms-out p-3 h-100">
      <div class="text-muted small">School expenses</div>
      <div class="n text-danger">₹ <?php echo number_format($totalOut, 2); ?></div>
      <div class="small"><?php echo (int) $expenseCount; ?> bills
        · <a href="<?php echo e($expUrl . '?' . http_build_query(['from' => $fromDate, 'to' => $toDate])); ?>">See list</a>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card ms-stat <?php echo $net >= 0 ? 'ms-net-ok' : 'ms-net-bad'; ?> p-3 h-100">
      <div class="text-muted small"><?php echo $net >= 0 ? 'Leftover this month' : 'Short this month'; ?></div>
      <div class="n <?php echo $net >= 0 ? 'text-primary' : 'text-danger'; ?>">₹ <?php echo number_format($net, 2); ?></div>
      <div class="small text-muted">Fees minus expenses</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card ms-mini p-3 h-100">
      <div class="small text-muted">New admissions</div>
      <div class="fs-4 fw-semibold"><?php echo (int) $admissions; ?></div>
      <div class="small text-muted">this month</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card ms-mini p-3 h-100">
      <div class="small text-muted">Children on roll</div>
      <div class="fs-4 fw-semibold"><?php echo (int) $activeKids; ?></div>
      <div class="small text-muted">active now</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card ms-mini p-3 h-100">
      <div class="small text-muted">Pending fees now</div>
      <div class="fs-5 fw-semibold">₹ <?php echo number_format($pendingNow, 0); ?></div>
      <div class="small"><a href="<?php echo e($pendingUrl); ?>"><?php echo (int) $pendingKids; ?> children</a></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card ms-mini p-3 h-100">
      <div class="small text-muted">Average receipt</div>
      <div class="fs-5 fw-semibold">₹ <?php echo $receiptCount ? number_format($totalIn / $receiptCount, 0) : '0'; ?></div>
      <div class="small text-muted">this month</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Fees by class</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <tbody>
          <?php if ($byClass === []): ?>
            <tr><td class="text-muted p-3">No fees collected this month.</td></tr>
          <?php else: foreach ($byClass as $k => $v): ?>
            <tr>
              <td><?php echo e($k); ?></td>
              <td class="text-end">₹ <?php echo number_format($v, 2); ?></td>
            </tr>
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
            <tr><td class="text-muted p-3">No payments this month.</td></tr>
          <?php else: foreach ($byPay as $k => $v): ?>
            <tr>
              <td><?php echo e($k); ?></td>
              <td class="text-end">₹ <?php echo number_format($v, 2); ?></td>
            </tr>
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
            <tr><td class="text-muted p-3">No expenses this month.</td></tr>
          <?php else: foreach ($byCat as $k => $v): ?>
            <tr>
              <td><?php echo e($categoryLabels[$k] ?? $k); ?></td>
              <td class="text-end">₹ <?php echo number_format($v, 2); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white d-flex justify-content-between">
        <span class="fw-semibold">Latest fees</span>
        <a class="small ms-no-print" href="<?php echo e($dailyUrl . '?' . http_build_query(['from' => $fromDate, 'to' => $toDate])); ?>">Full cash book</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Child</th><th class="text-end">Paid</th></tr></thead>
          <tbody>
          <?php
          $latestFees = array_slice($feeReceipts, 0, 8);
          if ($latestFees === []):
          ?>
            <tr><td colspan="3" class="text-muted p-3">Nothing collected yet.</td></tr>
          <?php else: foreach ($latestFees as $r): ?>
            <tr>
              <td><?php echo e(substr((string) ($r['collected_at'] ?? ''), 0, 10)); ?></td>
              <td><?php echo e($stuName($r) !== '' ? $stuName($r) : '—'); ?>
                <div class="small text-muted"><?php echo e($r['class_name'] ?? ''); ?></div>
              </td>
              <td class="text-end">₹ <?php echo number_format((float) $r['paid_amount'], 2); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header bg-white d-flex justify-content-between">
        <span class="fw-semibold">Latest expenses</span>
        <a class="small ms-no-print" href="<?php echo e($expUrl . '?' . http_build_query(['from' => $fromDate, 'to' => $toDate])); ?>">All expenses</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Bill</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php
          $latestExp = array_slice($expenses, 0, 8);
          if ($latestExp === []):
          ?>
            <tr><td colspan="3" class="text-muted p-3">No bills this month.</td></tr>
          <?php else: foreach ($latestExp as $ex): ?>
            <tr>
              <td><?php echo e((string) ($ex['expense_date'] ?? '')); ?></td>
              <td><?php echo e((string) ($ex['title'] ?? '')); ?>
                <div class="small text-muted"><?php echo e($categoryLabels[$ex['category'] ?? ''] ?? ($ex['category'] ?? '')); ?></div>
              </td>
              <td class="text-end">₹ <?php echo number_format((float) $ex['amount'], 2); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../footer.php';
