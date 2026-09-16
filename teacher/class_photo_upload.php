<?php
/**
 * teacher/class_photo_upload.php
 *
 * Teacher -> Class Photo Upload (final)
 *
 * Behaviour:
 * - Requires teacher login ($_SESSION['teacher_auth_user']).
 * - Loads classes assigned to the logged-in teacher (uses same approach as teacher/my_classes.php):
 *     1) teacher_classes mapping table (teacher_id -> class_id)
 *     2) classes.teacher_id column (fallback)
 * - Renders:
 *     - Class select (required)
 *     - Quick class buttons (button group). Clicking a button sets the select value and marks button "active".
 * - Handles file upload (JPG/PNG/GIF/WEBP), writes file to uploads/class_photos/, creates thumbnail (GD if available)
 *   and inserts metadata into class_photos table.
 * - Avoids redeclaring helper functions if already present in includes.
 *
 * Save as: /pioneerplayschool01/teacher/class_photo_upload.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('teacher');
$DEBUG = panel_debug();

/* -------------------------
   Require teacher login
   ------------------------- */
$teacher = auth_user() ?? [];
$teacherId = auth_user_id() ?? 0;

/* -------------------------
   Helper wrappers (only define if missing)
   ------------------------- */

/* -------------------------
   Get classes assigned to teacher (consistent with my_classes.php)
   - Prefer teacher_classes mapping table
   - Fallback to classes.teacher_id
   ------------------------- */
function get_teacher_classes_for_upload(int $tid): array {
    $out = [];
    foreach (panel_teacher_assigned_classes($tid) as $r) {
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'label' => trim(
                (($r['short_name'] ?? '') !== '' ? $r['short_name'] . ' ' : '')
                . ($r['name'] ?? '')
                . (!empty($r['section']) ? ' • Sec: ' . $r['section'] : '')
            ),
        ];
    }
    return $out;
}

/* -------------------------
   Upload configuration
   ------------------------- */
$uploadPublicBase = '/uploads/class_photos';
$uploadDir = __DIR__ . '/../uploads/class_photos';
$thumbDir = $uploadDir . '/thumbs';
@mkdir($uploadDir, 0755, true);
@mkdir($thumbDir, 0755, true);

$maxFileBytes = 8 * 1024 * 1024; // 8MB
$allowedMime = ['image/jpeg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];

/* -------------------------
   Load teacher classes
   ------------------------- */
$classEntries = get_teacher_classes_for_upload($teacherId); // array of ['id','label']
$classOptions = [];
foreach ($classEntries as $c) $classOptions[(int)$c['id']] = $c['label'];

/* -------------------------
   Selected class (persist across POST/GET)
   ------------------------- */
$selectedClass = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedClass = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
} elseif (isset($_GET['class_id'])) {
    $selectedClass = (int)$_GET['class_id'];
}

if (!function_exists('validate_csrf')) {
    function validate_csrf(string $t): bool { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$t); }
}

