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
        header('Location: ?class_id=' . (int) $row['class_id'] . '&deleted=1');
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
        $className = 'Class';
        foreach ($assignedClasses as $c) {
            if ((int) $c['id'] === $selectedClassId) {
                $className = (string) ($c['name'] ?? 'Class');
                break;
            }
        }
        $autoTitle = $caption !== '' ? $caption : ($className . ' · ' . date('d M'));
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
                 VALUES (:school_id, NULL, :class_id, :title, :description, :file_path, :uploaded_by, NOW(), NOW(), NOW())',
                [
                    ':school_id' => $schoolId > 0 ? $schoolId : null,
                    ':class_id' => $selectedClassId,
                    ':title' => $autoTitle,
                    ':description' => $caption !== '' ? $caption : null,
                    ':file_path' => $publicPath,
                    ':uploaded_by' => $teacherId,
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
            header('Location: ?class_id=' . $selectedClassId . '&saved=' . $saved);
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

$todayPhotos = [];
$olderPhotos = [];
if ($tableOk && $selectedClassId > 0) {
    $todayPhotos = safe_db_get_all(
        'SELECT id, title, file_path, uploaded_at, uploaded_by
         FROM class_photos
         WHERE class_id = :c AND DATE(uploaded_at) = :d
         ORDER BY uploaded_at DESC',
        [':c' => $selectedClassId, ':d' => date('Y-m-d')]
    ) ?: [];
    $olderPhotos = safe_db_get_all(
        'SELECT id, title, file_path, uploaded_at, uploaded_by
         FROM class_photos
         WHERE class_id = :c AND DATE(uploaded_at) < :d
         ORDER BY uploaded_at DESC
         LIMIT 24',
        [':c' => $selectedClassId, ':d' => date('Y-m-d')]
    ) ?: [];
}

$page_title = 'Class photos';
$pageTitle = $page_title;
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ph-chip { display:inline-flex; border:1px solid #dbe7fb; background:#fff; border-radius:999px; padding:.35rem .85rem; text-decoration:none; color:#1e3a5f; font-weight:600; font-size:.9rem; margin:0 .4rem .5rem 0; }
.ph-chip.active { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
.ph-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:14px 16px; margin-bottom:12px; }
.ph-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
.ph-item { position:relative; border-radius:12px; overflow:hidden; background:#e2e8f0; }
.ph-item img { width:100%; height:140px; object-fit:cover; display:block; }
.ph-item .ph-del { position:absolute; top:6px; right:6px; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success py-2"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?php echo e($er); ?></div><?php endforeach; ?>

<?php if ($assignedClasses === []): ?>
  <div class="alert alert-info mb-0">No class is assigned yet. Ask the owner to assign you a class.</div>
<?php elseif (!$tableOk): ?>
  <div class="alert alert-warning mb-0">Class photos are not set up yet.</div>
<?php else: ?>

  <p class="text-muted mb-2">Today’s class photos. Parents see them in <strong>Gallery</strong> — circle time, play, snack, outing.</p>

  <div class="mb-3">
    <?php foreach ($assignedClasses as $c):
        $cid = (int) $c['id'];
        ?>
      <a class="ph-chip<?php echo $cid === $selectedClassId ? ' active' : ''; ?>" href="?class_id=<?php echo $cid; ?>"><?php echo e((string) ($c['name'] ?? 'Class')); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" enctype="multipart/form-data" class="ph-card">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
    <div class="fw-bold mb-2">Add photos for today</div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input class="form-control" type="file" name="photos[]" accept="image/*" capture="environment" multiple required style="max-width:320px">
      <input class="form-control" name="caption" maxlength="120" placeholder="Optional caption, e.g. Outdoor play" style="max-width:260px">
      <button class="btn btn-success" type="submit">Upload</button>
    </div>
    <div class="small text-muted">Phone camera or gallery. JPG/PNG, up to 8 MB each.</div>
  </form>

  <div class="ph-card">
    <div class="fw-bold mb-2">Today · <?php echo count($todayPhotos); ?> photo<?php echo count($todayPhotos) === 1 ? '' : 's'; ?></div>
    <?php if ($todayPhotos === []): ?>
      <div class="text-muted">No photos yet today for this class.</div>
    <?php else: ?>
      <div class="ph-grid">
        <?php foreach ($todayPhotos as $p):
            $pid = (int) $p['id'];
            $src = $photoUrl((string) ($p['file_path'] ?? ''));
            $canDel = (int) ($p['uploaded_by'] ?? 0) === $teacherId || (function_exists('auth_is_owner_super') && auth_is_owner_super());
            ?>
          <div class="ph-item">
            <?php if ($src !== ''): ?><img src="<?php echo e($src); ?>" alt=""><?php endif; ?>
            <?php if ($canDel): ?>
              <div class="ph-del"><?php echo render_secure_delete_button($pid, '×', 'Remove this photo?', 'btn btn-sm btn-light', ['class_id' => $selectedClassId]); ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($olderPhotos !== []): ?>
    <div class="ph-card">
      <div class="fw-bold mb-2">Earlier</div>
      <div class="ph-grid">
        <?php foreach ($olderPhotos as $p):
            $pid = (int) $p['id'];
            $src = $photoUrl((string) ($p['file_path'] ?? ''));
            $canDel = (int) ($p['uploaded_by'] ?? 0) === $teacherId || (function_exists('auth_is_owner_super') && auth_is_owner_super());
            ?>
          <div class="ph-item">
            <?php if ($src !== ''): ?><img src="<?php echo e($src); ?>" alt=""><?php endif; ?>
            <?php if ($canDel): ?>
              <div class="ph-del"><?php echo render_secure_delete_button($pid, '×', 'Remove this photo?', 'btn btn-sm btn-light', ['class_id' => $selectedClassId]); ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
