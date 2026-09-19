<?php
/**
 * parent/dashboard.php — how is my child today.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
require_once __DIR__ . '/../includes/student_idcard.php';
require_once __DIR__ . '/../includes/student_report.php';

$parentId = panel_parent_context_id();
$today = date('Y-m-d');
$skip_panel_ay_banner = true;

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

$inr = static function (float $n): string {
    if (function_exists('format_money')) {
        return format_money($n);
    }
    return '₹ ' . number_format($n, 0);
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
foreach ($childIds as $id) {
    $row = student_idcard_load($id);
    if ($row) {
        $children[] = $row;
    }
}

$selectedId = (int) ($_GET['student_id'] ?? $_GET['child_id'] ?? 0);
if ($selectedId <= 0 && $childIds !== []) {
    $selectedId = $childIds[0];
}
if ($selectedId > 0 && !in_array($selectedId, $childIds, true)) {
    $selectedId = $childIds[0] ?? 0;
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

$report = $selectedId > 0 ? student_report_build($selectedId, date('Y-m')) : null;
$selected = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $selectedId) {
        $selected = $c;
        break;
    }
}

$att = is_array($report['attendance'] ?? null) ? $report['attendance'] : ['present' => 0, 'absent' => 0, 'leave' => 0, 'today' => ''];
$fees = is_array($report['fees'] ?? null) ? $report['fees'] : ['total' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
$due = (float) ($fees['remaining'] ?? 0);
$childName = $report['name'] ?? ($selected ? $nameOf($selected) : 'Your child');
$className = trim((string) ($report['class'] ?? ($selected['class_name'] ?? '')));
$photo = (string) ($report['photo'] ?? '');
if ($photo === '' && $selected && function_exists('student_photo_url')) {
    $photo = student_photo_url((string) ($selected['photo_path'] ?? ''));
}

$heroText = 'Not marked yet';
$heroClass = 'none';
if (($att['today'] ?? '') === 'present' || ($att['today'] ?? '') === 'late') {
    $heroText = 'In school today';
    $heroClass = 'in';
} elseif (($att['today'] ?? '') === 'absent') {
    $heroText = 'Absent today';
    $heroClass = 'out';
} elseif (($att['today'] ?? '') === 'leave') {
    $heroText = 'Leave today';
    $heroClass = 'leave';
}

$notices = [];
if (table_exists('notices')) {
    $order = (function_exists('column_exists') && column_exists('notices', 'published_at'))
        ? 'COALESCE(published_at, created_at) DESC'
        : 'id DESC';
    $where = '1=1';
    if (function_exists('column_exists') && column_exists('notices', 'published_at')) {
        $where = 'published_at IS NOT NULL';
    }
    $notices = safe_db_get_all("SELECT title, published_at FROM notices WHERE {$where} ORDER BY {$order} LIMIT 3") ?: [];
}

$events = [];
if (table_exists('news_events')) {
    $where = '1=1';
    $params = [];
    if (function_exists('column_exists') && column_exists('news_events', 'is_published')) {
        $where .= ' AND is_published = 1';
    }
    if (function_exists('column_exists') && column_exists('news_events', 'type')) {
        $where .= " AND type = 'event'";
    }
    if (function_exists('column_exists') && column_exists('news_events', 'start_date')) {
        $where .= ' AND start_date >= :d';
        $params[':d'] = $today;
        $events = safe_db_get_all(
            "SELECT title, start_date FROM news_events WHERE {$where} ORDER BY start_date ASC LIMIT 3",
            $params
        ) ?: [];
    }
}

$fmtDay = static function (string $raw): string {
    $d = substr($raw, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    if ($d === date('Y-m-d')) {
        return 'Today';
    }
    $ts = strtotime($d);
    return $ts ? date('d M', $ts) : $d;
};

$page_title = 'Home';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.pd-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.pd-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.pd-chip img, .pd-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.pd-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.pd-hero.in { border-color:#86efac; background:#f0fdf4; }
.pd-hero.out { border-color:#fca5a5; background:#fef2f2; }
.pd-hero.leave { border-color:#fdba74; background:#fff7ed; }
.pd-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.pd-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.pd-row { display:flex; gap:10px; margin-bottom:14px; }
.pd-stat { flex:1; background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:12px; text-align:center; text-decoration:none; color:inherit; }
.pd-stat .n { font-weight:800; font-size:1.1rem; color:#1e3a5f; }
.pd-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:14px; }
@media (min-width:576px) { .pd-grid { grid-template-columns:repeat(3,1fr); } }
.pd-act { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 12px; text-decoration:none; color:#1e3a5f; display:flex; flex-direction:column; gap:4px; }
.pd-act strong { font-size:.95rem; }
.pd-act span { font-size:.78rem; color:#64748b; }
.pd-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.pd-line { display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px dashed #e2e8f0; font-size:.92rem; }
.pd-line:last-child { border-bottom:0; }
.pd-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:4px 0 8px; }
.pd-due { color:#c2410c; font-weight:800; }
.pd-ok { color:#166534; font-weight:800; }
</style>

<p class="text-muted mb-2">Your child’s day at preschool.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="pd-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="?student_id=<?php echo $cid; ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="pd-hero <?php echo e($heroClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="pd-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="pd-photo"></div>
    <?php endif; ?>
    <div>
      <div class="pd-big"><?php echo e($heroText); ?></div>
      <div class="text-muted"><?php echo e($childName); ?><?php echo $className !== '' ? ' · ' . e($className) : ''; ?> · <?php echo e(date('D, d M')); ?></div>
    </div>
  </div>

  <div class="pd-row">
    <a class="pd-stat" href="<?php echo e($u('/parent/attendance.php') . '?student_id=' . $selectedId); ?>">
      <div class="n pd-ok"><?php echo (int) ($att['present'] ?? 0); ?></div>
      <div class="small text-muted">Present this month</div>
    </a>
    <a class="pd-stat" href="<?php echo e($u('/parent/fees.php') . '?student_id=' . $selectedId); ?>">
      <div class="n <?php echo $due < 0.5 ? 'pd-ok' : 'pd-due'; ?>"><?php echo $due < 0.5 ? 'Paid' : e($inr($due)); ?></div>
      <div class="small text-muted">Fees due</div>
    </a>
    <a class="pd-stat" href="<?php echo e($u('/parent/homeworks.php') . '?student_id=' . $selectedId); ?>">
      <div class="n" style="font-size:.95rem"><?php echo e((string) ($report['homework'] ?? '') !== '' ? 'See it' : 'None'); ?></div>
      <div class="small text-muted">Homework</div>
    </a>
  </div>

  <div class="pd-grid">
    <a class="pd-act" href="<?php echo e($u('/parent/attendance.php') . '?student_id=' . $selectedId); ?>">
      <strong>Attendance</strong><span>Did they come?</span>
    </a>
    <a class="pd-act" href="<?php echo e($u('/parent/homeworks.php') . '?student_id=' . $selectedId); ?>">
      <strong>Homework</strong><span>What to do at home</span>
    </a>
    <a class="pd-act" href="<?php echo e($u('/parent/fees.php') . '?student_id=' . $selectedId); ?>">
      <strong>Fees</strong><span>Due and receipts</span>
    </a>
    <a class="pd-act" href="<?php echo e($u('/parent/gallery.php') . '?student_id=' . $selectedId); ?>">
      <strong>Photos</strong><span>Class gallery</span>
    </a>
    <a class="pd-act" href="<?php echo e($u('/parent/notices.php') . '?student_id=' . $selectedId); ?>">
      <strong>Notices</strong><span>School messages</span>
    </a>
    <a class="pd-act" href="<?php echo e($u('/parent/id_card.php') . '?student_id=' . $selectedId); ?>">
      <strong>ID card</strong><span>Print or save</span>
    </a>
  </div>

  <?php if ((string) ($report['homework'] ?? '') !== '' || (string) ($report['remark'] ?? '') !== ''): ?>
    <div class="pd-card">
      <div class="pd-sec">This month</div>
      <?php if ((string) ($report['homework'] ?? '') !== ''): ?>
        <div class="pd-line"><span class="text-muted">Homework</span><span><?php echo e((string) $report['homework']); ?></span></div>
      <?php endif; ?>
      <?php if ((string) ($report['remark'] ?? '') !== ''): ?>
        <div class="pd-line"><span class="text-muted">Teacher</span><span><?php echo e((string) $report['remark']); ?></span></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($notices !== [] || $events !== []): ?>
    <div class="pd-card">
      <div class="pd-sec">School news</div>
      <?php foreach ($notices as $n): ?>
        <div class="pd-line">
          <span><?php echo e((string) ($n['title'] ?? '')); ?></span>
          <span class="text-muted"><?php echo e($fmtDay((string) ($n['published_at'] ?? ''))); ?></span>
        </div>
      <?php endforeach; ?>
      <?php foreach ($events as $ev): ?>
        <div class="pd-line">
          <span><?php echo e((string) ($ev['title'] ?? '')); ?></span>
          <span class="text-muted"><?php echo e($fmtDay((string) ($ev['start_date'] ?? ''))); ?></span>
        </div>
      <?php endforeach; ?>
      <div class="mt-2"><a href="<?php echo e($u('/parent/notices.php')); ?>">All notices</a> · <a href="<?php echo e($u('/parent/events.php')); ?>">Events</a></div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
