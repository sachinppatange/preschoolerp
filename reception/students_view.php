<?php
/**
 * reception/students_view.php
 *
 * Detailed Student View (Reception) with A4-friendly print support.
 *
 * - Shows core student fields and extended data (from extended_json / meta file / DB columns).
 * - "Print" button triggers a print-friendly A4 output using CSS @page and print-only rules.
 * - The printable region is the `.print-area` element; everything else is hidden during printing.
 *
 * Place at: /pioneerplayschool01/reception/students_view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth guard (adjust to your app) */
/* Optional project includes */
/* Guarded helpers */

/* Ensure students table exists */
if (!table_exists('students')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required table <strong>students</strong> not found. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Read id */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Missing or invalid student id.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Download meta */
if (isset($_GET['download_meta']) && $_GET['download_meta'] === '1') {
    $metaFile = __DIR__ . '/../uploads/students/meta/student_' . $id . '.json';
    if (is_file($metaFile)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=student_' . $id . '_meta.json');
        readfile($metaFile);
        exit;
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo 'Meta not found.';
        exit;
    }
}

/* Delete action */
$messages = []; $errors = [];
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    try {
        $pdo = pdo_connect();
        if (!($pdo instanceof \PDO)) throw new RuntimeException('DB unavailable.');
        $stu = safe_db_get_one("SELECT photo_path FROM students WHERE id = :id LIMIT 1", [':id'=>$id]);
        $ok = safe_db_run("DELETE FROM students WHERE id = :id", [':id'=>$id]);
        safe_db_run("DELETE FROM parents_children WHERE child_student_id = :id", [':id'=>$id]);
        if ($ok) {
            if (!empty($stu['photo_path'])) {
                $fp = __DIR__ . '/../' . ltrim($stu['photo_path'], '/');
                if (is_file($fp)) @unlink($fp);
            }
            $metaFile = __DIR__ . '/../uploads/students/meta/student_' . $id . '.json';
            if (is_file($metaFile)) @unlink($metaFile);
            $_SESSION['flash_success'] = 'Student deleted.';
            header('Location: /reception/students.php');
            exit;
        } else {
            $errors[] = 'Failed to delete student.';
        }
    } catch (Throwable $e) {
        error_log('student delete error: ' . $e->getMessage());
        $errors[] = $DEBUG ? 'Error: ' . $e->getMessage() : 'Failed to delete student.';
    }
}

/* Load student */
$student = safe_db_get_one("SELECT * FROM students WHERE id = :id LIMIT 1", [':id'=>$id]);
if (!$student) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-warning">Student not found.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Existing columns */
$colsInfo = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
$existingCols = array_column($colsInfo, 'COLUMN_NAME');

