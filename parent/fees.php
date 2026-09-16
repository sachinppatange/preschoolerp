<?php
/**
 * parent/fees.php
 *
 * Parent -> Fees & Payments page (uses students and fees_records).
 *
 * Behavior:
 * - Requires parent login ($_SESSION['parent_auth_user'])
 * - Loads linked children from parents_children (fallback to students.parent_id)
 * - Uses students.total_fees when present to calculate pending = total_fees - SUM(paid_amount)
 * - If students.total_fees not present, uses fees_records to compute pending as SUM(amount) - SUM(paid_amount)
 * - Shows recent payments from fees_records (fields used: id, school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at)
 * - Allows parent to record an offline payment: inserts a fees_records row with paid_amount, receipt_no, collected_at, created_at and status='paid'
 * - Exports payments CSV
 *
 * Place at: /pioneerplayschool01/parent/fees.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- Require parent login ---------- */
/* ---------- Initialize messages/errors ---------- */
$messages = [];
$errors = [];

/* ---------- Parent identity ---------- */
$parentId = panel_parent_context_id();
$parentSession = auth_user() ?? [];
$parentName = auth_user_name('Parent');/* ---------- Detect useful tables ---------- */
$hasStudents = table_exists('students');
$hasParentsChildren = table_exists('parents_children');
$hasFeesRecords = table_exists('fees_records');

/* ---------- fees_records table name ---------- */
$feesTable = $hasFeesRecords ? 'fees_records' : null;

/* ---------- Load linked children (mapping) ---------- */
$childIds = [];
$childMappings = [];
if ($hasParentsChildren) {
    $childMappings = safe_db_get_all("SELECT id, parent_user_id, child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC", [':pid'=>$parentId]);
    foreach ($childMappings as $m) $childIds[] = (int)$m['child_student_id'];
}

/* fallback: students.parent_id / father_id / mother_id */
if (empty($childIds) && $hasStudents) {
    $rows = safe_db_get_all("SELECT id FROM students WHERE parent_id = :pid OR father_id = :pid OR mother_id = :pid", [':pid'=>$parentId]);
    foreach ($rows as $r) $childIds[] = (int)$r['id'];
}

/* ---------- Load student rows for linked children ---------- */
$children = [];
if (!empty($childIds) && $hasStudents) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $sql = "SELECT
                id, school_id, first_name, middle_name, last_name, dob, class_id, parent_id, photo_path, admission_date,
                status, created_at, updated_at, form_no, location, total_fees,
                father_first, father_middle, father_last, father_phone,
                mother_first, mother_middle, mother_last, mother_phone
            FROM students WHERE id IN ($ph)";
    $rows = safe_db_get_all($sql, $childIds);
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    foreach ($childIds as $cid) {
        if (isset($byId[$cid])) $children[] = $byId[$cid];
        else $children[] = ['id'=>$cid,'first_name'=>'Student','middle_name'=>'','last_name'=>'#'.$cid,'class_id'=>null,'form_no'=>null,'admission_date'=>null,'photo_path'=>null,'placeholder'=>true];
    }
}

/* ---------- Compute pending per child ---------- */
$pendingByChild = [];
if (!empty($childIds)) {
    if ($hasFeesRecords) {
        // If students.total_fees exists, use it; otherwise compute from fees_records sums
        try {
            // Get paid sums and total due sums per student
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            $paidRows = safe_db_get_all("SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum, COALESCE(SUM(amount),0) AS due_sum FROM {$feesTable} WHERE student_id IN ($ph) GROUP BY student_id", $childIds);
            $paidMap = []; $dueMap = [];
            foreach ($paidRows as $r) { $paidMap[(int)$r['student_id']] = (float)$r['paid_sum']; $dueMap[(int)$r['student_id']] = (float)$r['due_sum']; }

            foreach ($children as $c) {
                $cid = (int)$c['id'];
                $totalFees = isset($c['total_fees']) && $c['total_fees'] !== '' ? (float)$c['total_fees'] : null;
                $paid = $paidMap[$cid] ?? 0.0;
                $dueSum = $dueMap[$cid] ?? 0.0;
                if ($totalFees !== null) {
                    $pendingByChild[$cid] = max(0.0, $totalFees - $paid);
                } else {
                    // use fees_records data (sum(amount) - sum(paid_amount))
                    $pendingByChild[$cid] = max(0.0, $dueSum - $paid);
                }
            }
        } catch (Throwable $e) {
            if ($DEBUG) error_log('pending calc error: '.$e->getMessage());
            foreach ($childIds as $cid) $pendingByChild[$cid] = null;
        }
    } else {
        // No fees_records table: we can only show students.total_fees if present
        foreach ($children as $c) {
            $cid = (int)$c['id'];
            $totalFees = isset($c['total_fees']) && $c['total_fees'] !== '' ? (float)$c['total_fees'] : null;
            $pendingByChild[$cid] = $totalFees;
        }
    }
}

