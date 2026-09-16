<?php
/**
 * Shared feature: news_events
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$esc = function(string $v) { return e($v); };

/* upload helpers */
/* NOTE: explicit nullable parameter for $errors reference to satisfy Intelephense (P1078) */
function cms_upload_dir(): string|false {
    $appRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $dir = $appRoot . '/assets/uploads';
    if (is_dir($dir) && is_writable($dir)) return $dir;
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0775, true)) { @chmod($dir, 0775); return $dir; }
        return false;
    }
    return is_writable($dir) ? $dir : false;
}

/**
 * Save uploaded image file.
 * @param string $field  file input name
 * @param array|null $errors  reference to array to collect error messages (nullable)
 * @return string|null web path to saved file or null
 */
function save_uploaded_image_field(string $field, ?array &$errors = null): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error code: ' . intval($_FILES[$field]['error']);
        return null;
    }
    $tmp = $_FILES[$field]['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) { $errors[] = 'File is not an image.'; return null; }
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($info[2], $allowed, true)) { $errors[] = 'Only JPG/PNG/GIF/WEBP allowed.'; return null; }
    $dir = cms_upload_dir();
    if ($dir === false) { $errors[] = 'Upload directory not writable.'; return null; }
    $orig = basename((string)($_FILES[$field]['name'] ?? 'upload'));
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    try {
        $uniq = time() . '_' . bin2hex(random_bytes(5)) . '_' . $safe;
    } catch (Throwable $e) {
        // fallback if random_bytes unavailable
        $uniq = time() . '_' . substr(md5(uniqid('', true)), 0, 10) . '_' . $safe;
    }
    $dest = $dir . '/' . $uniq;
    if (!move_uploaded_file($tmp, $dest)) { $errors[] = 'Failed to move uploaded file.'; return null; }
    $webPath = '/assets/uploads/' . $uniq;
    return $webPath;
}

/* -------------------------
   Ensure table exists
   ------------------------- */
