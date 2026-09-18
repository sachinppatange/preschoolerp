<?php
/**
 * Today's cash book — money actually collected (not the collect form).
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Daily Collection');
$pageTitle = $page_title;

$selfPath = $panel === 'accounts' ? '/accounts/daily_collection.php' : '/owner/daily_collection.php';
$selfUrl = function_exists('site_url') ? site_url($selfPath) : $selfPath;
$collectUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : '../accounts/fees_collection.php';
$receiptUrl = function_exists('site_url') ? site_url('/accounts/receipt_print.php') : '../accounts/receipt_print.php';

if (!function_exists('table_exists') || !table_exists('fees_records')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">No fee receipts found in the database.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

if (!function_exists('dc_receipt_base')) {
    function dc_receipt_base(string $raw): string
    {
        return str_contains($raw, '||') ? explode('||', $raw, 2)[0] : $raw;
    }
    function dc_receipt_method(string $raw): string
    {
        if (preg_match('/METHOD:([^|]+)/', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

$today = date('Y-m-d');
$from = trim((string) ($_GET['from'] ?? $today));
$to = trim((string) ($_GET['to'] ?? $from));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $today;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $from;
}
if ($to < $from) {
    $to = $from;
}
if (function_exists('ay_limit_dates')) {
    [$from, $to] = ay_limit_dates($from, $to);
}
$q = trim((string) ($_GET['q'] ?? ''));
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : 0;

$where = ['fr.collected_at IS NOT NULL', 'fr.collected_at >= :from', 'fr.collected_at <= :to'];
$params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
if ($q !== '') {
    $where[] = "(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q OR fr.receipt_no LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($classFilter > 0) {
    $where[] = 's.class_id = :cid';
    $params[':cid'] = $classFilter;
}
if (function_exists('ay_apply_student_filter')) {
    ay_apply_student_filter($where, $params, 's');
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = safe_db_get_all(
    "SELECT fr.id, fr.receipt_no, fr.paid_amount, fr.collected_at,
            COALESCE(s.first_name,'') AS first_name, COALESCE(s.middle_name,'') AS middle_name, COALESCE(s.last_name,'') AS last_name,
            COALESCE(c.name,'') AS class_name, COALESCE(u.name,'') AS collector_name
     FROM fees_records fr
     INNER JOIN students s ON s.id = fr.student_id
     LEFT JOIN classes c ON c.id = s.class_id
     LEFT JOIN users u ON u.id = fr.collected_by
     {$whereSql}
     ORDER BY fr.collected_at DESC, fr.id DESC",
    $params
) ?: [];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=daily_collection_' . $from . '_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Student', 'Class', 'Receipt', 'Paid by', 'Amount', 'Collected by']);
    foreach ($rows as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $raw = (string) ($r['receipt_no'] ?? '');
        fputcsv($out, [
            substr((string) ($r['collected_at'] ?? ''), 0, 16),
            $name,
            $r['class_name'] ?? '',
            dc_receipt_base($raw),
            dc_receipt_method($raw),
            number_format((float) ($r['paid_amount'] ?? 0), 2, '.', ''),
            $r['collector_name'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$totalAmt = 0.0;
$byMethod = [];
foreach ($rows as $r) {
    $amt = (float) ($r['paid_amount'] ?? 0);
    $totalAmt += $amt;
    $m = dc_receipt_method((string) ($r['receipt_no'] ?? '')) ?: 'Other';
    $byMethod[$m] = ($byMethod[$m] ?? 0) + $amt;
}
arsort($byMethod);

$classList = table_exists('classes') ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: []) : [];

$filterQs = array_filter([
    'from' => $from,
    'to' => $to,
    'q' => $q !== '' ? $q : null,
    'class_id' => $classFilter > 0 ? (string) $classFilter : null,
], static fn ($v) => $v !== null);

require_once __DIR__ . '/../header.php';
?>
<style>
.dc-card { background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:14px 16px; margin-bottom:12px; }
.dc-stat { font-size:1.4rem; font-weight:800; color:#0d3b8c; }
</style>

<p class="text-muted mb-3">This is the <strong>cash book</strong> — money already collected. To take a new payment use <a href="<?php echo e($collectUrl); ?>">Collect Fees</a>.</p>

<div class="row g-2 mb-3">
  <div class="col-md-5">
    <div class="dc-card">
      <div class="small text-muted"><?php echo e($from === $to ? ('Collected on ' . $from) : ($from . ' to ' . $to)); ?></div>
      <div class="dc-stat">₹ <?php echo number_format($totalAmt, 2); ?></div>
      <div class="small"><?php echo count($rows); ?> receipts</div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="dc-card">
      <div class="small text-muted mb-1">How it was paid</div>
      <?php if ($byMethod === []): ?>
        <div class="text-muted">No receipts in this date range.</div>
      <?php else: ?>
        <?php foreach ($byMethod as $m => $a): ?>
          <div class="d-flex justify-content-between"><span><?php echo e($m); ?></span><strong>₹ <?php echo number_format($a, 2); ?></strong></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="dc-card">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="from" class="form-control" value="<?php echo e($from); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="to" class="form-control" value="<?php echo e($to); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Student / receipt</label>
      <input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Name or receipt no.">
    </div>
    <div class="col-md-2">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select">
        <option value="">All</option>
        <?php foreach ($classList as $c): ?>
          <option value="<?php echo (int) $c['id']; ?>" <?php echo $classFilter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e((string) $c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex flex-wrap gap-2">
      <button class="btn btn-primary" type="submit">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">Today</a>
      <a class="btn btn-outline-secondary" href="?<?php echo e(http_build_query(array_merge($filterQs, ['export' => 'csv']))); ?>">CSV</a>
    </div>
  </form>
</div>

<div class="dc-card">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>When</th><th>Student</th><th>Paid by</th><th class="text-end">Amount</th><th></th></tr></thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No collections for these dates. Open Collect Fees to take a payment.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $rid = (int) $r['id'];
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $raw = (string) ($r['receipt_no'] ?? '');
            $viewHref = $receiptUrl . (str_contains($receiptUrl, '?') ? '&' : '?') . 'id=' . $rid;
        ?>
          <tr>
            <td class="small text-muted"><?php echo e(substr((string) ($r['collected_at'] ?? ''), 0, 16)); ?></td>
            <td>
              <div class="fw-semibold"><?php echo e($name !== '' ? $name : 'Student'); ?></div>
              <div class="small text-muted"><?php echo e((string) ($r['class_name'] ?: '')); ?><?php echo $raw !== '' ? ' · ' . e(dc_receipt_base($raw)) : ''; ?></div>
            </td>
            <td><?php echo e(dc_receipt_method($raw) ?: '—'); ?></td>
            <td class="text-end fw-bold">₹ <?php echo number_format((float) ($r['paid_amount'] ?? 0), 2); ?></td>
            <td class="text-nowrap text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?php echo e($viewHref); ?>" target="_blank" rel="noopener">View</a>
              <a class="btn btn-sm btn-success" href="<?php echo e($viewHref . '&print=1'); ?>" target="_blank" rel="noopener">Print</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
require_once __DIR__ . '/../footer.php';
