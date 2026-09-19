<?php
/**
 * Public scan page for a student ID-card QR.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/panel/bootstrap.php';
panel_bootstrap(null, ['skip_auth' => true]);
require_once __DIR__ . '/includes/student_idcard.php';

$id = (int) ($_GET['s'] ?? $_GET['id'] ?? 0);
$token = trim((string) ($_GET['t'] ?? ''));
$ok = student_idcard_check($id, $token);
$s = $ok ? student_idcard_load($id) : null;
$school = student_idcard_school();
$schoolName = trim((string) ($school['name'] ?? ''));
if ($schoolName === '') {
    $schoolName = defined('APP_NAME') ? (string) APP_NAME : 'School';
}
$logo = '';
if (!empty($school['logo_path']) && function_exists('resolve_image_url')) {
    $logo = (string) resolve_image_url((string) $school['logo_path'], '');
}

$name = '';
$class = '';
$form = '';
$dob = '';
$photo = '';
$allergy = '';
$em = ['name' => '', 'phone' => ''];
$ay = '';
if ($s) {
    $name = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
    $class = trim((string) ($s['class_name'] ?? ''));
    $form = trim((string) ($s['form_no'] ?? ''));
    $dob = substr((string) ($s['dob'] ?? ''), 0, 10);
    if ($dob === '0000-00-00') {
        $dob = '';
    }
    $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
    $allergy = trim((string) ($s['allergies'] ?? ''));
    if (strcasecmp($allergy, 'none') === 0) {
        $allergy = '';
    }
    $em = student_idcard_emergency($s);
    $ay = trim((string) ($s['academic_year'] ?? ''));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $s ? e($name) . ' — ID' : 'Student ID'; ?></title>
  <style>
    body { margin:0; font-family:"Segoe UI", Arial, sans-serif; background:#eff6ff; color:#0f2744; }
    .wrap { max-width:420px; margin:0 auto; padding:18px 14px 32px; }
    .card { background:#fff; border-radius:18px; padding:18px; box-shadow:0 10px 30px rgba(20,58,122,.12); }
    .brand { display:flex; gap:10px; align-items:center; margin-bottom:14px; }
    .brand img { width:44px; height:44px; object-fit:contain; }
    .photo { width:100%; max-height:220px; object-fit:cover; border-radius:14px; background:#e2e8f0; }
    h1 { font-size:1.35rem; margin:12px 0 4px; }
    .muted { color:#64748b; }
    .row { display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px dashed #e2e8f0; font-size:.95rem; }
    .alert { background:#fff7ed; color:#9a3412; border-radius:12px; padding:10px 12px; margin-top:10px; }
    .err { background:#fff; border-radius:18px; padding:24px; text-align:center; }
  </style>
</head>
<body>
<div class="wrap">
<?php if (!$s): ?>
  <div class="err">
    <div class="fw-bold" style="font-weight:800;font-size:1.1rem">ID card not found</div>
    <p class="muted">This QR is not valid. Ask the school office.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="brand">
      <?php if ($logo !== ''): ?><img src="<?php echo e($logo); ?>" alt=""><?php endif; ?>
      <div>
        <div style="font-weight:800"><?php echo e($schoolName); ?></div>
        <div class="muted" style="font-size:.8rem">STUDENT ID</div>
      </div>
    </div>
    <?php if ($photo !== ''): ?><img class="photo" src="<?php echo e($photo); ?>" alt=""><?php endif; ?>
    <h1><?php echo e($name !== '' ? $name : ('Student #' . $id)); ?></h1>
    <div class="muted"><?php echo e($class !== '' ? $class : 'Class not set'); ?><?php echo $ay !== '' ? ' · ' . e($ay) : ''; ?></div>
    <div class="row"><span class="muted">Form / ID</span><span><?php echo e($form !== '' ? $form : ('#' . $id)); ?></span></div>
    <?php if ($dob !== ''): ?><div class="row"><span class="muted">Date of birth</span><span><?php echo e(date('d M Y', strtotime($dob) ?: time())); ?></span></div><?php endif; ?>
    <?php if ($em['name'] !== ''): ?><div class="row"><span class="muted">Parent</span><span><?php echo e($em['name']); ?></span></div><?php endif; ?>
    <?php if ($em['phone'] !== ''): ?><div class="row"><span class="muted">Emergency call</span><span><?php echo e($em['phone']); ?></span></div><?php endif; ?>
    <?php if ($allergy !== ''): ?><div class="alert">Allergy: <?php echo e($allergy); ?></div><?php endif; ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>
