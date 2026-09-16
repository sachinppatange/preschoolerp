<?php
/**
 * accounts/fees_collectionbulk.php
 *
 * Bulk upload page for fees/receipts (imports multiple rows into fees_records).
 *
 * Features:
 *  - Upload CSV with columns: student_id, school_id, class_id, amount, payment_date, payment_type, note
 *    (header row optional; case-insensitive column names supported)
 *  - Preview parsed rows and validation errors
 *  - Confirm import: inserts rows inside a DB transaction; generates receipt_no for each row
 *  - Saves debug errors to sys_get_temp_dir()/fees_collection_error.log
 *  - Uses same DB helpers / safe patterns as other accounts pages in this project
 *
 * Usage:
 *  - Place at /demopreschoolapp/accounts/fees_collectionbulk.php
 *
 * Notes:
 *  - collected_by is set to the logged-in accounts user id when available
 *  - If your CSV is large, ensure PHP upload_max_filesize and post_max_size allow it
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts', ['skip_auth' => true]);
require_accounts_or_reception_auth();
$DEBUG = panel_debug();

/* Optional project includes (guarded) */
require_accounts_or_reception_auth();
$accountsUserId = auth_user_id();

/* Debug flag */
/* escape helper */

/* table exists helper */
function table_exists(string $name): bool {
    try { $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]); return !empty($r) && intval($r['cnt'])>0; } catch (Throwable $e) { return false; }
}

/* Ensure fees_records table exists */
if (!table_exists('fees_records')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required table <strong>fees_records</strong> missing. Check DB.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* small helpers */
function format_student_name(array $st): string {
    $first = preg_replace('/\s+/', ' ', trim((string)($st['first_name'] ?? '')));
    $middle = preg_replace('/\s+/', ' ', trim((string)($st['middle_name'] ?? '')));
    $last = preg_replace('/\s+/', ' ', trim((string)($st['last_name'] ?? '')));
    $last_tokens = $last === '' ? [] : preg_split('/\s+/', $last, -1, PREG_SPLIT_NO_EMPTY);
    $seen = []; $parts = [];
    $push = function($t) use (&$parts,&$seen) {
        $t = trim((string)$t); if ($t === '') return;
        $k = mb_strtolower($t, 'UTF-8'); if (isset($seen[$k])) return; $seen[$k]=true; $parts[]=$t;
    };
    if ($first !== '') $push($first);
    $firstLower = mb_strtolower($first,'UTF-8'); $middleLower = mb_strtolower($middle,'UTF-8');
    $lastLowerTokens = array_map(function($x){ return mb_strtolower($x,'UTF-8'); }, $last_tokens);
    if ($middle !== '' && $middleLower !== $firstLower && !in_array($middleLower, $lastLowerTokens, true)) $push($middle);
    foreach ($last_tokens as $t) { $t=trim($t); if ($t==='') continue; $low=mb_strtolower($t,'UTF-8'); if ($low===$firstLower) continue; if ($middle!=='' && $low===$middleLower) continue; $push($t); }
    return trim(implode(' ', $parts));
}

/* temp directory (prefer project tmp) */
$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
    @chmod($tmpDir, 0777);
}
if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
    $tmpDir = sys_get_temp_dir();
}

/* Available payment types (same as fees_collection) */
$paymentTypes = ['Cash','Online','Cheque','Bank','Bad Debts/kasar'];

/* Page actions: template, preview, import */
$action = $_REQUEST['action'] ?? 'form';

/* TEMPLATE: download sample CSV */
if ($action === 'template') {
    $filename = 'fees_bulk_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');
    // header row
    fputcsv($out, ['student_id','school_id','class_id','amount','payment_date','payment_type','note']);
    // sample rows
    fputcsv($out, ['123','1','2','8500','2026-03-22','Cash','March fees collected by Adhira']);
    fputcsv($out, ['124','','2','5000','2026-03-22','Online','Partial payment']);
    fclose($out);
    exit;
}

/* Handle CSV upload -> preview */
$previewRows = $_SESSION['bulk_rows'] ?? null;
$previewErrors = $_SESSION['bulk_errors'] ?? null;
$previewFile = $_SESSION['bulk_file'] ?? null;
$previewCount = is_array($previewRows) ? count($previewRows) : 0;

