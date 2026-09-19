<?php
/**
 * parent/id_card.php — child's school ID card (CR80), ready after admission.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
require_once __DIR__ . '/../includes/student_idcard.php';

$parentId = panel_parent_context_id();
$childIds = [];
if ($parentId > 0 && table_exists('parents_children')) {
    $maps = safe_db_get_all(
        'SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC',
        [':pid' => $parentId]
    ) ?: [];
    foreach ($maps as $m) {
        $id = (int) ($m['child_student_id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
}
if ($childIds === [] && $parentId > 0 && table_exists('students')) {
    $or = ['parent_id = :pid'];
    $params = [':pid' => $parentId];
    if (function_exists('column_exists') && column_exists('students', 'father_id')) {
        $or[] = 'father_id = :pid';
    }
    if (function_exists('column_exists') && column_exists('students', 'mother_id')) {
        $or[] = 'mother_id = :pid';
    }
    $rows = safe_db_get_all('SELECT id FROM students WHERE ' . implode(' OR ', $or), $params) ?: [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $childIds[] = $id;
        }
    }
}
$childIds = array_values(array_unique($childIds));

$selectedId = (int) ($_GET['student_id'] ?? 0);
if ($selectedId <= 0 && $childIds !== []) {
    $selectedId = $childIds[0];
}
if ($selectedId > 0 && !in_array($selectedId, $childIds, true)) {
    $selectedId = $childIds[0] ?? 0;
}

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

if ((string) ($_GET['print'] ?? '') === '1' && $selectedId > 0) {
    $row = student_idcard_load($selectedId);
    if ($row) {
        student_idcard_print_document([$row], $u('/parent/id_card.php?student_id=' . $selectedId));
        exit;
    }
}

$children = [];
foreach ($childIds as $id) {
    $row = student_idcard_load($id);
    if ($row) {
        $children[] = $row;
    }
}

$nameOf = static function (array $s): string {
    if (function_exists('student_full_name')) {
        $n = trim(student_full_name($s));
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
};

$selected = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $selectedId) {
        $selected = $c;
        break;
    }
}

$page_title = 'ID card';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.idc-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.idc-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.idc-preview { display:flex; justify-content:center; margin:12px 0 16px; }
<?php echo student_idcard_css(); ?>
body:not(.idc-print) .idc { box-shadow: 0 10px 28px rgba(20,58,122,.16); }
</style>

<p class="text-muted mb-2">School ID card. Save or print it — the office can also print it on a PVC card.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked yet. After admission this card appears here.</div>
<?php else: ?>
  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          ?>
        <a class="idc-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="?student_id=<?php echo $cid; ?>"><?php echo e($nameOf($ch)); ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($selected): ?>
    <div class="idc-preview"><?php echo student_idcard_markup($selected); ?></div>
    <div class="text-center d-flex justify-content-center gap-2 flex-wrap">
      <a class="btn btn-success" href="?student_id=<?php echo (int) $selectedId; ?>&amp;print=1" target="_blank">Download / Print</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
