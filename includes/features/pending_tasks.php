<?php
/**
 * Office to-do: assign work, notes with who wrote them, and a Problem tab when stuck.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'To-do');
$mineOnly = !empty($cfg['enforce_ownership']) || (($cfg['task_scope'] ?? '') === 'assigned_to_me');
$canAssign = !$mineOnly;
$canDelete = !$mineOnly;
$userId = (int) (auth_user_id() ?? 0);
$userName = function_exists('auth_user_name') ? auth_user_name('Staff') : 'Staff';
$schoolId = function_exists('auth_school_id') ? auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$esc = static fn(string $v): string => e($v);

if (!table_exists('tasks')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The tasks table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

if (!table_exists('task_notes')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                'CREATE TABLE IF NOT EXISTS task_notes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT DEFAULT NULL,
                    note TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_task (task_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    } catch (Throwable $e) {
        error_log('task_notes create: ' . $e->getMessage());
    }
}
$notesOk = table_exists('task_notes');

$helpStatus = 'in_progress';
$statusCol = safe_db_get_one("SHOW COLUMNS FROM tasks LIKE 'status'");
$statusType = strtolower((string) ($statusCol['Type'] ?? ''));
if (str_contains($statusType, 'blocked')) {
    $helpStatus = 'blocked';
} elseif ($statusType !== '') {
    try {
        if (function_exists('db_execute')) {
            db_execute("ALTER TABLE tasks MODIFY status ENUM('pending','in_progress','done','blocked') DEFAULT 'pending'");
            $helpStatus = 'blocked';
        }
    } catch (Throwable $e) {
        $helpStatus = 'in_progress';
    }
}

$hasDemo = safe_db_get_one("SELECT id FROM tasks WHERE title LIKE '[Demo]%' LIMIT 1");
if (!$hasDemo && $userId > 0) {
    $demos = [
        ['[Demo] Collect first-term fee', "How to collect a fee:\n1. Open Accounts → Collect Fees\n2. Type the student name and select them\n3. Pending amount fills in — change it if they paid part\n4. Choose Cash / UPI / Online, then Save & print receipt\nTick this Done after you try it once.", 'high'],
        ['[Demo] Mark a task done', 'Click the circle on the left of this line. It moves to Finished.', 'low'],
        ['[Demo] Ask if stuck', 'If work is stuck, open the task and tap Problem. Write what happened so the owner can help.', 'medium'],
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

$canTouch = static function (int $id) use ($mineOnly, $owns): bool {
    return !$mineOnly || $owns($id);
};

$addNote = static function (int $taskId, string $note, int $uid) use ($notesOk): bool {
    $note = trim($note);
    if (!$notesOk || $taskId <= 0 || $note === '') {
        return false;
    }
    return (bool) safe_db_run(
        'INSERT INTO task_notes (task_id, user_id, note, created_at) VALUES (:t, :u, :n, NOW())',
        [':t' => $taskId, ':u' => $uid > 0 ? $uid : null, ':n' => $note]
    );
};

$isHelp = static function (string $status) use ($helpStatus): bool {
    return in_array($status, ['blocked', 'in_progress', $helpStatus], true);
};

$action = (string) ($_REQUEST['action'] ?? 'list');

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
            $errors[] = 'Write what needs to be done.';
        } else {
            $ok = safe_db_run(
                'INSERT INTO tasks (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
                 VALUES (:s, :t, :d, :a, :due, :p, :st, NOW(), NOW())',
                [':s' => $schoolId, ':t' => $title, ':d' => $note !== '' ? $note : null, ':a' => $assigned ?: null, ':due' => $due, ':p' => $priority, ':st' => 'pending']
            );
            if ($ok) {
                $newId = 0;
                $pdo = function_exists('pdo_connect') ? pdo_connect() : null;
                if ($pdo instanceof PDO) {
                    $newId = (int) $pdo->lastInsertId();
                }
                if ($newId <= 0) {
                    $last = safe_db_get_one('SELECT id FROM tasks WHERE assigned_to = :a ORDER BY id DESC LIMIT 1', [':a' => $assigned]);
                    $newId = (int) ($last['id'] ?? 0);
                }
                if ($note !== '' && $newId > 0) {
                    $addNote($newId, $note, $userId);
                }
                header('Location: ?added=1');
                exit;
            }
            $errors[] = 'Could not save task.';
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $priority = array_key_exists((string) ($_POST['priority'] ?? ''), $priorities) ? (string) $_POST['priority'] : 'medium';
        $statusIn = (string) ($_POST['status'] ?? 'pending');
        if ($statusIn === 'blocked' || $statusIn === 'in_progress' || $statusIn === 'help') {
            $status = $helpStatus;
        } elseif ($statusIn === 'done') {
            $status = 'done';
        } else {
            $status = 'pending';
        }
        $assigned = $mineOnly ? $userId : (($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null);
        $extraNote = trim((string) ($_POST['new_note'] ?? ''));
        if ($id <= 0 || $title === '') {
            $errors[] = 'Title is required.';
        } elseif (!$canTouch($id)) {
            $errors[] = 'You can only edit your own tasks.';
        } else {
            $ok = $mineOnly
                ? safe_db_run(
                    'UPDATE tasks SET title=:t, due_date=:due, priority=:p, status=:st, updated_at=NOW() WHERE id=:id',
                    [':t' => $title, ':due' => $due, ':p' => $priority, ':st' => $status, ':id' => $id]
                )
                : safe_db_run(
                    'UPDATE tasks SET title=:t, assigned_to=:a, due_date=:due, priority=:p, status=:st, updated_at=NOW() WHERE id=:id',
                    [':t' => $title, ':a' => $assigned, ':due' => $due, ':p' => $priority, ':st' => $status, ':id' => $id]
                );
            if ($ok) {
                if ($extraNote !== '') {
                    $addNote($id, $extraNote, $userId);
                }
                $tabBack = $status === 'done' ? 'done' : ($isHelp($status) ? 'help' : 'open');
                header('Location: ?tab=' . $tabBack . '&saved=1');
                exit;
            }
            $errors[] = 'Could not update task.';
        }
    } elseif ($action === 'note') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $tabBack = (string) ($_POST['tab'] ?? 'open');
        if (!in_array($tabBack, ['open', 'help', 'done'], true)) {
            $tabBack = 'open';
        }
        if ($id <= 0 || !$canTouch($id)) {
            $errors[] = 'You cannot add a note here.';
        } elseif ($note === '') {
            $errors[] = 'Write a note first.';
        } elseif ($addNote($id, $note, $userId)) {
            header('Location: ?tab=' . rawurlencode($tabBack) . '&saved=1#task-' . $id);
            exit;
        } else {
            $errors[] = 'Could not save the note.';
        }
    } elseif ($action === 'help') {
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($id <= 0 || !$canTouch($id)) {
            $errors[] = 'You cannot mark this as a problem.';
        } elseif (safe_db_run('UPDATE tasks SET status = :st, updated_at = NOW() WHERE id = :id', [':st' => $helpStatus, ':id' => $id])) {
            if ($note !== '') {
                $addNote($id, $note, $userId);
            } else {
                $addNote($id, 'Need help with this.', $userId);
            }
            header('Location: ?tab=help&saved=1#task-' . $id);
            exit;
        } else {
            $errors[] = 'Could not move to Problem.';
        }
    } elseif ($action === 'done') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $canTouch($id) && safe_db_run("UPDATE tasks SET status='done', updated_at=NOW() WHERE id=:id", [':id' => $id])) {
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not mark finished.';
    } elseif ($action === 'reopen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $canTouch($id) && safe_db_run("UPDATE tasks SET status='pending', updated_at=NOW() WHERE id=:id", [':id' => $id])) {
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
        if ($notesOk) {
            safe_db_run('DELETE FROM task_notes WHERE task_id = :id', [':id' => $deleteId]);
        }
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
if (!in_array($tab, ['open', 'help', 'done'], true)) {
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
} elseif ($tab === 'help') {
    $where[] = "t.status IN ('blocked','in_progress')";
} else {
    $where[] = "t.status = 'pending'";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$mineSql = $mineOnly ? ' AND t.assigned_to = :uid' : '';
$mineParams = $mineOnly ? [':uid' => $userId] : [];
$openCount = (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM tasks t WHERE t.status = 'pending'{$mineSql}", $mineParams)['c'] ?? 0);
$helpCount = (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM tasks t WHERE t.status IN ('blocked','in_progress'){$mineSql}", $mineParams)['c'] ?? 0);
$doneCount = (int) (safe_db_get_one("SELECT COUNT(*) AS c FROM tasks t WHERE t.status = 'done'{$mineSql}", $mineParams)['c'] ?? 0);

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

$notesByTask = [];
if ($notesOk && $tasks !== []) {
    $ids = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $tasks)));
    if ($ids !== []) {
        $in = implode(',', $ids);
        $rows = safe_db_get_all(
            "SELECT n.task_id, n.note, n.created_at, n.user_id, COALESCE(u.name,'') AS author_name
             FROM task_notes n
             LEFT JOIN users u ON u.id = n.user_id
             WHERE n.task_id IN ({$in})
             ORDER BY n.id ASC"
        ) ?: [];
        foreach ($rows as $nr) {
            $notesByTask[(int) $nr['task_id']][] = $nr;
        }
    }
}

$staffList = [];
if ($canAssign && table_exists('users')) {
    try {
        $staffList = safe_db_get_all("SELECT id, name FROM users WHERE role <> 'parent' ORDER BY name ASC") ?: [];
    } catch (Throwable $e) {
        $staffList = safe_db_get_all('SELECT id, name FROM users ORDER BY name ASC') ?: [];
    }
}

$fmtWhen = static function (?string $d): string {
    $ts = strtotime((string) $d);
    return $ts ? date('d M, g:i a', $ts) : '';
};

require_once __DIR__ . '/../header.php';
?>
<style>
.todo-add { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:12px; margin-bottom:12px; }
.todo-list { background:#fff; border:1px solid #dbe7fb; border-radius:16px; overflow:hidden; }
.todo-item { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-bottom:1px solid #f0f4fb; }
.todo-item:last-child { border-bottom:0; }
.todo-item.overdue { background:#fff8f7; }
.todo-item.help { background:#fff7ed; }
.todo-check { width:28px; height:28px; border-radius:50%; border:2px solid #94a3b8; background:#fff; color:transparent; font-weight:800; line-height:1; padding:0; flex:0 0 28px; margin-top:2px; }
.todo-check:hover { border-color:#16a34a; color:#16a34a; }
.todo-item.done .todo-check { background:#16a34a; border-color:#16a34a; color:#fff; }
.todo-item.done .todo-title { text-decoration:line-through; color:#94a3b8; }
.todo-title { font-weight:700; color:#1e3a5f; background:none; border:0; padding:0; text-align:left; }
.todo-title:hover { color:#0d3b8c; text-decoration:underline; }
.todo-meta { font-size:.8rem; color:#64748b; }
.todo-urgent { color:#b42318; font-weight:700; }
.todo-note { background:#f8fafc; border-radius:10px; padding:8px 10px; margin-top:6px; font-size:.88rem; }
.todo-note .who { font-weight:700; color:#1e3a5f; }
.todo-note .when { color:#94a3b8; font-size:.75rem; }
.todo-tabs a { text-decoration:none; }
.todo-tabs a.active { font-weight:800; color:#1d4ed8; }
</style>

<p class="text-muted small mb-2">
  <?php if ($canAssign): ?>
    Office list: give someone a job. If they get stuck they tap <strong>Problem</strong> and write a note — you will see <em>who</em> wrote it.
  <?php else: ?>
    Your jobs. Tick when finished. If you are stuck, tap <strong>Problem</strong> and write what happened.
  <?php endif; ?>
</p>
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
  <input class="form-control form-control-sm mt-2" name="description" placeholder="Optional first note (your name will show on it)">
  <div class="form-check mt-2 mb-0">
    <input class="form-check-input" type="checkbox" name="priority" value="high" id="todoUrgent">
    <label class="form-check-label small" for="todoUrgent">Urgent</label>
  </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div class="small todo-tabs">
    <a class="<?php echo $tab === 'open' ? 'active' : ''; ?>" href="?tab=open">To do (<?php echo $openCount; ?>)</a>
    <span class="text-muted"> · </span>
    <a class="<?php echo $tab === 'help' ? 'active' : ''; ?>" href="?tab=help">Problem (<?php echo $helpCount; ?>)</a>
    <span class="text-muted"> · </span>
    <a class="<?php echo $tab === 'done' ? 'active' : ''; ?>" href="?tab=done">Finished (<?php echo $doneCount; ?>)</a>
  </div>
  <form method="get" class="d-flex gap-1">
    <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
    <input class="form-control form-control-sm" name="q" value="<?php echo $esc($qraw); ?>" placeholder="Search" style="width:160px">
  </form>
</div>

<div class="todo-list">
  <?php if ($tasks === []): ?>
    <div class="p-4 text-center text-muted">
      <?php
        echo $tab === 'done' ? 'Nothing finished yet.'
            : ($tab === 'help' ? 'No problems right now.' : 'Nothing to do. Type above and press Add.');
      ?>
    </div>
  <?php endif; ?>
  <?php foreach ($tasks as $t):
      $tid = (int) ($t['id'] ?? 0);
      $due = substr((string) ($t['due_date'] ?? ''), 0, 10);
      $overdue = $tab !== 'done' && $due !== '' && $due < date('Y-m-d');
      $pri = (string) ($t['priority'] ?? 'medium');
      $st = (string) ($t['status'] ?? 'pending');
      $isDone = $tab === 'done';
      $onHelp = $isHelp($st);
      $thread = $notesByTask[$tid] ?? [];
      if ($thread === [] && trim((string) ($t['description'] ?? '')) !== '') {
          $thread[] = [
              'note' => (string) $t['description'],
              'author_name' => (string) ($t['assignee_name'] ?? ''),
              'created_at' => (string) ($t['created_at'] ?? ''),
              'user_id' => (int) ($t['assigned_to'] ?? 0),
          ];
      }
  ?>
    <div class="todo-item<?php echo $overdue ? ' overdue' : ''; ?><?php echo $isDone ? ' done' : ''; ?><?php echo $onHelp && !$isDone ? ' help' : ''; ?>" id="task-<?php echo $tid; ?>">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $isDone ? 'reopen' : 'done'; ?>">
        <input type="hidden" name="id" value="<?php echo $tid; ?>">
        <button class="todo-check" type="submit" title="<?php echo $isDone ? 'Move back to to-do' : 'Mark finished'; ?>">✓</button>
      </form>
      <div class="flex-grow-1">
        <button type="button" class="todo-title" data-bs-toggle="modal" data-bs-target="#editTaskModal" data-id="<?php echo $tid; ?>"><?php echo $esc((string) $t['title']); ?></button>
        <div class="todo-meta">
          For: <?php echo $esc((string) ($t['assignee_name'] !== '' ? $t['assignee_name'] : 'Unassigned')); ?>
          <?php if ($due !== ''): ?> · <?php echo $esc(date('d M', strtotime($due) ?: time())); ?><?php endif; ?>
          <?php if ($overdue): ?> · <span class="todo-urgent">Overdue</span><?php endif; ?>
          <?php if ($pri === 'high'): ?> · <span class="todo-urgent">Urgent</span><?php endif; ?>
          <?php if ($onHelp && !$isDone): ?> · <span class="todo-urgent">Problem</span><?php endif; ?>
        </div>
        <?php foreach ($thread as $nr):
            $who = trim((string) ($nr['author_name'] ?? ''));
            if ($who === '') {
                $who = 'Staff';
            }
            $when = $fmtWhen((string) ($nr['created_at'] ?? ''));
            ?>
          <div class="todo-note">
            <div><span class="who"><?php echo $esc($who); ?></span><?php if ($when !== ''): ?> <span class="when"><?php echo $esc($when); ?></span><?php endif; ?></div>
            <div style="white-space:pre-wrap"><?php echo $esc((string) ($nr['note'] ?? '')); ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$isDone): ?>
          <form method="post" class="d-flex flex-wrap gap-1 mt-2">
            <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
            <input type="hidden" name="action" value="note">
            <input type="hidden" name="id" value="<?php echo $tid; ?>">
            <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
            <input class="form-control form-control-sm" name="note" required placeholder="Add a note as <?php echo $esc($userName); ?>" style="flex:1; min-width:160px">
            <button class="btn btn-sm btn-outline-primary" type="submit">Note</button>
          </form>
          <?php if ($tab === 'open'): ?>
            <form method="post" class="mt-1">
              <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
              <input type="hidden" name="action" value="help">
              <input type="hidden" name="id" value="<?php echo $tid; ?>">
              <input type="hidden" name="note" value="">
              <button class="btn btn-sm btn-outline-warning" type="submit">Problem — need help</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if ($canDelete) {
          echo render_secure_delete_button($tid, '×', 'Delete this to-do?');
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
        <div class="col-12"><label class="form-label">Add a note</label><textarea name="new_note" class="form-control" rows="2" placeholder="Saved with your name: <?php echo $esc($userName); ?>"></textarea></div>
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
            <option value="help">Problem</option>
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
        document.getElementById('edit_due_date').value = d.due_date || '';
        document.getElementById('edit_priority').value = d.priority === 'high' ? 'high' : 'medium';
        var st = d.status || 'pending';
        document.getElementById('edit_status').value = (st === 'done') ? 'done' : ((st === 'blocked' || st === 'in_progress') ? 'help' : 'pending');
        var asg = document.getElementById('edit_assigned_to');
        if (asg) asg.value = d.assigned_to || '';
      });
  });
});
</script>
<?php require_once __DIR__ . '/../footer.php'; ?>
