<?php
/**
 * teacher/dashboard.php
 *
 * Teacher Dashboard for Pioneer Play School.
 * - Built by studying reception/dashboard.php and using the same safe DB helpers and patterns.
 * - Shows teacher-focused metrics and quick actions:
 *     - Today's attendance (teacher's classes)
 *     - My classes & students count
 *     - Pending homeworks / assignments to review
 *     - Notices / announcements relevant to teachers
 *     - Pending leave requests (if table exists)
 *     - Pending tasks assigned to teacher
 * - Provides recent lists for quick access.
 *
 * Place at: /pioneerplayschool01/teacher/dashboard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* ---------- Detect useful tables ---------- */
function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

$hasStudents = table_exists('students');
$hasClasses = table_exists('classes');
$hasAttendance = table_exists('attendance');
$hasHomeworks = table_exists('homeworks') || table_exists('assignments') || table_exists('student_homeworks');
$hasNotices = table_exists('notices');
$hasTimetable = table_exists('timetable') || table_exists('class_timetable');
$hasLeaves = table_exists('leave_requests') || table_exists('leaves');
$hasTasks = table_exists('tasks');
$hasExams = table_exists('exams') || table_exists('assessments');

/* ---------- Metrics for teacher ---------- */
$teacher = auth_user() ?? [];
$teacherId = auth_user_id() ?? 0;

$metrics = [
    'myClasses' => 0,
    'myStudents' => 0,
    'todayAttendanceMarked' => 0,
    'attendancePending' => 0,
    'pendingHomeworks' => 0,
    'activeNotices' => 0,
    'pendingLeaves' => 0,
    'myTasks' => 0,
    'upcomingExams' => 0,
];

/* My classes & students:
   We try to infer classes assigned to this teacher via classes.teacher_id or a teacher_classes mapping.
*/
$myClassIds = [];
try {
    if ($hasClasses) {
        foreach (panel_teacher_assigned_classes($teacherId) as $row) {
            $myClassIds[] = (int) ($row['id'] ?? 0);
        }
        $myClassIds = array_values(array_filter($myClassIds, static fn(int $v): bool => $v > 0));
    }
} catch (Throwable $e) { /* ignore */ }

$metrics['myClasses'] = count($myClassIds);

if ($hasStudents) {
    if (!empty($myClassIds)) {
        $placeholders = implode(',', array_fill(0, count($myClassIds), '?'));
        $sql = "SELECT COUNT(*) AS cnt FROM students WHERE class_id IN ($placeholders) AND status='active'";
        $stuParams = $myClassIds;
        if (function_exists('ay_students_have_column') && ay_students_have_column()) {
            $sql .= ' AND academic_year = ?';
            $stuParams[] = ay_selected();
        }
        $r = safe_db_get_one($sql, $stuParams);
        $metrics['myStudents'] = intval($r['cnt'] ?? 0);
    } else {
        // fallback: count of students where primary_teacher_id = teacherId
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE primary_teacher_id = :tid AND status='active'", [':tid'=>$teacherId]);
        $metrics['myStudents'] = intval($r['cnt'] ?? 0);
    }
}