if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['bulk_errors'] = ['Failed to upload file.']; header('Location: ?'); exit;
    }

    $tmpName = $_FILES['csv_file']['tmp_name'];
    $origName = $_FILES['csv_file']['name'] ?? 'upload.csv';
    // move to project tmp to persist between requests
    $saved = tempnam($tmpDir, 'fees_bulk_');
    if ($saved === false) {
        $_SESSION['bulk_errors'] = ['Failed to create temporary file.']; header('Location: ?'); exit;
    }
    // ensure extension for readability
    $savedWithExt = $saved . '.csv';
    @rename($saved, $savedWithExt);
    $saved = $savedWithExt;
    if (!@move_uploaded_file($tmpName, $saved)) {
        // fallback: copy
        if (!@copy($tmpName, $saved)) {
            @unlink($saved);
            $_SESSION['bulk_errors'] = ['Failed to move uploaded file to temporary location.']; header('Location: ?'); exit;
        }
    }

    // parse options
    $hasHeader = !empty($_POST['has_header']);
    $delimiter = isset($_POST['delimiter']) && $_POST['delimiter'] !== '' ? $_POST['delimiter'] : ',';
    $maxPreview = 200;

    $handle = fopen($saved, 'r');
    if (!$handle) {
        @unlink($saved);
        $_SESSION['bulk_errors'] = ['Failed to open uploaded file.']; header('Location: ?'); exit;
    }

    // read first line as header if requested, else assume fixed order
    $headers = [];
    $rows = []; $errors = [];
    $rowNum = 0;
    if ($hasHeader) {
        $hdr = fgetcsv($handle, 0, $delimiter);
        $rowNum++;
        if ($hdr === false) {
            fclose($handle); @unlink($saved);
            $_SESSION['bulk_errors'] = ['CSV header row not found or file empty.']; header('Location: ?'); exit;
        }
        // normalize headers
        foreach ($hdr as $h) {
            $headers[] = strtolower(trim((string)$h));
        }
    } else {
        // default headers (expected order)
        $headers = ['student_id','school_id','class_id','amount','payment_date','payment_type','note'];
    }

    // accepted header mapping: allow common variants
    $map = [
        'student_id'=>'student_id','student'=>'student_id','sid'=>'student_id',
        'school_id'=>'school_id','school'=>'school_id',
        'class_id'=>'class_id','class'=>'class_id',
        'amount'=>'amount','paid_amount'=>'amount','fee_amount'=>'amount',
        'payment_date'=>'payment_date','date'=>'payment_date',
        'payment_type'=>'payment_type','method'=>'payment_type',
        'note'=>'note','remarks'=>'note','payment_note'=>'note',
        'receipt_no'=>'receipt_no'
    ];

    // normalize headers into canonical keys
    $cols = [];
    foreach ($headers as $h) {
        $k = $map[$h] ?? null;
        if ($k === null) {
            // unknown header — keep original as-is (will map by position)
            $cols[] = $h;
        } else {
            $cols[] = $k;
        }
    }

    // parse lines
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (count($data) === 1 && trim($data[0]) === '') continue; // skip empty lines
        $rec = [
            'row' => $rowNum,
            'raw' => $data,
            'student_id' => null,
            'school_id' => null,
            'class_id' => null,
            'amount' => null,
            'payment_date' => null,
            'payment_type' => null,
            'note' => null,
            'receipt_no' => null,
            'errors' => []
        ];

        // map by headers/position
        foreach ($data as $i => $v) {
            $colKey = $cols[$i] ?? null;
            $v = trim((string)$v);
            if ($colKey === null) continue;
            switch ($colKey) {
                case 'student_id': $rec['student_id'] = $v !== '' ? (int)$v : null; break;
                case 'school_id': $rec['school_id'] = $v !== '' ? (int)$v : null; break;
                case 'class_id': $rec['class_id'] = $v !== '' ? (int)$v : null; break;
                case 'amount': $rec['amount'] = $v !== '' ? (float) str_replace(',', '', $v) : null; break;
                case 'payment_date': $rec['payment_date'] = $v !== '' ? $v : null; break;
                case 'payment_type': $rec['payment_type'] = $v !== '' ? $v : null; break;
                case 'note': $rec['note'] = $v !== '' ? $v : null; break;
                case 'receipt_no': $rec['receipt_no'] = $v !== '' ? $v : null; break;
                default:
                    // unknown header -> ignore
                    break;
            }
        }

        // validation
        if (empty($rec['student_id']) || $rec['student_id'] <= 0) $rec['errors'][] = 'Missing or invalid student_id';
        if (empty($rec['amount']) || $rec['amount'] <= 0) $rec['errors'][] = 'Missing or invalid amount';
        if (empty($rec['payment_type'])) $rec['errors'][] = 'Missing payment_type';
        if (empty($rec['payment_date'])) { $rec['payment_date'] = date('Y-m-d'); } // default today
        if (!\DateTime::createFromFormat('Y-m-d', $rec['payment_date'])) $rec['errors'][] = 'Invalid payment_date, expected YYYY-MM-DD';

        // optional: check student exists
        if (!empty($rec['student_id'])) {
            $s = safe_db_get_one("SELECT id FROM students WHERE id = :id LIMIT 1", [':id'=>$rec['student_id']]);
            if (!$s) $rec['errors'][] = 'student_id not found';
        }

        $rows[] = $rec;
        if (count($rows) >= $maxPreview) break;
    }

    fclose($handle);

    // store preview in session for confirm step
    $_SESSION['bulk_rows'] = $rows;
    $_SESSION['bulk_file'] = $saved;
    $_SESSION['bulk_errors'] = [];
    header('Location: ?action=preview_display');
    exit;
}

