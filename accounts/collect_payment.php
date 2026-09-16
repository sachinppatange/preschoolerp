<?php
/**
 * accounts/collect_payment.php
 *
 * Collect fees and print receipt.
 *
 * This is the same collect_payment.php you provided earlier, updated to address the
 * CSS diagnostic: add the standard `print-color-adjust` property alongside
 * `-webkit-print-color-adjust` for broader compatibility.
 *
 * Place at: /pioneerplayschool01/accounts/collect_payment.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts', ['skip_auth' => true]);
require_accounts_or_reception_auth();
$DEBUG = panel_debug();

/* Auth check */
require_accounts_or_reception_auth();

/* Optional includes */
/* DEBUG (turn off in production) */
function safe_db_get_one(string $sql, array $params = []) {
    if (function_exists('db_fetch_one')) {
        try { return call_user_func('db_fetch_one', $sql, $params); } catch (Throwable $e) {}
    }
    $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return null;
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $r = $stmt->fetch(\PDO::FETCH_ASSOC); return $r === false ? null : $r; }
    catch (Throwable $e) { if ((defined('DEV_SHOW_ERRORS') && constant('DEV_SHOW_ERRORS'))) error_log($e->getMessage()); return null; }
}

function safe_db_get_all(string $sql, array $params = []): array {
    if (function_exists('db_fetch_all')) {
        try { return call_user_func('db_fetch_all', $sql, $params) ?: []; } catch (Throwable $e) {}
    }
    $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return [];
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { if ((defined('DEV_SHOW_ERRORS') && constant('DEV_SHOW_ERRORS'))) error_log($e->getMessage()); return []; }
}

function table_exists(string $name): bool {
    try { $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]); return !empty($r) && intval($r['cnt'])>0; } catch (Throwable $e) { return false; }
}

function current_user_id(): ?int {
    return auth_user_id();
}

/* -------------------------
   Helper: total paid for a student (sum of fees_records.paid_amount)
   ------------------------- */
function get_total_paid_for_student(int $studentId): float {
    if (!table_exists('fees_records')) return 0.0;
    $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS paid FROM fees_records WHERE student_id = :sid", [':sid'=>$studentId]);
    return $r ? (float)$r['paid'] : 0.0;
}

/* -------------------------
   Receipt number generator
   ------------------------- */
