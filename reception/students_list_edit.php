<?php
/**
 * reception/students_list_edit.php
 *
 * Full Student Edit page for Reception (mobile friendly).
 * - Fetches student by id and allows editing ALL fields (as per students table)
 * - Photo Path / URL editable + Upload new photo option (uploads/students/)
 * - Academic Year dropdown (if column exists)
 * - Uses guarded helper functions style similar to admission.php
 *
 * Place at: /pioneerplayschool01/reception/students_list_edit.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth guard */
/* Optional includes (project-specific) */
/* Ensure table exists */
if (!table_exists('students')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>students</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Input */
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-warning">Invalid student id.</div><a class="btn btn-outline-secondary" href="../reception/students_list.php">Back</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Lists */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$parentList = table_exists('users') ? safe_db_get_all("SELECT id, name, phone FROM users WHERE role='parent' ORDER BY name ASC") : [];
$academicYears = ['2024-25','2025-26','2026-27'];
$hasAcademicYear = column_exists('students', 'academic_year');

/* Helpers */
$fmtPhotoSrc = function(?string $path): string {
    $p = trim((string)$path);
    if ($p === '') return '';
    // If already absolute URL
    if (preg_match('~^https?://~i', $p)) return $p;
    // If already absolute from root
    if (str_starts_with($p, '/')) return $p;
    // If points to uploads/... from project root, on /reception/* page we need ../
    if (str_starts_with($p, 'uploads/')) return '../' . $p;
    // Otherwise return as-is (best effort)
    return $p;
};

/* Load student */
$student = safe_db_get_one("SELECT * FROM students WHERE id = :id LIMIT 1", [':id' => $id]);
if (!$student) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-info">Student not found.</div><a class="btn btn-outline-secondary" href="../reception/students_list.php">Back</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$messages = [];
$errors = [];

/* -------------------------
   Handle POST (Save)
   ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $incoming)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        // Collect posted data (ALL fields)
        $data = [];
        $data['school_id'] = ($_POST['school_id'] ?? '') !== '' ? (int)$_POST['school_id'] : null;

        $data['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $data['middle_name'] = trim((string)($_POST['middle_name'] ?? ''));
        $data['last_name'] = trim((string)($_POST['last_name'] ?? ''));

        $data['dob'] = trim((string)($_POST['dob'] ?? '')) ?: null;
        $data['class_id'] = ($_POST['class_id'] ?? '') !== '' ? (int)$_POST['class_id'] : null;
        $data['parent_id'] = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;

        $data['admission_date'] = trim((string)($_POST['admission_date'] ?? '')) ?: null;
        $data['status'] = trim((string)($_POST['status'] ?? 'active'));
        $data['form_no'] = trim((string)($_POST['form_no'] ?? ''));

        if ($hasAcademicYear) {
            $data['academic_year'] = trim((string)($_POST['academic_year'] ?? ''));
            if ($data['academic_year'] !== '' && !in_array($data['academic_year'], $academicYears, true)) {
                $errors[] = 'Invalid Academic Year.';
            }
        }

        // Address
        $data['address'] = trim((string)($_POST['address'] ?? ''));
        $data['city'] = trim((string)($_POST['city'] ?? ''));
        $data['state'] = trim((string)($_POST['state'] ?? ''));
        $data['country'] = trim((string)($_POST['country'] ?? ''));
        $data['pin'] = trim((string)($_POST['pin'] ?? ''));

        // Father
        $data['father_first'] = trim((string)($_POST['father_first'] ?? ''));
        $data['father_middle'] = trim((string)($_POST['father_middle'] ?? ''));
        $data['father_last'] = trim((string)($_POST['father_last'] ?? ''));
        $data['father_email'] = trim((string)($_POST['father_email'] ?? ''));
        $data['father_phone'] = preg_replace('/\D+/', '', (string)($_POST['father_phone'] ?? ''));
        $data['father_prof'] = trim((string)($_POST['father_prof'] ?? ''));

        // Mother
        $data['mother_first'] = trim((string)($_POST['mother_first'] ?? ''));
        $data['mother_middle'] = trim((string)($_POST['mother_middle'] ?? ''));
        $data['mother_last'] = trim((string)($_POST['mother_last'] ?? ''));
        $data['mother_email'] = trim((string)($_POST['mother_email'] ?? ''));
        $data['mother_phone'] = preg_replace('/\D+/', '', (string)($_POST['mother_phone'] ?? ''));

        // Guardian
        $data['guardian_name'] = trim((string)($_POST['guardian_name'] ?? ''));
        $data['guardian_relation'] = trim((string)($_POST['guardian_relation'] ?? ''));
        $data['guardian_phone'] = preg_replace('/\D+/', '', (string)($_POST['guardian_phone'] ?? ''));

        // Health
        $data['allergies'] = trim((string)($_POST['allergies'] ?? ''));
        $data['health_conditions'] = trim((string)($_POST['health_conditions'] ?? ''));
        $data['current_medications'] = trim((string)($_POST['current_medications'] ?? ''));

        // Fees
        $data['total_fees'] = trim((string)($_POST['total_fees'] ?? ''));
        $data['installment1'] = trim((string)($_POST['installment1'] ?? ''));
        $data['installment2'] = trim((string)($_POST['installment2'] ?? ''));
        $data['installment3'] = trim((string)($_POST['installment3'] ?? ''));

        // Other
        $data['remark'] = trim((string)($_POST['remark'] ?? ''));
        $data['stamp'] = trim((string)($_POST['stamp'] ?? ''));

        // Photo path from text input (fallback)
        $photo_path = trim((string)($_POST['photo_path'] ?? ''));

        // Upload option (overrides text input if file uploaded successfully)
        if (!empty($_FILES['photo_upload']) && ($_FILES['photo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['photo_upload'];
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $errors[] = 'Photo upload error (code ' . (int)$f['error'] . ').';
            } else {
                $mime = null;
                if (class_exists('finfo')) {
                    try {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = $finfo->file($f['tmp_name']);
                    } catch (Throwable $e) { $mime = null; }
                }
                if ($mime === null && function_exists('mime_content_type')) {
                    $mime = mime_content_type($f['tmp_name']);
                }

                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                if (empty($mime) || !isset($allowed[$mime])) {
                    $errors[] = 'Photo must be JPG/PNG/GIF/WEBP.';
                } else {
                    $ext = $allowed[$mime];
                    $uploadsDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

                    $destName = 'stu_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $destPath = $uploadsDir . $destName;

                    if (!move_uploaded_file($f['tmp_name'], $destPath)) {
                        $errors[] = 'Failed to save uploaded photo.';
                    } else {
                        $photo_path = 'uploads/students/' . $destName; // store relative to project root
                    }
                }
            }
        }

        // Required validations
        if ($data['first_name'] === '') $errors[] = 'First name is required.';
        if ($data['status'] === '') $data['status'] = 'active';

        if (empty($errors)) {
            // Build update SQL only for columns that exist
            $colsInfo = safe_db_get_all(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'"
            );
            $existingCols = array_map('strval', array_column($colsInfo, 'COLUMN_NAME'));

            $update = [];
            $params = [':id' => $id];

            $setIfExists = function(string $col, $val) use (&$update, &$params, $existingCols) {
                if (in_array($col, $existingCols, true)) {
                    $update[] = "`{$col}` = :{$col}";
                    $params[":{$col}"] = ($val === '' ? null : $val);
                }
            };

            foreach ($data as $col => $val) $setIfExists($col, $val);

            // photo_path
            $setIfExists('photo_path', $photo_path);

            // updated_at if exists
            if (in_array('updated_at', $existingCols, true)) {
                $update[] = "`updated_at` = NOW()";
            }

            if (empty($update)) {
                $errors[] = 'No editable columns found in students table.';
            } else {
                $sql = "UPDATE `students` SET " . implode(', ', $update) . " WHERE id = :id";
                $ok = safe_db_run($sql, $params);

                if ($ok) {
                    $_SESSION['flash_success'] = 'Student updated successfully.';
                    header('Location: ../reception/students_list_view.php?id=' . urlencode((string)$id));
                    exit;
                } else {
                    $errors[] = 'Failed to update student.';
                }
            }
        }
    }

    // Reload student for form refill
    $student = safe_db_get_one("SELECT * FROM students WHERE id = :id LIMIT 1", [':id' => $id]) ?: $student;
}

/* Page header */
$pageTitle = 'Edit Student';
require_once __DIR__ . '/../includes/header.php';
$val = function(string $k, $fallback = '') use ($student) {
    return $student[$k] ?? $fallback;
};
?>
<style>
  .card-soft{border-radius:14px;box-shadow:0 10px 24px rgba(0,0,0,0.05);border:0}
  .photo-preview{width:120px;height:120px;border-radius:16px;object-fit:cover;background:#eef2ff}
  @media (max-width:576px){ .photo-preview{width:96px;height:96px;border-radius:14px} }
</style>

<div class="d-flex justify-content-end gap-2 mb-3">
  <a class="btn btn-outline-secondary" href="../reception/students_list_view.php?id=<?php echo (int)$id; ?>">Back</a>
  <a class="btn btn-outline-secondary" href="../reception/students_list.php">Students List</a>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?php echo e((string)$_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
  <?php endif; ?>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e((string)$m); ?></div><?php endforeach; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $er): ?><li><?php echo e((string)$er); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card card-soft">
    <div class="card-body">
      <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

      <!-- Photo -->
      <div class="d-flex gap-3 align-items-center mb-3">
        <?php $src = $fmtPhotoSrc((string)($val('photo_path', ''))); ?>
        <?php if ($src !== ''): ?>
          <img class="photo-preview" src="<?php echo e($src); ?>" alt="Student Photo">
        <?php else: ?>
          <div class="photo-preview d-flex align-items-center justify-content-center text-muted">No Photo</div>
        <?php endif; ?>
        <div class="flex-grow-1">
          <div class="mb-2">
            <label class="form-label">Photo Path / URL</label>
            <input name="photo_path" class="form-control" value="<?php echo e((string)$val('photo_path','')); ?>" placeholder="uploads/students/xyz.jpg OR https://...">
            <div class="form-text">Tip: जर value `uploads/...` अशी असेल तर view page साठी path योग्य असणे गरजेचे आहे.</div>
          </div>
          <div>
            <label class="form-label">Upload New Photo (optional)</label>
            <input type="file" name="photo_upload" class="form-control" accept="image/*">
            <div class="form-text">Upload केल्यावर `Photo Path` auto update होईल.</div>
          </div>
        </div>
      </div>

      <hr>

      <!-- Basic -->
      <div class="row g-3">
        <div class="col-md-2">
          <label class="form-label">School ID</label>
          <input name="school_id" class="form-control" value="<?php echo e((string)$val('school_id','')); ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">First Name *</label>
          <input name="first_name" class="form-control" required value="<?php echo e((string)$val('first_name','')); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Middle Name</label>
          <input name="middle_name" class="form-control" value="<?php echo e((string)$val('middle_name','')); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Last Name</label>
          <input name="last_name" class="form-control" value="<?php echo e((string)$val('last_name','')); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">DOB</label>
          <input type="date" name="dob" class="form-control" value="<?php echo e((string)$val('dob','')); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Admission Date</label>
          <input type="date" name="admission_date" class="form-control" value="<?php echo e((string)$val('admission_date','')); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Form No</label>
          <input name="form_no" class="form-control" value="<?php echo e((string)$val('form_no','')); ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label">Status</label>
          <?php $st = (string)($val('status','active')); ?>
          <select name="status" class="form-select">
            <?php foreach (['active','inactive','pending','alumni'] as $opt): ?>
              <option value="<?php echo e($opt); ?>" <?php if ($st === $opt) echo 'selected'; ?>><?php echo e(ucfirst($opt)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($hasAcademicYear): ?>
          <div class="col-md-3">
            <label class="form-label">Academic Year</label>
            <?php $ay = (string)($val('academic_year','')); ?>
            <select name="academic_year" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($academicYears as $y): ?>
                <option value="<?php echo e($y); ?>" <?php if ($ay === $y) echo 'selected'; ?>><?php echo e($y); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-3">
          <label class="form-label">Class</label>
          <?php $cid = (string)($val('class_id','')); ?>
          <select name="class_id" class="form-select">
            <option value="">-- Select --</option>
            <?php foreach ($classList as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php if ($cid !== '' && (int)$cid === (int)$c['id']) echo 'selected'; ?>>
                <?php echo e((string)$c['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Parent</label>
          <?php $pid = (string)($val('parent_id','')); ?>
          <select name="parent_id" class="form-select">
            <option value="">-- Select Parent --</option>
            <?php foreach ($parentList as $p): ?>
              <option value="<?php echo (int)$p['id']; ?>" <?php if ($pid !== '' && (int)$pid === (int)$p['id']) echo 'selected'; ?>>
                <?php echo e((string)$p['name']); ?><?php if (!empty($p['phone'])) echo ' ('.e((string)$p['phone']).')'; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr>

      <!-- Address -->
      <h6 class="mb-2">Address</h6>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Address</label>
          <input name="address" class="form-control" value="<?php echo e((string)$val('address','')); ?>">
        </div>
        <div class="col-md-3"><label class="form-label">City</label><input name="city" class="form-control" value="<?php echo e((string)$val('city','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">State</label><input name="state" class="form-control" value="<?php echo e((string)$val('state','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">Country</label><input name="country" class="form-control" value="<?php echo e((string)$val('country','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">PIN</label><input name="pin" class="form-control" value="<?php echo e((string)$val('pin','')); ?>"></div>
      </div>

      <hr>

      <!-- Father -->
      <h6 class="mb-2">Father Details</h6>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">First</label><input name="father_first" class="form-control" value="<?php echo e((string)$val('father_first','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Middle</label><input name="father_middle" class="form-control" value="<?php echo e((string)$val('father_middle','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Last</label><input name="father_last" class="form-control" value="<?php echo e((string)$val('father_last','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Email</label><input name="father_email" class="form-control" value="<?php echo e((string)$val('father_email','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input name="father_phone" class="form-control" value="<?php echo e((string)$val('father_phone','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Profession</label><input name="father_prof" class="form-control" value="<?php echo e((string)$val('father_prof','')); ?>"></div>
      </div>

      <hr>

      <!-- Mother -->
      <h6 class="mb-2">Mother Details</h6>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">First</label><input name="mother_first" class="form-control" value="<?php echo e((string)$val('mother_first','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Middle</label><input name="mother_middle" class="form-control" value="<?php echo e((string)$val('mother_middle','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Last</label><input name="mother_last" class="form-control" value="<?php echo e((string)$val('mother_last','')); ?>"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input name="mother_email" class="form-control" value="<?php echo e((string)$val('mother_email','')); ?>"></div>
        <div class="col-md-6"><label class="form-label">Phone</label><input name="mother_phone" class="form-control" value="<?php echo e((string)$val('mother_phone','')); ?>"></div>
      </div>

      <hr>

      <!-- Guardian -->
      <h6 class="mb-2">Guardian (Emergency)</h6>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Name</label><input name="guardian_name" class="form-control" value="<?php echo e((string)$val('guardian_name','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Relation</label><input name="guardian_relation" class="form-control" value="<?php echo e((string)$val('guardian_relation','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input name="guardian_phone" class="form-control" value="<?php echo e((string)$val('guardian_phone','')); ?>"></div>
      </div>

      <hr>

      <!-- Health -->
      <h6 class="mb-2">Health</h6>
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Allergies</label><input name="allergies" class="form-control" value="<?php echo e((string)$val('allergies','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Health Conditions</label><input name="health_conditions" class="form-control" value="<?php echo e((string)$val('health_conditions','')); ?>"></div>
        <div class="col-md-4"><label class="form-label">Current Medications</label><input name="current_medications" class="form-control" value="<?php echo e((string)$val('current_medications','')); ?>"></div>
      </div>

      <hr>

      <!-- Fees -->
      <h6 class="mb-2">Fees</h6>
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Total Fees</label><input name="total_fees" class="form-control" value="<?php echo e((string)$val('total_fees','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">Installment 1</label><input name="installment1" class="form-control" value="<?php echo e((string)$val('installment1','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">Installment 2</label><input name="installment2" class="form-control" value="<?php echo e((string)$val('installment2','')); ?>"></div>
        <div class="col-md-3"><label class="form-label">Installment 3</label><input name="installment3" class="form-control" value="<?php echo e((string)$val('installment3','')); ?>"></div>
      </div>

      <hr>

      <!-- Other -->
      <h6 class="mb-2">Other</h6>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Remark</label><input name="remark" class="form-control" value="<?php echo e((string)$val('remark','')); ?>"></div>
        <div class="col-md-6"><label class="form-label">Stamp</label><input name="stamp" class="form-control" value="<?php echo e((string)$val('stamp','')); ?>"></div>
      </div>

      <hr>

      <div class="d-flex justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="../reception/students_list_view.php?id=<?php echo (int)$id; ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Save Changes</button>
      </div>

    </div>
  </form>

  <div class="small-muted mt-3">
    Note: जर फोटो अजूनही view page वर दिसत नसेल तर `photo_path` value आणि actual file path (server वर) verify करा.
  </div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>