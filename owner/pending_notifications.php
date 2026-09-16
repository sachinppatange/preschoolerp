<?php
/**
 * owner/pending_notifications.php
 *
 * Manage notifications (tasks + alerts) stored in `notifications` table.
 *
 * Table columns (expected):
 *  id, school_id, type, title, message, assigned_to, due_date, priority, status, level, link, is_read, created_at, updated_at
 *
 * Features:
 *  - List with filters (type, status, priority, level, read, date range, q)
 *  - Add (modal), Edit (modal), View (modal fragment)
 *  - Assign (quick form), change status, mark read/unread, delete
 *  - Export CSV
 *
 * Place at: /pioneerplayschool01/owner/pending_notifications.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

/* Optional includes */
$esc = function(string $v) { return e($v); };

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

/* Ensure notifications table exists */
if (!table_exists('notifications')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>notifications</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* -------------------------
   Action handling
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

$types = ['task','alert'];
$priorityOptions = ['low','medium','high'];
$statusOptions = ['pending','in_progress','done'];
$levelOptions = ['info','warning','critical'];

/* ADD (POST) */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = in_array($_POST['type'] ?? 'task', $types, true) ? $_POST['type'] : 'task';
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $priority = in_array($_POST['priority'] ?? 'medium', $priorityOptions, true) ? $_POST['priority'] : 'medium';
    $status = in_array($_POST['status'] ?? 'pending', $statusOptions, true) ? $_POST['status'] : 'pending';
    $level = in_array($_POST['level'] ?? 'info', $levelOptions, true) ? $_POST['level'] : 'info';
    $link = trim((string)($_POST['link'] ?? '')) ?: null;
    $is_read = !empty($_POST['is_read']) ? 1 : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;

    if ($type === 'task' && $title === '') $errors[] = 'Title required for tasks.';
    if ($message === '') $errors[] = 'Message required.';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO notifications (school_id,type,title,message,assigned_to,due_date,priority,status,level,link,is_read,created_at,updated_at)
                           VALUES (:school_id,:type,:title,:message,:assigned_to,:due_date,:priority,:status,:level,:link,:is_read,NOW(),NOW())",
            [
                ':school_id'=>$school_id, ':type'=>$type, ':title'=>$title ?: null, ':message'=>$message,
                ':assigned_to'=>$assigned_to, ':due_date'=>$due_date, ':priority'=>$priority, ':status'=>$status,
                ':level'=>$level, ':link'=>$link, ':is_read'=>$is_read
            ]);
        if ($ok) { $messages[] = ucfirst($type) . ' added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $type = in_array($_POST['type'] ?? 'task', $types, true) ? $_POST['type'] : 'task';
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $priority = in_array($_POST['priority'] ?? 'medium', $priorityOptions, true) ? $_POST['priority'] : 'medium';
    $status = in_array($_POST['status'] ?? 'pending', $statusOptions, true) ? $_POST['status'] : 'pending';
    $level = in_array($_POST['level'] ?? 'info', $levelOptions, true) ? $_POST['level'] : 'info';
    $link = trim((string)($_POST['link'] ?? '')) ?: null;
    $is_read = !empty($_POST['is_read']) ? 1 : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($type === 'task' && $title === '') $errors[] = 'Title required for tasks.';
    if ($message === '') $errors[] = 'Message required.';

    if (empty($errors)) {
        $ok = safe_db_run("UPDATE notifications SET school_id=:school_id, type=:type, title=:title, message=:message, assigned_to=:assigned_to, due_date=:due_date, priority=:priority, status=:status, level=:level, link=:link, is_read=:is_read, updated_at=NOW() WHERE id = :id",
            [
                ':school_id'=>$school_id, ':type'=>$type, ':title'=>$title ?: null, ':message'=>$message,
                ':assigned_to'=>$assigned_to, ':due_date'=>$due_date, ':priority'=>$priority, ':status'=>$status,
                ':level'=>$level, ':link'=>$link, ':is_read'=>$is_read, ':id'=>$id
            ]);
        if ($ok) { $messages[] = ucfirst($type) . ' updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* VIEW (modal HTML fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    $row = safe_db_get_one("SELECT n.*, COALESCE(u.name,'') AS assignee_name FROM notifications n LEFT JOIN users u ON u.id = n.assigned_to WHERE n.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Item not found</div>'; exit; }

    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Type</dt><dd class="col-sm-9">'.e($row['type']).'</dd>';
    if (!empty($row['title'])) echo '<dt class="col-sm-3">Title</dt><dd class="col-sm-9">'.e($row['title']).'</dd>';
    echo '<dt class="col-sm-3">Message</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['message']).'</pre></dd>';
    echo '<dt class="col-sm-3">Assigned</dt><dd class="col-sm-9">'.($row['assignee_name'] ? e($row['assignee_name']) : 'Unassigned').'</dd>';
    if (!empty($row['due_date'])) echo '<dt class="col-sm-3">Due</dt><dd class="col-sm-9">'.e($row['due_date']).'</dd>';
    if (!empty($row['priority'])) echo '<dt class="col-sm-3">Priority</dt><dd class="col-sm-9">'.e($row['priority']).'</dd>';
    if (!empty($row['status'])) echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.e($row['status']).'</dd>';
    if (!empty($row['level'])) echo '<dt class="col-sm-3">Level</dt><dd class="col-sm-9">'.e($row['level']).'</dd>';
    if (!empty($row['link'])) echo '<dt class="col-sm-3">Link</dt><dd class="col-sm-9"><a href="'.e($row['link']).'" target="_blank">Open</a></dd>';
    echo '<dt class="col-sm-3">Read</dt><dd class="col-sm-9">'.($row['is_read'] ? 'Yes' : 'No').'</dd>';
    echo '<dt class="col-sm-3">Created</dt><dd class="col-sm-9">'.e($row['created_at']).'</dd>';
    echo '<dt class="col-sm-3">Updated</dt><dd class="col-sm-9">'.e($row['updated_at'] ?? '—').'</dd>';
    echo '</dl>';
    exit;
}

/* GET (for edit modal) returns JSON */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, type, title, message, assigned_to, DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date, priority, status, level, link, is_read FROM notifications WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]); exit;
}

