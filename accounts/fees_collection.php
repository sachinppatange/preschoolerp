<?php
/**
 * accounts/fees_collection.php
 *
 * Finalised Fees Collection page (debugged)
 *
 * Notes:
 * - Uses exact fees_records columns:
 *     id, school_id, student_id, class_id, amount, due_date, paid_amount,
 *     status, receipt_no, collected_by (INT), collected_at, created_at, updated_at
 * - collected_by is saved as the accounts user id (int) when available.
 * - Payment Type and Payment Note are preserved by appending short metadata to receipt_no:
 *     receipt_no = <RC...> ||METHOD:<type> ||NOTE:<first 120 chars of note>
 *   (This preserves the info without changing DB schema.)
 * - Debug logging writes exceptions to sys_get_temp_dir()/fees_collection_error.log
 *
 * Place at: /demopreschoolapp/accounts/fees_collection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
require_accounts_or_reception_auth();
$DEBUG = panel_debug();
$accountsUserId = auth_user_id();

/* Debug flag */
/* escape helper */

if (!function_exists('table_columns')) {
    function table_columns(string $table): array {
        try {
            $r = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", [':t'=>$table]);
            return array_map(static function ($row) { return $row['COLUMN_NAME']; }, $r ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('table_exists')) {
    function table_exists(string $name): bool {
        try {
            $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
            return !empty($r) && intval($r['cnt']) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* ensure fees_records exists */
if (!table_exists('fees_records')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required table <strong>fees_records</strong> missing. Check DB.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Helper to format student name (avoid duplicate middle token) */
if (!function_exists('format_student_name')) {
function format_student_name(array $st): string {
    // Robust formatter that removes duplicate tokens (case-insensitive)
    $first = preg_replace('/\s+/', ' ', trim((string)($st['first_name'] ?? '')));
    $middle = preg_replace('/\s+/', ' ', trim((string)($st['middle_name'] ?? '')));
    $last = preg_replace('/\s+/', ' ', trim((string)($st['last_name'] ?? '')));

    $last_tokens = $last === '' ? [] : preg_split('/\s+/', $last, -1, PREG_SPLIT_NO_EMPTY);

    $seen = [];
    $parts = [];

    $push = function(string $token) use (&$parts, &$seen) {
        $t = trim($token);
        if ($t === '') return;
        $k = mb_strtolower($t, 'UTF-8');
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $parts[] = $t;
    };

    if ($first !== '') $push($first);

    $firstLower = mb_strtolower($first, 'UTF-8');
    $middleLower = mb_strtolower($middle, 'UTF-8');
    $lastLowerTokens = array_map(function($x){ return mb_strtolower($x, 'UTF-8'); }, $last_tokens);

    if ($middle !== '' && $middleLower !== $firstLower && !in_array($middleLower, $lastLowerTokens, true)) {
        $push($middle);
    }

    foreach ($last_tokens as $t) {
        $trimmed = trim($t);
        if ($trimmed === '') continue;
        $lower = mb_strtolower($trimmed, 'UTF-8');
        if ($lower === $firstLower) continue;
        if ($middle !== '' && $lower === $middleLower) continue;
        $push($trimmed);
    }

    return trim(implode(' ', $parts));
}
}

/* preload lists */
$schools = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classes = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$students = [];
if (table_exists('students')) {
    if (function_exists('ay_students_have_column') && ay_students_have_column()) {
        $students = safe_db_get_all(
            "SELECT id, COALESCE(first_name,'') AS first_name, COALESCE(middle_name,'') AS middle_name, COALESCE(last_name,'') AS last_name, COALESCE(academic_year,'') AS academic_year, COALESCE(school_id,NULL) AS school_id, COALESCE(class_id,NULL) AS class_id
             FROM students WHERE academic_year = :panel_ay ORDER BY first_name, last_name",
            [':panel_ay' => ay_selected()]
        );
    } else {
        $students = safe_db_get_all("SELECT id, COALESCE(first_name,'') AS first_name, COALESCE(middle_name,'') AS middle_name, COALESCE(last_name,'') AS last_name, COALESCE(academic_year,'') AS academic_year, COALESCE(school_id,NULL) AS school_id, COALESCE(class_id,NULL) AS class_id FROM students ORDER BY first_name, last_name");
    }
}

/* default school id if Pioneer Play School exists */
$defaultSchoolId = null;
foreach ($schools as $s) {
    if (strcasecmp(trim($s['name']), 'Pioneer Play School') === 0) { $defaultSchoolId = (int)$s['id']; break; }
}

$paymentTypes = ['Cash', 'UPI', 'Online', 'Cheque', 'Bank'];
$action = $_REQUEST['action'] ?? 'form';

/* AJAX: get_pending */
if ($action === 'get_pending' && !empty($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int) $_GET['student_id'];
    if ($sid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid student id']); exit; }
    $stu = safe_db_get_one("SELECT id, COALESCE(total_fees,0) AS total_fees, COALESCE(academic_year,'') AS academic_year, school_id, class_id FROM students WHERE id = :id LIMIT 1", [':id'=>$sid]);
    $total = $stu ? (float)$stu['total_fees'] : 0.0;
    $academic_year = $stu ? trim($stu['academic_year']) : '';
    $stu_school = $stu ? (int)$stu['school_id'] : null;
    $stu_class = $stu ? (int)$stu['class_id'] : null;
    $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id = :id", [':id'=>$sid]);
    $paid = $r ? (float)$r['paid_sum'] : 0.0;
    $pending = max(0.0, $total - $paid);
    echo json_encode(['ok'=>true,'student_id'=>$sid,'total_fee'=>round($total,2),'total_paid'=>round($paid,2),'pending'=>round($pending,2),'academic_year'=>$academic_year,'school_id'=>$stu_school,'class_id'=>$stu_class]);
    exit;
}

/* CREATE: insert into fees_records using exact columns; set collected_by to accountsUserId (int)
   This INSERT is built dynamically so that if you have added the optional columns
   receipt_no_base/payment_method/payment_note they will be saved separately.
*/
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $msg = 'Invalid CSRF token.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?'); exit;
    }
    if (function_exists('ay_can_edit') && !ay_can_edit()) {
        $msg = 'This academic year is locked. Fee collection is not allowed.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?'); exit;
    }

    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $student_id = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : null;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $payment_date = trim((string)($_POST['payment_date'] ?? ''));
    $payment_type = trim((string)($_POST['payment_type'] ?? ''));
    $amount_raw = trim((string)($_POST['amount'] ?? '0'));
    $amount = (float) str_replace(',', '', $amount_raw);
    $note = trim((string)($_POST['note'] ?? ''));

    $errors = [];
    if (empty($student_id) || $student_id <= 0) $errors[] = 'Please select a student.';
    if ($amount <= 0) $errors[] = 'Enter an amount greater than 0.';
    if ($payment_type === '') $errors[] = 'Please choose how the fee was paid.';
    if ($payment_date === '') $payment_date = date('Y-m-d');
    if (!\DateTime::createFromFormat('Y-m-d', $payment_date)) $errors[] = 'Payment Date must be YYYY-MM-DD';

    if (!empty($errors)) {
        $msg = implode(' ; ', $errors);
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?student_id=' . urlencode((string)$student_id)); exit;
    }

    // generate receipt and metadata
    $receipt_no_base = 'RC' . date('YmdHis') . mt_rand(100,999);
    $metaParts = [];
    if ($payment_type !== '') $metaParts[] = 'METHOD:' . str_replace(['|','||'],['',''], $payment_type);
    if ($note !== '') {
        $n = preg_replace('/\s+/', ' ', trim($note));
        $n = substr($n, 0, 120); // limit length
        $n = str_replace(['|','||'], ['',''], $n);
        $metaParts[] = 'NOTE:' . $n;
    }
    $receipt_no = $receipt_no_base . (!empty($metaParts) ? '||' . implode('||', $metaParts) : '');

    $collected_by_int = $accountsUserId ? (int)$accountsUserId : null;
    $collected_at_db = $payment_date . ' ' . date('H:i:s');
    $now = date('Y-m-d H:i:s');

    // detect optional columns
    $cols = table_columns('fees_records');
    $has_receipt_base = in_array('receipt_no_base', $cols, true);
    $has_payment_method = in_array('payment_method', $cols, true);
    $has_payment_note = in_array('payment_note', $cols, true);

    // Build insert dynamically
    $columns = ['school_id','student_id','class_id','amount','due_date','paid_amount','status','receipt_no','collected_by','collected_at','created_at','updated_at'];
    $placeholders = [':school_id',':student_id',':class_id',':amount','NULL',':paid_amount',':status',':receipt_no',':collected_by',':collected_at',':created_at',':updated_at'];
    $params = [
        ':school_id'   => $school_id,
        ':student_id'  => $student_id,
        ':class_id'    => $class_id,
        ':amount'      => $amount,
        ':paid_amount' => $amount,
        ':status'      => 'paid',
        ':receipt_no'  => $receipt_no,
        ':collected_by'=> $collected_by_int,
        ':collected_at'=> $collected_at_db,
        ':created_at'  => $now,
        ':updated_at'  => $now
    ];

    if ($has_receipt_base) {
        $columns[] = 'receipt_no_base';
        $placeholders[] = ':receipt_no_base';
        $params[':receipt_no_base'] = $receipt_no_base;
    }
    if ($has_payment_method) {
        $columns[] = 'payment_method';
        $placeholders[] = ':payment_method';
        $params[':payment_method'] = $payment_type !== '' ? $payment_type : null;
    }
    if ($has_payment_note) {
        $columns[] = 'payment_note';
        $placeholders[] = ':payment_note';
        $params[':payment_note'] = $note !== '' ? $n : null;
    }

    $insertSql = "INSERT INTO fees_records (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    $pdo = pdo_connect();
    try {
        if (!($pdo instanceof \PDO)) throw new RuntimeException('Database connection unavailable');

        $stmt = $pdo->prepare($insertSql);
        $ok = $stmt->execute($params);
        if (!$ok) {
            $info = $stmt->errorInfo();
            throw new RuntimeException('Insert failed: ' . ($info[2] ?? json_encode($info)));
        }
        $newId = (int)$pdo->lastInsertId();

        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'id'=>$newId,'receipt_no'=>$receipt_no]); exit; }

        $_SESSION['form_success'] = 'Payment saved. Receipt: ' . $receipt_no_base;
        header('Location: ?saved_id=' . urlencode((string)$newId)); exit;
    } catch (Throwable $e) {
        $errMsg = $DEBUG ? $e->getMessage() : 'Failed to save payment.';
        error_log('[fees_collection] Create error: ' . $e->getMessage());
        @file_put_contents(sys_get_temp_dir() . '/fees_collection_error.log', date('c') . " - " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$errMsg]); exit; }
        $_SESSION['form_error'] = $errMsg;
        header('Location: ?'); exit;
    }
}

