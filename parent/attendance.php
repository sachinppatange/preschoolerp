<?php
/**
 * parent/attendance.php
 *
 * Parent-facing Attendance viewer.
 *
 * Features:
 * - Requires parent login ($_SESSION['parent_auth_user'])
 * - Loads linked children from parents_children (fallback to students.parent_id)
 * - Shows summary (last 30 days) and per-child attendance for selected child (date range)
 * - Exports attendance CSV for selected child or all linked children (date range)
 * - Uses attendance table with schema:
 *     id, school_id, student_id, class_id, date, status, recorded_by, notes, created_at
 *
 * Place at: /pioneerplayschool01/parent/attendance.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('parent');
$DEBUG = panel_debug();

/* ------------------------
   Require parent login
   ------------------------ */
/* CSRF helpers */

/* ------------------------
   Identify tables and user
   ------------------------ */
$hasParentsChildren = table_exists('parents_children');
$hasStudents = table_exists('students');
$hasAttendance = table_exists('attendance');

$parent = auth_user() ?? [];
$parentId = panel_parent_context_id();

/* ------------------------
   Load linked children
   ------------------------ */
$childIds = [];
if ($hasParentsChildren) {
    $maps = safe_db_get_all("SELECT child_student_id FROM parents_children WHERE parent_user_id = :pid ORDER BY id DESC", [':pid' => $parentId]);
    foreach ($maps as $m) {
        $childIds[] = (int) ($m['child_student_id'] ?? 0);
    }
}

if (empty($childIds) && $hasStudents) {
    $rows = safe_db_get_all("SELECT id FROM students WHERE parent_id = :pid", [':pid' => $parentId]);
    foreach ($rows as $r) {
        $childIds[] = (int) ($r['id'] ?? 0);
    }
}

/* ------------------------
   Load child details
   ------------------------ */
$children = [];
if (!empty($childIds) && $hasStudents) {
    $ph = implode(',', array_fill(0, count($childIds), '?'));
    $sql = "SELECT id, first_name, middle_name, last_name, class_id, form_no, photo_path, admission_date FROM students WHERE id IN ($ph)";
    $rows = safe_db_get_all($sql, $childIds);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    foreach ($childIds as $cid) {
        if (isset($byId[$cid])) {
            $children[] = $byId[$cid];
        } else {
            $children[] = ['id' => $cid, 'first_name' => 'Student', 'middle_name' => '', 'last_name' => '#'.$cid, 'class_id' => null, 'form_no' => null, 'photo_path' => null];
        }
    }
}

/* ------------------------
   Selected child and date range
   ------------------------ */
$selectedChildId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
if ($selectedChildId === 0 && !empty($childIds)) {
    $selectedChildId = $childIds[0];
}
if ($selectedChildId !== 0 && !in_array($selectedChildId, $childIds, true)) {
    $selectedChildId = ($childIds[0] ?? 0);
}

$from = isset($_GET['from']) ? substr((string)$_GET['from'], 0, 10) : '';
$to   = isset($_GET['to'])   ? substr((string)$_GET['to'], 0, 10) : '';

$today = new DateTimeImmutable('now');
$defaultFrom = $today->sub(new DateInterval('P30D'))->format('Y-m-d');
$defaultTo   = $today->format('Y-m-d');

if ($from === '') $from = $defaultFrom;
if ($to === '')   $to   = $defaultTo;

/* validate dates */
try {
    $dt = new DateTimeImmutable($from);
    $from = $dt->format('Y-m-d');
} catch (Throwable $e) {
    $from = $defaultFrom;
}
try {
    $dt = new DateTimeImmutable($to);
    $to = $dt->format('Y-m-d');
} catch (Throwable $e) {
    $to = $defaultTo;
}

/* ------------------------
   Export CSV
   ------------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    if (!$hasAttendance) {
        http_response_code(404);
        echo "Attendance table missing.";
        exit;
    }

    $exportStudent = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
    if ($exportStudent > 0 && !in_array($exportStudent, $childIds, true)) {
        http_response_code(403);
        echo "Forbidden.";
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_export_' . ($exportStudent ?: 'all') . '_' . $from . '_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','school_id','student_id','class_id','date','status','recorded_by','notes','created_at']);

    if ($exportStudent > 0) {
        $rows = safe_db_get_all("SELECT id, school_id, student_id, class_id, date, status, recorded_by, notes, created_at FROM attendance WHERE student_id = :sid AND DATE(date) BETWEEN :from AND :to ORDER BY date DESC", [':sid' => $exportStudent, ':from' => $from, ':to' => $to]);
    } else {
        if (empty($childIds)) {
            $rows = [];
        } else {
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            $params = array_merge($childIds, [$from, $to]);
            $rows = safe_db_get_all("SELECT id, school_id, student_id, class_id, date, status, recorded_by, notes, created_at FROM attendance WHERE student_id IN ($ph) AND DATE(date) BETWEEN ? AND ? ORDER BY date DESC", $params);
        }
    }

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['student_id'] ?? '',
            $r['class_id'] ?? '',
            $r['date'] ?? '',
            $r['status'] ?? '',
            $r['recorded_by'] ?? '',
            $r['notes'] ?? '',
            $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------
   Summary (last 30 days)
   ------------------------ */
