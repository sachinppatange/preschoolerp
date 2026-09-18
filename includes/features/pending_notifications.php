<?php
/**
 * Owner staff reminders — simple inbox from the `notifications` table.
 * Tell a teacher or receptionist something. Tick Seen when done.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Staff reminders');
$pageTitle = $page_title;

$userId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
if ($schoolId < 1) {
    $schoolId = 1;
}
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$esc = static fn(string $v): string => e($v);
$todoUrl = function_exists('site_url') ? site_url('/' . $panel . '/pending_tasks.php') : 'pending_tasks.php';
$noticeUrl = function_exists('site_url') ? site_url('/' . $panel . '/notices_publish.php') : 'notices_publish.php';

if (!function_exists('table_exists') || !table_exists('notifications')) {
    require_once __DIR__ . '/../header.php';
    echo '<div class="alert alert-danger">The notifications table was not found.</div>';
    require_once __DIR__ . '/../footer.php';
    exit;
}

$col = static function (string $name): bool {
    return function_exists('column_exists') && column_exists('notifications', $name);
};

$csrfOk = static function () use ($csrf): bool {
    return function_exists('validate_csrf_token') && validate_csrf_token((string) ($_POST['csrf'] ?? $_POST['csrf_token'] ?? ''));
};

$staffList = [];
if (function_exists('table_exists') && table_exists('users')) {
    try {
        $staffList = safe_db_get_all("SELECT id, name FROM users WHERE role <> 'parent' ORDER BY name ASC") ?: [];
    } catch (Throwable $e) {
        $staffList = safe_db_get_all('SELECT id, name FROM users ORDER BY name ASC') ?: [];
    }
}

$messages = [];
$errors = [];
$action = (string) ($_REQUEST['action'] ?? 'list');

$saveFields = static function (array $data, int $id) use ($col, $schoolId): bool {
    $fields = [];
    $params = [];
    foreach ($data as $k => $v) {
        if ($col($k)) {
            $fields[$k] = $v;
        }
    }
    if ($id <= 0 && $col('school_id') && !array_key_exists('school_id', $fields)) {
        $fields['school_id'] = $schoolId;
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
        return (bool) safe_db_run('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
    }
    $cols = [];
    $ph = [];
    foreach ($fields as $k => $v) {
        $cols[] = $k;
        $ph[] = ':' . $k;
        $params[':' . $k] = $v;
    }
    return (bool) safe_db_run(
        'INSERT INTO notifications (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')',
        $params
    );
};

if ($action === 'get' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) $_GET['id'];
    $row = $id > 0 ? safe_db_get_one(
        'SELECT id, title, message, assigned_to, DATE_FORMAT(due_date, \'%Y-%m-%d\') AS due_date, priority, status, is_read
         FROM notifications WHERE id = :id LIMIT 1',
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
        $note = trim((string) ($_POST['message'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $assigned = ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : $userId;
        $urgent = ((string) ($_POST['priority'] ?? '')) === 'high';
        if ($title === '') {
            $errors[] = 'Type a short reminder.';
        } else {
            $ok = $saveFields([
                'type' => 'alert',
                'title' => $title,
                'message' => $note !== '' ? $note : $title,
                'assigned_to' => $assigned > 0 ? $assigned : null,
                'due_date' => $due,
                'priority' => $urgent ? 'high' : 'medium',
                'status' => 'pending',
                'level' => $urgent ? 'warning' : 'info',
                'is_read' => 0,
            ], 0);
            if ($ok) {
                header('Location: ?added=1');
                exit;
            }
            $errors[] = 'Could not save reminder.';
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $note = trim((string) ($_POST['message'] ?? ''));
        $due = trim((string) ($_POST['due_date'] ?? '')) ?: null;
        $assigned = ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null;
        $urgent = ((string) ($_POST['priority'] ?? '')) === 'high';
        $seen = ((string) ($_POST['status'] ?? '')) === 'done';
        if ($id <= 0 || $title === '') {
            $errors[] = 'Reminder text is required.';
        } else {
            $ok = $saveFields([
                'title' => $title,
                'message' => $note !== '' ? $note : $title,
                'assigned_to' => $assigned,
                'due_date' => $due,
                'priority' => $urgent ? 'high' : 'medium',
                'status' => $seen ? 'done' : 'pending',
                'is_read' => $seen ? 1 : 0,
            ], $id);
            if ($ok) {
                header('Location: ?saved=1');
                exit;
            }
            $errors[] = 'Could not update reminder.';
        }
    } elseif ($action === 'seen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $saveFields(['status' => 'done', 'is_read' => 1], $id)) {
            header('Location: ?saved=1');
            exit;
        }
        $errors[] = 'Could not mark seen.';
    } elseif ($action === 'reopen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $saveFields(['status' => 'pending', 'is_read' => 0], $id)) {
            header('Location: ?tab=seen&saved=1');
            exit;
        }
        $errors[] = 'Could not reopen reminder.';
    }
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    $errors[] = 'Delete requires confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    if (safe_db_run('DELETE FROM notifications WHERE id = :id', [':id' => $deleteId])) {
        header('Location: ?deleted=1');
        exit;
    }
    $errors[] = 'Delete failed.';
}

if (!empty($_GET['added'])) {
    $messages[] = 'Reminder sent.';
}
if (!empty($_GET['saved'])) {
    $messages[] = 'Saved.';
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Reminder deleted.';
}

$tab = (string) ($_GET['tab'] ?? 'new');
if (!in_array($tab, ['new', 'seen'], true)) {
    $tab = 'new';
}
$qraw = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($qraw !== '') {
    $where[] = '(n.title LIKE :q OR n.message LIKE :q)';
    $params[':q'] = '%' . $qraw . '%';
}
if ($tab === 'seen') {
    $where[] = "(n.status = 'done' OR n.is_read = 1)";
} else {
    $where[] = "(n.status <> 'done' AND n.is_read = 0)";
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$openCount = (int) (safe_db_get_one(
    "SELECT COUNT(*) AS c FROM notifications n WHERE n.status <> 'done' AND n.is_read = 0"
)['c'] ?? 0);
$seenCount = (int) (safe_db_get_one(
    "SELECT COUNT(*) AS c FROM notifications n WHERE n.status = 'done' OR n.is_read = 1"
)['c'] ?? 0);
$overdueCount = (int) (safe_db_get_one(
    "SELECT COUNT(*) AS c FROM notifications n
     WHERE n.status <> 'done' AND n.is_read = 0
       AND n.due_date IS NOT NULL AND n.due_date < CURDATE()"
)['c'] ?? 0);

$order = $tab === 'seen'
    ? 'n.updated_at DESC, n.id DESC'
    : 'CASE WHEN n.due_date IS NOT NULL AND n.due_date < CURDATE() THEN 0 ELSE 1 END, n.due_date IS NULL, n.due_date ASC, n.id DESC';

$joinUser = (function_exists('table_exists') && table_exists('users'))
    ? 'LEFT JOIN users u ON u.id = n.assigned_to'
    : '';
$nameCol = $joinUser !== '' ? "COALESCE(u.name,'') AS assignee_name" : "'' AS assignee_name";

$rows = safe_db_get_all(
    "SELECT n.*, {$nameCol}
     FROM notifications n {$joinUser}
     {$whereSql}
     ORDER BY {$order}
     LIMIT 200",
    $params
) ?: [];

require_once __DIR__ . '/../header.php';
?>
<style>
.note-add { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px; margin-bottom:12px; }
.note-list { background:#fff; border:1px solid #dbe7fb; border-radius:16px; overflow:hidden; }
.note-item { display:flex; gap:12px; align-items:flex-start; padding:12px 14px; border-bottom:1px solid #f0f4fb; }
.note-item:last-child { border-bottom:0; }
.note-item.overdue { background:#fff8f7; }
.note-item.seen .note-title { text-decoration:line-through; color:#94a3b8; }
.note-check { width:28px; height:28px; border-radius:50%; border:2px solid #94a3b8; background:#fff; color:transparent; font-weight:800; line-height:1; padding:0; flex:0 0 28px; margin-top:2px; }
.note-check:hover { border-color:#16a34a; color:#16a34a; }
.note-item.seen .note-check { background:#16a34a; border-color:#16a34a; color:#fff; }
.note-title { font-weight:700; color:#1e3a5f; background:none; border:0; padding:0; text-align:left; }
.note-title:hover { color:#0d3b8c; text-decoration:underline; }
.note-meta { font-size:.8rem; color:#64748b; }
.note-urgent { color:#b42318; font-weight:700; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Staff reminders</h1>
    <p class="text-muted mb-0">Tell a teacher or receptionist. Tick the circle when they have seen it. Your own list is <a href="<?php echo $esc($todoUrl); ?>">To-do</a>. Parents see <a href="<?php echo $esc($noticeUrl); ?>">Notices</a>.</p>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo $esc($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo $esc($er); ?></div><?php endforeach; ?>

<form method="post" class="note-add">
  <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
  <input type="hidden" name="action" value="add">
  <div class="d-flex flex-wrap gap-2">
    <input class="form-control" name="title" required maxlength="255" placeholder="Reminder, e.g. Ask teacher to send class photos" style="flex:1; min-width:220px">
    <select name="assigned_to" class="form-select" style="max-width:200px">
      <option value="<?php echo (int) $userId; ?>">Me</option>
      <?php foreach ($staffList as $s): ?>
        <option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="due_date" class="form-control" style="max-width:160px" title="By when">
    <button class="btn btn-success" type="submit">Send</button>
  </div>
  <div class="form-check mt-2 mb-0">
    <input class="form-check-input" type="checkbox" name="priority" value="high" id="noteUrgent">
    <label class="form-check-label small" for="noteUrgent">Urgent</label>
  </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="small">
    <a class="<?php echo $tab === 'new' ? 'fw-bold' : ''; ?>" href="?tab=new">New (<?php echo $openCount; ?>)</a>
    <span class="text-muted"> · </span>
    <a class="<?php echo $tab === 'seen' ? 'fw-bold' : ''; ?>" href="?tab=seen">Seen (<?php echo $seenCount; ?>)</a>
    <?php if ($overdueCount > 0 && $tab === 'new'): ?>
      <span class="note-urgent ms-2"><?php echo $overdueCount; ?> overdue</span>
    <?php endif; ?>
  </div>
  <form method="get" class="d-flex gap-1">
    <input type="hidden" name="tab" value="<?php echo $esc($tab); ?>">
    <input class="form-control form-control-sm" name="q" value="<?php echo $esc($qraw); ?>" placeholder="Search" style="width:160px">
  </form>
</div>

<div class="note-list">
  <?php if ($rows === []): ?>
    <div class="p-4 text-center text-muted"><?php echo $tab === 'seen' ? 'None seen yet.' : 'No new reminders. Type above and press Send.'; ?></div>
  <?php endif; ?>
  <?php foreach ($rows as $n):
      $due = substr((string) ($n['due_date'] ?? ''), 0, 10);
      $overdue = $tab === 'new' && $due !== '' && $due < date('Y-m-d');
      $pri = (string) ($n['priority'] ?? 'medium');
      $isSeen = $tab === 'seen';
      $label = trim((string) ($n['title'] ?? ''));
      if ($label === '') {
          $label = trim((string) ($n['message'] ?? ''));
      }
      $who = trim((string) ($n['assignee_name'] ?? ''));
  ?>
    <div class="note-item<?php echo $overdue ? ' overdue' : ''; ?><?php echo $isSeen ? ' seen' : ''; ?>">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $isSeen ? 'reopen' : 'seen'; ?>">
        <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
        <button class="note-check" type="submit" title="<?php echo $isSeen ? 'Move back to New' : 'Mark seen'; ?>">✓</button>
      </form>
      <div class="flex-grow-1">
        <button type="button" class="note-title" data-bs-toggle="modal" data-bs-target="#editNoteModal" data-id="<?php echo (int) $n['id']; ?>"><?php echo $esc($label !== '' ? $label : 'Reminder'); ?></button>
        <div class="note-meta">
          <?php echo $esc($who !== '' ? $who : 'Unassigned'); ?>
          <?php if ($due !== ''): ?> · <?php echo $esc(date('d M', strtotime($due))); ?><?php endif; ?>
          <?php if ($overdue): ?> · <span class="note-urgent">Overdue</span><?php endif; ?>
          <?php if ($pri === 'high'): ?> · <span class="note-urgent">Urgent</span><?php endif; ?>
        </div>
      </div>
      <?php echo render_secure_delete_button((int) $n['id'], '×', 'Delete this reminder?', 'btn btn-sm btn-link text-danger text-decoration-none'); ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="editNoteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="modal-header"><h5 class="modal-title">Edit reminder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label">Reminder</label><input name="title" id="edit_title" class="form-control" required maxlength="255"></div>
        <div class="col-12"><label class="form-label">Note</label><textarea name="message" id="edit_message" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6">
          <label class="form-label">Who</label>
          <select name="assigned_to" id="edit_assigned_to" class="form-select">
            <option value="">Unassigned</option>
            <?php foreach ($staffList as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>"><?php echo $esc((string) $s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">By when</label><input type="date" name="due_date" id="edit_due_date" class="form-control"></div>
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
            <option value="pending">New</option>
            <option value="done">Seen</option>
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
  var editModal = document.getElementById('editNoteModal');
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
        document.getElementById('edit_title').value = d.title || d.message || '';
        document.getElementById('edit_message').value = d.message || '';
        document.getElementById('edit_due_date').value = d.due_date || '';
        document.getElementById('edit_priority').value = d.priority === 'high' ? 'high' : 'medium';
        document.getElementById('edit_status').value = (d.status === 'done' || Number(d.is_read) === 1) ? 'done' : 'pending';
        var asg = document.getElementById('edit_assigned_to');
        if (asg) asg.value = d.assigned_to || '';
      });
  });
});
</script>
<?php require_once __DIR__ . '/../footer.php'; ?>
