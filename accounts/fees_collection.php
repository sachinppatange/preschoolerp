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
panel_bootstrap('accounts', ['skip_auth' => true]);
require_accounts_or_reception_auth();
$DEBUG = panel_debug();

/* Optional includes (guarded) */
require_accounts_or_reception_auth();
$accountsUserId = auth_user_id();

/* Debug flag */
/* escape helper */

/* helper: get column list for a table */
function table_columns(string $table): array {
    try {
        $r = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", [':t'=>$table]);
        return array_map(function($row){ return $row['COLUMN_NAME']; }, $r);
    } catch (Throwable $e) { return []; }
}

/* table exists helper */
function table_exists(string $name): bool {
    try { $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]); return !empty($r) && intval($r['cnt'])>0; } catch (Throwable $e) { return false; }
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

$paymentTypes = ['Cash','Online','Cheque','Bank','Bad Debts/kasar'];
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
    if (empty($student_id) || $student_id <= 0) $errors[] = 'कृपया विद्यार्थी निवडा / Select student.';
    if ($amount <= 0) $errors[] = 'कृपया रक्कम भरा (Amount must be > 0).';
    if ($payment_type === '') $errors[] = 'Payment Type आवश्यक आहे (required).';
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

$recent = safe_db_get_all("SELECT fr.id, fr.receipt_no, fr.amount, fr.paid_amount, fr.collected_by, fr.collected_at, COALESCE(s.first_name,'') AS student_first, COALESCE(s.middle_name,'') AS student_middle, COALESCE(s.last_name,'') AS student_last, COALESCE(s.academic_year,'') AS academic_year FROM fees_records fr LEFT JOIN students s ON s.id = fr.student_id ORDER BY fr.created_at DESC LIMIT 20");

