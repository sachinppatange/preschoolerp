<?php
/**
 * reception/admissionbulkcsv.php
 *
 * Bulk admissions via CSV for Reception (parent provided by parent_id).
 *
 * Changes from previous version:
 *  - CSV must provide parent_id (existing users.id of role='parent').
 *  - Removed parent_phone / parent_name / create_parent columns.
 *  - Preview shows parent name fetched from users table for the given parent_id.
 *  - If parent_id missing or invalid, preview shows error/warning and import will skip or abort depending on "skip invalid" option.
 *  - Invalid class_id / school_id are still saved as NULL with warnings to avoid FK errors.
 *
 * Expected CSV header (case-insensitive):
 *   student_first,student_middle,student_last,dob,gender,class_id,school_id,academic_year,parent_id,total_fees,application_date,meta
 *
 * Place at: /pioneerplayschool01/reception/admissionbulkcsv.php
 * Requires reception login: $_SESSION['reception_auth_user']
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth */
/* Optional includes */
/* Helpers (guard) */

/* Ensure students and users and parents_children exist */
function table_exists(string $name): bool {
    try { $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", [':t'=>$name]); return !empty($r) && intval($r['cnt'])>0; } catch (Throwable $e) { return false; }
}
if (!table_exists('students') || !table_exists('users') || !table_exists('parents_children')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required tables missing (students, users, parents_children). कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* tmp dir and logs */
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); @chmod($tmpDir, 0777); }
if (!is_dir($tmpDir) || !is_writable($tmpDir)) $tmpDir = sys_get_temp_dir();

function write_debug_trace(Throwable $e) {
    $sys = sys_get_temp_dir() . '/admission_bulk_error.log';
    @file_put_contents($sys, date('c').' - '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL.PHP_EOL, FILE_APPEND);
    $proj = __DIR__ . '/../tmp/admission_bulk_error.log';
    @file_put_contents($proj, date('c').' - '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL.PHP_EOL, FILE_APPEND);
}

/* Actions */
$action = $_REQUEST['action'] ?? 'form';

/* Template download */
if ($action === 'template') {
    $fn = 'admission_bulk_template_parentid.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $out = fopen('php://output','w');
    fputcsv($out, ['student_first','student_middle','student_last','dob','gender','class_id','school_id','academic_year','parent_id','total_fees','application_date','meta']);
    fputcsv($out, ['Asha','','Patel','2018-04-10','female','2','1','2025-26','12','15000','2025-06-01','{"notes":"Sibling of ID 12"}']);
    fclose($out); exit;
}

