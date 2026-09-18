<?php
/**
 * accounts/dashboard.php
 *
 * Accounts Dashboard for Pioneer Play School.
 * - Mobile-first responsive UI using Bootstrap 5
 * - Shows accounts/finance-focused metrics and quick actions
 * - Prefers project includes (config.php, db.php, functions.php) when available
 * - Uses safe DB helpers with PDO fallbacks and guards to avoid redeclare errors
 * - Session auth key: $_SESSION['accounts_auth_user'] or $_SESSION['accounts_user_id']
 *
 * Place at: /pioneerplayschool01/accounts/dashboard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
$DEBUG = panel_debug();

$accountsUserId = auth_user_id();

/* ---------- Detect tables ---------- */
function table_exists(string $name): bool {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t' => $name]);
    return !empty($r['cnt']);
}

$hasFees = table_exists('fees_records');
$hasExpenses = table_exists('expenses');
$hasStudents = table_exists('students');
$hasNotices = table_exists('notices');
$hasInvoices = table_exists('invoices'); // optional
$hasPayments = $hasFees; // treat fees_records as payments source

/* ---------- Gather metrics (accounts-focused) ---------- */
$metrics = [
    'todayCollection' => 0.0,
    'monthCollection' => 0.0,
    'pendingFees' => 0.0,
    'dueInvoices' => 0,
    'expenseThisMonth' => 0.0,
    'totalStudents' => 0,
];

if ($hasFees) {
    // Today's collection: paid_amount where collected_at date is today OR created_at today and paid_amount>0
    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(paid_amount),0) AS amt FROM fees_records
         WHERE paid_amount>0 AND ((collected_at IS NOT NULL AND DATE(collected_at)=CURDATE()) OR (collected_at IS NULL AND DATE(created_at)=CURDATE()))"
    );
    $metrics['todayCollection'] = (float)($r['amt'] ?? 0);

    // Month collection
    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(paid_amount),0) AS amt FROM fees_records
         WHERE paid_amount>0 AND ((collected_at IS NOT NULL AND MONTH(collected_at)=MONTH(CURDATE()) AND YEAR(collected_at)=YEAR(CURDATE()))
         OR (collected_at IS NULL AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())))"
    );
    $metrics['monthCollection'] = (float)($r['amt'] ?? 0);

    // Pending fees (due)
    $r = safe_db_get_one(
        "SELECT COALESCE(SUM(GREATEST(0, amount - COALESCE(paid_amount,0))),0) AS due FROM fees_records WHERE (amount - COALESCE(paid_amount,0)) > 0"
    );
    $metrics['pendingFees'] = (float)($r['due'] ?? 0);
}

if ($hasInvoices) {
    // Count due invoices
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM invoices WHERE status IN ('due','unpaid')");
    $metrics['dueInvoices'] = intval($r['cnt'] ?? 0);
} else {
    // fallback: count fee records with due date in past and unpaid
    if ($hasFees) {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM fees_records WHERE (amount - COALESCE(paid_amount,0)) > 0 AND (due_date IS NOT NULL AND due_date < CURDATE())");
        $metrics['dueInvoices'] = intval($r['cnt'] ?? 0);
    }
}

if ($hasExpenses) {
    $r = safe_db_get_one("SELECT COALESCE(SUM(amount),0) AS s FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())");
    $metrics['expenseThisMonth'] = (float)($r['s'] ?? 0);
}

if ($hasStudents) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE status = 'active'");
    $metrics['totalStudents'] = intval($r['cnt'] ?? 0);
}

/* ---------- Recent items ---------- */
$recent = [
    'payments' => [],
    'expenses' => [],
    'pending_fees' => []
];

if ($hasFees) {
    $recent['payments'] = safe_db_get_all("SELECT id, student_id, paid_amount, payment_method, collected_at, created_at FROM fees_records ORDER BY COALESCE(collected_at, created_at) DESC LIMIT 8");
    $recent['pending_fees'] = safe_db_get_all("SELECT id, student_id, amount, COALESCE(paid_amount,0) AS paid_amount, due_date FROM fees_records WHERE (amount - COALESCE(paid_amount,0)) > 0 ORDER BY due_date IS NULL, due_date ASC LIMIT 8");
}
if ($hasExpenses) {
    $recent['expenses'] = safe_db_get_all("SELECT id, title, amount, expense_date, created_at FROM expenses ORDER BY expense_date DESC LIMIT 8");
}

