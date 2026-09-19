<?php
/**
 * Public / visit scan page for a student ID-card QR — one place for parent meeting.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/panel/bootstrap.php';
panel_bootstrap(null, ['skip_auth' => true]);
require_once __DIR__ . '/includes/student_idcard.php';
require_once __DIR__ . '/includes/student_report.php';

$id = (int) ($_GET['s'] ?? $_GET['id'] ?? 0);
$token = trim((string) ($_GET['t'] ?? ''));
$ok = student_idcard_check($id, $token);
$r = $ok ? student_report_build($id, date('Y-m')) : null;
$school = student_idcard_school();
$schoolName = $r['school'] ?? (trim((string) ($school['name'] ?? '')) ?: (defined('APP_NAME') ? APP_NAME : 'School'));
$logo = '';
if (!empty($school['logo_path']) && function_exists('resolve_image_url')) {
    $logo = (string) resolve_image_url((string) $school['logo_path'], '');
}

$role = function_exists('auth_role') ? (string) (auth_role() ?? '') : '';
$loggedParent = $role === 'parent' && function_exists('panel_parent_context_id') && panel_parent_context_id() > 0;
$staff = function_exists('student_report_can_view_full') && student_report_can_view_full();
$loginUrl = function_exists('site_url') ? site_url('/login.php') : '/login.php';
$parentHome = function_exists('site_url') ? site_url('/parent/children.php') : '/parent/children.php';
$ownerView = function_exists('site_url') ? site_url('/owner/students_view.php?id=' . $id) : '/owner/students_view.php?id=' . $id;

$inr = static function (float $n): string {
    return function_exists('format_money') ? format_money($n) : ('₹ ' . number_format($n, 0));
};

$todayTxt = '';
$att = is_array($r['attendance'] ?? null) ? $r['attendance'] : [];
if (($att['today'] ?? '') === 'present') {
    $todayTxt = 'In school today';
} elseif (($att['today'] ?? '') === 'absent') {
    $todayTxt = 'Absent today';
} elseif (($att['today'] ?? '') === 'leave') {
    $todayTxt = 'Leave today';
}

$dob = substr((string) ($r['dob'] ?? ''), 0, 10);
if ($dob === '0000-00-00') {
    $dob = '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $r ? e((string) $r['name']) . ' — visit' : 'Student'; ?></title>
  <style>
    body { margin:0; font-family:"Segoe UI", Arial, sans-serif; background:#eff6ff; color:#0f2744; }
    .wrap { max-width:440px; margin:0 auto; padding:16px 14px 36px; }
    .card { background:#fff; border-radius:18px; padding:16px 18px; margin-bottom:12px; box-shadow:0 8px 24px rgba(20,58,122,.1); }
    .brand { display:flex; gap:10px; align-items:center; }
    .brand img { width:40px; height:40px; object-fit:contain; }
    .photo { width:88px; height:88px; object-fit:cover; border-radius:16px; background:#e2e8f0; float:right; margin:0 0 8px 10px; }
    h1 { font-size:1.25rem; margin:10px 0 4px; }
    .muted { color:#64748b; }
    .pill { display:inline-block; border-radius:999px; padding:.2rem .65rem; font-size:.78rem; font-weight:700; margin-top:.35rem; }
    .pill.in { background:#dcfce7; color:#166534; }
    .pill.out { background:#fee2e2; color:#991b1b; }
    .pill.leave { background:#ffedd5; color:#9a3412; }
    .stats { display:flex; gap:8px; margin-top:10px; }
    .stat { flex:1; text-align:center; background:#f8fafc; border-radius:12px; padding:8px 4px; }
    .stat b { display:block; font-size:1.15rem; }
    .sec { font-size:.72rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:12px 0 6px; }
    .row { display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px dashed #e2e8f0; font-size:.93rem; }
    .alert { background:#fff7ed; color:#9a3412; border-radius:12px; padding:10px 12px; }
    .err { background:#fff; border-radius:18px; padding:24px; text-align:center; }
    .btn { display:inline-block; margin-top:10px; background:#1d4ed8; color:#fff; text-decoration:none; border-radius:10px; padding:.55rem 1rem; font-weight:700; }
    .due { color:#c2410c; font-weight:800; }
    .ok { color:#166534; font-weight:800; }
  </style>
</head>
<body>
<div class="wrap">
<?php if (!$r): ?>
  <div class="err">
    <div style="font-weight:800;font-size:1.1rem">ID card not found</div>
    <p class="muted">This QR is not valid. Ask the school office.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="brand">
      <?php if ($logo !== ''): ?><img src="<?php echo e($logo); ?>" alt=""><?php endif; ?>
      <div>
        <div style="font-weight:800"><?php echo e((string) $schoolName); ?></div>
        <div class="muted" style="font-size:.8rem">PARENT VISIT · <?php echo e((string) $r['month_label']); ?></div>
      </div>
    </div>
    <?php if ((string) ($r['photo'] ?? '') !== ''): ?>
      <img class="photo" src="<?php echo e((string) $r['photo']); ?>" alt="">
    <?php endif; ?>
    <h1><?php echo e((string) $r['name']); ?></h1>
    <div class="muted">
      <?php echo e((string) ($r['class'] !== '' ? $r['class'] : 'Class not set')); ?>
      <?php echo (string) $r['form'] !== '' ? ' · ' . e((string) $r['form']) : ''; ?>
    </div>
    <?php if ($todayTxt !== ''): ?>
      <span class="pill <?php echo ($att['today'] ?? '') === 'present' ? 'in' : (($att['today'] ?? '') === 'absent' ? 'out' : 'leave'); ?>"><?php echo e($todayTxt); ?></span>
    <?php endif; ?>
    <div style="clear:both"></div>
    <div class="stats">
      <div class="stat"><b class="ok"><?php echo (int) $att['present']; ?></b><span class="muted">Present</span></div>
      <div class="stat"><b style="color:#b91c1c"><?php echo (int) $att['absent']; ?></b><span class="muted">Absent</span></div>
      <div class="stat"><b style="color:#c2410c"><?php echo (int) $att['leave']; ?></b><span class="muted">Leave</span></div>
    </div>
  </div>

  <div class="card">
    <div class="sec">Fees</div>
    <?php $due = (float) ($r['fees']['remaining'] ?? 0); ?>
    <div class="row"><span class="muted">Year fees</span><span><?php echo e($inr((float) ($r['fees']['total'] ?? 0))); ?></span></div>
    <div class="row"><span class="muted">Paid</span><span><?php echo e($inr((float) ($r['fees']['paid'] ?? 0))); ?></span></div>
    <div class="row"><span class="muted">Still due</span><span class="<?php echo $due < 0.5 ? 'ok' : 'due'; ?>"><?php echo $due < 0.5 ? 'Paid' : e($inr($due)); ?></span></div>
    <?php if ((float) ($r['paid_month'] ?? 0) > 0): ?>
      <div class="row"><span class="muted">Paid this month</span><span><?php echo e($inr((float) $r['paid_month'])); ?></span></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="sec">This month at school</div>
    <?php if ((string) ($r['homework'] ?? '') !== ''): ?>
      <div class="row"><span class="muted">Homework</span><span><?php echo e((string) $r['homework']); ?></span></div>
    <?php endif; ?>
    <?php if ((string) ($r['remark'] ?? '') !== ''): ?>
      <div class="row"><span class="muted">Teacher</span><span><?php echo e((string) $r['remark']); ?></span></div>
    <?php endif; ?>
    <?php if ($dob !== ''): ?>
      <div class="row"><span class="muted">Birthday</span><span><?php echo e(date('d M Y', strtotime($dob) ?: time())); ?></span></div>
    <?php endif; ?>
    <?php if ((string) ($r['emergency']['name'] ?? '') !== ''): ?>
      <div class="row"><span class="muted">Parent</span><span><?php echo e((string) $r['emergency']['name']); ?></span></div>
    <?php endif; ?>
    <?php if ((string) ($r['emergency']['phone'] ?? '') !== ''): ?>
      <div class="row"><span class="muted">Call</span><span><?php echo e((string) $r['emergency']['phone']); ?></span></div>
    <?php endif; ?>
    <?php if ((string) ($r['homework'] ?? '') === '' && (string) ($r['remark'] ?? '') === ''): ?>
      <div class="muted">No homework or teacher note this month yet.</div>
    <?php endif; ?>
    <?php if ((string) ($r['allergy'] ?? '') !== ''): ?>
      <div class="alert" style="margin-top:10px">Allergy: <?php echo e((string) $r['allergy']); ?></div>
    <?php endif; ?>
  </div>

  <?php if ($staff): ?>
    <a class="btn" href="<?php echo e($ownerView); ?>">Open full student file</a>
  <?php elseif ($loggedParent): ?>
    <a class="btn" href="<?php echo e($parentHome); ?>">Open parent app</a>
  <?php else: ?>
    <a class="btn" href="<?php echo e($loginUrl); ?>">Parent / staff login</a>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
