<?php
/**
 * teacher/tasks.php — this teacher’s to-do list.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$action = (string) ($_REQUEST['action'] ?? '');

if (!table_exists('tasks')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                "CREATE TABLE IF NOT EXISTS tasks (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    school_id INT UNSIGNED NOT NULL DEFAULT 1,
                    title VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    assigned_to INT DEFAULT NULL,
                    due_date DATE DEFAULT NULL,
                    priority ENUM('low','medium','high') DEFAULT 'medium',
                    status ENUM('pending','in_progress','done') DEFAULT 'pending',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
    } catch (Throwable $e) {
        error_log('tasks create: ' . $e->getMessage());
    }
}

$tableOk = table_exists('tasks');
$messages = [];
$errors = [];

$owns = static function (int $id) use ($teacherId): bool {
    if ($id <= 0 || $teacherId <= 0) {
        return false;
    }
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    $r = safe_db_get_one('SELECT assigned_to FROM tasks WHERE id = :id LIMIT 1', [':id' => $id]);
    return $r && (int) ($r['assigned_to'] ?? 0) === $teacherId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'delete') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$tableOk) {
        $errors[] = 'Tasks are not set up yet.';
    } elseif ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['description'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $due = '';
        }
        $priority = ((string) ($_POST['priority'] ?? '')) === 'high' ? 'high' : 'medium';
        if ($title === '') {
            $errors[] = 'Write what you need to do.';
        } elseif (safe_db_run(
            'INSERT INTO tasks (school_id, title, description, assigned_to, due_date, priority, status, created_at, updated_at)
             VALUES (:s, :t, :d, :a, :due, :p, :st, NOW(), NOW())',
            [
                ':s' => $schoolId > 0 ? $schoolId : 1,
                ':t' => $title,
                ':d' => $note !== '' ? $note : null,
                ':a' => $teacherId > 0 ? $teacherId : null,
                ':due' => $due !== '' ? $due : null,
                ':p' => $priority,
                ':st' => 'pending',
            ]
        )) {
            header('Location: ?saved=1');
            exit;
        } else {
            $errors[] = 'Could not save the task.';
        }
    } elseif ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['description'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? ''));
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $due = '';
        }
        $priority = ((string) ($_POST['priority'] ?? '')) === 'high' ? 'high' : 'medium';
        $status = in_array((string) ($_POST['status'] ?? ''), ['pending', 'in_progress', 'done'], true)
            ? (string) $_POST['status']
            : 'pending';
        if ($id <= 0 || !$owns($id)) {
            $errors[] = 'You cannot edit this task.';
        } elseif ($title === '') {
            $errors[] = 'Write what you need to do.';
        } elseif (safe_db_run(
            'UPDATE tasks SET title = :t, description = :d, due_date = :due, priority = :p, status = :st, updated_at = NOW() WHERE id = :id',
            [
                ':t' => $title,
                ':d' => $note !== '' ? $note : null,
                ':due' => $due !== '' ? $due : null,
                ':p' => $priority,
                ':st' => $status,
                ':id' => $id,
            ]
        )) {
            header('Location: ?saved=1');
            exit;
        } else {
            $errors[] = 'Could not update.';
        }
    } elseif ($action === 'done') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $owns($id) && safe_db_run("UPDATE tasks SET status = 'done', updated_at = NOW() WHERE id = :id", [':id' => $id])) {
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not mark done.';
    } elseif ($action === 'reopen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $owns($id) && safe_db_run("UPDATE tasks SET status = 'pending', updated_at = NOW() WHERE id = :id", [':id' => $id])) {
            header('Location: ?tab=done&saved=1');
            exit;
        }
        $errors[] = 'Could not reopen.';
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if (!$owns($deleteId)) {
        $errors[] = 'You cannot delete this task.';
    } elseif (safe_db_run('DELETE FROM tasks WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if (!empty($_GET['saved'])) {
    $messages[] = 'Saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Task removed.';
}

$tab = ((string) ($_GET['tab'] ?? 'open')) === 'done' ? 'done' : 'open';
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0 && $owns($editId)) {
    $editRow = safe_db_get_one('SELECT * FROM tasks WHERE id = :id LIMIT 1', [':id' => $editId]);
}

$openCount = 0;
$doneCount = 0;
$overdueCount = 0;
$tasks = [];
if ($tableOk && $teacherId > 0) {
    $openCount = (int) (safe_db_get_one(
        "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = :u AND status <> 'done'",
        [':u' => $teacherId]
    )['c'] ?? 0);
    $doneCount = (int) (safe_db_get_one(
        "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = :u AND status = 'done'",
        [':u' => $teacherId]
    )['c'] ?? 0);
    $overdueCount = (int) (safe_db_get_one(
        "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = :u AND status <> 'done' AND due_date IS NOT NULL AND due_date < CURDATE()",
        [':u' => $teacherId]
    )['c'] ?? 0);
    $statusSql = $tab === 'done' ? "status = 'done'" : "status <> 'done'";
    $tasks = safe_db_get_all(
        "SELECT id, title, description, due_date, priority, status
         FROM tasks
         WHERE assigned_to = :u AND {$statusSql}
         ORDER BY CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC, id DESC
         LIMIT 80",
        [':u' => $teacherId]
    ) ?: [];
}

$fmt = static function (?string $d): string {
    $d = substr((string) $d, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M', $t) : $d;
};

$page_title = 'My to-do';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.todo-add { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:12px; }
.todo-list { background:#fff; border:1px solid #dbe7fb; border-radius:16px; overflow:hidden; }
.todo-item { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-bottom:1px solid #f0f4fb; }
.todo-item:last-child { border-bottom:0; }
.todo-item.overdue { background:#fff8f7; }
.todo-check { width:28px; height:28px; border-radius:50%; border:2px solid #94a3b8; background:#fff; color:transparent; font-weight:800; line-height:1; padding:0; flex:0 0 28px; margin-top:2px; }
.todo-check:hover { border-color:#16a34a; color:#16a34a; }
.todo-item.done .todo-check { background:#16a34a; border-color:#16a34a; color:#fff; }
.todo-item.done .todo-title { text-decoration:line-through; color:#94a3b8; }
.todo-title { font-weight:800; color:#1e3a5f; }
.todo-meta { font-size:.85rem; color:#64748b; }
.todo-urgent { color:#b42318; font-weight:700; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Tasks are not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">Your checklist — things to do in class or follow up with parents. Tick the circle when done.</p>

  <form method="post" class="todo-add">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="<?php echo $editRow ? 'save' : 'add'; ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>
    <div class="fw-bold mb-2"><?php echo $editRow ? 'Edit task' : 'Add a task'; ?></div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" name="title" required maxlength="255" placeholder="e.g. Call Aanya’s parent about bag" value="<?php echo e((string) ($editRow['title'] ?? '')); ?>" style="flex:1; min-width:200px">
      <input type="date" name="due_date" class="form-control" style="max-width:160px" value="<?php echo e(substr((string) ($editRow['due_date'] ?? ''), 0, 10)); ?>">
      <button class="btn btn-success" type="submit"><?php echo $editRow ? 'Save' : 'Add'; ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="?">Cancel</a><?php endif; ?>
    </div>
    <textarea class="form-control mb-2" name="description" rows="2" placeholder="Optional note"><?php echo e((string) ($editRow['description'] ?? '')); ?></textarea>
    <div class="d-flex flex-wrap gap-3 align-items-center">
      <label class="form-check mb-0">
        <input class="form-check-input" type="checkbox" name="priority" value="high" <?php echo (($editRow['priority'] ?? '') === 'high') ? 'checked' : ''; ?>>
        <span class="form-check-label small">Urgent</span>
      </label>
      <?php if ($editRow): ?>
        <input type="hidden" name="status" value="<?php echo e((string) ($editRow['status'] ?? 'pending')); ?>">
      <?php endif; ?>
    </div>
  </form>

  <div class="small mb-2">
    <a class="<?php echo $tab === 'open' ? 'fw-bold' : ''; ?>" href="?tab=open">To do (<?php echo (int) $openCount; ?>)</a>
    <span class="text-muted"> · </span>
    <a class="<?php echo $tab === 'done' ? 'fw-bold' : ''; ?>" href="?tab=done">Finished (<?php echo (int) $doneCount; ?>)</a>
    <?php if ($overdueCount > 0 && $tab === 'open'): ?>
      <span class="todo-urgent ms-2"><?php echo (int) $overdueCount; ?> overdue</span>
    <?php endif; ?>
  </div>

  <div class="todo-list">
    <?php if ($tasks === []): ?>
      <div class="p-4 text-center text-muted"><?php echo $tab === 'done' ? 'Nothing finished yet.' : 'Nothing to do. Type above and press Add.'; ?></div>
    <?php else: ?>
      <?php foreach ($tasks as $t):
          $tid = (int) ($t['id'] ?? 0);
          $due = substr((string) ($t['due_date'] ?? ''), 0, 10);
          $overdue = $tab === 'open' && $due !== '' && $due < $today = date('Y-m-d');
          $isDone = $tab === 'done';
          $pri = (string) ($t['priority'] ?? 'medium');
          ?>
        <div class="todo-item<?php echo $overdue ? ' overdue' : ''; ?><?php echo $isDone ? ' done' : ''; ?>">
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="<?php echo $isDone ? 'reopen' : 'done'; ?>">
            <input type="hidden" name="id" value="<?php echo $tid; ?>">
            <button class="todo-check" type="submit" title="<?php echo $isDone ? 'Move back to to-do' : 'Mark done'; ?>">✓</button>
          </form>
          <div class="flex-grow-1">
            <div class="todo-title"><?php echo e((string) ($t['title'] ?? '')); ?></div>
            <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
              <div class="small" style="white-space:pre-wrap"><?php echo e((string) $t['description']); ?></div>
            <?php endif; ?>
            <div class="todo-meta">
              <?php if ($due !== ''): ?><?php echo e($fmt($due)); ?><?php endif; ?>
              <?php if ($overdue): ?> · <span class="todo-urgent">Overdue</span><?php endif; ?>
              <?php if ($pri === 'high'): ?> · <span class="todo-urgent">Urgent</span><?php endif; ?>
            </div>
          </div>
          <div class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo $tid; ?>">Edit</a>
            <?php echo render_secure_delete_button($tid, 'Delete', 'Remove this task?', 'btn btn-sm btn-outline-danger'); ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
