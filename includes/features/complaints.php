<?php
/**
 * Owner inbox for parent website complaints.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Complaints');
$pageTitle = $page_title;
$esc = static fn(string $v): string => e($v);
$publicUrl = function_exists('site_url') ? site_url('/complaint.php') : '../complaint.php';
$userId = (int) (auth_user_id() ?? 0);
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

if (!function_exists('table_exists') || !table_exists('complaints')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The complaints table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$col = static function (string $name): bool {
    return function_exists('column_exists') && column_exists('complaints', $name);
};
$hasStatus = $col('status');
$hasActions = function_exists('table_exists') && table_exists('complaint_actions');

$csrfOk = static function () use ($csrf): bool {
    return function_exists('validate_csrf_token')
        && validate_csrf_token((string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? ''));
};

$messages = [];
$errors = [];
$action = (string) ($_REQUEST['action'] ?? 'list');

$setStatus = static function (int $id, string $status) use ($col, $userId): bool {
    if ($id <= 0) {
        return false;
    }
    $fields = [];
    $params = [':id' => $id];
    if ($col('status')) {
        $fields[] = 'status = :st';
        $params[':st'] = $status;
    }
    if ($col('updated_at')) {
        $fields[] = 'updated_at = NOW()';
    }
    if ($col('updated_by') && $userId > 0) {
        $fields[] = 'updated_by = :by';
        $params[':by'] = $userId;
    }
    if ($fields === []) {
        return false;
    }
    return (bool) safe_db_run('UPDATE complaints SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk()) {
        $errors[] = 'Please reload the page and try again.';
    } elseif ($action === 'done') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($setStatus($id, 'closed')) {
            if ($note !== '' && $hasActions) {
                safe_db_run(
                    'INSERT INTO complaint_actions (complaint_id, action_note, performed_by, created_at)
                     VALUES (:id, :n, :by, NOW())',
                    [':id' => $id, ':n' => $note, ':by' => $userId > 0 ? $userId : null]
                );
            }
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not mark done.';
    } elseif ($action === 'reopen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($setStatus($id, 'open')) {
            header('Location: ?tab=done&saved=1');
            exit;
        }
        $errors[] = 'Could not reopen.';
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if ($hasActions) {
        safe_db_run('DELETE FROM complaint_actions WHERE complaint_id = :id', [':id' => $deleteId]);
    }
    if (safe_db_run('DELETE FROM complaints WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    }
    $errors[] = 'Could not delete.';
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Removed.';
}

$tab = (string) ($_GET['tab'] ?? 'open');
if (!in_array($tab, ['open', 'done', 'all'], true)) {
    $tab = 'open';
}
if (!$hasStatus) {
    $tab = 'all';
}
$qraw = trim((string) ($_GET['q'] ?? ''));
$openId = (int) ($_GET['id'] ?? 0);

$where = [];
$params = [];
if ($hasStatus && $tab === 'open') {
    $where[] = "LOWER(COALESCE(c.status,'open')) <> 'closed'";
} elseif ($hasStatus && $tab === 'done') {
    $where[] = "LOWER(c.status) = 'closed'";
}
if ($qraw !== '') {
    $where[] = '(c.name LIKE :q OR c.phone LIKE :q OR c.message LIKE :q)';
    $params[':q'] = '%' . $qraw . '%';
}
$whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

$countOpen = $hasStatus
    ? (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM complaints WHERE LOWER(COALESCE(status,'open')) <> 'closed'")['c'] ?? 0)
    : 0;
$countDone = $hasStatus
    ? (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM complaints WHERE LOWER(status) = 'closed'")['c'] ?? 0)
    : 0;
$countAll = (int) (safe_db_get_one('SELECT COUNT(*) AS c FROM complaints')['c'] ?? 0);

$rows = safe_db_get_all(
    "SELECT c.* FROM complaints c {$whereSql} ORDER BY c.created_at DESC LIMIT 150",
    $params
) ?: [];

$notesBy = [];
if ($hasActions && $rows !== []) {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $ids = array_values(array_filter($ids));
    if ($ids !== []) {
        $in = implode(',', $ids);
        $notes = safe_db_get_all(
            "SELECT complaint_id, action_note, created_at
             FROM complaint_actions
             WHERE complaint_id IN ({$in})
             ORDER BY created_at DESC"
        ) ?: [];
        foreach ($notes as $n) {
            $cid = (int) ($n['complaint_id'] ?? 0);
            if ($cid > 0 && !isset($notesBy[$cid])) {
                $notesBy[$cid] = $n;
            }
        }
    }
}

$digits = static function (?string $phone): string {
    return preg_replace('/\D+/', '', (string) $phone) ?? '';
};

require_once __DIR__ . '/../header.php';
?>
<style>
.cp-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:10px; }
.cp-card.open { border-color:#f59e0b; }
.cp-who { font-weight:700; color:#1e3a5f; }
.cp-meta { font-size:.82rem; color:#64748b; }
.cp-msg { white-space:pre-wrap; margin:8px 0 0; }
.cp-snip { color:#334155; }
.cp-note { font-size:.85rem; color:#475569; background:#f8fafc; border-radius:10px; padding:8px 10px; margin-top:8px; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Complaints</h1>
    <p class="text-muted mb-0">Parents write from the website. Call them, then tick Done. Form: <a href="<?php echo $esc($publicUrl); ?>" target="_blank" rel="noopener">complaint.php</a></p>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo $esc($er); ?></div><?php endforeach; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="d-flex flex-wrap gap-2">
    <?php if ($hasStatus): ?>
      <a class="btn btn-sm <?php echo $tab === 'open' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=open">Open (<?php echo $countOpen; ?>)</a>
      <a class="btn btn-sm <?php echo $tab === 'done' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=done">Done (<?php echo $countDone; ?>)</a>
    <?php endif; ?>
    <a class="btn btn-sm <?php echo $tab === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=all">All (<?php echo $countAll; ?>)</a>
  </div>
  <form method="get" class="d-flex gap-1">
    <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
    <input class="form-control form-control-sm" name="q" value="<?php echo $esc($qraw); ?>" placeholder="Search name or phone" style="width:180px">
  </form>
</div>

<?php if ($rows === []): ?>
  <div class="alert alert-light border mb-0"><?php echo $tab === 'done' ? 'None closed yet.' : 'No open complaints.'; ?></div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $id = (int) $r['id'];
    $isOpenRow = $openId === $id;
    $phone = $digits($r['phone'] ?? '');
    $phone10 = $phone !== '' ? substr($phone, -10) : '';
    $wa = $phone10 !== '' ? ('https://wa.me/91' . $phone10) : '';
    $tel = $phone10 !== '' ? ('tel:' . $phone10) : '';
    $st = strtolower((string) ($r['status'] ?? 'open'));
    $isDone = $st === 'closed';
    $when = substr((string) ($r['created_at'] ?? ''), 0, 16);
    $msg = trim((string) ($r['message'] ?? ''));
    $snip = mb_strlen($msg) > 140 ? (mb_substr($msg, 0, 140) . '…') : $msg;
    $qs = http_build_query(array_filter(['tab' => $tab, 'q' => $qraw !== '' ? $qraw : null, 'id' => $isOpenRow ? null : $id]));
    $lastNote = $notesBy[$id] ?? null;
?>
  <div class="cp-card<?php echo $isOpenRow ? ' open' : ''; ?>">
    <div class="d-flex justify-content-between gap-2 flex-wrap">
      <div>
        <div class="cp-who"><?php echo $esc(trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : 'Parent'); ?></div>
        <div class="cp-meta"><?php echo $esc($isDone ? 'Done' : 'Open'); ?> · <?php echo $esc($when); ?><?php if ($phone10 !== ''): ?> · <?php echo $esc($phone10); ?><?php endif; ?></div>
      </div>
      <div class="d-flex gap-1 flex-wrap align-items-start">
        <?php if ($tel !== ''): ?><a class="btn btn-sm btn-outline-primary" href="<?php echo $esc($tel); ?>">Call</a><?php endif; ?>
        <?php if ($wa !== ''): ?><a class="btn btn-sm btn-outline-success" href="<?php echo $esc($wa); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="?<?php echo $esc($qs); ?>"><?php echo $isOpenRow ? 'Hide' : 'Read'; ?></a>
        <?php if ($hasStatus): ?>
          <?php if ($isDone): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="reopen">
              <input type="hidden" name="id" value="<?php echo $id; ?>">
              <button class="btn btn-sm btn-outline-warning" type="submit">Open again</button>
            </form>
          <?php else: ?>
            <form method="post" class="d-flex gap-1">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="done">
              <input type="hidden" name="id" value="<?php echo $id; ?>">
              <?php if ($isOpenRow && $hasActions): ?>
                <input class="form-control form-control-sm" name="note" placeholder="What we did" style="width:140px">
              <?php endif; ?>
              <button class="btn btn-sm btn-success" type="submit">Done</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php echo render_secure_delete_button($id, '×', 'Delete this complaint?', 'btn btn-sm btn-link text-danger text-decoration-none'); ?>
      </div>
    </div>
    <?php if ($isOpenRow): ?>
      <p class="cp-msg"><?php echo nl2br($esc($msg)); ?></p>
      <?php if ($lastNote): ?>
        <div class="cp-note">Last note: <?php echo nl2br($esc((string) ($lastNote['action_note'] ?? ''))); ?></div>
      <?php endif; ?>
    <?php else: ?>
      <p class="cp-snip small mb-0 mt-2"><?php echo $esc($snip); ?></p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../footer.php'; ?>
