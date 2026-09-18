<?php
/**
 * accounts/fees_collection.php
 *
 * Finalised Fees Collection page (debugged)
 *
 * Notes:
 * - Uses exact fees_records columns:
 *     id, school_id, student_id, class_id, amount, due_date, paid_amount,
 *     status, receipt_no, collected_by (INT), collected_at, created_at, updated_at
 * - collected_by is saved as the accounts user id (int) when available.
 * - Payment Type and Payment Note are preserved by appending short metadata to receipt_no:
 *     receipt_no = <RC...> ||METHOD:<type> ||NOTE:<first 120 chars of note>
 *   (This preserves the info without changing DB schema.)
 * - Debug logging writes exceptions to sys_get_temp_dir()/fees_collection_error.log
 *
 * Place at: /demopreschoolapp/accounts/fees_collection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
require_accounts_or_reception_auth();
$DEBUG = panel_debug();
$accountsUserId = auth_user_id();

/* Debug flag */
/* escape helper */

if (!function_exists('table_columns')) {
    function table_columns(string $table): array {
        try {
            $r = safe_db_get_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", [':t'=>$table]);
            return array_map(static function ($row) { return $row['COLUMN_NAME']; }, $r ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('table_exists')) {
    function table_exists(string $name): bool {
        try {
            $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
            return !empty($r) && intval($r['cnt']) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* ensure fees_records exists */
if (!table_exists('fees_records')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">Required table <strong>fees_records</strong> missing. Check DB.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

/* Helper to format student name (avoid duplicate middle token) */
if (!function_exists('format_student_name')) {
function format_student_name(array $st): string {
    // Robust formatter that removes duplicate tokens (case-insensitive)
    $first = preg_replace('/\s+/', ' ', trim((string)($st['first_name'] ?? '')));
    $middle = preg_replace('/\s+/', ' ', trim((string)($st['middle_name'] ?? '')));
    $last = preg_replace('/\s+/', ' ', trim((string)($st['last_name'] ?? '')));

    $last_tokens = $last === '' ? [] : preg_split('/\s+/', $last, -1, PREG_SPLIT_NO_EMPTY);

    $seen = [];
    $parts = [];

    $push = function(string $token) use (&$parts, &$seen) {
        $t = trim($token);
        if ($t === '') return;
        $k = mb_strtolower($t, 'UTF-8');
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $parts[] = $t;
    };

    if ($first !== '') $push($first);

    $firstLower = mb_strtolower($first, 'UTF-8');
    $middleLower = mb_strtolower($middle, 'UTF-8');
    $lastLowerTokens = array_map(function($x){ return mb_strtolower($x, 'UTF-8'); }, $last_tokens);

    if ($middle !== '' && $middleLower !== $firstLower && !in_array($middleLower, $lastLowerTokens, true)) {
        $push($middle);
    }

    foreach ($last_tokens as $t) {
        $trimmed = trim($t);
        if ($trimmed === '') continue;
        $lower = mb_strtolower($trimmed, 'UTF-8');
        if ($lower === $firstLower) continue;
        if ($middle !== '' && $lower === $middleLower) continue;
        $push($trimmed);
    }

    return trim(implode(' ', $parts));
}
}

if (!function_exists('fees_receipt_meta')) {
    function fees_receipt_meta(string $receiptNo): array
    {
        $base = $receiptNo;
        $method = '';
        $note = '';
        if (str_contains($receiptNo, '||')) {
            $parts = explode('||', $receiptNo);
            $base = (string) array_shift($parts);
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if (stripos($p, 'METHOD:') === 0) {
                    $method = trim(substr($p, 7));
                } elseif (stripos($p, 'NOTE:') === 0) {
                    $note = trim(substr($p, 5));
                }
            }
        }
        return ['base' => $base, 'method' => $method, 'note' => $note];
    }
}

if (!function_exists('fees_receipt_build')) {
    function fees_receipt_build(string $base, string $method, string $note): string
    {
        $meta = [];
        $method = str_replace(['|', '||'], '', $method);
        if ($method !== '') {
            $meta[] = 'METHOD:' . $method;
        }
        $note = preg_replace('/\s+/', ' ', trim($note)) ?? '';
        $note = substr(str_replace(['|', '||'], '', $note), 0, 120);
        if ($note !== '') {
            $meta[] = 'NOTE:' . $note;
        }
        return $base . ($meta !== [] ? '||' . implode('||', $meta) : '');
    }
}

if (!function_exists('fees_year_locked')) {
    function fees_year_locked(): bool
    {
        return function_exists('ay_can_edit') && !ay_can_edit();
    }
}

/* preload lists */
$schools = table_exists('schools') ? safe_db_get_all("SELECT id, name FROM schools ORDER BY name ASC") : [];
$classes = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$students = [];
if (table_exists('students')) {
    if (function_exists('ay_students_have_column') && ay_students_have_column()) {
        $students = safe_db_get_all(
            "SELECT id, COALESCE(first_name,'') AS first_name, COALESCE(middle_name,'') AS middle_name, COALESCE(last_name,'') AS last_name, COALESCE(academic_year,'') AS academic_year, COALESCE(school_id,NULL) AS school_id, COALESCE(class_id,NULL) AS class_id
             FROM students WHERE academic_year = :panel_ay AND (status IS NULL OR status IN ('active','pending')) ORDER BY first_name, last_name",
            [':panel_ay' => ay_selected()]
        );
    } else {
        $students = safe_db_get_all("SELECT id, COALESCE(first_name,'') AS first_name, COALESCE(middle_name,'') AS middle_name, COALESCE(last_name,'') AS last_name, COALESCE(academic_year,'') AS academic_year, COALESCE(school_id,NULL) AS school_id, COALESCE(class_id,NULL) AS class_id FROM students ORDER BY first_name, last_name");
    }
}

$forceSid = (int) ($_GET['student_id'] ?? 0);
if ($forceSid <= 0 && !empty($_GET['edit_id'])) {
    $erow = safe_db_get_one('SELECT student_id FROM fees_records WHERE id = :id LIMIT 1', [':id' => (int) $_GET['edit_id']]);
    $forceSid = $erow ? (int) ($erow['student_id'] ?? 0) : 0;
}
if ($forceSid > 0) {
    $have = false;
    foreach ($students as $st) {
        if ((int) $st['id'] === $forceSid) { $have = true; break; }
    }
    if (!$have) {
        $extra = safe_db_get_one(
            "SELECT id, COALESCE(first_name,'') AS first_name, COALESCE(middle_name,'') AS middle_name, COALESCE(last_name,'') AS last_name, COALESCE(academic_year,'') AS academic_year, COALESCE(school_id,NULL) AS school_id, COALESCE(class_id,NULL) AS class_id FROM students WHERE id = :id LIMIT 1",
            [':id' => $forceSid]
        );
        if ($extra) {
            $students[] = $extra;
        }
    }
}

/* default school id if Pioneer Play School exists */
$defaultSchoolId = null;
foreach ($schools as $s) {
    if (strcasecmp(trim($s['name']), 'Pioneer Play School') === 0) { $defaultSchoolId = (int)$s['id']; break; }
}

$paymentTypes = ['Cash', 'UPI', 'Online', 'Cheque', 'Bank'];
$action = $_REQUEST['action'] ?? 'form';

/* AJAX: get_pending */
if ($action === 'get_pending' && !empty($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int) $_GET['student_id'];
    if ($sid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid student id']); exit; }
    $stu = safe_db_get_one("SELECT id, COALESCE(total_fees,0) AS total_fees, COALESCE(academic_year,'') AS academic_year, school_id, class_id FROM students WHERE id = :id LIMIT 1", [':id'=>$sid]);
    $total = $stu ? (float)$stu['total_fees'] : 0.0;
    $academic_year = $stu ? trim($stu['academic_year']) : '';
    $stu_school = $stu ? (int)$stu['school_id'] : null;
    $stu_class = $stu ? (int)$stu['class_id'] : null;
    $r = safe_db_get_one("SELECT COALESCE(SUM(paid_amount),0) AS paid_sum FROM fees_records WHERE student_id = :id", [':id'=>$sid]);
    $paid = $r ? (float)$r['paid_sum'] : 0.0;
    $pending = max(0.0, $total - $paid);
    echo json_encode(['ok'=>true,'student_id'=>$sid,'total_fee'=>round($total,2),'total_paid'=>round($paid,2),'pending'=>round($pending,2),'academic_year'=>$academic_year,'school_id'=>$stu_school,'class_id'=>$stu_class]);
    exit;
}

if ($action === 'list_receipts' && !empty($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int) $_GET['student_id'];
    $rows = $sid > 0 ? (safe_db_get_all(
        "SELECT id, receipt_no, paid_amount, collected_at FROM fees_records WHERE student_id = :id ORDER BY collected_at DESC, id DESC",
        [':id' => $sid]
    ) ?: []) : [];
    $out = [];
    foreach ($rows as $rr) {
        $meta = fees_receipt_meta((string) ($rr['receipt_no'] ?? ''));
        $out[] = [
            'id' => (int) $rr['id'],
            'receipt' => $meta['base'],
            'amount' => round((float) ($rr['paid_amount'] ?? 0), 2),
            'date' => substr((string) ($rr['collected_at'] ?? ''), 0, 16),
            'method' => $meta['method'],
            'note' => $meta['note'],
        ];
    }
    echo json_encode(['ok' => true, 'rows' => $out]);
    exit;
}

if ($action === 'get_receipt' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $row = safe_db_get_one('SELECT * FROM fees_records WHERE id = :id LIMIT 1', [':id' => (int) $_GET['id']]);
    if (!$row) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $meta = fees_receipt_meta((string) ($row['receipt_no'] ?? ''));
    echo json_encode([
        'ok' => true,
        'data' => [
            'id' => (int) $row['id'],
            'student_id' => (int) ($row['student_id'] ?? 0),
            'school_id' => (int) ($row['school_id'] ?? 0),
            'class_id' => (int) ($row['class_id'] ?? 0),
            'amount' => number_format((float) ($row['paid_amount'] ?? 0), 2, '.', ''),
            'payment_date' => substr((string) ($row['collected_at'] ?? ''), 0, 10),
            'payment_type' => $meta['method'] !== '' ? $meta['method'] : 'Cash',
            'note' => $meta['note'],
        ],
    ]);
    exit;
}

/* CREATE: insert into fees_records using exact columns; set collected_by to accountsUserId (int)
   This INSERT is built dynamically so that if you have added the optional columns
   receipt_no_base/payment_method/payment_note they will be saved separately.
*/
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_POST['csrf_token'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$incoming)) {
        $msg = 'Invalid CSRF token.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?'); exit;
    }
    if (function_exists('ay_can_edit') && !ay_can_edit()) {
        $msg = 'This academic year is locked. Fee collection is not allowed.';
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?'); exit;
    }

    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : null;
    $student_id = isset($_POST['student_id']) && $_POST['student_id'] !== '' ? (int)$_POST['student_id'] : null;
    $class_id = isset($_POST['class_id']) && $_POST['class_id'] !== '' ? (int)$_POST['class_id'] : null;
    $payment_date = trim((string)($_POST['payment_date'] ?? ''));
    $payment_type = trim((string)($_POST['payment_type'] ?? ''));
    $amount_raw = trim((string)($_POST['amount'] ?? '0'));
    $amount = (float) str_replace(',', '', $amount_raw);
    $note = trim((string)($_POST['note'] ?? ''));

    $errors = [];
    if (empty($student_id) || $student_id <= 0) $errors[] = 'Please select a student.';
    if ($amount <= 0) $errors[] = 'Enter an amount greater than 0.';
    if ($payment_type === '') $errors[] = 'Please choose how the fee was paid.';
    if ($payment_date === '') $payment_date = date('Y-m-d');
    if (!\DateTime::createFromFormat('Y-m-d', $payment_date)) $errors[] = 'Payment Date must be YYYY-MM-DD';

    if (!empty($errors)) {
        $msg = implode(' ; ', $errors);
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
        $_SESSION['form_error'] = $msg; header('Location: ?student_id=' . urlencode((string)$student_id)); exit;
    }

    // generate receipt and metadata
    $receipt_no_base = 'RC' . date('YmdHis') . mt_rand(100,999);
    $metaParts = [];
    if ($payment_type !== '') $metaParts[] = 'METHOD:' . str_replace(['|','||'],['',''], $payment_type);
    if ($note !== '') {
        $n = preg_replace('/\s+/', ' ', trim($note));
        $n = substr($n, 0, 120); // limit length
        $n = str_replace(['|','||'], ['',''], $n);
        $metaParts[] = 'NOTE:' . $n;
    }
    $receipt_no = $receipt_no_base . (!empty($metaParts) ? '||' . implode('||', $metaParts) : '');

    $collected_by_int = $accountsUserId ? (int)$accountsUserId : null;
    $collected_at_db = $payment_date . ' ' . date('H:i:s');
    $now = date('Y-m-d H:i:s');

    // detect optional columns
    $cols = table_columns('fees_records');
    $has_receipt_base = in_array('receipt_no_base', $cols, true);
    $has_payment_method = in_array('payment_method', $cols, true);
    $has_payment_note = in_array('payment_note', $cols, true);

    // Build insert dynamically
    $columns = ['school_id','student_id','class_id','amount','due_date','paid_amount','status','receipt_no','collected_by','collected_at','created_at','updated_at'];
    $placeholders = [':school_id',':student_id',':class_id',':amount','NULL',':paid_amount',':status',':receipt_no',':collected_by',':collected_at',':created_at',':updated_at'];
    $params = [
        ':school_id'   => $school_id,
        ':student_id'  => $student_id,
        ':class_id'    => $class_id,
        ':amount'      => $amount,
        ':paid_amount' => $amount,
        ':status'      => 'paid',
        ':receipt_no'  => $receipt_no,
        ':collected_by'=> $collected_by_int,
        ':collected_at'=> $collected_at_db,
        ':created_at'  => $now,
        ':updated_at'  => $now
    ];

    if ($has_receipt_base) {
        $columns[] = 'receipt_no_base';
        $placeholders[] = ':receipt_no_base';
        $params[':receipt_no_base'] = $receipt_no_base;
    }
    if ($has_payment_method) {
        $columns[] = 'payment_method';
        $placeholders[] = ':payment_method';
        $params[':payment_method'] = $payment_type !== '' ? $payment_type : null;
    }
    if ($has_payment_note) {
        $columns[] = 'payment_note';
        $placeholders[] = ':payment_note';
        $params[':payment_note'] = $note !== '' ? $n : null;
    }

    $insertSql = "INSERT INTO fees_records (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

    $pdo = pdo_connect();
    try {
        if (!($pdo instanceof \PDO)) throw new RuntimeException('Database connection unavailable');

        $stmt = $pdo->prepare($insertSql);
        $ok = $stmt->execute($params);
        if (!$ok) {
            $info = $stmt->errorInfo();
            throw new RuntimeException('Insert failed: ' . ($info[2] ?? json_encode($info)));
        }
        $newId = (int)$pdo->lastInsertId();

        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'id'=>$newId,'receipt_no'=>$receipt_no]); exit; }

        $_SESSION['form_success'] = 'Payment saved. Receipt: ' . $receipt_no_base;
        header('Location: ?student_id=' . urlencode((string) $student_id));
        exit;
    } catch (Throwable $e) {
        $errMsg = $DEBUG ? $e->getMessage() : 'Failed to save payment.';
        error_log('[fees_collection] Create error: ' . $e->getMessage());
        @file_put_contents(sys_get_temp_dir() . '/fees_collection_error.log', date('c') . " - " . $e->getMessage() . PHP_EOL, FILE_APPEND);
        if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>$errMsg]); exit; }
        $_SESSION['form_error'] = $errMsg;
        header('Location: ?'); exit;
    }
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['form_error'] = 'Invalid CSRF token.';
        header('Location: ?');
        exit;
    }
    if (fees_year_locked()) {
        $_SESSION['form_error'] = 'This academic year is locked.';
        header('Location: ?');
        exit;
    }
    $feesId = (int) ($_POST['fees_id'] ?? 0);
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $amount = (float) str_replace(',', '', trim((string) ($_POST['amount'] ?? '0')));
    $payment_type = trim((string) ($_POST['payment_type'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $payment_date = trim((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d');
    $row = $feesId > 0 ? safe_db_get_one('SELECT * FROM fees_records WHERE id = :id LIMIT 1', [':id' => $feesId]) : null;
    if (!$row) {
        $_SESSION['form_error'] = 'Receipt not found.';
        header('Location: ?student_id=' . $student_id);
        exit;
    }
    if ($amount <= 0 || $student_id <= 0 || $payment_type === '') {
        $_SESSION['form_error'] = 'Student, amount and paid-by are required.';
        header('Location: ?student_id=' . $student_id . '&edit_id=' . $feesId);
        exit;
    }
    $meta = fees_receipt_meta((string) ($row['receipt_no'] ?? ''));
    $base = $meta['base'] !== '' ? $meta['base'] : ('RC' . date('YmdHis') . mt_rand(100, 999));
    $receipt_no = fees_receipt_build($base, $payment_type, $note);
    $collected_at_db = $payment_date . ' ' . date('H:i:s');
    $cols = table_columns('fees_records');
    $sql = "UPDATE fees_records SET student_id = :student_id, amount = :amount, paid_amount = :paid_amount, status = 'paid', receipt_no = :receipt_no, collected_at = :collected_at, updated_at = NOW()";
    $params = [
        ':student_id' => $student_id,
        ':amount' => $amount,
        ':paid_amount' => $amount,
        ':receipt_no' => $receipt_no,
        ':collected_at' => $collected_at_db,
        ':id' => $feesId,
    ];
    if (in_array('payment_method', $cols, true)) {
        $sql .= ', payment_method = :payment_method';
        $params[':payment_method'] = $payment_type;
    }
    if (in_array('payment_note', $cols, true)) {
        $sql .= ', payment_note = :payment_note';
        $params[':payment_note'] = $note !== '' ? $note : null;
    }
    $sql .= ' WHERE id = :id';
    $ok = safe_db_run($sql, $params);
    $_SESSION[$ok ? 'form_success' : 'form_error'] = $ok ? 'Receipt updated. Pending fees are recalculated.' : 'Could not update receipt.';
    header('Location: ?student_id=' . $student_id);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['form_error'] = 'Invalid CSRF token.';
        header('Location: ?');
        exit;
    }
    if (fees_year_locked()) {
        $_SESSION['form_error'] = 'This academic year is locked.';
        header('Location: ?');
        exit;
    }
    $feesId = (int) ($_POST['fees_id'] ?? 0);
    $row = $feesId > 0 ? safe_db_get_one('SELECT student_id FROM fees_records WHERE id = :id LIMIT 1', [':id' => $feesId]) : null;
    $student_id = $row ? (int) $row['student_id'] : (int) ($_POST['student_id'] ?? 0);
    $ok = $feesId > 0 && safe_db_run('DELETE FROM fees_records WHERE id = :id', [':id' => $feesId]);
    $_SESSION[$ok ? 'form_success' : 'form_error'] = $ok
        ? 'Receipt deleted. That amount is pending again.'
        : 'Could not delete receipt.';
    header('Location: ?student_id=' . $student_id);
    exit;
}

/* Flash and recent payments */
$messages = []; $errors_flash = [];
if (!empty($_SESSION['form_error'])) { $errors_flash[] = $_SESSION['form_error']; unset($_SESSION['form_error']); }
if (!empty($_SESSION['form_success'])) { $messages[] = $_SESSION['form_success']; unset($_SESSION['form_success']); }
$saved_id = isset($_GET['saved_id']) ? (int)$_GET['saved_id'] : 0;
$preselect_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$editId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$editPrefill = null;
if ($editId > 0) {
    $er = safe_db_get_one('SELECT * FROM fees_records WHERE id = :id LIMIT 1', [':id' => $editId]);
    if ($er) {
        $meta = fees_receipt_meta((string) ($er['receipt_no'] ?? ''));
        $preselect_student = (int) ($er['student_id'] ?? 0);
        $editPrefill = [
            'id' => $editId,
            'amount' => number_format((float) ($er['paid_amount'] ?? 0), 2, '.', ''),
            'payment_date' => substr((string) ($er['collected_at'] ?? ''), 0, 10),
            'payment_type' => $meta['method'] !== '' ? $meta['method'] : 'Cash',
            'note' => $meta['note'],
        ];
    }
}

$page_title = 'Collect Fees';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.fee-card { background:#fff; border:1px solid #dbe7fb; border-radius:16px; padding:1.1rem 1.2rem; margin-bottom:1rem; }
.fee-steps { color:#64748b; font-size:.9rem; margin-bottom:1rem; }
.fee-pending { background:#f0f7ff; border:1px dashed #b6d0f5; border-radius:14px; padding:1rem; }
.fee-pending .amt { font-size:1.6rem; font-weight:800; color:#0d3b8c; }
.fee-ok { background:#e7f8ee; color:#137a3a; }
</style>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors_flash as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>

<p class="fee-steps mb-3">Select a student → save a receipt, or edit / delete an existing one. Delete puts that amount back in pending.</p>

<div class="fee-card">
  <form id="paymentForm" method="post" action="?action=<?php echo $editPrefill ? 'update' : 'create'; ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
    <input type="hidden" name="fees_id" id="fees_id" value="<?php echo $editPrefill ? (int) $editPrefill['id'] : ''; ?>">
    <input type="hidden" name="school_id" id="school_select" value="<?php echo $defaultSchoolId ? (int) $defaultSchoolId : ''; ?>">
    <input type="hidden" name="class_id" id="class_select" value="">
    <div class="col-12" id="editBanner" <?php echo $editPrefill ? '' : 'hidden'; ?>>
      <div class="alert alert-info py-2 mb-0">Editing a receipt. <a href="?student_id=<?php echo (int) $preselect_student; ?>">Cancel</a></div>
    </div>

    <div class="col-md-7">
      <label class="form-label fw-semibold">Student *</label>
      <input type="search" id="studentSearch" class="form-control mb-2" placeholder="Type to search name" autocomplete="off">
      <select id="student_select" name="student_id" class="form-select" size="8" required>
        <option value="">Select a student</option>
        <?php foreach ($students as $st):
            $label = format_student_name($st);
            $search = strtolower($label . ' ' . (string) ($st['academic_year'] ?? ''));
        ?>
          <option value="<?php echo (int) $st['id']; ?>" data-search="<?php echo e($search); ?>" <?php echo $preselect_student === (int) $st['id'] ? 'selected' : ''; ?>>
            <?php echo e($label !== '' ? $label : ('Student #' . (int) $st['id'])); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-5">
      <div class="fee-pending h-100" id="pendingSummary">
        <div class="small text-muted">Pending fees</div>
        <div class="amt" id="pendingValue">—</div>
        <div class="small text-muted" id="pendingDetails">Select a student to see total, paid and pending.</div>
      </div>
    </div>

    <div class="col-md-4">
      <label class="form-label fw-semibold">Amount *</label>
      <input id="amount_input" name="amount" class="form-control" type="text" inputmode="decimal" placeholder="0.00" required value="<?php echo e((string) ($editPrefill['amount'] ?? '')); ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-semibold">Paid by *</label>
      <select name="payment_type" id="payment_type" class="form-select" required>
        <?php
        $selType = $editPrefill['payment_type'] ?? 'Cash';
        foreach ($paymentTypes as $pt): ?>
          <option value="<?php echo e($pt); ?>" <?php echo $selType === $pt ? 'selected' : ''; ?>><?php echo e($pt); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Date</label>
      <input name="payment_date" id="payment_date" type="date" class="form-control" value="<?php echo e($editPrefill['payment_date'] ?? date('Y-m-d')); ?>" required>
    </div>
    <div class="col-12">
      <label class="form-label">Note</label>
      <input name="note" id="note_input" class="form-control" placeholder="Optional, e.g. term 1 / cheque no." value="<?php echo e((string) ($editPrefill['note'] ?? '')); ?>">
    </div>
    <div class="col-12">
      <button class="btn btn-primary" type="submit" id="saveBtn"><?php echo $editPrefill ? 'Update receipt' : 'Save receipt'; ?></button>
      <a class="btn btn-outline-secondary" id="cancelEdit" href="?student_id=<?php echo (int) $preselect_student; ?>" <?php echo $editPrefill ? '' : 'hidden'; ?>>Cancel edit</a>
    </div>
  </form>
</div>

<div class="fee-card">
  <h2 class="h6 fw-bold mb-3">This student’s receipts</h2>
  <p class="small text-muted" id="receiptHint">Select a student to see receipts. Edit or delete a wrong entry — pending updates automatically.</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Receipt</th><th>Amount</th><th>When</th><th></th></tr></thead>
      <tbody id="receiptRows">
        <tr><td colspan="4" class="text-muted text-center py-3">Select a student.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  var csrf = <?php echo json_encode($CSRF); ?>;
  var editing = <?php echo $editPrefill ? 'true' : 'false'; ?>;
  var studentSelect = document.getElementById('student_select');
  var search = document.getElementById('studentSearch');
  var pendingValue = document.getElementById('pendingValue');
  var pendingDetails = document.getElementById('pendingDetails');
  var pendingBox = document.getElementById('pendingSummary');
  var amountInput = document.getElementById('amount_input');
  var schoolSelect = document.getElementById('school_select');
  var classSelect = document.getElementById('class_select');
  var form = document.getElementById('paymentForm');
  var feesId = document.getElementById('fees_id');
  var saveBtn = document.getElementById('saveBtn');
  var cancelEdit = document.getElementById('cancelEdit');
  var editBanner = document.getElementById('editBanner');
  var receiptRows = document.getElementById('receiptRows');

  function fmt(n) { return Number(n).toFixed(2); }
  function clearPending() {
    pendingValue.textContent = '—';
    pendingDetails.textContent = 'Select a student to see total, paid and pending.';
    pendingBox.classList.remove('fee-ok');
    if (!editing) amountInput.value = '';
  }
  function fetchPending(sid) {
    if (!sid) { clearPending(); renderReceipts([]); return; }
    pendingValue.textContent = '…';
    fetch('?action=get_pending&student_id=' + encodeURIComponent(sid), { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : Promise.reject(); })
      .then(function (json) {
        if (!json || !json.ok) { clearPending(); return; }
        pendingValue.textContent = '₹ ' + fmt(json.pending);
        pendingDetails.textContent = 'Total ₹ ' + fmt(json.total_fee) + ' · Paid ₹ ' + fmt(json.total_paid);
        pendingBox.classList.toggle('fee-ok', Number(json.pending) <= 0.009);
        if (!editing) amountInput.value = fmt(json.pending);
        if (json.school_id && schoolSelect) schoolSelect.value = json.school_id;
        if (json.class_id && classSelect) classSelect.value = json.class_id;
      })
      .catch(function () { pendingDetails.textContent = 'Could not load pending fees.'; });
    fetch('?action=list_receipts&student_id=' + encodeURIComponent(sid), { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : Promise.reject(); })
      .then(function (json) { renderReceipts((json && json.rows) ? json.rows : []); })
      .catch(function () { renderReceipts([]); });
  }
  function renderReceipts(rows) {
    if (!receiptRows) return;
    if (!rows.length) {
      receiptRows.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">No receipts for this student yet.</td></tr>';
      return;
    }
    receiptRows.innerHTML = rows.map(function (r) {
      var id = r.id;
      return '<tr><td><div class="fw-semibold">' + escapeHtml(r.receipt || '') + '</div>' +
        (r.method ? '<div class="small text-muted">' + escapeHtml(r.method) + '</div>' : '') + '</td>' +
        '<td>₹ ' + fmt(r.amount) + '</td>' +
        '<td class="small text-muted">' + escapeHtml(r.date || '') + '</td>' +
        '<td class="text-nowrap">' +
        '<a class="btn btn-sm btn-outline-primary" href="receipt_print.php?id=' + id + '" target="_blank" rel="noopener">View</a> ' +
        '<a class="btn btn-sm btn-success" href="receipt_print.php?id=' + id + '&print=1" target="_blank" rel="noopener">Print</a> ' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-edit="' + id + '">Edit</button> ' +
        '<form method="post" action="?action=delete" class="d-inline" onsubmit="return confirm(\'Delete this receipt? The amount will go back to pending.\');">' +
        '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '">' +
        '<input type="hidden" name="fees_id" value="' + id + '">' +
        '<input type="hidden" name="student_id" value="' + (studentSelect.value || '') + '">' +
        '<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>' +
        '</td></tr>';
    }).join('');
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
  }
  function startEdit(id) {
    fetch('?action=get_receipt&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : Promise.reject(); })
      .then(function (json) {
        if (!json || !json.ok || !json.data) return;
        var d = json.data;
        editing = true;
        feesId.value = d.id;
        form.action = '?action=update';
        studentSelect.value = String(d.student_id);
        amountInput.value = d.amount;
        document.getElementById('payment_date').value = d.payment_date || '';
        document.getElementById('payment_type').value = d.payment_type || 'Cash';
        document.getElementById('note_input').value = d.note || '';
        saveBtn.textContent = 'Update receipt';
        if (cancelEdit) { cancelEdit.hidden = false; cancelEdit.href = '?student_id=' + d.student_id; }
        if (editBanner) editBanner.hidden = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        fetchPending(d.student_id);
      });
  }
  if (receiptRows) {
    receiptRows.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-edit]');
      if (btn) startEdit(btn.getAttribute('data-edit'));
    });
  }
  if (search) {
    search.addEventListener('input', function () {
      var q = (search.value || '').toLowerCase().trim();
      Array.prototype.forEach.call(studentSelect.options, function (opt, i) {
        if (i === 0) { opt.hidden = false; return; }
        var hay = opt.getAttribute('data-search') || opt.textContent.toLowerCase();
        opt.hidden = q !== '' && hay.indexOf(q) === -1;
      });
    });
  }
  if (studentSelect) studentSelect.addEventListener('change', function () {
    if (editing) return;
    fetchPending(this.value);
  });
  <?php if ($preselect_student > 0): ?>fetchPending(<?php echo (int) $preselect_student; ?>);<?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