/* header include if exists */
$pageTitle = 'Add Payment Receipt';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors_flash as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <div class="card mb-3"><div class="card-body">
    <form id="paymentForm" method="post" action="?action=create" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

      <div class="col-md-4">
        <label class="form-label">Student *</label>
        <select id="student_select" name="student_id" class="form-select" required>
          <option value="">-- Select student --</option>
          <?php foreach ($students as $st):
            $label = format_student_name($st);
            $label .= $st['academic_year'] ? ' — ' . e($st['academic_year']) : '';
          ?>
            <option value="<?php echo (int)$st['id']; ?>" <?php if($preselect_student === (int)$st['id']) echo 'selected'; ?>>
              <?php echo e($label ?: ('Student ' . (int)$st['id'])); ?> (ID: <?php echo (int)$st['id']; ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">School (optional)</label>
        <select id="school_select" name="school_id" class="form-select">
          <option value="">-- Select school --</option>
          <?php foreach ($schools as $s):
              $sel = ($defaultSchoolId !== null && (int)$s['id'] === $defaultSchoolId) ? ' selected' : '';
          ?>
            <option value="<?php echo (int)$s['id']; ?>"<?php echo $sel; ?>><?php echo e($s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Class (optional)</label>
        <select id="class_select" name="class_id" class="form-select">
          <option value="">-- Select class --</option>
          <?php foreach ($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <div id="pendingSummary" class="summary-box d-flex justify-content-between align-items-center">
          <div>
            <div class="small-muted">Remaining Amount / Pending Fees</div>
            <div id="pendingValue" class="summary-value">—</div>
            <div id="pendingDetails" class="small text-muted">Select student to view details</div>
          </div>
          <div style="min-width:260px">
            <div class="mb-2"><label class="form-label">Payment Date *</label>
              <input name="payment_date" type="date" class="form-control" value="<?php echo e(date('Y-m-d')); ?>" required></div>
            <div class="mb-2">
              <label class="form-label">Payment Type *</label>
              <select name="payment_type" class="form-select" required>
                <option value="">-- Select Type --</option>
                <?php foreach ($paymentTypes as $pt): ?><option value="<?php echo e($pt); ?>"><?php echo e($pt); ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Amount *</label>
        <input id="amount_input" name="amount" class="form-control" type="text" placeholder="Enter amount being collected" required>
      </div>

      <div class="col-md-8">
        <label class="form-label">Payment Note / Collected by (name)</label>
        <input name="note" class="form-control" placeholder="Collector name or note (optional)">
        <div class="form-text small-muted">Collector identity is stored as your account user id (collected_by). Note and payment type are preserved in the receipt metadata; if your DB has separate payment_method/payment_note columns they will be saved separately.</div>
      </div>

      <div class="col-12 text-end">
        <button class="btn btn-primary" type="submit">Save Payment & Generate Receipt</button>
      </div>
    </form>
  </div></div>

  <div class="card"><div class="card-body">
    <h5 class="card-title">Recent Payments</h5>
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead><tr><th>ID</th><th>Receipt</th><th>Student</th><th>Academic Year</th><th>Amount</th><th>Collected At</th><th>By (user id)</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (!empty($recent)): foreach ($recent as $rr):
              $tmp = ['first_name'=>$rr['student_first'] ?? '','middle_name'=>$rr['student_middle'] ?? '','last_name'=>$rr['student_last'] ?? ''];
              $student_label = format_student_name($tmp);
              $rid = (int)$rr['id'];
          ?>
            <tr>
              <td><?php echo $rid; ?></td>
              <td><?php echo e($rr['receipt_no'] ?? ''); ?></td>
              <td><?php echo e($student_label); ?></td>
              <td><?php echo e($rr['academic_year'] ?? ''); ?></td>
              <td>₹ <?php echo number_format((float)$rr['paid_amount'],2); ?></td>
              <td><?php echo e($rr['collected_at'] ?? ''); ?></td>
              <td><?php echo e($rr['collected_by'] ?? ''); ?></td>
              <td class="action-btns">
                <div class="btn-group" role="group" aria-label="actions">
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewReceipt(<?php echo $rid; ?>)">View</button>
                  <button type="button" class="btn btn-sm btn-outline-success" onclick="printReceipt(<?php echo $rid; ?>)">Print</button>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="8" class="text-center text-muted">No payments yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const $ = id => document.getElementById(id);
  const studentSelect = $('student_select');
  const pendingValue = $('pendingValue');
  const pendingDetails = $('pendingDetails');
  const amountInput = $('amount_input');
  const schoolSelect = $('school_select');
  const classSelect = $('class_select');

  function fmt(n){ return Number(n).toFixed(2); }
  function clearPending(){ pendingValue.textContent='—'; pendingDetails.textContent='Select student to view details'; amountInput.value=''; amountInput.readOnly=false; }

  function fetchPending(sid){
    if (!sid) { clearPending(); return; }
    pendingValue.textContent = 'Loading...';
    pendingDetails.textContent = '';
    fetch('?action=get_pending&student_id=' + encodeURIComponent(sid), { credentials: 'same-origin' })
      .then(resp => resp.ok ? resp.json() : Promise.reject())
      .then(json => {
        if (json && json.ok) {
          pendingValue.textContent = '₹ ' + fmt(json.pending);
          let details = 'Total Fee: ₹ ' + fmt(json.total_fee) + ' • Paid: ₹ ' + fmt(json.total_paid);
          if (json.academic_year) details += ' • Year: ' + json.academic_year;
          pendingDetails.textContent = details;
          amountInput.value = fmt(json.pending);
          amountInput.readOnly = false;
          if (json.school_id && schoolSelect) schoolSelect.value = json.school_id;
          if (json.class_id && classSelect) classSelect.value = json.class_id;
        } else {
          clearPending();
        }
      }).catch(() => { pendingValue.textContent='—'; pendingDetails.textContent='Failed to load'; });
  }

  <?php if ($preselect_student > 0): ?>
    fetchPending(<?php echo (int)$preselect_student; ?>);
  <?php endif; ?>

  if (studentSelect) studentSelect.addEventListener('change', function(){ fetchPending(this.value); });

  (function openReceiptIfSaved(){
    const params = new URLSearchParams(window.location.search);
    const saved_id = params.get('saved_id');
    if (saved_id) {
      // OPEN the receipt page under the demopreschoolapp path (fixed)
      const url = '/demopreschoolapp/accounts/receipt_print.php?id=' + encodeURIComponent(saved_id);
      try { window.open(url, '_blank'); } catch(e) {}
      params.delete('saved_id');
      const base = window.location.pathname + (params.toString() ? ('?' + params.toString()) : '');
      history.replaceState(null, '', base);
    }
  })();
});

/* Receipt actions: view, print */
function viewReceipt(id) {
  const url = '/demopreschoolapp/accounts/receipt_print.php?id=' + encodeURIComponent(id);
  window.open(url, '_blank');
}

function printReceipt(id) {
  const url = '/demopreschoolapp/accounts/receipt_print.php?id=' + encodeURIComponent(id);
  const w = window.open(url, '_blank');
  if (!w) { alert('Popup blocked. Please allow popups for this site.'); return; }
  w.onload = function() { try { w.focus(); w.print(); } catch(e) {} };
  setTimeout(function(){ try { w.focus(); w.print(); } catch(e) {} }, 2000);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
