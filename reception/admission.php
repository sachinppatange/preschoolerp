<?php
/**
 * reception/admission.php
 *
 * Full Student Admission form and save handler.
 * - Parent login is created/linked automatically from parent name + mobile (same number = siblings)
 * - Saves form into `students` table: discovers existing columns and inserts only matching columns
 * - Ensures parents_children mapping exists
 * - Uploads photo to uploads/students/
 * - Saves full extended payload to uploads/students/meta/student_{id}.json
 *
 * UPDATE (Academic Year):
 * - Added Academic Year field (format: 2024-25, 2025-26, 2026-27)
 * - Saves to students.academic_year (VARCHAR(7)) if column exists
 * - Also included in extended_json payload
 *
 * Place at: /pioneerplayschool01/reception/admission.php
 *
 * Requires: $_SESSION['reception_auth_user'] to be set for reception access
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();
if (!function_exists('parent_find_or_create')) {
    require_once __DIR__ . '/../includes/parent_account.php';
}

/* Ensure required tables exist */
if (!table_exists('students') || !table_exists('users') || !table_exists('parents_children')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required tables missing (students, users, parents_children). Please check the database.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Load lists */
$schoolList = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name, fees FROM classes ORDER BY name ASC") : [];

/* Optional prefill when opened from Parents list (?parent_id=) */
$parent = null;
$parent_id = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;
if ($parent_id > 0) {
    $parent = safe_db_get_one("SELECT id, name, phone, whatsapp_id, meta, school_id FROM users WHERE id = :id AND role='parent' LIMIT 1", [':id'=>$parent_id]);
    if (!$parent) $parent_id = 0;
}

/* Helper */
if (!function_exists('generate_form_no')) {
    function generate_form_no(): string { return sprintf('FORM-%s-%04d', date('Ymd'), random_int(1000,9999)); }
}

/* Academic year list (June–June, synced with global panel selector) */
if (!function_exists('ay_list')) {
    require_once __DIR__ . '/../includes/panel/academic_year.php';
}
$academicYears = ay_list();

/* Defaults */
$defaults = [
    'school_id' => $parent['school_id'] ?? '',
    'form_no' => generate_form_no(),
    'location' => '',
    'admission_seeking_in' => '',
    // NEW
    'academic_year' => ay_selected(),

    'parent_login_name' => $parent['name'] ?? '',
    'parent_login_phone' => $parent['phone'] ?? '',
    'parent_login_whatsapp' => $parent['whatsapp_id'] ?? '',
    'parent_login_email' => function_exists('parent_email_from_user_row') && $parent ? parent_email_from_user_row($parent) : '',
    'parent_login_relation' => 'parent',
    'stu_first'=>'','stu_middle'=>'','stu_last'=>'',
    'dob'=>'','gender'=>'male','place_of_birth'=>'','nationality'=>'','caste'=>'','languages'=>'',
    'address'=>'','city'=>'','state'=>'','country'=>'','pin'=>'',
    'father_first'=>'','father_middle'=>'','father_last'=>'','father_email'=>'','father_edu'=>'','father_prof'=>'','father_designation'=>'','father_phone'=>'',
    'mother_first'=>'','mother_middle'=>'','mother_last'=>'','mother_email'=>'','mother_edu'=>'','mother_prof'=>'','mother_designation'=>'','mother_phone'=>'',
    'guardian_name'=>'','guardian_email'=>'','guardian_relation'=>'','guardian_phone'=>'',
    'previous_school'=>'','allergies'=>'','health_conditions'=>'','current_medications'=>'','immunization_records'=>'',
    'sibling1'=>'','sibling2'=>'','additional_info'=>'','application_date'=>date('Y-m-d'),
    'parent_signature'=>'','installment1'=>'','installment2'=>'','installment3'=>'','total_fees'=>'','remark'=>'','stamp'=>''
];

$errors = []; $messages = [];

/* -------------------------
   Handle Admission POST (main)
   ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (function_exists('ay_can_edit') && !ay_can_edit(trim((string) ($_POST['academic_year'] ?? ay_selected())))) {
        $errors[] = 'This academic year is locked. New admissions are not allowed.';
    } else {
        // collect inputs
        $in = [];
        $in['school_id'] = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
        $in['form_no'] = trim((string)($_POST['form_no'] ?? generate_form_no()));
        $in['location'] = trim((string)($_POST['location'] ?? ''));
        $in['class_id'] = isset($_POST['admission_seeking_in']) && $_POST['admission_seeking_in'] !== '' ? (int)$_POST['admission_seeking_in'] : null;

        // NEW: Academic year
        $in['academic_year'] = trim((string)($_POST['academic_year'] ?? ''));

        $in['stu_first'] = trim((string)($_POST['stu_first'] ?? ''));
        $in['stu_middle'] = trim((string)($_POST['stu_middle'] ?? ''));
        $in['stu_last'] = trim((string)($_POST['stu_last'] ?? ''));
        $in['dob'] = trim((string)($_POST['dob'] ?? ''));
        $in['gender'] = trim((string)($_POST['gender'] ?? 'male'));
        $in['place_of_birth'] = trim((string)($_POST['place_of_birth'] ?? ''));
        $in['nationality'] = trim((string)($_POST['nationality'] ?? ''));
        $in['caste'] = trim((string)($_POST['caste'] ?? ''));
        $in['languages'] = trim((string)($_POST['languages'] ?? ''));
        $in['address'] = trim((string)($_POST['address'] ?? ''));
        $in['city'] = trim((string)($_POST['city'] ?? ''));
        $in['state'] = trim((string)($_POST['state'] ?? ''));
        $in['country'] = trim((string)($_POST['country'] ?? ''));
        $in['pin'] = trim((string)($_POST['pin'] ?? ''));

        $in['father_first'] = trim((string)($_POST['father_first'] ?? ''));
        $in['father_middle'] = trim((string)($_POST['father_middle'] ?? ''));
        $in['father_last'] = trim((string)($_POST['father_last'] ?? ''));
        $in['father_email'] = trim((string)($_POST['father_email'] ?? ''));
        $in['father_edu'] = trim((string)($_POST['father_edu'] ?? ''));
        $in['father_prof'] = trim((string)($_POST['father_prof'] ?? ''));
        $in['father_designation'] = trim((string)($_POST['father_designation'] ?? ''));
        $in['father_phone'] = preg_replace('/\D+/', '', (string)($_POST['father_phone'] ?? ''));

        $in['mother_first'] = trim((string)($_POST['mother_first'] ?? ''));
        $in['mother_middle'] = trim((string)($_POST['mother_middle'] ?? ''));
        $in['mother_last'] = trim((string)($_POST['mother_last'] ?? ''));
        $in['mother_email'] = trim((string)($_POST['mother_email'] ?? ''));
        $in['mother_edu'] = trim((string)($_POST['mother_edu'] ?? ''));
        $in['mother_prof'] = trim((string)($_POST['mother_prof'] ?? ''));
        $in['mother_designation'] = trim((string)($_POST['mother_designation'] ?? ''));
        $in['mother_phone'] = preg_replace('/\D+/', '', (string)($_POST['mother_phone'] ?? ''));

        $in['guardian_name'] = trim((string)($_POST['guardian_name'] ?? ''));
        $in['guardian_email'] = trim((string)($_POST['guardian_email'] ?? ''));
        $in['guardian_relation'] = trim((string)($_POST['guardian_relation'] ?? ''));
        $in['guardian_phone'] = preg_replace('/\D+/', '', (string)($_POST['guardian_phone'] ?? ''));

        $in['previous_school'] = trim((string)($_POST['previous_school'] ?? ''));
        $in['allergies'] = trim((string)($_POST['allergies'] ?? ''));
        $in['health_conditions'] = trim((string)($_POST['health_conditions'] ?? ''));
        $in['current_medications'] = trim((string)($_POST['current_medications'] ?? ''));
        $in['immunization_records'] = trim((string)($_POST['immunization_records'] ?? ''));
        $in['sibling1'] = trim((string)($_POST['sibling1'] ?? ''));
        $in['sibling2'] = trim((string)($_POST['sibling2'] ?? ''));
        $in['additional_info'] = trim((string)($_POST['additional_info'] ?? ''));
        $in['application_date'] = trim((string)($_POST['application_date'] ?? date('Y-m-d')));
        $in['parent_signature'] = trim((string)($_POST['parent_signature'] ?? ''));

        $in['total_fees'] = trim((string)($_POST['total_fees'] ?? ''));
        $in['installment1'] = trim((string)($_POST['installment1'] ?? ''));
        $in['installment2'] = trim((string)($_POST['installment2'] ?? ''));
        $in['installment3'] = trim((string)($_POST['installment3'] ?? ''));
        $in['remark'] = trim((string)($_POST['remark'] ?? ''));
        $in['stamp'] = trim((string)($_POST['stamp'] ?? ''));

        $in['parent_login_name'] = trim((string)($_POST['parent_login_name'] ?? ''));
        $smsRaw = trim((string)($_POST['parent_login_phone'] ?? ''));
        $waRaw = trim((string)($_POST['parent_login_whatsapp'] ?? ''));
        $emailRaw = trim((string)($_POST['parent_login_email'] ?? ''));
        $in['parent_login_phone'] = parent_phone_last10($smsRaw);
        $in['parent_login_whatsapp'] = parent_phone_last10($waRaw);
        $in['parent_login_email'] = function_exists('parent_normalize_email')
            ? parent_normalize_email($emailRaw)
            : strtolower($emailRaw);
        $in['parent_login_relation'] = trim((string)($_POST['parent_login_relation'] ?? 'parent'));
        if (!in_array($in['parent_login_relation'], ['father', 'mother', 'guardian', 'parent'], true)) {
            $in['parent_login_relation'] = 'parent';
        }
        if ($in['parent_login_name'] === '') {
            $fn = trim($in['father_first'] . ' ' . $in['father_last']);
            $mn = trim($in['mother_first'] . ' ' . $in['mother_last']);
            $in['parent_login_name'] = $fn !== '' ? $fn : ($mn !== '' ? $mn : trim($in['guardian_name']));
        }
        if (strlen($in['parent_login_phone']) !== 10) {
            $in['parent_login_phone'] = parent_phone_last10((string)($in['father_phone'] ?: $in['mother_phone'] ?: $in['guardian_phone']));
        }
        if (strlen($in['parent_login_whatsapp']) !== 10) {
            $in['parent_login_whatsapp'] = $in['parent_login_phone'];
        }
        if ($in['parent_login_email'] === '') {
            foreach ([$in['father_email'] ?? '', $in['mother_email'] ?? '', $in['guardian_email'] ?? ''] as $tryEmail) {
                $norm = function_exists('parent_normalize_email') ? parent_normalize_email((string)$tryEmail) : trim((string)$tryEmail);
                if ($norm !== '') {
                    $in['parent_login_email'] = $norm;
                    break;
                }
            }
        }

        // validations
        if ($smsRaw !== '' && strlen(parent_phone_last10($smsRaw)) !== 10) {
            $errors[] = 'SMS OTP mobile must be a 10-digit number if entered.';
        }
        if ($waRaw !== '' && strlen(parent_phone_last10($waRaw)) !== 10) {
            $errors[] = 'WhatsApp OTP number must be a 10-digit number if entered.';
        }
        if ($emailRaw !== '' && $in['parent_login_email'] === '') {
            $errors[] = 'Enter a valid email address for Email OTP.';
        }
        if ($in['stu_first'] === '') $errors[] = 'Student first name required.';
        if ($in['dob'] === '') $errors[] = 'Date of birth is required.';

        // NEW validation
        if ($in['academic_year'] === '') $errors[] = 'Academic Year is required.';
        if ($in['academic_year'] !== '' && !in_array($in['academic_year'], $academicYears, true)) {
            $errors[] = 'Invalid Academic Year.';
        }

        // photo upload
        $photo_path_db = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['photo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Photo upload error (code ' . (int)$f['error'] . ').';
            } else {
                $mime = null;
                if (class_exists('finfo')) {
                    try { $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($f['tmp_name']); } catch (Throwable $e) { $mime = null; }
                }
                if ($mime === null && function_exists('mime_content_type')) $mime = mime_content_type($f['tmp_name']);
                $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
                if (empty($mime) || !isset($allowed[$mime])) {
                    $errors[] = 'Photo must be JPEG/PNG/GIF.';
                } else {
                    $ext = $allowed[$mime];
                    $uploadsDir = __DIR__ . '/../uploads/students/';
                    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                    $destName = bin2hex(random_bytes(12)) . '.' . $ext;
                    $destPath = $uploadsDir . $destName;
                    if (!move_uploaded_file($f['tmp_name'], $destPath)) $errors[] = 'Failed to move uploaded photo.';
                    else $photo_path_db = 'uploads/students/' . $destName;
                }
            }
        }

        if (empty($errors)) {
            $pdo = pdo_connect();
            if (!($pdo instanceof \PDO)) {
                $errors[] = 'Database connection unavailable.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $posted_parent_id = function_exists('parent_resolve_login')
                        ? parent_resolve_login($pdo, [
                            'name' => $in['parent_login_name'],
                            'phone' => $in['parent_login_phone'],
                            'whatsapp_id' => $in['parent_login_whatsapp'],
                            'email' => $in['parent_login_email'],
                            'school_id' => $in['school_id'] ?: 1,
                        ])
                        : (int) (parent_find_or_create($pdo, [
                            'name' => $in['parent_login_name'],
                            'phone' => $in['parent_login_phone'],
                            'whatsapp_id' => $in['parent_login_whatsapp'],
                            'email' => $in['parent_login_email'],
                            'school_id' => $in['school_id'] ?: 1,
                        ])['id'] ?? 0);
                    if ($posted_parent_id <= 0) {
                        throw new RuntimeException('Could not create or find parent login. Enter parent name or a mobile number.');
                    }

                    // discover existing student columns
                    $colsInfo = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
                    $existingCols = array_column($colsInfo, 'COLUMN_NAME');

                    // prepare dbData
                    $dbData = [];
                    $full_first = trim($in['stu_first'] . ' ' . $in['stu_middle']);
                    if (in_array('first_name', $existingCols)) $dbData['first_name'] = $full_first;
                    if (in_array('middle_name', $existingCols)) $dbData['middle_name'] = $in['stu_middle'];
                    if (in_array('last_name', $existingCols)) $dbData['last_name'] = $in['stu_last'];
                    if (in_array('school_id', $existingCols)) $dbData['school_id'] = $in['school_id'] ?: 1;
                    if (in_array('dob', $existingCols)) $dbData['dob'] = $in['dob'];
                    if (in_array('class_id', $existingCols)) $dbData['class_id'] = $in['class_id'];
                    if (in_array('parent_id', $existingCols)) $dbData['parent_id'] = $posted_parent_id;
                    if (in_array('photo_path', $existingCols)) $dbData['photo_path'] = $photo_path_db;
                    if (in_array('admission_date', $existingCols)) $dbData['admission_date'] = $in['application_date'];

                    // NEW: Save academic_year if column exists
                    if (in_array('academic_year', $existingCols)) $dbData['academic_year'] = $in['academic_year'];

                    if (in_array('status', $existingCols)) $dbData['status'] = 'active';
                    $now = date('Y-m-d H:i:s');
                    if (in_array('created_at', $existingCols)) $dbData['created_at'] = $now;
                    if (in_array('updated_at', $existingCols)) $dbData['updated_at'] = $now;

                    // extended map
                    $map = [
                        'form_no' => $in['form_no'],
                        'location' => $in['location'],

                        // NEW
                        'academic_year' => $in['academic_year'],

                        'place_of_birth' => $in['place_of_birth'],
                        'gender' => $in['gender'],
                        'nationality' => $in['nationality'],
                        'caste' => $in['caste'],
                        'languages' => $in['languages'],
                        'address' => $in['address'],
                        'city' => $in['city'],
                        'state' => $in['state'],
                        'country' => $in['country'],
                        'pin' => $in['pin'],

                        'father_first' => $in['father_first'],
                        'father_middle' => $in['father_middle'],
                        'father_last' => $in['father_last'],
                        'father_email' => $in['father_email'],
                        'father_edu' => $in['father_edu'],
                        'father_prof' => $in['father_prof'],
                        'father_designation' => $in['father_designation'],
                        'father_phone' => $in['father_phone'],

                        'mother_first' => $in['mother_first'],
                        'mother_middle' => $in['mother_middle'],
                        'mother_last' => $in['mother_last'],
                        'mother_email' => $in['mother_email'],
                        'mother_edu' => $in['mother_edu'],
                        'mother_prof' => $in['mother_prof'],
                        'mother_designation' => $in['mother_designation'],
                        'mother_phone' => $in['mother_phone'],

                        'guardian_name' => $in['guardian_name'],
                        'guardian_email' => $in['guardian_email'],
                        'guardian_relation' => $in['guardian_relation'],
                        'guardian_phone' => $in['guardian_phone'],

                        'previous_school' => $in['previous_school'],

                        'allergies' => $in['allergies'],
                        'health_conditions' => $in['health_conditions'],
                        'current_medications' => $in['current_medications'],
                        'immunization_records' => $in['immunization_records'],

                        'sibling1' => $in['sibling1'],
                        'sibling2' => $in['sibling2'],

                        'additional_info' => $in['additional_info'],
                        'parent_signature' => $in['parent_signature'],

                        'total_fees' => $in['total_fees'] !== '' ? $in['total_fees'] : null,
                        'installment1' => $in['installment1'] !== '' ? $in['installment1'] : null,
                        'installment2' => $in['installment2'] !== '' ? $in['installment2'] : null,
                        'installment3' => $in['installment3'] !== '' ? $in['installment3'] : null,
                        'remark' => $in['remark'],
                        'stamp' => $in['stamp']
                    ];
                    foreach ($map as $col => $val) {
                        if (in_array($col, $existingCols)) $dbData[$col] = $val;
                    }

                    // extended_json payload
                    $extended_payload = [
                        'form_no'=>$in['form_no'],
                        'location'=>$in['location'],

                        // NEW
                        'academic_year' => $in['academic_year'],

                        'admission_seeking_in'=>$in['class_id'],
                        'student'=>[
                            'first'=>$in['stu_first'],
                            'middle'=>$in['stu_middle'],
                            'last'=>$in['stu_last'],
                            'dob'=>$in['dob'],
                            'gender'=>$in['gender']
                        ],
                        'place_of_birth'=>$in['place_of_birth'],
                        'nationality'=>$in['nationality'],
                        'caste'=>$in['caste'],
                        'languages'=>$in['languages'],
                        'address'=>[
                            'address'=>$in['address'],
                            'city'=>$in['city'],
                            'state'=>$in['state'],
                            'country'=>$in['country'],
                            'pin'=>$in['pin']
                        ],
                        'father'=>['first'=>$in['father_first'],'phone'=>$in['father_phone']],
                        'mother'=>['first'=>$in['mother_first'],'phone'=>$in['mother_phone']],
                        'guardian'=>[
                            'name'=>$in['guardian_name'],
                            'email'=>$in['guardian_email'],
                            'phone'=>$in['guardian_phone'],
                            'relation'=>$in['guardian_relation']
                        ],
                        'previous_school'=>$in['previous_school'],
                        'medical'=>[
                            'allergies'=>$in['allergies'],
                            'health_conditions'=>$in['health_conditions'],
                            'current_medications'=>$in['current_medications'],
                            'immunization_records'=>$in['immunization_records']
                        ],
                        'siblings'=>['1'=>$in['sibling1'],'2'=>$in['sibling2']],
                        'additional_info'=>$in['additional_info'],
                        'fees'=>[
                            'total'=>$in['total_fees'],
                            'installments'=>[$in['installment1'],$in['installment2'],$in['installment3']],
                            'remark'=>$in['remark'],
                            'stamp'=>$in['stamp']
                        ],
                        'created_at'=>$now,
                        'parent_login'=>[
                            'name'=>$in['parent_login_name'],
                            'phone'=>$in['parent_login_phone'],
                            'whatsapp'=>$in['parent_login_whatsapp'],
                            'email'=>$in['parent_login_email'],
                            'relation'=>$in['parent_login_relation'],
                            'user_id'=>$posted_parent_id,
                        ],
                    ];
                    if (in_array('extended_json', $existingCols)) {
                        $dbData['extended_json'] = json_encode($extended_payload, JSON_UNESCAPED_UNICODE);
                    }

                    if (empty($dbData)) throw new RuntimeException('No matching students columns found to insert.');

                    $colsSql = implode('`,`', array_keys($dbData));
                    $placeholders = implode(',', array_map(function($c){ return ':' . $c; }, array_keys($dbData)));
                    $sql = "INSERT INTO `students` (`" . $colsSql . "`) VALUES (" . $placeholders . ")";
                    $stmt = $pdo->prepare($sql);

                    $bindParams = [];
                    foreach ($dbData as $col => $val) {
                        $bindParams[':'.$col] = $val === '' ? null : $val;
                    }
                    $stmt->execute($bindParams);
                    $studentId = (int)$pdo->lastInsertId();
                    if ($studentId <= 0) throw new RuntimeException('Failed to insert student record.');

                    parent_link_student($pdo, $posted_parent_id, $studentId, $in['parent_login_relation']);

                    // write meta json file
                    $metaDir = __DIR__ . '/../uploads/students/meta/';
                    if (!is_dir($metaDir)) @mkdir($metaDir, 0755, true);
                    @file_put_contents(
                        $metaDir . 'student_' . $studentId . '.json',
                        json_encode($extended_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );

                    $pdo->commit();

                    $_SESSION['flash_success'] = 'Admission saved. Student ID: ' . $studentId;
                    header('Location: ../reception/students_view.php?id=' . urlencode((string)$studentId));
                    exit;
                } catch (Throwable $e) {
                    if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                    error_log('admission save error: ' . $e->getMessage());
                    $errors[] = $DEBUG ? 'Error: ' . $e->getMessage() : 'Failed to save admission.';
                    if (!empty($photo_path_db)) {
                        $fp = __DIR__ . '/../' . ltrim($photo_path_db, '/');
                        if (is_file($fp)) @unlink($fp);
                    }
                }
            }
        }
    }
}

/* class fees map for JS */
$class_fees_map = [];
foreach ($classList as $c) $class_fees_map[(int)$c['id']] = $c['fees'] ?? '';

/* Render UI */
$page_title = 'Student Admission';
$selectedYear = $_POST['academic_year'] ?? ($defaults['academic_year'] ?? '');
$admBase = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');
require_once __DIR__ . '/../includes/header.php';
?>

<link href="<?php echo e($admBase); ?>/assets/css/admission-form.css" rel="stylesheet">

<div class="adm-page">
  <?php if (!empty($_SESSION['flash_success'])) { echo '<div class="alert alert-success">'.e($_SESSION['flash_success']).'</div>'; unset($_SESSION['flash_success']); } ?>
  <?php if (!empty($messages)) foreach ($messages as $m) echo '<div class="alert alert-success">'.e($m).'</div>'; ?>
  <?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div><?php endif; ?>

  <div class="panel-toolbar adm-toolbar">
    <div>
      <p class="panel-page-lead mb-1">Fill student details. Parent Portal login is created from the parent mobile number — the same number on two children keeps one parent login.</p>
    </div>
    <div class="adm-toolbar-actions">
      <a href="students_list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-people me-1"></i>Students</a>
    </div>
  </div>

  <nav class="adm-nav" aria-label="Form sections">
    <a href="#adm-basic" class="active">Basic</a>
    <a href="#adm-parent">Parent login</a>
    <a href="#adm-student">Student</a>
    <a href="#adm-address">Address</a>
    <a href="#adm-family">Family</a>
    <a href="#adm-other">Other</a>
    <a href="#adm-office">Office</a>
  </nav>

  <form method="post" enctype="multipart/form-data" id="admissionForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">

    <section class="adm-section" id="adm-basic">
      <div class="adm-section-head">
        <span class="adm-section-num">1</span>
        <div><h2>Admission Details</h2><p>Form no., class, academic year</p></div>
      </div>
      <div class="adm-section-body">
        <div class="row g-3">
          <div class="col-md-4 col-lg-3">
            <label class="form-label">Form No.</label>
            <input name="form_no" class="form-control" value="<?php echo e($defaults['form_no']); ?>" readonly>
          </div>
          <div class="col-md-4 col-lg-3">
            <label class="form-label">Academic Year <span class="adm-req">*</span></label>
            <select name="academic_year" class="form-select" required>
              <option value="">-- Select Year --</option>
              <?php foreach ($academicYears as $y): ?>
                <option value="<?php echo e($y); ?>" <?php if ($selectedYear === $y) echo 'selected'; ?>>
                  <?php echo e(function_exists('ay_display_long') ? ay_display_long($y) : $y); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 col-lg-3">
            <label class="form-label">Admission Seeking In</label>
            <select name="admission_seeking_in" id="admission_seeking_in" class="form-select">
              <option value="">-- Choose class --</option>
              <?php $seekClass = $_POST['admission_seeking_in'] ?? ($defaults['admission_seeking_in'] ?? ''); foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if((string)$seekClass===(string)$c['id']) echo 'selected'; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 col-lg-3">
            <label class="form-label">School <span class="small-muted">(optional)</span></label>
            <select name="school_id" class="form-select">
              <option value="">-- Select school --</option>
              <?php foreach ($schoolList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if((string)$defaults['school_id']===(string)$s['id']) echo 'selected'; ?>><?php echo e($s['name']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Location</label>
            <input name="location" class="form-control" placeholder="Branch / area" value="<?php echo e($_POST['location'] ?? $defaults['location']); ?>">
          </div>
        </div>
      </div>
    </section>

    <section class="adm-section" id="adm-parent">
      <div class="adm-section-head">
        <span class="adm-section-num">2</span>
        <div>
          <h2>Parent portal login</h2>
          <p>SMS, WhatsApp and email OTP are optional. Parent name is enough to create the Parents-page login. The same mobile on a second child keeps one login. If SMS is blank, father/mother phone is used when available.</p>
        </div>
      </div>
      <div class="adm-section-body">
        <?php if ($parent): ?>
          <div class="adm-parent-banner mb-3"><i class="bi bi-person-check-fill"></i> Prefill from existing parent: <strong><?php echo e($parent['name']); ?></strong><?php if (!empty($parent['phone'])) echo ' · '.e($parent['phone']); ?></div>
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Parent name</label>
            <input name="parent_login_name" class="form-control" value="<?php echo e($_POST['parent_login_name'] ?? $defaults['parent_login_name']); ?>" placeholder="Name as on Parent Portal">
          </div>
          <div class="col-md-6">
            <label class="form-label">Relation</label>
            <?php $rel = $_POST['parent_login_relation'] ?? $defaults['parent_login_relation']; ?>
            <select name="parent_login_relation" class="form-select">
              <option value="parent" <?php if ($rel === 'parent') echo 'selected'; ?>>Parent</option>
              <option value="father" <?php if ($rel === 'father') echo 'selected'; ?>>Father</option>
              <option value="mother" <?php if ($rel === 'mother') echo 'selected'; ?>>Mother</option>
              <option value="guardian" <?php if ($rel === 'guardian') echo 'selected'; ?>>Guardian</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">SMS OTP mobile</label>
            <input name="parent_login_phone" class="form-control" inputmode="numeric" maxlength="15" value="<?php echo e($_POST['parent_login_phone'] ?? parent_phone_last10((string)$defaults['parent_login_phone'])); ?>" placeholder="10-digit mobile">
          </div>
          <div class="col-md-4">
            <label class="form-label">WhatsApp OTP</label>
            <input name="parent_login_whatsapp" class="form-control" inputmode="numeric" maxlength="15" value="<?php echo e($_POST['parent_login_whatsapp'] ?? parent_phone_last10((string)$defaults['parent_login_whatsapp'])); ?>" placeholder="10-digit WhatsApp">
          </div>
          <div class="col-md-4">
            <label class="form-label">Email OTP</label>
            <input type="email" name="parent_login_email" class="form-control" value="<?php echo e($_POST['parent_login_email'] ?? $defaults['parent_login_email']); ?>" placeholder="parent@email.com">
          </div>
        </div>
      </div>
    </section>

    <section class="adm-section" id="adm-student">
      <div class="adm-section-head">
        <span class="adm-section-num">3</span>
        <div><h2>Student Personal Details</h2><p>Name, DOB, gender &amp; identity</p></div>
      </div>
      <div class="adm-section-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Student's Name <span class="adm-req">*</span></label>
            <div class="row g-2">
              <div class="col-md-4"><input type="text" name="stu_first" class="form-control" placeholder="First" required value="<?php echo e($_POST['stu_first'] ?? $defaults['stu_first']); ?>"></div>
              <div class="col-md-4"><input type="text" name="stu_middle" class="form-control" placeholder="Middle" value="<?php echo e($_POST['stu_middle'] ?? $defaults['stu_middle']); ?>"></div>
              <div class="col-md-4"><input type="text" name="stu_last" class="form-control" placeholder="Last" value="<?php echo e($_POST['stu_last'] ?? $defaults['stu_last']); ?>"></div>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date of Birth <span class="adm-req">*</span></label>
            <input type="date" name="dob" class="form-control" required value="<?php echo e($_POST['dob'] ?? $defaults['dob']); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label d-block">Gender</label>
            <?php $g = $_POST['gender'] ?? $defaults['gender']; ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="gender" id="gender_male" value="male" <?php if($g==='male') echo 'checked'; ?>> <label class="form-check-label" for="gender_male">Male</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="gender" id="gender_female" value="female" <?php if($g==='female') echo 'checked'; ?>> <label class="form-check-label" for="gender_female">Female</label></div>
          </div>
          <div class="col-md-4"><label class="form-label">Place of Birth</label><input name="place_of_birth" class="form-control" value="<?php echo e($_POST['place_of_birth'] ?? $defaults['place_of_birth']); ?>"></div>
          <div class="col-md-4"><label class="form-label">Nationality</label><input name="nationality" class="form-control" value="<?php echo e($_POST['nationality'] ?? $defaults['nationality']); ?>"></div>
          <div class="col-md-4"><label class="form-label">Caste Category</label><input name="caste" class="form-control" value="<?php echo e($_POST['caste'] ?? $defaults['caste']); ?>"></div>
          <div class="col-md-4"><label class="form-label">Languages Known</label><input name="languages" class="form-control" value="<?php echo e($_POST['languages'] ?? $defaults['languages']); ?>"></div>
        </div>
      </div>
    </section>

    <section class="adm-section" id="adm-address">
      <div class="adm-section-head">
        <span class="adm-section-num">4</span>
        <div><h2>Residential Address</h2><p>Contact address details</p></div>
      </div>
      <div class="adm-section-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Address</label><input name="address" class="form-control" value="<?php echo e($_POST['address'] ?? $defaults['address']); ?>"></div>
          <div class="col-md-3"><label class="form-label">City</label><input name="city" class="form-control" value="<?php echo e($_POST['city'] ?? $defaults['city']); ?>"></div>
          <div class="col-md-3"><label class="form-label">State</label><input name="state" class="form-control" value="<?php echo e($_POST['state'] ?? $defaults['state']); ?>"></div>
          <div class="col-md-3"><label class="form-label">Country</label><input name="country" class="form-control" value="<?php echo e($_POST['country'] ?? $defaults['country']); ?>"></div>
          <div class="col-md-3"><label class="form-label">PIN Code</label><input name="pin" class="form-control" inputmode="numeric" value="<?php echo e($_POST['pin'] ?? $defaults['pin']); ?>"></div>
        </div>
      </div>
    </section>

    <section class="adm-section" id="adm-family">
      <div class="adm-section-head">
        <span class="adm-section-num">5</span>
        <div><h2>Family Information</h2><p>Father, mother &amp; emergency guardian</p></div>
        <button type="button" class="adm-collapse-toggle d-md-none" data-bs-toggle="collapse" data-bs-target="#admFamilyBody" aria-expanded="true">Toggle</button>
      </div>
      <div class="collapse show" id="admFamilyBody">
        <div class="adm-section-body">
          <div class="adm-subsection-title"><i class="bi bi-person me-1"></i>Father</div>
          <div class="row g-3 mb-2">
            <div class="col-12">
              <div class="row g-2">
                <div class="col-md-4"><input type="text" name="father_first" class="form-control" placeholder="First name" value="<?php echo e($_POST['father_first'] ?? $defaults['father_first']); ?>"></div>
                <div class="col-md-4"><input type="text" name="father_middle" class="form-control" placeholder="Middle name" value="<?php echo e($_POST['father_middle'] ?? $defaults['father_middle']); ?>"></div>
                <div class="col-md-4"><input type="text" name="father_last" class="form-control" placeholder="Last name" value="<?php echo e($_POST['father_last'] ?? $defaults['father_last']); ?>"></div>
              </div>
            </div>
            <div class="col-md-4"><label class="form-label small-muted">E-mail</label><input type="email" name="father_email" class="form-control" value="<?php echo e($_POST['father_email'] ?? $defaults['father_email']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Education</label><input name="father_edu" class="form-control" value="<?php echo e($_POST['father_edu'] ?? $defaults['father_edu']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Profession</label><input name="father_prof" class="form-control" value="<?php echo e($_POST['father_prof'] ?? $defaults['father_prof']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Designation</label><input name="father_designation" class="form-control" value="<?php echo e($_POST['father_designation'] ?? $defaults['father_designation']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Phone</label><input name="father_phone" class="form-control" inputmode="tel" value="<?php echo e($_POST['father_phone'] ?? $defaults['father_phone']); ?>"></div>
          </div>

          <div class="adm-subsection-title"><i class="bi bi-person me-1"></i>Mother</div>
          <div class="row g-3 mb-2">
            <div class="col-12">
              <div class="row g-2">
                <div class="col-md-4"><input type="text" name="mother_first" class="form-control" placeholder="First name" value="<?php echo e($_POST['mother_first'] ?? $defaults['mother_first']); ?>"></div>
                <div class="col-md-4"><input type="text" name="mother_middle" class="form-control" placeholder="Middle name" value="<?php echo e($_POST['mother_middle'] ?? $defaults['mother_middle']); ?>"></div>
                <div class="col-md-4"><input type="text" name="mother_last" class="form-control" placeholder="Last name" value="<?php echo e($_POST['mother_last'] ?? $defaults['mother_last']); ?>"></div>
              </div>
            </div>
            <div class="col-md-4"><label class="form-label small-muted">E-mail</label><input type="email" name="mother_email" class="form-control" value="<?php echo e($_POST['mother_email'] ?? $defaults['mother_email']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Education</label><input name="mother_edu" class="form-control" value="<?php echo e($_POST['mother_edu'] ?? $defaults['mother_edu']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Profession</label><input name="mother_prof" class="form-control" value="<?php echo e($_POST['mother_prof'] ?? $defaults['mother_prof']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Designation</label><input name="mother_designation" class="form-control" value="<?php echo e($_POST['mother_designation'] ?? $defaults['mother_designation']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Phone</label><input name="mother_phone" class="form-control" inputmode="tel" value="<?php echo e($_POST['mother_phone'] ?? $defaults['mother_phone']); ?>"></div>
          </div>

          <div class="adm-subsection-title"><i class="bi bi-shield-check me-1"></i>Guardian (Emergency)</div>
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label small-muted">Full Name</label><input name="guardian_name" class="form-control" value="<?php echo e($_POST['guardian_name'] ?? $defaults['guardian_name']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Relation</label><input name="guardian_relation" class="form-control" value="<?php echo e($_POST['guardian_relation'] ?? $defaults['guardian_relation']); ?>"></div>
            <div class="col-md-4"><label class="form-label small-muted">Phone</label><input name="guardian_phone" class="form-control" inputmode="tel" value="<?php echo e($_POST['guardian_phone'] ?? $defaults['guardian_phone']); ?>"></div>
            <div class="col-md-6"><label class="form-label small-muted">E-mail</label><input name="guardian_email" class="form-control" value="<?php echo e($_POST['guardian_email'] ?? $defaults['guardian_email']); ?>"></div>
          </div>
        </div>
      </div>
    </section>

    <section class="adm-section" id="adm-other">
      <div class="adm-section-head">
        <span class="adm-section-num">6</span>
        <div><h2>Education, Health &amp; Documents</h2><p>Background, medical info &amp; photo</p></div>
      </div>
      <div class="adm-section-body">
        <div class="adm-subsection-title">Educational Background</div>
        <div class="mb-3"><input name="previous_school" class="form-control" placeholder="Previous school name" value="<?php echo e($_POST['previous_school'] ?? $defaults['previous_school']); ?>"></div>

        <div class="adm-subsection-title">Medical Information</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label small-muted">Allergies</label><input name="allergies" class="form-control" value="<?php echo e($_POST['allergies'] ?? $defaults['allergies']); ?>"></div>
          <div class="col-md-6"><label class="form-label small-muted">Health Conditions</label><input name="health_conditions" class="form-control" value="<?php echo e($_POST['health_conditions'] ?? $defaults['health_conditions']); ?>"></div>
          <div class="col-md-6"><label class="form-label small-muted">Current Medications</label><input name="current_medications" class="form-control" value="<?php echo e($_POST['current_medications'] ?? $defaults['current_medications']); ?>"></div>
          <div class="col-md-6"><label class="form-label small-muted">Immunization Records</label><input name="immunization_records" class="form-control" value="<?php echo e($_POST['immunization_records'] ?? $defaults['immunization_records']); ?>"></div>
        </div>

        <div class="adm-subsection-title">Siblings</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><input name="sibling1" class="form-control" placeholder="Sibling 1" value="<?php echo e($_POST['sibling1'] ?? $defaults['sibling1']); ?>"></div>
          <div class="col-md-6"><input name="sibling2" class="form-control" placeholder="Sibling 2" value="<?php echo e($_POST['sibling2'] ?? $defaults['sibling2']); ?>"></div>
        </div>

        <div class="adm-subsection-title">Photo &amp; Declaration</div>
        <div class="row g-3">
          <div class="col-lg-7">
            <label class="form-label">Passport-size photograph</label>
            <div class="adm-photo-upload">
              <div class="adm-photo-preview" id="photoPreview"><i class="bi bi-camera"></i></div>
              <div class="flex-grow-1">
                <input type="file" name="photo" id="photoInput" accept="image/*" class="form-control">
                <div class="form-text">JPEG, PNG or GIF — max recommended 2 MB.</div>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3">
            <label class="form-label">Application Date</label>
            <input type="date" name="application_date" class="form-control" value="<?php echo e($_POST['application_date'] ?? $defaults['application_date']); ?>">
          </div>
          <div class="col-md-6 col-lg-2">
            <label class="form-label">Signature</label>
            <input name="parent_signature" class="form-control" placeholder="Name" value="<?php echo e($_POST['parent_signature'] ?? $defaults['parent_signature']); ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Additional Information</label>
            <textarea name="additional_info" class="form-control" rows="3" placeholder="Any other notes"><?php echo e($_POST['additional_info'] ?? $defaults['additional_info']); ?></textarea>
          </div>
        </div>
      </div>
    </section>

    <section class="adm-section adm-office" id="adm-office">
      <div class="adm-section-head">
        <span class="adm-section-num"><i class="bi bi-building"></i></span>
        <div><h2>Office Use Only</h2><p>Fees &amp; internal remarks</p></div>
      </div>
      <div class="adm-section-body">
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label">Total Fees</label><input id="total_fees" name="total_fees" class="form-control" inputmode="decimal" value="<?php echo e($_POST['total_fees'] ?? $defaults['total_fees']); ?>"></div>
          <div class="col-md-3"><label class="form-label">Installment 1</label><input name="installment1" class="form-control" inputmode="decimal" value="<?php echo e($_POST['installment1'] ?? $defaults['installment1']); ?>"></div>
          <div class="col-md-3"><label class="form-label">Installment 2</label><input name="installment2" class="form-control" inputmode="decimal" value="<?php echo e($_POST['installment2'] ?? $defaults['installment2']); ?>"></div>
          <div class="col-md-3"><label class="form-label">Installment 3</label><input name="installment3" class="form-control" inputmode="decimal" value="<?php echo e($_POST['installment3'] ?? $defaults['installment3']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Remark</label><input name="remark" class="form-control" value="<?php echo e($_POST['remark'] ?? $defaults['remark']); ?>"></div>
          <div class="col-md-6"><label class="form-label">Stamp</label><input name="stamp" class="form-control" value="<?php echo e($_POST['stamp'] ?? $defaults['stamp']); ?>"></div>
        </div>
      </div>
    </section>

    <div class="adm-submit-bar">
      <p class="form-text small-muted"><span class="adm-req">*</span> Parent name, parent mobile, student name, date of birth, and academic year are required.</p>
      <button class="btn btn-success btn-lg" type="submit"><i class="bi bi-check2-circle me-1"></i>Submit Admission</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var classFees = <?php echo json_encode($class_fees_map); ?>;
  var classSel = document.getElementById('admission_seeking_in');
  var totalFeesInput = document.getElementById('total_fees');
  function updateFees(){
    if (!classSel || !totalFeesInput) return;
    var v = classSel.value;
    totalFeesInput.value = (v && classFees[v] !== undefined) ? classFees[v] : '';
  }
  if (classSel) { classSel.addEventListener('change', updateFees); updateFees(); }

  var photoInput = document.getElementById('photoInput');
  var photoPreview = document.getElementById('photoPreview');
  if (photoInput && photoPreview) {
    photoInput.addEventListener('change', function(){
      var file = photoInput.files && photoInput.files[0];
      if (!file) { photoPreview.innerHTML = '<i class="bi bi-camera"></i>'; return; }
      var reader = new FileReader();
      reader.onload = function(e){ photoPreview.innerHTML = '<img src="'+e.target.result+'" alt="Preview">'; };
      reader.readAsDataURL(file);
    });
  }

  document.querySelectorAll('.adm-nav a').forEach(function(link){
    link.addEventListener('click', function(){
      document.querySelectorAll('.adm-nav a').forEach(function(a){ a.classList.remove('active'); });
      link.classList.add('active');
    });
  });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>