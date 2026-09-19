<?php
/**
 * teacher/student_view.php — classroom card for one child (no fees).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$studentId = (int) ($_GET['id'] ?? 0);

$t = static function (string $path): string {
    return function_exists('site_url') ? site_url('/teacher/' . ltrim($path, '/')) : $path;
};

if ($studentId <= 0) {
    http_response_code(400);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Choose a child from My Classes.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . e($t('my_classes.php')) . '">My Classes</a>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$student = table_exists('students')
    ? safe_db_get_one('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $studentId])
    : null;
if (!$student) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">Child not found.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . e($t('my_classes.php')) . '">My Classes</a>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if (function_exists('student_hydrate_row')) {
    $student = student_hydrate_row($student);
}

$studentClassId = (int) ($student['class_id'] ?? 0);
$allowedIds = array_values(array_filter(array_map(
    static fn(array $c): int => (int) ($c['id'] ?? 0),
    panel_teacher_assigned_classes($teacherId)
)));
$authorized = (function_exists('auth_is_owner_super') && auth_is_owner_super())
    || in_array($studentClassId, $allowedIds, true);

if (!$authorized) {
    http_response_code(403);
    $page_title = 'Child';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">This child is not in your class.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . e($t('my_classes.php')) . '">My Classes</a>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$className = '';
if ($studentClassId > 0 && table_exists('classes')) {
    $classInfo = safe_db_get_one('SELECT id, name FROM classes WHERE id = :id LIMIT 1', [':id' => $studentClassId]);
    $className = trim((string) ($classInfo['name'] ?? ''));
}

$name = function_exists('student_full_name') ? student_full_name($student) : trim(
    (string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? '')
);
if ($name === '') {
    $name = 'Child';
}
$photo = function_exists('student_photo_url') ? student_photo_url((string) ($student['photo_path'] ?? '')) : '';

$txt = static function (mixed $v): string {
    return trim((string) ($v ?? ''));
};
$person = static function (string $first, string $middle, string $last) use ($txt): string {
    return trim(preg_replace('/\s+/', ' ', $txt($first) . ' ' . $txt($middle) . ' ' . $txt($last)) ?? '');
};
$phone10 = static function (mixed $raw): string {
    $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
    return strlen($d) >= 10 ? substr($d, -10) : $d;
};

$dob = substr($txt($student['dob'] ?? ''), 0, 10);
$age = '';
if ($dob !== '' && $dob !== '0000-00-00') {
    try {
        $y = (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
        $age = $y > 0 ? $y . ' years' : '';
    } catch (Throwable $e) {
        $age = '';
    }
}

$fatherName = $person((string) ($student['father_first'] ?? ''), (string) ($student['father_middle'] ?? ''), (string) ($student['father_last'] ?? ''));
$motherName = $person((string) ($student['mother_first'] ?? ''), (string) ($student['mother_middle'] ?? ''), (string) ($student['mother_last'] ?? ''));
$fatherPhone = $phone10($student['father_phone'] ?? '');
$motherPhone = $phone10($student['mother_phone'] ?? '');
$guardianName = $txt($student['guardian_name'] ?? '');
$guardianPhone = $phone10($student['guardian_phone'] ?? '');
$guardianRel = $txt($student['guardian_relation'] ?? '');

$allergies = $txt($student['allergies'] ?? '');
$health = $txt($student['health_conditions'] ?? '');
$meds = $txt($student['current_medications'] ?? '');
$hasMedical = $allergies !== '' || $health !== '' || $meds !== '';

$addressBits = array_filter([
    $txt($student['address'] ?? ''),
    $txt($student['city'] ?? ''),
    $txt($student['pin'] ?? ''),
], static fn(string $v): bool => $v !== '');
$address = implode(', ', $addressBits);
$languages = $txt($student['languages'] ?? '');
$sibling1 = $txt($student['sibling1'] ?? '');
$sibling2 = $txt($student['sibling2'] ?? '');
$note = $txt($student['additional_info'] ?? '');
$gender = $txt($student['gender'] ?? '');

$backClass = $studentClassId > 0 ? $t('my_classes.php?class_id=' . $studentClassId) : $t('my_classes.php');

$page_title = $name;
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.sv-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px; margin-bottom:12px; }
.sv-photo { width:88px; height:88px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.sv-name { font-weight:800; color:#1e3a5f; font-size:1.25rem; }
.sv-meta { color:#64748b; font-size:.9rem; }
.sv-label { font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:.15rem; }
.sv-val { font-weight:600; color:#1e3a5f; }
.sv-call { display:inline-flex; align-items:center; gap:.35rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <a class="btn btn-outline-secondary btn-sm" href="<?php echo e($backClass); ?>">← My Classes</a>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($studentClassId > 0): ?>
      <a class="btn btn-sm btn-primary" href="<?php echo e($t('attendance_mark.php?class_id=' . $studentClassId . '&student_id=' . $studentId)); ?>">Attendance</a>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo e($t('student_remarks.php?class_id=' . $studentClassId . '&student_id=' . $studentId)); ?>">Remarks</a>
    <?php endif; ?>
  </div>
</div>

<div class="sv-card d-flex flex-wrap gap-3 align-items-center">
  <?php if ($photo !== ''): ?>
    <img class="sv-photo" src="<?php echo e($photo); ?>" alt="">
  <?php else: ?>
    <div class="sv-photo"></div>
  <?php endif; ?>
  <div>
    <div class="sv-name"><?php echo e($name); ?></div>
    <div class="sv-meta">
      <?php echo $className !== '' ? e($className) : 'Class not set'; ?>
      <?php if ($age !== ''): ?> · <?php echo e($age); ?><?php endif; ?>
      <?php if ($gender !== ''): ?> · <?php echo e($gender); ?><?php endif; ?>
    </div>
    <?php if ($dob !== '' && $dob !== '0000-00-00'): ?>
      <div class="sv-meta">Birthday <?php echo e(date('d M Y', strtotime($dob)) ?: $dob); ?></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasMedical): ?>
  <div class="alert alert-warning">
    <strong>Health — tell the class every day</strong>
    <ul class="mb-0 mt-2">
      <?php if ($allergies !== ''): ?><li>Allergies: <?php echo e($allergies); ?></li><?php endif; ?>
      <?php if ($health !== ''): ?><li>Health: <?php echo e($health); ?></li><?php endif; ?>
      <?php if ($meds !== ''): ?><li>Medicine in school: <?php echo e($meds); ?></li><?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="sv-card">
  <div class="fw-bold mb-3">Who to call</div>
  <div class="row g-3">
    <div class="col-md-4">
      <div class="sv-label">Father</div>
      <div class="sv-val"><?php echo e($fatherName !== '' ? $fatherName : '—'); ?></div>
      <?php if ($fatherPhone !== ''): ?>
        <a class="sv-call small" href="tel:<?php echo e($fatherPhone); ?>"><?php echo e($fatherPhone); ?></a>
      <?php endif; ?>
    </div>
    <div class="col-md-4">
      <div class="sv-label">Mother</div>
      <div class="sv-val"><?php echo e($motherName !== '' ? $motherName : '—'); ?></div>
      <?php if ($motherPhone !== ''): ?>
        <a class="sv-call small" href="tel:<?php echo e($motherPhone); ?>"><?php echo e($motherPhone); ?></a>
      <?php endif; ?>
    </div>
    <div class="col-md-4">
      <div class="sv-label">Pickup / other</div>
      <div class="sv-val"><?php echo e($guardianName !== '' ? $guardianName : 'Same as parents'); ?></div>
      <?php if ($guardianRel !== ''): ?><div class="sv-meta"><?php echo e($guardianRel); ?></div><?php endif; ?>
      <?php if ($guardianPhone !== ''): ?>
        <a class="sv-call small" href="tel:<?php echo e($guardianPhone); ?>"><?php echo e($guardianPhone); ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extras = [];
if ($address !== '') {
    $extras[] = ['Home', $address];
}
if ($languages !== '') {
    $extras[] = ['Home language', $languages];
}
$sibs = array_filter([$sibling1, $sibling2]);
if ($sibs !== []) {
    $extras[] = ['Sibling', implode(', ', $sibs)];
}
if ($note !== '') {
    $extras[] = ['Note for teacher', $note];
}
if ($extras !== []):
?>
<div class="sv-card">
  <div class="fw-bold mb-3">Classroom notes</div>
  <div class="row g-3">
    <?php foreach ($extras as [$lab, $val]): ?>
      <div class="col-md-6">
        <div class="sv-label"><?php echo e($lab); ?></div>
        <div class="sv-val" style="font-weight:500; white-space:pre-wrap"><?php echo e($val); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
