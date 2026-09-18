<?php
/**
 * School expenses — add, list, edit, delete. Preschool categories.
 */
declare(strict_types=1);

$cfg = $GLOBALS['FEATURE_CONFIG'] ?? [];
$panel = (string) ($cfg['panel'] ?? 'owner');
$page_title = (string) ($cfg['page_title'] ?? 'Expenses');
$pageTitle = $page_title;
$createdByUserId = (int) (auth_user_id() ?? 0);

$selfPath = $panel === 'accounts' ? '/accounts/expenses.php' : '/owner/expense.php';
$selfUrl = function_exists('site_url') ? site_url($selfPath) : $selfPath;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = function_exists('get_csrf_token') ? get_csrf_token() : (string) $_SESSION['csrf_token'];

$categories = [
    'Rent' => 'Rent / premises',
    'Salary' => 'Staff salary',
    'Electricity' => 'Electricity',
    'Water' => 'Water',
    'Housekeeping' => 'Housekeeping / cleaning',
    'Food' => 'Food / snacks / milk',
    'Learning' => 'Toys & learning material',
    'Stationery' => 'Stationery / printing',
    'Events' => 'Events / celebrations',
    'Transport' => 'Transport',
    'Maintenance' => 'Maintenance / repairs',
    'Internet' => 'Internet / phone',
    'Medical' => 'Medical / first aid',
    'Uniforms' => 'Uniforms / ID cards',
    'Marketing' => 'Marketing',
    'Licence' => 'Licence / government fees',
    'Other' => 'Other',
];
$payMethods = ['Cash', 'UPI', 'Online', 'Cheque', 'Bank'];

