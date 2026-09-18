<?php
/**
 * Accounts home — money today / this month, then collect or look up.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');

$page_title = 'Accounts';
$pageTitle = $page_title;
$lastUpdated = date('d M Y, h:i A');

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

$hasFees = function_exists('table_exists') && table_exists('fees_records');
$hasExp = function_exists('table_exists') && table_exists('expenses');
$hasStudents = function_exists('table_exists') && table_exists('students');
$hasDues = $hasStudents && function_exists('column_exists') && column_exists('students', 'dues_status');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$todayIn = 0.0;
$todayN = 0;
$monthIn = 0.0;
$monthOut = 0.0;
$pendingAmt = 0.0;
$pendingKids = 0;

if ($hasFees) {
    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(paid_amount),0) AS a, COUNT(*) AS n
         FROM fees_records
         WHERE collected_at IS NOT NULL
           AND collected_at >= :a AND collected_at <= :b",
        [':a' => $today . ' 00:00:00', ':b' => $today . ' 23:59:59']
    );
    $todayIn = (float) ($r['a'] ?? 0);
    $todayN = (int) ($r['n'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(paid_amount),0) AS a
         FROM fees_records
         WHERE collected_at IS NOT NULL
           AND collected_at >= :a AND collected_at <= :b",
        [':a' => $monthStart . ' 00:00:00', ':b' => $monthEnd . ' 23:59:59']
    );
    $monthIn = (float) ($r['a'] ?? 0);
}

if ($hasExp) {
    $r = safe_db_get_one(
        'SELECT COALESCE(SUM(amount),0) AS a FROM expenses WHERE expense_date BETWEEN :a AND :b',
        [':a' => $monthStart, ':b' => $monthEnd]
    );
    $monthOut = (float) ($r['a'] ?? 0);
}

$monthNet = $monthIn - $monthOut;

$dueStudents = [];
if ($hasStudents && $hasFees) {
    $duesSql = $hasDues ? " AND LOWER(COALESCE(s.dues_status,'open')) <> 'written_off'" : '';
    $tot = safe_db_get_one(
        "SELECT COALESCE(SUM(GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0)),0) AS amt,
                COALESCE(SUM(CASE WHEN GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0) > 0.009 THEN 1 ELSE 0 END),0) AS n
         FROM students s
         LEFT JOIN (
            SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id
         ) fr_sum ON fr_sum.student_id = s.id
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') {$duesSql}"
    );
    $pendingAmt = (float) ($tot['amt'] ?? 0);
    $pendingKids = (int) ($tot['n'] ?? 0);
    $dueStudents = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, COALESCE(c.name,'') AS class_name,
                GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0) AS pending
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         LEFT JOIN (
            SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id
         ) fr_sum ON fr_sum.student_id = s.id
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') {$duesSql}
         HAVING pending > 0.009
         ORDER BY pending DESC
         LIMIT 8"
    ) ?: [];
}

$stuName = static function (array $s): string {
    $n = trim(preg_replace('/\s+/', ' ', trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))));
    return $n !== '' ? $n : 'Student';
};

$payMethod = static function (string $raw): string {
    if (preg_match('/METHOD:([^|]+)/', $raw, $m)) {
        return trim($m[1]);
    }
    return '';
};

$recentPay = $hasFees
    ? (safe_db_get_all(
        "SELECT fr.id, fr.paid_amount, fr.collected_at, fr.receipt_no,
                COALESCE(s.first_name,'') AS first_name,
                COALESCE(s.middle_name,'') AS middle_name,
                COALESCE(s.last_name,'') AS last_name,
                COALESCE(c.name,'') AS class_name
         FROM fees_records fr
         LEFT JOIN students s ON s.id = fr.student_id
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE fr.collected_at IS NOT NULL
         ORDER BY fr.collected_at DESC, fr.id DESC
         LIMIT 8"
    ) ?: [])
    : [];

$recentExp = $hasExp
    ? (safe_db_get_all(
        'SELECT id, title, amount, expense_date, category FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 6'
    ) ?: [])
    : [];

$money = static function ($v): string {
    return function_exists('format_money') ? format_money($v) : ('₹ ' . number_format((float) $v, 2));
};

$collectUrl = $u('/accounts/fees_collection.php');
$bulkUrl = $u('/accounts/fees_collectionbulk.php');
$dailyUrl = $u('/accounts/daily_collection.php');
$pendingUrl = $u('/accounts/pending_fees.php');
$expUrl = $u('/accounts/expenses.php');
$monthUrl = $u('/accounts/monthly_summary.php');
$reportUrl = $u('/accounts/reports.php');
$receiptUrl = $u('/accounts/receipt_print.php');

require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?php echo htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/')); ?>/assets/css/dashboard-cards.css" rel="stylesheet">

<div class="dc-page">
  <div class="dc-ay-bar">
    <div class="dc-ay-chip"><i class="bi bi-cash-coin"></i> Accounts · <?php echo e(date('d M Y')); ?> · Updated <?php echo e($lastUpdated); ?></div>
  </div>

  <section class="dc-section">
    <h2 class="dc-section-title">Do this now</h2>
    <div class="row g-2 mb-2">
      <div class="col-md-6">
        <a href="<?php echo e($collectUrl); ?>" class="btn btn-success w-100 py-3 fw-semibold">
          <i class="bi bi-person-check me-1"></i> Collect from one child
        </a>
      </div>
      <div class="col-md-6">
        <a href="<?php echo e($bulkUrl); ?>" class="btn btn-outline-success w-100 py-3 fw-semibold">
          <i class="bi bi-people me-1"></i> Collect from a class
        </a>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Money snapshot</h2>
    <div class="dc-grid dc-grid-4">
      <a href="<?php echo e($dailyUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--pink"><i class="bi bi-cash-stack"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Collected today</span>
            <span class="dc-card-value"><?php echo e($money($todayIn)); ?></span>
            <span class="dc-card-meta"><?php echo (int) $todayN; ?> receipts</span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($monthUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--green"><i class="bi bi-calendar3"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">This month in</span>
            <span class="dc-card-value"><?php echo e($money($monthIn)); ?></span>
            <span class="dc-card-meta">Fees · <?php echo e(date('M Y')); ?></span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($expUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--rose"><i class="bi bi-wallet2"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">This month out</span>
            <span class="dc-card-value"><?php echo e($money($monthOut)); ?></span>
            <span class="dc-card-meta">Expenses</span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($pendingUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-hourglass-split"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Still pending</span>
            <span class="dc-card-value dc-card-value--warn"><?php echo e($money($pendingAmt)); ?></span>
            <span class="dc-card-meta"><?php echo (int) $pendingKids; ?> children</span>
          </div>
        </div>
      </a>
    </div>
    <div class="dc-card mt-3">
      <div class="dc-card-top">
        <div class="dc-card-icon dc-card-icon--sky"><i class="bi bi-piggy-bank-fill"></i></div>
        <div class="dc-card-info">
          <span class="dc-card-label">Leftover this month</span>
          <span class="dc-card-value <?php echo $monthNet >= 0 ? 'dc-card-value--ok' : 'dc-card-value--bad'; ?>"><?php echo e($money($monthNet)); ?></span>
          <span class="dc-card-meta">Fees minus expenses · <a href="<?php echo e($reportUrl); ?>">Full report</a></span>
        </div>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Other pages</h2>
    <div class="row g-2">
      <?php
      $quick = [
          [$dailyUrl, 'bi-calendar-day', 'Daily collection'],
          [$pendingUrl, 'bi-clock-history', 'Pending fees'],
          [$expUrl, 'bi-receipt', 'Add expense'],
          [$monthUrl, 'bi-calendar-check', 'Monthly summary'],
          [$reportUrl, 'bi-bar-chart-line', 'Reports'],
      ];
      foreach ($quick as $q): ?>
        <div class="col-6 col-md-4 col-lg">
          <a href="<?php echo e($q[0]); ?>" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi <?php echo e($q[1]); ?> fs-4"></i>
            <span class="small fw-semibold"><?php echo e($q[2]); ?></span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dc-section">
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Latest receipts</span>
          <a href="<?php echo e($dailyUrl); ?>" class="dc-section-link">All</a>
        </div>
        <?php if ($recentPay === []): ?>
          <p class="dc-card-meta mb-0">No receipts yet. Collect fees to see them here.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($recentPay as $p): ?>
              <li class="d-flex justify-content-between gap-2 py-2 border-bottom">
                <div>
                  <div class="fw-semibold"><?php echo e($stuName($p)); ?></div>
                  <div class="small text-muted"><?php echo e($p['class_name'] ?? ''); ?>
                    <?php $pm = $payMethod((string) ($p['receipt_no'] ?? '')); echo $pm !== '' ? ' · ' . e($pm) : ''; ?>
                    · <?php echo e(substr((string) ($p['collected_at'] ?? ''), 0, 10)); ?>
                  </div>
                </div>
                <div class="text-end">
                  <div class="fw-semibold"><?php echo e($money($p['paid_amount'] ?? 0)); ?></div>
                  <a class="small" href="<?php echo e($receiptUrl . '?id=' . (int) $p['id']); ?>" target="_blank" rel="noopener">Receipt</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Highest pending</span>
          <a href="<?php echo e($pendingUrl); ?>" class="dc-section-link">All pending</a>
        </div>
        <?php if ($dueStudents === []): ?>
          <p class="dc-card-meta mb-0">Nobody owes fees right now.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($dueStudents as $s): ?>
              <li class="d-flex justify-content-between gap-2 py-2 border-bottom">
                <div>
                  <div class="fw-semibold"><?php echo e($stuName($s)); ?></div>
                  <div class="small text-muted"><?php echo e($s['class_name'] ?? ''); ?></div>
                </div>
                <div class="text-end">
                  <div class="fw-semibold text-warning"><?php echo e($money($s['pending'])); ?></div>
                  <a class="small" href="<?php echo e($collectUrl . '?student_id=' . (int) $s['id']); ?>">Collect</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <div class="dc-card dc-card--panel">
      <div class="dc-card-header">
        <span class="dc-card-label">Latest expenses</span>
        <a href="<?php echo e($expUrl); ?>" class="dc-section-link">All bills</a>
      </div>
      <?php if ($recentExp === []): ?>
        <p class="dc-card-meta mb-0">No expenses yet.</p>
      <?php else: ?>
        <ul class="list-unstyled mb-0">
          <?php foreach ($recentExp as $ex): ?>
            <li class="d-flex justify-content-between gap-2 py-2 border-bottom">
              <div>
                <div class="fw-semibold"><?php echo e((string) ($ex['title'] ?? 'Expense')); ?></div>
                <div class="small text-muted"><?php echo e((string) ($ex['category'] ?? '')); ?> · <?php echo e((string) ($ex['expense_date'] ?? '')); ?></div>
              </div>
              <div class="fw-semibold"><?php echo e($money($ex['amount'] ?? 0)); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
