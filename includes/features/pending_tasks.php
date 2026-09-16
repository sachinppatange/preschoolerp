<?php
/**
 * Shared feature: pending_tasks
 * Loaded via feature_run() after panel_bootstrap().
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string)($cfg['panel'] ?? 'owner');

$taskScope = (string)($cfg['task_scope'] ?? 'all');
$showAssignUi = (bool)($cfg['show_assign_ui'] ?? ($taskScope !== 'assigned_to_me'));
$allowAssignAction = (bool)($cfg['allow_assign_action'] ?? ($taskScope !== 'assigned_to_me'));
$allowDeleteUi = (bool)($cfg['allow_delete_ui'] ?? ($taskScope !== 'assigned_to_me'));
$enforceOwnership = (bool)($cfg['enforce_ownership'] ?? ($taskScope === 'assigned_to_me'));
$pageTitle = (string)($cfg['page_title'] ?? ($enforceOwnership ? 'Pending Tasks (Assigned to me)' : 'Pending Tasks'));
$loggedInUserId = (int)(auth_user_id() ?? 0);

function task_belongs_to_user(int $taskId, int $userId): bool {
    $r = safe_db_get_one("SELECT assigned_to FROM tasks WHERE id = :id LIMIT 1", [':id' => $taskId]);
    if (!$r) return false;
    return (int)($r['assigned_to'] ?? 0) === $userId;
}

$esc = function(string $v) { return e($v); };

/* Ensure tasks table exists */
if (!table_exists('tasks')) {
    require_once __DIR__ . '/../header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>tasks</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

/* -------------------------
   Actions
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

$priorityOptions = ['low','medium','high'];
$statusOptions = ['pending','in_progress','done'];

/* ADD */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    if ($enforceOwnership) {
        $assigned_to = $loggedInUserId;
    } else {
        $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    }
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $priority = in_array($_POST['priority'] ?? 'medium', $priorityOptions, true) ? $_POST['priority'] : 'medium';
    $status = in_array($_POST['status'] ?? 'pending', $statusOptions, true) ? $_POST['status'] : 'pending';

    if ($title === '') $errors[] = 'Title required.';
    if ($description === '') $errors[] = 'Description required.';

    if (empty($errors)) {
        $ok = safe_db_run("INSERT INTO tasks (school_id,title,description,assigned_to,due_date,priority,status,created_at,updated_at)
                           VALUES (:school_id,:title,:description,:assigned_to,:due_date,:priority,:status,NOW(),NOW())",
            [
                ':school_id'=>$school_id,
                ':title'=>$title,
                ':description'=>$description,
                ':assigned_to'=>$assigned_to,
                ':due_date'=>$due_date,
                ':priority'=>$priority,
                ':status'=>$status
            ]);
        if ($ok) { $messages[] = 'Task added.'; header('Location: ?'); exit; } else $errors[] = 'Insert failed.';
    }
}

/* EDIT */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $due_date = trim((string)($_POST['due_date'] ?? '')) ?: null;
    $priority = in_array($_POST['priority'] ?? 'medium', $priorityOptions, true) ? $_POST['priority'] : 'medium';
    $status = in_array($_POST['status'] ?? 'pending', $statusOptions, true) ? $_POST['status'] : 'pending';

    if ($id <= 0) $errors[] = 'Invalid id.';
    if ($title === '') $errors[] = 'Title required.';
    if ($description === '') $errors[] = 'Description required.';
    if ($id > 0 && $enforceOwnership && !task_belongs_to_user($id, $loggedInUserId)) {
        $errors[] = 'You are not authorized to edit this task.';
    }

    if (empty($errors)) {
        if ($enforceOwnership) {
            $ok = safe_db_run("UPDATE tasks SET school_id=:school_id, title=:title, description=:description, due_date=:due_date, priority=:priority, status=:status, updated_at=NOW() WHERE id = :id",
                [
                    ':school_id'=>$school_id,
                    ':title'=>$title,
                    ':description'=>$description,
                    ':due_date'=>$due_date,
                    ':priority'=>$priority,
                    ':status'=>$status,
                    ':id'=>$id
                ]);
        } else {
            $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
            $ok = safe_db_run("UPDATE tasks SET school_id=:school_id, title=:title, description=:description, assigned_to=:assigned_to, due_date=:due_date, priority=:priority, status=:status, updated_at=NOW() WHERE id = :id",
                [
                    ':school_id'=>$school_id,
                    ':title'=>$title,
                    ':description'=>$description,
                    ':assigned_to'=>$assigned_to,
                    ':due_date'=>$due_date,
                    ':priority'=>$priority,
                    ':status'=>$status,
                    ':id'=>$id
                ]);
        }
        if ($ok) { $messages[] = 'Task updated.'; header('Location: ?'); exit; } else $errors[] = 'Update failed.';
    }
}

