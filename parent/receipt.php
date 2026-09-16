<?php
/**
 * parent/fees.php
 *
 * Parent -> Fees & Payments page
 *
 * Full, defensive implementation that:
 * - Requires parent login (expects $_SESSION['parent_auth_user'])
 * - Reads linked children from parents_children (fields used: id, parent_user_id, child_student_id)
 *   with fallback to students.parent_id / father_id / mother_id
 * - Uses students table (schema you provided) for child info
 * - Uses fees_records table (schema you provided) for payments:
 *     id, school_id, student_id, class_id, amount, due_date, paid_amount, status, receipt_no,
 *     collected_by, collected_at, created_at, updated_at
 * - Shows per-child pending amount (computed using students.total_fees when present, otherwise fees_records sums)
 * - Shows recent payments (from fees_records) and allows exporting CSV of payments
 * - Allows parent to record an offline payment (inserts into fees_records) with CSRF protection
 * - Provides a "View" modal for full student details (AJAX fragment via ?action=view_student&id=...)
 *
 * Save as: /pioneerplayschool01/parent/fees.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

$messages = [];
$errors = [];

/* CSRF helpers */

/* ---------------------------
   Identify tables and user
   --------------------------- */
$hasStudents = table_exists('students');
$hasParentsChildren = table_exists('parents_children');
$hasFeesRecords = table_exists('fees_records');

$feesTable = $hasFeesRecords ? 'fees_records' : null;

$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

/* ---------------------------
   Handle actions (student view fragment, export, record payment)
   --------------------------- */
$action = $_REQUEST['action'] ?? 'list';

/* VIEW STUDENT (AJAX fragment used by modal) */
if ($action === 'view_student' && !empty($_GET['id'])) {
    $sid = (int)$_GET['id'];
    if ($sid <= 0) { echo '<div class="p-3 text-danger">Invalid student id</div>'; exit; }

    // verify mapping for this parent
    $allowed = false;
    if ($hasParentsChildren) {
        $m = safe_db_get_one("SELECT 1 FROM parents_children WHERE parent_user_id = :pid AND child_student_id = :sid LIMIT 1", [':pid'=>$parentId, ':sid'=>$sid]);
        if ($m) $allowed = true;
    }
    if (!$allowed && $hasStudents) {
        $r = safe_db_get_one("SELECT parent_id FROM students WHERE id = :sid LIMIT 1", [':sid'=>$sid]);
        if ($r && isset($r['parent_id']) && (int)$r['parent_id'] === $parentId) $allowed = true;
    }
    if (!$allowed) { echo '<div class="p-3 text-muted">Not authorized to view this student.</div>'; exit; }

    if (!$hasStudents) { echo '<div class="p-3 text-muted">students table missing.</div>'; exit; }

    $student = safe_db_get_one("SELECT * FROM students WHERE id = :sid LIMIT 1", [':sid'=>$sid]);
    if (!$student) { echo '<div class="p-3 text-muted">Student not found.</div>'; exit; }

    // Render a compact detail fragment
    echo '<div class="p-3">';
    echo '<div class="d-flex gap-3 mb-3">';
    if (!empty($student['photo_path'])) {
        echo '<img src="'.e($student['photo_path']).'" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:6px">';
    } else {
        echo '<div style="width:120px;height:120px;background:#f1f1f1;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#777">No Photo</div>';
    }
    echo '<div>';
    echo '<h5 class="mb-1">'.e(trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))).'</h5>';
    echo '<div class="small-muted">ID: '.(int)$student['id'].' • Form: '.e($student['form_no'] ?? '—').'</div>';
    echo '<div class="small-muted">Admission: '.e(substr((string)($student['admission_date'] ?? ''),0,10) ?: '—').'</div>';
    echo '</div></div>';

    echo '<div><strong>Contact</strong><div class="small-muted">Father: '.e($student['father_first'] ?? '—').' '.e($student['father_phone'] ?? '').' | Mother: '.e($student['mother_first'] ?? '—').' '.e($student['mother_phone'] ?? '').'</div></div>';

    echo '<div class="mt-3"><strong>Address</strong><div class="small-muted">'.e(trim(($student['address'] ?? '') . ', ' . ($student['city'] ?? '') . ', ' . ($student['state'] ?? ''))).'</div></div>';

    echo '</div>';
    exit;
}

