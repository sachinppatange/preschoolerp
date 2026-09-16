<?php
/**
 * teacher/timetable.php
 *
 * Timetable - Add / View entries (teacher)
 *
 * Change requested: provide a text box for Subject (free text) in addition to existing subject_id dropdown.
 * Behavior:
 *  - Shows classes assigned to the logged-in teacher (classes.teacher_id or teacher_classes mapping).
 *  - Subject input:
 *      * If timetable table has a text column for subject (subject_name / subject_text / subject), the entered text is stored there.
 *      * Else, if a subjects table exists, a new subject row will be inserted and its id used.
 *      * Else, if subject_id dropdown chosen, that will be used.
 *  - Uses CSRF protection and PDO prepared statements.
 *  - Safe: detects available columns and tables, avoids redeclaring functions if project provides them.
 *
 * Place this file at: /pioneerplayschool01/teacher/timetable.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* ---------- Config ---------- */
$TT_TABLE = 'timetable';
$AUTO_CREATE_TABLE = false; // set true for local/dev to auto-create timetable table

/* ---------- Require teacher login ---------- */
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ---------- Determine classes assigned to this teacher ---------- */
$assignedClasses = panel_teacher_assigned_classes($teacherId);
$assignedClassIds = array_map(fn($c)=>(int)$c['id'], $assignedClasses);

$ttExists = table_exists($TT_TABLE);
if (!$ttExists && $AUTO_CREATE_TABLE) {
    $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$TT_TABLE}` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT DEFAULT NULL,
  `class_id` INT NOT NULL,
  `day_of_week` VARCHAR(16) NOT NULL,
  `period` VARCHAR(32) DEFAULT NULL,
  `start_time` TIME DEFAULT NULL,
  `end_time` TIME DEFAULT NULL,
  `subject_id` INT DEFAULT NULL,
  `subject_name` VARCHAR(255) DEFAULT NULL,
  `teacher_id` INT DEFAULT NULL,
  `room` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  INDEX `idx_class_day` (`class_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    safe_db_run($createSql);
    $ttExists = table_exists($TT_TABLE);
}

/* ---------- Detect which subject columns are available ---------- */
$ttCols = $ttExists ? get_table_columns($TT_TABLE) : [];
$col_tt_subject_id = in_array('subject_id', $ttCols, true) ? 'subject_id' : null;
$col_tt_subject_text = null;
foreach (['subject_name','subject_text','subject'] as $c) { if (in_array($c, $ttCols, true)) { $col_tt_subject_text = $c; break; } }

/* ---------- Subjects table detection (for creating new subjects if needed) ---------- */
$subjectTable = null;
foreach (['subjects','subject','subjects_master','subject_master','subject_list'] as $t) {
    if (table_exists($t)) { $subjectTable = $t; break; }
}
$subjectDisplayCol = null;
if ($subjectTable) {
    $subCols = get_table_columns($subjectTable);
    foreach (['name','title','subject_name','subject'] as $c) { if (in_array($c, $subCols, true)) { $subjectDisplayCol = $c; break; } }
    $subjectIdCol = in_array('id', $subCols, true) ? 'id' : (in_array('subject_id', $subCols, true) ? 'subject_id' : null);
}

$teachers = [];
$tTable = null;
if (table_exists('teachers')) $tTable = 'teachers';
elseif (table_exists('staff')) $tTable = 'staff';
elseif (table_exists('users')) $tTable = 'users';
if ($tTable) {
    $cols = get_table_columns($tTable);
    if (in_array('name', $cols, true)) {
        $rows = safe_db_get_all("SELECT id, name FROM `$tTable` ORDER BY name ASC");
        foreach ($rows as $r) $teachers[] = ['id'=>(int)$r['id'],'label'=>$r['name']];
    } else {
        $rows = safe_db_get_all("SELECT id, first_name, last_name FROM `$tTable` ORDER BY id ASC");
        foreach ($rows as $r) $teachers[] = ['id'=>(int)$r['id'],'label'=>trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))];
    }
}