$summary = ['present' => 0, 'absent' => 0, 'other' => 0];

if ($hasAttendance && !empty($childIds)) {
    try {
        $since = (new DateTimeImmutable())->sub(new DateInterval('P30D'))->format('Y-m-d');
        $pdo = pdo_connect();
        if ($pdo instanceof PDO) {
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            $sql = "SELECT status, COUNT(*) AS cnt FROM attendance WHERE student_id IN ($ph) AND DATE(date) >= :since GROUP BY status";
            $stmt = $pdo->prepare($sql);
            $i = 1;
            foreach ($childIds as $cid) {
                $stmt->bindValue($i++, $cid, PDO::PARAM_INT);
            }
            $stmt->bindValue(':since', $since);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $sql = "SELECT status, COUNT(*) AS cnt FROM attendance WHERE student_id IN (" . implode(',', array_fill(0, count($childIds), '?')) . ") AND DATE(date) >= ? GROUP BY status";
            $rows = safe_db_get_all($sql, array_merge($childIds, [$since]));
        }
        foreach ($rows as $r) {
            $st = strtolower((string)($r['status'] ?? ''));
            $cnt = (int)($r['cnt'] ?? 0);
            if ($st === 'present' || $st === 'p') $summary['present'] += $cnt;
            elseif ($st === 'absent' || $st === 'a') $summary['absent'] += $cnt;
            else $summary['other'] += $cnt;
        }
    } catch (Throwable $e) {
        // ignore summary errors
    }
}

/* ------------------------
   Attendance list for selected child
   ------------------------ */
$attendanceList = [];
if ($hasAttendance && $selectedChildId > 0) {
    $attendanceList = safe_db_get_all(
        "SELECT id, school_id, student_id, class_id, date, status, recorded_by, notes, created_at FROM attendance WHERE student_id = :sid AND DATE(date) BETWEEN :from AND :to ORDER BY date DESC",
        [':sid' => $selectedChildId, ':from' => $from, ':to' => $to]
    );
}

/* ------------------------
   Render HTML
   ------------------------ */
$pageTitle = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
echo panel_owner_parent_gate_html();
?>