/* EXPORT payments CSV */
if ($action === 'export_payments') {
    if (!$hasFeesRecords) {
        http_response_code(404); echo "No payments table."; exit;
    }
    // fetch linked child ids
    $childIds = [];
    if ($hasParentsChildren) {
        $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid", [':pid'=>$parentId]);
        foreach ($maps as $m) $childIds[] = (int)$m['child_student_id'];
    } else {
        // fallback: students.parent_id
        if ($hasStudents) {
            $rows = safe_db_get_all("SELECT id FROM students WHERE parent_id = :pid", [':pid'=>$parentId]);
            foreach ($rows as $r) $childIds[] = (int)$r['id'];
        }
    }
    if (empty($childIds)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=payments_empty.csv');
        echo "No payments\n"; exit;
    }
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $rows = safe_db_get_all("SELECT id, school_id, student_id, class_id, amount, paid_amount, due_date, status, receipt_no, collected_by, collected_at, created_at, updated_at FROM {$feesTable} WHERE student_id IN ($ph) ORDER BY COALESCE(collected_at, created_at) DESC", $childIds);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payments_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','school_id','student_id','class_id','amount','paid_amount','due_date','status','receipt_no','collected_by','collected_at','created_at','updated_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['student_id'] ?? '',
            $r['class_id'] ?? '',
            $r['amount'] ?? '',
            $r['paid_amount'] ?? '',
            $r['due_date'] ?? '',
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

/* ---------------------------
   Load linked children for display
   --------------------------- */
$childIds = [];
if ($hasParentsChildren) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC", [':pid'=>$parentId]);
    foreach ($maps as $m) $childIds[] = (int)$m['child_student_id'];
}
if (empty($childIds) && $hasStudents) {
    $rows = safe_db_get_all("SELECT id FROM students WHERE parent_id = :pid OR father_id = :pid OR mother_id = :pid", [':pid'=>$parentId]);
    foreach ($rows as $r) $childIds[] = (int)$r['id'];
}

$children = [];
if (!empty($childIds) && $hasStudents) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $students = safe_db_get_all("SELECT id, school_id, first_name, middle_name, last_name, form_no, class_id, admission_date, photo_path, total_fees, father_first, father_phone, mother_first, mother_phone FROM students WHERE id IN ($ph)", $childIds);
    $byId = [];
    foreach ($students as $s) $byId[(int)$s['id']] = $s;
    foreach ($childIds as $cid) {
        if (isset($byId[$cid])) $children[] = $byId[$cid];
        else $children[] = ['id'=>$cid, 'first_name'=>'Student', 'middle_name'=>'', 'last_name'=>'#'.$cid, 'form_no'=>null, 'class_id'=>null, 'admission_date'=>null, 'photo_path'=>null, 'placeholder'=>true];
    }
}

/* ---------------------------
   Compute pending for each child using fees_records and/or students.total_fees
   --------------------------- */
$pendingByChild = [];
if (!empty($childIds)) {
    if ($hasFeesRecords) {
        try {
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            // sum paid_amount and amount (due) per student
            $sums = safe_db_get_all("SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum, COALESCE(SUM(amount),0) AS due_sum FROM {$feesTable} WHERE student_id IN ($ph) GROUP BY student_id", $childIds);
            $paidMap = []; $dueMap = [];
            foreach ($sums as $r) { $paidMap[(int)$r['student_id']] = (float)$r['paid_sum']; $dueMap[(int)$r['student_id']] = (float)$r['due_sum']; }
            foreach ($children as $c) {
                $cid = (int)$c['id'];
                $totalFees = isset($c['total_fees']) && $c['total_fees'] !== '' ? (float)$c['total_fees'] : null;
                $paid = $paidMap[$cid] ?? 0.0;
                $dueSum = $dueMap[$cid] ?? 0.0;
                if ($totalFees !== null) $pendingByChild[$cid] = max(0.0, $totalFees - $paid);
                else $pendingByChild[$cid] = max(0.0, $dueSum - $paid);
            }
        } catch (Throwable $e) {
            if ($DEBUG) error_log('pending calc error: '.$e->getMessage());
            foreach ($childIds as $cid) $pendingByChild[$cid] = null;
        }
    } else {
        foreach ($children as $c) {
            $cid = (int)$c['id'];
            $pendingByChild[$cid] = isset($c['total_fees']) && $c['total_fees'] !== '' ? (float)$c['total_fees'] : null;
        }
    }
}

/* ---------------------------
   Recent payments (for display)
   --------------------------- */
$recentPayments = [];
if ($hasFeesRecords && !empty($childIds)) {
    try {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $recentPayments = safe_db_get_all("SELECT id, school_id, student_id, class_id, amount, paid_amount, due_date, status, receipt_no, collected_by, collected_at, created_at FROM {$feesTable} WHERE student_id IN ($ph) ORDER BY COALESCE(collected_at, created_at) DESC LIMIT 12", $childIds);
    } catch (Throwable $e) {
        if ($DEBUG) error_log('recentPayments error: '.$e->getMessage());
        $recentPayments = [];
    }
}

