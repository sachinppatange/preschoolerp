<?php
/**
 * parent/notices.php — short notes from school / class teacher.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$parentId = panel_parent_context_id();
$today = date('Y-m-d');
$tableOk = table_exists('notices');

$col = static function (string $name) use ($tableOk): bool {
    return $tableOk && function_exists('column_exists') && column_exists('notices', $name);
};

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

$noticeText = static function (array $n): string {
    foreach (['message', 'body', 'content', 'description'] as $c) {
        if (isset($n[$c]) && trim((string) $n[$c]) !== '') {
            return trim((string) $n[$c]);
        }
    }
    return '';
};

$noticeWhen = static function (array $n): string {
    foreach (['published_at', 'created_at'] as $c) {
        $d = substr((string) ($n[$c] ?? ''), 0, 10);
        if ($d !== '' && $d !== '0000-00-00') {
            return $d;
        }
    }
    return '';
};

$classNames = [];
if (table_exists('classes')) {
    foreach ($children as $ch) {
        $cid = (int) ($ch['class_id'] ?? 0);
        $nm = trim((string) ($ch['class_name'] ?? ''));
        if ($cid > 0 && $nm !== '') {
            $classNames[$cid] = $nm;
        }
    }
}

$notices = [];
if ($tableOk) {
    $where = ['1=1'];
    $params = [];

    if ($col('published_at')) {
        $where[] = 'published_at IS NOT NULL AND DATE(published_at) <= :pubto';
        $params[':pubto'] = $today;
        $where[] = 'DATE(published_at) >= :ayfrom';
        $params[':ayfrom'] = $ayFrom;
    } elseif ($col('created_at')) {
        $where[] = 'DATE(created_at) BETWEEN :ayfrom AND :ayto';
        $params[':ayfrom'] = $ayFrom;
        $params[':ayto'] = $ayTo;
    }

    if ($col('expires_at')) {
        $where[] = '(expires_at IS NULL OR DATE(expires_at) = :zero OR DATE(expires_at) >= :expto)';
        $params[':zero'] = '0000-00-00';
        $params[':expto'] = $today;
    }

    if ($col('class_id')) {
        if ($classId > 0) {
            $where[] = '(class_id IS NULL OR class_id = 0 OR class_id = :cid)';
            $params[':cid'] = $classId;
        } else {
            $where[] = '(class_id IS NULL OR class_id = 0)';
        }
    }

    if ($col('audience')) {
        $where[] = "(audience IS NULL OR audience = '' OR LOWER(audience) IN ('all','parents','parent'))";
    }

    $order = $col('published_at')
        ? ($col('created_at') ? 'COALESCE(published_at, created_at) DESC, id DESC' : 'published_at DESC, id DESC')
        : ($col('created_at') ? 'created_at DESC, id DESC' : 'id DESC');

    $notices = safe_db_get_all(
        'SELECT * FROM notices WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 40',
        $params
    ) ?: [];
}

$todayN = [];
$weekN = [];
$earlierN = [];
$weekStart = date('Y-m-d', strtotime('monday this week') ?: time());
foreach ($notices as $n) {
    $when = $noticeWhen($n);
    if ($when === $today) {
        $todayN[] = $n;
    } elseif ($when >= $weekStart) {
        $weekN[] = $n;
    } else {
        $earlierN[] = $n;
    }
}

$heroText = 'No notices yet';
$heroClass = 'none';
$heroSub = $childName . ($className !== '' ? ' · ' . $className : '');
if ($todayN !== []) {
    $heroText = count($todayN) === 1 ? 'New notice today' : (count($todayN) . ' notices today');
    $heroClass = 'has';
} elseif ($notices !== []) {
    $heroText = (string) ($notices[0]['title'] ?? 'Latest notice');
    $heroClass = 'has';
    $when = $fmtDay($noticeWhen($notices[0]));
    $heroSub = ($when !== '' ? $when . ' · ' : '') . $heroSub;
}

$page_title = 'Notices';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.nt-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.nt-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.nt-chip img, .nt-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.nt-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.nt-hero.has { border-color:#93c5fd; background:#eff6ff; }
.nt-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.nt-big { font-weight:800; font-size:1.2rem; color:#1e3a5f; }
.nt-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:14px 16px; margin-bottom:10px; }
.nt-card.today { border-color:#93c5fd; background:#eff6ff; }
.nt-title { font-weight:800; color:#1e3a5f; }
.nt-meta { font-size:.88rem; color:#64748b; }
.nt-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:14px 0 8px; }
.nt-pill { display:inline-block; border-radius:999px; padding:.12rem .55rem; font-size:.75rem; font-weight:700; background:#e0e7ff; color:#3730a3; }
.nt-pill.class { background:#dcfce7; color:#166534; }
</style>

<p class="text-muted mb-2">Messages from school — holiday, picnic, what to bring.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Notices are not set up yet.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="nt-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="?student_id=<?php echo $cid; ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="nt-hero <?php echo e($heroClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="nt-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="nt-photo"></div>
    <?php endif; ?>
    <div>
      <div class="nt-big"><?php echo e($heroText); ?></div>
      <div class="text-muted"><?php echo e($heroSub); ?></div>
    </div>
  </div>

  <?php if ($notices === []): ?>
    <div class="nt-card text-muted mb-0">No notices for this year yet.</div>
  <?php else: ?>
    <?php
    $sections = [
        ['Today', $todayN, true],
        ['This week', $weekN, false],
        ['Earlier', $earlierN, false],
    ];
    foreach ($sections as [$secLabel, $list, $markToday]):
        if ($list === []) {
            continue;
        }
        ?>
      <div class="nt-sec"><?php echo e($secLabel); ?></div>
      <?php foreach ($list as $n):
          $nidClass = $col('class_id') ? (int) ($n['class_id'] ?? 0) : 0;
          $who = $nidClass > 0
              ? ($classNames[$nidClass] ?? $className ?: 'Your class')
              : 'Whole school';
          $pillClass = $nidClass > 0 ? 'class' : '';
          $when = $fmtDay($noticeWhen($n));
          $body = $noticeText($n);
          $att = $col('attachment_path') ? trim((string) ($n['attachment_path'] ?? '')) : '';
          ?>
        <div class="nt-card<?php echo $markToday ? ' today' : ''; ?>">
          <div class="nt-title"><?php echo e((string) ($n['title'] ?? '')); ?></div>
          <div class="nt-meta">
            <span class="nt-pill <?php echo e($pillClass); ?>"><?php echo e($who); ?></span>
            <?php if ($when !== ''): ?> · <?php echo e($when); ?><?php endif; ?>
          </div>
          <?php if ($body !== ''): ?>
            <div class="mt-2" style="white-space:pre-wrap"><?php echo e($body); ?></div>
          <?php endif; ?>
          <?php if ($att !== ''): ?>
            <div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="<?php echo e($att); ?>" target="_blank" rel="noopener">Open file</a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
