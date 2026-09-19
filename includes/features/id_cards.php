<?php
/**
 * Staff ID-card print: CR80 cards for one child or a whole class.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
require_once dirname(__DIR__) . '/student_idcard.php';

$classes = function_exists('panel_classes_all') ? panel_classes_all() : [];
$classId = (int) ($_GET['class_id'] ?? 0);
$oneId = (int) ($_GET['id'] ?? 0);
$doPrint = (string) ($_GET['print'] ?? '') === '1';
$doPdf = (string) ($_GET['pdf'] ?? '') === '1';
$allClasses = (string) ($_GET['all'] ?? '') === '1';

if ($oneId > 0 && $classId <= 0) {
    $one = student_idcard_load($oneId);
    if ($one) {
        $classId = (int) ($one['class_id'] ?? 0);
    }
}

if ($classId <= 0 && $classes !== []) {
    $classId = (int) ($classes[0]['id'] ?? 0);
}

$listUrl = $panel === 'reception'
    ? (function_exists('site_url') ? site_url('/reception/id_cards.php') : 'id_cards.php')
    : (function_exists('site_url') ? site_url('/owner/id_cards.php') : 'id_cards.php');

if ($doPrint || $doPdf) {
    $pack = [];
    $fileHint = 'id-cards';
    if ($oneId > 0) {
        $row = student_idcard_load($oneId);
        if ($row) {
            $pack[] = $row;
            $nm = function_exists('student_full_name') ? student_full_name($row) : ('student-' . $oneId);
            $fileHint = strtolower(trim(preg_replace('/\s+/', '-', $nm) ?: ('student-' . $oneId)));
        }
    } elseif ($allClasses) {
        $pack = student_idcard_list_all_classes();
        $fileHint = 'id-cards-all';
    } elseif ($classId > 0) {
        $pack = student_idcard_list_for_class($classId);
        $cname = '';
        foreach ($classes as $c) {
            if ((int) ($c['id'] ?? 0) === $classId) {
                $cname = (string) ($c['name'] ?? '');
                break;
            }
        }
        $fileHint = 'id-cards-' . strtolower(trim(preg_replace('/\s+/', '-', $cname) ?: ('class-' . $classId)));
    }
    if ($pack === []) {
        require_once dirname(__DIR__) . '/header.php';
        echo '<div class="alert alert-info">No student found. <a href="' . e($listUrl) . '">Back</a></div>';
        require_once dirname(__DIR__) . '/footer.php';
        exit;
    }
    $back = $listUrl . '?class_id=' . $classId . ($oneId > 0 ? ('&id=' . $oneId) : '');
    if ($doPdf) {
        student_idcard_pdf_download($pack, $fileHint . '.pdf');
        require_once dirname(__DIR__) . '/header.php';
        echo '<div class="alert alert-warning">Could not make the PDF (need GD). Use Print instead. <a href="' . e($back) . '">Back</a></div>';
        require_once dirname(__DIR__) . '/footer.php';
        exit;
    }
    student_idcard_print_document($pack, $back);
    exit;
}

$students = $classId > 0 ? student_idcard_list_for_class($classId) : [];
$qs = static function (int $cid, int $sid = 0) use ($listUrl): string {
    $u = $listUrl . '?class_id=' . $cid;
    if ($sid > 0) {
        $u .= '&id=' . $sid;
    }
    return $u;
};

$page_title = 'ID cards';
$pageTitle = $page_title;
require_once dirname(__DIR__) . '/header.php';
?>
<style>
.idc-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .4rem .5rem 0; }
.idc-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.idc-mini { display:flex; gap:10px; align-items:center; background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:10px 12px; margin-bottom:8px; }
.idc-mini img { width:40px; height:40px; border-radius:10px; object-fit:cover; background:#e2e8f0; }
</style>

<p class="text-muted mb-2">PVC / CR80 size (credit-card). Print one child or a whole class. Parents also see this card after admission.</p>

<?php if ($classes === []): ?>
  <div class="alert alert-info mb-0">Add classes first, then print ID cards.</div>
<?php else: ?>
  <div class="mb-3">
    <?php foreach ($classes as $c):
        $cid = (int) ($c['id'] ?? 0);
        ?>
      <a class="idc-chip<?php echo $cid === $classId ? ' active' : ''; ?>" href="<?php echo e($qs($cid)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <div class="mb-3 d-flex flex-wrap gap-2">
    <a class="btn btn-outline-primary" href="<?php echo e($listUrl); ?>?pdf=1&amp;all=1">Download all classes PDF</a>
  </div>

  <?php if ($students === []): ?>
    <div class="alert alert-info mb-0">No students in this class this year.</div>
  <?php else: ?>
    <div class="mb-3 d-flex flex-wrap gap-2">
      <a class="btn btn-primary" href="<?php echo e($qs($classId)); ?>&amp;pdf=1">Download class PDF (<?php echo count($students); ?>)</a>
      <a class="btn btn-outline-success" href="<?php echo e($qs($classId)); ?>&amp;print=1" target="_blank">Print class</a>
    </div>
    <p class="small text-muted mb-2">One PDF, one CR80 card per page — send the file to the PVC printer.</p>
    <?php foreach ($students as $s):
        $sid = (int) ($s['id'] ?? 0);
        $nm = function_exists('student_full_name') ? student_full_name($s) : trim((string) ($s['first_name'] ?? ''));
        $ph = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : '';
        ?>
      <div class="idc-mini">
        <?php if ($ph !== ''): ?><img src="<?php echo e($ph); ?>" alt=""><?php else: ?><div style="width:40px;height:40px;border-radius:10px;background:#e2e8f0"></div><?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-bold"><?php echo e($nm); ?></div>
          <div class="small text-muted"><?php echo e((string) ($s['form_no'] ?? '')); ?></div>
        </div>
        <a class="btn btn-sm btn-primary" href="<?php echo e($qs($classId, $sid)); ?>&amp;pdf=1">PDF</a>
        <a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($classId, $sid)); ?>&amp;print=1" target="_blank">Print</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/footer.php'; ?>