/* ---------- Recent payments (from fees_records) ---------- */
$recentPayments = [];
if ($hasFeesRecords && !empty($childIds)) {
    try {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $recentPayments = safe_db_get_all(
            "SELECT id, school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at
             FROM {$feesTable}
             WHERE student_id IN ($ph)
             ORDER BY COALESCE(collected_at, created_at) DESC
             LIMIT 12",
            $childIds
        );
    } catch (Throwable $e) {
        if ($DEBUG) error_log('recentPayments error: '.$e->getMessage());
        $recentPayments = [];
    }
}

/* ---------- Handle offline payment recording by parent ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (!$hasFeesRecords) {
            $errors[] = 'Payment recording not supported on this system.';
        } else {
            $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $paid_amount = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0.0;
            $method = trim((string)($_POST['method'] ?? 'offline'));
            $note = trim((string)($_POST['note'] ?? 'Recorded by parent (offline)'));
            if ($student_id <= 0 || $paid_amount <= 0) {
                $errors[] = 'Valid student and amount required.';
            } elseif (!in_array($student_id, $childIds, true)) {
                $errors[] = 'You are not linked to the selected student.';
            } else {
                try {
                    // find student to populate school_id/class_id if possible
                    $stu = null;
                    foreach ($children as $c) { if ((int)$c['id'] === $student_id) { $stu = $c; break; } }
                    $school_id = $stu['school_id'] ?? null;
                    $class_id = $stu['class_id'] ?? null;
                    // Create a receipt no
                    $receipt = 'P' . time() . rand(100,999);
                    // Insert row: amount = paid_amount (we don't know due amount line), paid_amount set; set status 'paid'
                    $ok = safe_db_run(
                        "INSERT INTO {$feesTable} (school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at)
                         VALUES (:school_id, :student_id, :class_id, :amount, :paid_amount, :status, :receipt, :collected_by, NOW(), NOW(), NOW())",
                        [
                            ':school_id' => $school_id,
                            ':student_id' => $student_id,
                            ':class_id' => $class_id,
                            ':amount' => $paid_amount,
                            ':paid_amount' => $paid_amount,
                            ':status' => 'paid',
                            ':receipt' => $receipt,
                            ':collected_by' => null,
                        ]
                    );
                    if ($ok) {
                        $messages[] = 'Payment recorded (offline). Receipt: ' . e($receipt) . '.';
                        // refresh recent payments and pending
                        if (!empty($childIds)) {
                            $ph = implode(',', array_fill(0, count($childIds), '?'));
                            $recentPayments = safe_db_get_all(
                                "SELECT id, school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at
                                 FROM {$feesTable}
                                 WHERE student_id IN ($ph)
                                 ORDER BY COALESCE(collected_at, created_at) DESC
                                 LIMIT 12",
                                $childIds
                            );
                            // recompute pending sums
                            $paidRows = safe_db_get_all("SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum, COALESCE(SUM(amount),0) AS due_sum FROM {$feesTable} WHERE student_id IN ($ph) GROUP BY student_id", $childIds);
                            $paidMap = []; $dueMap = [];
                            foreach ($paidRows as $r) { $paidMap[(int)$r['student_id']] = (float)$r['paid_sum']; $dueMap[(int)$r['student_id']] = (float)$r['due_sum']; }
                            foreach ($children as $c) {
                                $cid = (int)$c['id'];
                                $totalFees = isset($c['total_fees']) && $c['total_fees'] !== '' ? (float)$c['total_fees'] : null;
                                $paid = $paidMap[$cid] ?? 0.0;
                                $dueSum = $dueMap[$cid] ?? 0.0;
                                if ($totalFees !== null) $pendingByChild[$cid] = max(0.0, $totalFees - $paid);
                                else $pendingByChild[$cid] = max(0.0, $dueSum - $paid);
                            }
                        }
                    } else {
                        $errors[] = 'Failed to record payment. Contact admin.';
                    }
                } catch (Throwable $e) {
                    if ($DEBUG) error_log('record payment error: '.$e->getMessage());
                    $errors[] = 'Failed to record payment. Contact admin.';
                }
            }
        }
    }
}

/* ---------- Export payments CSV (action=export_payments) ---------- */
if (($action = ($_GET['action'] ?? '')) === 'export_payments') {
    if ($hasFeesRecords && !empty($childIds)) {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $rows = safe_db_get_all(
            "SELECT id, school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at FROM {$feesTable} WHERE student_id IN ($ph) ORDER BY COALESCE(collected_at, created_at) DESC",
            $childIds
        );
    } else {
        $rows = [];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payments_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','school_id','student_id','class_id','amount','paid_amount','status','receipt_no','collected_by','collected_at','created_at','updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['student_id'] ?? '',
            $r['class_id'] ?? '',
            $r['amount'] ?? '',
            $r['paid_amount'] ?? '',
            $r['status'] ?? '',
            $r['receipt_no'] ?? '',
            $r['collected_by'] ?? '',
            $r['collected_at'] ?? '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- Render page ---------- */
$pageTitle = 'Fees & Payments';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<?php if (!empty($messages) && is_array($messages)): foreach ($messages as $m): ?>
  <div class="alert alert-success"><?php echo e($m); ?></div>
<?php endforeach; endif; ?>

<?php if (!empty($errors) && is_array($errors)): foreach ($errors as $er): ?>
  <div class="alert alert-danger"><?php echo e($er); ?></div>
<?php endforeach; endif; ?>

<div class="row g-3">
  <div class="col-12 col-md-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">My Children & Fees</h6>

        <?php if (empty($children)): ?>
          <div class="small-muted">No children linked to your account.</div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($children as $c): $cid = (int)$c['id']; ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                  <div class="me-2">
                    <?php if (!empty($c['photo_path'])): ?>
                      <img src="<?php echo e($c['photo_path']); ?>" alt="" class="child-photo">
                    <?php else: ?>
                      <div class="child-photo" style="background:#f1f1f1"></div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="fw-semibold"><?php echo e(trim((($c['first_name'] ?? '') . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['last_name'] ?? '')))); ?></div>
                    <div class="small-muted">Class: <?php echo e($c['class_id'] ?? '—'); ?> • Form: <?php echo e($c['form_no'] ?? '—'); ?></div>
                  </div>
                </div>

                <div class="text-end" style="min-width:170px">
                  <div class="mb-1">
                    <?php
                      $pend = array_key_exists($cid, $pendingByChild) ? $pendingByChild[$cid] : null;
                      if ($pend === null) { echo '<span class="small-muted">Pending: —</span>'; }
                      else { echo '<span class="fw-semibold">₹ '.number_format($pend,2).'</span>'; }
                    ?>
                  </div>
                  <div>
                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo $cid; ?>">View</button>
                    <a class="btn btn-sm btn-outline-primary" href="?action=pay_online&student_id=<?php echo $cid; ?>">Pay</a>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      </div>
    </div>

    <div class="card mt-3 shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Record Offline Payment</h6>
        <?php if (!$hasFeesRecords): ?>
          <div class="small-muted">Your system does not support recording payments. Contact administrator.</div>
        <?php else: ?>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?php echo e(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="record_payment">
            <div class="col-12">
              <label class="form-label">Select child</label>
              <select name="student_id" class="form-select" required>
                <?php foreach ($children as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>"><?php echo e(trim((($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')))); ?> (ID: <?php echo (int)$c['id']; ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Amount (₹)</label>
              <input type="number" step="0.01" min="0.01" name="paid_amount" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Method</label>
              <select name="method" class="form-select">
                <option value="offline">Offline (Cash/Cheque)</option>
                <option value="bank">Bank Transfer</option>
                <option value="upi">UPI</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Note (optional)</label>
              <input name="note" class="form-control" placeholder="Receipt number or note">
            </div>
            <div class="col-12 text-end">
              <button class="btn btn-primary">Record Payment</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Recent Payments</h6>
        <?php if (empty($recentPayments)): ?>
          <div class="small-muted">No recent payments found.</div>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($recentPayments as $p): ?>
              <li class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold">₹ <?php echo number_format((float)($p['paid_amount'] ?? $p['amount'] ?? 0),2); ?></div>
                  <div class="small-muted">Student ID: <?php echo (int)($p['student_id'] ?? 0); ?> • <?php echo e(substr((string)($p['collected_at'] ?? $p['created_at'] ?? ''),0,16)); ?> • <?php echo e($p['status'] ?? ''); ?></div>
                  <?php if (!empty($p['receipt_no'])): ?><div class="small-muted">Receipt: <?php echo e($p['receipt_no']); ?></div><?php endif; ?>
                </div>
                <div class="text-end small-muted">
                  <?php echo e($p['collected_by'] ?? ''); ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <div class="mt-3 text-end">
          <a class="btn btn-sm btn-outline-secondary" href="?action=export_payments">Export Payments CSV</a>
        </div>
      </div>
    </div>

    <div class="card mt-3 shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Help / Notes</h6>
        <div class="small-muted">
          - Pending amounts are computed using students.total_fees when present, otherwise from fees records (sum(amount)-sum(paid_amount)).<br>
          - Recording an offline payment inserts a fees_records row and marks it as 'paid' (admin can adjust).<br>
          - Replace the "Pay" link with your payment gateway flow when integrating online payments.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- student view modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Student details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&student_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ if (!resp.ok) throw new Error('Network'); return resp.text(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';

?>