function generate_receipt_no(): string {
    return 'R' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

/* -------------------------
   Page state
   ------------------------- */
$errors = [];
$messages = [];
$receipt = null;
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Load dropdowns */
$schools = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classes = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$students = safe_db_get_all("SELECT id, first_name, middle_name, last_name, school_id, class_id, COALESCE(total_fees,0) AS total_fees FROM students ORDER BY first_name ASC");

/* If editing (existing fees_records), load */
$fees_table_exists = table_exists('fees_records');
$record = null;
if ($edit_id > 0 && $fees_table_exists) {
    $record = safe_db_get_one("SELECT * FROM fees_records WHERE id = :id LIMIT 1", [':id'=>$edit_id]);
}

/* If student_id provided via GET (to open form for that student), compute pending to prefill */
$preselect_student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$prefill_amount = '';
$prefill_paid_total = 0.0;
if ($preselect_student_id > 0) {
    $srow = safe_db_get_one("SELECT COALESCE(total_fees,0) AS total_fees FROM students WHERE id = :id LIMIT 1", [':id'=>$preselect_student_id]);
    if ($srow) {
        $totalFees = (float)$srow['total_fees'];
        $paidSum = get_total_paid_for_student($preselect_student_id);
        $pending = max(0.0, $totalFees - $paidSum);
        $prefill_amount = number_format($pending, 2, '.', '');
        $prefill_paid_total = $paidSum;
    }
}

/* POST: Save (insert/update) fees_records */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save')) {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Invalid CSRF token.';
    }

    if (!$fees_table_exists) {
        $errors[] = 'fees_records table does not exist. Please create it first.';
    }

    $form_id = isset($_POST['fees_id']) && $_POST['fees_id'] !== '' ? (int)$_POST['fees_id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0; // total fees (editable)
    $due_date_raw = trim((string)($_POST['due_date'] ?? ''));
    $due_date = $due_date_raw !== '' ? date('Y-m-d', strtotime($due_date_raw)) : null;
    $paid_amount = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0.0;
    $receipt_no = trim((string)($_POST['receipt_no'] ?? ''));
    $collected_at_raw = trim((string)($_POST['collected_at'] ?? ''));
    $collected_at = $collected_at_raw !== '' ? str_replace('T', ' ', $collected_at_raw) : null;

    // Validation
    if ($student_id <= 0) $errors[] = 'Student is required.';
    if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($paid_amount < 0) $errors[] = 'Paid amount cannot be negative.';
    if ($paid_amount > $amount + 0.0001) $errors[] = 'Paid amount cannot exceed total amount.';

    if (empty($errors)) {
        $pdo = pdo_connect();
        if (!($pdo instanceof \PDO)) {
            $errors[] = 'Database unavailable.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($receipt_no === '') $receipt_no = generate_receipt_no();
                $status = ($paid_amount >= $amount - 0.0001) ? 'paid' : (($paid_amount > 0) ? 'partial' : 'pending');

                if ($form_id > 0) {
                    $stmt = $pdo->prepare("UPDATE fees_records SET school_id = :school_id, student_id = :student_id, class_id = :class_id, amount = :amount, due_date = :due_date, paid_amount = :paid_amount, status = :status, receipt_no = :receipt_no, collected_by = :collected_by, collected_at = :collected_at, updated_at = NOW() WHERE id = :id");
                    $stmt->execute([
                        ':school_id' => $school_id,
                        ':student_id' => $student_id,
                        ':class_id' => $class_id,
                        ':amount' => $amount,
                        ':due_date' => $due_date,
                        ':paid_amount' => $paid_amount,
                        ':status' => $status,
                        ':receipt_no' => $receipt_no,
                        ':collected_by' => current_user_id(),
                        ':collected_at' => $collected_at,
                        ':id' => $form_id
                    ]);
                    $saved_id = $form_id;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO fees_records (school_id, student_id, class_id, amount, due_date, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at)
                        VALUES (:school_id, :student_id, :class_id, :amount, :due_date, :paid_amount, :status, :receipt_no, :collected_by, :collected_at, NOW(), NOW())");
                    $stmt->execute([
                        ':school_id' => $school_id,
                        ':student_id' => $student_id,
                        ':class_id' => $class_id,
                        ':amount' => $amount,
                        ':due_date' => $due_date,
                        ':paid_amount' => $paid_amount,
                        ':status' => $status,
                        ':receipt_no' => $receipt_no,
                        ':collected_by' => current_user_id(),
                        ':collected_at' => $collected_at
                    ]);
                    $saved_id = (int)$pdo->lastInsertId();
                }

                // optionally insert into fee_collections for history
                if (table_exists('fee_collections') && $paid_amount > 0) {
                    $colStmt = $pdo->prepare("INSERT INTO fee_collections (fees_record_id, school_id, student_id, class_id, amount, receipt_no, collected_by, collected_at, created_at)
                        VALUES (:fr, :sc, :st, :cl, :amt, :rc, :cb, :ca, NOW())");
                    $colStmt->execute([
                        ':fr' => $saved_id,
                        ':sc' => $school_id,
                        ':st' => $student_id,
                        ':cl' => $class_id,
                        ':amt' => $paid_amount,
                        ':rc' => $receipt_no,
                        ':cb' => current_user_id(),
                        ':ca' => $collected_at ?? date('Y-m-d H:i:s')
                    ]);
                }

                $pdo->commit();

                // build receipt
                $srow = safe_db_get_one("SELECT first_name, middle_name, last_name FROM students WHERE id = :id LIMIT 1", [':id'=>$student_id]);
                $student_name = $srow ? trim(($srow['first_name'] ?? '') . ' ' . ($srow['middle_name'] ?? '') . ' ' . ($srow['last_name'] ?? '')) : ('#' . $student_id);

                $receipt = [
                    'receipt_no' => $receipt_no,
                    'fees_id' => $saved_id,
                    'student_id' => $student_id,
                    'student_name' => $student_name,
                    'amount_total' => number_format($amount, 2, '.', ''),
                    'paid_amount' => number_format($paid_amount, 2, '.', ''),
                    'pending_after' => number_format(max(0, $amount - $paid_amount), 2, '.', ''),
                    'collected_by' => current_user_id(),
                    'collected_at' => $collected_at ?? date('Y-m-d H:i:s')
                ];

                $messages[] = 'Payment recorded (ID: ' . $saved_id . '). Receipt: ' . $receipt_no;
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                if ($DEBUG) $errors[] = 'DB error: ' . $e->getMessage();
                else $errors[] = 'Failed to save fees record.';
            }
        }
    }
}

