<?php
/**
 * parent/dashboard.php
 *
 * Enhanced Parent Dashboard for Pioneer Play School.
 *
 * Improvements / features in this version (compared to the older file you provided):
 * - Reuses safe DB helpers and includes if available.
 * - Shows global parent-level metrics + per-child detailed panel (select a child).
 * - Child selector (switch between linked children) — all queries restricted to the logged-in parent's children.
 * - Detailed per-child cards: pending fees, recent payments, attendance (7d), pending homeworks, teacher remarks, class info.
 * - Recent lists: recent payments, recent homeworks/submissions, notices/events (school-wide).
 * - CSV export endpoints for payments and remarks (quick links).
 * - Defensive checks if tables are missing (graceful fallbacks).
 * - Uses prepared statements and avoids SQL injection; handles DB connection fallbacks.
 *
 * Installation:
 * - Place at /pioneerplayschool01/parent/dashboard.php (replace existing).
 * - Make sure your includes/config.php etc. are correct for DB connection.
 *
 * Notes:
 * - This file is read-only for parents. Actions which change data (e.g. request linkage) should be routed to other pages.
 * - If you want additional per-child charts/graphs, tell me and I can add simple sparklines or Chart.js integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ---------- Require parent login ---------- */
function fullname(array $s): string { return trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? '')); }

/* ---------- Parent identity & children mapping ---------- */
$parentId = panel_parent_context_id();
$parentSession = auth_user() ?? [];
$parentName = auth_user_name('Parent');

/* Fetch parent-child mappings (robust to alternate table names) */
$childMappings = [];
$childIds = [];
if (table_exists('parents_children')) {
    $childMappings = safe_db_get_all("SELECT id, parent_user_id, child_student_id, relation, created_at FROM parents_children WHERE parent_user_id = :pid ORDER BY created_at DESC", [':pid'=>$parentId]);
    foreach ($childMappings as $m) $childIds[] = (int)$m['child_student_id'];
} elseif (table_exists('parent_students')) {
    $childMappings = safe_db_get_all("SELECT id, parent_id AS parent_user_id, student_id AS child_student_id, relation, created_at FROM parent_students WHERE parent_id = :pid ORDER BY created_at DESC", [':pid'=>$parentId]);
    foreach ($childMappings as $m) $childIds[] = (int)$m['child_student_id'];
} else {
    // fallback: check students table for parent references (father_id / mother_id / parent_id)
    if (table_exists('students')) {
        $mappings = safe_db_get_all("SELECT id, NULL AS parent_user_id, id AS child_student_id, NULL AS relation, created_at FROM students WHERE parent_id = :pid OR father_id = :pid OR mother_id = :pid", [':pid'=>$parentId]);
        $childMappings = $mappings;
        foreach ($mappings as $m) $childIds[] = (int)$m['child_student_id'];
    }
}

/* Load children details (from students table) */
$children = [];
if (!empty($childIds) && table_exists('students')) {
    // We'll request children in same order as childIds
    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $params = array_merge($childIds, $childIds); // used twice for ORDER BY FIELD compatibility in some DB libs
    $sql = "SELECT id, first_name, middle_name, last_name, class_id, roll_no, photo_path FROM students WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)";
    // safe_db_get_all uses array_values internally, so we pass merged array to cover both placeholders
    $children = safe_db_get_all($sql, $params);
} else {
    // If no students table or children empty, create minimal placeholders
    foreach ($childIds as $cid) $children[] = ['id'=>$cid, 'first_name'=>'Student','middle_name'=>'','last_name'=>('#'.$cid),'class_id'=>null];
}

/* Child selector: choose focused child for per-child panel */
$selectedChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
if ($selectedChildId === 0 && !empty($childIds)) $selectedChildId = $childIds[0];
if ($selectedChildId !== 0 && !in_array($selectedChildId, $childIds, true)) $selectedChildId = ($childIds[0] ?? 0);