if (!table_exists('news_events')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>news_events</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Actions (add/edit/view/get/publish/delete/export)
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = ($_POST['type'] ?? 'news') === 'event' ? 'event' : 'news';
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $excerpt = trim((string)($_POST['excerpt'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $start_date = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $end_date = trim((string)($_POST['end_date'] ?? '')) ?: null;
    $location = trim((string)($_POST['location'] ?? ''));
    $is_published = !empty($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title required.';
    if ($slug === '') $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($title)));

    // handle image
    $imgErr = [];
    $image_path = save_uploaded_image_field('image_path', $imgErr);
    if (!empty($imgErr)) $errors = array_merge($errors, $imgErr);

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO news_events (type,title,slug,excerpt,content,image_path,start_date,end_date,location,is_published,created_at,updated_at)
                           VALUES (:type,:title,:slug,:excerpt,:content,:image_path,:start_date,:end_date,:location,:is_published,NOW(),NOW())",
            [
                ':type'=>$type, ':title'=>$title, ':slug'=>$slug, ':excerpt'=>$excerpt, ':content'=>$content,
                ':image_path'=>$image_path, ':start_date'=>$start_date, ':end_date'=>$end_date, ':location'=>$location,
                ':is_published'=>$is_published
            ]);
        if ($ok) { $messages[] = 'Saved.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $type = ($_POST['type'] ?? 'news') === 'event' ? 'event' : 'news';
    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim((string)($_POST['slug'] ?? ''));
    $excerpt = trim((string)($_POST['excerpt'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $start_date = trim((string)($_POST['start_date'] ?? '')) ?: null;
    $end_date = trim((string)($_POST['end_date'] ?? '')) ?: null;
    $location = trim((string)($_POST['location'] ?? ''));
    $is_published = !empty($_POST['is_published']) ? 1 : 0;
    $remove_image = !empty($_POST['remove_image']) ? true : false;

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($title === '') $errors[] = 'Title required.';
    if ($slug === '') $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($title)));

    // handle new upload if present
    $imgErr = [];
    $new_image_path = save_uploaded_image_field('image_path', $imgErr);
    if (!empty($imgErr)) $errors = array_merge($errors, $imgErr);

    if (empty($errors)) {
        $existing = safe_db_get_one("SELECT image_path FROM news_events WHERE id = :id LIMIT 1", [':id'=>$id]);
        $image_to_store = $existing['image_path'] ?? null;
        if ($new_image_path !== null) {
            if (!empty($image_to_store)) {
                $fs = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($image_to_store, '/');
                if (is_file($fs)) @unlink($fs);
            }
            $image_to_store = $new_image_path;
        } elseif ($remove_image && !empty($image_to_store)) {
            $fs = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($image_to_store, '/');
            if (is_file($fs)) @unlink($fs);
            $image_to_store = null;
        }

        $ok = safe_db_run("UPDATE news_events SET type=:type, title=:title, slug=:slug, excerpt=:excerpt, content=:content, image_path=:image_path, start_date=:start_date, end_date=:end_date, location=:location, is_published=:is_published, updated_at=NOW() WHERE id = :id",
            [
                ':type'=>$type, ':title'=>$title, ':slug'=>$slug, ':excerpt'=>$excerpt, ':content'=>$content,
                ':image_path'=>$image_to_store, ':start_date'=>$start_date, ':end_date'=>$end_date, ':location'=>$location,
                ':is_published'=>$is_published, ':id'=>$id
            ]);
        if ($ok) { $messages[] = 'Updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* PUBLISH / UNPUBLISH */
if ($action === 'publish' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $ok = safe_db_run("UPDATE news_events SET is_published = 1, updated_at = NOW() WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Published.'; else $errors[] = 'Publish failed.';
}
if ($action === 'unpublish' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $ok = safe_db_run("UPDATE news_events SET is_published = 0, updated_at = NOW() WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Unpublished.'; else $errors[] = 'Unpublish failed.';
}

/* DELETE */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId;
    $existing = safe_db_get_one("SELECT image_path FROM news_events WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!empty($existing['image_path'])) {
        $fs = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($existing['image_path'], '/');
        if (is_file($fs)) @unlink($fs);
    }
    $ok = safe_db_run("DELETE FROM news_events WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Deleted.'; else $errors[] = 'Delete failed.';
}

/* GET for edit (JSON) */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    $row = safe_db_get_one("SELECT id,type,title,slug,excerpt,content,image_path,DATE_FORMAT(start_date,'%Y-%m-%d') AS start_date,DATE_FORMAT(end_date,'%Y-%m-%d') AS end_date,location,is_published FROM news_events WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$row]); exit;
}

/* VIEW fragment (improved: absolute image URL) */
if ($action === 'view' && !empty($_GET['id'])) {
    header('Content-Type: text/html; charset=utf-8');
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }

    $row = safe_db_get_one("SELECT ne.*, COALESCE(ne.image_path,'') AS image_path FROM news_events ne WHERE ne.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Not found</div>'; exit; }

    // Build absolute image URL if needed
    $imageUrl = '';
    if (!empty($row['image_path'])) {
        $img = $row['image_path'];
        if (preg_match('/^https?:\\/\\//i', $img)) {
            $imageUrl = $img;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
            $base = rtrim($scheme . '://' . $host, '/');
            $imageUrl = $base . '/' . ltrim($img, '/');
        }
    }

    echo '<div class="p-3">';
    echo '<h4>' . e($row['title']) . '</h4>';
    echo '<div class="text-muted mb-2">' . e($row['type']) . ($row['start_date'] ? ' • ' . e($row['start_date']) : '') . ($row['end_date'] ? ' - ' . e($row['end_date']) : '') . '</div>';
    if (!empty($imageUrl)) {
        echo '<div class="mb-3"><img src="' . e($imageUrl) . '" style="max-width:100%;height:auto;border-radius:6px;display:block;margin:0 auto;"></div>';
    }
    if (!empty($row['excerpt'])) echo '<p><em>' . e($row['excerpt']) . '</em></p>';
    echo '<div>' . nl2br(e($row['content'])) . '</div>';
    if (!empty($row['location'])) echo '<div class="mt-3"><strong>Location:</strong> ' . e($row['location']) . '</div>';
    echo '<div class="mt-3 text-muted">Created: ' . e($row['created_at']) . ' • Updated: ' . e($row['updated_at'] ?? '') . '</div>';
    echo '</div>';
    exit;
}

/* EXPORT CSV */
if ($action === 'export') {
    $where=[]; $params=[];
    if (!empty($_GET['q'])) { $where[] = "(title LIKE :q OR excerpt LIKE :q OR content LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['type'])) { $where[] = "type = :type"; $params[':type'] = $_GET['type']; }
    if (isset($_GET['is_published']) && ($_GET['is_published']==='0' || $_GET['is_published']==='1')) { $where[] = "is_published = :pub"; $params[':pub'] = (int)$_GET['is_published']; }
    if (!empty($_GET['from'])) { $where[] = "created_at >= :from"; $params[':from'] = $_GET['from'].' 00:00:00'; }
    if (!empty($_GET['to'])) { $where[] = "created_at <= :to"; $params[':to'] = $_GET['to'].' 23:59:59'; }
    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
    $rows = safe_db_get_all("SELECT * FROM news_events $whereSql ORDER BY created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=news_events_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','Type','Title','Slug','Excerpt','Content','Image Path','Start Date','End Date','Location','Is Published','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['type'], $r['title'], $r['slug'], preg_replace("/\r\n|\r|\n/"," ", $r['excerpt'] ?? ''),
            preg_replace("/\r\n|\r|\n/"," ", $r['content'] ?? ''), $r['image_path'] ?? '', $r['start_date'] ?? '', $r['end_date'] ?? '',
            $r['location'] ?? '', $r['is_published'] ? '1' : '0', $r['created_at'] ?? '', $r['updated_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; $offset = ($page - 1) * $perPage;
$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(ne.title LIKE :q OR ne.excerpt LIKE :q OR ne.content LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$typeFilter = $_GET['type'] ?? '';
if ($typeFilter !== '' && in_array($typeFilter, ['news','event'], true)) { $where[] = "ne.type = :type"; $params[':type'] = $typeFilter; }
$pubFilter = isset($_GET['is_published']) && ($_GET['is_published']==='0' || $_GET['is_published']==='1') ? (int)$_GET['is_published'] : null;
if ($pubFilter !== null) { $where[] = "ne.is_published = :is_published"; $params[':is_published'] = $pubFilter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "ne.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = trim((string)($_GET['to'] ?? ''));   if ($to   !== '') { $where[] = "ne.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM news_events ne " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$items = [];
try {
    $sql = "SELECT ne.* FROM news_events ne $whereSql ORDER BY ne.created_at DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $items = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int)ceil(max(0, $total) / $perPage);

/* render */
$pageTitle = 'News & Events';
require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button id="btnAddNew" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add News / Event</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo http_build_query($_GET); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label><input name="q" value="<?php echo $esc($qraw); ?>" class="form-control" placeholder="Title, excerpt or content"></div>
      <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select"><option value="">Any</option><option value="news" <?php if(($typeFilter ?? '')==='news') echo 'selected'; ?>>News</option><option value="event" <?php if(($typeFilter ?? '')==='event') echo 'selected'; ?>>Event</option></select>
      </div>
      <div class="col-md-2"><label class="form-label">Published</label>
        <select name="is_published" class="form-select"><option value="">Any</option><option value="1" <?php if($pubFilter===1) echo 'selected'; ?>>Published</option><option value="0" <?php if($pubFilter===0) echo 'selected'; ?>>Unpublished</option></select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input name="from" type="date" value="<?php echo $esc($from); ?>" class="form-control"></div>
      <div class="col-md-2 text-end"><label class="form-label d-block invisible">x</label><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Title / Excerpt</th>
            <th style="width:140px">Dates / Location</th>
            <th style="width:120px">Image</th>
            <th style="width:110px">Published</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($items)): foreach ($items as $it): ?>
          <tr>
            <td><?php echo (int)$it['id']; ?></td>
            <td>
              <div class="fw-semibold"><?php echo $esc($it['title']); ?></div>
              <div class="small text-muted"><?php echo $esc(mb_strimwidth($it['excerpt'] ?? '', 0, 180, '...')); ?></div>
            </td>
            <td>
              <div class="small text-muted"><?php echo $esc($it['start_date'] ?? ''); ?> <?php if(!empty($it['end_date'])) echo ' - '.e($it['end_date']); ?></div>
              <div class="small text-muted"><?php echo $esc($it['location'] ?? ''); ?></div>
            </td>
            <td><?php if (!empty($it['image_path'])): ?><img src="<?php echo $esc($it['image_path']); ?>" class="thumb-small" alt="img"><?php else: ?>—<?php endif; ?></td>
            <td><?php echo $it['is_published'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
            <td>
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$it['id']; ?>">View</button>
              <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$it['id']; ?>">Edit</button>
              <?php if ($it['is_published']): ?>
                <a class="btn btn-sm btn-outline-secondary" href="?action=unpublish&id=<?php echo (int)$it['id']; ?>">Unpublish</a>
              <?php else: ?>
                <a class="btn btn-sm btn-primary" href="?action=publish&id=<?php echo (int)$it['id']; ?>">Publish</a>
              <?php endif; ?>
              <?php echo render_secure_delete_button((int)$it['id'], 'Delete', 'Delete item?'); ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6" class="text-center text-muted">No items found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($items)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$p])); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-lg-wide modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="" enctype="multipart/form-data" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title">Add News / Event</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label">Type</label>
              <select name="type" class="form-select"><option value="news">News</option><option value="event">Event</option></select>
            </div>
            <div class="col-md-9"><label class="form-label">Title *</label><input name="title" id="add_title" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Slug</label><input name="slug" id="add_slug" class="form-control" placeholder="auto-generated"></div>
            <div class="col-md-6"><label class="form-label">Excerpt</label><input name="excerpt" id="add_excerpt" class="form-control"></div>
            <div class="col-12"><label class="form-label">Content</label><textarea name="content" id="add_content" rows="8" class="form-control"></textarea></div>
            <div class="col-md-4"><label class="form-label">Start date</label><input name="start_date" type="date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">End date</label><input name="end_date" type="date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Location</label><input name="location" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Image</label><input type="file" name="image_path" accept="image/*" class="form-control"></div>
            <div class="col-md-3 d-flex align-items-center">
              <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_published" id="add_is_published" value="1"><label class="form-check-label" for="add_is_published">Publish now</label></div>
            </div>
            <div class="col-md-3 d-flex align-items-center">
              <button type="button" id="generateSlugBtn" class="btn btn-outline-secondary">Generate Slug</button>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Save</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-lg-wide modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="" enctype="multipart/form-data" id="editForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header">
          <h5 class="modal-title">Edit Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="editBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-lg-wide modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="viewBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Helper: slugify */
function slugify(s){
  return String(s || '').normalize('NFKD').replace(/[^\w\s-]/g,'').trim().toLowerCase().replace(/[\s_-]+/g,'-').replace(/^-+|-+$/g,'');
}

document.addEventListener('DOMContentLoaded', function(){
  // Reset and focus Add form on show; ensure modal-body scroll resets
  var addModalEl = document.getElementById('addModal');
  if (addModalEl) {
    addModalEl.addEventListener('show.bs.modal', function () {
      var form = document.getElementById('addForm');
      if (form) form.reset();
      var title = document.getElementById('add_title');
      setTimeout(function(){ if (title) title.focus(); }, 250);
      addModalEl.scrollIntoView({behavior:'smooth', block:'center'});
    });
    addModalEl.addEventListener('shown.bs.modal', function () {
      var mb = addModalEl.querySelector('.modal-body');
      if (mb) mb.scrollTop = 0;
    });
  }

  // Generate slug button
  var genBtn = document.getElementById('generateSlugBtn');
  if (genBtn) {
    genBtn.addEventListener('click', function(){
      var t = document.getElementById('add_title');
      var s = document.getElementById('add_slug');
      if (!t || !s) return;
      var val = t.value.trim();
      if (val === '') { alert('Enter title first'); t.focus(); return; }
      s.value = slugify(val);
    });
  }

  // auto-generate slug when title blur if slug empty
  var addTitle = document.getElementById('add_title');
  if (addTitle) {
    addTitle.addEventListener('blur', function(){
      var s = document.getElementById('add_slug');
      if (!s) return;
      if (s.value.trim() === '') s.value = slugify(this.value || '');
    });
  }

  // View modal: fetch fragment and ensure scroll
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget && event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewBody');
      if (!id) { body.innerHTML = '<div class="text-danger p-3">Invalid item id</div>'; return; }
      body.innerHTML = '<div class="text-center text-muted py-3">Loading…</div>';

      var url = new URL(window.location.href);
      url.searchParams.set('action', 'view');
      url.searchParams.set('id', id);

      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function(resp){
          if (!resp.ok) return resp.text().then(function(t){ throw new Error(t || 'Failed to load'); });
          return resp.text();
        })
        .then(function(html){
          body.innerHTML = html;
          var mb = viewModal.querySelector('.modal-body');
          if (mb) mb.scrollTop = 0;
        })
        .catch(function(err){
          console.error('Error loading view fragment:', err);
          body.innerHTML = '<div class="text-danger p-3">Failed to load details.</div>';
        });
    });

    viewModal.addEventListener('shown.bs.modal', function(){
      viewModal.scrollIntoView({behavior:'smooth', block:'center'});
    });
  }

  // Edit modal: fetch JSON and populate form and ensure focus
  var editModal = document.getElementById('editModal');
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editBody');
      body.innerHTML = '<div class="text-center text-muted py-3">Loading…</div>';
      var url = new URL(window.location.href);
      url.searchParams.set('action','get');
      url.searchParams.set('id', id);
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function(resp){
          if (!resp.ok) return resp.json().then(j => Promise.reject(j || 'Failed'));
          return resp.json();
        })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load item.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';
          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-3"><label class="form-label">Type</label><select name="type" id="edit_type" class="form-select"><option value="news">News</option><option value="event">Event</option></select></div>';
          html += '<div class="col-md-9"><label class="form-label">Title</label><input id="edit_title" name="title" class="form-control"></div>';
          html += '<div class="col-md-6"><label class="form-label">Slug</label><input id="edit_slug" name="slug" class="form-control"></div>';
          html += '<div class="col-md-6"><label class="form-label">Excerpt</label><input id="edit_excerpt" name="excerpt" class="form-control"></div>';
          html += '<div class="col-12"><label class="form-label">Content</label><textarea id="edit_content" name="content" rows="8" class="form-control"></textarea></div>';
          html += '<div class="col-md-4"><label class="form-label">Start date</label><input id="edit_start_date" name="start_date" type="date" class="form-control"></div>';
          html += '<div class="col-md-4"><label class="form-label">End date</label><input id="edit_end_date" name="end_date" type="date" class="form-control"></div>';
          html += '<div class="col-md-4"><label class="form-label">Location</label><input id="edit_location" name="location" class="form-control"></div>';
          html += '<div class="col-md-6"><label class="form-label">Current Image</label><div id="currentImage"></div></div>';
          html += '<div class="col-md-6"><label class="form-label">Replace Image</label><input type="file" name="image_path" class="form-control"></div>';
          html += '<div class="col-md-3"><label class="form-check mt-2"><input type="checkbox" name="remove_image" class="form-check-input" id="remove_image"> Remove current image</label></div>';
          html += '<div class="col-md-3 d-flex align-items-center"><div class="form-check"><input id="edit_is_published" name="is_published" class="form-check-input" type="checkbox" value="1"><label class="form-check-label" for="edit_is_published">Published</label></div></div>';
          html += '</div>';
          body.innerHTML = html;

          document.getElementById('edit_type').value = d.type || 'news';
          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_slug').value = d.slug || '';
          document.getElementById('edit_excerpt').value = d.excerpt || '';
          document.getElementById('edit_content').value = d.content || '';
          document.getElementById('edit_start_date').value = d.start_date || '';
          document.getElementById('edit_end_date').value = d.end_date || '';
          document.getElementById('edit_location').value = d.location || '';
          document.getElementById('edit_is_published').checked = !!d.is_published;
          var curImg = document.getElementById('currentImage');
          if (d.image_path) curImg.innerHTML = '<img src="'+d.image_path+'" style="max-width:160px;border-radius:6px">';
          else curImg.innerHTML = '<div class="text-muted">No image</div>';
          setTimeout(function(){ var t = document.getElementById('edit_title'); if (t) t.focus(); }, 250);
        })
        .catch(function(err){ console.error('Edit load error', err); body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
    editModal.addEventListener('shown.bs.modal', function(){ editModal.scrollIntoView({behavior:'smooth', block:'center'}); var mb = editModal.querySelector('.modal-body'); if (mb) mb.scrollTop = 0; });
  }

  // Ensure clicking Add button brings modal into view on mobile
  var btnAdd = document.getElementById('btnAddNew');
  if (btnAdd) btnAdd.addEventListener('click', function(){ setTimeout(function(){ var addModal = document.getElementById('addModal'); if (addModal) addModal.scrollIntoView({behavior:'smooth', block:'center'}); }, 250); });
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>