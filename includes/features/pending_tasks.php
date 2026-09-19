<?php
/**
 * Reception tasks — simple to-do list from the `tasks` table.
 * Owner: all tasks, assign to staff. Reception: only tasks assigned to them.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'To-do');
$mineOnly = !empty($cfg['enforce_ownership']) || (($cfg['task_scope'] ?? '') === 'assigned_to_me');
$canAssign = !$mineOnly;
$canDelete = !$mineOnly;
$userId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$esc = static fn(string $v): string => e($v);

if (!table_exists('tasks')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The tasks table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$hasDemo = safe_db_get_one("SELECT id FROM tasks WHERE title LIKE '[Demo]%' LIMIT 1");
if (!$hasDemo && $userId > 0) {
    $demos = [
        ['[Demo] Collect first-term fee', "How to collect a fee:\n1. Open Accounts → Collect Fees\n2. Type the student name and select them\n3. Pending amount fills in — change it if they paid part\n4. Choose Cash / UPI / Online, then Save & print receipt\nTick this Done after you try it once.", 'high'],
        ['[Demo] Mark a task done', 'Click the circle on the left of this line. It moves to Done. Click the title to edit note, due date, or who it is for.', 'low'],
        ['[Demo] Add your own task', 'Use the box at the top: type a short title (example: Call parent about photos), optional date, then Add. You can delete these Demo items anytime.', 'medium'],
    ];
    foreach ($demos as $d) {
        safe_db_run(
            'INSERT INTO tasks (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
             VALUES (:s, :t, :d, :a, :due, :p, :st, NOW(), NOW())',
            [
                ':s' => $schoolId,
                ':t' => $d[0],
                ':d' => $d[1],
                ':a' => $userId,
                ':due' => date('Y-m-d', strtotime('+2 days')),
                ':p' => $d[2],
                ':st' => 'pending',
            ]
        );
    }
}

$messages = [];
$errors = [];
$priorities = ['medium' => 'Normal', 'high' => 'Urgent', 'low' => 'Low'];

$csrfOk = static function () use ($csrf): bool {
    return function_exists('validate_csrf_token') && validate_csrf_token((string) ($_POST['csrf'] ?? ''));
};

$owns = static function (int $id) use ($userId): bool {
    $r = safe_db_get_one('SELECT assigned_to FROM tasks WHERE id = :id LIMIT 1', [':id' => $id]);
    return $r && (int) ($r['assigned_to'] ?? 0) === $userId;
};

$action = (string) ($_REQUEST['action'] ?? 'list');

if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if ($mineOnly && !$owns($id)) {
        echo '<div class="text-muted">Task not found.</div>';
        exit;
    }
    $row = $id > 0 ? safe_db_get_one(
        "SELECT t.*, COALESCE(u.name,'') AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = :id LIMIT 1",
        [':id' => $id]
    ) : null;
    if (!$row) {
        echo '<div class="text-muted">Task not found.</div>';
        exit;
    }
    echo '<p class="fw-semibold mb-1">' . $esc((string) $row['title']) . '</p>';
    echo '<p class="mb-2">' . nl2br($esc((string) ($row['description'] ?? ''))) . '</p>';
    echo '<div class="small text-muted">Assigned: ' . $esc((string) ($row['assignee_name'] ?: 'Unassigned')) . '</div>';
    echo '<div class="small text-muted">Due: ' . $esc((string) ($row['due_date'] ?: '—')) . ' · ' . $esc($priorities[$row['priority'] ?? 'medium'] ?? 'Normal') . '</div>';
    exit;
}

if ($action === 'get' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) $_GET['id'];
    if ($mineOnly && !$owns($id)) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $row = $id > 0 ? safe_db_get_one(
        "SELECT id, title, description, assigned_to, DATE_FORMAT(due_date,'%Y-%m-%d') AS due_date, priority, status FROM tasks WHERE id = :id LIMIT 1",
        [':id' => $id]
    ) : null;
    echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$csrfOk()) {
        $errors[] = 'Please reload the page and try again.';
    } elseif ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['description'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $priority = array_key_exists((string) ($_POST['priority'] ?? ''), $priorities) ? (string) $_POST['priority'] : 'medium';
        $assigned = $mineOnly ? $userId : (($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : $userId);
        if ($title === '') {
            $errors[] = 'Please enter a task title.';
        } else {
            $ok = safe_db_run(
                'INSERT INTO tasks (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
                 VALUES (:s, :t, :d, :a, :due, :p, :st, NOW(), NOW())',
                [':s' => $schoolId, ':t' => $title, ':d' => $note, ':a' => $assigned ?: null, ':due' => $due, ':p' => $priority, ':st' => 'pending']
            );
            if ($ok) {
                header('Location: ?added=1');
                exit;
            }
            $errors[] = 'Could not save task.';
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['description'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $priority = array_key_exists((string) ($_POST['priority'] ?? ''), $priorities) ? (string) $_POST['priority'] : 'medium';
        $status = in_array((string) ($_POST['status'] ?? ''), ['pending', 'in_progress', 'done'], true) ? (string) $_POST['status'] : 'pending';
        $assigned = $mineOnly ? $userId : (($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null);
        if ($id <= 0 || $title === '') {
            $errors[] = 'Title is required.';
        } elseif ($mineOnly && !$owns($id)) {
            $errors[] = 'You can only edit your own tasks.';
        } else {
            $ok = $mineOnly
                ? safe_db_run(
                    'UPDATE tasks SET title=:t, description=:d, due_date=:due, priority=:p, status=:st, updated_at=NOW() WHERE id=:id',
                    [':t' => $title, ':d' => $note, ':due' => $due, ':p' => $priority, ':st' => $status, ':id' => $id]
                )
                : safe_db_run(
                    'UPDATE tasks SET title=:t, description=:d, assigned_to=:a, due_date=:due, priority=:p, status=:st, updated_at=NOW() WHERE id=:id',
                    [':t' => $title, ':d' => $note, ':a' => $assigned, ':due' => $due, ':p' => $priority, ':st' => $status, ':id' => $id]
                );
            if ($ok) {
                header('Location: ?saved=1');
                exit;
            }
            $errors[] = 'Could not update task.';
        }
    } elseif ($action === 'done') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && (!$mineOnly || $owns($id)) && safe_db_run("UPDATE tasks SET status='done', updated_at=NOW() WHERE id=:id", [':id' => $id])) {
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not mark done.';
    } elseif ($action === 'reopen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && (!$mineOnly || $owns($id)) && safe_db_run("UPDATE tasks SET status='pending', updated_at=NOW() WHERE id=:id", [':id' => $id])) {
            header('Location: ?tab=done&saved=1');
            exit;
        }
        $errors[] = 'Could not reopen task.';
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action) && $canDelete) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = ($canDelete && function_exists('secure_delete_id')) ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if (safe_db_run('DELETE FROM tasks WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    }
    $errors[] = 'Delete failed.';
}

if (!empty($_GET['added'])) {
    $messages[] = 'Task added.';
}
if (!empty($_GET['saved'])) {
    $messages[] = 'Saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Task deleted.';
}

$tab = (string) ($_GET['tab'] ?? 'open');
if (!in_array($tab, ['open', 'done'], true)) {
    $tab = 'open';
}
$qraw = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($mineOnly) {
    $where[] = 't.assigned_to = :uid';
    $params[':uid'] = $userId;
}
if ($qraw !== '') {
    $where[] = '(t.title LIKE :q OR t.description LIKE :q)';
    $params[':q'] = '%' . $qraw . '%';
}
if ($tab === 'done') {
    $where[] = "t.status = 'done'";
} else {
    $where[] = "t.status <> 'done'";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$openCount = (int) (safe_db_get_one(
    'SELECT COUNT(*) AS c FROM tasks t WHERE t.status <> \'done\'' . ($mineOnly ? ' AND t.assigned_to = :uid' : ''),
    $mineOnly ? [':uid' => $userId] : []
)['c'] ?? 0);
$doneCount = (int) (safe_db_get_one(
    'SELECT COUNT(*) AS c FROM tasks t WHERE t.status = \'done\'' . ($mineOnly ? ' AND t.assigned_to = :uid' : ''),
    $mineOnly ? [':uid' => $userId] : []
)['c'] ?? 0);
$overdueCount = (int) (safe_db_get_one(
    'SELECT COUNT(*) AS c FROM tasks t WHERE t.status <> \'done\' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()' . ($mineOnly ? ' AND t.assigned_to = :uid' : ''),
    $mineOnly ? [':uid' => $userId] : []
)['c'] ?? 0);

$order = $tab === 'done'
    ? 't.updated_at DESC, t.id DESC'
    : 'CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 0 ELSE 1 END, t.due_date IS NULL, t.due_date ASC, t.id DESC';
$tasks = safe_db_get_all(
    "SELECT t.*, COALESCE(u.name,'') AS assignee_name
     FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to
     {$whereSql}
     ORDER BY {$order}
     LIMIT 200",
    $params
) ?: [];

$staffList = [];
if ($canAssign && table_exists('users')) {
    try {
        $staffList = safe_db_get_all("SELECT id, name FROM users WHERE role <> 'parent' ORDER BY name ASC") ?: [];
    } catch (Throwable $e) {
        $staffList = safe_db_get_all('SELECT id, name FROM users ORDER BY name ASC') ?: [];
    }
}

require_once __DIR__ . '/../header.php';
?>
<style>
.todo-add { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:12px; margin-bottom:12px; }
.todo-list { background:#fff; border:1px solid #dbe7fb; border-radius:16px; overflow:hidden; }
.todo-item { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-bottom:1px solid #f0f4fb; }
.todo-item:last-child { border-bottom:0; }
.todo-item.overdue { background:#fff8f7; }
.todo-check { width:28px; height:28px; border-radius:50%; border:2px solid #94a3b8; background:#fff; color:transparent; font-weight:800; line-height:1; padding:0; flex:0 0 28px; margin-top:2px; }
.todo-check:hover { border-color:#16a34a; color:#16a34a; }
.todo-item.done .todo-check { background:#16a34a; border-color:#16a34a; color:#fff; }
.todo-item.done .todo-title { text-decoration:line-through; color:#94a3b8; }
.todo-title { font-weight:700; color:#1e3a5f; background:none; border:0; padding:0; text-align:left; }
.todo-title:hover { color:#0d3b8c; text-decoration:underline; }
.todo-meta { font-size:.8rem; color:#64748b; }
.todo-urgent { color:#b42318; font-weight:700; }
</style>

<?php if ($canAssign): ?>
  <p class="text-muted small mb-2">One list for the office. Add a to-do and pick who it is for (you, reception, or a teacher).</p>
<?php endif; ?>
<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo $esc($er); ?></div><?php endforeach; ?>

<form method="post" class="todo-add">
  <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
  <input type="hidden" name="action" value="add">
  <div class="d-flex flex-wrap gap-2">
    <input class="form-control" name="title" required placeholder="Add a to-do, e.g. Call parent for documents" style="flex:1; min-width:220px">
    <?php if ($canAssign): ?>
    <select name="assigned_to" class="form-select" style="max-width:200px">
      <option value="<?php echo (int) $userId; ?>">Me</option>
      <?php foreach ($staffList as $s): ?>
        <option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn-success" type="submit">Add</button>
  </div>
  <div class="form-check mt-2 mb-0">
    <input class="form-check-input" type="checkbox" name="priority" value="high" id="todoUrgent">
    <label class="form-check-label small" for="todoUrgent">Urgent</label>
  </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="small">
    <a class="<?php echo $tab === 'open' ? 'fw-bold' : ''; ?>" href="?tab=open">To do (<?php echo $openCount; ?>)</a>
    <span class="text-muted"> · </span>
    <a class="<?php echo $tab === 'done' ? 'fw-bold' : ''; ?>" href="?tab=done">Finished (<?php echo $doneCount; ?>)</a>
    <?php if ($overdueCount > 0 && $tab === 'open'): ?>
      <span class="todo-urgent ms-2"><?php echo $overdueCount; ?> overdue</span>
    <?php endif; ?>
  </div>
  <form method="get" class="d-flex gap-1">
    <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
    <input class="form-control form-control-sm" name="q" value="<?php echo $esc($qraw); ?>" placeholder="Search" style="width:160px">
  </form>
</div>

<div class="todo-list">
  <?php if ($tasks === []): ?>
    <div class="p-4 text-center text-muted"><?php echo $tab === 'done' ? 'Nothing finished yet.' : 'Nothing to do. Type above and press Add.'; ?></div>
  <?php endif; ?>
  <?php foreach ($tasks as $t):
      $due = (string) ($t['due_date'] ?? '');
      $overdue = $tab === 'open' && $due !== '' && $due < date('Y-m-d');
      $pri = (string) ($t['priority'] ?? 'medium');
      $isDone = $tab === 'done';
  ?>
    <div class="todo-item<?php echo $overdue ? ' overdue' : ''; ?><?php echo $isDone ? ' done' : ''; ?>">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $isDone ? 'reopen' : 'done'; ?>">
        <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
        <button class="todo-check" type="submit" title="<?php echo $isDone ? 'Move back to to-do' : 'Mark done'; ?>">✓</button>
      </form>
      <div class="flex-grow-1">
        <button type="button" class="todo-title" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-id="<?php echo (int) $t['id']; ?>"><?php echo $esc((string) $t['title']); ?></button>
        <div class="todo-meta">
          <?php echo $esc((string) ($t['assignee_name'] !== '' ? $t['assignee_name'] : 'Unassigned')); ?>
          <?php if ($due !== ''): ?> · <?php echo $esc(date('d M', strtotime($due))); ?><?php endif; ?>
          <?php if ($overdue): ?> · <span class="todo-urgent">Overdue</span><?php endif; ?>
          <?php if ($pri === 'high'): ?> · <span class="todo-urgent">Urgent</span><?php endif; ?>
        </div>
      </div>
      <?php if ($canDelete) {
          echo render_secure_delete_button((int) $t['id'], '×', 'Delete this to-do?');
      } ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="editTaskModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header"><h5 class="modal-title">Edit to-do</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label">To-do</label><input name="title" id="edit_title" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Note</label><textarea name="description" id="edit_description" class="form-control" rows="2"></textarea></div>
        <?php if ($canAssign): ?>
        <div class="col-md-6">
          <label class="form-label">Who</label>
          <select name="assigned_to" id="edit_assigned_to" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach ($staffList as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">Due</label><input type="date" name="due_date" id="edit_due_date" class="form-control"></div>
        <div class="col-md-6">
          <label class="form-label">Priority</label>
          <select name="priority" id="edit_priority" class="form-select">
            <option value="medium">Normal</option>
            <option value="high">Urgent</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" id="edit_status" class="form-select">
            <option value="pending">To do</option>
            <option value="done">Finished</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var editModal = document.getElementById('editTaskModal');
  if (!editModal) return;
  editModal.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) return;
    fetch('?action=get&id=' + encodeURIComponent(btn.getAttribute('data-id')), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.ok) return;
        var d = json.data;
        document.getElementById('edit_id').value = d.id || '';
        document.getElementById('edit_title').value = d.title || '';
        document.getElementById('edit_description').value = d.description || '';
        document.getElementById('edit_due_date').value = d.due_date || '';
        document.getElementById('edit_priority').value = d.priority === 'high' ? 'high' : 'medium';
        document.getElementById('edit_status').value = d.status === 'done' ? 'done' : 'pending';
        var asg = document.getElementById('edit_assigned_to');
        if (asg) asg.value = d.assigned_to || '';
      });
  });
});
</script>
<?php require_once __DIR__ . '/../footer.php'; ?>
