<?php
/**
 * parent/events.php — school days to remember (annual day, picnic, sports).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$today = date('Y-m-d');
$tableOk = table_exists('news_events');

$col = static function (string $name) use ($tableOk): bool {
    return $tableOk && function_exists('column_exists') && column_exists('news_events', $name);
};

$ayFrom = $ayTo = $today;
if (function_exists('ay_limit_dates')) {
    [$ayFrom, $ayTo] = ay_limit_dates('2000-01-01', '2099-12-31');
}

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
    if ($d === date('Y-m-d', strtotime('+1 day'))) {
        return 'Tomorrow';
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('D, d M', $ts);
};

$dateLabel = static function (array $r) use ($fmtDay): string {
    $start = substr((string) ($r['start_date'] ?? ''), 0, 10);
    $end = substr((string) ($r['end_date'] ?? ''), 0, 10);
    $a = $fmtDay($start);
    if ($a === '') {
        $created = substr((string) ($r['created_at'] ?? ''), 0, 10);
        return $fmtDay($created);
    }
    if ($end !== '' && $end !== '0000-00-00' && $end !== $start) {
        return $a . ' – ' . $fmtDay($end);
    }
    return $a;
};

$imgUrl = static function (array $r): string {
    $path = trim((string) ($r['image_path'] ?? ''));
    if ($path === '') {
        return '';
    }
    if (function_exists('resolve_image_url')) {
        return (string) resolve_image_url($path, '');
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return (function_exists('site_url') ? rtrim(site_url(''), '/') : '') . '/' . ltrim($path, '/');
};

$bodyOf = static function (array $r): string {
    $c = trim((string) ($r['content'] ?? ''));
    if ($c !== '') {
        return $c;
    }
    return trim((string) ($r['excerpt'] ?? ''));
};

$whenOf = static function (array $r): string {
    foreach (['start_date', 'created_at'] as $c) {
        $d = substr((string) ($r[$c] ?? ''), 0, 10);
        if ($d !== '' && $d !== '0000-00-00') {
            return $d;
        }
    }
    return '';
};

$endOf = static function (array $r) use ($whenOf): string {
    $end = substr((string) ($r['end_date'] ?? ''), 0, 10);
    if ($end !== '' && $end !== '0000-00-00') {
        return $end;
    }
    return $whenOf($r);
};

$rows = [];
if ($tableOk) {
    $where = ['1=1'];
    $params = [];
    if ($col('is_published')) {
        $where[] = 'is_published = 1';
    }
    $dateParts = [];
    if ($col('start_date')) {
        $dateParts[] = '(start_date IS NOT NULL AND start_date BETWEEN :a1 AND :b1)';
        $params[':a1'] = $ayFrom;
        $params[':b1'] = $ayTo;
    }
    if ($col('end_date')) {
        $dateParts[] = '(end_date IS NOT NULL AND end_date BETWEEN :a2 AND :b2)';
        $params[':a2'] = $ayFrom;
        $params[':b2'] = $ayTo;
    }
    if ($col('created_at')) {
        if ($col('start_date')) {
            $dateParts[] = '((start_date IS NULL OR start_date = :zero) AND DATE(created_at) BETWEEN :a3 AND :b3)';
            $params[':zero'] = '0000-00-00';
        } else {
            $dateParts[] = 'DATE(created_at) BETWEEN :a3 AND :b3';
        }
        $params[':a3'] = $ayFrom;
        $params[':b3'] = $ayTo;
    }
    if ($dateParts !== []) {
        $where[] = '(' . implode(' OR ', $dateParts) . ')';
    }
    $order = $col('start_date')
        ? 'CASE WHEN start_date IS NULL THEN 1 ELSE 0 END, start_date ASC, id DESC'
        : 'id DESC';
    $rows = safe_db_get_all(
        'SELECT * FROM news_events WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 40',
        $params
    ) ?: [];
}

$upcoming = [];
$past = [];
$news = [];
foreach ($rows as $r) {
    $type = strtolower((string) ($r['type'] ?? 'event'));
    if ($type === 'news') {
        $news[] = $r;
        continue;
    }
    if ($endOf($r) >= $today) {
        $upcoming[] = $r;
    } else {
        $past[] = $r;
    }
}
usort($past, static function (array $a, array $b) use ($whenOf): int {
    return $whenOf($b) <=> $whenOf($a);
});

$next = $upcoming[0] ?? null;
$heroText = 'No events coming up';
$heroClass = 'none';
$heroSub = 'School days like annual day, picnic or sports.';
if ($next) {
    $heroText = (string) ($next['title'] ?? 'Next event');
    $heroClass = 'has';
    $heroSub = $dateLabel($next);
    $place = trim((string) ($next['location'] ?? ''));
    if ($place !== '') {
        $heroSub .= ' · ' . $place;
    }
}

$page_title = 'Events';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.ev-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; }
.ev-hero.has { border-color:#fcd34d; background:#fffbeb; }
.ev-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.ev-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:14px 16px; margin-bottom:10px; overflow:hidden; }
.ev-card.next { border-color:#fcd34d; background:#fffbeb; }
.ev-title { font-weight:800; color:#1e3a5f; }
.ev-meta { font-size:.88rem; color:#64748b; }
.ev-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:14px 0 8px; }
.ev-pill { display:inline-block; border-radius:999px; padding:.12rem .55rem; font-size:.75rem; font-weight:700; background:#ffedd5; color:#9a3412; }
.ev-photo { width:100%; max-height:180px; object-fit:cover; border-radius:12px; margin-bottom:10px; }
</style>

<p class="text-muted mb-2">School events — what day, where, and what to bring.</p>

<?php if (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Events are not set up yet.</div>
<?php else: ?>

  <div class="ev-hero <?php echo e($heroClass); ?>">
    <div class="ev-big"><?php echo e($heroText); ?></div>
    <div class="text-muted"><?php echo e($heroSub); ?></div>
  </div>

  <?php if ($upcoming === [] && $past === [] && $news === []): ?>
    <div class="ev-card text-muted mb-0">No events this year yet.</div>
  <?php endif; ?>

  <?php if ($upcoming !== []): ?>
    <div class="ev-sec">Coming up</div>
    <?php foreach ($upcoming as $i => $ev):
        $place = trim((string) ($ev['location'] ?? ''));
        $body = $bodyOf($ev);
        $img = $imgUrl($ev);
        ?>
      <div class="ev-card<?php echo $i === 0 ? ' next' : ''; ?>">
        <?php if ($img !== ''): ?><img class="ev-photo" src="<?php echo e($img); ?>" alt=""><?php endif; ?>
        <div class="ev-title"><?php echo e((string) ($ev['title'] ?? '')); ?></div>
        <div class="ev-meta">
          <span class="ev-pill"><?php echo e($dateLabel($ev)); ?></span>
          <?php if ($place !== ''): ?> · <?php echo e($place); ?><?php endif; ?>
        </div>
        <?php if ($body !== ''): ?>
          <div class="mt-2" style="white-space:pre-wrap"><?php echo e($body); ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($news !== []): ?>
    <div class="ev-sec">School news</div>
    <?php foreach ($news as $ev):
        $body = $bodyOf($ev);
        $img = $imgUrl($ev);
        $when = $fmtDay($whenOf($ev));
        ?>
      <div class="ev-card">
        <?php if ($img !== ''): ?><img class="ev-photo" src="<?php echo e($img); ?>" alt=""><?php endif; ?>
        <div class="ev-title"><?php echo e((string) ($ev['title'] ?? '')); ?></div>
        <?php if ($when !== ''): ?><div class="ev-meta"><?php echo e($when); ?></div><?php endif; ?>
        <?php if ($body !== ''): ?>
          <div class="mt-2" style="white-space:pre-wrap"><?php echo e($body); ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($past !== []): ?>
    <div class="ev-sec">Earlier this year</div>
    <?php foreach ($past as $ev):
        $place = trim((string) ($ev['location'] ?? ''));
        $body = $bodyOf($ev);
        ?>
      <div class="ev-card">
        <div class="ev-title"><?php echo e((string) ($ev['title'] ?? '')); ?></div>
        <div class="ev-meta"><?php echo e($dateLabel($ev)); ?><?php echo $place !== '' ? ' · ' . e($place) : ''; ?></div>
        <?php if ($body !== ''): ?>
          <div class="mt-2" style="white-space:pre-wrap"><?php echo e($body); ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
