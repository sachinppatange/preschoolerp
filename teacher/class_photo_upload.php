<?php
/**
 * teacher/class_photo_upload.php — daily class photos for the parent gallery.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

$teacherId = (int) (auth_user_id() ?? 0);
$schoolId = function_exists('auth_school_id') ? (int) auth_school_id() : 1;
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';

$assignedClasses = panel_teacher_assigned_classes($teacherId);
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
usort($assignedClasses, static function (array $a, array $b) use ($classRank): int {
    $d = $classRank($a) <=> $classRank($b);
    return $d !== 0 ? $d : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$allowedIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $assignedClasses)));
$canClass = static function (int $classId) use ($allowedIds): bool {
    if (function_exists('auth_is_owner_super') && auth_is_owner_super()) {
        return true;
    }
    return in_array($classId, $allowedIds, true);
};

if (!table_exists('class_photos')) {
    try {
        if (function_exists('db_execute')) {
            db_execute(
                'CREATE TABLE IF NOT EXISTS class_photos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    school_id INT DEFAULT NULL,
                    student_id INT DEFAULT NULL,
                    class_id INT DEFAULT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    description TEXT DEFAULT NULL,
                    file_path VARCHAR(1024) NOT NULL,
                    uploaded_by INT DEFAULT NULL,
                    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    } catch (Throwable $e) {
        error_log('class_photos create: ' . $e->getMessage());
    }
}
$tableOk = table_exists('class_photos');

$uploadDir = dirname(__DIR__) . '/uploads/class_photos';
$thumbDir = $uploadDir . '/thumbs';
@mkdir($uploadDir, 0755, true);
@mkdir($thumbDir, 0755, true);
$maxBytes = 8 * 1024 * 1024;
$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

$photoUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (function_exists('asset_url')) {
        return asset_url($path);
    }
    if (function_exists('site_url')) {
        return site_url('/' . ltrim($path, '/'));
    }
    return $path;
};

$makeThumb = static function (string $dest, string $filename, string $mime) use ($thumbDir): void {
    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $info = @getimagesize($dest);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return;
    }
    $srcW = (int) $info[0];
    $srcH = (int) $info[1];
    $tW = 480;
    $tH = (int) round($tW * $srcH / max(1, $srcW));
    $ml = strtolower($mime);
    $srcImg = null;
    if (($ml === 'image/jpeg' || $ml === 'image/pjpeg') && function_exists('imagecreatefromjpeg')) {
        $srcImg = @imagecreatefromjpeg($dest);
    } elseif ($ml === 'image/png' && function_exists('imagecreatefrompng')) {
        $srcImg = @imagecreatefrompng($dest);
    } elseif ($ml === 'image/gif' && function_exists('imagecreatefromgif')) {
        $srcImg = @imagecreatefromgif($dest);
    } elseif ($ml === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $srcImg = @imagecreatefromwebp($dest);
    }
    if ($srcImg === null) {
        return;
    }
    $thumb = @imagecreatetruecolor($tW, $tH);
    if ($thumb === false) {
        return;
    }
    if ($ml === 'image/png') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $tW, $tH, $srcW, $srcH);
    $thumbPath = $thumbDir . '/thumb_' . $filename;
    if (function_exists('imagejpeg')) {
        imagejpeg($thumb, $thumbPath, 82);
    }
    imagedestroy($thumb);
    imagedestroy($srcImg);
};

$messages = [];
$errors = [];
$selectedClassId = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if ($selectedClassId <= 0 && $allowedIds !== []) {
    $selectedClassId = $allowedIds[0];
}
if ($selectedClassId > 0 && !$canClass($selectedClassId)) {
    $selectedClassId = 0;
}

$today = date('Y-m-d');
$photoDate = trim((string) ($_POST['photo_date'] ?? $_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $photoDate)) {
    $photoDate = $today;
}
$groups = ['Play', 'Circle time', 'Snack', 'Outing', 'Celebration'];
$selectedGroup = trim((string) ($_POST['group'] ?? $_GET['group'] ?? 'Play'));
if ($selectedGroup === '') {
    $selectedGroup = 'Play';
}

if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get((string) ($_REQUEST['action'] ?? ''))) {
    $errors[] = 'Delete needs confirmation.';
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0 && $tableOk) {
    $row = safe_db_get_one('SELECT id, class_id, file_path, uploaded_by FROM class_photos WHERE id = :id LIMIT 1', [':id' => $deleteId]);
    $ownerOk = $row && ((int) ($row['uploaded_by'] ?? 0) === $teacherId || (function_exists('auth_is_owner_super') && auth_is_owner_super()));
    $classOk = $row && $canClass((int) ($row['class_id'] ?? 0));
    if (!$ownerOk || !$classOk) {
        $errors[] = 'You cannot delete this photo.';
    } elseif (safe_db_run('DELETE FROM class_photos WHERE id = :id', [':id' => $deleteId])) {
        $rel = ltrim((string) ($row['file_path'] ?? ''), '/');
        $full = dirname(__DIR__) . '/' . $rel;
        if (is_file($full)) {
            @unlink($full);
        }
        $base = basename($rel);
        $thumb = $thumbDir . '/thumb_' . $base;
        if (is_file($thumb)) {
            @unlink($thumb);
        }
        $backDate = trim((string) ($_POST['photo_date'] ?? $_GET['date'] ?? $photoDate));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $backDate)) {
            $backDate = $photoDate;
        }
        header('Location: ?class_id=' . (int) $row['class_id'] . '&date=' . rawurlencode($backDate) . '&deleted=1');
        exit;
    } else {
        $errors[] = 'Could not delete.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'upload') {
    if (!function_exists('validate_csrf_token') || !validate_csrf_token((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Please reload the page and try again.';
    } elseif (!$tableOk) {
        $errors[] = 'Photos are not set up yet.';
    } elseif ($selectedClassId <= 0 || !$canClass($selectedClassId)) {
        $errors[] = 'Choose a class.';
    } else {
        $caption = trim((string) ($_POST['caption'] ?? ''));
        $group = trim((string) ($_POST['group'] ?? 'Play'));
        if ($group === '') {
            $group = 'Play';
        }
        if (strlen($group) > 80) {
            $group = substr($group, 0, 80);
        }
        $raw = $_FILES['photos'] ?? null;
        $files = [];
        if (is_array($raw) && isset($raw['name'])) {
            if (is_array($raw['name'])) {
                foreach ($raw['name'] as $i => $n) {
                    $files[] = [
                        'name' => (string) $n,
                        'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
                        'error' => (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'size' => (int) ($raw['size'][$i] ?? 0),
                    ];
                }
            } else {
                $files[] = [
                    'name' => (string) $raw['name'],
                    'tmp_name' => (string) ($raw['tmp_name'] ?? ''),
                    'error' => (int) ($raw['error'] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($raw['size'] ?? 0),
                ];
            }
        }
        $files = array_values(array_filter($files, static fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if ($files === []) {
            $errors[] = 'Choose one or more photos.';
        }
        $saved = 0;
        $when = $photoDate === $today
            ? date('Y-m-d H:i:s')
            : ($photoDate . ' ' . date('H:i:s'));
        foreach ($files as $file) {
            if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                $errors[] = 'Could not read a photo. Try a smaller JPG or PNG.';
                continue;
            }
            if (($file['size'] ?? 0) > $maxBytes) {
                $errors[] = 'A photo is larger than 8 MB.';
                continue;
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            $mime = @mime_content_type($tmp) ?: '';
            if (!isset($allowedMime[$mime])) {
                $g = @getimagesize($tmp);
                $mime = is_array($g) ? (string) ($g['mime'] ?? '') : '';
            }
            if (!isset($allowedMime[$mime])) {
                $errors[] = 'Use JPG, PNG, GIF or WEBP.';
                continue;
            }
            $ext = $allowedMime[$mime];
            $filename = 'class' . $selectedClassId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $dest = $uploadDir . '/' . $filename;
            if (!move_uploaded_file($tmp, $dest)) {
                $errors[] = 'Could not save a photo.';
                continue;
            }
            @chmod($dest, 0644);
            $makeThumb($dest, $filename, $mime);
            $publicPath = '/uploads/class_photos/' . $filename;
            $ok = safe_db_run(
                'INSERT INTO class_photos (school_id, student_id, class_id, title, description, file_path, uploaded_by, uploaded_at, created_at, updated_at)
                 VALUES (:school_id, NULL, :class_id, :title, :description, :file_path, :uploaded_by, :uploaded_at, NOW(), NOW())',
                [
                    ':school_id' => $schoolId > 0 ? $schoolId : null,
                    ':class_id' => $selectedClassId,
                    ':title' => $group,
                    ':description' => $caption !== '' ? $caption : $group,
                    ':file_path' => $publicPath,
                    ':uploaded_by' => $teacherId,
                    ':uploaded_at' => $when,
                ]
            );
            if ($ok) {
                $saved++;
            } else {
                @unlink($dest);
                $errors[] = 'Photo saved on disk but not in the gallery.';
            }
        }
        if ($saved > 0) {
            header('Location: ?class_id=' . $selectedClassId . '&date=' . rawurlencode($photoDate) . '&group=' . rawurlencode($group) . '&saved=' . $saved);
            exit;
        }
        if ($errors === []) {
            $errors[] = 'No photos were uploaded.';
        }
    }
}

if (!empty($_GET['saved'])) {
    $n = (int) $_GET['saved'];
    $messages[] = $n === 1 ? 'Photo added. Parents can see it in Gallery.' : ($n . ' photos added. Parents can see them in Gallery.');
}
if (!empty($_GET['deleted'])) {
    $messages[] = 'Photo removed.';
}

$dayPhotos = [];
$dayGroups = [];
$otherDates = [];
if ($tableOk && $selectedClassId > 0) {
    $dayPhotos = safe_db_get_all(
        'SELECT id, title, description, file_path, uploaded_at, uploaded_by
         FROM class_photos
         WHERE class_id = :c AND DATE(uploaded_at) = :d
         ORDER BY title ASC, uploaded_at DESC',
        [':c' => $selectedClassId, ':d' => $photoDate]
    ) ?: [];
    foreach ($dayPhotos as $p) {
        $g = trim((string) ($p['title'] ?? ''));
        if ($g === '') {
            $g = 'Class photos';
        }
        $dayGroups[$g][] = $p;
    }
    $otherDates = safe_db_get_all(
        'SELECT DATE(uploaded_at) AS d, COUNT(*) AS c
         FROM class_photos
         WHERE class_id = :c
         GROUP BY DATE(uploaded_at)
         ORDER BY d DESC
         LIMIT 21',
        [':c' => $selectedClassId]
    ) ?: [];
}

$dateLabel = $photoDate === $today ? 'Today' : date('d M Y', strtotime($photoDate) ?: time());
$prevDate = date('Y-m-d', strtotime($photoDate . ' -1 day') ?: time());
$nextDate = date('Y-m-d', strtotime($photoDate . ' +1 day') ?: time());
$qs = static function (int $classId, string $date, string $group = '') : string {
    $q = '?class_id=' . $classId . '&date=' . rawurlencode($date);
    if ($group !== '') {
        $q .= '&group=' . rawurlencode($group);
    }
    return $q;
};

$page_title = 'Class photos';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ph-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; cursor:pointer; }
.ph-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.ph-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:12px; }
.ph-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
.ph-item { position:relative; border-radius:12px; overflow:hidden; background:#e2e8f0; }
.ph-item img { width:100%; height:140px; object-fit:cover; display:block; }
.ph-item .ph-del { position:absolute; top:6px; right:6px; }
.ph-datechip { display:inline-block; border:1px solid #e2e8f0; border-radius:10px; padding:.25rem .6rem; font-size:.82rem; text-decoration:none; color:#334155; margin:0 .35rem .35rem 0; background:#f8fafc; }
.ph-datechip.active { border-color:#1d4ed8; color:#1d4ed8; background:#eff6ff; font-weight:700; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <?php echo panel_teacher_empty_classes_html(); ?>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Class photos are not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">Daily photos for parents’ <strong>Gallery</strong>. Pick the date and group (Play, Snack, Outing), then upload.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="ph-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="<?php echo e($qs($cid, $photoDate, $selectedGroup)); ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $prevDate, $selectedGroup)); ?>">←</a>
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
      <input type="hidden" name="group" value="<?php echo e($selectedGroup); ?>">
      <input type="date" name="date" class="form-control form-control-sm" value="<?php echo e($photoDate); ?>" onchange="this.form.submit()" style="max-width:160px">
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo e($qs($selectedClassId, $nextDate, $selectedGroup)); ?>">→</a>
    <?php if ($photoDate !== $today): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo e($qs($selectedClassId, $today, $selectedGroup)); ?>">Today</a>
    <?php endif; ?>
    <span class="text-muted small"><?php echo e($dateLabel); ?></span>
  </div>

  <form method="post" enctype="multipart/form-data" class="ph-card" id="photoUploadForm">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <input type="hidden" name="photo_date" value="<?php echo e($photoDate); ?>">
    <input type="hidden" name="group" id="groupField" value="<?php echo e($selectedGroup); ?>">
    <div class="fw-bold mb-2">Add photos · <?php echo e($dateLabel); ?></div>
    <div class="mb-2">
      <?php foreach ($groups as $g): ?>
        <button type="button" class="ph-chip group-chip<?php echo $selectedGroup === $g ? ' active' : ''; ?>" data-group="<?php echo e($g); ?>"><?php echo e($g); ?></button>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" type="file" name="photos[]" accept="image/*" capture="environment" multiple required style="max-width:320px">
      <input class="form-control" name="caption" maxlength="120" placeholder="Optional note for parents" style="max-width:260px">
      <button class="btn btn-success" type="submit">Upload</button>
    </div>
    <div class="small text-muted">Group first (Play / Snack / Outing), then pick photos. Parents see the same groups in Gallery.</div>
  </form>

  <?php if ($otherDates !== []): ?>
    <div class="mb-3">
      <?php foreach ($otherDates as $od):
          $d = (string) ($od['d'] ?? '');
          if ($d === '') {
              continue;
          }
          $lab = $d === $today ? 'Today' : date('d M', strtotime($d) ?: time());
          ?>
        <a class="ph-datechip<?php echo $d === $photoDate ? ' active' : ''; ?>" href="<?php echo e($qs($selectedClassId, $d, $selectedGroup)); ?>"><?php echo e($lab); ?> · <?php echo (int) ($od['c'] ?? 0); ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="ph-card">
    <div class="fw-bold mb-2"><?php echo e($dateLabel); ?> · <?php echo count($dayPhotos); ?> photo<?php echo count($dayPhotos) === 1 ? '' : 's'; ?></div>
    <?php if ($dayPhotos === []): ?>
      <div class="text-muted">No photos on this date. Pick photos above.</div>
    <?php else: ?>
      <?php foreach ($dayGroups as $gName => $plist): ?>
        <div class="fw-semibold mt-3 mb-2"><?php echo e((string) $gName); ?> <span class="text-muted fw-normal">· <?php echo count($plist); ?></span></div>
        <div class="ph-grid">
          <?php foreach ($plist as $p):
              $pid = (int) $p['id'];
              $src = $photoUrl((string) ($p['file_path'] ?? ''));
              $canDel = (int) ($p['uploaded_by'] ?? 0) === $teacherId || (function_exists('auth_is_owner_super') && auth_is_owner_super());
              ?>
            <div class="ph-item">
              <?php if ($src !== ''): ?><img src="<?php echo e($src); ?>" alt=""><?php endif; ?>
              <?php if ($canDel): ?>
                <div class="ph-del"><?php echo render_secure_delete_button($pid, '×', 'Remove this photo?', 'btn btn-sm btn-light', ['class_id' => $selectedClassId, 'photo_date' => $photoDate]); ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<script>
document.querySelectorAll('.group-chip').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var g = btn.getAttribute('data-group') || 'Play';
    var field = document.getElementById('groupField');
    if (field) field.value = g;
    document.querySelectorAll('.group-chip').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
  });
});
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
