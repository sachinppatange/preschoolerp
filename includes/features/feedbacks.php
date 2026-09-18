<?php
/**
 * Owner inbox for parent website feedback.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Feedback');
$pageTitle = $page_title;
$esc = static fn(string $v): string => e($v);
$publicUrl = function_exists('site_url') ? site_url('/feedback.php') : '../feedback.php';

if (!function_exists('table_exists') || !table_exists('feedbacks')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The feedbacks table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$messages = [];
$errors = [];
$action = (string) ($_REQUEST['action'] ?? 'list');

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if (safe_db_run('DELETE FROM feedbacks WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    }
    $errors[] = 'Could not delete.';
}

if (!empty($_GET['deleted'])) {
    $messages[] = 'Removed.';
}

$tab = (string) ($_GET['tab'] ?? 'all');
if (!in_array($tab, ['all', 'feedback', 'suggestion'], true)) {
    $tab = 'all';
}
$qraw = trim((string) ($_GET['q'] ?? ''));
$openId = (int) ($_GET['id'] ?? 0);

$where = [];
$params = [];
if ($tab === 'feedback' || $tab === 'suggestion') {
    $where[] = 'f.type = :type';
    $params[':type'] = $tab;
}
if ($qraw !== '') {
    $where[] = '(f.name LIKE :q OR f.phone LIKE :q OR f.message LIKE :q)';
    $params[':q'] = '%' . $qraw . '%';
}
$whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

$countAll = (int) (safe_db_get_one('SELECT COUNT(*) AS c FROM feedbacks')['c'] ?? 0);
$countThanks = (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM feedbacks WHERE type = 'feedback'")['c'] ?? 0);
$countIdea = (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM feedbacks WHERE type = 'suggestion'")['c'] ?? 0);

$rows = safe_db_get_all(
    "SELECT f.* FROM feedbacks f {$whereSql} ORDER BY f.created_at DESC LIMIT 150",
    $params
) ?: [];

$digits = static function (?string $phone): string {
    return preg_replace('/\D+/', '', (string) $phone) ?? '';
};

require_once __DIR__ . '/../header.php';
?>
<style>
.fb-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.fb-card.open { border-color:#93c5fd; }
.fb-who { font-weight:700; color:#1e3a5f; }
.fb-meta { font-size:.82rem; color:#64748b; }
.fb-msg { white-space:pre-wrap; margin:8px 0 0; }
.fb-snip { color:#334155; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Parent feedback</h1>
    <p class="text-muted mb-0">Messages from the website form. Call if you need to reply. Public page: <a href="<?php echo $esc($publicUrl); ?>" target="_blank" rel="noopener">feedback.php</a></p>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo $esc($er); ?></div><?php endforeach; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm <?php echo $tab === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=all">All (<?php echo $countAll; ?>)</a>
    <a class="btn btn-sm <?php echo $tab === 'feedback' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=feedback">Thanks (<?php echo $countThanks; ?>)</a>
    <a class="btn btn-sm <?php echo $tab === 'suggestion' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=suggestion">Ideas (<?php echo $countIdea; ?>)</a>
  </div>
  <form method="get" class="d-flex gap-1">
    <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
    <input class="form-control form-control-sm" name="q" value="<?php echo $esc($qraw); ?>" placeholder="Search name or phone" style="width:180px">
  </form>
</div>

<?php if ($rows === []): ?>
  <div class="alert alert-light border mb-0">No messages yet. Parents send them from the website Feedback page.</div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $id = (int) $r['id'];
    $open = $openId === $id;
    $phone = $digits($r['phone'] ?? '');
    $phone10 = $phone !== '' ? substr($phone, -10) : '';
    $wa = $phone10 !== '' ? ('https://wa.me/91' . $phone10) : '';
    $tel = $phone10 !== '' ? ('tel:' . $phone10) : '';
    $kind = ((string) ($r['type'] ?? '')) === 'suggestion' ? 'Idea' : 'Thanks';
    $when = substr((string) ($r['created_at'] ?? ''), 0, 16);
    $msg = trim((string) ($r['message'] ?? ''));
    $snip = mb_strlen($msg) > 140 ? (mb_substr($msg, 0, 140) . '…') : $msg;
    $qs = http_build_query(array_filter(['tab' => $tab, 'q' => $qraw !== '' ? $qraw : null, 'id' => $open ? null : $id]));
?>
  <div class="fb-card<?php echo $open ? ' open' : ''; ?>">
    <div class="d-flex justify-content-between gap-2 flex-wrap">
      <div>
        <div class="fb-who"><?php echo $esc(trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : 'Parent'); ?></div>
        <div class="fb-meta"><?php echo $esc($kind); ?> · <?php echo $esc($when); ?><?php if ($phone10 !== ''): ?> · <?php echo $esc($phone10); ?><?php endif; ?></div>
      </div>
      <div class="d-flex gap-1 flex-wrap align-items-start">
        <?php if ($tel !== ''): ?><a class="btn btn-sm btn-outline-primary" href="<?php echo $esc($tel); ?>">Call</a><?php endif; ?>
        <?php if ($wa !== ''): ?><a class="btn btn-sm btn-outline-success" href="<?php echo $esc($wa); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?php echo $esc($qs); ?>"><?php echo $open ? 'Hide' : 'Read'; ?></a>
        <?php echo render_secure_delete_button($id, '×', 'Delete this message?', 'btn btn-sm btn-link text-danger text-decoration-none'); ?>
      </div>
    </div>
    <?php if ($open): ?>
      <p class="fb-msg mb-0"><?php echo nl2br($esc($msg)); ?></p>
    <?php else: ?>
      <p class="fb-snip small mb-0 mt-2"><?php echo $esc($snip); ?></p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../footer.php'; ?>
