<?php
/**
 * teacher/student_view.php
 *
 * View student details (teacher view).
 *
 * - Requires teacher login ($_SESSION['teacher_auth_user']).
 * - Loads student by ?id=...
 * - Ensures the logged-in teacher is assigned to the student's class:
 *     * uses classes.teacher_id (preferred) or teacher_classes mapping (teacher_id,class_id)
 * - Shows full details from students table (fields provided in schema).
 * - Reuses includes when present (config/db/functions/header/footer); falls back to secure PDO helpers.
 *
 * Place at: /pioneerplayschool01/teacher/student_view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* ---------- Require teacher login ---------- */
if (!defined('DEV_SHOW_ERRORS')) define('DEV_SHOW_ERRORS', false);
/* ---------- Teacher identity ---------- */
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ---------- Get student id ---------- */
$studentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($studentId <= 0) {
    // invalid id
    http_response_code(400);
    echo 'Invalid student id.';
    exit;
}

/* ---------- Load student record ---------- */
$student = null;
if (table_exists('students')) {
    $student = safe_db_get_one("SELECT * FROM students WHERE id = :id LIMIT 1", [':id'=>$studentId]);
}
if (empty($student)) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}

$studentClassId = isset($student['class_id']) ? (int)$student['class_id'] : 0;
$teacherAssignedClassIds = array_map(
    static fn(array $c): int => (int) ($c['id'] ?? 0),
    panel_teacher_assigned_classes($teacherId)
);
$teacherAssignedClassIds = array_values(array_filter(array_unique($teacherAssignedClassIds), static fn(int $v): bool => $v > 0));

$authorized = auth_is_owner_super() || in_array($studentClassId, $teacherAssignedClassIds, true);

if (!$authorized) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-danger">You are not authorized to view this student's details.</div>
    <?php if ($DEBUG): ?>
      <pre class="small text-muted">Debug: teacherAssignedClassIds = <?php echo e(json_encode($teacherAssignedClassIds)); ?>, studentClassId = <?php echo e((string)$studentClassId); ?></pre>
    <?php endif; ?>
    <p><a href="/teacher/my_classes.php" class="btn btn-sm btn-outline-secondary">Back to classes</a></p>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* ---------- Helper to display full name ---------- */
function full_name_from_row(array $r): string {
    $parts = array();
    if (!empty($r['first_name'])) $parts[] = $r['first_name'];
    if (!empty($r['middle_name'])) $parts[] = $r['middle_name'];
    if (!empty($r['last_name'])) $parts[] = $r['last_name'];
    return trim(implode(' ', $parts));
}

/* ---------- Load class information (if available) ---------- */
$classInfo = null;
if ($studentClassId > 0 && table_exists('classes')) {
    $classInfo = safe_db_get_one("SELECT id, name, section, short_name FROM classes WHERE id = :id LIMIT 1", [':id'=>$studentClassId]);
}

