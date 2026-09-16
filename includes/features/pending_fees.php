<?php
/**
 * Shared feature: pending_fees
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

require_once __DIR__ . '/../cms/helpers.php';

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');
$pageTitle = (string)($cfg['page_title'] ?? 'Pending Fees');

if (!function_exists('build_full_name')) {
    function build_full_name(?string $first, ?string $middle, ?string $last): string {
        $first = trim((string)$first);
        $middle = trim((string)$middle);
        $last = trim((string)$last);
        $lc_first = mb_strtolower(preg_replace('/\s+/', ' ', $first));
        $lc_middle = mb_strtolower(preg_replace('/\s+/', ' ', $middle));
        $lc_last = mb_strtolower(preg_replace('/\s+/', ' ', $last));
        $parts = [];
        if ($first !== '') $parts[] = $first;
        $add_middle = false;
        if ($middle !== '') {
            $already_in_first = $lc_middle !== '' && (mb_stripos($lc_first, $lc_middle) !== false);
            $already_in_last = $lc_middle !== '' && (mb_stripos($lc_last, $lc_middle) !== false);
            if (!$already_in_first && !$already_in_last) $add_middle = true;
        }
        if ($add_middle) $parts[] = $middle;
        if ($last !== '') {
            $already = false;
            foreach ($parts as $p) {
                if (mb_strtolower($p) === $lc_last) { $already = true; break; }
            }
            if (!$already) $parts[] = $last;
        }
        return trim(implode(' ', $parts));
    }
}


/* Optional includes */
/* ---------- Payment table detection ---------- */
$feesRecordsExists = table_exists('fees_records');
$feeCollectionsExists = table_exists('fee_collections');

if (!function_exists('ay_to_range')) {
    require_once __DIR__ . '/../panel/academic_year.php';
}
function academic_year_to_dates(?string $ay): ?array {
    $range = ay_to_range($ay);
    return $range ? [$range['start'], $range['end']] : null;
}

$availableYears = ay_list();
$defaultAY = ay_selected();

