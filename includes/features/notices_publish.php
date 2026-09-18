<?php
/**
 * Publish a school notice parents can see. One form, send now.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'reception');
$page_title = 'Notices';
$pageTitle = $page_title;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = function_exists('get_csrf_token') ? get_csrf_token() : (string) $_SESSION['csrf_token'];
$userId = (int) (auth_user_id() ?? 0);

$hasTable = function_exists('table_exists') && table_exists('notices');
if (!$hasTable) {
    try {
        $pdo = pdo_connect();
        if ($pdo instanceof PDO) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS notices (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  school_id INT NULL,
                  class_id INT NULL,
                  title VARCHAR(255) NOT NULL,
                  message TEXT,
                  body TEXT,
                  audience VARCHAR(50) DEFAULT 'parents',
                  published_by INT NULL,
                  created_by INT NULL,
                  published_at DATETIME NULL,
                  expires_at DATE NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NULL,
                  INDEX (published_at),
                  INDEX (class_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $hasTable = true;
        }
    } catch (Throwable $e) {
        $hasTable = function_exists('table_exists') && table_exists('notices');
    }
}

$col = static function (string $name) use ($hasTable): bool {
    return $hasTable && function_exists('column_exists') && column_exists('notices', $name);
};

$csrfOk = static function (string $token) use ($CSRF): bool {
    return $token !== '' && hash_equals($CSRF, $token);
};

$classes = (function_exists('table_exists') && table_exists('classes'))
    ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [])
    : [];

$saveNotice = static function (array $data, int $id) use ($col, $userId): bool {
    $title = $data['title'];
    $text = $data['message'];
    $classId = $data['class_id'];
    $expires = $data['expires_at'];
    $publishNow = $data['publish'];

    $fields = [];
    $params = [];
    if ($col('title')) {
        $fields['title'] = $title;
    }
    if ($col('message')) {
        $fields['message'] = $text;
    }
    if ($col('body')) {
        $fields['body'] = $text;
    }
    if ($col('description') && !$col('body') && !$col('message')) {
        $fields['description'] = $text;
    }
    if ($col('audience')) {
        $fields['audience'] = 'parents';
    }
    if ($col('class_id')) {
        $fields['class_id'] = $classId > 0 ? $classId : null;
    }
    if ($col('school_id')) {
        $fields['school_id'] = 1;
    }
    if ($col('expires_at')) {
        $fields['expires_at'] = $expires !== '' ? $expires : null;
    }
    if ($publishNow) {
        if ($col('published_at')) {
            $fields['published_at'] = date('Y-m-d H:i:s');
        }
        if ($col('published_by')) {
            $fields['published_by'] = $userId > 0 ? $userId : null;
        }
    }
    if ($id <= 0 && $col('created_by')) {
        $fields['created_by'] = $userId > 0 ? $userId : null;
    }
    if ($id > 0 && $col('updated_at')) {
        $fields['updated_at'] = date('Y-m-d H:i:s');
    }

    if ($fields === []) {
        return false;
    }

    if ($id > 0) {
        $set = [];
        foreach ($fields as $k => $v) {
            $set[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        $params[':id'] = $id;
        return (bool) safe_db_run('UPDATE notices SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
    }

    $cols = array_keys($fields);
    $ph = [];
    foreach ($cols as $k) {
        $ph[] = ':' . $k;
        $params[':' . $k] = $fields[$k];
    }
    if ($col('created_at') && !isset($fields['created_at'])) {
        return (bool) safe_db_run(
            'INSERT INTO notices (' . implode(',', $cols) . ', created_at) VALUES (' . implode(',', $ph) . ', NOW())',
            $params
        );
    }
    return (bool) safe_db_run(
        'INSERT INTO notices (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')',
        $params
    );
};

if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!$csrfOk($token)) {
        set_flash('error', 'Could not save. Refresh and try again.');
        header('Location: ?');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        safe_db_run('DELETE FROM notices WHERE id = :id', [':id' => $id]);
        set_flash('success', 'Notice removed.');
        header('Location: ?');
        exit;
    }

    if ($action === 'hide' && $id > 0 && $col('published_at')) {
        safe_db_run('UPDATE notices SET published_at = NULL WHERE id = :id', [':id' => $id]);
        set_flash('success', 'Hidden from parents.');
        header('Location: ?');
        exit;
    }

    if ($action === 'show' && $id > 0 && $col('published_at')) {
        $extra = '';
        $params = [':id' => $id];
        if ($col('published_by') && $userId > 0) {
            $extra = ', published_by = :by';
            $params[':by'] = $userId;
        }
        safe_db_run('UPDATE notices SET published_at = NOW()' . $extra . ' WHERE id = :id', $params);
        set_flash('success', 'Parents can see this notice now.');
        header('Location: ?');
        exit;
    }

    if ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $classId = (int) ($_POST['class_id'] ?? 0);
        $expires = trim((string) ($_POST['expires_at'] ?? ''));
        $sendNow = !empty($_POST['send_now']) || $id <= 0;
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            $expires = '';
        }
        if ($title === '' || $message === '') {
            set_flash('error', 'Write a title and a message.');
            header('Location: ?' . ($id > 0 ? 'id=' . $id : ''));
            exit;
        }
        $ok = $saveNotice([
            'title' => $title,
            'message' => $message,
            'class_id' => $classId,
            'expires_at' => $expires,
            'publish' => $sendNow,
        ], $id);
        set_flash($ok ? 'success' : 'error', $ok ? ($sendNow ? 'Notice sent to parents.' : 'Draft saved.') : 'Save failed.');
        header('Location: ?');
        exit;
    }
}

$edit = null;
$editId = (int) ($_GET['id'] ?? 0);
if ($hasTable && $editId > 0) {
    $edit = safe_db_get_one('SELECT * FROM notices WHERE id = :id LIMIT 1', [':id' => $editId]);
}

$textOf = static function (array $n): string {
    foreach (['message', 'body', 'description', 'content'] as $k) {
        if (isset($n[$k]) && trim((string) $n[$k]) !== '') {
            return (string) $n[$k];
        }
    }
    return '';
};

$tab = trim((string) ($_GET['tab'] ?? 'live'));
if (!in_array($tab, ['live', 'hidden', 'all'], true)) {
    $tab = 'live';
}

$rows = $hasTable
    ? (safe_db_get_all('SELECT * FROM notices ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 80') ?: [])
    : [];

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$isLive = static function (array $n) use ($now, $today): bool {
    $pub = $n['published_at'] ?? null;
    if ($pub === null || $pub === '') {
        return false;
    }
    $exp = $n['expires_at'] ?? null;
    if ($exp !== null && $exp !== '' && substr((string) $exp, 0, 10) < $today) {
        return false;
    }
    return true;
};

$className = [];
foreach ($classes as $c) {
    $className[(int) $c['id']] = (string) $c['name'];
}

require_once __DIR__ . '/../header.php';

$editTitle = $edit ? (string) ($edit['title'] ?? '') : '';
$editText = $edit ? $textOf($edit) : '';
$editClass = $edit ? (int) ($edit['class_id'] ?? 0) : 0;
$editExp = $edit ? substr((string) ($edit['expires_at'] ?? ''), 0, 10) : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Parent notices</h1>
    <p class="text-muted mb-0">Write once. Parents see it in their app. Holiday, holiday homework, or “school closed tomorrow”.</p>
  </div>
</div>

<?php if (!$hasTable): ?>
  <div class="alert alert-danger">Could not create the notices table. Ask support to check the database.</div>
<?php else: ?>
<form method="post" class="card card-body mb-4">
  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
  <input type="hidden" name="action" value="save">
  <?php if ($edit): ?>
    <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
    <p class="small text-muted mb-2">Editing notice. Tick send if it is still a draft.</p>
  <?php endif; ?>
  <div class="row g-2">
    <div class="col-md-8">
      <label class="form-label">Title</label>
      <input name="title" class="form-control" required maxlength="180" value="<?php echo e($editTitle); ?>" placeholder="e.g. Holiday on Friday">
    </div>
    <div class="col-md-4">
      <label class="form-label">Who</label>
      <select name="class_id" class="form-select">
        <option value="0">All parents</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int) $c['id']; ?>" <?php echo $editClass === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e((string) $c['name']); ?> only</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label">Message</label>
      <textarea name="message" class="form-control" rows="5" required placeholder="Short and clear for parents"><?php echo e($editText); ?></textarea>
    </div>
    <div class="col-md-4">
      <label class="form-label">Hide after (optional)</label>
      <input type="date" name="expires_at" class="form-control" value="<?php echo e($editExp); ?>">
    </div>
    <div class="col-md-8 d-flex align-items-end gap-2 flex-wrap">
      <?php if (!$edit || empty($edit['published_at'])): ?>
        <input type="hidden" name="send_now" value="1">
        <button class="btn btn-success btn-lg" type="submit">Send to parents</button>
      <?php else: ?>
        <button class="btn btn-primary" type="submit">Save changes</button>
      <?php endif; ?>
      <?php if ($edit): ?>
        <a class="btn btn-outline-secondary" href="?">Cancel</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm <?php echo $tab === 'live' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?tab=live">Showing now</a>
  <a class="btn btn-sm <?php echo $tab === 'hidden' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=hidden">Hidden / draft</a>
  <a class="btn btn-sm <?php echo $tab === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=all">All</a>
</div>

<?php
$shown = 0;
foreach ($rows as $n):
    $live = $isLive($n);
    if ($tab === 'live' && !$live) {
        continue;
    }
    if ($tab === 'hidden' && $live) {
        continue;
    }
    $shown++;
    $cid = (int) ($n['class_id'] ?? 0);
    $who = $cid > 0 ? ($className[$cid] ?? 'One class') : 'All parents';
    $txt = $textOf($n);
?>
  <div class="card mb-2">
    <div class="card-body py-3">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <div class="fw-semibold"><?php echo e((string) ($n['title'] ?? '')); ?></div>
          <div class="small text-muted mb-1"><?php echo e($who); ?>
            · <?php echo $live ? 'Parents can see this' : 'Hidden'; ?>
            <?php if (!empty($n['expires_at'])): ?> · until <?php echo e(substr((string) $n['expires_at'], 0, 10)); ?><?php endif; ?>
          </div>
          <div style="white-space:pre-wrap"><?php echo e(mb_strimwidth($txt, 0, 280, '…')); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-1 align-items-start">
          <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo (int) $n['id']; ?>&tab=<?php echo e($tab); ?>">Edit</a>
          <?php if ($live): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="hide">
              <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
              <button class="btn btn-sm btn-outline-secondary" type="submit">Hide</button>
            </form>
          <?php else: ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="show">
              <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
              <button class="btn btn-sm btn-success" type="submit">Show</button>
            </form>
          <?php endif; ?>
          <?php echo render_secure_delete_button((int) $n['id'], 'Delete', 'Delete this notice?'); ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($shown === 0): ?>
  <div class="text-muted">No notices in this list yet.</div>
<?php endif; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../footer.php';