/* Upload preview handling */
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['admission_bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['admission_bulk_errors'] = ['File upload failed.']; header('Location: ?'); exit;
    }

    $tmpName = $_FILES['csv_file']['tmp_name'];
    $saved = tempnam($tmpDir, 'admission_bulk_');
    if ($saved === false) { $_SESSION['admission_bulk_errors'] = ['Failed to create temp file.']; header('Location: ?'); exit; }
    $savedWithExt = $saved . '.csv';
    if (!@rename($saved, $savedWithExt)) $savedWithExt = $saved;
    $saved = $savedWithExt;
    if (!@move_uploaded_file($tmpName, $saved)) {
        if (!@copy($tmpName, $saved)) { @unlink($saved); $_SESSION['admission_bulk_errors']=['Failed to move uploaded file.']; header('Location: ?'); exit; }
    }

    $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] !== '' ? $_POST['delimiter'] : ',';
    $maxPreview = 1000;

    $handle = fopen($saved,'r');
    if (!$handle) { @unlink($saved); $_SESSION['admission_bulk_errors']=['Failed to open uploaded file.']; header('Location: ?'); exit; }

    // read header
    $hdr = fgetcsv($handle, 0, $delimiter);
    if ($hdr === false) { fclose($handle); @unlink($saved); $_SESSION['admission_bulk_errors']=['CSV header missing or file empty.']; header('Location: ?'); exit; }
    $headers = array_map(function($x){ return strtolower(trim((string)$x)); }, $hdr);

    // map canonical names
    $map = [
        'student_first'=>'student_first','student_middle'=>'student_middle','student_last'=>'student_last',
        'first'=>'student_first','last'=>'student_last',
        'dob'=>'dob','date_of_birth'=>'dob',
        'gender'=>'gender',
        'class_id'=>'class_id','school_id'=>'school_id','academic_year'=>'academic_year',
        'parent_id'=>'parent_id',
        'total_fees'=>'total_fees','application_date'=>'application_date','meta'=>'meta'
    ];
    $cols = [];
    foreach ($headers as $h) $cols[] = $map[$h] ?? $h;

    $rows = []; $rowNum = 1;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (count($data) === 1 && trim($data[0]) === '') continue;
        $rec = [
            'row'=>$rowNum,
            'student_first'=>null,'student_middle'=>null,'student_last'=>null,
            'dob'=>null,'gender'=>'male','class_id'=>null,'school_id'=>null,'academic_year'=>null,
            'parent_id'=>null,
            'total_fees'=>null,'application_date'=>null,'meta'=>null,
            'errors'=>[], 'warnings'=>[], 'parent_name'=>null
        ];
        foreach ($data as $i=>$v) {
            $key = $cols[$i] ?? null;
            $val = trim((string)$v);
            if ($key === null) continue;
            switch ($key) {
                case 'student_first': $rec['student_first']=$val; break;
                case 'student_middle': $rec['student_middle']=$val; break;
                case 'student_last': $rec['student_last']=$val; break;
                case 'dob': $rec['dob']=$val; break;
                case 'gender': $rec['gender']=strtolower($val)?:'male'; break;
                case 'class_id': $rec['class_id']=$val!==''?(int)$val:null; break;
                case 'school_id': $rec['school_id']=$val!==''?(int)$val:null; break;
                case 'academic_year': $rec['academic_year']=$val; break;
                case 'parent_id': $rec['parent_id']=$val!==''?(int)$val:null; break;
                case 'total_fees': $rec['total_fees']=$val!==''?$val:null; break;
                case 'application_date': $rec['application_date']=$val; break;
                case 'meta': $rec['meta']=$val!==''?$val:null; break;
                default: break;
            }
        }
        // validations
        if (empty($rec['student_first'])) $rec['errors'][] = 'Missing student_first';
        if (empty($rec['dob'])) $rec['errors'][] = 'Missing dob';
        elseif (!\DateTime::createFromFormat('Y-m-d', $rec['dob'])) $rec['errors'][] = 'dob must be YYYY-MM-DD';
        if (empty($rec['parent_id'])) $rec['errors'][] = 'Missing parent_id';
        if (!empty($rec['meta'])) {
            $decoded = json_decode($rec['meta'], true);
            if (json_last_error() === JSON_ERROR_NONE) $rec['meta_json'] = $decoded;
            else $rec['meta_note'] = $rec['meta'];
        }

        // try find parent by id and fetch name for preview
        if (!empty($rec['parent_id'])) {
            try {
                $found = safe_db_get_one("SELECT id, name FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id'=>$rec['parent_id']]);
            } catch (Throwable $e) { $found = null; }
            if ($found) {
                $rec['parent_id'] = (int)$found['id']; // normalize
                $rec['parent_name'] = $found['name'] ?? null;
            } else {
                $rec['warnings'][] = 'parent_id ' . e((string)$rec['parent_id']) . ' not found or not a parent; will cause error unless fixed';
            }
        }

        // validate class_id and school_id for preview: add warnings if missing in DB
        if (!empty($rec['class_id'])) {
            try { $c = safe_db_get_one("SELECT id, name FROM classes WHERE id = :id LIMIT 1", [':id'=>$rec['class_id']]); } catch (Throwable $e) { $c = null; }
            if (!$c) $rec['warnings'][] = 'class_id ' . e((string)$rec['class_id']) . ' not found; will be saved as NULL';
        }
        if (!empty($rec['school_id'])) {
            try { $s = safe_db_get_one("SELECT id, name FROM schools WHERE id = :id LIMIT 1", [':id'=>$rec['school_id']]); } catch (Throwable $e) { $s = null; }
            if (!$s) $rec['warnings'][] = 'school_id ' . e((string)$rec['school_id']) . ' not found; will be saved as NULL';
        }

        $rows[] = $rec;
        if (count($rows) >= $maxPreview) break;
    }
    fclose($handle);

    $_SESSION['admission_bulk_preview'] = $rows;
    $_SESSION['admission_bulk_file'] = $saved;
    $_SESSION['admission_bulk_errors'] = [];
    header('Location: ?action=preview_display'); exit;
}