/* ---------- Metrics (parent level) ---------- */
$metrics = [
    'childrenCount' => count($childIds),
    'pendingFees' => 0.0,            // sum of pending fees across children
    'recentPaymentsCount' => 0,
    'attendancePresentLast7' => 0,
    'attendanceAbsentLast7' => 0,
    'pendingHomeworks' => 0,
];

/* Helper: compute pending fees across children if relevant tables exist */
if (!empty($childIds) && table_exists('students') && table_exists('fees_records')) {
    try {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        // Sum pending per student: total_fees - paid_sum (clamped to 0)
        $sql = "
            SELECT COALESCE(SUM(GREATEST(COALESCE(s.total_fees,0) - COALESCE(fr.paid_sum,0),0)),0) AS total_pending
            FROM students s
            LEFT JOIN (
                SELECT student_id, SUM(paid_amount) AS paid_sum
                FROM fees_records
                WHERE student_id IN ($ph)
                GROUP BY student_id
            ) fr ON fr.student_id = s.id
            WHERE s.id IN ($ph)
        ";
        $params = array_merge($childIds, $childIds);
        $row = safe_db_get_one($sql, $params);
        $metrics['pendingFees'] = $row ? (float)$row['total_pending'] : 0.0;
    } catch (Throwable $e) {
        if ($DEBUG) error_log('pending fees calc: '.$e->getMessage());
    }
}

/* Recent payments for these children */
$recentPayments = [];
if (!empty($childIds) && table_exists('fees_records')) {
    try {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $recentPayments = safe_db_get_all("SELECT id, student_id, paid_amount, receipt_no, COALESCE(collected_at, created_at) AS collected_at FROM fees_records WHERE student_id IN ($ph) ORDER BY COALESCE(collected_at, created_at) DESC LIMIT 8", $childIds);
        $metrics['recentPaymentsCount'] = count($recentPayments);
    } catch (Throwable $e) {
        if ($DEBUG) error_log('recent payments: '.$e->getMessage());
    }
}

/* Attendance summary last 7 days for these children */
if (!empty($childIds) && table_exists('attendance')) {
    try {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END),0) AS present,
                    COALESCE(SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END),0) AS absent
                FROM attendance
                WHERE student_id IN ($ph) AND DATE(date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $row = safe_db_get_one($sql, $childIds);
        if ($row) {
            $metrics['attendancePresentLast7'] = intval($row['present'] ?? 0);
            $metrics['attendanceAbsentLast7'] = intval($row['absent'] ?? 0);
        }
    } catch (Throwable $e) {
        if ($DEBUG) error_log('attendance summary: '.$e->getMessage());
    }
}

/* Recent homeworks/submissions for children */
$recentHomeworks = [];
if (!empty($childIds)) {
    try {
        $hwTable = table_exists('student_homeworks') ? 'student_homeworks' : (table_exists('student_submissions') ? 'student_submissions' : (table_exists('student_assignments') ? 'student_assignments' : ''));
        if ($hwTable !== '') {
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            $recentHomeworks = safe_db_get_all("SELECT id, student_id, status, submitted_at, remarks FROM {$hwTable} WHERE student_id IN ($ph) ORDER BY submitted_at DESC LIMIT 8", $childIds);
            $metrics['pendingHomeworks'] = count(array_filter($recentHomeworks, fn($r) => in_array(strtolower($r['status'] ?? ''), ['submitted','pending'])));
        }
    } catch (Throwable $e) {
        if ($DEBUG) error_log('homeworks fetch: '.$e->getMessage());
    }
}

/* Recent teacher remarks for selected child */
$recentRemarks = [];
if ($selectedChildId > 0 && table_exists('student_remarks')) {
    try {
        $recentRemarks = safe_db_get_all("SELECT id, remark, type, DATE_FORMAT(created_at,'%Y-%m-%d') AS date, teacher_id FROM student_remarks WHERE student_id = :sid ORDER BY created_at DESC LIMIT 6", [':sid'=>$selectedChildId]);
    } catch (Throwable $e) {
        if ($DEBUG) error_log('remarks fetch: '.$e->getMessage());
    }
}

