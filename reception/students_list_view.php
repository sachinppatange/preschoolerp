<?php
/**
 * reception/students_list_view.php
 *
 * Read-only Student View page for Reception (mobile friendly).
 * - This file is made to work with your current reception/students_list.php
 *   which now links to:
 *     ../reception/students_list_view.php?id=STUDENT_ID
 *
 * IMPORTANT (as per your instruction):
 * - DB logic / helper functions style kept SAME pattern as your admission.php / students_list.php
 * - No existing project functions are modified
 * - This is a NEW page: only reads from DB and shows details nicely
 *
 * Place at: /pioneerplayschool01/reception/students_list_view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth guard */
/* Optional includes (project-specific) */
/* Validate required table */
if (!table_exists('students')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>students</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Input */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-warning">Invalid student id.</div><a class="btn btn-outline-secondary" href="../reception/students_list.php">Back</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Fetch student row (includes class + parent names if available) */
$sql = "
    SELECT 
        st.*,
        COALESCE(c.name,'') AS class_name,
        COALESCE(u.name,'') AS parent_name,
        COALESCE(u.phone,'') AS parent_phone
    FROM students st
    LEFT JOIN classes c ON c.id = st.class_id
    LEFT JOIN users u ON u.id = st.parent_id
    WHERE st.id = :id
    LIMIT 1
";
$student = safe_db_get_one($sql, [':id' => $id]);

if (!$student) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-info">Student not found.</div><a class="btn btn-outline-secondary" href="../reception/students_list.php">Back</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Page header */
$pageTitle = 'Student Details';
require_once __DIR__ . '/../includes/header.php';
/* small helper for date */
$fmtDate = function($v) {
    if (empty($v)) return '—';
    $ts = strtotime((string)$v);
    if (!$ts) return e((string)$v);
    return e(date('d M Y', $ts));
};
?>
<style>
  .card-soft{border-radius:14px;box-shadow:0 10px 24px rgba(0,0,0,0.05);border:0}
  .label{color:#6c757d;font-size:.88rem}
  .value{font-weight:600}
  .photo{width:110px;height:110px;object-fit:cover;border-radius:16px;background:#eef2ff}
  .section-title{font-weight:800;font-size:1rem;margin:0}
  .divider{height:1px;background:#f0f0f0;margin:.9rem 0}
  @media (max-width:576px){ .photo{width:86px;height:86px;border-radius:14px} }
</style>

<div class="d-flex justify-content-end gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="../reception/students_list.php">Back</a>
  <a class="btn btn-warning" href="../reception/students_list_edit.php?id=<?php echo (int)$student['id']; ?>">Edit</a>
</div>

<div class="card card-soft mb-3">
    <div class="card-body">
      <div class="d-flex gap-3 align-items-center">
        <?php if (!empty($student['photo_path'])): ?>
          <img class="photo" src="<?php echo e((string)$student['photo_path']); ?>" alt="Student Photo">
        <?php else: ?>
          <div class="photo d-flex align-items-center justify-content-center text-muted">
            No Photo
          </div>
        <?php endif; ?>

        <div class="flex-grow-1">
          <div class="text-muted small">Student ID: <?php echo (int)$student['id']; ?></div>
          <div class="h5 mb-1">
            <?php
              $fullName = trim(
                (string)($student['first_name'] ?? '') . ' ' .
                (string)($student['middle_name'] ?? '') . ' ' .
                (string)($student['last_name'] ?? '')
              );
              echo e($fullName !== '' ? $fullName : '—');
            ?>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <span class="badge <?php echo (($student['status'] ?? '') === 'active') ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo e(ucfirst((string)($student['status'] ?? ''))); ?>
            </span>
            <?php if (!empty($student['class_name'])): ?>
              <span class="badge bg-primary"><?php echo e((string)$student['class_name']); ?></span>
            <?php endif; ?>
            <?php if (!empty($student['academic_year'])): ?>
              <span class="badge bg-info text-dark">AY: <?php echo e((string)$student['academic_year']); ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="row g-3">
        <div class="col-6 col-md-3">
          <div class="label">DOB</div>
          <div class="value"><?php echo $fmtDate($student['dob'] ?? null); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Admission Date</div>
          <div class="value"><?php echo $fmtDate($student['admission_date'] ?? null); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Form No</div>
          <div class="value"><?php echo e((string)($student['form_no'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">School ID</div>
          <div class="value"><?php echo e((string)($student['school_id'] ?? '—')); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Parent -->
  <div class="card card-soft mb-3">
    <div class="card-body">
      <p class="section-title">Parent</p>
      <div class="divider"></div>
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <div class="label">Name</div>
          <div class="value"><?php echo e((string)($student['parent_name'] ?? '—')); ?></div>
        </div>
        <div class="col-12 col-md-4">
          <div class="label">Phone</div>
          <div class="value"><?php echo e((string)($student['parent_phone'] ?? '—')); ?></div>
        </div>
        <div class="col-12 col-md-4">
          <div class="label">Parent ID</div>
          <div class="value"><?php echo e((string)($student['parent_id'] ?? '—')); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Address -->
  <div class="card card-soft mb-3">
    <div class="card-body">
      <p class="section-title">Address</p>
      <div class="divider"></div>

      <div class="row g-3">
        <div class="col-12">
          <div class="label">Address</div>
          <div class="value"><?php echo e((string)($student['address'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">City</div>
          <div class="value"><?php echo e((string)($student['city'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">State</div>
          <div class="value"><?php echo e((string)($student['state'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Country</div>
          <div class="value"><?php echo e((string)($student['country'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">PIN</div>
          <div class="value"><?php echo e((string)($student['pin'] ?? '—')); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Father / Mother / Guardian -->
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card card-soft h-100">
        <div class="card-body">
          <p class="section-title">Father</p>
          <div class="divider"></div>
          <div class="label">Name</div>
          <div class="value">
            <?php
              $father = trim(
                (string)($student['father_first'] ?? '') . ' ' .
                (string)($student['father_middle'] ?? '') . ' ' .
                (string)($student['father_last'] ?? '')
              );
              echo e($father !== '' ? $father : '—');
            ?>
          </div>
          <div class="mt-2 label">Email</div>
          <div class="value"><?php echo e((string)($student['father_email'] ?? '—')); ?></div>
          <div class="mt-2 label">Phone</div>
          <div class="value"><?php echo e((string)($student['father_phone'] ?? '—')); ?></div>
          <div class="mt-2 label">Profession</div>
          <div class="value"><?php echo e((string)($student['father_prof'] ?? '—')); ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card card-soft h-100">
        <div class="card-body">
          <p class="section-title">Mother</p>
          <div class="divider"></div>
          <div class="label">Name</div>
          <div class="value">
            <?php
              $mother = trim(
                (string)($student['mother_first'] ?? '') . ' ' .
                (string)($student['mother_middle'] ?? '') . ' ' .
                (string)($student['mother_last'] ?? '')
              );
              echo e($mother !== '' ? $mother : '—');
            ?>
          </div>
          <div class="mt-2 label">Email</div>
          <div class="value"><?php echo e((string)($student['mother_email'] ?? '—')); ?></div>
          <div class="mt-2 label">Phone</div>
          <div class="value"><?php echo e((string)($student['mother_phone'] ?? '—')); ?></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card card-soft h-100">
        <div class="card-body">
          <p class="section-title">Guardian (Emergency)</p>
          <div class="divider"></div>
          <div class="label">Name</div>
          <div class="value"><?php echo e((string)($student['guardian_name'] ?? '—')); ?></div>
          <div class="mt-2 label">Relation</div>
          <div class="value"><?php echo e((string)($student['guardian_relation'] ?? '—')); ?></div>
          <div class="mt-2 label">Phone</div>
          <div class="value"><?php echo e((string)($student['guardian_phone'] ?? '—')); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Fees -->
  <div class="card card-soft mt-3">
    <div class="card-body">
      <p class="section-title">Fees</p>
      <div class="divider"></div>

      <div class="row g-3">
        <div class="col-6 col-md-3">
          <div class="label">Total Fees</div>
          <div class="value"><?php echo e((string)($student['total_fees'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Installment 1</div>
          <div class="value"><?php echo e((string)($student['installment1'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Installment 2</div>
          <div class="value"><?php echo e((string)($student['installment2'] ?? '—')); ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="label">Installment 3</div>
          <div class="value"><?php echo e((string)($student['installment3'] ?? '—')); ?></div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="row g-3">
        <div class="col-12 col-md-6">
          <div class="label">Remark</div>
          <div class="value"><?php echo e((string)($student['remark'] ?? '—')); ?></div>
        </div>
        <div class="col-12 col-md-6">
          <div class="label">Stamp</div>
          <div class="value"><?php echo e((string)($student['stamp'] ?? '—')); ?></div>
        </div>
      </div>
    </div>
  </div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>