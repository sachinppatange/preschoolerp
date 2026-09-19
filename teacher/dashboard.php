<?php
/**
 * teacher/dashboard.php — preschool class teacher home for today.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$page_title = 'My class today';
$pageTitle = $page_title;
$skip_panel_ay_banner = true;
$lastUpdated = date('d M Y, h:i A');
$todayLabel = date('d M Y');
$today = date('Y-m-d');
$todayName = date('l');
$teacherId = (int) (auth_user_id() ?? 0);
$ay = function_exists('ay_selected') ? ay_selected() : '';
$ayLabel = function_exists('ay_display_short') ? ay_display_short() : '';
$ayLong = function_exists('ay_display_long') ? ay_display_long() : $ayLabel;

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

$attUrl = $u('/teacher/attendance_mark.php');
$hwUrl = $u('/teacher/homeworks.php');
$photoUrl = $u('/teacher/class_photo_upload.php');
$remarkUrl = $u('/teacher/student_remarks.php');
$noticeUrl = $u('/teacher/notices.php');
$ttUrl = $u('/teacher/timetable.php');
$todoUrl = $u('/teacher/tasks.php');
$classesUrl = $u('/teacher/my_classes.php');

$classRank = static function (array $c): int {
    $n = strtolower((string) ($c['name'] ?? ''));
    if (str_starts_with($n, 'play')) {
        return 1;
    }
    if (str_starts_with($n, 'nurs')) {
        return 2;
    }
    if (str_starts_with($n, 'l')) {
        return 3;
    }
    if (str_starts_with($n, 'u')) {
        return 4;
    }
    return 9;
};

$assignedClasses = panel_teacher_assigned_classes($teacherId);
usort($assignedClasses, static function (array $a, array $b) use ($classRank): int {
    $d = $classRank($a) <=> $classRank($b);
    return $d !== 0 ? $d : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$classIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $assignedClasses)));
$classNames = [];
foreach ($assignedClasses as $c) {
    $classNames[(int) $c['id']] = (string) ($c['name'] ?? 'Class');
}

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$ayP = static function (array $extra = []): array {
    return function_exists('ay_params_student') ? ay_params_student($extra) : $extra;
};
$statusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";

$childCount = 0;
$perClassKids = [];
if ($classIds !== [] && table_exists('students')) {
    $in = implode(',', $classIds);
    $rows = safe_db_get_all(
        "SELECT s.class_id, COUNT(*) AS c
         FROM students s
         WHERE {$statusSql} AND {$ayStu} AND s.class_id IN ({$in})
         GROUP BY s.class_id",
        $ayP()
    ) ?: [];
    foreach ($rows as $r) {
        $cid = (int) ($r['class_id'] ?? 0);
        $n = (int) ($r['c'] ?? 0);
        $perClassKids[$cid] = $n;
        $childCount += $n;
    }
}

$attByClass = [];
$present = 0;
$absent = 0;
$leave = 0;
$markedClasses = 0;
if ($classIds !== [] && table_exists('attendance')) {
    $in = implode(',', $classIds);
    $rows = safe_db_get_all(
        "SELECT class_id, LOWER(status) AS st, COUNT(*) AS c
         FROM attendance
         WHERE `date` = :d AND class_id IN ({$in})
         GROUP BY class_id, LOWER(status)",
        [':d' => $today]
    ) ?: [];
    foreach ($rows as $r) {
        $cid = (int) ($r['class_id'] ?? 0);
        $st = (string) ($r['st'] ?? '');
        $n = (int) ($r['c'] ?? 0);
        if (!isset($attByClass[$cid])) {
            $attByClass[$cid] = ['present' => 0, 'absent' => 0, 'leave' => 0, 'marked' => 0];
        }
        if (in_array($st, ['present', 'late'], true)) {
            $attByClass[$cid]['present'] += $n;
            $present += $n;
        } elseif ($st === 'absent') {
            $attByClass[$cid]['absent'] += $n;
            $absent += $n;
        } else {
            $attByClass[$cid]['leave'] += $n;
            $leave += $n;
        }
        $attByClass[$cid]['marked'] += $n;
    }
    foreach ($attByClass as $block) {
        if (($block['marked'] ?? 0) > 0) {
            $markedClasses++;
        }
    }
}
$attPending = max(0, count($classIds) - $markedClasses);

$todoOpen = 0;
$todoHelp = 0;
if (table_exists('tasks') && $teacherId > 0) {
    try {
        $todoOpen = (int) (safe_db_get_one(
            "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = :u AND status = 'pending'",
            [':u' => $teacherId]
        )['c'] ?? 0);
        $todoHelp = (int) (safe_db_get_one(
            "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = :u AND status IN ('blocked','in_progress')",
            [':u' => $teacherId]
        )['c'] ?? 0);
    } catch (Throwable $e) {
        $todoOpen = 0;
        $todoHelp = 0;
    }
}

$birthdays = ['students' => [], 'count' => 0];
if ($classIds !== [] && table_exists('students') && function_exists('column_exists') && column_exists('students', 'dob')) {
    $in = implode(',', $classIds);
    $bRows = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.class_id, COALESCE(c.name,'') AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$statusSql} AND {$ayStu} AND s.class_id IN ({$in})
           AND s.dob IS NOT NULL
           AND MONTH(s.dob) = MONTH(CURDATE()) AND DAY(s.dob) = DAY(CURDATE())
         ORDER BY s.first_name ASC
         LIMIT 8",
        $ayP()
    ) ?: [];
    $birthdays = ['students' => $bRows, 'count' => count($bRows)];
}

$routine = [];
if ($classIds !== [] && table_exists('timetable')) {
    $in = implode(',', $classIds);
    $routine = safe_db_get_all(
        "SELECT * FROM timetable
         WHERE class_id IN ({$in}) AND day_of_week = :day
         ORDER BY start_time ASC, id ASC
         LIMIT 12",
        [':day' => $todayName]
    ) ?: [];
}

$recentHw = [];
if ($classIds !== [] && table_exists('homeworks')) {
    $in = implode(',', $classIds);
    $recentHw = safe_db_get_all(
        "SELECT id, title, class_id, assigned_date
         FROM homeworks
         WHERE class_id IN ({$in})
         ORDER BY assigned_date DESC, id DESC
         LIMIT 6"
    ) ?: [];
}

$fmtTime = static function (?string $t): string {
    $t = trim((string) $t);
    if ($t === '' || $t === '00:00:00') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $t);
    return $ts ? date('g:i a', $ts) : substr($t, 0, 5);
};
$slotName = static function (array $r): string {
    foreach (['subject_name', 'subject', 'title', 'period'] as $k) {
        if (!empty($r[$k]) && !is_numeric((string) $r[$k])) {
            return (string) $r[$k];
        }
    }
    return 'Activity';
};
$stuName = static function (array $s): string {
    if (function_exists('student_full_name')) {
        $n = trim(student_full_name($s));
        if ($n !== '') {
            return $n;
        }
    }
    return trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
};
$fmtDay = static function (?string $d): string {
    $d = substr((string) $d, 0, 10);
    if ($d === '' || $d === '0000-00-00') {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d M', $t) : $d;
};

require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?php echo htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/')); ?>/assets/css/dashboard-cards.css" rel="stylesheet">
<style>
.rx-action { display:flex; flex-direction:column; align-items:flex-start; gap:.35rem; min-height:108px; }
.rx-action i { font-size:1.45rem; }
.rx-action strong { font-size:1.02rem; }
.rx-action span { font-size:.8rem; color:#64748b; font-weight:600; }
.td-class { display:flex; justify-content:space-between; gap:8px; align-items:center; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.td-class:last-child { border-bottom:0; }
</style>

<div class="dc-page">
  <div class="dc-ay-bar">
    <div class="dc-ay-chip d-none d-md-inline-flex"><i class="bi bi-journal-bookmark"></i> Class teacher · <?php echo e($ayLong); ?> · Updated <?php echo e($lastUpdated); ?></div>
    <div class="dc-ay-mobile d-md-none">
      <?php if (function_exists('render_dashboard_academic_year_dropdown')) {
          render_dashboard_academic_year_dropdown();
      } ?>
      <span class="dc-ay-mobile-meta">Updated <?php echo e($lastUpdated); ?></span>
    </div>
  </div>

  <p class="text-muted small mb-3">Your day with the children: who came, a short homework, a photo, a note for parents. Fees stay with the office.</p>

  <?php if ($assignedClasses === []): ?>
    <?php echo panel_teacher_empty_classes_html(); ?>
  <?php else: ?>

  <section class="dc-section">
    <h2 class="dc-section-title">Do this now</h2>
    <div class="dc-grid dc-grid-3">
      <a href="<?php echo e($attUrl); ?>" class="dc-card dc-card--link rx-action">
        <i class="bi bi-person-check-fill text-success"></i>
        <strong>Who came today</strong>
        <span><?php echo $attPending > 0 ? ($attPending . ' class' . ($attPending === 1 ? '' : 'es') . ' not marked') : 'Attendance done'; ?></span>
      </a>
      <a href="<?php echo e($hwUrl); ?>" class="dc-card dc-card--link rx-action">
        <i class="bi bi-journal-text" style="color:#3aa8f0"></i>
        <strong>Homework</strong>
        <span>One short thing for home</span>
      </a>
      <a href="<?php echo e($photoUrl); ?>" class="dc-card dc-card--link rx-action">
        <i class="bi bi-camera-fill" style="color:#d97706"></i>
        <strong>Class photos</strong>
        <span>For the parent gallery</span>
      </a>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Today · <?php echo e($todayLabel); ?></h2>
    <div class="dc-grid dc-grid-3">
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--green"><i class="bi bi-clipboard-check"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">In class</span>
            <span class="dc-card-value"><?php echo (int) $present; ?></span>
            <span class="dc-card-meta"><?php echo (int) $absent; ?> absent · <?php echo (int) $leave; ?> leave</span>
          </div>
        </div>
      </div>
      <div class="dc-card">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-cake2-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Birthdays</span>
            <span class="dc-card-value"><?php echo (int) ($birthdays['count'] ?? 0); ?></span>
            <span class="dc-card-meta">Wish them in circle time</span>
          </div>
        </div>
        <?php if (!empty($birthdays['students'])): ?>
          <ul class="dc-birthday-list">
            <?php foreach (array_slice($birthdays['students'], 0, 5) as $b): ?>
              <li>
                <span class="name"><?php echo e($stuName($b)); ?></span>
                <span class="meta"><?php echo e((string) ($b['class_name'] ?? '')); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <a href="<?php echo e($todoUrl . ($todoHelp > 0 ? '?tab=help' : '')); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--<?php echo $todoHelp > 0 ? 'rose' : 'sky'; ?>"><i class="bi bi-check2-square"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">To-do</span>
            <span class="dc-card-value"><?php echo (int) $todoOpen; ?></span>
            <span class="dc-card-meta"><?php echo $todoHelp > 0 ? ((int) $todoHelp . ' need help') : 'Office jobs for you'; ?></span>
          </div>
        </div>
      </a>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Your classes · <?php echo (int) $childCount; ?> children</h2>
    <div class="dc-card">
      <?php foreach ($assignedClasses as $c):
          $cid = (int) $c['id'];
          $kids = (int) ($perClassKids[$cid] ?? 0);
          $att = $attByClass[$cid] ?? null;
          $done = $att && (int) ($att['marked'] ?? 0) > 0;
          ?>
        <div class="td-class">
          <div>
            <div class="fw-bold"><?php echo e((string) ($c['name'] ?? 'Class')); ?></div>
            <div class="small text-muted">
              <?php echo $kids; ?> children
              <?php if ($done): ?>
                · <?php echo (int) $att['present']; ?> present · <?php echo (int) $att['absent']; ?> absent
              <?php else: ?>
                · attendance not marked
              <?php endif; ?>
            </div>
          </div>
          <a class="btn btn-sm <?php echo $done ? 'btn-outline-secondary' : 'btn-success'; ?>" href="<?php echo e($attUrl . '?class_id=' . $cid); ?>"><?php echo $done ? 'Check' : 'Mark'; ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dc-section">
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Today’s routine · <?php echo e($todayName); ?></span>
          <a href="<?php echo e($ttUrl); ?>" class="dc-section-link">Edit</a>
        </div>
        <?php if ($routine === []): ?>
          <p class="dc-card-meta mb-0">No routine slots for today. Add Circle time, Snack, Outdoor on the routine page.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($routine as $slot):
                $st = $fmtTime((string) ($slot['start_time'] ?? ''));
                $en = $fmtTime((string) ($slot['end_time'] ?? ''));
                $when = $st !== '' ? ($en !== '' ? $st . ' – ' . $en : $st) : '';
                $cid = (int) ($slot['class_id'] ?? 0);
                ?>
              <li class="py-2 border-bottom">
                <div class="fw-semibold"><?php echo e($slotName($slot)); ?></div>
                <div class="small text-muted"><?php echo e($classNames[$cid] ?? ''); ?><?php echo $when !== '' ? ' · ' . e($when) : ''; ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Recent homework</span>
          <a href="<?php echo e($hwUrl); ?>" class="dc-section-link">Add</a>
        </div>
        <?php if ($recentHw === []): ?>
          <p class="dc-card-meta mb-0">None yet. Add one short thing parents can do at home.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($recentHw as $hw):
                $cid = (int) ($hw['class_id'] ?? 0);
                ?>
              <li class="py-2 border-bottom">
                <div class="fw-semibold"><?php echo e((string) ($hw['title'] ?? '')); ?></div>
                <div class="small text-muted"><?php echo e($classNames[$cid] ?? ''); ?> · <?php echo e($fmtDay((string) ($hw['assigned_date'] ?? ''))); ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Also</h2>
    <div class="row g-2">
      <?php
      $quick = [
          [$remarkUrl, 'bi-chat-square-text', 'Child note'],
          [$noticeUrl, 'bi-megaphone', 'Parent notice'],
          [$ttUrl, 'bi-calendar-week', 'Class routine'],
          [$classesUrl, 'bi-people', 'My children'],
          [$todoUrl, 'bi-check2-square', 'To-do'],
      ];
      foreach ($quick as $q): ?>
        <div class="col-6 col-md">
          <a href="<?php echo e($q[0]); ?>" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi <?php echo e($q[1]); ?> fs-4"></i>
            <span class="small fw-semibold"><?php echo e($q[2]); ?></span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