/* -------------------------
   Handle upload POST
   ------------------------- */
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!validate_csrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $class_id = $selectedClass;
        $student_id = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : null;
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if (empty($class_id)) {
            $errors[] = 'Please select a class (use the buttons or the dropdown).';
        } elseif (!empty($classOptions) && !array_key_exists((int)$class_id, $classOptions)) {
            $errors[] = 'Selected class is not assigned to you.';
        }

        if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
            $errors[] = 'No file uploaded.';
        }

        if (empty($errors)) {
            $file = $_FILES['photo'];
            if (!isset($file['error']) || is_array($file['error'])) {
                $errors[] = 'Invalid upload parameters.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload error code: ' . $file['error'];
            } elseif ($file['size'] <= 0) {
                $errors[] = 'Empty file.';
            } elseif ($file['size'] > $maxFileBytes) {
                $errors[] = 'File too large. Max ' . round($maxFileBytes / (1024*1024), 1) . ' MB.';
            } else {
                // mime detection
                $mime = @mime_content_type($file['tmp_name']) ?: '';
                if (!isset($allowedMime[$mime])) {
                    $g = @getimagesize($file['tmp_name']);
                    $mime2 = is_array($g) && !empty($g['mime']) ? $g['mime'] : '';
                    if (isset($allowedMime[$mime2])) $mime = $mime2;
                }
                if (!isset($allowedMime[$mime])) {
                    $errors[] = 'Unsupported file type. Allowed: JPG, PNG, GIF, WEBP.';
                } else {
                    $ext = $allowedMime[$mime];
                    $base = pathinfo($file['name'], PATHINFO_FILENAME);
                    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);
                    $filename = $base . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $dest = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                    $publicPath = rtrim($uploadPublicBase, '/') . '/' . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors[] = 'Failed to move uploaded file.';
                    } else {
                        @chmod($dest, 0644);

                        // create thumbnail (best-effort)
                        $thumbCreated = false;
                        $thumbPath = $thumbDir . '/thumb_' . $filename;
                        if (function_exists('getimagesize') && function_exists('imagecreatefromstring')) {
                            try {
                                $info = @getimagesize($dest);
                                if ($info && isset($info[0], $info[1])) {
                                    $srcW = (int)$info[0]; $srcH = (int)$info[1];
                                    $tW = 400; $tH = (int)round($tW * $srcH / max(1, $srcW));
                                    $srcImg = null;
                                    $ml = strtolower($mime);
                                    if (($ml === 'image/jpeg' || $ml === 'image/pjpeg') && function_exists('imagecreatefromjpeg')) $srcImg = @imagecreatefromjpeg($dest);
                                    elseif ($ml === 'image/png' && function_exists('imagecreatefrompng')) $srcImg = @imagecreatefrompng($dest);
                                    elseif ($ml === 'image/gif' && function_exists('imagecreatefromgif')) $srcImg = @imagecreatefromgif($dest);
                                    elseif ($ml === 'image/webp' && function_exists('imagecreatefromwebp')) $srcImg = @imagecreatefromwebp($dest);

                                    if ($srcImg !== null) {
                                        $thumb = @imagecreatetruecolor($tW, $tH);
                                        if ($thumb !== false) {
                                            if ($ml === 'image/png') {
                                                imagealphablending($thumb, false);
                                                imagesavealpha($thumb, true);
                                                $tr = imagecolorallocatealpha($thumb, 255,255,255,127);
                                                imagefilledrectangle($thumb, 0, 0, $tW, $tH, $tr);
                                            }
                                            imagecopyresampled($thumb, $srcImg, 0,0,0,0, $tW,$tH, $srcW,$srcH);
                                            if ($ml === 'image/png' && function_exists('imagepng')) imagepng($thumb, $thumbPath, 6);
                                            elseif ($ml === 'image/gif' && function_exists('imagegif')) imagegif($thumb, $thumbPath);
                                            elseif ($ml === 'image/webp' && function_exists('imagewebp')) imagewebp($thumb, $thumbPath);
                                            elseif (function_exists('imagejpeg')) imagejpeg($thumb, $thumbPath, 82);
                                            @chmod($thumbPath, 0644);
                                            $thumbCreated = file_exists($thumbPath);
                                            unset($thumb, $srcImg);
                                            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                                        }
                                    }
                                }
                            } catch (Throwable $ex) { error_log('thumb creation error: '.$ex->getMessage()); }
                        }

                        // Insert record
                        $school_id = !empty($_SESSION['teacher_school_id']) ? (int)$_SESSION['teacher_school_id'] : null;
                        $ok = safe_db_run(
                            "INSERT INTO class_photos (school_id, student_id, class_id, title, description, file_path, uploaded_by, uploaded_at, created_at, updated_at)
                             VALUES (:school_id,:student_id,:class_id,:title,:description,:file_path,:uploaded_by,NOW(),NOW(),NOW())",
                            [
                                ':school_id'=>$school_id,
                                ':student_id'=>$student_id,
                                ':class_id'=>$class_id,
                                ':title'=>$title ?: null,
                                ':description'=>$description ?: null,
                                ':file_path'=>$publicPath,
                                ':uploaded_by'=>$teacherId,
                            ]
                        );
                        if ($ok) $messages[] = 'Photo uploaded successfully.';
                        else $errors[] = 'Upload saved but DB insert failed.';
                    }
                }
            }
        }
    }
}

/* ---------------------------
   Fetch recent uploads for display
   --------------------------- */
$recent = [];
if (table_exists('class_photos')) {
    $recent = safe_db_get_all("SELECT id, class_id, title, file_path, uploaded_at FROM class_photos WHERE uploaded_by = :tid ORDER BY uploaded_at DESC LIMIT 20", [':tid'=>$teacherId]);
}

