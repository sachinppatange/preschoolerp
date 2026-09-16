<?php
/**
 * reception/add_parentsbulk.php
 *
 * Bulk upload page for Parent users (reception area).
 *
 * Behavior:
 *  - Upload CSV with columns (header optional, case-insensitive):
 *        name, phone, whatsapp_id, school_id, is_active, meta, admit
 *    (meta will be parsed for preview but NOT inserted into users table)
 *  - Preview parsed rows and validation
 *  - Confirm import: inserts rows into users table with role='parent'
 *  - Existing parents (matched by phone) are not duplicated; admit flags are preserved in results
 *  - Stores preview in session between preview and import
 *  - Writes full exception traces to sys_get_temp_dir()/parents_bulk_error.log and project tmp
 *
 * Place at: /pioneerplayschool01/reception/add_parentsbulk.php
 *
 * Requires reception login: $_SESSION['reception_auth_user']
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('reception');
$DEBUG = panel_debug();

/* Auth: reception only */
/* Optional includes (guarded) */
/* small helpers */

/* table exists helper */
function table_exists(string $name): bool {
    try { $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]); return !empty($r) && intval($r['cnt'])>0; } catch (Throwable $e) { return false; }
}

/* Ensure users table exists */
if (!table_exists('users')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required table <strong>users</strong> missing. Check DB.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* tmp dir for uploaded files and debug log */
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); @chmod($tmpDir, 0777); }
if (!is_dir($tmpDir) || !is_writable($tmpDir)) $tmpDir = sys_get_temp_dir();

/* page actions */
$action = $_REQUEST['action'] ?? 'form';

/* Template download */
if ($action === 'template') {
    $fn = 'parents_bulk_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name','phone','whatsapp_id','school_id','is_active','meta','admit']);
    fputcsv($out, ['Jane Doe','9876543210','','1','1','{"relation":"mother"}','0']);
    fputcsv($out, ['John Smith','9123456780','johnwa','2','1','Note about parent','1']);
    fclose($out);
    exit;
}

