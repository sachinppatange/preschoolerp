<?php
/**
 * Reports index — where to look for money, children, and parent messages.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$pageTitle = 'Reports';
$page_title = $pageTitle;

$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';
$n = static function (string $sql, array $params = []): int {
    try {
        $row = function_exists('safe_db_get_one') ? safe_db_get_one($sql, $params) : null;
        return (int) ($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
};

$has = static function (string $table): bool {
    return function_exists('table_exists') && table_exists($table);
};

$pendingFees = 0.0;
if ($has('students') && $has('fees_records')) {
    try {
        $row = safe_db_get_one(
            "SELECT COALESCE(SUM(GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr_sum.paid_sum,0),0)),0) AS p
             FROM students s
             LEFT JOIN (SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records GROUP BY student_id) fr_sum
               ON fr_sum.student_id = s.id
             WHERE s.status = 'active'"
        );
        $pendingFees = (float) ($row['p'] ?? 0);
    } catch (Throwable $e) {
        $pendingFees = 0.0;
    }
}

$openComplaints = $has('complaints')
    ? $n("SELECT COUNT(*) AS c FROM complaints WHERE LOWER(COALESCE(status,'open')) <> 'closed'")
    : 0;
$newFeedback = $has('feedbacks')
    ? $n('SELECT COUNT(*) AS c FROM feedbacks WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
    : 0;

$money = static function (float $v): string {
    return function_exists('format_money') ? format_money($v) : ('₹ ' . number_format($v, 0));
};

$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => $ownerBase . '/dashboard.php'],
    ['label' => 'Reports'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h4 mb-1">Reports</h1>
<p class="text-muted mb-4">Pick what you want to see. This page does not calculate extra charts.</p>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <a class="text-decoration-none" href="<?php echo e($ownerBase); ?>/pending_fees.php">
      <div class="card metric-card h-100">
        <div class="card-body">
          <div class="small text-muted">Fees still pending</div>
          <div class="fs-4 fw-bold text-danger"><?php echo e($money($pendingFees)); ?></div>
          <div class="small">Open the list →</div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a class="text-decoration-none" href="<?php echo e($ownerBase); ?>/complaints.php">
      <div class="card metric-card h-100">
        <div class="card-body">
          <div class="small text-muted">Complaints still open</div>
          <div class="fs-4 fw-bold"><?php echo (int) $openComplaints; ?></div>
          <div class="small">Read and close →</div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a class="text-decoration-none" href="<?php echo e($ownerBase); ?>/feedbacks.php">
      <div class="card metric-card h-100">
        <div class="card-body">
          <div class="small text-muted">New feedback (30 days)</div>
          <div class="fs-4 fw-bold"><?php echo (int) $newFeedback; ?></div>
          <div class="small">Read messages →</div>
        </div>
      </div>
    </a>
  </div>
</div>

<div class="card metric-card">
  <div class="card-body p-0">
    <div class="list-group list-group-flush">
      <a class="list-group-item list-group-item-action py-3" href="<?php echo e($ownerBase); ?>/daily_collection.php">
        <div class="fw-semibold">Today’s fees</div>
        <div class="small text-muted">Who paid today</div>
      </a>
      <a class="list-group-item list-group-item-action py-3" href="<?php echo e($ownerBase); ?>/monthly_summary.php">
        <div class="fw-semibold">This month</div>
        <div class="small text-muted">Fees in vs school expenses</div>
      </a>
      <a class="list-group-item list-group-item-action py-3" href="<?php echo e($ownerBase); ?>/year_end_report.php">
        <div class="fw-semibold">This school year</div>
        <div class="small text-muted"><?php echo e(function_exists('ay_period_phrase') ? ay_period_phrase() : 'School year'); ?> children and fees (print if needed)</div>
      </a>
      <a class="list-group-item list-group-item-action py-3" href="<?php echo e($ownerBase); ?>/month_reports.php">
        <div class="fw-semibold">Month reports to parents</div>
        <div class="small text-muted">Send or print each child’s month note</div>
      </a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
