<?php
/**
 * teacher/attendance_mark.php
 *
 * Attendance marking page for teachers.
 *
 * Behavior:
 * - Only logged-in teacher (session $_SESSION['teacher_auth_user']) can access.
 * - Finds classes assigned to teacher (classes.teacher_id or teacher_classes mapping).
 * - Teacher selects class and date (default today), sees students for that class (students.class_id).
 * - Saves attendance into `attendance` table with the schema you provided:
 *     id, school_id, student_id, class_id, date, status, recorded_by, notes, created_at
 * - For each student: tries to find existing attendance row for same class_id + student_id + date.
 *     - If found: updates status, notes, recorded_by.
 *     - Else: inserts new row (school_id taken from students.school_id when present).
 * - Uses CSRF protection and prepared statements. Reuses project's includes when available.
 *
 * Place at: /pioneerplayschool01/teacher/attendance_mark.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

if (!function_exists('validate_csrf_token') && file_exists(__DIR__ . '/../includes/csrf.php')) {
    require_once __DIR__ . '/../includes/csrf.php';
}

if (!defined('DEV_SHOW_ERRORS')) define('DEV_SHOW_ERRORS', false);
$envDebug = getenv('DEV_SHOW_ERRORS');
if ($envDebug !== false) {
    $envVal = strtolower((string)$envDebug);
    } else {
    $DEBUG = (bool) DEV_SHOW_ERRORS;
}

/* ---------- Config & defaults ---------- */
$validStatuses = ['present','absent','late','excused'];
$defaultDate = date('Y-m-d');

/* ---------- Teacher identity ---------- */
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ---------- Find assigned classes for teacher ---------- */
$assignedClasses = panel_teacher_assigned_classes($teacherId);

/* ---------- UI state (selected class & date) ---------- */
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : (isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0);
if ($selectedClassId === 0 && !empty($assignedClasses)) $selectedClassId = (int)$assignedClasses[0]['id'];
// validate teacher owns
$allowedIds = [];
foreach ($assignedClasses as $r) $allowedIds[] = (int)$r['id'];
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedIds, true)) $selectedClassId = 0;

$selectedDate = trim((string)(isset($_GET['date']) ? $_GET['date'] : (isset($_POST['date']) ? $_POST['date'] : $defaultDate)));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = $defaultDate;

/* ---------- Handle POST: save attendance into attendance table schema provided ---------- */
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming_csrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!validate_csrf_token($incoming_csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $date = trim((string)($_POST['date'] ?? $defaultDate));
        if ($class_id <= 0 || !in_array($class_id, $allowedIds, true)) {
            $errors[] = 'You are not authorized to mark attendance for this class.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'Invalid date.';
        } else {
            $submitted = isset($_POST['status']) && is_array($_POST['status']) ? $_POST['status'] : [];
            if (!table_exists('attendance')) {
                $errors[] = 'Attendance table does not exist. Please create the attendance table with the required schema.';
            } else {
                $pdo = pdo_connect();
                if (!($pdo instanceof PDO)) {
                    $errors[] = 'Database connection failed.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // prepare statements
                        $selStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = :class_id AND student_id = :student_id AND `date` = :date LIMIT 1");
                        $updStmt = $pdo->prepare("UPDATE attendance SET status = :status, notes = :notes, recorded_by = :recorded_by WHERE id = :id");
                        $insStmt = $pdo->prepare("INSERT INTO attendance (school_id, student_id, class_id, `date`, status, recorded_by, notes, created_at) VALUES (:school_id, :student_id, :class_id, :date, :status, :recorded_by, :notes, NOW())");

                        // To set school_id on insert, we fetch student.school_id when needed.
                        $studentSchoolCache = [];

                        foreach ($submitted as $studentIdStr => $statusVal) {
                            $student_id = (int)$studentIdStr;
                            $status = strtolower(trim((string)$statusVal));
                            if (!in_array($status, $validStatuses, true)) $status = 'absent';
                            $notes = isset($_POST['notes'][$studentIdStr]) ? trim((string)$_POST['notes'][$studentIdStr]) : '';

                            // check existing
                            $selStmt->execute([':class_id'=>$class_id, ':student_id'=>$student_id, ':date'=>$date]);
                            $found = $selStmt->fetch(PDO::FETCH_ASSOC);

                            if ($found && !empty($found['id'])) {
                                // update
                                $updStmt->execute([':status'=>$status, ':notes'=>$notes, ':recorded_by'=>$teacherId, ':id'=>$found['id']]);
                            } else {
                                // determine school_id from students table if available
                                if (!isset($studentSchoolCache[$student_id])) {
                                    $srow = safe_db_get_one("SELECT school_id FROM students WHERE id = :id LIMIT 1", [':id'=>$student_id]);
                                    $studentSchoolCache[$student_id] = $srow['school_id'] ?? null;
                                }
                                $school_id = $studentSchoolCache[$student_id] ?? null;

                                $insStmt->execute([
                                    ':school_id'   => $school_id,
                                    ':student_id'  => $student_id,
                                    ':class_id'    => $class_id,
                                    ':date'        => $date,
                                    ':status'      => $status,
                                    ':recorded_by' => $teacherId,
                                    ':notes'       => $notes
                                ]);
                            }
                        }

                        $pdo->commit();
                        $messages[] = 'Attendance saved for ' . e($date) . '.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = 'Failed to save attendance.' . ($DEBUG ? ' ' . $e->getMessage() : '');
                    }
                }
            }
        }
    }
}