/* Show preview */
if ($action === 'preview_display') {
    $previewRows = $_SESSION['admission_bulk_preview'] ?? [];
    $previewFile = $_SESSION['admission_bulk_file'] ?? null;
}

/* Import */
$importResult = null;
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['admission_bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }
    $rows = $_SESSION['admission_bulk_preview'] ?? null;
    $saved = $_SESSION['admission_bulk_file'] ?? null;
    if (!is_array($rows) || empty($rows)) { $_SESSION['admission_bulk_errors']=['No preview available.']; header('Location: ?'); exit; }

    $skipInvalid = !empty($_POST['skip_invalid']);
    $inserted = []; $skipped = []; $existing = [];
    $pdo = pdo_connect();
    if (!($pdo instanceof \PDO)) { $_SESSION['admission_bulk_errors']=['Database unavailable.']; header('Location: ?'); exit; }

    try {
        $pdo->beginTransaction();

        // discover students columns
        $colsInfo = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'");
        $existingCols = array_column($colsInfo, 'COLUMN_NAME');

        foreach ($rows as $r) {
            if (!empty($r['errors'])) {
                $skipped[] = ['row'=>$r['row'],'errors'=>$r['errors']];
                if ($skipInvalid) continue;
                throw new RuntimeException('Validation errors present; aborting. Enable skip_invalid to proceed.');
            }

            // validate parent exists (ensures no FK or logical error)
            $parentId = $r['parent_id'] ?? 0;
            $parentNameForLog = null;
            if ($parentId > 0) {
                $found = safe_db_get_one("SELECT id, name FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id'=>$parentId]);
                if ($found) $parentNameForLog = $found['name'] ?? null;
                else {
                    $skipped[] = ['row'=>$r['row'],'errors'=>['parent_id ' . e((string)$parentId) . ' not found or not a parent']];
                    if ($skipInvalid) continue;
                    throw new RuntimeException('parent_id ' . $parentId . ' not found for row ' . $r['row']);
                }
            } else {
                $skipped[] = ['row'=>$r['row'],'errors'=>['Missing parent_id']];
                if ($skipInvalid) continue;
                throw new RuntimeException('Missing parent_id for row ' . $r['row']);
            }

            // Validate class_id and school_id against DB to avoid FK errors
            $validClassId = null;
            if (!empty($r['class_id'])) {
                $c = safe_db_get_one("SELECT id FROM classes WHERE id = :id LIMIT 1", [':id'=> (int)$r['class_id']]);
                if ($c && !empty($c['id'])) $validClassId = (int)$c['id'];
                else $validClassId = null; // will save as NULL
            }
            $validSchoolId = null;
            if (!empty($r['school_id'])) {
                $s = safe_db_get_one("SELECT id FROM schools WHERE id = :id LIMIT 1", [':id'=> (int)$r['school_id']]);
                if ($s && !empty($s['id'])) $validSchoolId = (int)$s['id'];
                else $validSchoolId = null;
            }

            // assemble student db data based on existingCols
            $dbData = [];
            if (in_array('first_name', $existingCols)) $dbData['first_name'] = $r['student_first'];
            if (in_array('middle_name', $existingCols)) $dbData['middle_name'] = $r['student_middle'];
            if (in_array('last_name', $existingCols)) $dbData['last_name'] = $r['student_last'];
            if (in_array('dob', $existingCols)) $dbData['dob'] = $r['dob'];
            if (in_array('gender', $existingCols)) $dbData['gender'] = $r['gender'];
            if (in_array('class_id', $existingCols)) $dbData['class_id'] = $validClassId;
            if (in_array('school_id', $existingCols)) $dbData['school_id'] = $validSchoolId;
            if (in_array('parent_id', $existingCols)) $dbData['parent_id'] = $parentId;
            if (in_array('total_fees', $existingCols)) $dbData['total_fees'] = $r['total_fees'] ?? null;
            if (in_array('admission_date', $existingCols)) $dbData['admission_date'] = $r['application_date'] ?? date('Y-m-d');
            if (in_array('academic_year', $existingCols)) $dbData['academic_year'] = $r['academic_year'] ?? null;
            if (in_array('created_at', $existingCols)) $dbData['created_at'] = date('Y-m-d H:i:s');
            if (in_array('updated_at', $existingCols)) $dbData['updated_at'] = date('Y-m-d H:i:s');

            // extended payload
            $extended = [
                'student'=>['first'=>$r['student_first'],'middle'=>$r['student_middle'],'last'=>$r['student_last'],'dob'=>$r['dob'],'gender'=>$r['gender']],
                'class_id'=>$validClassId,
                'school_id'=>$validSchoolId,
                'academic_year'=>$r['academic_year'] ?? null,
                'parent_id'=>$parentId,
                'parent_name'=>$parentNameForLog,
                'total_fees'=>$r['total_fees'] ?? null,
                'meta'=>$r['meta'] ?? null,
                'created_at'=>date('c')
            ];
            if (in_array('extended_json', $existingCols)) {
                $dbData['extended_json'] = json_encode($extended, JSON_UNESCAPED_UNICODE);
            }

            if (empty($dbData)) {
                $skipped[] = ['row'=>$r['row'],'errors'=>['No students columns found to insert']];
                if ($skipInvalid) continue;
                throw new RuntimeException('No students columns to insert');
            }

            $colsSql = implode('`,`', array_keys($dbData));
            $placeholders = implode(',', array_map(function($c){ return ':' . $c; }, array_keys($dbData)));
            $sql = "INSERT INTO `students` (`" . $colsSql . "`) VALUES (" . $placeholders . ")";
            $stmt = $pdo->prepare($sql);

            $bind = [];
            foreach ($dbData as $col=>$val) $bind[':'.$col] = ($val === '' ? null : $val);
            $stmt->execute($bind);
            $studentId = (int)$pdo->lastInsertId();

            if ($studentId <= 0) throw new RuntimeException('Failed to insert student at row ' . $r['row']);

            // parents_children mapping
            $map = $pdo->prepare("SELECT id FROM parents_children WHERE parent_user_id = :p AND child_student_id = :c LIMIT 1");
            $map->execute([':p'=>$parentId,':c'=>$studentId]);
            if (!$map->fetch(\PDO::FETCH_ASSOC)) {
                $ins = $pdo->prepare("INSERT INTO parents_children (parent_user_id, child_student_id, relation, created_at) VALUES (:p,:c,:r,NOW())");
                $ins->execute([':p'=>$parentId,':c'=>$studentId,':r'=>'guardian']);
            }

            // write extended payload to file
            $metaDir = __DIR__ . '/../uploads/students/meta/';
            if (!is_dir($metaDir)) @mkdir($metaDir, 0755, true);
            @file_put_contents($metaDir . 'student_' . $studentId . '.json', json_encode($extended, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $inserted[] = ['row'=>$r['row'],'student_id'=>$studentId,'parent_id'=>$parentId];
        } // foreach rows

        $pdo->commit();

        // cleanup
        if ($saved && is_file($saved)) @unlink($saved);
        unset($_SESSION['admission_bulk_preview'], $_SESSION['admission_bulk_file'], $_SESSION['admission_bulk_errors']);

        $importResult = ['inserted'=>$inserted,'skipped'=>$skipped];
    } catch (Throwable $e) {
        try { if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $_) {}
        write_debug_trace($e);
        $_SESSION['admission_bulk_errors'] = ['Import failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'), 'See log: ' . sys_get_temp_dir() . '/admission_bulk_error.log', 'Project log: ' . __DIR__ . '/../tmp/admission_bulk_error.log'];
        header('Location: ?'); exit;
    }
}

/* Clear preview */
if ($action === 'clear') {
    if (!empty($_SESSION['admission_bulk_file']) && is_file($_SESSION['admission_bulk_file'])) @unlink($_SESSION['admission_bulk_file']);
    unset($_SESSION['admission_bulk_preview'], $_SESSION['admission_bulk_file'], $_SESSION['admission_bulk_errors']);
    header('Location: ?'); exit;
}

/* Header include */
$pageTitle = 'Bulk Admissions (CSV) — parent_id';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php if (!empty($_SESSION['admission_bulk_errors'])): foreach ($_SESSION['admission_bulk_errors'] as $err): ?><div class="alert alert-danger"><?php echo e($err); ?></div><?php endforeach; unset($_SESSION['admission_bulk_errors']); endif; ?>

  <?php if ($importResult !== null): ?>
    <div class="card mb-3"><div class="card-body">
      <h5>Import Result</h5>
      <p>Inserted: <?php echo count($importResult['inserted']); ?> rows</p>
      <?php if (!empty($importResult['inserted'])): ?>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Row</th><th>Student ID</th><th>Parent ID</th></tr></thead><tbody>
          <?php foreach ($importResult['inserted'] as $it): ?>
            <tr><td><?php echo (int)$it['row']; ?></td><td><?php echo (int)$it['student_id']; ?></td><td><?php echo (int)$it['parent_id']; ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>

      <?php if (!empty($importResult['skipped'])): ?>
        <div class="mt-2"><h6>Skipped</h6><ul><?php foreach ($importResult['skipped'] as $s) echo '<li>Row '.e($s['row']).': '.e(implode('; ',$s['errors'])).'</li>'; ?></ul></div>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <!-- Upload form -->
  <div class="card mb-3"><div class="card-body">
    <form method="post" action="?action=preview" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
      <div class="col-md-6"><label class="form-label">CSV file</label><input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label">Delimiter</label><input name="delimiter" class="form-control" value="," placeholder="," /></div>
      <div class="col-12 text-end"><button class="btn btn-primary" type="submit">Upload & Preview</button></div>
    </form>
    <div class="small text-muted mt-2">CSV must include <code>parent_id</code> (existing users.id of role='parent'). Invalid class_id/school_id will be saved as NULL; preview will show warnings.</div>
  </div></div>

  <!-- Preview -->
  <?php if (!empty($previewRows)): ?>
    <div class="card mb-3"><div class="card-body">
      <h5>Preview (<?php echo count($previewRows); ?> rows)</h5>
      <div class="table-responsive" style="max-height:420px;overflow:auto">
        <table class="table table-sm table-striped mb-0">
          <thead><tr><th>Row</th><th>Student</th><th>DOB</th><th>Gender</th><th>Class</th><th>Parent (ID)</th><th>Parent Name</th><th>Warnings/Errors</th></tr></thead>
          <tbody>
            <?php foreach ($previewRows as $r): ?>
              <tr class="<?php echo !empty($r['errors']) ? 'table-danger' : ''; ?>">
                <td><?php echo (int)$r['row']; ?></td>
                <td><?php echo e($r['student_first'].' '.($r['student_middle']?:'').' '.$r['student_last']); ?></td>
                <td><?php echo e($r['dob']); ?></td>
                <td><?php echo e(ucfirst($r['gender'])); ?></td>
                <td><?php echo e($r['class_id'] ?? ''); ?></td>
                <td><?php echo e($r['parent_id'] ?? ''); ?></td>
                <td><?php echo e($r['parent_name'] ?? ''); ?></td>
                <td>
                  <?php
                    $msgs = [];
                    if (!empty($r['warnings'])) $msgs = array_merge($msgs, $r['warnings']);
                    if (!empty($r['errors'])) $msgs = array_merge($msgs, $r['errors']);
                    echo $msgs ? '<small>'.e(implode('; ', $msgs)).'</small>' : '—';
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="?action=import" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="skip_invalid" id="skip_invalid" value="1" checked>
          <label class="form-check-label" for="skip_invalid">Skip invalid rows and import the rest</label>
        </div>
        <div class="text-end">
          <a class="btn btn-outline-secondary" href="?action=clear">Cancel / Clear</a>
          <button class="btn btn-primary" type="submit">Import</button>
        </div>
      </form>
    </div></div>
  <?php endif; ?>

  <div class="card"><div class="card-body">
    <h6>Notes</h6>
    <ul class="small-muted">
      <li>CSV must include <code>parent_id</code> referring to an existing parent user (users.id with role='parent'). Preview will show parent name.</li>
      <li>Invalid class_id or school_id values in CSV will be saved as NULL to avoid foreign-key errors; preview will show warnings.</li>
      <li>Meta column is preview-only and will be saved inside student extended_json file (not into DB columns unless your schema supports extended_json).</li>
      <li>On error full trace is written to sys temp and project tmp: <code><?php echo e(sys_get_temp_dir()); ?>/admission_bulk_error.log</code> and <code><?php echo e(__DIR__.'/../tmp/admission_bulk_error.log'); ?></code></li>
    </ul>
  </div></div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