/* ---------- Request / Filters ---------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$q = trim((string)($_GET['q'] ?? ''));
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
$schoolFilter = isset($_GET['school_id']) && $_GET['school_id'] !== '' ? (int)$_GET['school_id'] : null;
$academicYearFilter = isset($_GET['academic_year']) && $_GET['academic_year'] !== ''
    ? trim((string) $_GET['academic_year'])
    : $defaultAY;
$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';
if ($academicYearFilter !== '' && !in_array($academicYearFilter, $availableYears, true)) {
    $academicYearFilter = $defaultAY;
}

/* Build WHERE for students (user asked: selecting year shows only that year's students) */
$where = []; $params = [];
if ($q !== '') { $where[] = "(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE :q)"; $params[':q'] = '%'.$q.'%'; }
if ($classFilter !== null) { $where[] = "s.class_id = :class_id"; $params[':class_id'] = $classFilter; }
if ($schoolFilter !== null) { $where[] = "s.school_id = :school_id"; $params[':school_id'] = $schoolFilter; }
if ($academicYearFilter !== '') { $where[] = "s.academic_year = :ay"; $params[':ay'] = $academicYearFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- Fetch matched student ids (for grand totals / collected today) ---------- */
$matchedStudentIds = [];
try {
    $rows = safe_db_get_all("SELECT s.id FROM students s " . ($whereSql ? $whereSql : ''), $params);
    foreach ($rows as $r) $matchedStudentIds[] = (int)$r['id'];
} catch (Throwable $e) {
    $matchedStudentIds = [];
}

/* ---------- Fetch page of students ---------- */
$students = [];
try {
    $sql = "SELECT s.id, s.school_id, s.class_id, COALESCE(s.total_fees,0) AS total_fees, s.first_name, s.middle_name, s.last_name, s.admission_date, s.academic_year
            FROM students s
            " . ($whereSql ? $whereSql : '') . "
            ORDER BY s.first_name ASC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $students = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $students = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $students = [];
    if ($DEBUG) error_log('student fetch failed: ' . $e->getMessage());
}

/* ---------- Year-aware aggregation and payments fetching ---------- */

/* Get paid sums per student restricted to selected academic year when possible */
function get_paid_sums_for_students_year(array $studentIds, string $academicYear): array {
    if (empty($studentIds)) return [];
    if (!table_exists('fees_records')) return [];
    $placeholders=[]; $params=[];
    foreach ($studentIds as $i=>$id) { $ph=':s'.$i; $placeholders[]=$ph; $params[$ph]=(int)$id; }
    $in = implode(',', $placeholders);

    $hasAYCol = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_records' AND COLUMN_NAME = 'academic_year' LIMIT 1");
    $hasCollectedAt = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_records' AND COLUMN_NAME = 'collected_at' LIMIT 1");

    if ($academicYear && $hasAYCol) {
        $params[':ay'] = $academicYear;
        $sql = "SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id IN ($in) AND academic_year = :ay GROUP BY student_id";
    } elseif ($academicYear && $hasCollectedAt) {
        $dates = academic_year_to_dates($academicYear);
        if ($dates !== null) {
            $params[':dstart'] = $dates[0];
            $params[':dend'] = $dates[1] . ' 23:59:59';
            $sql = "SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id IN ($in) AND collected_at BETWEEN :dstart AND :dend GROUP BY student_id";
        } else {
            $sql = "SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id IN ($in) GROUP BY student_id";
        }
    } else {
        $sql = "SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id IN ($in) GROUP BY student_id";
    }

    $rows = safe_db_get_all($sql, $params);
    $map = [];
    foreach ($rows as $r) $map[(int)$r['student_id']] = (float)$r['paid_sum'];
    return $map;
}

/* Get payment rows grouped by student for the selected year.
   After that, for students without rows, fetch fallback ALL payments grouped by student (so Payments panel never shows "No payment records..." unless table missing).
   Also return an array $paymentsFallbackUsed[student_id] = true if fallback used (i.e., no year-specific rows found).
*/
function get_payment_rows_with_fallback(array $studentIds, string $academicYear): array {
    $result = ['grouped'=>[], 'fallback_used'=>[]];
    if (empty($studentIds) || !table_exists('fees_records')) return $result;

    $placeholders=[]; $params=[];
    foreach ($studentIds as $i=>$id) { $ph=':s'.$i; $placeholders[]=$ph; $params[$ph]=(int)$id; }
    $in = implode(',', $placeholders);

    $hasAYCol = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_records' AND COLUMN_NAME = 'academic_year' LIMIT 1");
    $hasCollectedAt = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_records' AND COLUMN_NAME = 'collected_at' LIMIT 1");

    // 1) Try year-restricted rows
    if ($academicYear && $hasAYCol) {
        $params[':ay'] = $academicYear;
        $sql = "SELECT id, student_id, paid_amount, academic_year, collected_at, notes FROM fees_records WHERE student_id IN ($in) AND academic_year = :ay ORDER BY collected_at DESC, id DESC";
    } elseif ($academicYear && $hasCollectedAt) {
        $dates = academic_year_to_dates($academicYear);
        if ($dates !== null) {
            $params[':dstart'] = $dates[0];
            $params[':dend'] = $dates[1] . ' 23:59:59';
            $sql = "SELECT id, student_id, paid_amount, academic_year, collected_at, notes FROM fees_records WHERE student_id IN ($in) AND collected_at BETWEEN :dstart AND :dend ORDER BY collected_at DESC, id DESC";
        } else {
            // cannot parse year -> fall back to all rows for the students in step 2
            $sql = null;
        }
    } else {
        // cannot restrict by year -> we'll fetch all rows in fallback step
        $sql = null;
    }

    $grouped = [];
    if (!empty($sql)) {
        $rows = safe_db_get_all($sql, $params);
        foreach ($rows as $r) {
            $sid = (int)$r['student_id'];
            if (!isset($grouped[$sid])) $grouped[$sid] = [];
            $grouped[$sid][] = $r;
        }
    }

    // identify which students have no year-specific rows
    $missing = [];
    foreach ($studentIds as $sid) {
        if (!isset($grouped[$sid]) || count($grouped[$sid]) === 0) $missing[] = $sid;
    }

    // 2) For missing students, fetch ALL payments (fallback)
    if (!empty($missing)) {
        $placeholders2=[]; $params2=[];
        foreach ($missing as $i=>$id) { $ph=':m'.$i; $placeholders2[]=$ph; $params2[$ph]=(int)$id; }
        $in2 = implode(',', $placeholders2);
        $sql2 = "SELECT id, student_id, paid_amount, academic_year, collected_at, notes FROM fees_records WHERE student_id IN ($in2) ORDER BY collected_at DESC, id DESC";
        $rows2 = safe_db_get_all($sql2, $params2);
        foreach ($rows2 as $r) {
            $sid = (int)$r['student_id'];
            if (!isset($grouped[$sid])) $grouped[$sid] = [];
            $grouped[$sid][] = $r;
            $result['fallback_used'][$sid] = true;
        }
    }

    $result['grouped'] = $grouped;
    return $result;
}

/* ---------- Compute paid sums and payments for the page ---------- */
/* Grand totals: compute paid sums for all matched students using year-aware aggregation */
$grandPaidMap = $feesRecordsExists ? get_paid_sums_for_students_year($matchedStudentIds, $academicYearFilter) : [];
$grandTotalPending = 0.0;
foreach ($matchedStudentIds as $sid) {
    $row = safe_db_get_one("SELECT COALESCE(total_fees,0) AS tf FROM students WHERE id = :id LIMIT 1", [':id'=>$sid]);
    $tf = $row ? (float)$row['tf'] : 0.0;
    $paid = $grandPaidMap[$sid] ?? 0.0;
    $pending = max(0.0, $tf - $paid);
    $grandTotalPending += $pending;
}

/* For current page students compute paid sums and grouped payment rows with fallback info */
$pageStudentIds = array_column($students, 'id') ?: [];
$pagePaidMap = $feesRecordsExists ? get_paid_sums_for_students_year($pageStudentIds, $academicYearFilter) : [];
$paymentsData = $feesRecordsExists ? get_payment_rows_with_fallback($pageStudentIds, $academicYearFilter) : ['grouped'=>[], 'fallback_used'=>[]];
$paymentsGrouped = $paymentsData['grouped'];
$paymentsFallbackUsed = $paymentsData['fallback_used'];

/* Build list for display */
$list = []; $totalPendingSum = 0.0; $countPending = 0;
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $feeTotal = (float)($s['total_fees'] ?? 0.0);
    $paid = $pagePaidMap[$sid] ?? 0.0;
    $pending = max(0.0, $feeTotal - $paid);
    if ($pending > 0.0 || $showAll) {
        $list[] = [
            'id' => $sid,
            'name' => trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? '')),
            'class_id' => $s['class_id'] ?? null,
            'school_id' => $s['school_id'] ?? null,
            'academic_year' => $s['academic_year'] ?? '',
            'fee_total' => number_format($feeTotal, 2, '.', ''),
            'paid' => number_format($paid, 2, '.', ''),
            'pending' => number_format($pending, 2, '.', ''),
            'admission_date' => $s['admission_date'] ?? ''
        ];
    }
    if ($pending > 0.0) { $totalPendingSum += $pending; $countPending++; }
}