/* VIEW (modal fragment) */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }
    if ($enforceOwnership && !task_belongs_to_user($id, $loggedInUserId)) { echo '<div class="text-muted p-3">Task not found or you do not have access.</div>'; exit; }
    $row = safe_db_get_one("SELECT t.*, COALESCE(u.name,'') AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo '<div class="text-muted p-3">Task not found</div>'; exit; }

    echo '<dl class="row p-3">';
    echo '<dt class="col-sm-3">ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
    echo '<dt class="col-sm-3">Title</dt><dd class="col-sm-9">'.e($row['title']).'</dd>';
    echo '<dt class="col-sm-3">Description</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['description']).'</pre></dd>';
    echo '<dt class="col-sm-3">Assigned to</dt><dd class="col-sm-9">'.($row['assignee_name'] ? e($row['assignee_name']) : 'Unassigned').'</dd>';
    echo '<dt class="col-sm-3">Due date</dt><dd class="col-sm-9">'.e($row['due_date'] ?? '—').'</dd>';
    echo '<dt class="col-sm-3">Priority</dt><dd class="col-sm-9">'.e($row['priority']).'</dd>';
    echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.e($row['status']).'</dd>';
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
    if ($enforceOwnership && !task_belongs_to_user($id, $loggedInUserId)) { echo json_encode(['error'=>'Not authorized']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, title, description, assigned_to, DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date, priority, status FROM tasks WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Task not found']); exit; }
    echo json_encode(['ok'=>true, 'data'=>$row]);
    exit;
}

/* ASSIGN (quick assign) */
if ($allowAssignAction && $action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '' ? (int)$_POST['assigned_to'] : null;
    if ($id <= 0) $errors[] = 'Invalid id'; else {
        $ok = safe_db_run("UPDATE tasks SET assigned_to = :a, updated_at = NOW() WHERE id = :id", [':a'=>$assigned_to, ':id'=>$id]);
        if ($ok) $messages[] = 'Assignee updated.'; else $errors[] = 'Assign failed.';
    }
}

/* STATUS quick change via GET */
if ($action === 'status' && !empty($_GET['id']) && !empty($_GET['to'])) {
    $id = (int)$_GET['id']; $to = in_array($_GET['to'], $statusOptions, true) ? $_GET['to'] : null;
    if ($id <= 0) $errors[] = 'Invalid id';
    elseif ($to === null) $errors[] = 'Invalid status';
    elseif ($enforceOwnership && !task_belongs_to_user($id, $loggedInUserId)) $errors[] = 'You are not authorized to change status';
    else {
        $ok = safe_db_run("UPDATE tasks SET status = :s, updated_at = NOW() WHERE id = :id", [':s'=>$to, ':id'=>$id]);
        if ($ok) $messages[] = 'Status updated.'; else $errors[] = 'Status update failed.';
    }
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
    if ($id <= 0) { $errors[] = 'Invalid id'; }
    elseif ($enforceOwnership && !task_belongs_to_user($id, $loggedInUserId)) { $errors[] = 'You are not authorized to delete this task'; }
    else {
        $ok = safe_db_run("DELETE FROM tasks WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'Task deleted.'; else $errors[] = 'Delete failed.';
    }
}

/* EXPORT */
if ($action === 'export') {
    $where=[]; $params=[];
    if ($enforceOwnership) { $where[] = 't.assigned_to = :user_id'; $params[':user_id'] = $loggedInUserId; }
    if (!empty($_GET['q'])) { $where[]="(t.title LIKE :q OR t.description LIKE :q)"; $params[':q']='%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['status']) && in_array($_GET['status'], $statusOptions, true)) { $where[]="t.status=:status"; $params[':status']=$_GET['status']; }
    if (!empty($_GET['priority']) && in_array($_GET['priority'], $priorityOptions, true)) { $where[]="t.priority=:priority"; $params[':priority']=$_GET['priority']; }
    if (!$enforceOwnership && !empty($_GET['assigned_to'])) { $where[]="t.assigned_to = :assigned_to"; $params[':assigned_to'] = (int)$_GET['assigned_to']; }
    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
    $rows = safe_db_get_all("SELECT t.*, COALESCE(u.name,'') AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to $whereSql ORDER BY t.created_at DESC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tasks_'.date('Ymd_His').'.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','School ID','Title','Description','Assigned To','Due Date','Priority','Status','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['school_id'] ?? '', $r['title'] ?? '', preg_replace("/\r\n|\r|\n/"," ",$r['description'] ?? ''), $r['assignee_name'] ?? '',
            $r['due_date'] ?? '', $r['priority'] ?? '', $r['status'] ?? '', $r['created_at'] ?? '', $r['updated_at'] ?? ''
        ]);
    }
    fclose($out); exit;
}

/* -------------------------
   Filters & Pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;
$where = []; $params = [];
if ($enforceOwnership) { $where[] = "t.assigned_to = :user_id"; $params[':user_id'] = $loggedInUserId; }
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(t.title LIKE :q OR t.description LIKE :q)"; $params[':q']='%'.$qraw.'%'; }
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && in_array($statusFilter, $statusOptions, true)) { $where[] = "t.status = :status"; $params[':status'] = $statusFilter; }
$priorityFilter = $_GET['priority'] ?? '';
if ($priorityFilter !== '' && in_array($priorityFilter, $priorityOptions, true)) { $where[] = "t.priority = :priority"; $params[':priority'] = $priorityFilter; }
if (!$enforceOwnership && !empty($_GET['assigned_to'])) { $where[] = "t.assigned_to = :assigned_to"; $params[':assigned_to'] = (int)$_GET['assigned_to']; }
$from = $_GET['from'] ?? ''; if ($from !== '') { $where[] = "t.created_at >= :from"; $params[':from'] = $from . ' 00:00:00'; }
$to   = $_GET['to'] ?? '';   if ($to   !== '') { $where[] = "t.created_at <= :to";   $params[':to']   = $to . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM tasks t " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$tasks = [];
try {
    $sql = "SELECT t.*, COALESCE(u.name,'') AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to $whereSql ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $tasks = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
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

require_once __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTaskModal">Add Task</button>
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
    </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="Title or description"></div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">Any</option><?php foreach ($statusOptions as $s): ?><option value="<?php echo $esc($s); ?>" <?php if(($statusFilter ?? '')===$s) echo 'selected'; ?>><?php echo $esc(ucfirst(str_replace('_',' ',$s))); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Priority</label>
        <select name="priority" class="form-select"><option value="">Any</option><?php foreach ($priorityOptions as $p): ?><option value="<?php echo $esc($p); ?>" <?php if(($priorityFilter ?? '')===$p) echo 'selected'; ?>><?php echo $esc(ucfirst($p)); ?></option><?php endforeach; ?></select>
      </div>
<?php if ($showAssignUi): ?>
      <div class="col-md-3"><label class="form-label">Assigned to</label>
        <select name="assigned_to" class="form-select"><option value="">Any</option><?php foreach ($staffList as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php if(($_GET['assigned_to'] ?? '')===(string)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option><?php endforeach; ?></select>
      </div>
<?php else: ?>
      <div class="col-md-3"><label class="form-label">Assigned to</label>
        <select name="assigned_to" class="form-select" disabled><option><?php echo $esc('You'); ?></option></select>
      </div>
<?php endif; ?>
      <div class="col-md-2 text-end"><button class="btn btn-primary">Filter</button></div>
      <div class="col-12 text-end mt-2"><a class="btn btn-sm btn-outline-secondary" href="?">Reset</a></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0">
        <thead>
          <tr>
            <th style="width:60px">ID</th>
            <th>Title / Description</th>
            <th style="width:220px">Assigned / Due</th>
            <th style="width:120px">Priority / Status</th>
            <th style="width:140px">Created</th>
            <th style="width:220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($tasks)): foreach ($tasks as $t): ?>
            <tr>
              <td><?php echo (int)$t['id']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($t['title']); ?></div>
                <div class="small text-muted"><?php echo $esc(mb_strimwidth(strip_tags($t['description'] ?? ''), 0, 200, '...')); ?></div>
              </td>
              <td>
                <div class="small text-muted"><?php echo $esc($t['assignee_name'] ?? 'Unassigned'); ?></div>
                <div class="small text-muted">Due: <?php echo $esc($t['due_date'] ?? '—'); ?></div>
              </td>
              <td>
                <div><?php echo $esc(ucfirst($t['priority'] ?? '')); ?></div>
                <div class="mt-1"><span class="badge <?php echo ($t['status']==='done' ? 'bg-success' : ($t['status']==='in_progress' ? 'bg-warning text-dark' : 'bg-primary')); ?>"><?php echo $esc(ucfirst(str_replace('_',' ',$t['status'] ?? ''))); ?></span></div>
              </td>
              <td><?php echo $esc(substr($t['created_at'] ?? '',0,16)); ?></td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$t['id']; ?>">View</button>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-id="<?php echo (int)$t['id']; ?>">Edit</button>

<?php if ($allowAssignAction): ?>
                <form method="post" class="d-inline-block ms-1" style="display:inline;">
                  <input type="hidden" name="action" value="assign">
                  <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                  <select name="assigned_to" class="form-select form-select-sm d-inline-block" style="width:auto; display:inline-block;">
                    <option value="">Unassigned</option>
                    <?php foreach ($staffList as $s): ?>
                      <option value="<?php echo (int)$s['id']; ?>" <?php if((int)$t['assigned_to'] === (int)$s['id']) echo 'selected'; ?>><?php echo $esc($s['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                </form>

                <?php endif; ?>

                <div class="mt-1">
                  <a class="btn btn-sm btn-outline-secondary" href="?action=status&id=<?php echo (int)$t['id']; ?>&to=in_progress">Start</a>
                  <a class="btn btn-sm btn-success" href="?action=status&id=<?php echo (int)$t['id']; ?>&to=done">Done</a>
                </div>

                <?php if ($allowDeleteUi): echo render_secure_delete_button((int)$t['id'], 'Delete', 'Delete task?'); endif; ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-center text-muted">No tasks found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($tasks)); ?> of <?php echo $total; ?></div>
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

<!-- Add Task Modal -->
<div class="modal fade" id="addTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header"><h5 class="modal-title">Add Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-8"><label class="form-label">Title *</label><input name="title" class="form-control" required></div>
<?php if ($showAssignUi): ?>
            <div class="col-md-4"><label class="form-label">Assign to</label>
              <select name="assigned_to" class="form-select"><option value="">Unassigned</option><?php foreach ($staffList as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo $esc($s['name']); ?></option><?php endforeach; ?></select>
            </div>
<?php else: ?>
            <!-- Assign hidden: task assigned to current user -->
          <div class="small text-muted mt-2">Note: task will be assigned to you automatically.</div>
<?php endif; ?>
            <div class="col-12"><label class="form-label">Description *</label><textarea name="description" rows="5" class="form-control" required></textarea></div>
            <div class="col-md-3"><label class="form-label">Due date</label><input name="due_date" type="date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Priority</label>
              <select name="priority" class="form-select"><?php foreach ($priorityOptions as $p): ?><option value="<?php echo $esc($p); ?>" <?php if($p==='medium') echo 'selected'; ?>><?php echo $esc(ucfirst($p)); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-3"><label class="form-label">Status</label>
              <select name="status" class="form-select"><?php foreach ($statusOptions as $s): ?><option value="<?php echo $esc($s); ?>" <?php if($s==='pending') echo 'selected'; ?>><?php echo $esc(ucfirst(str_replace('_',' ',$s))); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-3"><label class="form-label">School ID</label><input name="school_id" class="form-control" value="1"></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Add Task</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Task Modal -->
<div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editTaskForm">
        <input type="hidden" name="id" id="edit_id">
        <div class="modal-header"><h5 class="modal-title">Edit Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="editTaskBody"><div class="text-center text-muted">Loading…</div></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Task details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // View modal: load fragment
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }

  // Edit modal: fetch JSON and populate
  var editModal = document.getElementById('editTaskModal');
  var staff = <?php echo json_encode($staffList, JSON_UNESCAPED_UNICODE); ?>;
  var showAssignUi = <?php echo $showAssignUi ? 'true' : 'false'; ?>;
  if (editModal) {
    editModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('editTaskBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.json() : Promise.reject(); })
        .then(function(json){
          if (!json || !json.ok || !json.data) { body.innerHTML = '<div class="text-danger p-3">Failed to load task.</div>'; return; }
          var d = json.data;
          document.getElementById('edit_id').value = d.id || '';

          var html = '';
          html += '<div class="row g-2">';
          html += '<div class="col-md-8"><label class="form-label">Title</label><input id="edit_title" name="title" class="form-control" required></div>';
          if (showAssignUi) {
            html += '<div class="col-md-4"><label class="form-label">Assign to</label><select id="edit_assigned_to" name="assigned_to" class="form-select"><option value="">Unassigned</option>';
            staff.forEach(function(s){ html += '<option value="'+s.id+'">'+s.name+'</option>'; });
            html += '</select></div>';
          }
          html += '<div class="col-12"><label class="form-label">Description</label><textarea id="edit_description" name="description" rows="5" class="form-control" required></textarea></div>';
          html += '<div class="col-md-3"><label class="form-label">Due date</label><input id="edit_due_date" name="due_date" type="date" class="form-control"></div>';
          html += '<div class="col-md-3"><label class="form-label">Priority</label><select id="edit_priority" name="priority" class="form-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>';
          html += '<div class="col-md-3"><label class="form-label">Status</label><select id="edit_status" name="status" class="form-select"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="done">Done</option></select></div>';
          html += '<div class="col-md-3"><label class="form-label">School ID</label><input id="edit_school_id" name="school_id" class="form-control"></div>';
          html += '</div>';
          if (!showAssignUi) html += '<div class="small text-muted mt-2">Note: assignment is fixed to you and cannot be changed here.</div>';
          body.innerHTML = html;

          document.getElementById('edit_title').value = d.title || '';
          document.getElementById('edit_description').value = d.description || '';
          if (showAssignUi && document.getElementById('edit_assigned_to')) document.getElementById('edit_assigned_to').value = d.assigned_to || '';
          document.getElementById('edit_due_date').value = d.due_date || '';
          document.getElementById('edit_priority').value = d.priority || 'medium';
          document.getElementById('edit_status').value = d.status || 'pending';
          document.getElementById('edit_school_id').value = d.school_id || 1;
        })
        .catch(function(){ body.innerHTML = '<div class="text-danger p-3">Failed to load.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../footer.php';
?>