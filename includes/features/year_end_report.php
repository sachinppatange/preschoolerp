<?php
/**
 * One-page numbers for a June–May school year (print from the browser).
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$pageTitle = (string) ($cfg['page_title'] ?? 'Year summary');
$page_title = $pageTitle;

$years = function_exists('ay_list') ? ay_list() : [];
$ay = trim((string) ($_GET['ay'] ?? (function_exists('ay_selected') ? ay_selected() : '')));
if (!function_exists('ay_is_valid') || !ay_is_valid($ay)) {
    $ay = function_exists('ay_selected') ? ay_selected() : $ay;
}

$stats = function_exists('ay_compute_stats') ? ay_compute_stats($ay) : [
    'active_students' => 0,
    'admissions' => 0,
    'alumni' => 0,
    'pending' => 0.0,
    'collected' => 0.0,
    'expenses' => 0.0,
    'enquiries' => 0,
    'by_class' => [],
];
$range = function_exists('ay_to_range') ? ay_to_range($ay) : null;
$ayLabel = function_exists('ay_display_short') ? ay_display_short($ay) : $ay;

$schoolName = defined('APP_NAME') ? APP_NAME : 'Preschool';
try {
    $row = function_exists('safe_db_get_one')
        ? safe_db_get_one('SELECT name FROM schools WHERE id = 1 LIMIT 1')
        : null;
    if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
        $schoolName = trim((string) $row['name']);
    }
} catch (Throwable $e) {
}

$money = static function ($n): string {
    return function_exists('format_money') ? format_money((float) $n) : ('₹ ' . number_format((float) $n, 2));
};

$active = (int) ($stats['active_students'] ?? 0);
$left = (int) ($stats['alumni'] ?? 0);
$collected = (float) ($stats['collected'] ?? 0);
$pending = (float) ($stats['pending'] ?? 0);
$expenses = (float) ($stats['expenses'] ?? 0);
$net = $collected - $expenses;
$byClass = is_array($stats['by_class'] ?? null) ? $stats['by_class'] : [];

$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => $ownerBase . '/dashboard.php'],
    ['label' => 'Year summary'],
];
require_once __DIR__ . '/../header.php';
?>
<style>
.yer-box { border: 0; border-radius: 12px; background: #f8fafc; }
.yer-box .n { font-size: 1.45rem; font-weight: 700; }
.yer-in { background: #ecfdf3; }
.yer-out { background: #fef2f2; }
.yer-wait { background: #fff7ed; }
.yer-kids { background: #eff6ff; }
@media print {
  .yer-no-print, .sidebar, nav, .navbar, footer, .breadcrumb { display: none !important; }
  .yer-box { border: 1px solid #ccc !important; }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Year summary</h1>
    <p class="text-muted mb-0">
      Children and fees for <strong><?php echo e($ayLabel); ?></strong>
      <?php if (is_array($range)): ?>
        <span>(<?php echo e(date('M Y', strtotime((string) $range['start']))); ?> – <?php echo e(date('M Y', strtotime((string) $range['end']))); ?>)</span>
      <?php endif; ?>
      . Print this if you need a copy for the file.
    </p>
  </div>
  <button type="button" class="btn btn-outline-primary yer-no-print" onclick="window.print()">Print</button>
</div>

<form method="get" class="card card-body mb-3 yer-no-print">
  <div class="row g-2 align-items-end">
    <div class="col-sm-6 col-md-4">
      <label class="form-label">School year</label>
      <select name="ay" class="form-select" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?php echo e($y); ?>"<?php echo $y === $ay ? ' selected' : ''; ?>><?php echo e(function_exists('ay_display_short') ? ay_display_short($y) : $y); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<div class="small text-muted mb-2 d-none d-print-block"><?php echo e($schoolName); ?> · <?php echo e($ayLabel); ?></div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card yer-box yer-kids p-3 h-100">
      <div class="text-muted small">Children in school</div>
      <div class="n"><?php echo $active; ?></div>
      <?php if ($left > 0): ?>
        <div class="small text-muted"><?php echo $left; ?> left this year</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card yer-box yer-in p-3 h-100">
      <div class="text-muted small">Fees received</div>
      <div class="n text-success"><?php echo e($money($collected)); ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card yer-box yer-wait p-3 h-100">
      <div class="text-muted small">Fees still pending</div>
      <div class="n text-danger"><?php echo e($money($pending)); ?></div>
    </div>
  </div>
</div>

<?php if ($expenses > 0): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="card yer-box yer-out p-3 h-100">
        <div class="text-muted small">School expenses</div>
        <div class="n"><?php echo e($money($expenses)); ?></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card yer-box p-3 h-100">
        <div class="text-muted small"><?php echo $net >= 0 ? 'Fees minus expenses' : 'Expenses more than fees'; ?></div>
        <div class="n <?php echo $net >= 0 ? 'text-primary' : 'text-danger'; ?>"><?php echo e($money($net)); ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($byClass !== []): ?>
  <div class="card metric-card">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-2">By class</h2>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Class</th><th class="text-end">Children</th></tr></thead>
          <tbody>
            <?php foreach ($byClass as $bc): ?>
              <tr>
                <td><?php echo e((string) ($bc['name'] ?? '')); ?></td>
                <td class="text-end"><?php echo (int) ($bc['count'] ?? 0); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="fw-semibold"><td>Total</td><td class="text-end"><?php echo $active; ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<p class="small text-muted mt-3 yer-no-print mb-0">
  Day-to-day fees: <a href="<?php echo e($ownerBase); ?>/monthly_summary.php">Monthly summary</a>
  · Move children to next year: <a href="<?php echo e($ownerBase); ?>/academic_year_hub.php">New school year</a>
</p>

<?php require_once __DIR__ . '/../footer.php'; ?>