/* Collected Today (year-aware where possible) */
$collectedToday = 0.0;
if (!empty($matchedStudentIds)) {
    if ($feeCollectionsExists) {
        $hasAY = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_collections' AND COLUMN_NAME = 'academic_year' LIMIT 1");
        $placeholders=[]; $p2=[];
        foreach ($matchedStudentIds as $i=>$id) { $ph=':m'.$i; $placeholders[]=$ph; $p2[$ph]=(int)$id; }
        $in = implode(',', $placeholders);
        if ($academicYearFilter !== '' && $hasAY) {
            $p2[':ay'] = $academicYearFilter;
            $r = safe_db_get_one("SELECT COALESCE(SUM(amount),0) AS c FROM fee_collections WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in) AND academic_year = :ay", $p2);
        } else {
            $r = safe_db_get_one("SELECT COALESCE(SUM(amount),0) AS c FROM fee_collections WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in)", $p2);
        }
        $collectedToday = $r ? (float)$r['c'] : 0.0;
    } elseif ($feesRecordsExists) {
        $hasAY = (bool) safe_db_get_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees_records' AND COLUMN_NAME = 'academic_year' LIMIT 1");
        $placeholders=[]; $p2=[];
        foreach ($matchedStudentIds as $i=>$id) { $ph=':m'.$i; $placeholders[]=$ph; $p2[$ph]=(int)$id; }
        $in = implode(',', $placeholders);
        if ($academicYearFilter !== '' && $hasAY) {
            $p2[':ay'] = $academicYearFilter;
            $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS c FROM fees_records WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in) AND academic_year = :ay", $p2);
        } elseif ($academicYearFilter !== '') {
            $dates = academic_year_to_dates($academicYearFilter);
            if ($dates) { $p2[':dstart']=$dates[0]; $p2[':dend']=$dates[1].' 23:59:59'; $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS c FROM fees_records WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in) AND collected_at BETWEEN :dstart AND :dend", $p2); }
            else { $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS c FROM fees_records WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in)", $p2); }
        } else {
            $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS c FROM fees_records WHERE DATE(collected_at)=CURDATE() AND student_id IN ($in)", $p2);
        }
        $collectedToday = $r ? (float)$r['c'] : 0.0;
    }
}