/* ---------- Render page ---------- */
$pageTitle = 'Student Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h3 class="mb-0"><?php echo e(full_name_from_row($student) ?: ('Student #' . (int)$student['id'])); ?></h3>
    <div class="small-muted">Student ID: <?php echo (int)$student['id']; ?><?php if ($classInfo): ?> · Class: <?php echo e(trim((($classInfo['short_name'] ?? '') . ' ' . ($classInfo['name'] ?? '')))); ?><?php endif; ?></div>
  </div>
  <div class="text-end">
    <a class="btn btn-outline-secondary btn-sm" href="/teacher/my_classes.php">Back to Classes</a>
    <a class="btn btn-outline-primary btn-sm" href="/teacher/attendance_mark.php?class_id=<?php echo (int)$studentClassId; ?>&student_id=<?php echo (int)$student['id']; ?>">Mark Attendance</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-body text-center">
        <?php if (!empty($student['photo_path'])): ?>
          <img src="<?php echo e($student['photo_path']); ?>" alt="photo" class="student-photo mb-3">
        <?php else: ?>
          <div class="student-photo mb-3" style="display:inline-block;background:#f6f6f6;line-height:96px;">&nbsp;</div>
        <?php endif; ?>
        <h5 class="mb-1"><?php echo e(full_name_from_row($student)); ?></h5>
        <div class="small-muted mb-2">Form No: <?php echo e($student['form_no'] ?? '—'); ?></div>
        <div class="small-muted">Admitted: <?php echo e(substr($student['admission_date'] ?? '', 0, 10) ?: '—'); ?></div>
        <hr>
        <div class="small-muted text-start">
          <strong>DOB:</strong> <?php echo e(substr($student['dob'] ?? '',0,10) ?: '—'); ?><br>
          <strong>Location:</strong> <?php echo e($student['location'] ?? '—'); ?><br>
          <strong>Place of birth:</strong> <?php echo e($student['place_of_birth'] ?? '—'); ?><br>
          <strong>Nationality:</strong> <?php echo e($student['nationality'] ?? '—'); ?><br>
          <strong>Caste:</strong> <?php echo e($student['caste'] ?? '—'); ?>
        </div>
      </div>
    </div>

    <div class="card mt-3 shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Contact / Guardians</h6>
        <div class="small-muted"><strong>Father:</strong> <?php echo e(trim((($student['father_first'] ?? '') . ' ' . ($student['father_middle'] ?? '') . ' ' . ($student['father_last'] ?? '')))); ?></div>
        <div class="small-muted">Phone: <?php echo e($student['father_phone'] ?? '—'); ?> · Email: <?php echo e($student['father_email'] ?? '—'); ?></div>
        <hr>
        <div class="small-muted"><strong>Mother:</strong> <?php echo e(trim((($student['mother_first'] ?? '') . ' ' . ($student['mother_middle'] ?? '') . ' ' . ($student['mother_last'] ?? '')))); ?></div>
        <div class="small-muted">Phone: <?php echo e($student['mother_phone'] ?? '—'); ?> · Email: <?php echo e($student['mother_email'] ?? '—'); ?></div>
        <hr>
        <div class="small-muted"><strong>Guardian:</strong> <?php echo e($student['guardian_name'] ?? '—'); ?></div>
        <div class="small-muted">Relation: <?php echo e($student['guardian_relation'] ?? '—'); ?> · Phone: <?php echo e($student['guardian_phone'] ?? '—'); ?> · Email: <?php echo e($student['guardian_email'] ?? '—'); ?></div>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">Address</h6>
        <div><?php echo nl2br(e($student['address'] ?? '—')); ?></div>
        <div class="small-muted mt-2"><?php echo e(($student['city'] ?? '') . ($student['state'] ? ', ' . $student['state'] : '') . ($student['pin'] ? ' - ' . $student['pin'] : '')); ?></div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">Medical / Health</h6>
        <div class="small-muted"><strong>Allergies:</strong> <?php echo e($student['allergies'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Health conditions:</strong> <?php echo e($student['health_conditions'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Current medications:</strong> <?php echo e($student['current_medications'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Immunization:</strong> <?php echo e($student['immunization_records'] ?? '—'); ?></div>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">School / Fees</h6>
        <div class="small-muted"><strong>School ID:</strong> <?php echo e($student['school_id'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Total fees:</strong> <?php echo e($student['total_fees'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Installments:</strong> <?php echo e($student['installment1'] ?? '—'); ?> / <?php echo e($student['installment2'] ?? '—'); ?> / <?php echo e($student['installment3'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Remark:</strong> <?php echo e($student['remark'] ?? '—'); ?></div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Additional info</h6>
        <div class="small-muted"><strong>Previous school:</strong> <?php echo e($student['previous_school'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Languages:</strong> <?php echo e($student['languages'] ?? '—'); ?></div>
        <div class="small-muted"><strong>Sibling 1:</strong> <?php echo e($student['sibling1'] ?? '—'); ?> · <strong>Sibling 2:</strong> <?php echo e($student['sibling2'] ?? '—'); ?></div>
        <div class="small-muted mt-2"><strong>Extended JSON:</strong>
          <?php
            if (!empty($student['extended_json'])) {
                $x = json_decode($student['extended_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    echo '<pre class="small" style="max-height:220px;overflow:auto;background:#f8f9fa;padding:.5rem;border-radius:.25rem;">' . e(print_r($x, true)) . '</pre>';
                } else {
                    echo e($student['extended_json']);
                }
            } else {
                echo ' — ';
            }
          ?>
        </div>
      </div>
    </div>

  </div>
</div>

<?php
if ($DEBUG) {
    echo '<pre class="mt-3 small text-muted">DEBUG: student record keys: ' . e(json_encode(array_keys($student))) . '</pre>';
}

/* ---------- Footer ---------- */
require_once __DIR__ . '/../includes/footer.php';
?>