$page_title = 'Accounts Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

  <!-- Quick Access -->
  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <h6 class="mb-0">Quick Access</h6>
          <div class="small-muted">Common accounts tasks</div>
        </div>
        <div class="d-none d-md-block">
          <a class="btn btn-sm btn-outline-primary" href="#metrics">Jump to metrics</a>
        </div>
      </div>

      <div class="row gy-2">
        <?php
        $quick = [
          ['../accounts/daily_collection.php','bi-cash-stack','Daily Collection'],
          ['../accounts/pending_fees.php','bi-clock-history','Pending Fees'],
          ['../accounts/monthly_summary.php','bi-calendar-check','Monthly Summary'],
          ['../accounts/expenses.php','bi-wallet2','Expenses'],
          ['../accounts/reports.php','bi-bar-chart-line','Reports'],
          // Added Fees Collection button that points to demopreschoolapp path as requested
          ['../accounts/fees_collection.php','bi-receipt','Fees Collection']
        ];
        foreach ($quick as $q) {
            ?>
            <div class="col-12 col-md-6">
              <a class="btn btn-outline-primary quick-btn d-flex align-items-center" href="<?php echo e($q[0]); ?>">
                <span class="d-flex align-items-center"><i class="bi <?php echo e($q[1]); ?> fs-5 me-2"></i><span><?php echo e($q[2]); ?></span></span>
                <i class="bi bi-chevron-right"></i>
              </a>
            </div>
            <?php
        }
        ?>
      </div>
    </div>
  </div>

  <!-- Metrics -->
  <section id="metrics" class="mb-4">
    <div class="row g-3">

      <div class="col-6 col-md-4 col-lg-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Today's Collection</div>
            <div class="h5 mb-1"><?php echo e(format_money($metrics['todayCollection'])); ?></div>
            <div class="small-muted">Collections today</div>
            <div class="mt-2"><a href="<?php echo e((site_url('/accounts/daily_collection.php'))); ?>" class="btn btn-sm btn-outline-primary w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-4 col-lg-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">This Month Collection</div>
            <div class="h5 mb-1"><?php echo e(format_money($metrics['monthCollection'])); ?></div>
            <div class="small-muted">Current month</div>
            <div class="mt-2"><a href="<?php echo e((site_url('/accounts/monthly_summary.php'))); ?>" class="btn btn-sm btn-outline-info w-100 btn-compact">Summary</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-4 col-lg-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Fees</div>
            <div class="h5 mb-1"><?php echo e(format_money($metrics['pendingFees'])); ?></div>
            <div class="small-muted">Total due</div>
            <div class="mt-2"><a href="<?php echo e((site_url('/accounts/pending_fees.php'))); ?>" class="btn btn-sm btn-outline-warning w-100 btn-compact">View</a></div>
          </div>
        </div>
      </div>

      <!-- Expenses This Month -->
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Expenses This Month</div>
            <div class="h5 mb-1"><?php echo e(format_money($metrics['expenseThisMonth'])); ?></div>
            <div class="small-muted">Outflow</div>
            <div class="mt-2"><a href="<?php echo e((site_url('/accounts/expenses.php'))); ?>" class="btn btn-sm btn-outline-danger w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <!-- Total Students -->
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Total Students</div>
            <div class="h5 mb-1"><?php echo e(number_format($metrics['totalStudents'])); ?></div>
            <div class="small-muted">Active</div>
            <div class="mt-2"><a href="<?php echo e((site_url('/accounts/students_list.php'))); ?>" class="btn btn-sm btn-outline-secondary w-100 btn-compact">Students</a></div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- Recent panels -->
  <div class="row g-3">

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Payments</h6>
          <?php if (!empty($recent['payments'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['payments'] as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold">Student: <?php echo e($p['student_id'] ?? '—'); ?></div>
                    <div class="small-muted"><?php echo e($p['payment_method'] ?? '—'); ?> • <?php echo e(format_money((float)($p['paid_amount'] ?? 0))); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e(substr(($p['collected_at'] ?? $p['created_at'] ?? ''),0,16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent payments</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="<?php echo e((site_url('/accounts/daily_collection.php'))); ?>">View all</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Pending Fees</h6>
          <?php if (!empty($recent['pending_fees'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['pending_fees'] as $pf): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold">Student: <?php echo e($pf['student_id'] ?? '—'); ?></div>
                    <div class="small-muted">Due: <?php echo e(format_money((float)(($pf['amount'] ?? 0) - ($pf['paid_amount'] ?? 0)))); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e($pf['due_date'] ?? '—'); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No pending fees</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-warning btn-compact" href="<?php echo e((site_url('/accounts/pending_fees.php'))); ?>">Manage</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Expenses</h6>
          <?php if (!empty($recent['expenses'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['expenses'] as $ex): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?php echo e(mb_strimwidth($ex['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                    <div class="small-muted"><?php echo e(format_money((float)($ex['amount'] ?? 0))); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e(substr($ex['expense_date'] ?? $ex['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent expenses</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-danger btn-compact" href="<?php echo e((site_url('/accounts/expenses.php'))); ?>">Open</a></div>
        </div>
      </div>
    </div>

  </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>