/* Helper: write debug trace */
function write_debug_trace(Throwable $e) {
    $logSys = sys_get_temp_dir() . '/parents_bulk_error.log';
    @file_put_contents($logSys, date('c') . ' - ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL . PHP_EOL, FILE_APPEND);

    $projTmp = __DIR__ . '/../tmp';
    if (!is_dir($projTmp)) { @mkdir($projTmp, 0777, true); @chmod($projTmp, 0777); }
    $logProj = rtrim($projTmp, '/\\') . '/parents_bulk_error.log';
    @file_put_contents($logProj, date('c') . ' - ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL . PHP_EOL, FILE_APPEND);
}

/* Preview upload handling */
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['parents_bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['parents_bulk_errors'] = ['File upload failed.']; header('Location: ?'); exit;
    }

    $tmpName = $_FILES['csv_file']['tmp_name'];
    $saved = tempnam($tmpDir, 'parents_bulk_');
    if ($saved === false) { $_SESSION['parents_bulk_errors'] = ['Failed to create temp file.']; header('Location: ?'); exit; }
    $savedWithExt = $saved . '.csv';
    if (!@rename($saved, $savedWithExt)) $savedWithExt = $saved; // fallback
    $saved = $savedWithExt;
    if (!@move_uploaded_file($tmpName, $saved)) {
        if (!@copy($tmpName, $saved)) { @unlink($saved); $_SESSION['parents_bulk_errors']=['Failed to move uploaded file.']; header('Location: ?'); exit; }
    }

    $hasHeader = !empty($_POST['has_header']);
    $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] !== '' ? $_POST['delimiter'] : ',';
    $maxPreview = 500;

    $handle = fopen($saved, 'r');
    if (!$handle) { @unlink($saved); $_SESSION['parents_bulk_errors']=['Failed to open uploaded file.']; header('Location: ?'); exit; }

    $headers = [];
    if ($hasHeader) {
        $hdr = fgetcsv($handle, 0, $delimiter);
        if ($hdr === false) { fclose($handle); @unlink($saved); $_SESSION['parents_bulk_errors']=['CSV header missing or file empty.']; header('Location: ?'); exit; }
        foreach ($hdr as $h) $headers[] = strtolower(trim((string)$h));
    } else {
        $headers = ['name','phone','whatsapp_id','school_id','is_active','meta','admit'];
    }

    $map = [
        'name'=>'name','full_name'=>'name',
        'phone'=>'phone','mobile'=>'phone',
        'whatsapp'=>'whatsapp_id','whatsapp_id'=>'whatsapp_id',
        'school_id'=>'school_id','school'=>'school_id',
        'is_active'=>'is_active','active'=>'is_active',
        'meta'=>'meta','notes'=>'meta',
        'admit'=>'admit'
    ];
    $cols = [];
    foreach ($headers as $h) { $cols[] = $map[$h] ?? $h; }

    $rows = []; $rowNum = 1;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (count($data) === 1 && trim($data[0]) === '') continue;
        $rec = [
            'row' => $rowNum,
            'name' => null,
            'phone' => null,
            'whatsapp_id' => null,
            'school_id' => null,
            'is_active' => 1,
            'meta' => null,
            'admit' => 0,
            'errors' => []
        ];
        foreach ($data as $i => $v) {
            $key = $cols[$i] ?? null;
            $val = trim((string)$v);
            if ($key === null) continue;
            switch ($key) {
                case 'name': $rec['name'] = $val; break;
                case 'phone': $rec['phone'] = preg_replace('/\D+/', '', $val); break;
                case 'whatsapp_id': $rec['whatsapp_id'] = $val !== '' ? $val : null; break;
                case 'school_id': $rec['school_id'] = $val !== '' ? (int)$val : null; break;
                case 'is_active': $rec['is_active'] = ($val === '' ? 1 : ((int)$val ? 1 : 0)); break;
                case 'meta': $rec['meta'] = $val !== '' ? $val : null; break;
                case 'admit': $rec['admit'] = ($val !== '' && in_array(strtolower($val), ['1','yes','y','true','t'])) ? 1 : 0; break;
                default: /* ignore unknown */ break;
            }
        }
        // validations
        if (empty($rec['name'])) $rec['errors'][] = 'Missing name';
        if (empty($rec['phone'])) $rec['errors'][] = 'Missing phone';
        if (!empty($rec['phone']) && !preg_match('/^\d{6,15}$/', $rec['phone'])) $rec['errors'][] = 'Phone invalid (expect 6-15 digits)';
        if (!empty($rec['meta'])) {
            $decoded = json_decode($rec['meta'], true);
            if (json_last_error() === JSON_ERROR_NONE) $rec['meta_json'] = $decoded;
            else $rec['meta_note'] = $rec['meta'];
        }
        // check if phone already exists -> mark as existing (won't be error)
        if (!empty($rec['phone'])) {
            try {
                $found = safe_db_get_one("SELECT id FROM users WHERE phone = :p LIMIT 1", [':p'=>$rec['phone']]);
            } catch (Throwable $e) {
                $found = null;
            }
            if ($found) $rec['existing_id'] = (int)$found['id'];
        }
        $rows[] = $rec;
        if (count($rows) >= $maxPreview) break;
    }

    fclose($handle);

    $_SESSION['parents_bulk_preview'] = $rows;
    $_SESSION['parents_bulk_file'] = $saved;
    $_SESSION['parents_bulk_errors'] = [];
    header('Location: ?action=preview_display');
    exit;
}

/* Show preview saved in session */
if ($action === 'preview_display') {
    $previewRows = $_SESSION['parents_bulk_preview'] ?? [];
    $previewFile = $_SESSION['parents_bulk_file'] ?? null;
}

