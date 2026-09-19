<?php
/**
 * Send this month's child report to parents on WhatsApp or email.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
require_once dirname(__DIR__) . '/student_idcard.php';
require_once dirname(__DIR__) . '/student_report.php';

$page_title = 'Month reports';
$pageTitle = $page_title;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$self = function_exists('site_url')
    ? site_url($panel === 'reception' ? '/reception/month_reports.php' : '/owner/month_reports.php')
    : 'month_reports.php';

$classes = function_exists('panel_classes_all') ? panel_classes_all() : [];
$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$month = substr((string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m')), 0, 7);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
if ($classId <= 0 && $classes !== []) {
    $classId = (int) ($classes[0]['id'] ?? 0);
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } else {
        $channel = (string) ($_POST['channel'] ?? 'whatsapp');
        if (!in_array($channel, ['whatsapp', 'email'], true)) {
            $channel = 'whatsapp';
        }
        $oneId = (int) ($_POST['student_id'] ?? 0);
        $ids = [];
        if ($oneId > 0) {
            $ids = [$oneId];
        } else {
            foreach (student_idcard_list_for_class($classId) as $row) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
        }
        $okN = 0;
        $failN = 0;
        $lastErr = '';
        foreach ($ids as $sid) {
            if ($sid <= 0) {
                continue;
            }
            $rep = student_report_build($sid, $month);
            if (!$rep) {
                $failN++;
                continue;
            }
            $text = student_report_text($rep);
            $stu = is_array($rep['student'] ?? null) ? $rep['student'] : [];
            if ($channel === 'email') {
                $to = student_report_email($stu);
                $sub = ($rep['school'] ?? 'School') . ' — ' . ($rep['month_label'] ?? 'Month') . ' report';
                $res = student_report_send_email($to, $sub, $text);
            } else {
                $res = student_report_send_whatsapp(student_report_phone10($stu), $text);
            }
            if (!empty($res['ok'])) {
                $okN++;
            } else {
                $failN++;
                $lastErr = (string) ($res['error'] ?? '');
            }
        }
        if ($okN > 0) {
            $messages[] = $okN === 1 ? 'Report sent.' : ($okN . ' reports sent.');
        }
        if ($failN > 0) {
            $errors[] = $failN . ' could not send' . ($lastErr !== '' ? (': ' . $lastErr) : '.') . ' Check parent phone/email, or WhatsApp / email settings.';
        }
    }
}

$students = $classId > 0 ? student_idcard_list_for_class($classId) : [];
[, , $monthLabel] = student_report_month_bounds($month);

require_once dirname(__DIR__) . '/header.php';
?>
<style>
.mr-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .4rem .5rem 0; }
.mr-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.mr-row { display:flex; gap:10px; align-items:center; background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:10px 12px; margin-bottom:8px; flex-wrap:wrap; }
.mr-row img { width:40px; height:40px; border-radius:10px; object-fit:cover; background:#e2e8f0; }
</style>

<p class="text-muted mb-2">Send this month’s attendance, fees and a short school note to parents on WhatsApp or email. Same facts as the ID-card QR visit page.</p>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($classes === []): ?>
  <div class="alert alert-info mb-0">Add classes first.</div>
<?php else: ?>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
    <div>
      <label class="form-label mb-1">Month</label>
      <input type="month" name="month" class="form-control" value="<?php echo e($month); ?>" onchange="this.form.submit()">
    </div>
  </form>
  <div class="mb-3">
    <?php foreach ($classes as $c):
        $cid = (int) ($c['id'] ?? 0);
        ?>
      <a class="mr-chip<?php echo $cid === $classId ? ' active' : ''; ?>" href="<?php echo e($self . '?class_id=' . $cid . '&month=' . rawurlencode($month)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($students === []): ?>
    <div class="alert alert-info mb-0">No students in this class this year.</div>
  <?php else: ?>
    <form method="post" class="mb-3 d-flex flex-wrap gap-2">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
      <input type="hidden" name="month" value="<?php echo e($month); ?>">
      <button class="btn btn-success" name="channel" value="whatsapp" type="submit">WhatsApp whole class (<?php echo count($students); ?>)</button>
      <button class="btn btn-outline-primary" name="channel" value="email" type="submit">Email whole class</button>
    </form>
    <div class="small text-muted mb-2"><?php echo e($monthLabel); ?> · each parent gets attendance, fees due, latest homework and teacher note.</div>
    <?php foreach ($students as $s):
        $sid = (int) ($s['id'] ?? 0);
        $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? ''));
        $ph = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
        $phone = student_report_phone10($s);
        $email = student_report_email($s);
        ?>
      <div class="mr-row">
        <?php if ($ph !== ''): ?><img src="<?php echo e($ph); ?>" alt=""><?php else: ?><div style="width:40px;height:40px;border-radius:10px;background:#e2e8f0"></div><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-bold"><?php echo e($nm); ?></div>
          <div class="small text-muted"><?php echo $phone !== '' ? e($phone) : 'No WhatsApp'; ?><?php echo $email !== '' ? ' · ' . e($email) : ''; ?></div>
        </div>
        <form method="post" class="d-flex gap-1">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
          <input type="hidden" name="month" value="<?php echo e($month); ?>">
          <input type="hidden" name="student_id" value="<?php echo $sid; ?>">
          <button class="btn btn-sm btn-success" name="channel" value="whatsapp" type="submit" <?php echo $phone === '' ? 'disabled' : ''; ?>>WhatsApp</button>
          <button class="btn btn-sm btn-outline-primary" name="channel" value="email" type="submit" <?php echo $email === '' ? 'disabled' : ''; ?>>Email</button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/footer.php'; ?>
