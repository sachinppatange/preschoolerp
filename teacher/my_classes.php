<?php
/**
 * teacher/my_classes.php
 *
 * Shows classes assigned to the logged-in teacher and students for a selected class.
 * This version uses the provided students table schema (students.class_id) and
 * a straightforward mapping strategy:
 *  - Prefer classes.teacher_id (if classes table has teacher_id)
 *  - Else use teacher_classes (mapping table) with columns teacher_id and class_id
 *
 * Place at: /pioneerplayschool01/teacher/my_classes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* ---------- Require teacher login ---------- */
if (!defined('DEV_SHOW_ERRORS')) define('DEV_SHOW_ERRORS', false);
/* ---------- Helper functions (fallbacks) ---------- */

/* ---------- Page state ---------- */
$messages = [];
$errors = [];

/* ---------- Teacher identity ---------- */
$teacherId = auth_user_id() ?? 0;
$teacherSession = auth_user() ?? [];

/* ---------- Determine assigned classes (simple and reliable) ---------- */
/* Strategy:
   1) If classes.teacher_id column exists, use it.
   2) Else if teacher_classes table exists, use mapping teacher_id -> class_id.
   3) Only handle the common column names (teacher_id, class_id). This avoids heuristic errors.
*/

$assignedClasses = panel_teacher_assigned_classes($teacherId);

/* ---------- Selected class handling ---------- */
$selectedClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if ($selectedClassId === 0 && !empty($assignedClasses)) {
    // choose first assigned class by default
    $selectedClassId = (int)$assignedClasses[0]['id'];
}
// Validate selection belongs to teacher
$allowedIds = array_map(function($r){ return (int)$r['id']; }, $assignedClasses);
if ($selectedClassId > 0 && !in_array($selectedClassId, $allowedIds, true)) {
    $selectedClassId = 0;
}

/* ---------- Pagination for students ---------- */
$perPage = 30;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $perPage;

/* ---------- Fetch students for selected class (using students.class_id) ---------- */
/* Students table schema provided by you contains class_id column */
$students = [];
$totalStudents = 0;
if ($selectedClassId > 0 && table_exists('students')) {
    $stuWhere = 'class_id = :cid AND (status IS NULL OR status = \'active\')';
    $stuParams = [':cid' => $selectedClassId];
    if (function_exists('ay_students_have_column') && ay_students_have_column()) {
        $stuWhere .= ' AND academic_year = :panel_ay';
        $stuParams[':panel_ay'] = ay_selected();
    }
    $count = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE {$stuWhere}", $stuParams);
    $totalStudents = intval($count['cnt'] ?? 0);

    if ($totalStudents > 0) {
        $listParams = [$selectedClassId];
        $listSql = "SELECT id, first_name, middle_name, last_name, form_no, dob, father_phone, mother_phone, photo_path, admission_date, status, created_at
             FROM students
             WHERE class_id = ? AND (status IS NULL OR status = 'active')";
        if (function_exists('ay_students_have_column') && ay_students_have_column()) {
            $listSql .= ' AND academic_year = ?';
            $listParams[] = ay_selected();
        }
        $listSql .= ' ORDER BY (form_no IS NULL), form_no ASC, first_name ASC LIMIT ? OFFSET ?';
        $listParams[] = $perPage;
        $listParams[] = $offset;
        $students = safe_db_get_all($listSql, $listParams);
    }
}

