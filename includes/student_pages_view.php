<?php
/**
 * Full student view (owner + reception). Bootstrap must already have run.
 */
declare(strict_types=1);

$DEBUG = function_exists('panel_debug') ? panel_debug() : false;

if (!table_exists('students')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="container py-4"><div class="alert alert-danger">Students table not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    require_once __DIR__ . '/header.php';
    echo '<div class="container py-4"><div class="alert alert-danger">Missing student id.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$messages = [];
$errors = [];
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $errors[] = 'Delete requires the confirm button on the list page.';
}

$joinSchool = table_exists('schools') ? 'LEFT JOIN schools sch ON sch.id = st.school_id' : '';
$schoolSelect = table_exists('schools') ? "COALESCE(sch.name,'') AS school_name" : "'' AS school_name";
$student = safe_db_get_one(
    "SELECT st.*, COALESCE(c.name,'') AS class_name, {$schoolSelect},
            COALESCE(u.name,'') AS parent_login_name, COALESCE(u.phone,'') AS parent_login_phone,
            COALESCE(u.is_active,1) AS parent_is_active
     FROM students st
     LEFT JOIN classes c ON c.id = st.class_id
     {$joinSchool}
     LEFT JOIN users u ON u.id = st.parent_id
     WHERE st.id = :id LIMIT 1",
    [':id' => $id]
);
if (!$student) {
    require_once __DIR__ . '/header.php';
    echo '<div class="container py-4"><div class="alert alert-warning">Student not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

if (function_exists('student_hydrate_row')) {
    $student = student_hydrate_row($student);
}

if (empty($student['parent_id']) && function_exists('parent_ensure_for_student')) {
    $pdoFix = function_exists('pdo_connect') ? pdo_connect() : null;
    if ($pdoFix instanceof \PDO) {
        try {
            $fixedParentId = parent_ensure_for_student($pdoFix, $student);
            if ($fixedParentId > 0) {
                $student['parent_id'] = $fixedParentId;
                $parentRow = safe_db_get_one('SELECT name, phone, is_active FROM users WHERE id = :id LIMIT 1', [':id' => $fixedParentId]);
                if ($parentRow) {
                    $student['parent_login_name'] = $parentRow['name'] ?? '';
                    $student['parent_login_phone'] = $parentRow['phone'] ?? '';
                    $student['parent_is_active'] = $parentRow['is_active'] ?? 1;
                }
                $messages[] = 'Parent Portal login was missing and has been created from this admission.';
            }
        } catch (Throwable $e) {
            $errors[] = $DEBUG ? ('Parent login repair: ' . $e->getMessage()) : 'Could not create Parent Portal login for this student.';
        }
    }
}

$fullName = function_exists('student_full_name') ? student_full_name($student) : trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
$dash = static function ($v): string {
    $s = trim((string) ($v ?? ''));
    return $s === '' ? '—' : $s;
};
$photoUrl = function_exists('student_photo_url')
    ? student_photo_url((string) ($student['photo_path'] ?? ''))
    : (function_exists('resolve_image_url')
        ? resolve_image_url((string) ($student['photo_path'] ?? ''), '')
        : (string) ($student['photo_path'] ?? ''));
$viewUrl = function_exists('student_view_url') ? student_view_url($id) : ('?id=' . $id);
$editUrl = function_exists('student_edit_url') ? student_edit_url($id) : ('../reception/students_list_edit.php?id=' . $id);
$listUrl = function_exists('student_list_url') ? student_list_url() : '../reception/students_list.php';
$printUrl = function_exists('student_print_url') ? student_print_url($id) : ('../reception/students_form_print.php?id=' . $id);
$cardUrl = function_exists('student_idcard_page_url') ? student_idcard_page_url($id) : ('../owner/id_cards.php?id=' . $id . '&print=1');
$scanUrl = function_exists('student_idcard_scan_url') ? student_idcard_scan_url($id) : '';
$reportUrl = function_exists('site_url')
    ? site_url('/owner/month_reports.php?class_id=' . (int) ($student['class_id'] ?? 0))
    : '../owner/month_reports.php';
$collectUrl = function_exists('site_url')
    ? site_url('/accounts/fees_collection.php?student_id=' . $id)
    : '../accounts/fees_collection.php?student_id=' . $id;

$fees = function_exists('student_fee_summary') ? student_fee_summary($student) : ['total' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
$payments = function_exists('student_fee_payments') ? student_fee_payments($id) : [];
$money = static function (float $n): string {
    return function_exists('format_money') ? format_money($n) : ('₹ ' . number_format($n, 2));
};

$siblings = [];
if (!empty($student['parent_id'])) {
    $siblings = safe_db_get_all(
        "SELECT id, first_name, last_name, class_id, status FROM students WHERE parent_id = :p AND id <> :id ORDER BY first_name ASC",
        [':p' => (int) $student['parent_id'], ':id' => $id]
    ) ?: [];
}

$attendance = ['present' => 0, 'absent' => 0, 'leave' => 0, 'total' => 0];
if (table_exists('attendance')) {
    $attRows = safe_db_get_all(
        "SELECT status, COUNT(*) AS c FROM attendance WHERE student_id = :id GROUP BY status",
        [':id' => $id]
    ) ?: [];
    foreach ($attRows as $ar) {
        $st = strtolower((string) ($ar['status'] ?? ''));
        $c = (int) ($ar['c'] ?? 0);
        if (isset($attendance[$st])) {
            $attendance[$st] = $c;
        }
        $attendance['total'] += $c;
    }
}

$remarks = [];
if (table_exists('student_remarks')) {
    $remarks = safe_db_get_all(
        "SELECT remark, type, `date`, created_at FROM student_remarks WHERE student_id = :id ORDER BY created_at DESC LIMIT 8",
        [':id' => $id]
    ) ?: [];
}

$pageTitle = 'Student: ' . ($fullName !== '' ? $fullName : ('#' . $id));
require_once __DIR__ . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-end gap-2 mb-3 no-print">
  <a class="btn btn-outline-secondary" href="<?php echo e($listUrl); ?>">Students</a>
  <a class="btn btn-outline-warning" href="<?php echo e($editUrl); ?>">Edit</a>
  <a class="btn btn-outline-primary" href="<?php echo e($collectUrl); ?>">Collect Fees</a>
  <a class="btn btn-success" href="<?php echo e($printUrl); ?>" target="_blank">Download PDF</a>
  <a class="btn btn-outline-success" href="<?php echo e($cardUrl); ?>" target="_blank">ID card</a>
  <?php if ($scanUrl !== ''): ?>
    <a class="btn btn-outline-primary" href="<?php echo e($scanUrl); ?>" target="_blank">Visit QR page</a>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?php echo e($reportUrl); ?>">Month report</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>' . e((string) $er) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="print-area">
  <div class="row g-3">
    <div class="col-md-4">
      <div class="card p-3 text-center">
        <?php if ($photoUrl !== ''): ?>
          <img src="<?php echo e($photoUrl); ?>" alt="Photo" class="img-fluid rounded mb-2" style="max-height:220px;object-fit:cover;">
        <?php else: ?>
          <div class="bg-light rounded p-5 mb-2 text-muted">No photo</div>
        <?php endif; ?>
        <div class="small text-muted">Status: <?php echo e($dash($student['status'] ?? '')); ?></div>
      </div>
      <div class="card p-3 mt-3">
        <h6 class="mb-2">Quick info</h6>
        <dl class="row mb-0 small">
          <dt class="col-5">Student ID</dt><dd class="col-7"><?php echo (int) $student['id']; ?></dd>
          <dt class="col-5">Form No</dt><dd class="col-7"><?php echo e($dash($student['form_no'] ?? '')); ?></dd>
          <dt class="col-5">Class</dt><dd class="col-7"><?php echo e($dash($student['class_name'] ?? '')); ?></dd>
          <dt class="col-5">Academic year</dt><dd class="col-7"><?php echo e($dash($student['academic_year'] ?? '')); ?></dd>
          <dt class="col-5">Location</dt><dd class="col-7"><?php echo e($dash($student['location'] ?? '')); ?></dd>
          <dt class="col-5">Admission</dt><dd class="col-7"><?php echo e($dash($student['admission_date'] ?? '')); ?></dd>
          <dt class="col-5">School</dt><dd class="col-7"><?php echo e($dash($student['school_name'] ?? '')); ?></dd>
        </dl>
      </div>
    </div>

    <div class="col-md-8">
      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Student details</h6>
        <div class="row g-2">
          <div class="col-md-6"><strong>First name:</strong> <?php echo e($dash($student['first_name'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Middle name:</strong> <?php echo e($dash($student['middle_name'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Last name:</strong> <?php echo e($dash($student['last_name'] ?? '')); ?></div>
          <div class="col-md-6"><strong>DOB:</strong> <?php echo e($dash($student['dob'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Gender:</strong> <?php echo e($dash($student['gender'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Place of birth:</strong> <?php echo e($dash($student['place_of_birth'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Nationality:</strong> <?php echo e($dash($student['nationality'] ?? '')); ?></div>
          <div class="col-md-6"><strong>Caste:</strong> <?php echo e($dash($student['caste'] ?? '')); ?></div>
          <div class="col-12"><strong>Languages:</strong> <?php echo e($dash($student['languages'] ?? '')); ?></div>
        </div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Address</h6>
        <div><?php echo nl2br(e($dash($student['address'] ?? ''))); ?></div>
        <div class="text-muted small"><?php echo e(trim($dash($student['city'] ?? '') . ', ' . $dash($student['state'] ?? '') . ', ' . $dash($student['country'] ?? '') . ' ' . $dash($student['pin'] ?? ''), ' ,')); ?></div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Parents &amp; guardian</h6>
        <div class="row">
          <div class="col-md-6">
            <strong>Father</strong>
            <div><?php echo e($dash(trim(($student['father_first'] ?? '') . ' ' . ($student['father_middle'] ?? '') . ' ' . ($student['father_last'] ?? '')))); ?></div>
            <div class="small">Phone: <?php echo e($dash($student['father_phone'] ?? '')); ?></div>
            <div class="small">Email: <?php echo e($dash($student['father_email'] ?? '')); ?></div>
            <div class="small">Education: <?php echo e($dash($student['father_edu'] ?? '')); ?></div>
            <div class="small">Profession: <?php echo e($dash($student['father_prof'] ?? '')); ?></div>
            <div class="small">Designation: <?php echo e($dash($student['father_designation'] ?? '')); ?></div>
          </div>
          <div class="col-md-6">
            <strong>Mother</strong>
            <div><?php echo e($dash(trim(($student['mother_first'] ?? '') . ' ' . ($student['mother_middle'] ?? '') . ' ' . ($student['mother_last'] ?? '')))); ?></div>
            <div class="small">Phone: <?php echo e($dash($student['mother_phone'] ?? '')); ?></div>
            <div class="small">Email: <?php echo e($dash($student['mother_email'] ?? '')); ?></div>
            <div class="small">Education: <?php echo e($dash($student['mother_edu'] ?? '')); ?></div>
            <div class="small">Profession: <?php echo e($dash($student['mother_prof'] ?? '')); ?></div>
            <div class="small">Designation: <?php echo e($dash($student['mother_designation'] ?? '')); ?></div>
          </div>
        </div>
        <hr>
        <strong>Guardian / emergency</strong>
        <div><?php echo e($dash($student['guardian_name'] ?? '')); ?></div>
        <div class="small">Relation: <?php echo e($dash($student['guardian_relation'] ?? '')); ?> · Phone: <?php echo e($dash($student['guardian_phone'] ?? '')); ?></div>
        <div class="small">Email: <?php echo e($dash($student['guardian_email'] ?? '')); ?></div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Parent Portal login</h6>
        <?php if (!empty($student['parent_id'])): ?>
          <div><strong><?php echo e($dash($student['parent_login_name'] ?? '')); ?></strong></div>
          <div class="small">Mobile: <?php echo e($dash($student['parent_login_phone'] ?? '')); ?></div>
          <div class="form-text">This number is used for Parent Portal login.</div>
        <?php else: ?>
          <div class="text-muted">No parent login linked. Add a 10-digit father, mother, or SMS OTP mobile on Edit, then open this page again.</div>
        <?php endif; ?>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Education &amp; health</h6>
        <div>Previous school: <?php echo e($dash($student['previous_school'] ?? '')); ?></div>
        <div>Allergies: <?php echo e($dash($student['allergies'] ?? '')); ?></div>
        <div>Health conditions: <?php echo e($dash($student['health_conditions'] ?? '')); ?></div>
        <div>Medications: <?php echo e($dash($student['current_medications'] ?? '')); ?></div>
        <div>Immunization: <?php echo e($dash($student['immunization_records'] ?? '')); ?></div>
        <div>Sibling 1: <?php echo e($dash($student['sibling1'] ?? '')); ?></div>
        <div>Sibling 2: <?php echo e($dash($student['sibling2'] ?? '')); ?></div>
        <div class="mt-2"><?php echo nl2br(e($dash($student['additional_info'] ?? ''))); ?></div>
      </div>

      <div class="card p-3 mb-3">
        <h6 class="fw-bold">Fees setup (admission)</h6>
        <div>Total fees: <?php echo e($dash($student['total_fees'] ?? '')); ?></div>
        <div class="small">Inst. 1: <?php echo e($dash($student['installment1'] ?? '')); ?> · Inst. 2: <?php echo e($dash($student['installment2'] ?? '')); ?> · Inst. 3: <?php echo e($dash($student['installment3'] ?? '')); ?></div>
        <div class="small">Remark: <?php echo e($dash($student['remark'] ?? '')); ?></div>
        <div class="small">Stamp: <?php echo e($dash($student['stamp'] ?? '')); ?></div>
        <div class="small">Parent signature: <?php echo e($dash($student['parent_signature'] ?? '')); ?></div>
      </div>
    </div>
  </div>

  <div class="card p-3 mt-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h6 class="fw-bold mb-0">Payment records</h6>
      <a class="btn btn-sm btn-primary no-print" href="<?php echo e($collectUrl); ?>">Add payment</a>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-md-4"><div class="border rounded p-2 text-center">Total fee<br><strong><?php echo e($money((float) $fees['total'])); ?></strong></div></div>
      <div class="col-md-4"><div class="border rounded p-2 text-center text-success">Paid<br><strong><?php echo e($money((float) $fees['paid'])); ?></strong></div></div>
      <div class="col-md-4"><div class="border rounded p-2 text-center text-danger">Remaining / pending<br><strong><?php echo e($money((float) $fees['remaining'])); ?></strong></div></div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-striped mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Receipt</th>
            <th>Type</th>
            <th class="text-end">Amount</th>
            <th>Note</th>
            <th>Collected by</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="7" class="text-muted">No payment recorded yet.</td></tr>
        <?php else: foreach ($payments as $p):
            $rid = (int) ($p['id'] ?? 0);
            $receiptUrl = function_exists('site_url')
                ? site_url('/accounts/receipt_print.php?id=' . $rid)
                : '../accounts/receipt_print.php?id=' . $rid;
            ?>
          <tr>
            <td><?php echo e((string) ($p['collected_at'] ?? $p['created_at'] ?? '')); ?></td>
            <td><?php echo e((string) ($p['receipt_display'] ?? '')); ?></td>
            <td><?php echo e((string) ($p['payment_type'] ?? '')); ?></td>
            <td class="text-end"><?php echo e($money((float) ($p['paid_amount'] ?? 0))); ?></td>
            <td><?php echo e((string) ($p['payment_note'] ?? '')); ?></td>
            <td><?php echo e((string) ($p['collector_name'] ?? '')); ?></td>
            <td class="no-print"><?php if ($rid > 0): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($receiptUrl); ?>" target="_blank">Receipt</a><?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold">Attendance</h6>
        <?php if ($attendance['total'] <= 0): ?>
          <div class="text-muted">No attendance marked yet.</div>
        <?php else: ?>
          <div>Present: <strong><?php echo (int) $attendance['present']; ?></strong></div>
          <div>Absent: <strong><?php echo (int) $attendance['absent']; ?></strong></div>
          <div>Leave: <strong><?php echo (int) $attendance['leave']; ?></strong></div>
          <div class="small text-muted">Total days: <?php echo (int) $attendance['total']; ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold">Siblings (same parent login)</h6>
        <?php if (!$siblings): ?>
          <div class="text-muted">No sibling linked.</div>
        <?php else: foreach ($siblings as $sib):
            $sid = (int) ($sib['id'] ?? 0);
            $sname = trim((string) ($sib['first_name'] ?? '') . ' ' . (string) ($sib['last_name'] ?? ''));
            $surl = function_exists('student_view_url') ? student_view_url($sid) : ('?id=' . $sid);
            ?>
          <div><a href="<?php echo e($surl); ?>"><?php echo e($sname !== '' ? $sname : ('Student #' . $sid)); ?></a>
            <span class="small text-muted"><?php echo e((string) ($sib['status'] ?? '')); ?></span></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card p-3 h-100">
        <h6 class="fw-bold">Teacher remarks</h6>
        <?php if (!$remarks): ?>
          <div class="text-muted">No remarks yet.</div>
        <?php else: foreach ($remarks as $rm): ?>
          <div class="mb-2">
            <div class="small text-muted"><?php echo e((string) ($rm['date'] ?? $rm['created_at'] ?? '')); ?> · <?php echo e((string) ($rm['type'] ?? 'note')); ?></div>
            <div><?php echo e((string) ($rm['remark'] ?? '')); ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