/* Import: insert into users (meta is skipped intentionally) */
$importResult = null;
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['parents_bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }
    $rows = $_SESSION['parents_bulk_preview'] ?? null;
    $saved = $_SESSION['parents_bulk_file'] ?? null;
    if (!is_array($rows) || empty($rows)) { $_SESSION['parents_bulk_errors'] = ['No preview available.']; header('Location: ?'); exit; }

    $skipInvalid = !empty($_POST['skip_invalid']);
    $inserted = []; $skipped = []; $existing = [];
    $pdo = pdo_connect();
    if (!($pdo instanceof \PDO)) { $_SESSION['parents_bulk_errors'] = ['Database unavailable.']; header('Location: ?'); exit; }

    try {
        $pdo->beginTransaction();
        // NOTE: meta is intentionally excluded from INSERT to avoid schema constraints
        $insertSql = "INSERT INTO users (school_id,name,phone,role,whatsapp_id,is_active,created_at,updated_at) VALUES (:school_id,:name,:phone,'parent',:whatsapp_id,:is_active,NOW(),NOW())";
        $stmt = $pdo->prepare($insertSql);
        foreach ($rows as $r) {
            if (!empty($r['errors'])) {
                $skipped[] = ['row'=>$r['row'],'errors'=>$r['errors']];
                if ($skipInvalid) continue;
                throw new RuntimeException('Validation errors present; aborting. Enable skip_invalid to proceed.');
            }
            if (!empty($r['existing_id'])) {
                // existing parent recorded; do not insert duplicate
                $existing[] = ['row'=>$r['row'],'id'=>$r['existing_id'],'admit'=>$r['admit']];
                continue;
            }

            $ok = $stmt->execute([
                ':school_id' => $r['school_id'] ?? null,
                ':name'      => $r['name'],
                ':phone'     => $r['phone'],
                ':whatsapp_id' => $r['whatsapp_id'] ?? null,
                ':is_active' => isset($r['is_active']) ? (int)$r['is_active'] : 1
            ]);
            if (!$ok) {
                $info = $stmt->errorInfo();
                throw new RuntimeException('Insert failed: ' . ($info[2] ?? json_encode($info)));
            }
            $newId = (int)$pdo->lastInsertId();
            $inserted[] = ['row'=>$r['row'],'id'=>$newId,'admit'=>$r['admit']];
        }
        $pdo->commit();

        // cleanup preview/session/file
        if (isset($saved) && is_file($saved)) @unlink($saved);
        unset($_SESSION['parents_bulk_preview'], $_SESSION['parents_bulk_file'], $_SESSION['parents_bulk_errors']);

        // Note: meta values were parsed but intentionally NOT inserted
        $importResult = ['inserted'=>$inserted,'existing'=>$existing,'skipped'=>$skipped,'meta_inserted'=>false];
    } catch (Throwable $e) {
        try { $pdo->rollBack(); } catch (Throwable $_) {}
        write_debug_trace($e);
        $_SESSION['parents_bulk_errors'] = ['Import failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'), 'See log: ' . sys_get_temp_dir() . '/parents_bulk_error.log'];
        header('Location: ?'); exit;
    }
}

/* Clear preview action */
if ($action === 'clear') {
    if (!empty($_SESSION['parents_bulk_file']) && is_file($_SESSION['parents_bulk_file'])) @unlink($_SESSION['parents_bulk_file']);
    unset($_SESSION['parents_bulk_preview'], $_SESSION['parents_bulk_file'], $_SESSION['parents_bulk_errors']);
    header('Location: ?'); exit;
}

