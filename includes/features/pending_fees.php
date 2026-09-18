<?php
/**
 * Who still owes fees this academic year — collect from here.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Pending Fees');
$pageTitle = $page_title;

$selfPath = $panel === 'accounts' ? '/accounts/pending_fees.php' : '/owner/pending_fees.php';
$selfUrl = function_exists('site_url') ? site_url($selfPath) : $selfPath;
$collectUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : '../accounts/fees_collection.php';
$receiptUrl = function_exists('site_url') ? site_url('/accounts/receipt_print.php') : '../accounts/receipt_print.php';

if (!function_exists('pending_fees_name')) {
    function pending_fees_name(array $s): string
    {
        $parts = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $k) {
            $t = trim((string) ($s[$k] ?? ''));
            if ($t === '') {
                continue;
            }
            $low = mb_strtolower($t, 'UTF-8');
            foreach ($parts as $p) {
                if (mb_strtolower($p, 'UTF-8') === $low) {
                    continue 2;
                }
            }
            $parts[] = $t;
        }
        return trim(implode(' ', $parts));
    }
}

$feesOk = function_exists('table_exists') && table_exists('fees_records');
$frCols = ($feesOk && function_exists('get_table_columns')) ? get_table_columns('fees_records') : [];
$hasFrAy = in_array('academic_year', $frCols, true);
$hasCollectedAt = in_array('collected_at', $frCols, true);

$availableYears = function_exists('ay_list') ? ay_list() : [];
$defaultAY = function_exists('ay_selected') ? ay_selected() : '';

$q = trim((string) ($_GET['q'] ?? ''));
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : 0;
$academicYearFilter = isset($_GET['academic_year']) && $_GET['academic_year'] !== ''
    ? trim((string) $_GET['academic_year'])
    : $defaultAY;
if ($academicYearFilter !== '' && $availableYears !== [] && !in_array($academicYearFilter, $availableYears, true)) {
    $academicYearFilter = $defaultAY;
}
$showPaid = isset($_GET['show_paid']) && $_GET['show_paid'] === '1';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;

$where = [];
$params = [];
if ($academicYearFilter !== '') {
    $where[] = 's.academic_year = :ay';
    $params[':ay'] = $academicYearFilter;
}
if ($q !== '') {
    $where[] = "CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q";
    $params[':q'] = '%' . $q . '%';
}
if ($classFilter > 0) {
    $where[] = 's.class_id = :class_id';
    $params[':class_id'] = $classFilter;
}
$whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

$studentParams = $params;
$paidJoin = '';
$paidSelect = '0';
if ($feesOk) {
    $paidWhere = '';
    if ($academicYearFilter !== '' && $hasFrAy) {
        $paidWhere = ' AND academic_year = :pay';
        $params[':pay'] = $academicYearFilter;
    } elseif ($academicYearFilter !== '' && $hasCollectedAt && function_exists('ay_to_range')) {
        $range = ay_to_range($academicYearFilter);
        if ($range) {
            $paidWhere = ' AND collected_at BETWEEN :dstart AND :dend';
            $params[':dstart'] = $range['start'];
            $params[':dend'] = $range['end'] . ' 23:59:59';
        }
    }
    $paidJoin = "LEFT JOIN (
        SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum
        FROM fees_records
        WHERE student_id IS NOT NULL {$paidWhere}
        GROUP BY student_id
    ) p ON p.student_id = s.id";
    $paidSelect = 'COALESCE(p.paid_sum,0)';
}

$pendingExpr = "GREATEST(0, COALESCE(s.total_fees,0) - {$paidSelect})";
$listWhere = $whereSql;
if (!$showPaid) {
    $listWhere .= ($listWhere === '' ? 'WHERE ' : ' AND ') . "({$pendingExpr}) > 0.009";
}

$classJoin = (function_exists('table_exists') && table_exists('classes'))
    ? 'LEFT JOIN classes c ON c.id = s.class_id'
    : '';
$classSelect = $classJoin !== '' ? "COALESCE(c.name,'') AS class_name" : "'' AS class_name";

$fromSql = "FROM students s {$paidJoin} {$classJoin} {$listWhere}";

$totalRow = safe_db_get_one(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM({$pendingExpr}),0) AS pending_sum FROM students s {$paidJoin} {$listWhere}",
    $params
) ?: ['cnt' => 0, 'pending_sum' => 0];
$totalRows = (int) ($totalRow['cnt'] ?? 0);
$grandPending = (float) ($totalRow['pending_sum'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$students = safe_db_get_all(
    "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.academic_year,
            COALESCE(s.total_fees,0) AS total_fees, {$paidSelect} AS paid_sum, {$classSelect}
     {$fromSql}
     ORDER BY ({$pendingExpr}) DESC, s.first_name ASC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
) ?: [];

$collectedToday = 0.0;
if ($feesOk && $hasCollectedAt) {
    $todaySql = "SELECT COALESCE(SUM(fr.paid_amount),0) AS c
        FROM fees_records fr
        INNER JOIN students s ON s.id = fr.student_id
        {$whereSql}" . ($whereSql === '' ? 'WHERE ' : ' AND ') . 'DATE(fr.collected_at) = CURDATE()';
    $tr = safe_db_get_one($todaySql, $studentParams);
    $collectedToday = $tr ? (float) ($tr['c'] ?? 0) : 0.0;
}

$pageIds = array_map(static fn ($r) => (int) $r['id'], $students);
$paymentsGrouped = [];
if ($feesOk && $pageIds !== []) {
    $ph = [];
    $pp = [];
    foreach ($pageIds as $i => $id) {
        $k = ':pid' . $i;
        $ph[] = $k;
        $pp[$k] = $id;
    }
    $payCols = 'id, student_id, paid_amount, collected_at, receipt_no';
    if ($hasFrAy) {
        $payCols .= ', academic_year';
    }
    $payRows = safe_db_get_all(
        "SELECT {$payCols} FROM fees_records WHERE student_id IN (" . implode(',', $ph) . ') ORDER BY collected_at DESC, id DESC',
        $pp
    ) ?: [];
    foreach ($payRows as $pr) {
        $paymentsGrouped[(int) $pr['student_id']][] = $pr;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pending_fees_' . date('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Class', 'Year', 'Total', 'Paid', 'Pending']);
    $all = safe_db_get_all(
        "SELECT s.first_name, s.middle_name, s.last_name, s.academic_year,
                COALESCE(s.total_fees,0) AS total_fees, {$paidSelect} AS paid_sum, {$classSelect}
         {$fromSql}
         ORDER BY ({$pendingExpr}) DESC, s.first_name ASC",
        $params
    ) ?: [];
    foreach ($all as $a) {
        $total = (float) $a['total_fees'];
        $paid = (float) $a['paid_sum'];
        fputcsv($out, [
            pending_fees_name($a),
            $a['class_name'] ?? '',
            $a['academic_year'] ?? '',
            number_format($total, 2, '.', ''),
            number_format($paid, 2, '.', ''),
            number_format(max(0, $total - $paid), 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

$classList = (function_exists('table_exists') && table_exists('classes'))
    ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [])
    : [];

$filterQs = array_filter([
    'q' => $q !== '' ? $q : null,
    'class_id' => $classFilter > 0 ? (string) $classFilter : null,
    'academic_year' => $academicYearFilter !== '' ? $academicYearFilter : null,
    'show_paid' => $showPaid ? '1' : null,
], static fn ($v) => $v !== null);

require_once __DIR__ . '/../header.php';
?>
<style>
.pf-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:1rem 1.1rem; height:100%; }
.pf-card .lbl { color:#64748b; font-size:.82rem; }
.pf-card .num { font-size:1.45rem; font-weight:800; color:#0d3b8c; }
.pf-card .num.owed { color:#b42318; }
.pf-card .num.ok { color:#137a3a; }
.pf-hint { color:#64748b; font-size:.9rem; margin-bottom:1rem; }
</style>

<p class="pf-hint">Students who still have a balance. Open <strong>Collect</strong> to take payment and print a receipt.</p>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="pf-card">
      <div class="lbl">Still pending</div>
      <div class="num owed"><?php echo (int) $totalRows; ?></div>
      <div class="lbl"><?php echo $showPaid ? 'students in this list' : 'students with dues'; ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="pf-card">
      <div class="lbl">Amount due</div>
      <div class="num owed">₹ <?php echo number_format($grandPending, 2); ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="pf-card">
      <div class="lbl">Collected today</div>
      <div class="num ok">₹ <?php echo number_format($collectedToday, 2); ?></div>
    </div>
  </div>
</div>

<div class="pf-card mb-3">
  <form method="get" class="row g-2 align-items-end">
    <?php if (count($availableYears) > 1): ?>
    <div class="col-md-3">
      <label class="form-label">Year</label>
      <select name="academic_year" class="form-select">
        <?php foreach ($availableYears as $ay): ?>
          <option value="<?php echo e($ay); ?>" <?php echo $academicYearFilter === $ay ? 'selected' : ''; ?>><?php echo e($ay); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-md-3">
      <label class="form-label">Student</label>
      <input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Search name">
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
    <div class="col-md-2">
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="show_paid" value="1" id="showPaid" <?php echo $showPaid ? 'checked' : ''; ?>>
        <label class="form-check-label" for="showPaid">Include paid</label>
      </div>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">Reset</a>
    </div>
  </form>
</div>

<div class="pf-card">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold mb-0">Dues list</h2>
    <a class="btn btn-sm btn-outline-secondary" href="?<?php echo e(http_build_query(array_merge($filterQs, ['export' => 'csv']))); ?>">Download CSV</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead>
        <tr>
          <th>Student</th>
          <th>Class</th>
          <th class="text-end">Total</th>
          <th class="text-end">Paid</th>
          <th class="text-end">Pending</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($students === []): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Nobody has pending fees for this filter. Tick “Include paid” to see everyone.</td></tr>
        <?php endif; ?>
        <?php foreach ($students as $s):
            $sid = (int) $s['id'];
            $total = (float) $s['total_fees'];
            $paid = (float) $s['paid_sum'];
            $pending = max(0.0, $total - $paid);
            $name = pending_fees_name($s);
            $collectHref = $collectUrl . (str_contains($collectUrl, '?') ? '&' : '?') . 'student_id=' . $sid;
            $viewBase = $receiptUrl . (str_contains($receiptUrl, '?') ? '&' : '?');
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo e($name !== '' ? $name : ('Student #' . $sid)); ?></div>
              <?php if ($paymentsGrouped[$sid] ?? []): ?>
                <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#pay-<?php echo $sid; ?>">Receipts</button>
              <?php endif; ?>
            </td>
            <td><?php echo e((string) ($s['class_name'] ?: '—')); ?></td>
            <td class="text-end">₹ <?php echo number_format($total, 2); ?></td>
            <td class="text-end">₹ <?php echo number_format($paid, 2); ?></td>
            <td class="text-end fw-bold <?php echo $pending > 0.009 ? 'text-danger' : 'text-success'; ?>">₹ <?php echo number_format($pending, 2); ?></td>
            <td class="text-nowrap text-end">
              <a class="btn btn-sm btn-primary" href="<?php echo e($collectHref); ?>">Collect</a>
            </td>
          </tr>
          <?php if (!empty($paymentsGrouped[$sid])): ?>
          <tr>
            <td colspan="6" class="p-0 border-0">
              <div class="collapse" id="pay-<?php echo $sid; ?>">
              <div class="bg-light p-2">
              <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>Amount</th><th>Receipt</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($paymentsGrouped[$sid] as $pr):
                      $rid = (int) $pr['id'];
                      $rc = (string) ($pr['receipt_no'] ?? '');
                      if (str_contains($rc, '||')) {
                          $rc = explode('||', $rc, 2)[0];
                      }
                  ?>
                    <tr>
                      <td><?php echo e(substr((string) ($pr['collected_at'] ?? ''), 0, 16)); ?></td>
                      <td>₹ <?php echo number_format((float) $pr['paid_amount'], 2); ?></td>
                      <td><?php echo e($rc); ?></td>
                      <td class="text-nowrap">
                        <a href="<?php echo e($viewBase . 'id=' . $rid); ?>" target="_blank" rel="noopener">View</a>
                        · <a href="<?php echo e($viewBase . 'id=' . $rid . '&pdf=1'); ?>">PDF</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center pt-3">
      <div class="small text-muted"><?php echo (int) $totalRows; ?> students</div>
      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
              <a class="page-link" href="?<?php echo e(http_build_query(array_merge($filterQs, ['page' => $p]))); ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/../footer.php';