/* Display preview (from session) */
if ($action === 'preview_display') {
    $previewRows = $_SESSION['bulk_rows'] ?? [];
    $previewFile = $_SESSION['bulk_file'] ?? null;
    $previewCount = count($previewRows);
    // fall through to form rendering where $previewRows is used
}

/* IMPORT: confirm and insert */
$importResult = null;
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $_SESSION['bulk_errors'] = ['Invalid CSRF token.']; header('Location: ?'); exit;
    }
    $rows = $_SESSION['bulk_rows'] ?? null;
    $saved = $_SESSION['bulk_file'] ?? null;
    if (!is_array($rows) || empty($rows)) {
        $_SESSION['bulk_errors'] = ['No preview data found. Please upload and preview first.']; header('Location: ?'); exit;
    }

    $skipInvalid = !empty($_POST['skip_invalid']);
    $inserted = []; $failed = [];
    $pdo = pdo_connect();
    if (!($pdo instanceof \PDO)) {
        $_SESSION['bulk_errors'] = ['Database connection unavailable']; header('Location: ?'); exit;
    }

    try {
        $pdo->beginTransaction();
        $insertSql = "INSERT INTO fees_records (school_id, student_id, class_id, amount, due_date, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at) VALUES (:school_id, :student_id, :class_id, :amount, NULL, :paid_amount, :status, :receipt_no, :collected_by, :collected_at, :created_at, :updated_at)";
        $stmt = $pdo->prepare($insertSql);
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            if (!empty($r['errors'])) {
                if ($skipInvalid) { $failed[] = ['row'=>$r['row'],'errors'=>$r['errors']]; continue; }
                throw new RuntimeException('Validation errors found; aborting import. Enable "skip invalid rows" to import others.');
            }
            // prepare receipt_no (preserve payment_type and note in metadata)
            $receipt_no_base = 'RC' . date('YmdHis') . mt_rand(100,999);
            $metaParts = [];
            if (!empty($r['payment_type'])) $metaParts[] = 'METHOD:' . str_replace(['|','||'], ['',''], $r['payment_type']);
            if (!empty($r['note'])) {
                $n = preg_replace('/\s+/', ' ', trim($r['note']));
                $n = substr($n, 0, 120);
                $n = str_replace(['|','||'], ['',''], $n);
                $metaParts[] = 'NOTE:' . $n;
            }
            $receipt_no = $receipt_no_base . (!empty($metaParts) ? '||' . implode('||', $metaParts) : '');

            $params = [
                ':school_id' => $r['school_id'] ?? null,
                ':student_id'=> $r['student_id'],
                ':class_id'  => $r['class_id'] ?? null,
                ':amount'    => $r['amount'],
                ':paid_amount'=> $r['amount'],
                ':status'    => 'paid',
                ':receipt_no'=> $receipt_no,
                ':collected_by'=> $accountsUserId ? (int)$accountsUserId : null,
                ':collected_at'=> ($r['payment_date'] ?? date('Y-m-d')) . ' ' . date('H:i:s'),
                ':created_at' => $now,
                ':updated_at' => $now
            ];

            $ok = $stmt->execute($params);
            if (!$ok) {
                $info = $stmt->errorInfo();
                throw new RuntimeException('Insert failed: ' . ($info[2] ?? json_encode($info)));
            }
            $newId = (int)$pdo->lastInsertId();
            $inserted[] = ['id'=>$newId,'receipt_no'=>$receipt_no,'row'=>$r['row']];
        }
        $pdo->commit();
        // cleanup session & uploaded file
        if (isset($saved) && is_file($saved)) @unlink($saved);
        unset($_SESSION['bulk_rows'], $_SESSION['bulk_file'], $_SESSION['bulk_errors']);
        $importResult = ['inserted'=>$inserted,'failed'=>$failed];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[fees_collectionbulk] import error: ' . $e->getMessage());
        @file_put_contents(sys_get_temp_dir().'/fees_collection_error.log', date('c') . " - " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        $_SESSION['bulk_errors'] = ['Import failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error')];
        header('Location: ?'); exit;
    }
}

