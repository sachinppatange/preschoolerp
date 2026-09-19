<?php
/**
 * Reception/owner alerts: unfinished admission and enquiry work for this academic year.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'reception');
$page_title = 'Alerts';
$pageTitle = $page_title;

$enqUrl = function_exists('site_url') ? site_url('/' . $panel . '/enquiry_list.php') : '../' . $panel . '/enquiry_list.php';
$admUrl = function_exists('site_url') ? site_url('/reception/admission.php') : '../reception/admission.php';
$todoUrl = function_exists('site_url') ? site_url('/' . $panel . '/pending_tasks.php') : 'pending_tasks.php';

$ayStu = function_exists('ay_sql_student') ? ay_sql_student('s') : '1=1';
$ayP = static function (array $extra = []): array {
    return function_exists('ay_params_student') ? ay_params_student($extra) : $extra;
};
$ayLabel = function_exists('ay_display_long') ? ay_display_long() : (function_exists('ay_selected') ? ay_selected() : '');

$stuName = static function (array $s): string {
    $n = trim(preg_replace('/\s+/', ' ', trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))));
    return $n !== '' ? $n : 'Student #' . (int) ($s['id'] ?? 0);
};

$editStu = static function (int $id): string {
    return function_exists('student_edit_url') ? student_edit_url($id) : ('students_list_edit.php?id=' . $id);
};

$view = trim((string) ($_GET['view'] ?? 'all'));
$allowed = ['all', 'enquiry', 'photo', 'phone', 'class', 'pending'];
if (!in_array($view, $allowed, true)) {
    $view = 'all';
}

$groups = [
    'enquiry' => ['title' => 'New enquiries', 'hint' => 'Call or WhatsApp, then mark contacted on Enquiries.', 'items' => []],
    'pending' => ['title' => 'Admission not finished', 'hint' => 'Status is still pending — complete the form.', 'items' => []],
    'photo' => ['title' => 'No child photo', 'hint' => 'Add a photo on the student page.', 'items' => []],
    'phone' => ['title' => 'No parent mobile', 'hint' => 'Add father or mother phone so you can call.', 'items' => []],
    'class' => ['title' => 'No class', 'hint' => 'Put the child in Playgroup / Nursery / LKG / UKG.', 'items' => []],
];

if (function_exists('table_exists') && table_exists('enquiries')) {
    $enqWhere = ["LOWER(COALESCE(status,'new')) IN ('new','pending')"];
    $enqParams = [];
    if (function_exists('column_exists') && column_exists('enquiries', 'academic_year') && function_exists('ay_selected')) {
        $enqWhere[] = 'academic_year = :panel_ay';
        $enqParams[':panel_ay'] = ay_selected();
    } elseif (function_exists('ay_apply_date_filter')) {
        ay_apply_date_filter($enqWhere, $enqParams, 'created_at');
    }
    $enqSql = 'WHERE ' . implode(' AND ', $enqWhere);
    $rows = safe_db_get_all(
        "SELECT id, name, phone, status, created_at
         FROM enquiries
         {$enqSql}
         ORDER BY created_at DESC
         LIMIT 80",
        $enqParams
    ) ?: [];
    foreach ($rows as $r) {
        $groups['enquiry']['items'][] = [
            'who' => trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : 'Enquiry',
            'meta' => trim((string) ($r['phone'] ?? '')) . ' · ' . substr((string) ($r['created_at'] ?? ''), 0, 10),
            'href' => $enqUrl,
        ];
    }
}

if (function_exists('table_exists') && table_exists('students')) {
    $hasPhoto = function_exists('column_exists') && column_exists('students', 'photo_path');
    $baseWhere = "LOWER(COALESCE(s.status,'active')) IN ('active','pending') AND {$ayStu}";

    $pending = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, COALESCE(c.name,'') AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE LOWER(COALESCE(s.status,'')) = 'pending' AND {$ayStu}
         ORDER BY s.first_name ASC
         LIMIT 80",
        $ayP()
    ) ?: [];
    foreach ($pending as $s) {
        $groups['pending']['items'][] = [
            'who' => $stuName($s),
            'meta' => (string) ($s['class_name'] ?? ''),
            'href' => $editStu((int) $s['id']),
        ];
    }

    if ($hasPhoto) {
        $photos = safe_db_get_all(
            "SELECT s.id, s.first_name, s.middle_name, s.last_name, COALESCE(c.name,'') AS class_name
             FROM students s
             LEFT JOIN classes c ON c.id = s.class_id
             WHERE {$baseWhere}
               AND (s.photo_path IS NULL OR TRIM(s.photo_path) = '')
             ORDER BY s.first_name ASC
             LIMIT 80",
            $ayP()
        ) ?: [];
        foreach ($photos as $s) {
            $groups['photo']['items'][] = [
                'who' => $stuName($s),
                'meta' => (string) ($s['class_name'] ?? ''),
                'href' => $editStu((int) $s['id']),
            ];
        }
    }

    $phoneSql = "(s.father_phone IS NULL OR TRIM(s.father_phone) = '')
           AND (s.mother_phone IS NULL OR TRIM(s.mother_phone) = '')";
    if (function_exists('column_exists') && column_exists('students', 'guardian_phone')) {
        $phoneSql .= " AND (s.guardian_phone IS NULL OR TRIM(s.guardian_phone) = '')";
    }
    $phones = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, COALESCE(c.name,'') AS class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$baseWhere}
           AND {$phoneSql}
         ORDER BY s.first_name ASC
         LIMIT 80",
        $ayP()
    ) ?: [];
    foreach ($phones as $s) {
        $groups['phone']['items'][] = [
            'who' => $stuName($s),
            'meta' => (string) ($s['class_name'] ?? ''),
            'href' => $editStu((int) $s['id']),
        ];
    }

    $noClass = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name
         FROM students s
         WHERE {$baseWhere}
           AND (s.class_id IS NULL OR s.class_id = 0)
         ORDER BY s.first_name ASC
         LIMIT 80",
        $ayP()
    ) ?: [];
    foreach ($noClass as $s) {
        $groups['class']['items'][] = [
            'who' => $stuName($s),
            'meta' => 'No class',
            'href' => $editStu((int) $s['id']),
        ];
    }
}

$counts = [];
$total = 0;
foreach ($groups as $key => $g) {
    $n = count($g['items']);
    $counts[$key] = $n;
    $total += $n;
}

$showKeys = $view === 'all' ? array_keys($groups) : [$view];

require_once __DIR__ . '/../header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Alerts</h1>
    <p class="text-muted mb-0">Only <strong><?php echo e($ayLabel); ?></strong>. Fix the form and it leaves this list. Notes for yourself are on <a href="<?php echo e($todoUrl); ?>">To-do</a>.</p>
  </div>
  <a class="btn btn-success" href="<?php echo e($admUrl); ?>">New admission</a>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm <?php echo $view === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?view=all">All (<?php echo (int) $total; ?>)</a>
  <?php foreach ($groups as $key => $g): ?>
    <a class="btn btn-sm <?php echo $view === $key ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?view=<?php echo e($key); ?>">
      <?php echo e($g['title']); ?> (<?php echo (int) $counts[$key]; ?>)
    </a>
  <?php endforeach; ?>
</div>

<?php if ($total === 0): ?>
  <div class="alert alert-success mb-0">Nothing pending. New enquiries and incomplete student details will show here.</div>
<?php else: ?>
  <?php foreach ($showKeys as $key):
      $g = $groups[$key];
      if ($g['items'] === [] && $view !== $key) {
          continue;
      }
  ?>
    <div class="card mb-3">
      <div class="card-header bg-white">
        <div class="fw-semibold"><?php echo e($g['title']); ?> · <?php echo count($g['items']); ?></div>
        <div class="small text-muted"><?php echo e($g['hint']); ?></div>
      </div>
      <?php if ($g['items'] === []): ?>
        <div class="card-body text-muted">None.</div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($g['items'] as $it): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
              <div>
                <div class="fw-semibold"><?php echo e($it['who']); ?></div>
                <?php if (trim((string) $it['meta']) !== ''): ?>
                  <div class="small text-muted"><?php echo e($it['meta']); ?></div>
                <?php endif; ?>
              </div>
              <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?php echo e($it['href']); ?>">Open</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../footer.php';