/* Structured extended data (no raw JSON output) */
$extended = null;
if (in_array('extended_json', $existingCols) && !empty($student['extended_json'])) {
    $decoded = @json_decode($student['extended_json'], true);
    if (json_last_error() === JSON_ERROR_NONE) $extended = $decoded;
}
if ($extended === null) {
    $metaFile = __DIR__ . '/../uploads/students/meta/student_' . $id . '.json';
    if (is_file($metaFile)) {
        $raw = @file_get_contents($metaFile);
        $decoded = @json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) $extended = $decoded;
        else $extended = null;
    }
}
if ($extended === null) {
    $extended = [
        'form_no' => $student['form_no'] ?? '',
        'location' => $student['location'] ?? '',
        'admission_seeking_in' => $student['class_id'] ?? '',
        'student' => [
            'first' => $student['first_name'] ?? '',
            'middle' => $student['middle_name'] ?? '',
            'last' => $student['last_name'] ?? '',
            'dob' => $student['dob'] ?? '',
            'gender' => $student['gender'] ?? ''
        ],
        'place_of_birth' => $student['place_of_birth'] ?? '',
        'nationality' => $student['nationality'] ?? '',
        'caste' => $student['caste'] ?? '',
        'languages' => $student['languages'] ?? '',
        'address' => [
            'address' => $student['address'] ?? '',
            'city' => $student['city'] ?? '',
            'state' => $student['state'] ?? '',
            'country' => $student['country'] ?? '',
            'pin' => $student['pin'] ?? ''
        ],
        'father' => [
            'first' => $student['father_first'] ?? '',
            'middle'=> $student['father_middle'] ?? '',
            'last' => $student['father_last'] ?? '',
            'email' => $student['father_email'] ?? '',
            'phone' => $student['father_phone'] ?? ''
        ],
        'mother' => [
            'first' => $student['mother_first'] ?? '',
            'middle'=> $student['mother_middle'] ?? '',
            'last' => $student['mother_last'] ?? '',
            'email' => $student['mother_email'] ?? '',
            'phone' => $student['mother_phone'] ?? ''
        ],
        'guardian' => [
            'name' => $student['guardian_name'] ?? '',
            'email' => $student['guardian_email'] ?? '',
            'relation' => $student['guardian_relation'] ?? '',
            'phone' => $student['guardian_phone'] ?? ''
        ],
        'previous_school' => $student['previous_school'] ?? '',
        'medical' => [
            'allergies' => $student['allergies'] ?? '',
            'health_conditions' => $student['health_conditions'] ?? '',
            'current_medications' => $student['current_medications'] ?? '',
            'immunization_records' => $student['immunization_records'] ?? ''
        ],
        'siblings' => [
            '1' => $student['sibling1'] ?? '',
            '2' => $student['sibling2'] ?? ''
        ],
        'additional_info' => $student['additional_info'] ?? '',
        'fees' => [
            'total' => $student['total_fees'] ?? '',
            'installments' => [
                $student['installment1'] ?? '',
                $student['installment2'] ?? '',
                $student['installment3'] ?? ''
            ],
            'remark' => $student['remark'] ?? '',
            'stamp' => $student['stamp'] ?? ''
        ],
        'parent_signature' => $student['parent_signature'] ?? ''
    ];
}

/* Parent user */
$parent = null;
if (!empty($student['parent_id'])) {
    $parent = safe_db_get_one("SELECT id, name, phone, is_active FROM users WHERE id = :id LIMIT 1", [':id'=>$student['parent_id']]);
}

/* Class / School names */
$className = '';
if (!empty($student['class_id']) && table_exists('classes')) {
    $c = safe_db_get_one("SELECT name FROM classes WHERE id = :id LIMIT 1", [':id'=>$student['class_id']]);
    $className = $c['name'] ?? '';
}
$schoolName = '';
if (!empty($student['school_id']) && table_exists('schools')) {
    $s = safe_db_get_one("SELECT name FROM schools WHERE id = :id LIMIT 1", [':id'=>$student['school_id']]);
    $schoolName = $s['name'] ?? '';
}

/* Siblings */
$siblings = [];
if (!empty($student['parent_id'])) {
    $siblings = safe_db_get_all("SELECT id, first_name, last_name, dob, class_id FROM students WHERE parent_id = :p AND id != :id ORDER BY first_name ASC", [':p'=>$student['parent_id'], ':id'=>$student['id']]);
}

/* Meta flag */
$metaPath = __DIR__ . '/../uploads/students/meta/student_' . $id . '.json';
$metaAvailable = is_file($metaPath);

/* Helper to build photo URL relative to project folder */
function build_photo_url(?string $photoRel): string {
    if (empty($photoRel)) return '';
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $projectPath = str_replace($docRoot, '', realpath(__DIR__ . '/..'));
    $projectPath = $projectPath === '' ? '' : '/' . ltrim($projectPath, '/');
    $photoRel = ltrim($photoRel, '/');
    return $projectPath . '/' . $photoRel;
}

/* Page header include */
$pageTitle = 'Student Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3 no-print">
  <a class="btn btn-outline-primary" href="/reception/admission.php?student_id=<?php echo (int)$student['id']; ?>">Edit</a>
  <?php echo render_secure_delete_button((int)$student['id'], 'Delete', 'Delete this student? This will also remove parent-child mapping.', 'btn btn-outline-danger'); ?>
  <?php if ($metaAvailable): ?><a class="btn btn-success" href="?id=<?php echo (int)$student['id']; ?>&download_meta=1">Download JSON</a><?php endif; ?>
  <button id="printBtn" class="btn btn-outline-secondary">Print (A4)</button>
</div>

