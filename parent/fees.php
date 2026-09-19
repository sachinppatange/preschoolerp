<?php
/**
 * parent/fees.php — how much is due, what was paid at school.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');

$parentId = panel_parent_context_id();
$tableOk = table_exists('fees_records');

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

$feeCol = function_exists('column_exists') && column_exists('students', 'total_fees');
$children = [];
if ($childIds !== [] && table_exists('students')) {
    $in = implode(',', array_map('intval', $childIds));
    $join = table_exists('classes') ? 'LEFT JOIN classes c ON c.id = s.class_id' : '';
    $classSel = table_exists('classes') ? ', c.name AS class_name' : '';
    $feeSel = $feeCol ? ', s.total_fees' : '';
    $rows = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id, s.photo_path{$feeSel}{$classSel}
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
    return date('d M Y', $ts);
};

$receiptBits = static function (string $raw): array {
    $raw = trim($raw);
    $no = $raw;
    $method = '';
    if (str_contains($raw, '||')) {
        $parts = explode('||', $raw);
        $no = trim((string) ($parts[0] ?? ''));
        foreach ($parts as $p) {
            $p = trim($p);
            if (stripos($p, 'METHOD:') === 0) {
                $method = trim(substr($p, 7));
            }
        }
    }
    return [$no, $method];
};

$className = $selected ? trim((string) ($selected['class_name'] ?? '')) : '';
$childName = $selected ? $nameOf($selected) : 'Your child';
$photo = $selected && function_exists('student_photo_url')
    ? student_photo_url((string) ($selected['photo_path'] ?? ''))
    : '';

$totalFees = $selected && $feeCol ? (float) ($selected['total_fees'] ?? 0) : 0.0;
$paid = 0.0;
$payments = [];
if ($tableOk && $selectedId > 0) {
    $sum = safe_db_get_one(
        'SELECT COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id = :sid',
        [':sid' => $selectedId]
    );
    $paid = (float) ($sum['paid_sum'] ?? 0);
    $payments = safe_db_get_all(
        'SELECT id, paid_amount, amount, receipt_no, collected_at, created_at
         FROM fees_records
         WHERE student_id = :sid AND paid_amount > 0
         ORDER BY COALESCE(collected_at, created_at) DESC, id DESC
         LIMIT 24',
        [':sid' => $selectedId]
    ) ?: [];
}

$pending = max(0.0, $totalFees - $paid);
$cleared = $pending < 0.5;

$heroText = $cleared ? 'Fees paid' : ($inr($pending) . ' still due');
$heroClass = $cleared ? 'ok' : 'due';
$heroSub = $childName;
if ($className !== '') {
    $heroSub .= ' · ' . $className;
}

$feesUrl = static function (int $sid = 0, int $receiptId = 0): string {
    $q = [];
    if ($sid > 0) {
        $q['student_id'] = $sid;
    }
    if ($receiptId > 0) {
        $q['receipt'] = $receiptId;
    }
    $path = '/parent/fees.php' . ($q !== [] ? ('?' . http_build_query($q)) : '');
    return function_exists('site_url') ? site_url($path) : $path;
};

$plainSchool = static function (string $raw): string {
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t) ?? $t;
    $t = preg_replace('/<\/\s*(p|div|li|h[1-6])\s*>/i', "\n", $t) ?? $t;
    $t = strip_tags($t);
    $t = preg_replace('/^\s*Address\s*:\s*/im', '', $t) ?? $t;
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
    $t = preg_replace("/\n{2,}/", "\n", $t) ?? $t;
    return trim($t);
};

