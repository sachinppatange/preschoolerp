<?php
/**
 * Reception home — admit, call enquiries, finish incomplete forms for this academic year.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');

$page_title = 'Reception';
$pageTitle = $page_title;
$lastUpdated = date('d M Y, h:i A');
$userId = (int) (auth_user_id() ?? 0);

$u = static function (string $path): string {
    return function_exists('site_url') ? site_url($path) : $path;
};

$ayLabel = function_exists('ay_display_short') ? ay_display_short() : '';
$ayLong = function_exists('ay_display_long') ? ay_display_long() : $ayLabel;
$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$ayP = static function (array $extra = []): array {
    return function_exists('ay_params_student') ? ay_params_student($extra) : $extra;
};
$range = function_exists('ay_range') ? ay_range() : ['start' => date('Y') . '-06-01', 'end' => date('Y-m-d')];
$ayStart = $range['start'] . ' 00:00:00';
$ayEnd = $range['end'] . ' 23:59:59';

$hasStudents = function_exists('table_exists') && table_exists('students');
$hasEnquiries = function_exists('table_exists') && table_exists('enquiries');
$hasTasks = function_exists('table_exists') && table_exists('tasks');
$hasNotices = function_exists('table_exists') && table_exists('notices');

$stuName = static function (array $s): string {
    $n = trim(preg_replace('/\s+/', ' ', trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?? '');
    return $n !== '' ? $n : 'Student #' . (int) ($s['id'] ?? 0);
};
$editStu = static function (int $id): string {
    return function_exists('student_edit_url') ? student_edit_url($id) : ('students_list_edit.php?id=' . $id);
};
$phoneDigits = static function (?string $phone): string {
    return preg_replace('/\D+/', '', (string) $phone) ?? '';
};

$admUrl = $u('/reception/admission.php');
$enqUrl = $u('/reception/enquiry_list.php');
$stuUrl = $u('/reception/students_list.php');
$alertUrl = $u('/reception/pending_alerts.php');
$todoUrl = $u('/reception/pending_tasks.php');
$noticeUrl = $u('/reception/notices_publish.php');
$newsUrl = $u('/reception/news_events.php');

$studentsYear = 0;
$pendingAdmissions = 0;
$enquiriesOpen = 0;
$enquiriesToday = 0;
$todoOpen = 0;
$noticesLive = 0;
$alertCount = 0;

$today = date('Y-m-d');
$todayInAy = !function_exists('ay_contains_date') || ay_contains_date($today);

if ($hasStudents) {
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s
         WHERE LOWER(COALESCE(s.status,'active')) IN ('active','pending') AND {$ayStu}",
        $ayP()
    );
    $studentsYear = (int) ($r['c'] ?? 0);

    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s
         WHERE LOWER(COALESCE(s.status,'')) = 'pending' AND {$ayStu}",
        $ayP()
    );
    $pendingAdmissions = (int) ($r['c'] ?? 0);
}

if ($hasEnquiries) {
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM enquiries
         WHERE LOWER(COALESCE(status,'new')) IN ('new','pending')
           AND created_at BETWEEN :a AND :b",
        [':a' => $ayStart, ':b' => $ayEnd]
    );
    $enquiriesOpen = (int) ($r['c'] ?? 0);

    if ($todayInAy) {
        $r = safe_db_get_one(
            "SELECT COUNT(*) AS c FROM enquiries
             WHERE DATE(created_at) = :d
               AND created_at BETWEEN :a AND :b",
            [':d' => $today, ':a' => $ayStart, ':b' => $ayEnd]
        );
        $enquiriesToday = (int) ($r['c'] ?? 0);
    }
}

if ($hasTasks) {
    try {
        $r = $userId > 0
            ? safe_db_get_one(
                "SELECT COUNT(*) AS c FROM tasks WHERE status <> 'done' AND (assigned_to = :u OR assigned_to IS NULL)",
                [':u' => $userId]
            )
            : safe_db_get_one("SELECT COUNT(*) AS c FROM tasks WHERE status <> 'done'");
        $todoOpen = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        $todoOpen = 0;
    }
}

if ($hasNotices) {
    try {
        $r = safe_db_get_one(
            "SELECT COUNT(*) AS c FROM notices
             WHERE published_at IS NOT NULL AND published_at <= NOW()
               AND (expires_at IS NULL OR expires_at >= CURDATE())
               AND published_at BETWEEN :a AND :b",
            [':a' => $ayStart, ':b' => $ayEnd]
        );
        $noticesLive = (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        $noticesLive = 0;
    }
}

if ($hasStudents) {
    $baseWhere = "LOWER(COALESCE(s.status,'active')) IN ('active','pending') AND {$ayStu}";
    $noPhoto = 0;
    $noPhone = 0;
    $noClass = 0;
    if (function_exists('column_exists') && column_exists('students', 'photo_path')) {
        $r = safe_db_get_one(
            "SELECT COUNT(*) AS c FROM students s
             WHERE {$baseWhere} AND (s.photo_path IS NULL OR TRIM(s.photo_path) = '')",
            $ayP()
        );
        $noPhoto = (int) ($r['c'] ?? 0);
    }
    $phoneSql = "(s.father_phone IS NULL OR TRIM(s.father_phone) = '')
           AND (s.mother_phone IS NULL OR TRIM(s.mother_phone) = '')";
    if (function_exists('column_exists') && column_exists('students', 'guardian_phone')) {
        $phoneSql .= " AND (s.guardian_phone IS NULL OR TRIM(s.guardian_phone) = '')";
    }
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s WHERE {$baseWhere} AND {$phoneSql}",
        $ayP()
    );
    $noPhone = (int) ($r['c'] ?? 0);
    $r = safe_db_get_one(
        "SELECT COUNT(*) AS c FROM students s WHERE {$baseWhere} AND (s.class_id IS NULL OR s.class_id = 0)",
        $ayP()
    );
    $noClass = (int) ($r['c'] ?? 0);
    $alertCount = $enquiriesOpen + $pendingAdmissions + $noPhoto + $noPhone + $noClass;
} else {
    $alertCount = $enquiriesOpen;
}

$recentEnq = [];
if ($hasEnquiries) {
    $recentEnq = safe_db_get_all(
        "SELECT id, name, phone, message, status, created_at
         FROM enquiries
         WHERE LOWER(COALESCE(status,'new')) IN ('new','pending')
           AND created_at BETWEEN :a AND :b
         ORDER BY created_at DESC
         LIMIT 8",
        [':a' => $ayStart, ':b' => $ayEnd]
    ) ?: [];
}

$recentPending = [];
if ($hasStudents) {
    $recentPending = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, COALESCE(c.name,'') AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE LOWER(COALESCE(s.status,'')) = 'pending' AND {$ayStu}
         ORDER BY s.created_at DESC
         LIMIT 8",
        $ayP()
    ) ?: [];
}

require_once __DIR__ . '/../includes/header.php';
?>
<link href="<?php echo htmlspecialchars(rtrim(defined('BASE_URL') ? BASE_URL : '/', '/')); ?>/assets/css/dashboard-cards.css" rel="stylesheet">

<div class="dc-page">
  <div class="dc-ay-bar">
    <div class="dc-ay-chip"><i class="bi bi-person-plus"></i> Reception · <?php echo e($ayLabel); ?> · Updated <?php echo e($lastUpdated); ?></div>
  </div>

  <section class="dc-section">
    <h2 class="dc-section-title">Do this now</h2>
    <div class="row g-2 mb-2">
      <div class="col-md-6">
        <a href="<?php echo e($admUrl); ?>" class="btn btn-success w-100 py-3 fw-semibold">
          <i class="bi bi-person-plus-fill me-1"></i> New admission
        </a>
      </div>
      <div class="col-md-6">
        <a href="<?php echo e($enqUrl); ?>" class="btn btn-outline-success w-100 py-3 fw-semibold">
          <i class="bi bi-telephone me-1"></i> Call enquiries
        </a>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">This year</h2>
    <div class="dc-grid dc-grid-4">
      <a href="<?php echo e($stuUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--sky"><i class="bi bi-people-fill"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Children</span>
            <span class="dc-card-value"><?php echo number_format($studentsYear); ?></span>
            <span class="dc-card-meta"><?php echo e($ayLabel); ?></span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($enqUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--pink"><i class="bi bi-chat-left-text"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Enquiries to call</span>
            <span class="dc-card-value"><?php echo number_format($enquiriesOpen); ?></span>
            <span class="dc-card-meta"><?php echo $todayInAy ? (number_format($enquiriesToday) . ' today') : 'Not in this year'; ?></span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($alertUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--amber"><i class="bi bi-exclamation-circle"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">Need attention</span>
            <span class="dc-card-value dc-card-value--warn"><?php echo number_format($alertCount); ?></span>
            <span class="dc-card-meta"><?php echo number_format($pendingAdmissions); ?> admission not finished</span>
          </div>
        </div>
      </a>
      <a href="<?php echo e($todoUrl); ?>" class="dc-card dc-card--link">
        <div class="dc-card-top">
          <div class="dc-card-icon dc-card-icon--green"><i class="bi bi-check2-square"></i></div>
          <div class="dc-card-info">
            <span class="dc-card-label">To-do</span>
            <span class="dc-card-value"><?php echo number_format($todoOpen); ?></span>
            <span class="dc-card-meta">Still open</span>
          </div>
        </div>
      </a>
    </div>
  </section>

  <section class="dc-section">
    <div class="dc-grid dc-grid-2">
      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Call these parents</span>
          <a href="<?php echo e($enqUrl); ?>" class="dc-section-link">All enquiries</a>
        </div>
        <?php if ($recentEnq === []): ?>
          <p class="dc-card-meta mb-0">No open enquiries this year.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($recentEnq as $item):
                $ph = $phoneDigits($item['phone'] ?? '');
                $ph10 = $ph !== '' ? substr($ph, -10) : '';
            ?>
              <li class="d-flex justify-content-between gap-2 py-2 border-bottom">
                <div>
                  <div class="fw-semibold"><?php echo e(trim((string) ($item['name'] ?? '')) !== '' ? (string) $item['name'] : 'Enquiry'); ?></div>
                  <div class="small text-muted"><?php echo e(mb_strimwidth((string) ($item['message'] ?? ''), 0, 48, '…')); ?>
                    · <?php echo e(substr((string) ($item['created_at'] ?? ''), 0, 10)); ?></div>
                </div>
                <div class="text-nowrap">
                  <?php if ($ph10 !== ''): ?>
                    <a class="btn btn-sm btn-outline-primary" href="tel:<?php echo e($ph10); ?>">Call</a>
                    <a class="btn btn-sm btn-outline-success" href="https://wa.me/91<?php echo e($ph10); ?>" target="_blank" rel="noopener">WA</a>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="dc-card dc-card--panel">
        <div class="dc-card-header">
          <span class="dc-card-label">Finish these admissions</span>
          <a href="<?php echo e($alertUrl); ?>" class="dc-section-link">All alerts</a>
        </div>
        <?php if ($recentPending === []): ?>
          <p class="dc-card-meta mb-0">No pending admissions this year.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php foreach ($recentPending as $s): ?>
              <li class="d-flex justify-content-between gap-2 py-2 border-bottom">
                <div>
                  <div class="fw-semibold"><?php echo e($stuName($s)); ?></div>
                  <div class="small text-muted"><?php echo e((string) ($s['class_name'] ?? '') !== '' ? (string) $s['class_name'] : 'No class'); ?></div>
                </div>
                <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?php echo e($editStu((int) $s['id'])); ?>">Open</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="dc-section">
    <h2 class="dc-section-title">Other pages</h2>
    <div class="row g-2">
      <?php
      $quick = [
          [$stuUrl, 'bi-people', 'Students'],
          [$alertUrl, 'bi-bell', 'Alerts'],
          [$todoUrl, 'bi-check2-square', 'To-do'],
          [$noticeUrl, 'bi-megaphone', 'Notices' . ($noticesLive > 0 ? ' (' . $noticesLive . ')' : '')],
          [$newsUrl, 'bi-newspaper', 'News & Events'],
      ];
      foreach ($quick as $q): ?>
        <div class="col-6 col-md-4 col-lg">
          <a href="<?php echo e($q[0]); ?>" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi <?php echo e($q[1]); ?> fs-4"></i>
            <span class="small fw-semibold"><?php echo e($q[2]); ?></span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