/* Header include if exists */
$pageTitle = 'Bulk Fees Upload';
require_once __DIR__ . '/../includes/header.php';
?>

  <?php if (!empty($_SESSION['bulk_errors'])): foreach ($_SESSION['bulk_errors'] as $err): ?>
    <div class="alert alert-danger"><?php echo e($err); ?></div>
  <?php endforeach; unset($_SESSION['bulk_errors']); endif; ?>

  <?php if ($importResult !== null): ?>
    <div class="card mb-3"><div class="card-body">
      <h5 class="card-title">Import result</h5>
      <p>Inserted: <?php echo count($importResult['inserted']); ?> rows</p>
      <?php if (!empty($importResult['inserted'])): ?>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Row</th><th>New ID</th><th>Receipt No</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($importResult['inserted'] as $it): ?>
          <tr>
            <td><?php echo (int)$it['row']; ?></td>
            <td><?php echo (int)$it['id']; ?></td>
            <td class="mono"><?php echo e($it['receipt_no']); ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="../accounts/receipt_print.php?id=<?php echo (int)$it['id']; ?>" target="_blank">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
      <?php if (!empty($importResult['failed'])): ?>
        <div class="mt-2"><strong>Skipped / failed rows:</strong>
          <ul><?php foreach ($importResult['failed'] as $f) echo '<li>Row ' . e((string)$f['row']) . ': ' . e(implode('; ',$f['errors'])) . '</li>'; ?></ul>
        </div>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <!-- Upload form -->
  <div class="card mb-3"><div class="card-body">
    <h5>Upload CSV</h5>
    <form method="post" action="?action=preview" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
      <div class="col-12">
        <label class="form-label">CSV file <span class="small-muted">(columns: student_id, school_id, class_id, amount, payment_date, payment_type, note)</span></label>
        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Delimiter</label>
        <input name="delimiter" class="form-control" value="," placeholder="," />
      </div>
      <div class="col-md-3">
        <label class="form-label">Header row</label>
        <div><input type="checkbox" name="has_header" value="1" checked> File contains header row</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Preview rows</label>
        <div class="small-muted">Up to first 200 rows will be shown</div>
      </div>
      <div class="col-12 text-end">
        <button class="btn btn-primary" type="submit">Upload & Preview</button>
      </div>
    </form>
  </div></div>

  <!-- Preview -->
  <?php if (!empty($previewRows)): ?>
    <div class="card mb-3"><div class="card-body">
      <h5>Preview (<?php echo (int)$previewCount; ?> rows shown)</h5>
      <div class="small-muted mb-2">Validation errors are shown per row. You can either fix your CSV and re-upload, or choose to skip invalid rows during import.</div>
      <div class="table-responsive" style="max-height:420px;overflow:auto">
        <table class="table table-sm table-striped mb-0">
          <thead><tr><th>Row</th><th>Student ID</th><th>School ID</th><th>Class ID</th><th>Amount</th><th>Payment Date</th><th>Payment Type</th><th>Note</th><th>Errors</th></tr></thead>
          <tbody>
            <?php foreach ($previewRows as $r): ?>
              <tr class="<?php echo !empty($r['errors']) ? 'table-danger' : ''; ?>">
                <td><?php echo (int)$r['row']; ?></td>
                <td><?php echo e($r['student_id'] ?? ''); ?></td>
                <td><?php echo e($r['school_id'] ?? ''); ?></td>
                <td><?php echo e($r['class_id'] ?? ''); ?></td>
                <td>₹ <?php echo is_numeric($r['amount']) ? number_format((float)$r['amount'],2) : ''; ?></td>
                <td><?php echo e($r['payment_date'] ?? ''); ?></td>
                <td><?php echo e($r['payment_type'] ?? ''); ?></td>
                <td><?php echo e($r['note'] ?? ''); ?></td>
                <td><?php if (!empty($r['errors'])) echo '<small>'.e(implode('; ',$r['errors'])).'</small>'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="?action=import" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="skip_invalid" id="skip_invalid" value="1" checked>
          <label class="form-check-label" for="skip_invalid">Skip invalid rows and import the rest (checked)</label>
        </div>
        <div class="mt-3 text-end">
          <button class="btn btn-danger" type="button" onclick="window.location.href='?action=clear'">Cancel / Clear</button>
          <button class="btn btn-primary" type="submit">Import</button>
        </div>
      </form>
    </div></div>
  <?php endif; ?>

  <?php if (isset($_GET['action']) && $_GET['action']==='clear'): // clear session preview ?>
    <?php unset($_SESSION['bulk_rows'], $_SESSION['bulk_file']); header('Location: ?'); exit; ?>
  <?php endif; ?>

  <div class="card"><div class="card-body">
    <h6 class="mb-1">Notes</h6>
    <ul class="small-muted mb-0">
      <li>CSV should be UTF-8 encoded. Amount must be numeric (no currency symbols).</li>
      <li>payment_date format: YYYY-MM-DD. If empty, today's date will be used.</li>
      <li>student_id is required and must exist in students table.</li>
      <li>On successful import you will get links to receipts for each inserted row.</li>
    </ul>
  </div></div>

</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