/* CSV export (uses same year-aware paid amounts for selected year) */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pending_fees_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID','Name','Academic Year','Class ID','School ID','Fee Total','Paid (selected year)','Pending','Admission Date']);
    try {
        $all = safe_db_get_all("SELECT s.id, s.first_name, s.middle_name, s.last_name, s.academic_year, s.class_id, s.school_id, COALESCE(s.total_fees,0) AS total_fees, s.admission_date FROM students s " . ($whereSql ? $whereSql : '') . " ORDER BY s.first_name ASC", $params);
        $ids = array_map(function($r){ return (int)$r['id']; }, $all);
        $paidMapAll = $feesRecordsExists ? get_paid_sums_for_students_year($ids, $academicYearFilter) : [];
        foreach ($all as $a) {
            $sid = (int)$a['id'];
            $feeTotal = (float)$a['total_fees'];
            $paid = $paidMapAll[$sid] ?? 0.0;
            $pending = max(0.0, $feeTotal - $paid);
            if ($pending > 0.0 || $showAll) {
                fputcsv($out, [
                    $sid,
                    trim(($a['first_name'] ?? '') . ' ' . ($a['middle_name'] ?? '') . ' ' . ($a['last_name'] ?? '')),
                    $a['academic_year'] ?? '',
                    $a['class_id'] ?? '',
                    $a['school_id'] ?? '',
                    number_format($feeTotal,2,'.',''),
                    number_format($paid,2,'.',''),
                    number_format($pending,2,'.',''),
                    $a['admission_date'] ?? ''
                ]);
            }
        }
    } catch (Throwable $e) {}
    fclose($out); exit;
}

/* For filters UI: class & school lists */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id,name FROM classes ORDER BY name ASC") : [];
$schoolList = table_exists('schools') ? safe_db_get_all("SELECT id,name FROM schools ORDER BY name ASC") : [];
$totalStudents = count($matchedStudentIds);
$totalPages = (int)ceil(max(1, $totalStudents) / $perPage);