/* Header include if exists */
$pageTitle = 'Bulk Add Parents';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php if (!empty($_SESSION['parents_bulk_errors'])): foreach ($_SESSION['parents_bulk_errors'] as $err): ?>
    <div class="alert alert-danger"><?php echo e($err); ?></div>
  <?php endforeach; unset($_SESSION['parents_bulk_errors']); endif; ?>

  <?php if ($importResult !== null): ?>
    <div class="card mb-3"><div class="card-body">
      <h5 class="card-title">Import Result</h5>
      <p>Inserted: <?php echo count($importResult['inserted']); ?> rows</p>

      <?php if (!empty($importResult['inserted'])): ?>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Row</th><th>ID</th><th>Admit?</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($importResult['inserted'] as $it): ?>
            <tr>
              <td><?php echo (int)$it['row']; ?></td>
              <td><?php echo (int)$it['id']; ?></td>
              <td><?php echo $it['admit'] ? 'Yes' : 'No'; ?></td>
              <td><?php if ($it['admit']): ?><a class="btn btn-sm btn-primary" href="/reception/admission.php?parent_id=<?php echo (int)$it['id']; ?>" target="_blank">Open Admission</a><?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>

      <?php if (!empty($importResult['existing'])): ?>
        <div class="mt-2">
          <h6>Existing parents (not inserted)</h6>
          <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Row</th><th>ID</th><th>Admit?</th><th>Action</th></tr></thead><tbody>
          <?php foreach ($importResult['existing'] as $ex): ?>
            <tr>
              <td><?php echo (int)$ex['row']; ?></td>
              <td><?php echo (int)$ex['id']; ?></td>
              <td><?php echo $ex['admit'] ? 'Yes' : 'No'; ?></td>
              <td><?php if ($ex['admit']): ?><a class="btn btn-sm btn-primary" href="/reception/admission.php?parent_id=<?php echo (int)$ex['id']; ?>" target="_blank">Open Admission</a><?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($importResult['skipped'])): ?>
        <div class="mt-2">
          <h6>Skipped / Invalid rows</h6>
          <ul><?php foreach ($importResult['skipped'] as $s) echo '<li>Row '.e((string)$s['row']).': '.e(implode('; ',$s['errors'])).'</li>'; ?></ul>
        </div>
      <?php endif; ?>

      <div class="mt-2 small text-muted">
        <strong>Note:</strong> meta values from the CSV were parsed for preview but are intentionally NOT inserted into the users table to avoid schema/constraint issues.
      </div>
    </div></div>
  <?php endif; ?>

  <!-- Upload form -->
  <div class="card mb-3"><div class="card-body">
    <h5>Upload CSV</h5>
    <form method="post" action="?action=preview" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
      <div class="col-md-6">
        <label class="form-label">CSV file</label>
        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Delimiter</label>
        <input name="delimiter" class="form-control" value="," placeholder="," />
      </div>
      <div class="col-md-2">
        <label class="form-label">Has header</label>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="has_header" value="1" checked>
          <label class="form-check-label">Yes</label>
        </div>
      </div>
      <div class="col-12 text-end">
        <button class="btn btn-primary" type="submit">Upload & Preview</button>
      </div>
    </form>
    <div class="small text-muted mt-2">Columns accepted: name, phone, whatsapp_id, school_id, is_active, meta (preview-only), admit (1/yes to open admission)</div>
  </div></div>

  <!-- Preview -->
  <?php if (!empty($previewRows)): ?>
    <div class="card mb-3"><div class="card-body">
      <h5>Preview (<?php echo count($previewRows); ?> rows)</h5>
      <div class="table-responsive" style="max-height:420px;overflow:auto">
        <table class="table table-sm table-striped mb-0">
          <thead><tr><th>Row</th><th>Name</th><th>Phone</th><th>WhatsApp</th><th>School ID</th><th>Active</th><th>Meta (preview)</th><th>Admit</th><th>Errors</th></tr></thead>
          <tbody>
            <?php foreach ($previewRows as $r): ?>
              <tr class="<?php echo !empty($r['errors']) ? 'table-danger' : ''; ?>">
                <td><?php echo (int)$r['row']; ?></td>
                <td><?php echo e($r['name'] ?? ''); ?></td>
                <td><?php echo e($r['phone'] ?? ''); ?></td>
                <td><?php echo e($r['whatsapp_id'] ?? ''); ?></td>
                <td><?php echo e($r['school_id'] ?? ''); ?></td>
                <td><?php echo isset($r['is_active']) ? ((int)$r['is_active'] ? 'Yes' : 'No') : 'Yes'; ?></td>
                <td><?php echo e($r['meta'] ?? ''); ?></td>
                <td><?php echo !empty($r['admit']) ? 'Yes' : 'No'; ?></td>
                <td><?php if (!empty($r['errors'])) echo '<small>'.e(implode('; ',$r['errors'])).'</small>'; elseif (!empty($r['existing_id'])) echo '<small>Exists (ID: '.(int)$r['existing_id'].')</small>'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="?action=import" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="skip_invalid" id="skip_invalid" value="1" checked>
          <label class="form-check-label" for="skip_invalid">Skip invalid rows and import the rest</label>
        </div>
        <div class="mt-3 text-end">
          <a class="btn btn-outline-secondary" href="?action=clear">Cancel / Clear</a>
          <button class="btn btn-primary" type="submit">Import</button>
        </div>
      </form>
    </div></div>
  <?php endif; ?>

  <div class="card"><div class="card-body">
    <h6>Notes</h6>
    <ul class="small-muted">
      <li>Phone must be numeric (6-15 digits). Existing phone will not be duplicated.</li>
      <li>Meta column is parsed for preview only and intentionally NOT inserted to avoid schema/constraint errors.</li>
      <li>If you want to persist meta, add a separate text/json column (e.g., meta_text) and I can provide the ALTER TABLE and script changes.</li>
      <li>On error, full trace is written to: <code><?php echo e(sys_get_temp_dir()); ?>/parents_bulk_error.log</code> and project tmp <code><?php echo e(__DIR__ . '/../tmp/parents_bulk_error.log'); ?></code></li>
    </ul>
  </div></div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