<div>
    <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">Dashboard</a>
    <?php if ($hasAttendance): ?>
      <a class="btn btn-sm btn-success" href="?action=export&student_id=<?php echo e($selectedChildId); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">Export CSV</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$hasAttendance): ?>
  <div class="alert alert-warning">Attendance table is not available on this system.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-md-4">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h6 class="mb-2">Summary (last 30 days)</h6>
        <div class="mb-1 small-muted">Present</div>
        <div class="h5"><?php echo number_format($summary['present']); ?></div>
        <div class="small-muted mt-2">Absent: <?php echo number_format($summary['absent']); ?> • Other: <?php echo number_format($summary['other']); ?></div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Linked Children</h6>
        <?php if (empty($children)): ?>
          <div class="small-muted">No linked children found.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($children as $ch): $cid = (int)($ch['id'] ?? 0); ?>
              <a class="list-group-item list-group-item-action <?php echo ($cid === $selectedChildId) ? 'active text-white' : ''; ?>" href="?student_id=<?php echo e($cid); ?>&from=<?php echo e($from); ?>&to=<?php echo e($to); ?>">
                <div class="d-flex align-items-center">
                  <div class="me-2">
                    <?php if (!empty($ch['photo_path'])): ?>
                      <img src="<?php echo e(function_exists('student_photo_url') ? student_photo_url((string) ($ch['photo_path'] ?? '')) : (string) ($ch['photo_path'] ?? '')); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px">
                    <?php else: ?>
                      <div style="width:40px;height:40px;background:#f1f1f1;border-radius:6px"></div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="fw-semibold"><?php echo e(trim((($ch['first_name'] ?? '') . ' ' . ($ch['middle_name'] ?? '') . ' ' . ($ch['last_name'] ?? '')))); ?></div>
                    <div class="small-muted">Class: <?php echo e($ch['class_id'] ?? '—'); ?> • Form: <?php echo e($ch['form_no'] ?? '—'); ?></div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="col-12 col-md-8">
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0">Attendance for
            <?php
              $selName = 'Selected child';
              foreach ($children as $c) {
                  if ((int)($c['id'] ?? 0) === $selectedChildId) {
                      $selName = trim((($c['first_name'] ?? '') . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
                      break;
                  }
              }
              echo e($selName);
            ?>
          </h6>

          <form method="get" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="student_id" value="<?php echo e($selectedChildId); ?>">
            <label class="small-muted mb-0">From</label>
            <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control form-control-sm" style="width:150px">
            <label class="small-muted mb-0">To</label>
            <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control form-control-sm" style="width:150px">
            <button class="btn btn-sm btn-primary">Filter</button>
          </form>
        </div>

        <?php if (!$hasAttendance): ?>
          <div class="small-muted">Attendance data not available.</div>
        <?php elseif ($selectedChildId <= 0): ?>
          <div class="small-muted">Select a child to view attendance records.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th style="width:110px">Date</th>
                  <th style="width:120px">Status</th>
                  <th>Recorded by</th>
                  <th>Notes</th>
                  <th style="width:120px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($attendanceList)): ?>
                  <tr><td colspan="5" class="text-center small-muted">No attendance records for the selected period.</td></tr>
                <?php else: foreach ($attendanceList as $a): ?>
                  <tr>
                    <td><?php echo e(substr((string)($a['date'] ?? ''), 0, 10)); ?></td>
                    <td><?php echo e(ucfirst((string)($a['status'] ?? ''))); ?></td>
                    <td class="small-muted"><?php echo e($a['recorded_by'] ?? '—'); ?></td>
                    <td><?php echo e($a['notes'] ?? ''); ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="attendance_detail.php?id=<?php echo (int)($a['id'] ?? 0); ?>">View</a></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="mb-2">Attendance calendar (<?php echo e($from); ?> — <?php echo e($to); ?>)</h6>

        <?php if (!$hasAttendance || empty($childIds)): ?>
          <div class="small-muted">Attendance calendar not available.</div>
        <?php else: ?>
          <?php
            $periodStart = new DateTimeImmutable($from);
            $periodEnd = new DateTimeImmutable($to);
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($periodStart, $interval, $periodEnd->modify('+1 day'));
            $attMap = [];

            if ($selectedChildId > 0) {
                $rows = safe_db_get_all("SELECT DATE(date) AS d, status FROM attendance WHERE student_id = :sid AND DATE(date) BETWEEN :from AND :to", [':sid' => $selectedChildId, ':from' => $from, ':to' => $to]);
                foreach ($rows as $r) {
                    $attMap[$r['d']] = $r['status'];
                }
            } else {
                // aggregate across all children
                $ph = implode(',', array_fill(0, count($childIds), '?'));
                $params = array_merge($childIds, [$from, $to]);
                $rows = safe_db_get_all("SELECT DATE(date) AS d, status, COUNT(*) AS cnt FROM attendance WHERE student_id IN ($ph) AND DATE(date) BETWEEN ? AND ? GROUP BY DATE(date), status", $params);
                $tmp = [];
                foreach ($rows as $r) {
                    $d = $r['d'];
                    $st = strtolower((string)$r['status']);
                    $tmp[$d][$st] = (int)$r['cnt'];
                }
                foreach ($tmp as $d => $map) {
                    if (!empty($map['present']) || !empty($map['p'])) $attMap[$d] = 'present';
                    elseif (!empty($map['absent']) || !empty($map['a'])) $attMap[$d] = 'absent';
                    else $attMap[$d] = key($map) ?? null;
                }
            }
          ?>

          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($period as $day): $d = $day->format('Y-m-d'); $label = $day->format('M j'); ?>
              <?php
                $st = $attMap[$d] ?? null;
                $cls = 'bg-secondary text-white';
                $title = 'No record';
                if ($st !== null) {
                    $s = strtolower((string)$st);
                    if ($s === 'present' || $s === 'p') { $cls = 'bg-success text-white'; $title = 'Present'; }
                    elseif ($s === 'absent' || $s === 'a') { $cls = 'bg-danger text-white'; $title = 'Absent'; }
                    else { $cls = 'bg-warning text-dark'; $title = ucfirst($s); }
                }
              ?>
              <div style="width:88px">
                <div class="p-2 <?php echo $cls; ?> text-center" title="<?php echo e($title . ' — ' . $d); ?>" style="border-radius:6px;">
                  <div style="font-size:.9rem"><?php echo e($label); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';

?>