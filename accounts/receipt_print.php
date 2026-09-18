<?php
/**
 * accounts/receipt_print.php
 *
 * Printable receipt page for a fees_records entry.
 * - Accepts ?id=NN or ?receipt_no=RC...
 * - Robust: checks available tables/columns, avoids SQL errors and undefined key warnings.
 * - Shows school details (name, logo_path, address, contact_phone, contact_email, social_links, contact_whatsapp) if present in schools table.
 * - Shows student pending amount (if students.total_fees exists) and suggests today's amount (prefills with pending if >0).
 * - format_student_name() removes duplicate name tokens (case-insensitive).
 *
 * Save at: /demopreschoolapp/accounts/receipt_print.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts', ['skip_auth' => true]);
auth_require_roles(['accounts', 'reception', 'staff', 'owner']);

$DEBUG = defined('DEV_SHOW_ERRORS') && (bool) constant('DEV_SHOW_ERRORS');

/* Safe escape helper */
if (!function_exists('e')) {
    function e($v = ''): string {
        if (is_null($v)) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* Name formatter to avoid duplicate middle token */
if (!function_exists('format_student_name')) {
    function format_student_name(array $st): string {
        $first = preg_replace('/\s+/', ' ', trim((string)($st['first_name'] ?? '')));
        $middle = preg_replace('/\s+/', ' ', trim((string)($st['middle_name'] ?? '')));
        $last = preg_replace('/\s+/', ' ', trim((string)($st['last_name'] ?? '')));

        $lastTokens = $last === '' ? [] : preg_split('/\s+/', $last, -1, PREG_SPLIT_NO_EMPTY);
        $seen = [];
        $parts = [];

        $push = function($token) use (&$parts, &$seen) {
            $t = trim((string)$token);
            if ($t === '') return;
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seen[$k])) return;
            $seen[$k] = true;
            $parts[] = $t;
        };

        if ($first !== '') $push($first);

        $firstLower = mb_strtolower($first, 'UTF-8');
        $middleLower = mb_strtolower($middle, 'UTF-8');
        $lastLowerTokens = array_map(function($x){ return mb_strtolower($x, 'UTF-8'); }, $lastTokens);

        if ($middle !== '' && $middleLower !== $firstLower && !in_array($middleLower, $lastLowerTokens, true)) {
            $push($middle);
        }

        foreach ($lastTokens as $t) {
            $tTrim = trim($t);
            if ($tTrim === '') continue;
            $lower = mb_strtolower($tTrim, 'UTF-8');
            if ($lower === $firstLower) continue;
            if ($middle !== '' && $lower === $middleLower) continue;
            $push($tTrim);
        }

        return trim(implode(' ', $parts));
    }
}

/* DB helpers (fallback) */
if (!function_exists('pdo_connect')) {
    function pdo_connect(): ?\PDO {
        foreach (['pdo','db','dbh','DB'] as $g) {
            if (!empty($GLOBALS[$g]) && $GLOBALS[$g] instanceof \PDO) { $GLOBALS['pdo'] = $GLOBALS[$g]; return $GLOBALS[$g]; }
        }
        if (function_exists('db_connect')) {
            try { $tmp = call_user_func('db_connect'); if ($tmp instanceof \PDO) { $GLOBALS['pdo']=$tmp; return $tmp; } } catch (Throwable $e) {}
        }
        $candidates = [];
        if (defined('DB_DSN')) $candidates[] = constant('DB_DSN');
        if (defined('DB_HOST') && defined('DB_NAME')) {
            $host = constant('DB_HOST'); $port = defined('DB_PORT') ? constant('DB_PORT') : 3306; $name = constant('DB_NAME');
            $candidates[] = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }
        if (defined('DB_NAME') && empty($candidates)) {
            $name = constant('DB_NAME');
            $candidates[] = "mysql:host=127.0.0.1;port=3306;dbname={$name};charset=utf8mb4";
        }
        foreach ($candidates as $dsn) {
            if (empty($dsn)) continue;
            try {
                $user = defined('DB_USER') ? constant('DB_USER') : null;
                $pass = defined('DB_PASS') ? constant('DB_PASS') : null;
                $pdo = new \PDO($dsn, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $GLOBALS['pdo'] = $pdo;
                return $pdo;
            } catch (\PDOException $e) {
                error_log('pdo_connect failed: ' . $e->getMessage());
            }
        }
        return null;
    }
}
if (!function_exists('safe_db_get_one')) {
    function safe_db_get_one(string $sql, array $params = []) {
        if (function_exists('db_fetch_one')) {
            try { return db_fetch_one($sql, $params); } catch (Throwable $e) {}
        }
        $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return null;
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(\PDO::FETCH_ASSOC); return $row === false ? null : $row; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) echo '<pre>DB error: '.e($e->getMessage()).'</pre>'; error_log('safe_db_get_one: '.$e->getMessage()); return null; }
    }
}
if (!function_exists('safe_db_get_all')) {
    function safe_db_get_all(string $sql, array $params = []): array {
        if (function_exists('db_fetch_all')) {
            try { return db_fetch_all($sql, $params) ?: []; } catch (Throwable $e) {}
        }
        $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return [];
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { if ($GLOBALS['DEBUG'] ?? false) echo '<pre>DB error: '.e($e->getMessage()).'</pre>'; error_log('safe_db_get_all: '.$e->getMessage()); return []; }
    }
}