/* ---------- Load students for selected class ---------- */
$students = [];
$totalStudents = 0;
if ($selectedClassId > 0 && table_exists('students')) {
    $cnt = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE class_id = :cid AND (status IS NULL OR status = 'active')", [':cid'=>$selectedClassId]);
    $totalStudents = intval($cnt['cnt'] ?? 0);
    if ($totalStudents > 0) {
        $students = safe_db_get_all(
            "SELECT id, first_name, middle_name, last_name, form_no, dob, father_phone, mother_phone, photo_path, admission_date
             FROM students
             WHERE class_id = :cid AND (status IS NULL OR status = 'active')
             ORDER BY (form_no IS NULL), form_no ASC, first_name ASC",
            [':cid'=>$selectedClassId]
        );
    }
}

$existing = [];
if (!empty($students) && table_exists('attendance')) {
    $ids = array();
    foreach ($students as $s) $ids[] = (int)$s['id'];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$selectedClassId, $selectedDate], $ids);
        $rows = safe_db_get_all("SELECT student_id, status, notes FROM attendance WHERE class_id = ? AND `date` = ? AND student_id IN ($ph)", $params);
        foreach ($rows as $r) $existing[(int)$r['student_id']] = $r;
    }
}

/* ---------- CSRF token ---------- */
$csrf = get_csrf_token();

$pageTitle = 'Mark Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($DEBUG): ?>
  <div class="alert alert-info small">Debug: teacherId=<?php echo e($teacherId); ?> selectedClassId=<?php echo e($selectedClassId); ?> selectedDate=<?php echo e($selectedDate); ?></div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label small">Class</label>
        <select name="class_id" class="form-select">
          <?php foreach ($assignedClasses as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$c['id'] === $selectedClassId) echo 'selected'; ?>><?php echo e(trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')))); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <label class="form-label small">Date</label>
        <input type="date" name="date" class="form-control" value="<?php echo e($selectedDate); ?>">
      </div>

      <div class="col-auto">
        <button class="btn btn-primary">Load</button>
      </div>
    </form>

    <?php if ($selectedClassId === 0): ?>
      <div class="small-muted">Select a class to begin marking attendance.</div>
    <?php else: ?>

      <form method="post" class="attendance-form">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="class_id" value="<?php echo (int)$selectedClassId; ?>">
        <input type="hidden" name="date" value="<?php echo e($selectedDate); ?>">

        <div class="mb-2 d-flex justify-content-between">
          <div><strong>Class:</strong> #<?php echo e($selectedClassId); ?> <div class="small-muted">Date: <?php echo e($selectedDate); ?></div></div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-success" id="markAllPresent">All Present</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="markAllAbsent">All Absent</button>
            <button type="submit" class="btn btn-sm btn-primary">Save Attendance</button>
          </div>
        </div>

        <?php if (empty($students)): ?>
          <div class="small-muted">No students in this class.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Photo</th>
                  <th>Form No</th>
                  <th>Name</th>
                  <th>DOB</th>
                  <th style="width:160px">Status</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php $i=1; foreach ($students as $s): 
                  $sid = (int)$s['id'];
                  $fullname = trim((($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? '')));
                  $pref = isset($existing[$sid]) ? $existing[$sid] : null;
                  $prefStatus = $pref['status'] ?? 'present';
                  $prefNotes = $pref['notes'] ?? $pref['notes'] ?? '';
                ?>
                  <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php if (!empty($s['photo_path'])): ?><img src="<?php echo e($s['photo_path']); ?>" class="student-photo" alt="photo"><?php else: ?><div style="width:40px;height:40px;background:#f1f1f1;border-radius:4px"></div><?php endif; ?></td>
                    <td><?php echo e($s['form_no'] ?? '—'); ?></td>
                    <td><?php echo e($fullname ?: ('Student #' . $sid)); ?><br><small class="small-muted">Admitted: <?php echo e(substr($s['admission_date'] ?? '',0,10) ?: '—'); ?></small></td>
                    <td><?php echo e(substr($s['dob'] ?? '',0,10) ?: '—'); ?></td>
                    <td>
                      <select name="status[<?php echo $sid; ?>]" class="form-select status-select">
                        <?php foreach ($validStatuses as $st): ?>
                          <option value="<?php echo e($st); ?>" <?php if ($st === $prefStatus) echo 'selected'; ?>><?php echo ucfirst($st); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="text" name="notes[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo e($prefNotes); ?>" placeholder="Optional notes"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="mt-3 text-end">
          <button type="submit" class="btn btn-primary">Save Attendance</button>
        </div>
      </form>
    <?php endif; ?>

  </div>
</div>

<script>
(function(){
  var markAllPresent = document.getElementById('markAllPresent');
  var markAllAbsent = document.getElementById('markAllAbsent');
  if (markAllPresent) {
    markAllPresent.addEventListener('click', function(){
      document.querySelectorAll('.status-select').forEach(function(s){ s.value = 'present'; });
    });
  }
  if (markAllAbsent) {
    markAllAbsent.addEventListener('click', function(){
      document.querySelectorAll('.status-select').forEach(function(s){ s.value = 'absent'; });
    });
  }
})();
</script>

<?php
/* ---------- Footer ---------- */
require_once __DIR__ . '/../includes/footer.php';

?>