<?php
/**
 * Collect fees for a whole class in one go (same date and payment method).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
require_accounts_or_reception_auth();

$page_title = 'Collect by class';
$pageTitle = $page_title;
$accountsUserId = auth_user_id();

$selfUrl = function_exists('site_url') ? site_url('/accounts/fees_collectionbulk.php') : 'fees_collectionbulk.php';
$collectUrl = function_exists('site_url') ? site_url('/accounts/fees_collection.php') : 'fees_collection.php';
$dailyUrl = function_exists('site_url') ? site_url('/accounts/daily_collection.php') : 'daily_collection.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = function_exists('get_csrf_token') ? get_csrf_token() : (string) $_SESSION['csrf_token'];

$locked = function_exists('ay_can_edit') && !ay_can_edit();
$hasFees = function_exists('table_exists') && table_exists('fees_records');
$hasStudents = function_exists('table_exists') && table_exists('students');
$hasDues = $hasStudents && function_exists('column_exists') && column_exists('students', 'dues_status');

$payMethods = ['Cash', 'UPI', 'Online', 'Cheque', 'Bank'];

$classes = (function_exists('table_exists') && table_exists('classes'))
    ? (safe_db_get_all('SELECT id, name FROM classes ORDER BY name ASC') ?: [])
    : [];

$classId = isset($_POST['class_id']) ? (int) $_POST['class_id'] : (int) ($_GET['class_id'] ?? 0);
$payDate = trim((string) ($_POST['payment_date'] ?? $_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
    $payDate = date('Y-m-d');
}
$payMethod = trim((string) ($_POST['payment_type'] ?? $_GET['method'] ?? 'Cash'));
if (!in_array($payMethod, $payMethods, true)) {
    $payMethod = 'Cash';
}
$note = trim((string) ($_POST['note'] ?? ''));

$stuName = static function (array $s): string {
    return trim(preg_replace('/\s+/', ' ', trim(($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))));
};

$makeReceipt = static function (string $method, string $note, int $i): string {
    $base = 'RC' . date('YmdHis') . sprintf('%03d', $i % 1000) . mt_rand(10, 99);
    $meta = ['METHOD:' . str_replace(['|', '||'], '', $method)];
    $n = preg_replace('/\s+/', ' ', $note) ?? '';
    $n = substr(str_replace(['|', '||'], '', $n), 0, 120);
    if ($n !== '') {
        $meta[] = 'NOTE:' . $n;
    }
    return $base . '||' . implode('||', $meta);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($CSRF, $token)) {
        set_flash('error', 'Could not save. Refresh and try again.');
        header('Location: ' . $selfUrl);
        exit;
    }
    if ($locked) {
        set_flash('error', 'This academic year is locked.');
        header('Location: ' . $selfUrl . '?class_id=' . $classId);
        exit;
    }
    if (!$hasFees) {
        set_flash('error', 'Fee receipts table is missing.');
        header('Location: ' . $selfUrl);
        exit;
    }
    $amounts = $_POST['amount'] ?? [];
    if (!is_array($amounts)) {
        $amounts = [];
    }

    $saved = 0;
    $skipped = 0;
    $pdo = pdo_connect();
    if (!($pdo instanceof PDO)) {
        set_flash('error', 'Database not available.');
        header('Location: ' . $selfUrl . '?class_id=' . $classId);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO fees_records
             (school_id, student_id, class_id, amount, due_date, paid_amount, status, receipt_no, collected_by, collected_at, created_at, updated_at)
             VALUES
             (:school_id, :student_id, :class_id, :amount, NULL, :paid_amount, 'paid', :receipt_no, :collected_by, :collected_at, :created_at, :updated_at)"
        );
        $now = date('Y-m-d H:i:s');
        $collectedAt = $payDate . ' ' . date('H:i:s');
        $i = 1;
        foreach ($amounts as $sidRaw => $amtRaw) {
            $sid = (int) $sidRaw;
            $amt = (float) str_replace([',', ' '], '', (string) $amtRaw);
            if ($sid <= 0 || $amt <= 0) {
                if ($amtRaw !== '' && $amt <= 0) {
                    $skipped++;
                }
                continue;
            }
            $stu = safe_db_get_one(
                "SELECT id, school_id, class_id FROM students WHERE id = :id LIMIT 1",
                [':id' => $sid]
            );
            if (!$stu) {
                $skipped++;
                continue;
            }
            $ok = $stmt->execute([
                ':school_id' => $stu['school_id'] !== null ? (int) $stu['school_id'] : null,
                ':student_id' => $sid,
                ':class_id' => $stu['class_id'] !== null ? (int) $stu['class_id'] : $classId,
                ':amount' => $amt,
                ':paid_amount' => $amt,
                ':receipt_no' => $makeReceipt($payMethod, $note, $i),
                ':collected_by' => $accountsUserId ? (int) $accountsUserId : null,
                ':collected_at' => $collectedAt,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            if ($ok) {
                $saved++;
                $i++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[fees_collectionbulk] ' . $e->getMessage());
        set_flash('error', 'Save failed. Try again.');
        header('Location: ' . $selfUrl . '?class_id=' . $classId);
        exit;
    }

    if ($saved > 0) {
        set_flash('success', 'Saved ' . $saved . ' receipt' . ($saved === 1 ? '' : 's') . '. See them on Daily Collection.');
        header('Location: ' . $dailyUrl . '?from=' . urlencode($payDate) . '&to=' . urlencode($payDate));
        exit;
    }
    set_flash('warning', 'Enter an amount for at least one child.');
    header('Location: ' . $selfUrl . '?class_id=' . $classId . '&date=' . urlencode($payDate) . '&method=' . urlencode($payMethod));
    exit;
}

$kids = [];
if ($classId > 0 && $hasStudents) {
    $duesSql = $hasDues ? " AND LOWER(COALESCE(s.dues_status,'open')) <> 'written_off'" : '';
    $kids = safe_db_get_all(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name, s.school_id, s.class_id,
                COALESCE(s.total_fees,0) AS total_fees,
                COALESCE((SELECT SUM(fr.paid_amount) FROM fees_records fr WHERE fr.student_id = s.id),0) AS paid
         FROM students s
         WHERE s.class_id = :cid
           AND LOWER(COALESCE(s.status,'active')) IN ('active','pending')
           {$duesSql}
         ORDER BY s.first_name, s.last_name",
        [':cid' => $classId]
    ) ?: [];
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Collect by class</h1>
    <p class="text-muted mb-0">One child at a time → <a href="<?php echo e($collectUrl); ?>">Collect Fees</a>. Whole class today → this page. Excel / student ID not needed.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?php echo e($dailyUrl); ?>">Daily Collection</a>
</div>

<?php if ($locked): ?>
  <div class="alert alert-warning">This academic year is locked. Collection is off.</div>
<?php endif; ?>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label">Class</label>
      <select name="class_id" class="form-select" required>
        <option value="">Choose class</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int) $c['id']; ?>" <?php echo $classId === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e((string) $c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Date</label>
      <input type="date" name="date" class="form-control" value="<?php echo e($payDate); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Paid by</label>
      <select name="method" class="form-select">
        <?php foreach ($payMethods as $m): ?>
          <option value="<?php echo e($m); ?>" <?php echo $payMethod === $m ? 'selected' : ''; ?>><?php echo e($m); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100" type="submit">Show children</button>
    </div>
  </div>
</form>

<?php if ($classId <= 0): ?>
  <div class="alert alert-info">Pick a class. Then type how much each child paid today. Leave a row blank to skip.</div>
<?php elseif ($kids === []): ?>
  <div class="alert alert-warning">No active children in this class.</div>
<?php else: ?>
<form method="post" id="bulkForm">
  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="class_id" value="<?php echo (int) $classId; ?>">
  <input type="hidden" name="payment_date" value="<?php echo e($payDate); ?>">
  <input type="hidden" name="payment_type" value="<?php echo e($payMethod); ?>">

  <div class="card mb-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label class="form-label mb-0 small">Note on all receipts (optional)</label>
          <input name="note" class="form-control" value="<?php echo e($note); ?>" placeholder="e.g. April fees">
        </div>
        <div class="col-md-6 text-md-end">
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="fillPending" <?php echo $locked ? 'disabled' : ''; ?>>Fill pending amounts</button>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3" id="clearAmts">Clear amounts</button>
        </div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>Child</th>
            <th class="text-end">Yearly</th>
            <th class="text-end">Already paid</th>
            <th class="text-end">Pending</th>
            <th style="width:160px">Collect now (₹)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($kids as $s):
            $total = (float) $s['total_fees'];
            $paid = (float) $s['paid'];
            $pending = max(0, $total - $paid);
            $sid = (int) $s['id'];
        ?>
          <tr>
            <td>
              <div class="fw-semibold"><?php echo e($stuName($s) !== '' ? $stuName($s) : 'Student #' . $sid); ?></div>
              <a class="small" href="<?php echo e($collectUrl . '?student_id=' . $sid); ?>">One receipt</a>
            </td>
            <td class="text-end">₹ <?php echo number_format($total, 0); ?></td>
            <td class="text-end">₹ <?php echo number_format($paid, 0); ?></td>
            <td class="text-end"><?php echo $pending > 0 ? '₹ ' . number_format($pending, 0) : '—'; ?></td>
            <td>
              <input class="form-control form-control-sm amt" type="number" min="0" step="1" name="amount[<?php echo $sid; ?>]" data-pending="<?php echo e((string) (int) round($pending)); ?>" inputmode="numeric" <?php echo $locked ? 'disabled' : ''; ?> placeholder="Skip">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <button class="btn btn-primary btn-lg" type="submit" <?php echo $locked ? 'disabled' : ''; ?>>Save receipts</button>
</form>
<script>
document.getElementById('fillPending')?.addEventListener('click', function () {
  document.querySelectorAll('.amt').forEach(function (el) {
    var p = el.getAttribute('data-pending') || '0';
    if (parseInt(p, 10) > 0) el.value = p;
  });
});
document.getElementById('clearAmts')?.addEventListener('click', function () {
  document.querySelectorAll('.amt').forEach(function (el) { el.value = ''; });
});
</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