if (!function_exists('table_exists')) {
    function table_exists(string $table): bool {
        $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return false;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
            $stmt->execute([':t'=>$table]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            return !empty($r) && intval($r['cnt']) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('table_columns')) {
    function table_columns(string $table): array {
        if (function_exists('get_table_columns')) {
            return get_table_columns($table);
        }
        $pdo = pdo_connect(); if (!($pdo instanceof \PDO)) return [];
        try {
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $stmt->execute([':t'=>$table]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

/* Read params */
$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$receipt_no_param = isset($_GET['receipt_no']) ? trim((string)$_GET['receipt_no']) : '';
$wantPrint = isset($_GET['print']) && (string)$_GET['print'] !== '0';
$wantPdf = isset($_GET['pdf']) && (string)$_GET['pdf'] !== '0';

/* Build SELECT: include student/class/school; for schools include requested fields only if they exist */
$studentCols = table_columns('students');
$schoolCols = table_columns('schools');
$joinUsers = table_exists('users');

$select = ["fr.*"];
$select[] = in_array('first_name', $studentCols, true) ? "COALESCE(s.first_name,'') AS student_first" : "'' AS student_first";
$select[] = in_array('middle_name', $studentCols, true) ? "COALESCE(s.middle_name,'') AS student_middle" : "'' AS student_middle";
$select[] = in_array('last_name', $studentCols, true) ? "COALESCE(s.last_name,'') AS student_last" : "'' AS student_last";
$select[] = in_array('admission_no', $studentCols, true) ? "COALESCE(s.admission_no,'') AS admission_no" : "'' AS admission_no";
$select[] = in_array('academic_year', $studentCols, true) ? "COALESCE(s.academic_year,'') AS academic_year" : "'' AS academic_year";

$select[] = "COALESCE(c.name,'') AS class_name";

$school_selects = [];
// requested school fields
$wantSchoolFields = ['name','logo_path','address','contact_phone','contact_email','social_links','contact_whatsapp'];
foreach ($wantSchoolFields as $col) {
    if (in_array($col, $schoolCols, true)) {
        $alias = 'school_' . $col;
        $school_selects[] = "COALESCE(sc.`$col`,'') AS `$alias`";
    } else {
        $school_selects[] = "'' AS school_$col";
    }
}
$select = array_merge($select, $school_selects);

if ($joinUsers) {
    $select[] = "COALESCE(u.name,'') AS collector_name";
    $select[] = "u.id AS collector_id";
}

$sqlBase = "FROM fees_records fr
LEFT JOIN students s ON s.id = fr.student_id
LEFT JOIN classes c ON c.id = fr.class_id
LEFT JOIN schools sc ON sc.id = fr.school_id";
if ($joinUsers) $sqlBase .= " LEFT JOIN users u ON u.id = fr.collected_by";

/* Fetch record */
$row = null;
if ($id > 0) {
    $sql = "SELECT " . implode(", ", $select) . " " . $sqlBase . " WHERE fr.id = :id LIMIT 1";
    $row = safe_db_get_one($sql, [':id'=>$id]);
}
if (!$row && $receipt_no_param !== '') {
    $sql = "SELECT " . implode(", ", $select) . " " . $sqlBase . " WHERE fr.receipt_no = :r LIMIT 1";
    $row = safe_db_get_one($sql, [':r'=>$receipt_no_param]);
}

/* Not found -> diagnostics */
if (!$row) {
    $recent = safe_db_get_all("SELECT id, receipt_no, student_id, paid_amount, collected_at, created_at FROM fees_records ORDER BY created_at DESC LIMIT 25");
    ?>
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Receipt not found</title><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
    <div class="container py-4">
      <div class="alert alert-warning">
        <?php
          if ($id > 0) echo 'Receipt not found for id=' . e((string)$id) . '.';
          elseif ($receipt_no_param !== '') echo 'Receipt not found for receipt_no=' . e($receipt_no_param) . '.';
          else echo 'Receipt not found for given parameters.';
        ?>
      </div>
      <div class="card mb-3"><div class="card-body"><h5>Quick checks</h5><ul>
        <li>Ensure you're using the same database as the app (local vs production).</li>
        <li>Run in DB client: <code><?php echo $id ? 'SELECT * FROM fees_records WHERE id = ' . (int)$id . ';' : 'SELECT * FROM fees_records WHERE id = [id];'; ?></code></li>
        <li>Check temp log: <code><?php echo e(sys_get_temp_dir() . '/fees_collection_error.log'); ?></code></li>
      </ul></div></div>

      <div class="card mb-3"><div class="card-body"><h5>Recent receipts</h5>
      <?php if (!empty($recent)): ?>
        <div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>ID</th><th>Receipt No</th><th>Student ID</th><th>Paid</th><th>Collected At</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo e($r['receipt_no']); ?></td>
            <td><?php echo (int)$r['student_id']; ?></td>
            <td>₹ <?php echo number_format((float)$r['paid_amount'],2); ?></td>
            <td><?php echo e($r['collected_at'] ?? $r['created_at']); ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="?id=<?php echo (int)$r['id']; ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
        <div class="text-muted">No receipts found.</div>
      <?php endif; ?>
      </div></div>

      <?php if ($DEBUG): ?>
        <div class="card"><div class="card-body"><h5>Debug</h5><pre><?php echo "Requested id: " . var_export($id, true) . "\nRequested receipt_no: " . var_export($receipt_no_param, true) . "\nStudent cols: " . implode(',', $studentCols) . "\nSchool cols: " . implode(',', $schoolCols) . "\nJoin users: " . ($joinUsers ? 'yes' : 'no') . "\n\n"; ?></pre></div></div>
      <?php endif; ?>

    </div>
    </body></html>
    <?php
    exit;
}

/* Parse receipt_no into base + meta */
$receipt_raw = (string)($row['receipt_no'] ?? '');
$receipt_base = $receipt_raw;
$receipt_meta = [];
if (strpos($receipt_raw, '||') !== false) {
    $parts = explode('||', $receipt_raw);
    $receipt_base = array_shift($parts);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strpos($p, ':') !== false) {
            list($k,$v) = explode(':', $p, 2);
            $receipt_meta[trim($k)] = trim($v);
        } else {
            $receipt_meta[] = $p;
        }
    }
}

/* Student and amounts */
$student_data = [
    'first_name' => $row['student_first'] ?? '',
    'middle_name' => $row['student_middle'] ?? '',
    'last_name' => $row['student_last'] ?? ''
];
$student_name = format_student_name($student_data) ?: ('Student ID ' . (int)($row['student_id'] ?? 0));
$amount = (float)($row['amount'] ?? 0.0);
$paid_amount = (float)($row['paid_amount'] ?? 0.0);
$due = max(0.0, $amount - $paid_amount);

/* Compute pending using students.total_fees if available */
$pending = null;
$studentsCols = $studentCols;
if (!empty($row['student_id']) && in_array('total_fees', $studentsCols, true)) {
    $sid = (int)$row['student_id'];
    $srow = safe_db_get_one("SELECT COALESCE(total_fees,0) AS total_fees FROM students WHERE id = :id LIMIT 1", [':id'=>$sid]);
    if ($srow) {
        $total_fees = (float)$srow['total_fees'];
        $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id = :id", [':id'=>$sid]);
        $paid_sum = $r ? (float)$r['paid_sum'] : 0.0;
        $pending = max(0.0, $total_fees - $paid_sum);
    }
}

/* Suggested amount for "today" - follow user's ask: prefill with pending if available else due */
$suggested_amount = $pending !== null ? $pending : $due;

/* school info extracted from row keys school_name, school_logo_path, school_address... */
$school = [
    'name' => $row['school_name'] ?? '',
    'logo_path' => $row['school_logo_path'] ?? '',
    'address' => $row['school_address'] ?? '',
    'contact_phone' => $row['school_contact_phone'] ?? '',
    'contact_email' => $row['school_contact_email'] ?? '',
    'social_links' => $row['school_social_links'] ?? '',
    'contact_whatsapp' => $row['school_contact_whatsapp'] ?? ''
];

/* number->words small helper */
if (!function_exists('_num_to_words_small')) {
    function _num_to_words_small($n){
        $ones = ['', 'one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
        $tens = ['', '', 'twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
        $n = (int)$n;
        if ($n < 20) return $ones[$n];
        if ($n < 100) { $t = intdiv($n,10); $o = $n%10; return $tens[$t] . ($o ? ' ' . $ones[$o] : ''); }
        return (string)$n;
    }
}
$paid_rupees = (int)floor($paid_amount);
$paid_paise = (int)round(($paid_amount - $paid_rupees) * 100);
$in_words = ucfirst(_num_to_words_small($paid_rupees)) . ' rupees' . ($paid_paise ? ' and ' . _num_to_words_small($paid_paise) . ' paise' : ' only');

$collected_at = $row['collected_at'] ?? $row['created_at'] ?? null;

/* Collector display */
$collector_display = '—';
if (!empty($row['collector_name'])) $collector_display = $row['collector_name'];
elseif (!empty($row['collected_by'])) $collector_display = is_numeric($row['collected_by']) ? 'User ID: ' . (int)$row['collected_by'] : (string)$row['collected_by'];

if ($wantPdf) {
    require_once __DIR__ . '/../includes/receipt_pdf.php';
    $dateLabel = $collected_at ? date('d-M-Y H:i', strtotime((string) $collected_at)) : date('d-M-Y H:i');
    receipt_pdf_output(
        ($receipt_base !== '' ? $receipt_base : 'receipt') . '.pdf',
        (string) ($school['name'] !== '' ? $school['name'] : 'Fee receipt'),
        [
            'Address' => (string) ($school['address'] ?? ''),
            'Receipt' => (string) $receipt_base,
            'Date' => $dateLabel,
            'Student' => $student_name,
            'Class' => (string) ($row['class_name'] ?? ''),
            'Year' => (string) ($row['academic_year'] ?? ''),
            'Paid' => 'Rs ' . number_format($paid_amount, 2),
            'Pending' => 'Rs ' . number_format($pending !== null ? $pending : $due, 2),
            'Paid by' => (string) ($receipt_meta['METHOD'] ?? ''),
            'Note' => (string) ($receipt_meta['NOTE'] ?? ''),
            'Collected by' => (string) $collector_display,
            '' => 'System generated receipt',
        ]
    );
}

/* Helper to render logo: if local file exists use as relative path, else if URL use as is */
if (!function_exists('render_logo_tag')) {
function render_logo_tag(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    // If it's an absolute URL
    if (preg_match('#^https?://#i', $path)) {
        return '<img src="' . e($path) . '" alt="logo" style="max-height:80px;">';
    }
    // try relative to document root
    $docRootPaths = [
        $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/'),
        __DIR__ . '/../' . ltrim($path, '/'),
        __DIR__ . '/' . ltrim($path, '/')
    ];
    foreach ($docRootPaths as $p) {
        if ($p && file_exists($p)) {
            // produce a relative URL if possible
            $urlPath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $p);
            $urlPath = $urlPath ?: $path;
            return '<img src="' . e($urlPath) . '" alt="logo" style="max-height:80px;">';
        }
    }
    // fallback to path as-is (may work if accessible)
    return '<img src="' . e($path) . '" alt="logo" style="max-height:80px;">';
}
}

/* Render HTML */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Receipt <?php echo e($receipt_base ?: ''); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .receipt{max-width:840px;margin:20px auto;padding:20px;border:1px solid #ddd;background:#fff}
    .logo { max-height:80px; }
    .hr { border-top:1px dashed #ccc; margin:12px 0; }
    .small-muted { color:#6c757d; font-size:0.9rem; }
    .table-borderless td, .table-borderless th { border:0; padding:.25rem .5rem; vertical-align:top; }
    @media print { .no-print { display:none !important; } .receipt { border:none; box-shadow:none; } }
  </style>
</head>
<body class="bg-light">
  <div class="receipt">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div>
        <?php if (!empty($school['name'])): ?>
          <h4 class="mb-0"><?php echo e($school['name']); ?></h4>
        <?php else: ?>
          <h4 class="mb-0">School</h4>
        <?php endif; ?>
        <?php if (!empty($school['address'])): ?><div class="small-muted"><?php echo e($school['address']); ?></div><?php endif; ?>
        <?php if (!empty($school['contact_phone']) || !empty($school['contact_whatsapp']) || !empty($school['contact_email'])): ?>
          <div class="small-muted">
            <?php if (!empty($school['contact_phone'])): ?>Tel: <?php echo e($school['contact_phone']); ?> <?php endif; ?>
            <?php if (!empty($school['contact_whatsapp'])): ?> | WhatsApp: <?php echo e($school['contact_whatsapp']); ?> <?php endif; ?>
            <?php if (!empty($school['contact_email'])): ?> | Email: <?php echo e($school['contact_email']); ?> <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="text-end">
        <?php if (!empty($school['logo_path'])): echo render_logo_tag($school['logo_path']); endif; ?>
      </div>
    </div>

    <div class="hr"></div>

    <div class="d-flex justify-content-between mb-2">
      <div>
        <div><strong>Student:</strong> <?php echo e($student_name); ?> <?php if(!empty($row['admission_no'])): ?><span class="small-muted"> (Adm: <?php echo e($row['admission_no']); ?>)</span><?php endif; ?></div>
        <div class="small-muted">Class: <?php echo e($row['class_name'] ?? '—'); ?></div>
        <?php if (!empty($row['academic_year'])): ?><div class="small-muted">Academic Year: <?php echo e($row['academic_year']); ?></div><?php endif; ?>
      </div>
      <div class="text-end">
        <div><strong>Receipt No:</strong> <?php echo e($receipt_base); ?></div>
        <?php if (!empty($receipt_meta['METHOD'])): ?><div class="small-muted">Method: <?php echo e($receipt_meta['METHOD']); ?></div><?php endif; ?>
        <?php if (!empty($receipt_meta['NOTE'])): ?><div class="small-muted">Note: <?php echo e($receipt_meta['NOTE']); ?></div><?php endif; ?>
        <div class="small-muted">Date: <?php echo e($collected_at ? date('d-M-Y H:i', strtotime($collected_at)) : '—'); ?></div>
      </div>
    </div>

    <div class="hr"></div>

    <table class="table table-sm mb-0">
      <thead>
        <tr><th style="width:60%">Description</th><th class="text-end">Amount (₹)</th></tr>
      </thead>
      <tbody>
        <tr><td>Fee amount</td><td class="text-end">₹ <?php echo number_format($amount,2); ?></td></tr>
        <tr><td>Paid now</td><td class="text-end">₹ <?php echo number_format($paid_amount,2); ?></td></tr>
        <tr><td>Remaining / Pending</td><td class="text-end">₹ <?php echo number_format($due,2); ?></td></tr>
        <?php if ($pending !== null): ?>
          <tr><td>Student Pending (from total fees)</td><td class="text-end">₹ <?php echo number_format($pending,2); ?></td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><th>Total Paid</th><th class="text-end">₹ <?php echo number_format($paid_amount,2); ?></th></tr>
      </tfoot>
    </table>

    <div class="mt-2 small-muted">Amount in words: <strong><?php echo e($in_words); ?></strong></div>

    <div class="hr"></div>

    <div class="row mb-2">
      <div class="col-6">
        <div><strong>Suggested amount for today:</strong></div>
        <div style="font-size:1.25rem;font-weight:600">₹ <?php echo number_format($suggested_amount,2); ?></div>
        <div class="small text-muted">This is prefilled from pending / remaining amount.</div>
      </div>
      <div class="col-6 text-end">
        <div><strong>Collected by:</strong></div>
        <div><?php echo e($collector_display); ?></div>
      </div>
    </div>

    <div class="hr"></div>

    <div class="mt-3 small-muted">Receipt generated at <?php echo e(date('d-M-Y H:i')); ?>. This is a system generated receipt.</div>

    <div class="mt-3 no-print d-flex flex-wrap gap-2">
      <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
      <a class="btn btn-success" href="?id=<?php echo (int) $id; ?>&amp;pdf=1">PDF</a>
      <a class="btn btn-outline-secondary" href="fees_collection.php">Back to Collect Fees</a>
    </div>
  </div>
  <?php if ($wantPrint): ?>
  <script>window.addEventListener('load', function () { window.print(); });</script>
  <?php endif; ?>
</body>
</html>