/* ---------------------------
   Handle recording offline payment by parent
   --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        if (!$hasFeesRecords) {
            $errors[] = 'Payments are not supported on this system.';
        } else {
            $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $paid_amount = isset($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0.0;
            $note = trim((string)($_POST['note'] ?? 'Recorded by parent'));
            if ($student_id <= 0 || $paid_amount <= 0) {
                $errors[] = 'Select a child and enter a valid amount.';
            } elseif (!in_array($student_id, $childIds, true)) {
                $errors[] = 'Selected student is not linked to your account.';
            } else {
                try {
                    // Try to populate school_id/class_id from students table if available
                    $stu = null;
                    foreach ($children as $c) { if ((int)$c['id'] === $student_id) { $stu = $c; break; } }
                    $school_id = $stu['school_id'] ?? null;
                    $class_id = $stu['class_id'] ?? null;
                    $receipt = 'P' . time() . rand(100,999);
                    $ok = safe_db_run("INSERT INTO {$feesTable} (school_id, student_id, class_id, amount, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at) VALUES (:school_id, :student_id, :class_id, :amount, :paid_amount, :status, :receipt, :collected_by, NOW(), NOW(), NOW())",
                        [':school_id'=>$school_id, ':student_id'=>$student_id, ':class_id'=>$class_id, ':amount'=>$paid_amount, ':paid_amount'=>$paid_amount, ':status'=>'paid', ':receipt'=>$receipt, ':collected_by'=>null]);
                    if ($ok) {
                        $messages[] = 'Offline payment recorded. Receipt: ' . e($receipt);
                        // refresh recentPayments and pending
                        if (!empty($childIds)) {
                            $ph = implode(',', array_fill(0, count($childIds), '?'));
                            $recentPayments = safe_db_get_all("SELECT id, school_id, student_id, class_id, amount, paid_amount, due_date, status, receipt_no, collected_by, collected_at, created_at FROM {$feesTable} WHERE student_id IN ($ph) ORDER BY COALESCE(collected_at, created_at) DESC LIMIT 12", $childIds);
                            $sums = safe_db_get_all("SELECT student_id, COALESCE(SUM(paid_amount),0) AS paid_sum, COALESCE(SUM(amount),0) AS due_sum FROM {$feesTable} WHERE student_id IN ($ph) GROUP BY student_id", $childIds);
                            $paidMap = []; $dueMap = [];
                            foreach ($sums as $r) { $paidMap[(int)$r['student_id']] = (float)$r['paid_sum']; $dueMap[(int)$r['student_id']] = (float)$r['due_sum']; }
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

/* ---------------------------
   Render HTML
   --------------------------- */
$pageTitle = 'Fees & Payments';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?php echo e($pageTitle); ?></h1>
      <div class="small-muted">Welcome, <?php echo e($parent['name'] ?? 'Parent'); ?></div>
    </div>
    <div>
      <a class="btn btn-outline-secondary btn-sm" href="../parent/profile.php">Profile</a>
      <a class="btn btn-danger btn-sm" href="../parent/logout.php">Logout</a>
    </div>
  </div>

  <?php if (!empty($messages)): foreach ($messages as $m): ?>
    <div class="alert alert-success"><?php echo e($m); ?></div>
  <?php endforeach; endif; ?>

  <?php if (!empty($errors)): foreach ($errors as $er): ?>
    <div class="alert alert-danger"><?php echo e($er); ?></div>
  <?php endforeach; endif; ?>

  <div class="row g-3">
    <div class="col-12 col-md-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">My Children & Balances</h6>

          <?php if (empty($children)): ?>
            <div class="small-muted">No linked children found.</div>
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
                        if ($pend === null) echo '<span class="small-muted">Pending: —</span>';
                        else echo '<span class="fw-semibold">₹ ' . number_format((float)$pend, 2) . '</span>';
                      ?>
                    </div>
                    <div>
                      <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#studentViewModal" data-id="<?php echo $cid; ?>">View</button>
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
            <div class="small-muted">Payment recording not available on this installation.</div>
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
                <label class="form-label">Note</label>
                <input name="note" class="form-control" placeholder="Optional note / receipt no">
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
            <div class="small-muted">No recent payments.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentPayments as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold">₹ <?php echo number_format((float)($p['paid_amount'] ?? $p['amount'] ?? 0), 2); ?></div>
                    <div class="small-muted">Student ID: <?php echo (int)($p['student_id'] ?? 0); ?> • <?php echo e(substr((string)($p['collected_at'] ?? $p['created_at'] ?? ''), 0, 16)); ?> • <?php echo e($p['status'] ?? ''); ?></div>
                    <?php if (!empty($p['receipt_no'])): ?><div class="small-muted">Receipt: <?php echo e($p['receipt_no']); ?></div><?php endif; ?>
                  </div>
                  <div class="text-end small-muted">
                    <?php echo e($p['collected_by'] ?? ''); ?><br>
                    <a class="btn btn-sm btn-outline-secondary mt-2" href="receipt.php?payment_id=<?php echo (int)$p['id']; ?>">Download Receipt</a>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div class="mt-3 text-end">
            <?php if ($hasFeesRecords): ?>
              <a class="btn btn-sm btn-outline-secondary" href="?action=export_payments">Export Payments CSV</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card mt-3 shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">Notes</h6>
          <div class="small-muted">
            - Pending amounts are computed using students.total_fees when present, otherwise from fees_records (sum(amount) - sum(paid_amount)).<br>
            - "Record Offline Payment" inserts a fees_records row marked as paid for school staff to verify. It does not process an online payment.<br>
            - Use the "Pay" link to integrate or redirect to your payment gateway.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- student view modal -->
<div class="modal fade" id="studentViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Student details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="studentViewBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var modal = document.getElementById('studentViewModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('studentViewBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view_student&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(resp.statusText); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load student details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>