/* ---------------------------
   Render HTML
   --------------------------- */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Teacher - Upload Class Photo</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .thumb{width:100%;height:120px;object-fit:cover;border-radius:6px}
    .btn-group .btn.active{background:#0d6efd;color:#fff;border-color:#0d6efd}
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Upload Class Photo</h3>
    <div>
      <a class="btn btn-outline-secondary" href="/teacher/dashboard.php">Dashboard</a>
      <a class="btn btn-outline-primary" href="/parent/gallery.php" target="_blank">Open Gallery</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; ?>

  <div class="card mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" id="uploadForm" class="row g-2">
        <input type="hidden" name="csrf" value="<?php echo e(get_csrf_token()); ?>">
        <input type="hidden" name="action" value="upload">

        <div class="col-12 mb-2">
          <label class="form-label">Class (select or quick buttons)</label>
          <select name="class_id" id="classSelect" class="form-select" required>
            <option value="">-- Select class --</option>
            <?php foreach ($classOptions as $cid => $label): ?>
              <option value="<?php echo (int)$cid; ?>" <?php if ($selectedClass !== null && $selectedClass === (int)$cid) echo 'selected'; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!empty($classOptions)): ?>
          <div class="col-12 mb-3">
            <div class="btn-group" id="classButtonGroup" role="group" aria-label="Quick class filters">
              <button type="button" class="btn btn-outline-secondary <?php if ($selectedClass === null) echo 'active'; ?>" data-class="">Clear</button>
              <?php foreach ($classOptions as $cid => $label): ?>
                <button type="button" class="btn btn-outline-secondary <?php if ($selectedClass !== null && $selectedClass === (int)$cid) echo 'active'; ?>" data-class="<?php echo (int)$cid; ?>"><?php echo e($label); ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="col-12 mb-3"><div class="small-muted">No classes assigned. Check <a href="/teacher/my_classes.php">My Classes</a>.</div></div>
        <?php endif; ?>

        <div class="col-md-4">
          <label class="form-label">Student ID (optional)</label>
          <input name="student_id" class="form-control" placeholder="Student id (optional)">
        </div>

        <div class="col-md-4">
          <label class="form-label">Title (optional)</label>
          <input name="title" class="form-control" placeholder="Photo title">
        </div>

        <div class="col-md-4">
          <label class="form-label">Photo (JPG/PNG/GIF/WEBP, max 8MB)</label>
          <input type="file" name="photo" class="form-control" accept="image/*" required>
        </div>

        <div class="col-12">
          <label class="form-label">Description (optional)</label>
          <textarea name="description" class="form-control" rows="2"></textarea>
        </div>

        <div class="col-12 text-end">
          <button class="btn btn-primary">Upload Photo</button>
        </div>
      </form>
    </div>
  </div>

  <h6>Recent uploads by you</h6>
  <?php if (empty($recent)): ?>
    <div class="small-muted mb-3">No recent uploads.</div>
  <?php else: ?>
    <div class="row g-2">
      <?php foreach ($recent as $r): ?>
        <div class="col-md-3">
          <div class="card">
            <div style="height:120px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;overflow:hidden">
              <?php if (!empty($r['file_path'])): ?><img src="<?php echo e($r['file_path']); ?>" class="thumb" alt=""><?php else: ?><div class="small-muted">No image</div><?php endif; ?>
            </div>
            <div class="card-body p-2">
              <div class="small-muted" style="font-size:.9rem"><?php echo e($r['title'] ?? ''); ?></div>
              <div class="small-muted" style="font-size:.8rem"><?php echo e(substr((string)($r['uploaded_at'] ?? ''),0,16)); ?></div>
              <div class="mt-2 text-end"><?php if (!empty($r['file_path'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo e($r['file_path']); ?>" target="_blank">Open</a><?php endif; ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var group = document.getElementById('classButtonGroup');
  var select = document.getElementById('classSelect');
  if (group && select) {
    group.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-class]');
      if (!btn) return;
      var cid = btn.getAttribute('data-class') ?? '';
      // update active state
      group.querySelectorAll('button').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      // set select value ('' clears)
      select.value = cid;
      // focus for required validation
      select.focus();
    });
    // sync when select changed manually
    select.addEventListener('change', function(){
      var val = select.value;
      group.querySelectorAll('button').forEach(function(b){
        if ((b.getAttribute('data-class') ?? '') === val) b.classList.add('active'); else b.classList.remove('active');
      });
    });
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>