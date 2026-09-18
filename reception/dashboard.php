<?php
/**
 * reception/dashboard.php
 *
 * Reception Dashboard for Pioneer Play School.
 * - Mobile-first, compact UI inspired by owner/dashboard.php
 * - Shows reception-focused metrics and quick actions
 * - Includes Pending Alerts, Pending Tasks
 *
 * "Pending Notifications" card removed as requested.
 *
 * UPDATE (2026-03-14):
 * - Added "Add Student Admission" link/button pointing to /reception/admission.php
 *
 * Place at: /pioneerplayschool01/reception/dashboard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth */
/* Load project includes if available */
/* Minimal DB helpers (guarded) */

/* small helpers */

/* detect tables */
$hasStudents = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'students'") ? true : false;
$hasEnquiries = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'enquiries'") ? true : false;
$hasClasses = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'classes'") ? true : false;
$hasNotices = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notices'") ? true : false;
$hasNewsEvents = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'news_events'") ? true : false;
$hasAlerts = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'alerts'") ? true : false;
$hasNotifications = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notifications'") ? true : false;
$hasTasks = safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tasks'") ? true : false;

/* metrics */
$metrics = [
    'enquiriesToday' => 0,
    'pendingEnquiries' => 0,
    'totalStudents' => 0,
    'admissionsThisMonth' => 0,
    'activeNotices' => 0,
    'newsEventsCount' => 0,
    'pendingAlerts' => 0,
    'pendingNotifications' => 0,
    'pendingTasks' => 0
];

if ($hasEnquiries) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM enquiries WHERE DATE(created_at) = CURDATE()");
    $metrics['enquiriesToday'] = intval($r['cnt'] ?? 0);
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM enquiries WHERE status IN ('new','pending')");
    $metrics['pendingEnquiries'] = intval($r['cnt'] ?? 0);
}

if ($hasStudents) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE status = 'active'");
    $metrics['totalStudents'] = intval($r['cnt'] ?? 0);
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status='active'");
    $metrics['admissionsThisMonth'] = intval($r['cnt'] ?? 0);
}

if ($hasNotices) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM notices WHERE published_at IS NOT NULL AND published_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())");
    $metrics['activeNotices'] = intval($r['cnt'] ?? 0);
}

if ($hasNewsEvents) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM news_events");
    $metrics['newsEventsCount'] = intval($r['cnt'] ?? 0);
}