$receiptId = (int) ($_GET['receipt'] ?? $_GET['payment_id'] ?? 0);
if ($receiptId > 0 && $tableOk) {
    $pay = safe_db_get_one(
        'SELECT id, student_id, paid_amount, amount, receipt_no, collected_at, created_at
         FROM fees_records WHERE id = :id LIMIT 1',
        [':id' => $receiptId]
    );
    $paySid = (int) ($pay['student_id'] ?? 0);
    $okPay = $pay && $paySid > 0 && in_array($paySid, $childIds, true);

    $school = [];
    if (table_exists('schools')) {
        $school = safe_db_get_one('SELECT * FROM schools ORDER BY id ASC LIMIT 1') ?: [];
    }
    $schoolName = trim((string) ($school['name'] ?? ''));
    if ($schoolName === '') {
        $schoolName = defined('APP_NAME') ? (string) APP_NAME : 'School';
    }
    $schoolAddr = $plainSchool((string) ($school['address'] ?? ''));
    $schoolPhone = trim((string) ($school['contact_phone'] ?? $school['phone'] ?? ''));
    $logo = '';
    if (!empty($school['logo_path']) && function_exists('resolve_image_url')) {
        $logo = (string) resolve_image_url((string) $school['logo_path'], '');
    }

    $payChild = $childName;
    $payClass = $className;
    foreach ($children as $ch) {
        if ((int) $ch['id'] === $paySid) {
            $payChild = $nameOf($ch);
            $payClass = trim((string) ($ch['class_name'] ?? ''));
            break;
        }
    }
    $amt = (float) ($pay['paid_amount'] ?? $pay['amount'] ?? 0);
    $rawWhen = substr((string) ($pay['collected_at'] ?? $pay['created_at'] ?? ''), 0, 10);
    $when = ($rawWhen !== '' && $rawWhen !== '0000-00-00' && strtotime($rawWhen) !== false)
        ? date('d M Y', strtotime($rawWhen))
        : '';
    [$rno, $method] = $okPay ? $receiptBits((string) ($pay['receipt_no'] ?? '')) : ['', ''];
    $back = $feesUrl($okPay ? $paySid : $selectedId);

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Fee receipt<?php echo $rno !== '' ? ' — ' . e($rno) : ''; ?></title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #eef3fb; color: #0f2744;
      font-family: "Segoe UI", Arial, sans-serif; }
    .bar { display: flex; gap: 8px; align-items: center; justify-content: center;
      padding: 12px; background: #fff; border-bottom: 1px solid #dbe7fb; }
    .bar button, .bar a { background: #16a34a; color: #fff; border: 0; padding: 10px 18px;
      border-radius: 10px; font: 700 15px/1 "Segoe UI", Arial, sans-serif; text-decoration: none; cursor: pointer; }
    .bar a.alt { background: #fff; color: #1e3a5f; border: 1px solid #dbe7fb; }
    .sheet { padding: 18px 12px 40px; }
    .slip { width: 148mm; max-width: 100%; margin: 0 auto; background: #fff; border: 1px solid #c5d4ea;
      border-radius: 4px; padding: 18mm 14mm; }
    .logo { max-height: 52px; max-width: 120px; display: block; margin: 0 auto 8px; }
    h1 { font-size: 18px; font-weight: 800; margin: 0 0 6px; text-align: center; }
    .addr { color: #475569; font-size: 12px; line-height: 1.45; text-align: center; white-space: pre-line; margin: 0 0 4px; }
    .phone { color: #475569; font-size: 12px; text-align: center; margin: 0 0 10px; }
    .tag { text-align: center; font-size: 11px; font-weight: 800; letter-spacing: .12em;
      text-transform: uppercase; color: #1d4ed8; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;
      padding: 8px 0; margin: 8px 0 4px; }
    .amt { text-align: center; font-size: 28px; font-weight: 800; color: #166534; margin: 12px 0 16px; }
    .rowl { display: flex; justify-content: space-between; gap: 16px; font-size: 14px;
      padding: 8px 0; border-bottom: 1px dashed #dbe3ee; }
    .rowl span:first-child { color: #64748b; }
    .rowl span:last-child { font-weight: 600; text-align: right; }
    .foot { text-align: center; color: #94a3b8; font-size: 11px; margin-top: 18px; }
    .err { max-width: 420px; margin: 40px auto; background: #fff; padding: 24px; border-radius: 12px; text-align: center; }
    @page { size: A4 portrait; margin: 12mm; }
    @media print {
      html, body { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .bar { display: none !important; }
      .sheet { padding: 0; }
      .slip { width: 170mm; max-width: 100%; border: 1px solid #cbd5e1; border-radius: 0; padding: 12mm 14mm; margin: 0 auto; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <?php if ($okPay): ?><button type="button" onclick="window.print()">Print / Save PDF</button><?php endif; ?>
    <a class="alt" href="<?php echo e($back); ?>">Back to fees</a>
  </div>
  <?php if (!$okPay): ?>
    <div class="err">This receipt was not found. <a href="<?php echo e($back); ?>">Back to fees</a></div>
  <?php else: ?>
    <div class="sheet">
      <article class="slip">
        <?php if ($logo !== ''): ?><img class="logo" src="<?php echo e($logo); ?>" alt=""><?php endif; ?>
        <h1><?php echo e($schoolName); ?></h1>
        <?php if ($schoolAddr !== ''): ?><p class="addr"><?php echo e($schoolAddr); ?></p><?php endif; ?>
        <?php if ($schoolPhone !== ''): ?><p class="phone"><?php echo e($schoolPhone); ?></p><?php endif; ?>
        <div class="tag">Fee receipt</div>
        <div class="amt"><?php echo e($inr($amt)); ?></div>
        <div class="rowl"><span>Child</span><span><?php echo e($payChild); ?></span></div>
        <?php if ($payClass !== ''): ?>
          <div class="rowl"><span>Class</span><span><?php echo e($payClass); ?></span></div>
        <?php endif; ?>
        <div class="rowl"><span>Date</span><span><?php echo e($when !== '' ? $when : '—'); ?></span></div>
        <?php if ($method !== ''): ?>
          <div class="rowl"><span>Paid by</span><span><?php echo e($method); ?></span></div>
        <?php endif; ?>
        <div class="rowl"><span>Receipt no.</span><span><?php echo e($rno !== '' ? $rno : ('#' . $receiptId)); ?></span></div>
        <div class="foot">Paid at school office</div>
      </article>
    </div>
  <?php endif; ?>
</body>
</html>
    <?php
    exit;
}

$page_title = 'Fees';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>
<style>
.fe-chip { display:inline-flex; align-items:center; gap:.4rem; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.3rem .8rem; text-decoration:none; color:#1e3a5f; font-weight:600; margin:0 .35rem .5rem 0; }
.fe-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.fe-chip img, .fe-chip .ph { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.fe-hero { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:18px; margin-bottom:14px; display:flex; gap:14px; align-items:center; }
.fe-hero.ok { border-color:#86efac; background:#f0fdf4; }
.fe-hero.due { border-color:#fdba74; background:#fff7ed; }
.fe-photo { width:64px; height:64px; border-radius:16px; object-fit:cover; background:#e2e8f0; flex-shrink:0; }
.fe-big { font-weight:800; font-size:1.25rem; color:#1e3a5f; }
.fe-row { display:flex; gap:10px; margin-bottom:14px; }
.fe-stat { flex:1; background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:12px; text-align:center; }
.fe-stat .n { font-weight:800; font-size:1.15rem; color:#1e3a5f; }
.fe-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:12px 14px; margin-bottom:8px; display:flex; justify-content:space-between; gap:10px; align-items:center; }
.fe-amt { font-weight:800; color:#166534; white-space:nowrap; }
.fe-meta { font-size:.88rem; color:#64748b; }
.fe-sec { font-size:.75rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#94a3b8; margin:8px 0; }
.fe-note { background:#eff6ff; border-radius:14px; padding:12px 14px; color:#1e3a5f; font-size:.92rem; }
</style>

<p class="text-muted mb-2">Fees and receipts in one place. Pay at school — the office will update this page.</p>

<?php if ($parentId <= 0): ?>
<?php elseif ($children === []): ?>
  <div class="alert alert-info mb-0">No child is linked to this login. Ask the school office.</div>
<?php elseif (!$tableOk && !$feeCol): ?>
  <div class="alert alert-warning mb-0">Fees are not set up yet.</div>
<?php else: ?>

  <?php if (count($children) > 1): ?>
    <div class="mb-3">
      <?php foreach ($children as $ch):
          $cid = (int) $ch['id'];
          $p = function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : '';
          ?>
        <a class="fe-chip<?php echo $cid === $selectedId ? ' active' : ''; ?>" href="?student_id=<?php echo $cid; ?>">
          <?php if ($p !== ''): ?><img src="<?php echo e($p); ?>" alt=""><?php else: ?><span class="ph"></span><?php endif; ?>
          <?php echo e($nameOf($ch)); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="fe-hero <?php echo e($heroClass); ?>">
    <?php if ($photo !== ''): ?>
      <img class="fe-photo" src="<?php echo e($photo); ?>" alt="">
    <?php else: ?>
      <div class="fe-photo"></div>
    <?php endif; ?>
    <div>
      <div class="fe-big"><?php echo e($heroText); ?></div>
      <div class="text-muted"><?php echo e($heroSub); ?></div>
    </div>
  </div>

  <div class="fe-row">
    <div class="fe-stat">
      <div class="n"><?php echo e($inr($totalFees)); ?></div>
      <div class="small text-muted">Year fees</div>
    </div>
    <div class="fe-stat">
      <div class="n" style="color:#166534"><?php echo e($inr($paid)); ?></div>
      <div class="small text-muted">Paid</div>
    </div>
    <div class="fe-stat">
      <div class="n" style="color:<?php echo $cleared ? '#166534' : '#c2410c'; ?>"><?php echo e($inr($pending)); ?></div>
      <div class="small text-muted">Still due</div>
    </div>
  </div>

  <?php if (!$cleared): ?>
    <div class="fe-note mb-3">Pay the remaining amount at school (cash / UPI). The office will update this page.</div>
  <?php endif; ?>

  <div class="fe-sec">Receipts</div>
  <?php if ($payments === []): ?>
    <div class="fe-card text-muted">No payments recorded yet.</div>
  <?php else: ?>
    <?php foreach ($payments as $p):
        $amt = (float) ($p['paid_amount'] ?? $p['amount'] ?? 0);
        $when = $fmtDay((string) ($p['collected_at'] ?? $p['created_at'] ?? ''));
        [$rno, $method] = $receiptBits((string) ($p['receipt_no'] ?? ''));
        $pid = (int) ($p['id'] ?? 0);
        ?>
      <div class="fe-card">
        <div>
          <div class="fe-amt"><?php echo e($inr($amt)); ?></div>
          <div class="fe-meta">
            <?php echo e($when); ?>
            <?php if ($method !== ''): ?> · <?php echo e($method); ?><?php endif; ?>
            <?php if ($rno !== ''): ?> · <?php echo e($rno); ?><?php endif; ?>
          </div>
        </div>
        <?php if ($pid > 0): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo e($feesUrl($selectedId, $pid)); ?>">Receipt</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