/* ---------- Render UI (English) ---------- */
$pageTitle = 'Pending Fees';
require_once __DIR__ . '/../header.php';
?>

  <!-- Summary -->
  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="card summary-card">
        <div class="small-muted">Records</div>
        <div class="summary-value"><?php echo (int)$countPending; ?></div>
        <div class="small text-muted">Students with pending &gt; 0</div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card summary-card">
        <div class="small-muted">Total Pending (matched filters)</div>
        <div class="summary-value text-danger-amount">₹ <?php echo number_format($grandTotalPending,2,'.',','); ?></div>
        <div class="small text-muted">Calculated using payments restricted to selected academic year where possible</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card summary-card">
        <div class="small-muted">Collected Today</div>
        <div class="summary-value text-success">₹ <?php echo number_format($collectedToday,2,'.',','); ?></div>
        <div class="small text-muted"><?php echo $feeCollectionsExists ? 'From fee_collections (today)' : ( $feesRecordsExists ? 'From fees_records collected_at (today)' : 'Not available' ); ?></div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-3 p-3 no-print">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Academic Year</label>
        <select name="academic_year" class="form-select">
          <?php foreach ($availableYears as $ay): ?>
            <option value="<?php echo e($ay); ?>" <?php if ($academicYearFilter === $ay) echo 'selected'; ?>><?php echo e($ay); ?><?php if ($ay === $defaultAY) echo ' (default)'; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3"><label class="form-label">Search student</label><input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Name"></div>
      <div class="col-md-2"><label class="form-label">Class</label>
        <select name="class_id" class="form-select"><option value="">Any</option><?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if($classFilter === (int)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">School</label>
        <select name="school_id" class="form-select"><option value="">Any</option><?php foreach ($schoolList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if($schoolFilter === (int)$s['id']) echo 'selected'; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2 text-end">
        <button class="btn btn-primary">Filter</button>
        <a class="btn btn-outline-secondary" href="/pioneerplayschool01/accounts/pending_fees.php">Reset</a>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0">
        <thead>
          <tr>
            <th style="width:64px">ID</th>
            <th>Student</th>
            <th>Academic Year</th>
            <th>Class</th>
            <th class="text-end">Fee Total</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Pending</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($list)): foreach ($list as $r): $sid = (int)$r['id']; ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo e($r['name']); ?></div>
                <div class="small text-muted">ID: <?php echo (int)$r['id']; ?> • <?php echo e($r['admission_date']); ?></div>
              </td>
              <td><?php echo e($r['academic_year']); ?></td>
              <td><?php echo e($r['class_id']); ?></td>
              <td class="text-end">₹ <?php echo number_format((float)$r['fee_total'],2,'.',','); ?></td>
              <td class="text-end">₹ <?php echo number_format((float)$r['paid'],2,'.',','); ?></td>
              <td class="text-end fw-bold text-danger">₹ <?php echo number_format((float)$r['pending'],2,'.',','); ?></td>
              <td class="text-end">
                <?php if ($feesRecordsExists): ?>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#payments-<?php echo $sid; ?>">Payments</button>
                <?php else: ?>
                  <span class="small-muted">No payments</span>
                <?php endif; ?>
              </td>
            </tr>

            <?php if ($feesRecordsExists): ?>
            <tr>
              <td colspan="8" class="p-0">
                <div class="collapse" id="payments-<?php echo $sid; ?>">
                  <div class="p-3 bg-light">
                    <?php
                      $rows = $paymentsGrouped[$sid] ?? [];
                      $fallback = !empty($paymentsFallbackUsed[$sid]);
                      if (!empty($rows)):
                    ?>
                      <?php if ($fallback): ?>
                        <div class="fallback-note mb-2">No year-specific payments found — showing all payments for this student (fallback).</div>
                      <?php endif; ?>
                      <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Amount</th><th>Academic Year</th><th>Notes</th></tr></thead>
                        <tbody>
                          <?php foreach ($rows as $rr): ?>
                            <tr>
                              <td><?php echo e($rr['collected_at'] ?? ''); ?></td>
                              <td>₹ <?php echo number_format((float)$rr['paid_amount'],2,'.',','); ?></td>
                              <td><?php echo e($rr['academic_year'] ?? ''); ?></td>
                              <td><?php echo e($rr['notes'] ?? ''); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <div class="small text-muted">No payment records found for this student.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
            </tr>
            <?php endif; ?>

          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">No records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center no-print">
      <div>Showing <?php echo count($list) ? ($offset+1) : 0; ?> - <?php echo min($offset + count($students), $totalStudents); ?> of <?php echo $totalStudents; ?> students</div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$p])); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>

  <div class="mt-3 small text-muted no-print">
    Notes:
    <ul>
      <li>Payments panel shows entries from <code>fees_records</code> for the selected academic year when possible. If no year-specific records exist, the panel falls back to showing all payments for that student and indicates this.</li>
      <li>Paid and Pending numbers are computed using the same year-aware logic so the table matches the Payments view.</li>
    </ul>
  </div>
</div>

<?php
require_once __DIR__ . '/../footer.php';
?>