/* Attendance: count today's attendance marked for my classes and students pending */
if ($hasAttendance) {
    try {
        if (!empty($myClassIds)) {
            $placeholders = implode(',', array_fill(0, count($myClassIds), '?'));
            // attendance table may have columns: class_id, date, marked_by, student_id
            $r = safe_db_get_one("SELECT COUNT(DISTINCT class_id) AS c FROM attendance WHERE DATE(date) = CURDATE() AND class_id IN ($placeholders)", $myClassIds);
            $metrics['todayAttendanceMarked'] = intval($r['c'] ?? 0);
            // total classes expected today = my classes count (approx)
            $metrics['attendancePending'] = max(0, $metrics['myClasses'] - $metrics['todayAttendanceMarked']);
        } else {
            // fallback: attendance rows marked_by = teacherId
            $r = safe_db_get_one("SELECT COUNT(DISTINCT class_id) AS c FROM attendance WHERE DATE(date) = CURDATE() AND marked_by = :tid", [':tid'=>$teacherId]);
            $metrics['todayAttendanceMarked'] = intval($r['c'] ?? 0);
            $metrics['attendancePending'] = 0;
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* Homeworks / assignments pending review:
   - Look for homeworks assigned by this teacher with status pending_review
   - fallbacks for different table names
*/
if ($hasHomeworks) {
    try {
        if (table_exists('homeworks')) {
            $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM homeworks WHERE (assigned_by = :tid OR teacher_id = :tid) AND status IN ('submitted','pending_review')", [':tid'=>$teacherId]);
            $metrics['pendingHomeworks'] = intval($r['cnt'] ?? 0);
        } elseif (table_exists('assignments')) {
            $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM assignments WHERE assigned_by = :tid AND status IN ('submitted','pending_review')", [':tid'=>$teacherId]);
            $metrics['pendingHomeworks'] = intval($r['cnt'] ?? 0);
        } elseif (table_exists('student_homeworks')) {
            $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM student_homeworks WHERE teacher_id = :tid AND status IN ('submitted','pending')", [':tid'=>$teacherId]);
            $metrics['pendingHomeworks'] = intval($r['cnt'] ?? 0);
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* Notices relevant to teachers */
if ($hasNotices) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM notices WHERE published_at IS NOT NULL AND published_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())");
    $metrics['activeNotices'] = intval($r['cnt'] ?? 0);
}

/* Pending leaves (leave_requests table) */
if ($hasLeaves) {
    // teacher usually sees leave requests from students/parents or staff depending on implementation.
    // We'll count leave_requests where status = 'pending' and assigned_to = teacherId OR role = 'teacher'
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM leave_requests WHERE status = 'pending' AND (assigned_to = :tid OR target_role = 'teacher')", [':tid'=>$teacherId]);
    $metrics['pendingLeaves'] = intval($r['cnt'] ?? 0);
}

/* Tasks assigned to this teacher */
if ($hasTasks) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM tasks WHERE (assigned_to = :tid OR FIND_IN_SET(:tid,assigned_to_list)) AND status IN ('pending','open')", [':tid'=>$teacherId]);
    if ($r === null) {
        // fallback simpler query
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM tasks WHERE assigned_to = :tid AND status IN ('pending','open')", [':tid'=>$teacherId]);
    }
    $metrics['myTasks'] = intval($r['cnt'] ?? 0);
}

/* Upcoming exams/assessments (if table exists) */
if ($hasExams) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM exams WHERE start_date >= CURDATE() AND start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $metrics['upcomingExams'] = intval($r['cnt'] ?? 0);
}

/* ---------- Recent lists for dashboard cards ---------- */
$recent = [
    'homeworks' => [],
    'notices' => [],
    'attendance' => [],
    'leaves' => [],
    'tasks' => []
];

if ($hasHomeworks) {
    if (table_exists('homeworks')) {
        $recent['homeworks'] = safe_db_get_all("SELECT id, title, assigned_to, status, due_date, assigned_at FROM homeworks WHERE (assigned_by = :tid OR teacher_id = :tid) ORDER BY assigned_at DESC LIMIT 8", [':tid'=>$teacherId]);
    } elseif (table_exists('assignments')) {
        $recent['homeworks'] = safe_db_get_all("SELECT id, title, class_id, status, due_date, created_at FROM assignments WHERE assigned_by = :tid ORDER BY created_at DESC LIMIT 8", [':tid'=>$teacherId]);
    } elseif (table_exists('student_homeworks')) {
        $recent['homeworks'] = safe_db_get_all("SELECT id, student_id, status, submitted_at FROM student_homeworks WHERE teacher_id = :tid ORDER BY submitted_at DESC LIMIT 8", [':tid'=>$teacherId]);
    }
}
if ($hasNotices) $recent['notices'] = safe_db_get_all("SELECT id, title, published_at FROM notices WHERE published_at IS NOT NULL ORDER BY published_at DESC LIMIT 8");
if ($hasAttendance) $recent['attendance'] = safe_db_get_all("SELECT id, class_id, student_id, status, date FROM attendance WHERE DATE(date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (marked_by = :tid OR class_id IN (" . (empty($myClassIds) ? "0" : implode(',', $myClassIds)) . ")) ORDER BY date DESC LIMIT 8", $teacherId ? [':tid'=>$teacherId] : []);
if ($hasLeaves) $recent['leaves'] = safe_db_get_all("SELECT id, title, user_id, status, created_at FROM leave_requests WHERE status IN ('pending','new') ORDER BY created_at DESC LIMIT 8");
if ($hasTasks) $recent['tasks'] = safe_db_get_all("SELECT id, title, status, created_at FROM tasks WHERE (assigned_to = :tid OR FIND_IN_SET(:tid,assigned_to_list)) ORDER BY created_at DESC LIMIT 8", [':tid'=>$teacherId]);

/* ---------- Page header (reuse common header if present) ---------- */
$page_title = 'Teacher Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

  <!-- Quick actions -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div><h6 class="mb-0">Quick Actions</h6><div class="small-muted">Teacher shortcuts</div></div>
        <div class="d-none d-md-block"><a class="btn btn-sm btn-outline-primary" href="#metrics">Jump</a></div>
      </div>

      <div class="row gy-2">
        <?php
        $actions = [
          ['../teacher/my_classes.php','bi-journal','My Classes'],
          ['../teacher/attendance_mark.php','bi-person-check','Mark Attendance'],
          ['../teacher/homeworks.php','bi-pencil-square','Homeworks'],
          ['../teacher/notices.php','bi-megaphone','Notices'],
          ['../teacher/timetable.php','bi-calendar3','Timetable'],
          ['../teacher/tasks.php','bi-list-task','To-do']
        ];
        foreach ($actions as $act): ?>
          <div class="col-12 col-md-4 col-lg-3">
            <a class="btn btn-outline-primary quick-btn d-flex align-items-center" href="<?php echo e($act[0]); ?>">
              <span class="d-flex align-items-center"><i class="bi <?php echo e($act[1]); ?> fs-5 me-2"></i><span><?php echo e($act[2]); ?></span></span>
              <i class="bi bi-chevron-right"></i>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Metrics -->
  <section id="metrics" class="mb-4">
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">My Classes</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['myClasses']); ?></div>
            <div class="small-muted">Active classes</div>
            <div class="mt-2"><a href="../teacher/my_classes.php" class="btn btn-sm btn-outline-secondary w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">My Students</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['myStudents']); ?></div>
            <div class="small-muted">Active students</div>
            <div class="mt-2"><a href="../teacher/my_students.php" class="btn btn-sm btn-outline-secondary w-100 btn-compact">List</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Attendance Marked</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['todayAttendanceMarked']); ?></div>
            <div class="small-muted">Classes done today</div>
            <div class="mt-2"><a href="../teacher/attendance_mark.php" class="btn btn-sm btn-outline-primary w-100 btn-compact">Mark</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Attendance Pending</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['attendancePending']); ?></div>
            <div class="small-muted">Classes remaining</div>
            <div class="mt-2"><a href="../teacher/attendance_mark.php" class="btn btn-sm btn-outline-warning w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <!-- Homeworks -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Homeworks</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['pendingHomeworks']); ?></div>
            <div class="small-muted">To review / grade</div>
            <div class="mt-2"><a href="../teacher/homeworks.php" class="btn btn-sm btn-outline-success w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <!-- Notices -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Active Notices</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['activeNotices']); ?></div>
            <div class="small-muted">Published</div>
            <div class="mt-2"><a href="../teacher/notices.php" class="btn btn-sm btn-outline-info w-100 btn-compact">Notices</a></div>
          </div>
        </div>
      </div>

      <!-- Leaves -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Leave Requests</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['pendingLeaves']); ?></div>
            <div class="small-muted">Requires action</div>
            <div class="mt-2"><a href="../teacher/leave_requests.php" class="btn btn-sm btn-outline-danger w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <!-- Tasks -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">My To-do</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['myTasks']); ?></div>
            <div class="small-muted">Open items</div>
            <div class="mt-2"><a href="../teacher/tasks.php" class="btn btn-sm btn-outline-warning w-100 btn-compact">To-do</a></div>
          </div>
        </div>
      </div>

      <!-- Upcoming exams -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Upcoming Exams (30d)</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['upcomingExams']); ?></div>
            <div class="small-muted">Assessments</div>
            <div class="mt-2"><a href="../teacher/exams.php" class="btn btn-sm btn-outline-primary w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- Recent panels -->
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Homeworks / Submissions</h6>
          <?php if (!empty($recent['homeworks'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['homeworks'] as $it): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?php echo e($it['title'] ?? ($it['id'] ? 'HW #'.(int)$it['id'] : '(no title)')); ?></div>
                    <div class="small-muted"><?php echo e($it['status'] ?? ''); ?> <?php if(!empty($it['due_date'])) echo '• Due '.e($it['due_date']); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e(substr($it['assigned_at'] ?? $it['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent homeworks</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../teacher/homeworks.php">Open</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Notices</h6>
          <?php if (!empty($recent['notices'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['notices'] as $it): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($it['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                  <div class="text-muted small"><?php echo e(substr($it['published_at'] ?? '', 0, 10)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No notices</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../teacher/notices.php">Notices</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Tasks / Leaves</h6>
          <?php if (!empty($recent['tasks']) || !empty($recent['leaves'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['tasks'] as $t): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($t['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                  <div class="text-muted small"><?php echo e(substr($t['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
              <?php foreach ($recent['leaves'] as $l): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($l['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                  <div class="text-muted small"><?php echo e(substr($l['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent tasks or leave requests</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../teacher/tasks.php">Manage</a></div>
        </div>
      </div>
    </div>

  </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>