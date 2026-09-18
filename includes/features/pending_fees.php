<?php
/**
 * Pending fees — current students, left with dues, write-off.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = 'Pending Fees';
$pageTitle = $page_title;

$selfPath = $panel === 'accounts' ? '/accounts/pending_fees.php' : '/owner/pending_fees.php';
$selfUrl = function_exists('site_url') ? site_url($selfPath) : $selfPath;
$collectUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : '../accounts/fees_collection.php';
$receiptUrl = function_exists('site_url') ? site_url('/accounts/receipt_print.php') : '../accounts/receipt_print.php';
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (function_exists('column_exists') && function_exists('safe_db_run') && !column_exists('students', 'dues_status')) {
    safe_db_run("ALTER TABLE students ADD COLUMN dues_status VARCHAR(20) NOT NULL DEFAULT 'open'");
}

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

if (!function_exists('pending_fees_phone')) {
    function pending_fees_phone(array $s): string
    {
        foreach (['father_phone', 'mother_phone', 'guardian_phone'] as $k) {
            $d = preg_replace('/\D+/', '', (string) ($s[$k] ?? '')) ?? '';
            if (strlen($d) >= 10) {
                return substr($d, -10);
            }
        }
        return '';
    }
}

$hasDues = function_exists('column_exists') && column_exists('students', 'dues_status');
$feesOk = function_exists('table_exists') && table_exists('fees_records');
$availableYears = function_exists('ay_list') ? ay_list() : [];
$defaultAY = function_exists('ay_selected') ? ay_selected() : '';
$schoolName = defined('APP_NAME') ? (string) APP_NAME : 'School';
$sch = (function_exists('table_exists') && table_exists('schools'))
    ? safe_db_get_one('SELECT name FROM schools ORDER BY id ASC LIMIT 1')
    : null;
if (!empty($sch['name'])) {
    $schoolName = (string) $sch['name'];
}

$q = trim((string) ($_GET['q'] ?? ''));
$classFilter = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : 0;
$academicYearFilter = isset($_GET['academic_year']) && $_GET['academic_year'] !== ''
    ? trim((string) $_GET['academic_year'])
    : $defaultAY;
if ($academicYearFilter !== '' && $availableYears !== [] && !in_array($academicYearFilter, $availableYears, true)) {
    $academicYearFilter = $defaultAY;
}
$view = (string) ($_GET['view'] ?? 'due');
if (!in_array($view, ['due', 'left', 'written'], true)) {
    $view = 'due';
}

$messages = [];
$errors = [];
if (!empty($_SESSION['pf_ok'])) {
    $messages[] = (string) $_SESSION['pf_ok'];
    unset($_SESSION['pf_ok']);
}
if (!empty($_SESSION['pf_err'])) {
    $errors[] = (string) $_SESSION['pf_err'];
    unset($_SESSION['pf_err']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenOk = function_exists('validate_csrf_token')
        ? validate_csrf_token((string) ($_POST['csrf'] ?? ''))
        : hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf'] ?? ''));
    $sid = (int) ($_POST['student_id'] ?? 0);
    $act = (string) ($_POST['do'] ?? '');
    if (!$tokenOk) {
        $_SESSION['pf_err'] = 'Please reload the page and try again.';
    } elseif ($sid <= 0) {
        $_SESSION['pf_err'] = 'Student not found.';
    } elseif (function_exists('ay_can_edit') && !ay_can_edit() && $act !== 'remind') {
        $_SESSION['pf_err'] = 'This academic year is locked.';
    } else {
        $stu = safe_db_get_one('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $sid]);
        if (!$stu) {
            $_SESSION['pf_err'] = 'Student not found.';
        } elseif ($act === 'left') {
            $sql = $hasDues
                ? "UPDATE students SET status = 'inactive', dues_status = 'open', updated_at = NOW() WHERE id = :id"
                : "UPDATE students SET status = 'inactive', updated_at = NOW() WHERE id = :id";
            safe_db_run($sql, [':id' => $sid]);
            $_SESSION['pf_ok'] = pending_fees_name($stu) . ' marked as Left. Record is kept. Remaining fees stay as dues until you Collect or Write off.';
        } elseif ($act === 'writeoff') {
            $sql = $hasDues
                ? "UPDATE students SET status = 'inactive', dues_status = 'written_off', updated_at = NOW() WHERE id = :id"
                : "UPDATE students SET status = 'inactive', updated_at = NOW() WHERE id = :id";
            safe_db_run($sql, [':id' => $sid]);
            $_SESSION['pf_ok'] = pending_fees_name($stu) . ' — remaining fees written off. Not in pending total. History and receipts stay.';
        } elseif ($act === 'restore') {
            $sql = $hasDues
                ? "UPDATE students SET status = 'active', dues_status = 'open', updated_at = NOW() WHERE id = :id"
                : "UPDATE students SET status = 'active', updated_at = NOW() WHERE id = :id";
            safe_db_run($sql, [':id' => $sid]);
            $_SESSION['pf_ok'] = pending_fees_name($stu) . ' is current again.';
        } elseif ($act === 'remind') {
            $paidRow = $feesOk
                ? safe_db_get_one('SELECT COALESCE(SUM(paid_amount),0) AS p FROM fees_records WHERE student_id = :id', [':id' => $sid])
                : ['p' => 0];
            $pendingAmt = max(0, (float) ($stu['total_fees'] ?? 0) - (float) ($paidRow['p'] ?? 0));
            $phone = pending_fees_phone($stu);
            $name = pending_fees_name($stu);
            $body = $schoolName . ': fee reminder for ' . $name . '. Amount due Rs ' . number_format($pendingAmt, 2) . '. Please contact the school office.';
            if ($phone === '') {
                $_SESSION['pf_err'] = 'No parent mobile on this student. Add father/mother phone on the student record.';
            } else {
                $sent = false;
                $sendErr = '';
                $waFile = dirname(__DIR__) . '/whatsapp_config.php';
                if (is_file($waFile)) {
                    require_once $waFile;
                }
                if (function_exists('whatsapp_send_text')) {
                    $res = whatsapp_send_text('+91' . $phone, $body);
                    $sent = !empty($res['success']);
                    $sendErr = (string) ($res['error'] ?? '');
                }
                if ($sent) {
                    $_SESSION['pf_ok'] = 'Reminder sent on WhatsApp to ' . $phone . '.';
                } else {
                    $_SESSION['pf_err'] = 'Could not send WhatsApp' . ($sendErr !== '' ? (': ' . $sendErr) : '') . '. Call or message ' . $phone . ' — "' . $body . '"';
                }
            }
        }
    }
    $back = $selfUrl . '?' . http_build_query(array_filter([
        'view' => $view,
        'q' => $q !== '' ? $q : null,
        'class_id' => $classFilter > 0 ? (string) $classFilter : null,
        'academic_year' => $academicYearFilter !== '' ? $academicYearFilter : null,
    ]));
    header('Location: ' . $back);
    exit;
}

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
$classJoin = (function_exists('table_exists') && table_exists('classes')) ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
$classSelect = $classJoin !== '' ? "COALESCE(c.name,'') AS class_name" : "'' AS class_name";
$duesSelect = $hasDues ? "COALESCE(s.dues_status,'open') AS dues_status" : "'open' AS dues_status";

$raw = safe_db_get_all(
    "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.status, s.academic_year,
            COALESCE(s.total_fees,0) AS total_fees, s.father_phone, s.mother_phone, s.guardian_phone,
            {$classSelect}, {$duesSelect}
     FROM students s {$classJoin} {$whereSql}
     ORDER BY s.first_name ASC, s.last_name ASC",
    $params
) ?: [];

$paidMap = [];
if ($feesOk && $raw !== []) {
    $ids = array_map(static fn ($r) => (int) $r['id'], $raw);
    $ph = implode(',', $ids);
    $sums = safe_db_get_all("SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id IN ({$ph}) GROUP BY student_id") ?: [];
    foreach ($sums as $sm) {
        $paidMap[(int) $sm['student_id']] = (float) $sm['paid_sum'];
    }
}

$buckets = ['due' => [], 'left' => [], 'written' => []];
$totals = ['due' => 0.0, 'left' => 0.0, 'written' => 0.0];
$todayPaid = 0.0;
if ($feesOk) {
    $todayWhere = $academicYearFilter !== ''
        ? 'INNER JOIN students s ON s.id = fr.student_id AND s.academic_year = :ay WHERE DATE(fr.collected_at) = CURDATE()'
        : 'WHERE DATE(fr.collected_at) = CURDATE()';
    $tr = safe_db_get_one(
        "SELECT COALESCE(SUM(fr.paid_amount),0) AS c FROM fees_records fr {$todayWhere}",
        $academicYearFilter !== '' ? [':ay' => $academicYearFilter] : []
    );
    $todayPaid = $tr ? (float) ($tr['c'] ?? 0) : 0.0;
}

foreach ($raw as $s) {
    $sid = (int) $s['id'];
    $total = (float) $s['total_fees'];
    $paid = $paidMap[$sid] ?? 0.0;
    $pending = max(0.0, $total - $paid);
    $status = strtolower((string) ($s['status'] ?? 'active'));
    $dues = strtolower((string) ($s['dues_status'] ?? 'open'));
    $s['_paid'] = $paid;
    $s['_pending'] = $pending;
    if ($dues === 'written_off') {
        $buckets['written'][] = $s;
        $totals['written'] += $pending;
        continue;
    }
    if (in_array($status, ['inactive', 'alumni'], true)) {
        if ($pending > 0.009) {
            $buckets['left'][] = $s;
            $totals['left'] += $pending;
        }
        continue;
    }
    if ($pending > 0.009) {
        $buckets['due'][] = $s;
        $totals['due'] += $pending;
    }
}

$list = $buckets[$view];
$pageIds = array_map(static fn ($r) => (int) $r['id'], $list);
$paymentsGrouped = [];
if ($feesOk && $pageIds !== []) {
    $ph = implode(',', $pageIds);
    $payRows = safe_db_get_all(
        "SELECT id, student_id, paid_amount, collected_at, receipt_no FROM fees_records WHERE student_id IN ({$ph}) ORDER BY collected_at DESC, id DESC"
    ) ?: [];
    foreach ($payRows as $pr) {
        $paymentsGrouped[(int) $pr['student_id']][] = $pr;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pending_fees_' . $view . '_' . date('Ymd') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student', 'Class', 'Status', 'Total', 'Paid', 'Pending']);
    foreach ($list as $a) {
        fputcsv($out, [
            pending_fees_name($a),
            $a['class_name'] ?? '',
            $a['status'] ?? '',
            number_format((float) $a['total_fees'], 2, '.', ''),
            number_format((float) $a['_paid'], 2, '.', ''),
            number_format((float) $a['_pending'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

$classList = (function_exists('table_exists') && table_exists('classes'))
    ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [])
    : [];

$filterQs = array_filter([
    'view' => $view,
    'q' => $q !== '' ? $q : null,
    'class_id' => $classFilter > 0 ? (string) $classFilter : null,
    'academic_year' => $academicYearFilter !== '' ? $academicYearFilter : null,
], static fn ($v) => $v !== null);

$tabUrl = static function (string $tab) use ($filterQs, $selfUrl): string {
    $qs = $filterQs;
    $qs['view'] = $tab;
    return $selfUrl . '?' . http_build_query($qs);
};

require_once __DIR__ . '/../header.php';
?>
<style>
.pf-wrap { max-width: 1100px; }
.pf-card { background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:14px 16px; margin-bottom:12px; }
.pf-stat { font-size:1.35rem; font-weight:800; }
.pf-tabs a { display:inline-block; padding:8px 12px; border-radius:999px; text-decoration:none; margin:0 6px 6px 0; border:1px solid #dbe7fb; color:#0d3b8c; font-weight:600; font-size:.9rem; }
.pf-tabs a.on { background:#0d3b8c; color:#fff; border-color:#0d3b8c; }
.pf-actions form { display:inline; }
.pf-actions .btn { margin:2px 0; }
</style>

<div class="pf-wrap">
<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<p class="text-muted mb-3">Do not delete a student who left. Mark <strong>Left</strong> so lists stay clean. Remaining fees stay as dues until you collect them or <strong>Write off</strong> (close the account).</p>

<div class="row g-2 mb-3">
  <div class="col-md-4"><div class="pf-card"><div class="small text-muted">Current students — due</div><div class="pf-stat text-danger">₹ <?php echo number_format($totals['due'], 2); ?></div><div class="small"><?php echo count($buckets['due']); ?> students</div></div></div>
  <div class="col-md-4"><div class="pf-card"><div class="small text-muted">Left — still due</div><div class="pf-stat text-danger">₹ <?php echo number_format($totals['left'], 2); ?></div><div class="small"><?php echo count($buckets['left']); ?> students</div></div></div>
  <div class="col-md-4"><div class="pf-card"><div class="small text-muted">Collected today</div><div class="pf-stat text-success">₹ <?php echo number_format($todayPaid, 2); ?></div></div></div>
</div>

<div class="pf-card">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="view" value="<?php echo e($view); ?>">
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
    <div class="col-md-4">
      <label class="form-label">Name</label>
      <input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Search">
    </div>
    <div class="col-md-3">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select">
        <option value="">All</option>
        <?php foreach ($classList as $c): ?>
          <option value="<?php echo (int) $c['id']; ?>" <?php echo $classFilter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e((string) $c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-primary" type="submit">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">Reset</a>
    </div>
  </form>
</div>

<div class="pf-tabs mb-2">
  <a class="<?php echo $view === 'due' ? 'on' : ''; ?>" href="<?php echo e($tabUrl('due')); ?>">Current due (<?php echo count($buckets['due']); ?>)</a>
  <a class="<?php echo $view === 'left' ? 'on' : ''; ?>" href="<?php echo e($tabUrl('left')); ?>">Left, still due (<?php echo count($buckets['left']); ?>)</a>
  <a class="<?php echo $view === 'written' ? 'on' : ''; ?>" href="<?php echo e($tabUrl('written')); ?>">Written off (<?php echo count($buckets['written']); ?>)</a>
</div>

<div class="pf-card">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h2 class="h6 fw-bold mb-0">
      <?php echo $view === 'due' ? 'Collect from students still in school' : ($view === 'left' ? 'Left school — follow up or write off' : 'Closed accounts (not in pending total)'); ?>
    </h2>
    <a class="btn btn-sm btn-outline-secondary" href="?<?php echo e(http_build_query(array_merge($filterQs, ['export' => 'csv']))); ?>">CSV</a>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>Student</th><th>Class</th><th class="text-end">Pending</th><th></th></tr></thead>
      <tbody>
        <?php if ($list === []): ?>
          <tr><td colspan="4" class="text-muted text-center py-4">Nothing in this list.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $s):
            $sid = (int) $s['id'];
            $name = pending_fees_name($s);
            $pending = (float) $s['_pending'];
            $phone = pending_fees_phone($s);
            $collectHref = $collectUrl . (str_contains($collectUrl, '?') ? '&' : '?') . 'student_id=' . $sid;
            $viewBase = $receiptUrl . (str_contains($receiptUrl, '?') ? '&' : '?');
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo e($name !== '' ? $name : ('#' . $sid)); ?></div>
              <div class="small text-muted">Total ₹ <?php echo number_format((float) $s['total_fees'], 2); ?> · Paid ₹ <?php echo number_format((float) $s['_paid'], 2); ?><?php echo $phone !== '' ? ' · ' . e($phone) : ''; ?></div>
              <?php if (!empty($paymentsGrouped[$sid])): ?>
                <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#pay-<?php echo $sid; ?>">Receipts</button>
              <?php endif; ?>
            </td>
            <td><?php echo e((string) ($s['class_name'] ?: '—')); ?></td>
            <td class="text-end fw-bold text-danger">₹ <?php echo number_format($pending, 2); ?></td>
            <td class="pf-actions text-nowrap text-end">
              <?php if ($view !== 'written'): ?>
                <a class="btn btn-sm btn-primary" href="<?php echo e($collectHref); ?>">Collect</a>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="do" value="remind">
                  <button class="btn btn-sm btn-outline-primary" type="submit">Remind</button>
                </form>
              <?php endif; ?>
              <?php if ($view === 'due'): ?>
                <form method="post" onsubmit="return confirm('Mark as Left? They leave the current list. Dues stay until Collect or Write off.');">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="do" value="left">
                  <button class="btn btn-sm btn-outline-secondary" type="submit">Left school</button>
                </form>
              <?php endif; ?>
              <?php if ($view === 'left'): ?>
                <form method="post" onsubmit="return confirm('Write off remaining fees? This closes the account. Receipts are not deleted.');">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="do" value="writeoff">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Write off</button>
                </form>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="do" value="restore">
                  <button class="btn btn-sm btn-outline-success" type="submit">Back to current</button>
                </form>
              <?php endif; ?>
              <?php if ($view === 'written'): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="do" value="restore">
                  <button class="btn btn-sm btn-outline-success" type="submit">Reopen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php if (!empty($paymentsGrouped[$sid])): ?>
          <tr>
            <td colspan="4" class="p-0 border-0">
              <div class="collapse" id="pay-<?php echo $sid; ?>">
                <div class="p-2 bg-light">
                  <?php foreach ($paymentsGrouped[$sid] as $pr):
                      $rid = (int) $pr['id'];
                      $rc = (string) ($pr['receipt_no'] ?? '');
                      if (str_contains($rc, '||')) {
                          $rc = explode('||', $rc, 2)[0];
                      }
                  ?>
                    <div class="small mb-1">
                      <?php echo e(substr((string) ($pr['collected_at'] ?? ''), 0, 16)); ?>
                      · ₹ <?php echo number_format((float) $pr['paid_amount'], 2); ?>
                      · <?php echo e($rc); ?>
                      · <a href="<?php echo e($viewBase . 'id=' . $rid); ?>" target="_blank" rel="noopener">View</a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
<?php
require_once __DIR__ . '/../footer.php';