/* pending alerts */
if ($hasAlerts) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM alerts WHERE status IN ('new','pending') OR resolved = 0");
    $metrics['pendingAlerts'] = intval($r['cnt'] ?? 0);
} else {
    // fallback: fee overdue + pending admissions
    $feeOver30 = 0;
    $admissionFollowups = 0;
    if (safe_db_get_one("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fees_records'")) {
        $rr = safe_db_get_one("SELECT COUNT(*) AS cnt FROM fees_records WHERE (amount - paid_amount) > 0 AND due_date IS NOT NULL AND due_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $feeOver30 = intval($rr['cnt'] ?? 0);
    }
    if ($hasStudents) {
        $rr = safe_db_get_one("SELECT COUNT(*) AS cnt FROM students WHERE status='pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $admissionFollowups = intval($rr['cnt'] ?? 0);
    }
    $metrics['pendingAlerts'] = $feeOver30 + $admissionFollowups;
}

/* pending notifications */
if ($hasNotifications) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM notifications WHERE (is_sent = 0 OR status IN ('pending','queued'))");
    $metrics['pendingNotifications'] = intval($r['cnt'] ?? 0);
}

/* pending tasks */
if ($hasTasks) {
    $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM tasks WHERE status IN ('pending','open') OR completed = 0");
    $metrics['pendingTasks'] = intval($r['cnt'] ?? 0);
}

/* recent lists */
$recent = [
    'enquiries' => [],
    'notices' => [],
    'news_events' => [],
    'alerts' => [],
    'notifications' => [],
    'tasks' => []
];

if ($hasEnquiries) $recent['enquiries'] = safe_db_get_all("SELECT id, name, phone, message, created_at FROM enquiries ORDER BY created_at DESC LIMIT 8");
if ($hasNotices)  $recent['notices']  = safe_db_get_all("SELECT id, title, published_at FROM notices WHERE published_at IS NOT NULL ORDER BY published_at DESC LIMIT 8");
if ($hasNewsEvents) $recent['news_events'] = safe_db_get_all("SELECT id, type, title, start_date, created_at FROM news_events ORDER BY created_at DESC LIMIT 8");
if ($hasAlerts) $recent['alerts'] = safe_db_get_all("SELECT id, title, created_at, status FROM alerts WHERE status IN ('new','pending') ORDER BY created_at DESC LIMIT 8");
if ($hasNotifications) $recent['notifications'] = safe_db_get_all("SELECT id, title, created_at, status FROM notifications WHERE (is_sent = 0 OR status IN ('pending','queued')) ORDER BY created_at DESC LIMIT 8");
if ($hasTasks) $recent['tasks'] = safe_db_get_all("SELECT id, title, created_at, status FROM tasks WHERE status IN ('pending','open') ORDER BY created_at DESC LIMIT 8");

/* page header */
$page_title = 'Reception Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

  <!-- Quick actions -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div><h6 class="mb-0">Quick Actions</h6><div class="small-muted">Common reception tasks</div></div>
        <div class="d-none d-md-block"><a class="btn btn-sm btn-outline-primary" href="#metrics">Jump</a></div>
      </div>

      <div class="row gy-2">
        <?php
        $actions = [
          ['/reception/admission.php','bi-person-plus','Add Student Admission'], // also included in quick list
          ['../reception/enquiry_list.php','bi-chat-left-text','Enquiries'],
          ['../reception/students_list.php','bi-people','Student List'],
          ['../reception/notices_publish.php','bi-megaphone','Notices'],
          ['../reception/news_events.php','bi-newspaper','News & Events'],
          ['../reception/students_list.php?filter=this_month','bi-person-plus','Admissions']
        ];
        foreach ($actions as $act) {
            ?>
            <div class="col-12 col-md-6">
              <a class="btn btn-outline-primary quick-btn d-flex align-items-center" href="<?php echo e($act[0]); ?>">
                <span class="d-flex align-items-center"><i class="bi <?php echo e($act[1]); ?> fs-5 me-2"></i><span><?php echo e($act[2]); ?></span></span>
                <i class="bi bi-chevron-right"></i>
              </a>
            </div>
            <?php
        }
        ?>
      </div>
    </div>
  </div>

  <!-- Metrics -->
  <section id="metrics" class="mb-4">
    <div class="row g-3">

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Enquiries Today</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['enquiriesToday']); ?></div>
            <div class="small-muted"><?php echo number_format($metrics['pendingEnquiries']); ?> pending</div>
            <div class="mt-2"><a href="../reception/enquiry_list.php" class="btn btn-sm btn-outline-primary w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Total Students</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['totalStudents']); ?></div>
            <div class="small-muted">Active</div>
            <div class="mt-2"><a href="../reception/students_list.php" class="btn btn-sm btn-outline-secondary w-100 btn-compact">List</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Admissions This Month</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['admissionsThisMonth']); ?></div>
            <div class="small-muted">New active</div>
            <div class="mt-2"><a href="../reception/students_list.php?filter=this_month" class="btn btn-sm btn-outline-success w-100 btn-compact">View</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Active Notices</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['activeNotices']); ?></div>
            <div class="small-muted">Published</div>
            <div class="mt-2"><a href="../reception/notices_publish.php" class="btn btn-sm btn-outline-info w-100 btn-compact">Notices</a></div>
          </div>
        </div>
      </div>

      <!-- News & Events -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">News & Events</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['newsEventsCount']); ?></div>
            <div class="small-muted">Total items</div>
            <div class="mt-2"><a href="../reception/news_events.php" class="btn btn-sm btn-outline-primary w-100 btn-compact">Open</a></div>
          </div>
        </div>
      </div>

      <!-- Pending Alerts -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Alerts</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['pendingAlerts']); ?></div>
            <div class="small-muted">Requires attention</div>
            <div class="mt-2"><a href="../reception/pending_alerts.php" class="btn btn-sm btn-outline-danger w-100 btn-compact">Open Alerts</a></div>
          </div>
        </div>
      </div>

      <!-- Pending Tasks -->
      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">To-do</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['pendingTasks']); ?></div>
            <div class="small-muted">Still open</div>
            <div class="mt-2"><a href="../reception/pending_tasks.php" class="btn btn-sm btn-outline-warning w-100 btn-compact">Open</a></div>
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
          <h6 class="mb-2">Recent Enquiries</h6>
          <?php if (!empty($recent['enquiries'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['enquiries'] as $item): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?php echo e($item['name'] ?: '(no name)'); ?></div>
                    <div class="small-muted"><?php echo e(mb_strimwidth($item['message'] ?? '', 0, 60, '...')); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e(substr($item['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent enquiries</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../reception/enquiry_list.php">View all</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent Notices</h6>
          <?php if (!empty($recent['notices'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['notices'] as $item): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($item['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                  <div class="text-muted small"><?php echo e(substr($item['published_at'] ?? '', 0, 10)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No notices</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../reception/notices_publish.php">Manage</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Recent News & Events</h6>
          <?php if (!empty($recent['news_events'])): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recent['news_events'] as $item): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <div class="fw-semibold"><?php echo e(mb_strimwidth($item['title'] ?? '(no title)', 0, 60, '...')); ?></div>
                    <div class="small-muted"><?php echo e(!empty($item['type']) ? ucfirst($item['type']) : ''); ?> <?php if(!empty($item['start_date'])) echo '• '.e($item['start_date']); ?></div>
                  </div>
                  <div class="text-muted small"><?php echo e(substr($item['created_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No news or events</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary btn-compact" href="../reception/news_events.php">Open</a></div>
        </div>
      </div>
    </div>

  </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>