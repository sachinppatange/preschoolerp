<?php
/**
 * parent/homeworks.php — what to do at home for this child.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$parentId = panel_parent_context_id();
$today = date('Y-m-d');
$tableOk = table_exists('homeworks');

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

$children = [];
if ($childIds !== [] && table_exists('students')) {
    $in = implode(',', array_map('intval', $childIds));
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
    $classSel = table_exists('classes') ? ', c.name AS class_name' : '';
    $rows = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id, s.photo_path{$classSel}
         FROM students s {$join}
         WHERE s.id IN ({$in})"
    ) ?: [];
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }
    foreach ($childIds as $id) {
        if (isset($byId[$id])) {
            $children[] = $byId[$id];
        }
    }
}

$selectedId = (int) ($_GET['student_id'] ?? 0);
if ($selectedId <= 0 && $childIds !== []) {
    $selectedId = $childIds[0];
}
if ($selectedId > 0 && !in_array($selectedId, $childIds, true)) {
    $selectedId = $childIds[0] ?? 0;
}

$selected = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $selectedId) {
        $selected = $c;
        break;
    }
}

$nameOf = static function (array $s): string {
    if (function_exists('student_full_name')) {
        $n = trim(student_full_name($s));
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['middle_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
};

$fmtDay = static function (string $raw): string {
    $d = substr($raw, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $ts = strtotime($d);
    if ($ts === false) {
        return $d;
    }
    if ($d === date('Y-m-d')) {
        return 'Today';
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    if ($d === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow';
    }
    return date('D, d M', $ts);
};

$classId = $selected ? (int) ($selected['class_id'] ?? 0) : 0;
$className = $selected ? trim((string) ($selected['class_name'] ?? '')) : '';
$childName = $selected ? $nameOf($selected) : 'Your child';
$photo = $selected && function_exists('student_photo_url')
    ? student_photo_url((string) ($selected['photo_path'] ?? ''))
    : '';

$ayFrom = $ayTo = $today;
if (function_exists('ay_limit_dates')) {
    [$ayFrom, $ayTo] = ay_limit_dates('2000-01-01', '2099-12-31');
}

$homeworks = [];
if ($tableOk && $classId > 0) {
    $sql = 'SELECT id, class_id, title, description, assigned_date, due_date
            FROM homeworks
            WHERE class_id = :cid
              AND assigned_date BETWEEN :a AND :b
            ORDER BY assigned_date DESC, id DESC
            LIMIT 40';
    $homeworks = safe_db_get_all($sql, [':cid' => $classId, ':a' => $ayFrom, ':b' => $ayTo]) ?: [];
}

$todayHw = [];
$weekHw = [];
$earlierHw = [];
$weekStart = date('Y-m-d', strtotime('monday this week') ?: time());
foreach ($homeworks as $hw) {
    $assigned = substr((string) ($hw['assigned_date'] ?? ''), 0, 10);
    if ($assigned === $today) {
        $todayHw[] = $hw;
    } elseif ($assigned >= $weekStart) {
        $weekHw[] = $hw;
    } else {
        $earlierHw[] = $hw;
    }
}

$heroText = 'No homework today';
$heroClass = 'none';
if ($todayHw !== []) {
    $n = count($todayHw);
    $heroText = $n === 1 ? '1 homework today' : ($n . ' homeworks today');
    $heroClass = 'has';
}

$page_title = 'Homework';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.hw-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.hw-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.hw-chip img, .hw-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.hw-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.hw-hero.has { border-color:#86efac; background:#f0fdf4; }
.hw-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.hw-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.hw-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:14px 16px; margin-bottom:10px; }
.hw-card.today { border-color:#86efac; background:#f0fdf4; }
.hw-title { font-weight:800; color:#1e3a5f; }
.hw-meta { font-size:.88rem; color:#64748b; }
.hw-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:14px 0 8px; }
.hw-due { display:inline-block; background:#ffedd5; color:#9a3412; border-radius:999px; padding:.12rem .55rem; font-size:.75rem; font-weight:700; }
</style>

<p class="text-muted mb-2">What the teacher asked your child to do at home.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Homework is not set up yet.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="hw-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="?student_id=<?php echo $cid; ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="hw-hero <?php echo e($heroClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="hw-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="hw-photo"></div>
    <?php endif; ?>
    <div>
      <div class="hw-big"><?php echo e($heroText); ?></div>
      <div class="text-muted"><?php echo e($childName); ?><?php echo $className !== '' ? ' · ' . e($className) : ''; ?></div>
    </div>
  </div>

  <?php if ($classId <= 0): ?>
    <div class="alert alert-info mb-0">Class is not set for this child. Ask the school office.</div>
  <?php elseif ($homeworks === []): ?>
    <div class="hw-card text-muted mb-0">No homework for this class yet this year.</div>
  <?php else: ?>
    <?php
    $sections = [
        ['Today', $todayHw, true],
        ['This week', $weekHw, false],
        ['Earlier', $earlierHw, false],
    ];
    foreach ($sections as [$secLabel, $list, $markToday]):
        if ($list === []) {
            continue;
        }
        ?>
      <div class="hw-sec"><?php echo e($secLabel); ?></div>
      <?php foreach ($list as $hw):
          $assigned = substr((string) ($hw['assigned_date'] ?? ''), 0, 10);
          $due = substr((string) ($hw['due_date'] ?? ''), 0, 10);
          $desc = trim((string) ($hw['description'] ?? ''));
          $dueLabel = '';
          if ($due !== '' && $due !== '0000-00-00' && $due !== $assigned) {
              $dueLabel = 'Do by ' . $fmtDay($due);
          }
          ?>
        <div class="hw-card<?php echo $markToday ? ' today' : ''; ?>">
          <div class="hw-title"><?php echo e((string) ($hw['title'] ?? '')); ?></div>
          <div class="hw-meta"><?php echo e($fmtDay($assigned)); ?>
            <?php if ($dueLabel !== ''): ?>
              · <span class="hw-due"><?php echo e($dueLabel); ?></span>
            <?php endif; ?>
          </div>
          <?php if ($desc !== ''): ?>
            <div class="mt-2" style="white-space:pre-wrap"><?php echo e($desc); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
