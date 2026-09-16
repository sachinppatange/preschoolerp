<?php
/**
 * Owner — quick access to all teacher module pages (owner sees all classes).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

$classCount = 0;
$teacherCount = 0;
if (function_exists('table_exists')) {
    if (table_exists('classes')) {
        $r = safe_db_get_one("SELECT COUNT(*) AS c FROM classes");
        $classCount = (int) ($r['c'] ?? 0);
    }
    if (table_exists('users')) {
        $r = safe_db_get_one("SELECT COUNT(*) AS c FROM users WHERE role = 'teacher' AND is_active = 1");
        $teacherCount = (int) ($r['c'] ?? 0);
    }
}

$pageTitle = 'Teacher Hub';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => (function_exists('site_url') ? site_url('/owner/dashboard.php') : '/owner/dashboard.php')],
    ['label' => 'Teacher Hub'],
];
require_once __DIR__ . '/../includes/header.php';

$teacherBase = function_exists('site_url') ? rtrim(site_url('/teacher'), '/') : '/teacher';
$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';

$modules = [
    ['my_classes.php', 'bi-journal-bookmark', 'My Classes', 'View students by class'],
    ['attendance_mark.php', 'bi-person-check', 'Mark Attendance', 'Daily present / absent'],
    ['homeworks.php', 'bi-journal-text', 'Homeworks', 'Assign and review homework'],
    ['student_remarks.php', 'bi-chat-square-text', 'Remarks', 'Behaviour & progress notes'],
    ['class_photo_upload.php', 'bi-camera', 'Class Photos', 'Upload class gallery photos'],
    ['timetable.php', 'bi-calendar-week', 'Timetable', 'Weekly schedule'],
    ['tasks.php', 'bi-kanban', 'Tasks', 'Teacher to-do list'],
    ['notices.php', 'bi-megaphone', 'Notices', 'Class-specific notices'],
];
?>

<div class="row g-3 mb-4">
  <div class="col-sm-6">
    <div class="card metric-card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="metric-icon primary"><i class="bi bi-book"></i></div>
        <div>
          <div class="small-muted">Classes</div>
          <div class="fs-4 fw-bold mb-0"><?php echo $classCount; ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="card metric-card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="metric-icon success"><i class="bi bi-person-workspace"></i></div>
        <div>
          <div class="small-muted">Active Teachers</div>
          <div class="fs-4 fw-bold mb-0"><?php echo $teacherCount; ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-info small">
  <i class="bi bi-info-circle me-1"></i>
  As <strong>Owner</strong> you can use every teacher tool and see <strong>all classes</strong> (not limited to one teacher).
</div>

<div class="row g-3 mb-3">
  <?php foreach ($modules as [$file, $icon, $title, $desc]): ?>
    <div class="col-md-6 col-lg-4">
      <a href="<?php echo e($teacherBase . '/' . $file); ?>" class="text-decoration-none text-dark">
        <div class="card metric-card h-100">
          <div class="card-body">
            <div class="d-flex align-items-start gap-3">
              <div class="metric-icon info"><i class="bi <?php echo e($icon); ?>"></i></div>
              <div>
                <div class="fw-bold"><?php echo e($title); ?></div>
                <div class="small-muted"><?php echo e($desc); ?></div>
              </div>
            </div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap gap-2">
  <a class="btn btn-outline-primary btn-sm" href="<?php echo e($ownerBase . '/class_setup.php'); ?>"><i class="bi bi-book me-1"></i>Class Setup</a>
  <a class="btn btn-outline-primary btn-sm" href="<?php echo e($ownerBase . '/teacher_assign.php'); ?>"><i class="bi bi-person-workspace me-1"></i>Assign Teachers</a>
  <a class="btn btn-outline-primary btn-sm" href="<?php echo e($ownerBase . '/attendance.php'); ?>"><i class="bi bi-clipboard-data me-1"></i>Attendance Records</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