/* ASSIGN quick (POST) */
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    if ($id <= 0) $errors[] = 'Invalid id'; else {
        $ok = safe_db_run("UPDATE notifications SET assigned_to = :a, updated_at = NOW() WHERE id = :id", [':a'=>$assigned_to, ':id'=>$id]);
        if ($ok) $messages[] = 'Assignee updated.'; else $errors[] = 'Assign failed.';
    }
}

/* STATUS quick change (GET) */
if ($action === 'status' && !empty($_GET['id']) && !empty($_GET['to'])) {
    $id = (int)$_GET['id']; $to = in_array($_GET['to'], $statusOptions, true) ? $_GET['to'] : null;
    if ($to === null) $errors[] = 'Invalid status'; else {
        $ok = safe_db_run("UPDATE notifications SET status = :s, updated_at = NOW() WHERE id = :id", [':s'=>$to, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.'; else $errors[] = 'Status update failed.';
    }
}

/* MARK read/unread (GET) */
if ($action === 'mark' && !empty($_GET['id']) && isset($_GET['to'])) {
    $id = (int)$_GET['id']; $to = ($_GET['to'] === '1') ? 1 : 0;
    $ok = safe_db_run("UPDATE notifications SET is_read = :r, updated_at = NOW() WHERE id = :id", [':r'=>$to, ':id'=>$id]);
    if ($ok) $messages[] = 'Updated.'; else $errors[] = 'Update failed.';
}

/* DELETE */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId; $ok = safe_db_run("DELETE FROM notifications WHERE id = :id", [':id'=>$id]);
    if ($ok) $messages[] = 'Deleted.'; else $errors[] = 'Delete failed.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where=[]; $params=[];
    if (!empty($_GET['q'])) { $where[] = "(title LIKE :q OR message LIKE :q OR link LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['type']) && in_array($_GET['type'], $types, true)) { $where[] = "type = :type"; $params[':type'] = $_GET['type']; }
    if (!empty($_GET['status']) && in_array($_GET['status'], $statusOptions, true)) { $where[] = "status = :status"; $params[':status'] = $_GET['status']; }
    if (!empty($_GET['priority']) && in_array($_GET['priority'], $priorityOptions, true)) { $where[] = "priority = :priority"; $params[':priority'] = $_GET['priority']; }
    if (!empty($_GET['level']) && in_array($_GET['level'], $levelOptions, true)) { $where[] = "level = :level"; $params[':level'] = $_GET['level']; }
    if (isset($_GET['is_read']) && ($_GET['is_read']==='0' || $_GET['is_read']==='1')) { $where[] = "is_read = :is_read"; $params[':is_read'] = (int)$_GET['is_read']; }
    if (!empty($_GET['from'])) { $where[] = "created_at >= :from"; $params[':from'] = $_GET['from'].' 00:00:00'; }
    if (!empty($_GET['to'])) { $where[] = "created_at <= :to"; $params[':to'] = $_GET['to'].' 23:59:59'; }
    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    $rows = safe_db_get_all("SELECT n.*, COALESCE(u.name,'') AS assignee_name FROM notifications n LEFT JOIN users u ON u.id = n.assigned_to $whereSql ORDER BY n.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=notifications_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','Type','Title','Message','Assigned To','Due Date','Priority','Status','Level','Link','Is Read','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['type'], $r['title'] ?? '', preg_replace("/\r\n|\r|\n/"," ", $r['message'] ?? ''),
            $r['assignee_name'] ?? '', $r['due_date'] ?? '', $r['priority'] ?? '', $r['status'] ?? '',
            $r['level'] ?? '', $r['link'] ?? '', $r['is_read'] ? '1' : '0', $r['created_at'] ?? '', $r['updated_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination (list)
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;
$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(n.title LIKE :q OR n.message LIKE :q OR n.link LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
$typeFilter = $_GET['type'] ?? '';
if ($typeFilter !== '' && in_array($typeFilter, $types, true)) { $where[] = "n.type = :type"; $params[':type'] = $typeFilter; }
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && in_array($statusFilter, $statusOptions, true)) { $where[] = "n.status = :status"; $params[':status'] = $statusFilter; }
$priorityFilter = $_GET['priority'] ?? '';
if ($priorityFilter !== '' && in_array($priorityFilter, $priorityOptions, true)) { $where[] = "n.priority = :priority"; $params[':priority'] = $priorityFilter; }
$levelFilter = $_GET['level'] ?? '';
if ($levelFilter !== '' && in_array($levelFilter, $levelOptions, true)) { $where[] = "n.level = :level"; $params[':level'] = $levelFilter; }
$is_read_filter = isset($_GET['is_read']) && ($_GET['is_read']==='0' || $_GET['is_read']==='1') ? (int)$_GET['is_read'] : null;
if ($is_read_filter !== null) { $where[] = "n.is_read = :is_read"; $params[':is_read'] = $is_read_filter; }
$from = trim((string)($_GET['from'] ?? '')); if ($from !== '') { $where[] = "n.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = trim((string)($_GET['to'] ?? ''));   if ($to   !== '') { $where[] = "n.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM notifications n " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$notifications = [];
try {
    $sql = "SELECT n.*, COALESCE(u.name,'') AS assignee_name FROM notifications n LEFT JOIN users u ON u.id = n.assigned_to $whereSql ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $notifications = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

/* Data for forms */
$staffList = table_exists('users') ? safe_db_get_all("SELECT id, name FROM users ORDER BY name ASC") : [];
$totalPages = (int)ceil(max(0, $total) / $perPage);

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* Render header/footer if present */
$pageTitle = 'Pending Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Add Notification</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Title, message or link"></div>

      <div class="col-md-2"><label class="form-label">Type</label>
        <select name="type" class="form-select"><option value="">Any</option><?php foreach ($types as $t): ?><option value="<?php echo $esc($t); ?>" <?php if(($typeFilter ?? '')===$t) echo 'selected'; ?>><?php echo $esc(ucfirst($t)); ?></option><?php endforeach; ?></select>
      </div>

      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">Any</option><?php foreach ($statusOptions as $s): ?><option value="<?php echo $esc($s); ?>" <?php if(($statusFilter ?? '')===$s) echo 'selected'; ?>><?php echo $esc(ucfirst(str_replace('_',' ',$s))); ?></option><?php endforeach; ?></select>
      </div>

      <div class="col-md-2"><label class="form-label">Priority</label>
        <select name="priority" class="form-select"><option value="">Any</option><?php foreach ($priorityOptions as $p): ?><option value="<?php echo $esc($p); ?>" <?php if(($priorityFilter ?? '')===$p) echo 'selected'; ?>><?php echo $esc(ucfirst($p)); ?></option><?php endforeach; ?></select>
      </div>

      <div class="col-md-1"><label class="form-label">Level</label>
        <select name="level" class="form-select"><option value="">Any</option><?php foreach ($levelOptions as $l): ?><option value="<?php echo $esc($l); ?>" <?php if(($levelFilter ?? '')===$l) echo 'selected'; ?>><?php echo $esc(ucfirst($l)); ?></option><?php endforeach; ?></select>
      </div>

      <div class="col-md-1"><label class="form-label">Read</label>
        <select name="is_read" class="form-select"><option value="">Any</option><option value="0" <?php if($is_read_filter===0) echo 'selected'; ?>>Unread</option><option value="1" <?php if($is_read_filter===1) echo 'selected'; ?>>Read</option></select>
      </div>

      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo $esc($from); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo $esc($to); ?>"></div>

      <div class="col-md-2 text-end"><button class="btn btn-primary">Filter</button></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Type / Title / Message</th>
            <th style="width:220px">Assigned / Due</th>
            <th style="width:160px">Priority / Status</th>
            <th style="width:120px">Level / Read</th>
            <th style="width:160px">Created</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($notifications)): foreach ($notifications as $n): ?>
            <tr>
              <td><?php echo (int)$n['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc(($n['type']==='task' ? ($n['title'] ?? '') : ($n['title'] ?: '[Alert]'))); ?></div>
                <div class="small text-muted"><?php echo $esc(mb_strimwidth(strip_tags($n['message'] ?? ''), 0, 200, '...')); ?></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc($n['assignee_name'] ?? 'Unassigned'); ?></div>
                <div class="small text-muted">Due: <?php echo $esc($n['due_date'] ?? '—'); ?></div>
              </td>
              <td>
                <div><?php echo $esc(ucfirst($n['priority'] ?? '')); ?></div>
                <div class="mt-1"><span class="badge <?php echo ($n['status']==='done' ? 'bg-success' : ($n['status']==='in_progress' ? 'bg-warning text-dark' : 'bg-primary')); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$n['status'] ?? ''))); ?></span></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc(ucfirst($n['level'] ?? '')); ?></div>
                <div class="mt-1"><?php echo $n['is_read'] ? '<span class="badge bg-secondary">Read</span>' : '<span class="badge bg-info text-dark">Unread</span>'; ?></div>
              </td>
              <td><?php echo $esc(substr($n['created_at'] ?? '', 0, 16)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$n['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?php echo (int)$n['id']; ?>">Edit</button>

                <form method="post" class="d-inline-block ms-1" style="display:inline;">
                  <input type="hidden" name="action" value="assign">
                  <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                  <select name="assigned_to" class="form-select form-select-sm d-inline-block" style="width:auto; display:inline-block;">
                    <option value="">Unassigned</option>
                    <?php foreach ($staffList as $s): ?>
                      <option value="<?php echo (int)$s['id']; ?>" <?php if((int)($n['assigned_to'] ?? 0) === (int)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                </form>

                <div class="mt-1">
                  <?php foreach ($statusOptions as $opt): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?action=status&id=<?php echo (int)$n['id']; ?>&to=<?php echo $esc($opt); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$opt))); ?></a>
                  <?php endforeach; ?>
                </div>

                <?php if ($n['is_read']): ?>
                  <a class="btn btn-sm btn-outline-primary mt-1" href="?action=mark&id=<?php echo (int)$n['id']; ?>&to=0">Mark unread</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-success mt-1" href="?action=mark&id=<?php echo (int)$n['id']; ?>&to=1">Mark read</a>
                <?php endif; ?>

                <?php echo render_secure_delete_button((int)$n['id'], 'Delete', 'Delete?'); ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No notifications found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($notifications)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="" id="addForm">
        <input type="hidden" name="action" value="add">
        <div class="modal-header"><h5 class="modal-title">Add Notification</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <option value="task">Task</option>
                <option value="alert">Alert</option>
              </select>
            </div>

            <div class="col-md-9">
              <label class="form-label">Title (tasks)</label>
              <input name="title" class="form-control" placeholder="Title for tasks (optional for alerts)">
            </div>

            <div class="col-12">
              <label class="form-label">Message *</label>
              <textarea name="message" rows="4" class="form-control" required></textarea>
            </div>

            <div class="col-md-4">
              <label class="form-label">Assign to</label>
              <select name="assigned_to" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($staffList as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option><?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2"><label class="form-label">Due date</label><input name="due_date" type="date" class="form-control"></div>

            <div class="col-md-2"><label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <?php foreach ($priorityOptions as $p): ?><option value="<?php echo $esc($p); ?>"><?php echo $esc(ucfirst($p)); ?></option><?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2"><label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach ($statusOptions as $s): ?><option value="<?php echo $esc($s); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$s))); ?></option><?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2"><label class="form-label">Level</label>
              <select name="level" class="form-select">
                <?php foreach ($levelOptions as $l): ?><option value="<?php echo $esc($l); ?>"><?php echo $esc(ucfirst($l)); ?></option><?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6"><label class="form-label">Link (optional)</label><input name="link" class="form-control"></div>
            <div class="col-md-3"><label class="form-check mt-2"><input type="checkbox" name="is_read" class="form-check-input"> Mark as read</label></div>
            <div class="col-md-3"><label class="form-label">School ID</label><input name="school_id" class="form-control" value="1"></div>
          </div>
        </div>

        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="" id="editForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Notification</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal: load HTML fragment
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget && event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewBody');
      if (!id) { body.innerHTML = '<div class="text-danger p-3">Invalid id</div>'; return; }
      body.innerHTML = '<div class="text-center text-muted py-3">Loading…</div>';
      var url = new URL(window.location.href);
      url.searchParams.set('action','view');
      url.searchParams.set('id', id);
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : resp.text().then(t => Promise.reject(t || 'Failed')); })
        .then(function(html){ body.innerHTML = html; var mb = viewModal.querySelector('.modal-body'); if (mb) mb.scrollTop = 0; })
        .catch(function(err){ console.error('View load error', err); body.innerHTML = '<div class="text-danger p-3">Failed to load details.</div>'; });
    });
  }

  // Edit modal: fetch JSON and populate fields
  var editModal = document.getElementById('editModal');
  var staff = <?php echo json_encode($staffList, JSON_UNESCAPED_UNICODE); ?>;
  var priorityOptions = <?php echo json_encode($priorityOptions); ?>;
  var statusOptions = <?php echo json_encode($statusOptions); ?>;
  var levelOptions = <?php echo json_encode($levelOptions); ?>;
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editBody');
      body.innerHTML = '<div class="text-center text-muted py-3">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : resp.json().then(j => Promise.reject(j || 'Failed')); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';

          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-3"><label class="form-label">Type</label><select name="type" id="edit_type" class="form-select"><option value="task">Task</option><option value="alert">Alert</option></select></div>';
          html += '<div class="col-md-9"><label class="form-label">Title (tasks)</label><input id="edit_title" name="title" class="form-control"></div>';
          html += '<div class="col-12"><label class="form-label">Message</label><textarea id="edit_message" name="message" rows="4" class="form-control"></textarea></div>';
          html += '<div class="col-md-4"><label class="form-label">Assign to</label><select id="edit_assigned_to" name="assigned_to" class="form-select"><option value="">Unassigned</option>';
          staff.forEach(function(s){ html += '<option value="'+s.id+'">'+s.name+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-2"><label class="form-label">Due date</label><input id="edit_due_date" name="due_date" type="date" class="form-control"></div>';
          html += '<div class="col-md-2"><label class="form-label">Priority</label><select id="edit_priority" name="priority" class="form-select">';
          priorityOptions.forEach(function(p){ html += '<option value="'+p+'">'+p.charAt(0).toUpperCase()+p.slice(1)+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-2"><label class="form-label">Status</label><select id="edit_status" name="status" class="form-select">';
          statusOptions.forEach(function(s){ html += '<option value="'+s+'">'+s.replace('_',' ').replace(/\b\w/g,l=>l.toUpperCase())+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-2"><label class="form-label">Level</label><select id="edit_level" name="level" class="form-select">';
          levelOptions.forEach(function(l){ html += '<option value="'+l+'">'+l.charAt(0).toUpperCase()+l.slice(1)+'</option>'; });
          html += '</select></div>';
          html += '<div class="col-md-6"><label class="form-label">Link</label><input id="edit_link" name="link" class="form-control"></div>';
          html += '<div class="col-md-2"><label class="form-check mt-2"><input id="edit_is_read" name="is_read" type="checkbox" class="form-check-input" value="1"> Read</label></div>';
          html += '<div class="col-md-2"><label class="form-label">School ID</label><input id="edit_school_id" name="school_id" class="form-control"></div>';
          html += '</div>';
          body.innerHTML = html;

          document.getElementById('edit_type').value = d.type || 'task';
          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_message').value = d.message || '';
          document.getElementById('edit_assigned_to').value = d.assigned_to || '';
          document.getElementById('edit_due_date').value = d.due_date || '';
          document.getElementById('edit_priority').value = d.priority || 'medium';
          document.getElementById('edit_status').value = d.status || 'pending';
          document.getElementById('edit_level').value = d.level || 'info';
          document.getElementById('edit_link').value = d.link || '';
          document.getElementById('edit_is_read').checked = !!d.is_read;
          document.getElementById('edit_school_id').value = d.school_id || 1;
        })
        .catch(function(err){ console.error('Edit load error', err); body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }

});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>