/* ---------- POST: create timetable entry (handles subject_text logic) ---------- */
$messages = []; $errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'create')) {
    // CSRF
    if (!validate_csrf_token($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (empty($assignedClassIds)) {
        $errors[] = 'You are not assigned to any class.';
    } elseif (!$ttExists) {
        $errors[] = "Timetable table '{$TT_TABLE}' does not exist.";
    } else {
        // read inputs
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $day = isset($_POST['day_of_week']) ? trim($_POST['day_of_week']) : '';
        $period = isset($_POST['period']) ? trim($_POST['period']) : null;
        $start_time = isset($_POST['start_time']) && $_POST['start_time'] !== '' ? trim($_POST['start_time']) : null;
        $end_time = isset($_POST['end_time']) && $_POST['end_time'] !== '' ? trim($_POST['end_time']) : null;
        $subject_id_post = isset($_POST['subject_id']) && $_POST['subject_id'] !== '' ? (int)$_POST['subject_id'] : null;
        $subject_text = isset($_POST['subject_text']) ? trim($_POST['subject_text']) : '';
        $teacher_id = isset($_POST['teacher_id']) && $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
        $room = isset($_POST['room']) ? trim($_POST['room']) : null;

        // validations
        if ($class_id <= 0) $errors[] = 'Please select a class.';
        elseif (!in_array($class_id, $assignedClassIds, true)) $errors[] = 'Invalid class selection. Choose one of your assigned classes.';
        if ($day === '') $errors[] = 'Please select a day.';
        if (empty($period) && empty($start_time)) $errors[] = 'Provide period label or start time.';
        if ($start_time !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) $errors[] = 'Start time invalid format.';
        if ($end_time !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) $errors[] = 'End time invalid format.';

        if (empty($errors)) {
            $pdo = pdo_connect();
            if (!($pdo instanceof PDO)) {
                $errors[] = 'Database connection failed.';
            } else {
                try {
                    // Decide how to store subject:
                    // Priority:
                    // 1) If subject_text provided and timetable has a subject text column -> store subject text there and subject_id as provided (optional)
                    // 2) Else if subject_text provided and subjects table exists -> insert new subject (if not exists) and use its id
                    // 3) Else if subject_id selected -> use subject_id
                    $use_subject_id = null;
                    $use_subject_text = null;

                    if ($subject_text !== '') {
                        if ($col_tt_subject_text) {
                            $use_subject_text = $subject_text;
                            // if user also selected subject_id, keep it; else null
                            $use_subject_id = $subject_id_post;
                        } elseif ($subjectTable && $subjectIdCol && $subjectDisplayCol) {
                            // try to find existing subject with same display value
                            $found = safe_db_get_one("SELECT `{$subjectIdCol}` AS id FROM `{$subjectTable}` WHERE `{$subjectDisplayCol}` = :val LIMIT 1", [':val'=>$subject_text]);
                            if ($found && !empty($found['id'])) {
                                $use_subject_id = (int)$found['id'];
                            } else {
                                // insert new subject
                                $ins = $pdo->prepare("INSERT INTO `{$subjectTable}` (`{$subjectDisplayCol}`) VALUES (:val)");
                                $ins->execute([':val'=>$subject_text]);
                                $use_subject_id = (int)$pdo->lastInsertId();
                            }
                        } else {
                            // cannot store text anywhere; fall back to subject_id_post if provided
                            $use_subject_id = $subject_id_post;
                        }
                    } else {
                        // no subject text provided, use subject_id if any
                        $use_subject_id = $subject_id_post;
                    }

                    // Build insert columns depending on table structure
                    $insertCols = ['class_id','day_of_week','period','start_time','end_time','teacher_id','room','created_at'];
                    $placeholders = [':class_id',':day',':period',':start_time',':end_time',':teacher_id',':room',':created_at'];
                    $params = [
                        ':class_id' => $class_id,
                        ':day' => $day,
                        ':period' => $period,
                        ':start_time' => $start_time,
                        ':end_time' => $end_time,
                        ':teacher_id' => $teacher_id,
                        ':room' => $room,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ];

                    if ($col_tt_subject_id && $use_subject_id !== null) {
                        $insertCols[] = $col_tt_subject_id;
                        $placeholders[] = ':subject_id';
                        $params[':subject_id'] = $use_subject_id;
                    }
                    if ($col_tt_subject_text && $use_subject_text !== null) {
                        $insertCols[] = $col_tt_subject_text;
                        $placeholders[] = ':subject_text';
                        $params[':subject_text'] = $use_subject_text;
                    }

                    $sql = "INSERT INTO `{$TT_TABLE}` (" . implode(',', array_map(fn($c) => "`{$c}`", $insertCols)) . ") VALUES (" . implode(',', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    // success: redirect (PRG)
                    $_SESSION['timetable_msg'] = 'Timetable entry added successfully.';
                    $redirect = strtok($_SERVER['REQUEST_URI'], '?') . '?class_id=' . $class_id;
                    header('Location: ' . $redirect);
                    exit;
                } catch (Throwable $e) {
                    $errors[] = 'Failed to save entry.' . (defined('DEV_SHOW_ERRORS') && DEV_SHOW_ERRORS ? ' ' . $e->getMessage() : '');
                }
            }
        }
    }
}

/* show session message */
if (!empty($_SESSION['timetable_msg'])) { $messages[] = $_SESSION['timetable_msg']; unset($_SESSION['timetable_msg']); }

$subjects = [];
if ($subjectTable && $subjectDisplayCol && $subjectIdCol) {
    $rows = safe_db_get_all("SELECT `$subjectIdCol` AS id, `$subjectDisplayCol` AS label FROM `$subjectTable` ORDER BY `$subjectDisplayCol` ASC");
    foreach ($rows as $r) $subjects[] = ['id'=>(int)$r['id'],'label'=>$r['label']];
} else {
    // fallback: distinct subject_id values from timetable (to show something if present)
    if ($ttExists) {
        $rows = safe_db_get_all("SELECT DISTINCT subject_id FROM `{$TT_TABLE}` WHERE subject_id IS NOT NULL AND subject_id != ''");
        foreach ($rows as $r) {
            $sid = isset($r['subject_id']) ? (int)$r['subject_id'] : 0;
            if ($sid > 0) $subjects[] = ['id'=>$sid,'label'=>'Subject #' . $sid];
        }
    }
}

/* ---------- Fetch classes for preview (only assigned classes shown) ---------- */
$selected_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($assignedClassIds[0] ?? 0);
$entries = [];
if ($ttExists && $selected_class > 0 && in_array($selected_class, $assignedClassIds, true)) {
    $entries = safe_db_get_all("SELECT * FROM `{$TT_TABLE}` WHERE class_id = :cid ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), COALESCE(period,'') ASC, start_time ASC", [':cid'=>$selected_class]);
}

/* ---------- CSRF token ---------- */
$csrf = get_csrf_token();

/* ---------- Render page ---------- */
$pageTitle = 'Timetable - Add Entry';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if (empty($assignedClasses)): ?>
  <div class="alert alert-warning">You have no assigned classes. Contact administrator to assign classes to you.</div>
<?php endif; ?>

<?php if (!$ttExists): ?>
  <div class="alert alert-warning">Timetable table '<?php echo e($TT_TABLE); ?>' not found. Create it first or enable AUTO_CREATE_TABLE in the script.</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="create">

      <div class="col-md-4">
        <label class="form-label small">Class</label>
        <select name="class_id" class="form-select" required <?php if (empty($assignedClasses)) echo 'disabled'; ?>>
          <option value="">Select class</option>
          <?php foreach ($assignedClasses as $c): $cid=(int)$c['id']; $label = (!empty($c['short_name']) ? $c['short_name'].' ' : '') . ($c['name'] ?? ''); ?>
            <option value="<?php echo $cid; ?>" <?php if ($selected_class === $cid) echo 'selected'; ?>><?php echo e($label ?: ('Class #'.$cid)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label small">Day</label>
        <select name="day_of_week" class="form-select" required>
          <option value="">Select day</option>
          <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
            <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label small">Period</label>
        <input name="period" class="form-control" placeholder="e.g. 1, 2, Morning">
      </div>

      <div class="col-md-3">
        <label class="form-label small">Start time</label>
        <input type="time" name="start_time" class="form-control">
      </div>

      <div class="col-md-3">
        <label class="form-label small">End time</label>
        <input type="time" name="end_time" class="form-control">
      </div>

      <div class="col-md-4">
        <label class="form-label small">Subject (choose or type)</label>
        <div class="input-group">
          <select name="subject_id" class="form-select">
            <option value="">Select existing subject (optional)</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['label']); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="subject_text" class="form-control" placeholder="Or type subject here (will be saved)">
        </div>
        <div class="form-text small">If you type text, it will be stored. If timetable supports subject text column it's saved there; otherwise a new subject will be created if possible.</div>
      </div>

      <div class="col-md-3">
        <label class="form-label small">Teacher</label>
        <select name="teacher_id" class="form-select">
          <option value="">Select teacher (optional)</option>
          <?php foreach ($teachers as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-5">
        <label class="form-label small">Room</label>
        <input name="room" class="form-control" placeholder="e.g. A101">
      </div>

      <div class="col-12 text-end">
        <button class="btn btn-primary" <?php if (empty($assignedClasses) || !$ttExists) echo 'disabled'; ?>>Add Timetable Entry</button>
      </div>
    </form>
  </div>
</div>

<?php if ($ttExists && $selected_class > 0): ?>
  <div class="card">
    <div class="card-body">
      <h6>Timetable for <?php $cn = array_values(array_filter($assignedClasses, fn($c)=> (int)$c['id'] === $selected_class)); echo e($cn[0]['name'] ?? ('Class #'.$selected_class)); ?></h6>
      <?php if (empty($entries)): ?>
        <div class="small-muted">No entries yet.</div>
      <?php else: ?>
        <table class="table table-sm mt-2">
          <thead><tr><th>#</th><th>Day</th><th>Period</th><th>Start</th><th>End</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead>
          <tbody>
            <?php $i=1; foreach ($entries as $r): ?>
              <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo e($r['day_of_week'] ?? $r['day'] ?? ''); ?></td>
                <td><?php echo e($r['period'] ?? ''); ?></td>
                <td><?php echo e($r['start_time'] ?? ''); ?></td>
                <td><?php echo e($r['end_time'] ?? ''); ?></td>
                <td><?php
                    // subject display: prefer subject text column, else try subject_id mapping, else show id
                    $subLabel = '';
                    if ($col_tt_subject_text && !empty($r[$col_tt_subject_text])) $subLabel = $r[$col_tt_subject_text];
                    elseif (!empty($r['subject_name'])) $subLabel = $r['subject_name'];
                    elseif (!empty($r['subject']) && !is_numeric($r['subject'])) $subLabel = $r['subject'];
                    elseif (!empty($r['subject_id'])) {
                        // try to map to subjects table label
                        if ($subjectTable && $subjectDisplayCol && $subjectIdCol) {
                            $srow = safe_db_get_one("SELECT `$subjectDisplayCol` AS label FROM `$subjectTable` WHERE `$subjectIdCol` = :id LIMIT 1", [':id'=>$r['subject_id']]);
                            $subLabel = $srow['label'] ?? ('Subject #'.$r['subject_id']);
                        } else $subLabel = 'Subject #'.$r['subject_id'];
                    } else $subLabel = '';
                    echo e($subLabel);
                ?></td>
                <td><?php echo e($r['teacher_id'] ?? ''); ?></td>
                <td><?php echo e($r['room'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>