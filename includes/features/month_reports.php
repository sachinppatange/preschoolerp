<?php
/**
 * View month report, download visit QR, send WhatsApp / email.
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
$viewId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$month = substr((string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m')), 0, 7);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
if ($classId <= 0 && $classes !== []) {
    $classId = (int) ($classes[0]['id'] ?? 0);
}

$messages = [];
$errors = [];

$listQs = static function (int $cid, string $ym, int $sid = 0) use ($self): string {
    $u = $self . '?class_id=' . $cid . '&month=' . rawurlencode($ym);
    if ($sid > 0) {
        $u .= '&student_id=' . $sid;
    }
    return $u;
};

if ((string) ($_GET['download_qr'] ?? '') === '1') {
    $sid = (int) ($_GET['student_id'] ?? 0);
    $stu = $sid > 0 ? student_idcard_load($sid) : null;
    if (!$stu) {
        $errors[] = 'Student not found.';
    } else {
        $nm = function_exists('student_full_name') ? student_full_name($stu) : ('student-' . $sid);
        $file = strtolower(trim(preg_replace('/\s+/', '-', $nm) ?: ('student-' . $sid))) . '-qr.png';
        if (student_idcard_qr_download($sid, $file)) {
            exit;
        }
        $errors[] = 'Could not download QR. Try Print QR instead.';
    }
}

if ((string) ($_GET['print_qr'] ?? '') === '1') {
    $pack = [];
    $one = (int) ($_GET['student_id'] ?? 0);
    if ($one > 0) {
        $row = student_idcard_load($one);
        if ($row) {
            $pack[] = $row;
        }
    } elseif ($classId > 0) {
        $pack = student_idcard_list_for_class($classId);
    }
    $back = $listQs($classId, $month, $one);
    if ($pack === []) {
        $errors[] = 'No QR to print.';
    } else {
        student_idcard_qr_sheet($pack, $back, 'Visit QR');
        exit;
    }
}

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
            $errors[] = $failN . ' could not send' . ($lastErr !== '' ? (': ' . $lastErr) : '.') . ' Check parent phone/email.';
        }
    }
}

$students = $classId > 0 ? student_idcard_list_for_class($classId) : [];
[, , $monthLabel] = student_report_month_bounds($month);

$viewRep = null;
if ($viewId > 0) {
    $viewRep = student_report_build($viewId, $month);
    if ($viewRep && $classId <= 0) {
        $classId = (int) (($viewRep['student']['class_id'] ?? 0));
    }
}

$inr = static function (float $n): string {
    return function_exists('format_money') ? format_money($n) : ('₹ ' . number_format($n, 0));
};

require_once dirname(__DIR__) . '/header.php';
?>
<style>
.mr-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .4rem .5rem 0; }
.mr-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.mr-row { display:flex; gap:10px; align-items:center; background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:10px 12px; margin-bottom:8px; flex-wrap:wrap; }
.mr-row img.ph { width:40px; height:40px; border-radius:10px; object-fit:cover; background:#e2e8f0; }
.mr-view { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:16px; margin-bottom:16px; }
.mr-view .stats { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0; }
.mr-view .stat { flex:1; min-width:72px; text-align:center; background:#f8fafc; border-radius:12px; padding:8px 6px; }
.mr-view .stat b { display:block; font-size:1.2rem; }
.mr-qr { width:160px; height:160px; }
.mr-note { white-space:pre-wrap; background:#f8fafc; border-radius:12px; padding:12px; font-size:.92rem; }
</style>

<p class="text-muted mb-2">Pick a class. View the month note, download the visit QR, or send WhatsApp / email to parents.</p>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($classes === []): ?>
  <div class="alert alert-info mb-0">Add classes first.</div>
<?php else: ?>
  <form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
    <?php if ($viewId > 0): ?><input type="hidden" name="student_id" value="<?php echo (int) $viewId; ?>"><?php endif; ?>
    <div>
      <label class="form-label mb-1">Month</label>
      <input type="month" name="month" class="form-control" value="<?php echo e($month); ?>" onchange="this.form.submit()">
    </div>
  </form>
  <div class="mb-3">
    <?php foreach ($classes as $c):
        $cid = (int) ($c['id'] ?? 0);
        ?>
      <a class="mr-chip<?php echo $cid === $classId ? ' active' : ''; ?>" href="<?php echo e($listQs($cid, $month)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($viewRep):
      $att = is_array($viewRep['attendance'] ?? null) ? $viewRep['attendance'] : [];
      $fees = is_array($viewRep['fees'] ?? null) ? $viewRep['fees'] : [];
      $due = (float) ($fees['remaining'] ?? 0);
      $scan = student_idcard_scan_url((int) $viewRep['id']);
      $qrImg = student_idcard_qr_src($scan, 320);
      $stu = is_array($viewRep['student'] ?? null) ? $viewRep['student'] : [];
      $phone = student_report_phone10($stu);
      $email = student_report_email($stu);
      $dl = $listQs($classId, $month, (int) $viewRep['id']) . '&download_qr=1';
      $pq = $listQs($classId, $month, (int) $viewRep['id']) . '&print_qr=1';
      ?>
    <div class="mr-view">
      <div class="d-flex flex-wrap gap-3">
        <div class="flex-grow-1">
          <a class="small" href="<?php echo e($listQs($classId, $month)); ?>">← Class list</a>
          <h4 class="mb-1 mt-1"><?php echo e((string) $viewRep['name']); ?></h4>
          <div class="text-muted"><?php echo e(trim((string) $viewRep['class'] . ' · ' . (string) $viewRep['month_label'], ' ·')); ?></div>
          <div class="stats">
            <div class="stat"><b><?php echo (int) ($att['present'] ?? 0); ?></b><span class="small text-muted">Present</span></div>
            <div class="stat"><b><?php echo (int) ($att['absent'] ?? 0); ?></b><span class="small text-muted">Absent</span></div>
            <div class="stat"><b><?php echo (int) ($att['leave'] ?? 0); ?></b><span class="small text-muted">Leave</span></div>
            <div class="stat"><b><?php echo $due < 0.5 ? 'Paid' : e($inr($due)); ?></b><span class="small text-muted">Fees due</span></div>
          </div>
          <?php if (trim((string) ($viewRep['homework'] ?? '')) !== ''): ?>
            <div class="small mb-1"><strong>Homework:</strong> <?php echo e((string) $viewRep['homework']); ?></div>
          <?php endif; ?>
          <?php if (trim((string) ($viewRep['remark'] ?? '')) !== ''): ?>
            <div class="small mb-2"><strong>Teacher:</strong> <?php echo e((string) $viewRep['remark']); ?></div>
          <?php endif; ?>
          <div class="mr-note mb-2"><?php echo e(student_report_text($viewRep)); ?></div>
          <form method="post" class="d-flex flex-wrap gap-2">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
            <input type="hidden" name="month" value="<?php echo e($month); ?>">
            <input type="hidden" name="student_id" value="<?php echo (int) $viewRep['id']; ?>">
            <button class="btn btn-success" name="channel" value="whatsapp" type="submit" <?php echo $phone === '' ? 'disabled' : ''; ?>>WhatsApp parent</button>
            <button class="btn btn-outline-primary" name="channel" value="email" type="submit" <?php echo $email === '' ? 'disabled' : ''; ?>>Email parent</button>
          </form>
        </div>
        <div class="text-center">
          <img class="mr-qr" src="<?php echo e($qrImg); ?>" alt="Visit QR">
          <div class="small text-muted mb-2">Scan = visit page</div>
          <a class="btn btn-primary btn-sm d-block mb-1" href="<?php echo e($dl); ?>">Download QR</a>
          <a class="btn btn-outline-secondary btn-sm d-block" href="<?php echo e($pq); ?>">Print QR</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($students === []): ?>
    <div class="alert alert-info mb-0">No students in this class this year.</div>
  <?php else: ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <a class="btn btn-outline-secondary" href="<?php echo e($listQs($classId, $month) . '&print_qr=1'); ?>">Print class QRs (<?php echo count($students); ?>)</a>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
        <input type="hidden" name="month" value="<?php echo e($month); ?>">
        <button class="btn btn-success" name="channel" value="whatsapp" type="submit">WhatsApp class</button>
        <button class="btn btn-outline-primary" name="channel" value="email" type="submit">Email class</button>
      </form>
    </div>
    <div class="small text-muted mb-2"><?php echo e($monthLabel); ?> · tap View to read the note before sending.</div>
    <?php foreach ($students as $s):
        $sid = (int) ($s['id'] ?? 0);
        $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? ''));
        $ph = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
        $phone = student_report_phone10($s);
        $email = student_report_email($s);
        $viewHref = $listQs($classId, $month, $sid);
        $dlHref = $viewHref . '&download_qr=1';
        ?>
      <div class="mr-row<?php echo $sid === $viewId ? ' border-primary' : ''; ?>">
        <?php if ($ph !== ''): ?><img class="ph" src="<?php echo e($ph); ?>" alt=""><?php else: ?><div class="ph" style="width:40px;height:40px;border-radius:10px;background:#e2e8f0"></div><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-bold"><?php echo e($nm); ?></div>
          <div class="small text-muted"><?php echo $phone !== '' ? e($phone) : 'No WhatsApp'; ?><?php echo $email !== '' ? ' · ' . e($email) : ''; ?></div>
        </div>
        <a class="btn btn-sm btn-primary" href="<?php echo e($viewHref); ?>">View</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($dlHref); ?>">QR</a>
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