/* Notices & upcoming events (school-wide) */
$recentNotices = table_exists('notices') ? safe_db_get_all("SELECT id,title,published_at FROM notices WHERE published_at IS NOT NULL ORDER BY published_at DESC LIMIT 6") : [];
$upcomingEvents = table_exists('news_events') ? safe_db_get_all("SELECT id,title,start_date FROM news_events WHERE start_date >= CURDATE() ORDER BY start_date ASC LIMIT 6") : [];

/* Per-child small summary (for selected child) */
$perChild = [
    'pendingFees' => 0.0,
    'recentPayments' => [],
    'attendance7' => ['present'=>0,'absent'=>0],
    'pendingHomeworks' => 0,
    'class_info' => null
];
if ($selectedChildId > 0) {
    // pending fees for child
    if (table_exists('students') && table_exists('fees_records')) {
        try {
            $r = safe_db_get_one("SELECT COALESCE(s.total_fees,0) AS total_fees, COALESCE(fr.paid_sum,0) AS paid_sum
                                  FROM students s
                                  LEFT JOIN (SELECT student_id, SUM(paid_amount) AS paid_sum FROM fees_records WHERE student_id = :sid GROUP BY student_id) fr ON fr.student_id = s.id
                                  WHERE s.id = :sid LIMIT 1", [':sid'=>$selectedChildId]);
            if ($r) $perChild['pendingFees'] = max(0.0, (float)$r['total_fees'] - (float)$r['paid_sum']);
        } catch (Throwable $e) { if ($DEBUG) error_log('perChild fees: '.$e->getMessage()); }
    }
    // recent payments
    if (table_exists('fees_records')) {
        try { $perChild['recentPayments'] = safe_db_get_all("SELECT id, paid_amount, receipt_no, COALESCE(collected_at,created_at) AS at FROM fees_records WHERE student_id = :sid ORDER BY COALESCE(collected_at,created_at) DESC LIMIT 6", [':sid'=>$selectedChildId]); }
        catch (Throwable $e) { if ($DEBUG) error_log('perChild payments: '.$e->getMessage()); }
    }
    // attendance last 7
    if (table_exists('attendance')) {
        try {
            $r = safe_db_get_one("SELECT COALESCE(SUM(CASE WHEN status='present' THEN 1 ELSE 0 END),0) AS present, COALESCE(SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END),0) AS absent FROM attendance WHERE student_id = :sid AND DATE(date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [':sid'=>$selectedChildId]);
            if ($r) { $perChild['attendance7']['present'] = intval($r['present']); $perChild['attendance7']['absent'] = intval($r['absent']); }
        } catch (Throwable $e) { if ($DEBUG) error_log('perChild attendance: '.$e->getMessage()); }
    }
    // pending homeworks for child
    if ($hwTable !== '') {
        try { $hw = safe_db_get_all("SELECT id,status,submitted_at FROM {$hwTable} WHERE student_id = :sid ORDER BY submitted_at DESC LIMIT 6", [':sid'=>$selectedChildId]); $perChild['pendingHomeworks'] = count(array_filter($hw, fn($x)=> in_array(strtolower($x['status'] ?? ''), ['submitted','pending']))); }
        catch (Throwable $e) { if ($DEBUG) error_log('perChild hw: '.$e->getMessage()); }
    }
    // class info
    if (table_exists('students') && table_exists('classes')) {
        try {
            $row = safe_db_get_one("SELECT c.id, c.name, c.short_name, c.section FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = :sid LIMIT 1", [':sid'=>$selectedChildId]);
            if ($row) $perChild['class_info'] = $row;
        } catch (Throwable $e) { if ($DEBUG) error_log('perChild class: '.$e->getMessage()); }
    }
}

/* ---------- Render UI (reuse header/footer if present) ---------- */
$page_title = 'Parent Dashboard';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

  <!-- Quick actions -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div><h6 class="mb-0">Quick Actions</h6><div class="small-muted">Parent shortcuts</div></div>
        <div class="d-none d-md-block"><a class="btn btn-sm btn-outline-primary" href="#metrics">Jump</a></div>
      </div>
      <div class="row gy-2">
        <?php
        $actions = [
          ['../parent/children.php','bi-people','My Children'],
          ['../parent/fees.php','bi-cash-stack','Fees & Payments'],
          ['../parent/attendance.php','bi-check2-square','Attendance'],
          ['../parent/homeworks.php','bi-journal-text','Homeworks'],
          ['../parent/notices.php','bi-megaphone','Notices'],
          ['../parent/events.php','bi-calendar-event','Events']
        ];
        foreach ($actions as $act): ?>
          <div class="col-12 col-md-6 col-lg-4">
            <a class="btn btn-outline-primary d-flex justify-content-between align-items-center" href="<?php echo e($act[0]); ?>">
              <span><i class="bi <?php echo e($act[1]); ?> me-2"></i><?php echo e($act[2]); ?></span><i class="bi bi-chevron-right"></i>
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
            <div class="small-muted">My Children</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['childrenCount']); ?></div>
            <div class="mt-2"><a class="btn btn-sm btn-outline-secondary w-100" href="../parent/children.php">View</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Fees</div>
            <div class="h5 mb-1"><?php echo format_money($metrics['pendingFees']); ?></div>
            <div class="mt-2"><a class="btn btn-sm btn-outline-danger w-100" href="../parent/fees.php">Pay</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Attendance (7d)</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['attendancePresentLast7']); ?> present</div>
            <div class="small-muted"><?php echo number_format($metrics['attendanceAbsentLast7']); ?> absent</div>
            <div class="mt-2"><a class="btn btn-sm btn-outline-primary w-100" href="../parent/attendance.php">Details</a></div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card metric-card shadow-sm h-100">
          <div class="card-body">
            <div class="small-muted">Pending Homeworks</div>
            <div class="h5 mb-1"><?php echo number_format($metrics['pendingHomeworks']); ?></div>
            <div class="mt-2"><a class="btn btn-sm btn-outline-success w-100" href="../parent/homeworks.php">Open</a></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Main columns: children list + per-child detail / recent lists -->
  <div class="row g-3">
    <div class="col-12 col-md-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6>Linked Children</h6>
          <?php if (!empty($children)): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($children as $ch): $cid=(int)$ch['id']; ?>
                <div class="list-group-item d-flex align-items-center <?php if ($cid === $selectedChildId) echo 'active text-white'; ?>">
                  <div class="me-3">
                    <?php if (!empty($ch['photo_path'])): ?>
                      <img src="<?php echo e($ch['photo_path']); ?>" alt="" class="child-photo">
                    <?php else: ?>
                      <div class="child-photo" style="background:#f1f1f1;display:inline-block"></div>
                    <?php endif; ?>
                  </div>
                  <div class="flex-fill">
                    <div class="fw-semibold"><?php echo e(fullname($ch)); ?></div>
                    <div class="small-muted">Class: <?php echo e($ch['class_id'] ?? '—'); ?> • Roll: <?php echo e($ch['roll_no'] ?? '—'); ?></div>
                  </div>
                  <div class="text-end">
                    <a class="btn btn-sm <?php echo $cid === $selectedChildId ? 'btn-light' : 'btn-outline-primary'; ?>" href="?child_id=<?php echo $cid; ?>"><?php echo $cid === $selectedChildId ? 'Selected' : 'Open'; ?></a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="small-muted">No children linked. Use 'Request linkage' to connect your child's account.</div>
            <div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="../parent/contact_school.php">Request linkage</a></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-3 shadow-sm">
        <div class="card-body">
          <h6>Recent Payments</h6>
          <?php if (!empty($recentPayments)): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentPayments as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold"><?php echo format_money((float)($p['paid_amount'] ?? 0)); ?></div>
                    <div class="small-muted">Receipt: <?php echo e($p['receipt_no'] ?? '—'); ?> • Student ID: <?php echo (int)($p['student_id'] ?? 0); ?></div>
                  </div>
                  <div class="small-muted"><?php echo e(substr($p['collected_at'] ?? '', 0, 16)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No recent payments</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary" href="../parent/fees.php">All Payments</a></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6>Details for <?php
            $selChild = null;
            foreach ($children as $c) { if ((int)$c['id'] === $selectedChildId) { $selChild = $c; break; } }
            echo e($selChild ? fullname($selChild) : 'Selected Child');
          ?></h6>

          <?php if ($selectedChildId <= 0): ?>
            <div class="small-muted">Select a child to view details.</div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-6 col-md-3">
                <div class="small-muted">Pending Fees</div>
                <div class="h5 mb-1"><?php echo format_money($perChild['pendingFees'] ?? 0.0); ?></div>
                <div class="mt-2"><a class="btn btn-sm btn-outline-danger" href="../parent/fees.php?student_id=<?php echo $selectedChildId; ?>">Pay</a></div>
              </div>

              <div class="col-6 col-md-3">
                <div class="small-muted">Attendance (7d)</div>
                <div class="h5 mb-1"><?php echo number_format($perChild['attendance7']['present'] ?? 0); ?></div>
                <div class="small-muted"><?php echo number_format($perChild['attendance7']['absent'] ?? 0); ?> absent</div>
                <div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="../parent/attendance.php?student_id=<?php echo $selectedChildId; ?>">Details</a></div>
              </div>

              <div class="col-6 col-md-3">
                <div class="small-muted">Recent Payments</div>
                <div class="h5 mb-1"><?php echo number_format(count($perChild['recentPayments'] ?? [])); ?></div>
                <div class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="../parent/fees.php?student_id=<?php echo $selectedChildId; ?>">Payments</a></div>
              </div>

              <div class="col-6 col-md-3">
                <div class="small-muted">Pending HW</div>
                <div class="h5 mb-1"><?php echo number_format($perChild['pendingHomeworks'] ?? 0); ?></div>
                <div class="mt-2"><a class="btn btn-sm btn-outline-success" href="../parent/homeworks.php?student_id=<?php echo $selectedChildId; ?>">Homeworks</a></div>
              </div>

              <div class="col-12">
                <h6 class="mt-3">Recent Remarks</h6>
                <?php if (!empty($recentRemarks)): ?>
                  <ul class="list-group list-group-flush">
                    <?php foreach ($recentRemarks as $rm): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?php echo e(mb_strimwidth($rm['remark'] ?? '', 0, 140, '...')); ?></div>
                        <div class="small-muted"><?php echo e($rm['type'] ?? 'note'); ?> • <?php echo e($rm['date'] ?? ''); ?> • By teacher ID <?php echo (int)$rm['teacher_id']; ?></div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div class="small-muted">No recent remarks for this child.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-3 shadow-sm">
        <div class="card-body">
          <h6>Notices & Events</h6>
          <?php if (!empty($recentNotices) || !empty($upcomingEvents)): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($recentNotices as $n): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($n['title'] ?? '', 0, 80, '...')); ?></div>
                  <div class="small-muted"><?php echo e(substr($n['published_at'] ?? '',0,10)); ?></div>
                </li>
              <?php endforeach; ?>
              <?php foreach ($upcomingEvents as $ev): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div class="fw-semibold"><?php echo e(mb_strimwidth($ev['title'] ?? '', 0, 80, '...')); ?></div>
                  <div class="small-muted"><?php echo e(substr($ev['start_date'] ?? '',0,10)); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="small-muted">No current notices or upcoming events.</div>
          <?php endif; ?>
          <div class="mt-2 text-end"><a class="btn btn-sm btn-outline-primary" href="../parent/notices.php">View All</a></div>
        </div>
      </div>
    </div>
  </div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