<?php if (!empty($_SESSION['flash_success'])) { echo '<div class="alert alert-success">'.e($_SESSION['flash_success']).'</div>'; unset($_SESSION['flash_success']); } ?>
    <?php if (!empty($messages)) foreach ($messages as $m) echo '<div class="alert alert-success">'.e($m).'</div>'; ?>
    <?php if (!empty($errors)) { echo '<div class="alert alert-danger"><ul class="mb-0">'; foreach ($errors as $er) echo '<li>'.e($er).'</li>'; echo '</ul></div>'; } ?>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="card p-3 text-center">
          <?php
            $photoRel = $student['photo_path'] ?? '';
            $photoUrl = build_photo_url($photoRel);
            $photoFullPath = $photoRel ? (__DIR__ . '/../' . ltrim($photoRel, '/')) : '';
            $placeholder = build_photo_url('assets/img/no-photo.png'); // adjust path or leave blank
          ?>
          <?php if (!empty($photoUrl) && is_file($photoFullPath) && @getimagesize($photoFullPath)): ?>
            <img src="<?php echo e($photoUrl); ?>" alt="Photo" class="thumb mb-2">
            <div class="no-print"><a href="<?php echo e($photoUrl); ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-2">View full</a></div>
          <?php else: ?>
            <?php if (!empty($photoRel) && !is_file($photoFullPath)): ?>
              <div class="alert alert-warning small mb-2">Uploaded photo file not found or unreadable.</div>
            <?php endif; ?>
            <?php if ($placeholder): ?>
              <img src="<?php echo e($placeholder); ?>" alt="No photo" class="thumb mb-2">
            <?php else: ?>
              <div class="bg-secondary text-white rounded p-5 mb-2">No photo</div>
            <?php endif; ?>
          <?php endif; ?>
          <div class="small text-muted mt-2">Status: <?php echo e($student['status'] ?? ''); ?></div>
        </div>

        <div class="card p-3 mt-3">
          <h6 class="mb-2">Quick info</h6>
          <dl class="row mb-0">
            <dt class="dl-term">Student ID</dt><dd class="col"><?php echo (int)$student['id']; ?></dd>
            <dt class="dl-term">Form No</dt><dd class="col"><?php echo e($extended['form_no'] ?? ($student['form_no'] ?? '')); ?></dd>
            <dt class="dl-term">Applied for</dt><dd class="col"><?php echo e($className ?: ($extended['admission_seeking_in'] ?? '—')); ?></dd>
            <dt class="dl-term">Admission Date</dt><dd class="col"><?php echo e($student['admission_date'] ?? ''); ?></dd>
            <dt class="dl-term">School</dt><dd class="col"><?php echo e($schoolName ?: '—'); ?></dd>
            <dt class="dl-term">Created</dt><dd class="col"><?php echo e($student['created_at'] ?? ''); ?></dd>
            <dt class="dl-term">Updated</dt><dd class="col"><?php echo e($student['updated_at'] ?? ''); ?></dd>
          </dl>
        </div>
      </div>

      <div class="col-md-8">
        <!-- Student details -->
        <div class="card p-3 mb-3">
          <div class="section-title">Student Details</div>
          <div class="row">
            <div class="col-md-6"><strong>First name:</strong> <?php echo e($extended['student']['first'] ?? ($student['first_name'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Middle name:</strong> <?php echo e($extended['student']['middle'] ?? ($student['middle_name'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Last name:</strong> <?php echo e($extended['student']['last'] ?? ($student['last_name'] ?? '')); ?></div>
            <div class="col-md-6"><strong>DOB:</strong> <?php echo e($extended['student']['dob'] ?? ($student['dob'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Gender:</strong> <?php echo e($extended['student']['gender'] ?? ($student['gender'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Place of birth:</strong> <?php echo e($extended['place_of_birth'] ?? ($student['place_of_birth'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Nationality:</strong> <?php echo e($extended['nationality'] ?? ($student['nationality'] ?? '')); ?></div>
            <div class="col-md-6"><strong>Caste:</strong> <?php echo e($extended['caste'] ?? ($student['caste'] ?? '')); ?></div>
            <div class="col-md-12"><strong>Languages:</strong> <?php echo e($extended['languages'] ?? ($student['languages'] ?? '')); ?></div>
          </div>
        </div>

        <!-- Address -->
        <div class="card p-3 mb-3">
          <div class="section-title">Address</div>
          <div><?php echo nl2br(e($extended['address']['address'] ?? ($student['address'] ?? ''))); ?></div>
          <small class="text-muted">
            <?php echo e($extended['address']['city'] ?? ($student['city'] ?? '')); ?>,
            <?php echo e($extended['address']['state'] ?? ($student['state'] ?? '')); ?>,
            <?php echo e($extended['address']['country'] ?? ($student['country'] ?? '')); ?>
            <?php if (!empty($extended['address']['pin'] ?? $student['pin'])): ?> - <?php echo e($extended['address']['pin'] ?? $student['pin']); ?><?php endif; ?>
          </small>
        </div>

        <!-- Parents & Guardian -->
        <div class="card p-3 mb-3">
          <div class="section-title">Parents & Guardian</div>
          <div class="row">
            <div class="col-md-6">
              <strong>Father</strong>
              <div><?php echo e(trim(($extended['father']['first'] ?? $student['father_first'] ?? '') . ' ' . ($extended['father']['middle'] ?? $student['father_middle'] ?? '') . ' ' . ($extended['father']['last'] ?? $student['father_last'] ?? ''))); ?></div>
              <?php if (!empty($extended['father']['email'] ?? $student['father_email'] ?? '')): ?><div><small>Email: <?php echo e($extended['father']['email'] ?? $student['father_email']); ?></small></div><?php endif; ?>
              <?php if (!empty($extended['father']['phone'] ?? $student['father_phone'] ?? '')): ?><div><small>Phone: <?php echo e($extended['father']['phone'] ?? $student['father_phone']); ?></small></div><?php endif; ?>
            </div>
            <div class="col-md-6">
              <strong>Mother</strong>
              <div><?php echo e(trim(($extended['mother']['first'] ?? $student['mother_first'] ?? '') . ' ' . ($extended['mother']['middle'] ?? $student['mother_middle'] ?? '') . ' ' . ($extended['mother']['last'] ?? $student['mother_last'] ?? ''))); ?></div>
              <?php if (!empty($extended['mother']['email'] ?? $student['mother_email'] ?? '')): ?><div><small>Email: <?php echo e($extended['mother']['email'] ?? $student['mother_email']); ?></small></div><?php endif; ?>
              <?php if (!empty($extended['mother']['phone'] ?? $student['mother_phone'] ?? '')): ?><div><small>Phone: <?php echo e($extended['mother']['phone'] ?? $student['mother_phone']); ?></small></div><?php endif; ?>
            </div>
          </div>
          <hr>
          <div>
            <strong>Guardian / Emergency Contact</strong>
            <div><?php echo e($extended['guardian']['name'] ?? $student['guardian_name'] ?? ''); ?></div>
            <?php if (!empty($extended['guardian']['relation'] ?? $student['guardian_relation'] ?? '')): ?><div><small>Relation: <?php echo e($extended['guardian']['relation'] ?? $student['guardian_relation']); ?></small></div><?php endif; ?>
            <?php if (!empty($extended['guardian']['email'] ?? $student['guardian_email'] ?? '')): ?><div><small>Email: <?php echo e($extended['guardian']['email'] ?? $student['guardian_email']); ?></small></div><?php endif; ?>
            <?php if (!empty($extended['guardian']['phone'] ?? $student['guardian_phone'] ?? '')): ?><div><small>Phone: <?php echo e($extended['guardian']['phone'] ?? $student['guardian_phone']); ?></small></div><?php endif; ?>
          </div>
        </div>

        <!-- Education / Medical / Siblings -->
        <div class="card p-3 mb-3">
          <div class="section-title">Education & Health</div>
          <div><strong>Previous School:</strong> <?php echo e($extended['previous_school'] ?? $student['previous_school'] ?? '—'); ?></div>
          <div class="mt-2"><strong>Allergies:</strong> <?php echo e($extended['medical']['allergies'] ?? $student['allergies'] ?? '—'); ?></div>
          <div><strong>Health conditions:</strong> <?php echo e($extended['medical']['health_conditions'] ?? $student['health_conditions'] ?? '—'); ?></div>
          <div><strong>Current medications:</strong> <?php echo e($extended['medical']['current_medications'] ?? $student['current_medications'] ?? '—'); ?></div>
          <div><strong>Immunization records:</strong> <?php echo e($extended['medical']['immunization_records'] ?? $student['immunization_records'] ?? '—'); ?></div>

          <hr>
          <div class="section-title">Siblings</div>
          <?php if (!empty($extended['siblings']) || !empty($siblings)): ?>
            <ul>
              <?php
                if (!empty($extended['siblings']) && is_array($extended['siblings'])) {
                    foreach ($extended['siblings'] as $k=>$v) {
                        if ($v !== '') echo '<li>' . e($v) . '</li>';
                    }
                }
                if (!empty($siblings)) {
                    foreach ($siblings as $s) {
                        echo '<li>' . e(trim($s['first_name'] . ' ' . ($s['last_name'] ?? ''))) . ' (ID: ' . (int)$s['id'] . ')</li>';
                    }
                }
              ?>
            </ul>
          <?php else: ?>
            <div class="text-muted">No siblings recorded.</div>
          <?php endif; ?>
        </div>

        <!-- Fees & Office Use -->
        <div class="card p-3 mb-3">
          <div class="section-title">Fees & Office Use</div>
          <div><strong>Total fees:</strong> <?php echo e($extended['fees']['total'] ?? $student['total_fees'] ?? ''); ?></div>
          <div class="row mt-2">
            <div class="col-md-4"><small>Installment 1: <?php echo e($extended['fees']['installments'][0] ?? $student['installment1'] ?? ''); ?></small></div>
            <div class="col-md-4"><small>Installment 2: <?php echo e($extended['fees']['installments'][1] ?? $student['installment2'] ?? ''); ?></small></div>
            <div class="col-md-4"><small>Installment 3: <?php echo e($extended['fees']['installments'][2] ?? $student['installment3'] ?? ''); ?></small></div>
          </div>
          <div class="mt-2"><small>Remark: <?php echo e($extended['fees']['remark'] ?? $student['remark'] ?? ''); ?></small></div>
          <div><small>Stamp: <?php echo e($extended['fees']['stamp'] ?? $student['stamp'] ?? ''); ?></small></div>
          <div class="mt-2"><small>Parent Signature: <?php echo e($extended['parent_signature'] ?? $student['parent_signature'] ?? ''); ?></small></div>
        </div>

        <!-- Additional info -->
        <div class="card p-3 mb-3">
          <div class="section-title">Additional Information</div>
          <div><?php echo nl2br(e($extended['additional_info'] ?? $student['additional_info'] ?? '')); ?></div>
        </div>

        <!-- Parent user -->
        <div class="card p-3 mb-3">
          <div class="section-title">Parent (User record)</div>
          <?php if ($parent): ?>
            <div><strong><?php echo e($parent['name']); ?></strong> <?php if ((int)($parent['is_active'] ?? 1) === 0) echo '<span class="badge bg-secondary ms-2">Inactive</span>'; ?></div>
            <div><small>Phone: <?php echo e($parent['phone'] ?? ''); ?></small></div>
            <div class="form-text">This number is used for Parent Portal login. To add a sibling, use the same mobile on a new admission.</div>
          <?php else: ?>
            <div class="text-muted">No parent user linked (parent_id empty)</div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
  </div> <!-- .print-area -->

<script>
document.addEventListener('DOMContentLoaded', function(){
  const printBtn = document.getElementById('printBtn');
  if (printBtn) {
    printBtn.addEventListener('click', function(){
      // Add print-mode class if you want to tweak styles via .print-mode
      document.documentElement.classList.add('print-mode');
      // Use beforeprint handler to ensure any JS-driven adjustments run
      if (window.matchMedia) {
        // some browsers support beforeprint/afterprint events
      }
      window.print();
    });
    // cleanup after print
    if ('onafterprint' in window) {
      window.onafterprint = function(){ document.documentElement.classList.remove('print-mode'); };
    } else {
      // fallback: remove after small timeout
      window.addEventListener('focus', function(){ document.documentElement.classList.remove('print-mode'); }, { once: true });
    }
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>