try {
    $pdo = pdo_connect();
    if ($pdo instanceof PDO && function_exists('table_exists') && !table_exists('expenses')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS expenses (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              title VARCHAR(255) NOT NULL,
              amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              category VARCHAR(100) DEFAULT NULL,
              expense_date DATE NOT NULL,
              payment_method VARCHAR(50) DEFAULT NULL,
              notes TEXT,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              created_by INT NULL,
              INDEX (expense_date),
              INDEX (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
} catch (Throwable $e) {
    // table may already exist
}

$yearLocked = function_exists('ay_can_edit') && !ay_can_edit();

$messages = [];
$errors = [];
if (!empty($_SESSION['ex_ok'])) {
    $messages[] = (string) $_SESSION['ex_ok'];
    unset($_SESSION['ex_ok']);
}
if (!empty($_SESSION['ex_err'])) {
    $errors[] = (string) $_SESSION['ex_err'];
    unset($_SESSION['ex_err']);
}

$csrfOk = static function (string $token) use ($CSRF): bool {
    if (function_exists('validate_csrf_token') && validate_csrf_token($token)) {
        return true;
    }
    return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
};

$action = (string) ($_REQUEST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save', 'delete'], true)) {
    if (!$csrfOk((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ex_err'] = 'Please reload the page and try again.';
        header('Location: ?');
        exit;
    }
    if ($yearLocked) {
        $_SESSION['ex_err'] = 'This academic year is locked.';
        header('Location: ?');
        exit;
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $ok = $id > 0 && safe_db_run('DELETE FROM expenses WHERE id = :id', [':id' => $id]);
        $_SESSION[$ok ? 'ex_ok' : 'ex_err'] = $ok ? 'Expense deleted.' : 'Could not delete.';
        header('Location: ?');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $amount = (float) str_replace(',', '', trim((string) ($_POST['amount'] ?? '0')));
    $category = trim((string) ($_POST['category'] ?? ''));
    $expense_date = trim((string) ($_POST['expense_date'] ?? '')) ?: date('Y-m-d');
    $payment_method = trim((string) ($_POST['payment_method'] ?? 'Cash'));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($title === '') {
        $_SESSION['ex_err'] = 'Write what you paid for.';
        header('Location: ?');
        exit;
    }
    if ($amount <= 0) {
        $_SESSION['ex_err'] = 'Enter an amount greater than 0.';
        header('Location: ?');
        exit;
    }
    if ($category === '' || !isset($categories[$category])) {
        $category = 'Other';
    }
    if (!in_array($payment_method, $payMethods, true)) {
        $payment_method = 'Cash';
    }
    if (!DateTime::createFromFormat('Y-m-d', $expense_date)) {
        $expense_date = date('Y-m-d');
    }
    $params = [
        ':title' => $title,
        ':amount' => $amount,
        ':category' => $category,
        ':expense_date' => $expense_date,
        ':payment_method' => $payment_method,
        ':notes' => $notes !== '' ? $notes : null,
    ];
    if ($id > 0) {
        $params[':id'] = $id;
        $ok = safe_db_run(
            'UPDATE expenses SET title=:title, amount=:amount, category=:category, expense_date=:expense_date, payment_method=:payment_method, notes=:notes, updated_at=NOW() WHERE id=:id',
            $params
        );
        $_SESSION[$ok ? 'ex_ok' : 'ex_err'] = $ok ? 'Expense updated.' : 'Could not update.';
    } else {
        $params[':created_by'] = $createdByUserId ?: null;
        $ok = safe_db_run(
            'INSERT INTO expenses (title, amount, category, expense_date, payment_method, notes, created_by, created_at) VALUES (:title, :amount, :category, :expense_date, :payment_method, :notes, :created_by, NOW())',
            $params
        );
        $_SESSION[$ok ? 'ex_ok' : 'ex_err'] = $ok ? 'Expense saved.' : 'Could not save.';
    }
    header('Location: ?');
    exit;
}

$edit = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $edit = safe_db_get_one('SELECT * FROM expenses WHERE id = :id LIMIT 1', [':id' => $editId]);
}

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$from = trim((string) ($_GET['from'] ?? $monthStart));
$to = trim((string) ($_GET['to'] ?? $monthEnd));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $monthStart;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $monthEnd;
}
$q = trim((string) ($_GET['q'] ?? ''));
$catFilter = trim((string) ($_GET['category'] ?? ''));

$where = ['expense_date >= :from', 'expense_date <= :to'];
$params = [':from' => $from, ':to' => $to];
if ($q !== '') {
    $where[] = '(title LIKE :q OR notes LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($catFilter !== '' && isset($categories[$catFilter])) {
    $where[] = 'category = :category';
    $params[':category'] = $catFilter;
}
if (function_exists('ay_apply_date_filter')) {
    ay_apply_date_filter($where, $params, 'expense_date');
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=expenses_' . $from . '_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'What', 'Category', 'Amount', 'Paid by', 'Paid to / bill']);
    $all = safe_db_get_all("SELECT * FROM expenses {$whereSql} ORDER BY expense_date DESC, id DESC", $params) ?: [];
    foreach ($all as $r) {
        fputcsv($out, [
            $r['expense_date'] ?? '',
            $r['title'] ?? '',
            $r['category'] ?? '',
            number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
            $r['payment_method'] ?? '',
            preg_replace("/\s+/", ' ', (string) ($r['notes'] ?? '')),
        ]);
    }
    fclose($out);
    exit;
}

$sumRow = safe_db_get_one("SELECT COALESCE(SUM(amount),0) AS s, COUNT(*) AS c FROM expenses {$whereSql}", $params) ?: ['s' => 0, 'c' => 0];
$listTotal = (float) ($sumRow['s'] ?? 0);
$listCount = (int) ($sumRow['c'] ?? 0);

$byCat = safe_db_get_all(
    "SELECT COALESCE(category,'Other') AS category, COALESCE(SUM(amount),0) AS amt FROM expenses {$whereSql} GROUP BY category ORDER BY amt DESC",
    $params
) ?: [];

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$totalPages = max(1, (int) ceil($listCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = safe_db_get_all(
    "SELECT * FROM expenses {$whereSql} ORDER BY expense_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
) ?: [];

$filterQs = array_filter([
    'from' => $from,
    'to' => $to,
    'q' => $q !== '' ? $q : null,
    'category' => $catFilter !== '' ? $catFilter : null,
], static fn ($v) => $v !== null);

require_once __DIR__ . '/../header.php';
?>
<style>
.ex-card { background:#fff; border:1px solid #dbe7fb; border-radius:14px; padding:14px 16px; margin-bottom:12px; }
.ex-stat { font-size:1.4rem; font-weight:800; color:#b42318; }
</style>

<p class="text-muted mb-3">Record money the school spent (rent, salary, snacks, bills). This is not fee collection.</p>

<?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo e($er); ?></div><?php endforeach; ?>
<?php if ($yearLocked): ?><div class="alert alert-warning">This academic year is locked. You can view expenses but not change them.</div><?php endif; ?>

<div class="row g-2 mb-3">
  <div class="col-md-4">
    <div class="ex-card">
      <div class="small text-muted">Total in this list</div>
      <div class="ex-stat">₹ <?php echo number_format($listTotal, 2); ?></div>
      <div class="small"><?php echo $listCount; ?> entries</div>
    </div>
  </div>
  <div class="col-md-8">
    <div class="ex-card">
      <div class="small text-muted mb-1">By category</div>
      <?php if ($byCat === []): ?>
        <div class="text-muted">No expenses in this date range.</div>
      <?php else: ?>
        <div class="row">
          <?php foreach ($byCat as $c): ?>
            <div class="col-6 col-md-4 d-flex justify-content-between"><span><?php echo e($categories[$c['category']] ?? (string) $c['category']); ?></span><strong>₹ <?php echo number_format((float) $c['amt'], 2); ?></strong></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="ex-card">
  <h2 class="h6 fw-bold mb-3"><?php echo $edit ? 'Edit expense' : 'Add expense'; ?></h2>
  <form method="post" action="?action=save" class="row g-2 align-items-end">
    <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
    <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit['id'] : 0; ?>">
    <div class="col-md-2">
      <label class="form-label">Date</label>
      <input type="date" name="expense_date" class="form-control" required value="<?php echo e((string) ($edit['expense_date'] ?? date('Y-m-d'))); ?>" <?php echo $yearLocked ? 'disabled' : ''; ?>>
    </div>
    <div class="col-md-3">
      <label class="form-label">Category</label>
      <select name="category" class="form-select" required <?php echo $yearLocked ? 'disabled' : ''; ?>>
        <?php $selCat = (string) ($edit['category'] ?? 'Other'); ?>
        <?php foreach ($categories as $key => $lab): ?>
          <option value="<?php echo e($key); ?>" <?php echo $selCat === $key ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">What did you pay for?</label>
      <input name="title" class="form-control" required placeholder="e.g. April teacher salary" value="<?php echo e((string) ($edit['title'] ?? '')); ?>" <?php echo $yearLocked ? 'disabled' : ''; ?>>
    </div>
    <div class="col-md-3">
      <label class="form-label">Amount</label>
      <input name="amount" class="form-control" inputmode="decimal" required placeholder="0.00" value="<?php echo e($edit ? number_format((float) $edit['amount'], 2, '.', '') : ''); ?>" <?php echo $yearLocked ? 'disabled' : ''; ?>>
    </div>
    <div class="col-md-2">
      <label class="form-label">Paid by</label>
      <select name="payment_method" class="form-select" <?php echo $yearLocked ? 'disabled' : ''; ?>>
        <?php $selPay = (string) ($edit['payment_method'] ?? 'Cash'); ?>
        <?php foreach ($payMethods as $pm): ?>
          <option value="<?php echo e($pm); ?>" <?php echo $selPay === $pm ? 'selected' : ''; ?>><?php echo e($pm); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Paid to / bill no. (optional)</label>
      <input name="notes" class="form-control" placeholder="Vendor name or bill number" value="<?php echo e((string) ($edit['notes'] ?? '')); ?>" <?php echo $yearLocked ? 'disabled' : ''; ?>>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <?php if (!$yearLocked): ?>
        <button class="btn btn-primary" type="submit"><?php echo $edit ? 'Update' : 'Save expense'; ?></button>
      <?php endif; ?>
      <?php if ($edit): ?>
        <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="ex-card">
  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo e($from); ?>"></div>
    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo e($to); ?>"></div>
    <div class="col-md-3">
      <label class="form-label">Category</label>
      <select name="category" class="form-select">
        <option value="">All</option>
        <?php foreach ($categories as $key => $lab): ?>
          <option value="<?php echo e($key); ?>" <?php echo $catFilter === $key ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label">Search</label><input name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="What / vendor"></div>
    <div class="col-md-2 d-flex flex-wrap gap-2">
      <button class="btn btn-outline-primary" type="submit">Show</button>
      <a class="btn btn-outline-secondary" href="<?php echo e($selfUrl); ?>">This month</a>
    </div>
  </form>
  <div class="d-flex justify-content-end mb-2">
    <a class="btn btn-sm btn-outline-secondary" href="?<?php echo e(http_build_query(array_merge($filterQs, ['export' => 'csv']))); ?>">CSV</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Date</th><th>What</th><th>Category</th><th class="text-end">Amount</th><th></th></tr></thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No expenses yet. Add one above (salary, electricity, snacks…).</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small text-muted"><?php echo e((string) ($r['expense_date'] ?? '')); ?></td>
            <td>
              <div class="fw-semibold"><?php echo e((string) ($r['title'] ?? '')); ?></div>
              <div class="small text-muted"><?php echo e((string) ($r['payment_method'] ?? '')); ?><?php echo !empty($r['notes']) ? ' · ' . e((string) $r['notes']) : ''; ?></div>
            </td>
            <td><?php echo e($categories[$r['category'] ?? ''] ?? (string) ($r['category'] ?? '—')); ?></td>
            <td class="text-end fw-bold">₹ <?php echo number_format((float) ($r['amount'] ?? 0), 2); ?></td>
            <td class="text-nowrap text-end">
              <?php if (!$yearLocked): ?>
                <a class="btn btn-sm btn-outline-secondary" href="?edit=<?php echo (int) $r['id']; ?>">Edit</a>
                <form method="post" action="?action=delete" class="d-inline" onsubmit="return confirm('Delete this expense?');">
                  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="pt-3">
      <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
            <a class="page-link" href="?<?php echo e(http_build_query(array_merge($filterQs, ['page' => $p]))); ?>"><?php echo $p; ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/../footer.php';