/* -------------------------
   Prefill logic for form:
   - If editing, use record values
   - Else if student selected via GET preselect_student_id, compute remaining pending and prefill Amount
   - Client-side also updates Amount when student selection changes (uses data-paid and data-suggest attributes)
   ------------------------- */
$initial = [
    'student_id' => $record ? (int)$record['student_id'] : ($preselect_student_id ?: ''),
    'school_id' => $record ? ($record['school_id'] ?? '') : '',
    'class_id' => $record ? ($record['class_id'] ?? '') : '',
    'amount' => $record ? number_format((float)$record['amount'], 2, '.', '') : ($prefill_amount !== '' ? $prefill_amount : ''),
    'paid_amount' => $record ? number_format((float)$record['paid_amount'], 2, '.', '') : '0.00',
    'due_date' => $record && !empty($record['due_date']) ? date('Y-m-d', strtotime($record['due_date'])) : '',
];

/* Header include */
$pageTitle = 'Collect Payment (fees_records)';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

  <?php if ($receipt): ?>
    <div class="mb-3 no-print">
      <button class="btn btn-primary" onclick="window.print()">Print Receipt (A4)</button>
      <a class="btn btn-outline-secondary" href="?">New Collection</a>
    </div>

    <div class="receipt bg-white p-4 border">
      <div class="d-flex justify-content-between mb-3">
        <div>
          <h5>School / Institution</h5>
          <div>Address line</div>
        </div>
        <div class="text-end">
          <h6>Receipt</h6>
          <div><strong><?php echo e($receipt['receipt_no']); ?></strong></div>
          <div><?php echo e(date('d M Y, H:i', strtotime($receipt['collected_at']))); ?></div>
        </div>
      </div>

      <table class="table table-borderless">
        <tr><th>Student</th><td><?php echo e($receipt['student_name']); ?> (ID: <?php echo (int)$receipt['student_id']; ?>)</td></tr>
        <tr><th>Fees Record</th><td><?php echo (int)$receipt['fees_id']; ?></td></tr>
        <tr><th>Collected by</th><td><?php echo e($receipt['collected_by']); ?></td></tr>
      </table>

      <hr>

      <table class="table">
        <thead><tr><th>Description</th><th class="text-end">Amount (₹)</th></tr></thead>
        <tbody>
          <tr><td>Fees payment</td><td class="text-end"><?php echo e($receipt['paid_amount']); ?></td></tr>
        </tbody>
        <tfoot>
          <tr><th>Total</th><th class="text-end"><?php echo e($receipt['paid_amount']); ?></th></tr>
        </tfoot>
      </table>

      <div>
        <strong>Balance after payment:</strong>
        <div>Total fees: ₹ <?php echo e($receipt['amount_total']); ?></div>
        <div>Paid: ₹ <?php echo e($receipt['paid_amount']); ?></div>
        <div>Pending: ₹ <?php echo e($receipt['pending_after']); ?></div>
      </div>

      <div class="mt-4 text-center small text-muted">System generated receipt.</div>
    </div>

  <?php else: ?>
    <div class="card mb-3 p-3 no-print">
      <form method="post" id="collectForm" class="row g-3">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="fees_id" value="<?php echo e($record ? (int)$record['id'] : ''); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

        <div class="col-md-4">
          <label class="form-label">Student *</label>
          <select name="student_id" id="studentSelect" class="form-select" required>
            <option value="">-- select student --</option>
            <?php foreach ($students as $s):
                $name = trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                $totalSuggest = number_format((float)$s['total_fees'], 2, '.', '');
                // compute paid sum server-side to include in data-paid attr
                $paidSum = get_total_paid_for_student((int)$s['id']);
            ?>
              <option value="<?php echo (int)$s['id']; ?>"
                      data-school="<?php echo e((int)$s['school_id']); ?>"
                      data-class="<?php echo e((int)$s['class_id']); ?>"
                      data-suggest="<?php echo e($totalSuggest); ?>"
                      data-paid="<?php echo e(number_format($paidSum,2,'.','')); ?>"
                      <?php if ($initial['student_id'] !== '' && (int)$initial['student_id'] === (int)$s['id']) echo 'selected'; ?>>
                <?php echo e('['.(int)$s['id'].'] '.$name.' (total ₹'.$totalSuggest.', paid ₹'.number_format($paidSum,2).')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">School</label>
          <select name="school_id" id="schoolSelect" class="form-select">
            <option value="">-- select school --</option>
            <?php foreach ($schools as $sc): ?>
              <option value="<?php echo (int)$sc['id']; ?>" <?php if ($initial['school_id'] !== '' && (int)$initial['school_id'] === (int)$sc['id']) echo 'selected'; ?>><?php echo e($sc['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Class</label>
          <select name="class_id" id="classSelect" class="form-select">
            <option value="">-- select class --</option>
            <?php foreach ($classes as $cl): ?>
              <option value="<?php echo (int)$cl['id']; ?>" <?php if ($initial['class_id'] !== '' && (int)$initial['class_id'] === (int)$cl['id']) echo 'selected'; ?>><?php echo e($cl['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Amount (Total fees) *</label>
          <input name="amount" id="amountInput" type="number" step="0.01" min="0.01" class="form-control" required value="<?php echo e($initial['amount']); ?>">
          <div class="small text-muted">Prefilled as remaining pending (total_fees - previous payments)</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Due Date</label>
          <input name="due_date" type="date" class="form-control" value="<?php echo e($initial['due_date']); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Paid Amount (collected now)</label>
          <input name="paid_amount" id="paidInput" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($initial['paid_amount']); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Collected At</label>
          <input name="collected_at" type="datetime-local" class="form-control" value="<?php echo e(date('Y-m-d\TH:i')); ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Receipt No (leave blank to auto-generate)</label>
          <input name="receipt_no" type="text" class="form-control" value="<?php echo e($record ? ($record['receipt_no'] ?? '') : ''); ?>">
        </div>

        <div class="col-12 text-end">
          <a class="btn btn-outline-secondary" href="/pioneerplayschool01/accounts/pending_fees.php">Cancel</a>
          <button type="submit" class="btn btn-primary">Save & Print</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const studentSelect = document.getElementById('studentSelect');
  const schoolSelect = document.getElementById('schoolSelect');
  const classSelect = document.getElementById('classSelect');
  const amountInput = document.getElementById('amountInput');
  const paidInput = document.getElementById('paidInput');

  function setAmountFromStudentOption(opt) {
    if (!opt) return;
    const total = parseFloat(opt.getAttribute('data-suggest') || '0');
    const paid = parseFloat(opt.getAttribute('data-paid') || '0');
    const pending = Math.max(0, total - paid);
    // Only overwrite amount input when it's empty or equals the original student total
    // or when user hasn't manually changed it (simple heuristic)
    if (!amountInput.value || amountInput.value === '' || parseFloat(amountInput.value) === total) {
      amountInput.value = pending.toFixed(2);
    }
    // Update school/class selects
    const school = opt.getAttribute('data-school');
    const klass = opt.getAttribute('data-class');
    if (school && schoolSelect) schoolSelect.value = school;
    if (klass && classSelect) classSelect.value = klass;
  }

  if (studentSelect) {
    studentSelect.addEventListener('change', function(){
      const opt = studentSelect.options[studentSelect.selectedIndex];
      if (!opt || !opt.value) return;
      setAmountFromStudentOption(opt);
    });

    // If page loaded with a preselected student, apply logic
    const pre = studentSelect.options[studentSelect.selectedIndex];
    if (pre && pre.value) {
      setAmountFromStudentOption(pre);
    }
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>