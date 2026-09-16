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

$pageTitle = 'Student: ' . ($fullName !== '' ? $fullName : ('#' . $id));
require_once __DIR__ . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-end gap-2 mb-3 no-print">
  <a class="btn btn-outline-secondary" href="<?php echo e($listUrl); ?>">Students</a>
  <a class="btn btn-outline-warning" href="<?php echo e($editUrl); ?>">Edit</a>
  <a class="btn btn-success" href="<?php echo e($printUrl); ?>" target="_blank">Download PDF</a>
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
          <div class="text-muted">No parent login linked.</div>
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
        <h6 class="fw-bold">Fees &amp; office</h6>
        <div>Total fees: <?php echo e($dash($student['total_fees'] ?? '')); ?></div>
        <div class="small">Inst. 1: <?php echo e($dash($student['installment1'] ?? '')); ?> · Inst. 2: <?php echo e($dash($student['installment2'] ?? '')); ?> · Inst. 3: <?php echo e($dash($student['installment3'] ?? '')); ?></div>
        <div class="small">Remark: <?php echo e($dash($student['remark'] ?? '')); ?></div>
        <div class="small">Stamp: <?php echo e($dash($student['stamp'] ?? '')); ?></div>
        <div class="small">Parent signature: <?php echo e($dash($student['parent_signature'] ?? '')); ?></div>
      </div>
    </div>
  </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
