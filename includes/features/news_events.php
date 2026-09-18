<?php
/**
 * School news and event days for the website and parent dashboard.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'reception');
$page_title = 'News & Events';
$pageTitle = $page_title;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = function_exists('get_csrf_token') ? get_csrf_token() : (string) $_SESSION['csrf_token'];

$csrfOk = static function (string $token) use ($CSRF): bool {
    return $token !== '' && hash_equals($CSRF, $token);
};

$root = dirname(__DIR__, 2);
$uploadDir = $root . '/assets/uploads';

if (!function_exists('ne_save_photo')) {
    function ne_save_photo(string $dir, array &$errors): ?string
    {
        if (empty($_FILES['photo']) || (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo did not upload.';
            return null;
        }
        $tmp = (string) $_FILES['photo']['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false) {
            $errors[] = 'Choose a photo (JPG or PNG).';
            return null;
        }
        if (!in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            $errors[] = 'Use JPG, PNG, GIF or WebP.';
            return null;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $errors[] = 'Photo folder is not writable.';
            return null;
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($_FILES['photo']['name'] ?? 'photo.jpg')));
        $name = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            $errors[] = 'Could not save photo.';
            return null;
        }
        return '/assets/uploads/' . $name;
    }
}

$imgUrl = static function (?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (function_exists('resolve_image_url')) {
        return (string) resolve_image_url($path, '');
    }
    $base = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');
    return $base . '/' . ltrim($path, '/');
};

$slugify = static function (string $title): string {
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
    return trim($s, '-') ?: ('item-' . date('YmdHis'));
};

$hasTable = function_exists('table_exists') && table_exists('news_events');
if (!$hasTable) {
    try {
        $pdo = pdo_connect();
        if ($pdo instanceof PDO) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS news_events (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  type VARCHAR(20) NOT NULL DEFAULT 'event',
                  title VARCHAR(255) NOT NULL,
                  slug VARCHAR(255) DEFAULT NULL,
                  excerpt TEXT,
                  content TEXT,
                  image_path VARCHAR(500) DEFAULT NULL,
                  start_date DATE NULL,
                  end_date DATE NULL,
                  location VARCHAR(255) DEFAULT NULL,
                  is_published TINYINT(1) NOT NULL DEFAULT 1,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at DATETIME NULL,
                  INDEX (type),
                  INDEX (start_date),
                  INDEX (is_published)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $hasTable = true;
        }
    } catch (Throwable $e) {
        $hasTable = function_exists('table_exists') && table_exists('news_events');
    }
}

if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!$csrfOk((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Could not save. Refresh and try again.');
        header('Location: ?');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $old = safe_db_get_one('SELECT image_path FROM news_events WHERE id = :id LIMIT 1', [':id' => $id]);
        safe_db_run('DELETE FROM news_events WHERE id = :id', [':id' => $id]);
        set_flash('success', 'Removed.');
        header('Location: ?');
        exit;
    }

    if ($action === 'hide' && $id > 0) {
        safe_db_run('UPDATE news_events SET is_published = 0, updated_at = NOW() WHERE id = :id', [':id' => $id]);
        set_flash('success', 'Hidden from the website.');
        header('Location: ?');
        exit;
    }
    if ($action === 'show' && $id > 0) {
        safe_db_run('UPDATE news_events SET is_published = 1, updated_at = NOW() WHERE id = :id', [':id' => $id]);
        set_flash('success', 'Visible on the website.');
        header('Location: ?');
        exit;
    }

    if ($action === 'save') {
        $errors = [];
        $type = (($_POST['type'] ?? 'event') === 'news') ? 'news' : 'event';
        $title = trim((string) ($_POST['title'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $start = trim((string) ($_POST['start_date'] ?? ''));
        $end = trim((string) ($_POST['end_date'] ?? ''));
        $place = trim((string) ($_POST['location'] ?? ''));
        if ($title === '') {
            $errors[] = 'Write a title.';
        }
        if ($type === 'event' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $errors[] = 'Pick the event date.';
        }
        if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = '';
        }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = '';
        }
        if ($type === 'news' && $start === '') {
            $start = date('Y-m-d');
        }
        $photo = ne_save_photo($uploadDir, $errors);
        if ($errors !== []) {
            set_flash('error', implode(' ', $errors));
            header('Location: ?' . ($id > 0 ? 'id=' . $id : ''));
            exit;
        }

        $excerpt = mb_strimwidth($details, 0, 160, '…');
        $slug = $slugify($title);
        $image = $photo;
        if ($id > 0) {
            $existing = safe_db_get_one('SELECT image_path FROM news_events WHERE id = :id LIMIT 1', [':id' => $id]);
            if ($image === null) {
                $image = $existing['image_path'] ?? null;
            }
            if (!empty($_POST['remove_photo'])) {
                $image = null;
            }
            $ok = safe_db_run(
                "UPDATE news_events SET type=:type, title=:title, slug=:slug, excerpt=:excerpt, content=:content,
                 image_path=:image_path, start_date=:start_date, end_date=:end_date, location=:location,
                 is_published=1, updated_at=NOW() WHERE id=:id",
                [
                    ':type' => $type,
                    ':title' => $title,
                    ':slug' => $slug,
                    ':excerpt' => $excerpt,
                    ':content' => $details,
                    ':image_path' => $image,
                    ':start_date' => $start !== '' ? $start : null,
                    ':end_date' => $end !== '' ? $end : null,
                    ':location' => $place !== '' ? $place : null,
                    ':id' => $id,
                ]
            );
        } else {
            $ok = safe_db_run(
                "INSERT INTO news_events (type,title,slug,excerpt,content,image_path,start_date,end_date,location,is_published,created_at,updated_at)
                 VALUES (:type,:title,:slug,:excerpt,:content,:image_path,:start_date,:end_date,:location,1,NOW(),NOW())",
                [
                    ':type' => $type,
                    ':title' => $title,
                    ':slug' => $slug,
                    ':excerpt' => $excerpt,
                    ':content' => $details,
                    ':image_path' => $image,
                    ':start_date' => $start !== '' ? $start : null,
                    ':end_date' => $end !== '' ? $end : null,
                    ':location' => $place !== '' ? $place : null,
                ]
            );
        }
        set_flash($ok ? 'success' : 'error', $ok ? 'Saved. Parents and the website can see it.' : 'Save failed.');
        header('Location: ?');
        exit;
    }
}

$edit = null;
$editId = (int) ($_GET['id'] ?? 0);
if ($hasTable && $editId > 0) {
    $edit = safe_db_get_one('SELECT * FROM news_events WHERE id = :id LIMIT 1', [':id' => $editId]);
}

$tab = trim((string) ($_GET['tab'] ?? 'up'));
if (!in_array($tab, ['up', 'past', 'hidden'], true)) {
    $tab = 'up';
}

$rows = $hasTable
    ? (safe_db_get_all('SELECT * FROM news_events ORDER BY COALESCE(start_date, created_at) DESC, id DESC LIMIT 80') ?: [])
    : [];

$today = date('Y-m-d');
$editType = $edit ? (string) ($edit['type'] ?? 'event') : 'event';
$editTitle = $edit ? (string) ($edit['title'] ?? '') : '';
$editDetails = $edit ? (string) (($edit['content'] ?? '') !== '' ? $edit['content'] : ($edit['excerpt'] ?? '')) : '';
$editStart = $edit ? substr((string) ($edit['start_date'] ?? ''), 0, 10) : '';
$editEnd = $edit ? substr((string) ($edit['end_date'] ?? ''), 0, 10) : '';
$editPlace = $edit ? (string) ($edit['location'] ?? '') : '';

require_once __DIR__ . '/../header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">News &amp; events</h1>
    <p class="text-muted mb-0">Annual day, sports, holiday list, or a short school update. Date + title is enough. Photo is optional.</p>
  </div>
</div>

<?php if (!$hasTable): ?>
  <div class="alert alert-danger">Could not create the news table. Ask support to check the database.</div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card card-body mb-4">
  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
  <input type="hidden" name="action" value="save">
  <?php if ($edit): ?>
    <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
  <?php endif; ?>
  <div class="row g-2">
    <div class="col-md-3">
      <label class="form-label">Kind</label>
      <select name="type" class="form-select">
        <option value="event" <?php echo $editType === 'event' ? 'selected' : ''; ?>>Event (has a date)</option>
        <option value="news" <?php echo $editType === 'news' ? 'selected' : ''; ?>>News (update)</option>
      </select>
    </div>
    <div class="col-md-9">
      <label class="form-label">Title</label>
      <input name="title" class="form-control" required maxlength="180" value="<?php echo e($editTitle); ?>" placeholder="e.g. Annual Day">
    </div>
    <div class="col-md-4">
      <label class="form-label">Date</label>
      <input type="date" name="start_date" class="form-control" value="<?php echo e($editStart); ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">End date (if more than one day)</label>
      <input type="date" name="end_date" class="form-control" value="<?php echo e($editEnd); ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Place</label>
      <input name="location" class="form-control" value="<?php echo e($editPlace); ?>" placeholder="School hall">
    </div>
    <div class="col-12">
      <label class="form-label">Details (optional)</label>
      <textarea name="details" class="form-control" rows="3" placeholder="Time, dress, what to bring"><?php echo e($editDetails); ?></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">Photo (optional)</label>
      <input type="file" name="photo" accept="image/*" class="form-control">
      <?php if ($edit && !empty($edit['image_path'])): ?>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="rmPhoto">
          <label class="form-check-label small" for="rmPhoto">Remove current photo</label>
        </div>
      <?php endif; ?>
    </div>
    <div class="col-md-6 d-flex align-items-end gap-2">
      <button class="btn btn-success btn-lg" type="submit"><?php echo $edit ? 'Save' : 'Add & show'; ?></button>
      <?php if ($edit): ?><a class="btn btn-outline-secondary" href="?">Cancel</a><?php endif; ?>
    </div>
  </div>
</form>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm <?php echo $tab === 'up' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?tab=up">Upcoming</a>
  <a class="btn btn-sm <?php echo $tab === 'past' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=past">Past</a>
  <a class="btn btn-sm <?php echo $tab === 'hidden' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?tab=hidden">Hidden</a>
</div>

<?php
$shown = 0;
foreach ($rows as $n):
    $pub = !empty($n['is_published']);
    $start = substr((string) ($n['start_date'] ?? ''), 0, 10);
    $isPast = $start !== '' && $start < $today;
    if ($tab === 'hidden' && $pub) {
        continue;
    }
    if ($tab === 'up' && (!$pub || $isPast)) {
        continue;
    }
    if ($tab === 'past' && (!$pub || !$isPast)) {
        continue;
    }
    $shown++;
    $kind = (($n['type'] ?? '') === 'news') ? 'News' : 'Event';
    $src = $imgUrl($n['image_path'] ?? '');
?>
  <div class="card mb-2">
    <div class="card-body py-3">
      <div class="d-flex gap-3">
        <?php if ($src !== ''): ?>
          <img src="<?php echo e($src); ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px">
        <?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-semibold"><?php echo e((string) ($n['title'] ?? '')); ?></div>
          <div class="small text-muted">
            <?php echo e($kind); ?>
            <?php if ($start !== ''): ?> · <?php echo e($start); ?><?php endif; ?>
            <?php if (!empty($n['end_date'])): ?> – <?php echo e(substr((string) $n['end_date'], 0, 10)); ?><?php endif; ?>
            <?php if (!empty($n['location'])): ?> · <?php echo e((string) $n['location']); ?><?php endif; ?>
            <?php echo $pub ? '' : ' · Hidden'; ?>
          </div>
          <?php if (!empty($n['excerpt'])): ?>
            <div class="small mt-1"><?php echo e(mb_strimwidth((string) $n['excerpt'], 0, 140, '…')); ?></div>
          <?php endif; ?>
        </div>
        <div class="d-flex flex-column gap-1">
          <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo (int) $n['id']; ?>&tab=<?php echo e($tab); ?>">Edit</a>
          <?php if ($pub): ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="hide">
              <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
              <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Hide</button>
            </form>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
              <input type="hidden" name="action" value="show">
              <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
              <button class="btn btn-sm btn-success w-100" type="submit">Show</button>
            </form>
          <?php endif; ?>
          <?php echo render_secure_delete_button((int) $n['id'], 'Delete', 'Delete this item?'); ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($shown === 0): ?>
  <div class="text-muted">Nothing in this list.</div>
<?php endif; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../footer.php';