/* Flash and recent payments */
$messages = []; $errors_flash = [];
if (!empty($_SESSION['form_error'])) { $errors_flash[] = $_SESSION['form_error']; unset($_SESSION['form_error']); }
if (!empty($_SESSION['form_success'])) { $messages[] = $_SESSION['form_success']; unset($_SESSION['form_success']); }
$saved_id = isset($_GET['saved_id']) ? (int)$_GET['saved_id'] : 0;
$preselect_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

$recent = safe_db_get_all("SELECT fr.id, fr.receipt_no, fr.amount, fr.paid_amount, fr.collected_by, fr.collected_at, COALESCE(s.first_name,'') AS student_first, COALESCE(s.middle_name,'') AS student_middle, COALESCE(s.last_name,'') AS student_last, COALESCE(s.academic_year,'') AS academic_year, COALESCE(u.name,'') AS collector_name FROM fees_records fr LEFT JOIN students s ON s.id = fr.student_id LEFT JOIN users u ON u.id = fr.collected_by ORDER BY fr.created_at DESC LIMIT 20") ?: [];
$receiptPrint = function_exists('site_url') ? site_url('/accounts/receipt_print.php') : '../accounts/receipt_print.php';

$page_title = 'Collect Fees';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.fee-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:1.1rem 1.2rem; margin-bottom:1rem; }
.fee-steps { color:#64748b; font-size:.9rem; margin-bottom:1rem; }
.fee-pending { background:#f0f7ff; border:1px dashed #b6d0f5; border-radius:14px; padding:1rem; }
.fee-pending .amt { font-size:1.6rem; font-weight:800; color:#0d3b8c; }
.fee-ok { background:#e7f8ee; color:#137a3a; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors_flash as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<p class="fee-steps mb-2">1. Find the student → 2. Check pending → 3. Enter amount &amp; how they paid → 4. Save (receipt opens to print)</p>
<div class="alert alert-light border mb-3">Try it: pick any student, leave Cash, click <strong>Save &amp; print receipt</strong>. Allow pop-ups once. If pending is ₹0 they have already paid — enter a small amount only if you need a test receipt.</div>

<div class="fee-card">
  <form id="paymentForm" method="post" action="?action=create" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
    <input type="hidden" name="school_id" id="school_select" value="<?php echo $defaultSchoolId ? (int) $defaultSchoolId : ''; ?>">
    <input type="hidden" name="class_id" id="class_select" value="">

    <div class="col-md-7">
      <label class="form-label fw-semibold">Student *</label>
      <input type="search" id="studentSearch" class="form-control mb-2" placeholder="Type to search name" autocomplete="off">
      <select id="student_select" name="student_id" class="form-select" size="8" required>
        <option value="">Select a student</option>
        <?php foreach ($students as $st):
            $label = format_student_name($st);
            $search = strtolower($label . ' ' . (string) ($st['academic_year'] ?? ''));
        ?>
          <option value="<?php echo (int) $st['id']; ?>" data-search="<?php echo e($search); ?>" <?php echo $preselect_student === (int) $st['id'] ? 'selected' : ''; ?>>
            <?php echo e($label !== '' ? $label : ('Student #' . (int) $st['id'])); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-5">
      <div class="fee-pending h-100" id="pendingSummary">
        <div class="small text-muted">Pending fees</div>
        <div class="amt" id="pendingValue">—</div>
        <div class="small text-muted" id="pendingDetails">Select a student to see total, paid and pending.</div>
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label fw-semibold">Amount *</label>
      <input id="amount_input" name="amount" class="form-control" type="text" inputmode="decimal" placeholder="0.00" required>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Paid by *</label>
      <select name="payment_type" class="form-select" required>
        <option value="Cash" selected>Cash</option>
        <?php foreach ($paymentTypes as $pt): if ($pt === 'Cash') continue; ?>
          <option value="<?php echo e($pt); ?>"><?php echo e($pt); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Date</label>
      <input name="payment_date" type="date" class="form-control" value="<?php echo e(date('Y-m-d')); ?>" required>
    </div>
    <div class="col-12">
      <label class="form-label">Note</label>
      <input name="note" class="form-control" placeholder="Optional, e.g. term 1 / cheque no.">
    </div>
    <div class="col-12">
      <button class="btn btn-primary" type="submit">Save &amp; print receipt</button>
    </div>
  </form>
</div>

<div class="fee-card">
  <h2 class="h6 fw-bold mb-3">Recent receipts</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Student</th><th>Amount</th><th>When</th><th></th></tr></thead>
      <tbody>
        <?php if ($recent === []): ?>
          <tr><td colspan="4" class="text-muted text-center py-3">No receipts yet. Collect a fee above to see it here.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $rr):
            $tmp = ['first_name'=>$rr['student_first'] ?? '','middle_name'=>$rr['student_middle'] ?? '','last_name'=>$rr['student_last'] ?? ''];
            $student_label = format_student_name($tmp);
            $rid = (int) $rr['id'];
            $rc = (string) ($rr['receipt_no'] ?? '');
            if (str_contains($rc, '||')) {
                $rc = explode('||', $rc, 2)[0];
            }
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo e($student_label !== '' ? $student_label : 'Student'); ?></div>
              <div class="small text-muted"><?php echo e($rc); ?></div>
            </td>
            <td>₹ <?php echo number_format((float) ($rr['paid_amount'] ?? 0), 2); ?></td>
            <td class="small text-muted"><?php echo e(substr((string) ($rr['collected_at'] ?? ''), 0, 16)); ?></td>
            <td class="text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="openReceipt(<?php echo $rid; ?>, false)">View</button>
              <button type="button" class="btn btn-sm btn-success" onclick="openReceipt(<?php echo $rid; ?>, true)">Print</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var receiptBase = <?php echo json_encode($receiptPrint); ?>;
  var studentSelect = document.getElementById('student_select');
  var search = document.getElementById('studentSearch');
  var pendingValue = document.getElementById('pendingValue');
  var pendingDetails = document.getElementById('pendingDetails');
  var pendingBox = document.getElementById('pendingSummary');
  var amountInput = document.getElementById('amount_input');
  var schoolSelect = document.getElementById('school_select');
  var classSelect = document.getElementById('class_select');

  function fmt(n) { return Number(n).toFixed(2); }
  function clearPending() {
    pendingValue.textContent = '—';
    pendingDetails.textContent = 'Select a student to see total, paid and pending.';
    pendingBox.classList.remove('fee-ok');
    amountInput.value = '';
  }
  function fetchPending(sid) {
    if (!sid) { clearPending(); return; }
    pendingValue.textContent = '…';
    fetch('?action=get_pending&student_id=' + encodeURIComponent(sid), { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : Promise.reject(); })
      .then(function (json) {
        if (!json || !json.ok) { clearPending(); return; }
        pendingValue.textContent = '₹ ' + fmt(json.pending);
        pendingDetails.textContent = 'Total ₹ ' + fmt(json.total_fee) + ' · Paid ₹ ' + fmt(json.total_paid);
        pendingBox.classList.toggle('fee-ok', Number(json.pending) <= 0.009);
        amountInput.value = fmt(json.pending);
        if (json.school_id && schoolSelect) schoolSelect.value = json.school_id;
        if (json.class_id && classSelect) classSelect.value = json.class_id;
      })
      .catch(function () { pendingDetails.textContent = 'Could not load pending fees.'; });
  }
  if (search) {
    search.addEventListener('input', function () {
      var q = (search.value || '').toLowerCase().trim();
      Array.prototype.forEach.call(studentSelect.options, function (opt, i) {
        if (i === 0) { opt.hidden = false; return; }
        var hay = opt.getAttribute('data-search') || opt.textContent.toLowerCase();
        opt.hidden = q !== '' && hay.indexOf(q) === -1;
      });
    });
  }
  if (studentSelect) studentSelect.addEventListener('change', function () { fetchPending(this.value); });
  <?php if ($preselect_student > 0): ?>fetchPending(<?php echo (int) $preselect_student; ?>);<?php endif; ?>

  window.openReceipt = function (id, doPrint) {
    var url = receiptBase + (receiptBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
    var w = window.open(url, '_blank');
    if (!w) { alert('Allow pop-ups to view the receipt.'); return; }
    if (doPrint) setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} }, 800);
  };
  var params = new URLSearchParams(window.location.search);
  var saved = params.get('saved_id');
  if (saved) {
    openReceipt(saved, true);
    params.delete('saved_id');
    history.replaceState(null, '', window.location.pathname + (params.toString() ? ('?' + params.toString()) : ''));
  }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