/* ---------- Page render ---------- */
$pageTitle = auth_is_owner_super() ? 'All Classes (Owner)' : 'My Classes';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (auth_is_owner_super()): ?>
  <div class="alert alert-info small mb-3"><i class="bi bi-shield-check me-1"></i>Owner view — all classes are available.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-md-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Assigned Classes</h6>
        <?php if (empty($assignedClasses)): ?>
          <div class="small-muted">You are not assigned to any class yet. Contact administrator.</div>
          <?php if ($DEBUG): ?>
            <pre class="mt-2 small text-muted">DEBUG: assignedClasses = <?php echo e(json_encode($assignedClasses)); ?></pre>
          <?php endif; ?>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($assignedClasses as $c): $cid = (int)$c['id']; ?>
              <li class="list-group-item d-flex justify-content-between align-items-center <?php echo $cid === $selectedClassId ? 'active text-white' : ''; ?>">
                <div>
                  <div class="fw-semibold"><?php echo e(trim((($c['short_name'] ?? '') . ' ' . ($c['name'] ?? '')))); ?></div>
                  <div class="small-muted"><?php echo e(!empty($c['section']) ? 'Section: ' . $c['section'] : ''); ?></div>
                </div>
                <div>
                  <a class="btn btn-sm btn-<?php echo $cid === $selectedClassId ? 'light' : 'outline-primary'; ?>" href="?class_id=<?php echo $cid; ?>"><?php echo $cid === $selectedClassId ? 'Selected' : 'Open'; ?></a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mt-3 shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Quick actions</h6>
        <div class="d-grid gap-2">
          <?php if ($selectedClassId > 0): ?>
            <a class="btn btn-outline-primary" href="../teacher/attendance_mark.php?class_id=<?php echo $selectedClassId; ?>"><i class="bi bi-check2-square me-1"></i> Mark Attendance</a>
            <a class="btn btn-outline-success" href="../teacher/homeworks.php?class_id=<?php echo $selectedClassId; ?>"><i class="bi bi-journal-text me-1"></i> Homeworks</a>
          <?php else: ?>
            <div class="small-muted">Select a class to view class-specific actions.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <div class="col-12 col-md-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-3">Students in class <?php if ($selectedClassId>0): ?><small class="text-muted">#<?php echo $selectedClassId; ?></small><?php endif; ?></h6>

        <?php if ($selectedClassId === 0): ?>
          <div class="small-muted">No class selected. Choose a class from the left.</div>
        <?php else: ?>

          <div class="mb-3 d-flex justify-content-between align-items-center">
            <div><strong><?php echo number_format($totalStudents); ?></strong> students</div>
            <div>
              <?php if ($totalStudents > 0): $totalPages = (int) ceil($totalStudents / $perPage); ?>
                <nav aria-label="students pagination">
                  <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?class_id=<?php echo $selectedClassId; ?>&p=<?php echo max(1,$page-1); ?>">Prev</a></li>
                    <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> / <?php echo max(1,$totalPages); ?></span></li>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="?class_id=<?php echo $selectedClassId; ?>&p=<?php echo min($totalPages,$page+1); ?>">Next</a></li>
                  </ul>
                </nav>
              <?php endif; ?>
            </div>
          </div>

          <?php if (empty($students)): ?>
            <div class="small-muted">No students found in this class.</div>
            <?php if ($DEBUG): ?>
              <pre class="mt-2 small text-muted">DEBUG: selectedClassId=<?php echo e($selectedClassId); ?>; totalStudents=<?php echo e($totalStudents); ?></pre>
            <?php endif; ?>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle">
                <thead>
                  <tr>
                    <th style="width:60px">#</th>
                    <th>Photo</th>
                    <th>Form No</th>
                    <th>Name</th>
                    <th style="width:120px">DOB</th>
                    <th style="width:160px" class="text-nowrap">Contact</th>
                    <th style="width:120px" class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $i = 1; foreach ($students as $s): 
                    $sid = (int)$s['id'];
                    $fullname = trim((($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? '')));
                    $photo = function_exists('student_photo_url') ? student_photo_url((string) ($s['photo_path'] ?? '')) : (string) ($s['photo_path'] ?? '');
                  ?>
                    <tr>
                      <td><?php echo $i++; ?></td>
                      <td>
                        <?php if ($photo): ?>
                          <img src="<?php echo e($photo); ?>" alt="photo" class="student-photo">
                        <?php else: ?>
                          <div style="width:40px;height:40px;background:#f1f1f1;border-radius:4px;"></div>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e($s['form_no'] ?? '—'); ?></td>
                      <td class="student-name"><?php echo e($fullname ?: ('Student #' . $sid)); ?><br><small class="small-muted">Admitted: <?php echo e(substr($s['admission_date'] ?? '',0,10) ?: '—'); ?></small></td>
                      <td><?php echo e(substr($s['dob'] ?? '',0,10) ?: '—'); ?></td>
                      <td class="small-muted text-nowrap">Father: <?php echo e($s['father_phone'] ?? '—'); ?><br>Mother: <?php echo e($s['mother_phone'] ?? '—'); ?></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="../teacher/student_view.php?id=<?php echo $sid; ?>">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="../teacher/attendance_mark.php?class_id=<?php echo $selectedClassId; ?>&student_id=<?php echo $sid; ?>">Attendance</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
/* ---------- Footer ---------- */
require_once __DIR__ . '/../includes/footer.php';

?>