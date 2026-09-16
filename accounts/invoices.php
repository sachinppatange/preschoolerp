<?php
/**
 * accounts/invoices.php
 *
 * Invoices list and export for Accounts area.
 * If `invoices` table is missing, falls back to showing data from `fees_records`
 * (treated as receipts). Supports list, view (modal fragment) and CSV export.
 *
 * Place at: /pioneerplayschool01/accounts/invoices.php
 *
 * Auth:
 *  - $_SESSION['accounts_auth_user'] or $_SESSION['accounts_user_id'] required.
 *
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('accounts');
$DEBUG = panel_debug();

$esc = function(string $v){ return e($v); };

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

$accountsUserId = auth_user_id();

/* -------------------------
   Determine primary data source
   ------------------------- */
$hasInvoices = table_exists('invoices');
$useFeesRecords = !$hasInvoices && table_exists('fees_records');

/* -------------------------
   Actions: list, view, export
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$messages = []; $errors = [];

/* VIEW: modal fragment for invoice OR fees_records */
if ($action === 'view' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) { echo '<div class="text-danger p-3">Invalid id</div>'; exit; }

    if ($hasInvoices) {
        $row = safe_db_get_one("
            SELECT inv.*, 
                   COALESCE(s.first_name,'') AS student_first, COALESCE(s.last_name,'') AS student_last,
                   COALESCE(c.name,'') AS class_name,
                   COALESCE(u.name,'') AS created_by_name
            FROM invoices inv
            LEFT JOIN students s ON s.id = inv.student_id
            LEFT JOIN classes c ON c.id = inv.class_id
            LEFT JOIN users u ON u.id = inv.created_by
            WHERE inv.id = :id LIMIT 1
        ", [':id'=>$id]);

        if (!$row) { echo '<div class="text-muted p-3">Invoice not found.</div>'; exit; }

        $student = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
        echo '<dl class="row p-3">';
        echo '<dt class="col-sm-3">Invoice ID</dt><dd class="col-sm-9">'.(int)$row['id'].'</dd>';
        echo '<dt class="col-sm-3">Invoice No</dt><dd class="col-sm-9">'.e($row['invoice_no'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Student</dt><dd class="col-sm-9">'.($student ? e($student) : '—').'</dd>';
        echo '<dt class="col-sm-3">Class</dt><dd class="col-sm-9">'.e($row['class_name'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Amount</dt><dd class="col-sm-9">₹ '.number_format((float)($row['amount'] ?? 0),2).'</dd>';
        echo '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">'.e($row['status'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Issued At</dt><dd class="col-sm-9">'.e($row['issued_at'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Due Date</dt><dd class="col-sm-9">'.e($row['due_date'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Created By</dt><dd class="col-sm-9">'.e($row['created_by_name'] ?? '—').'</dd>';
        echo '<dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><pre style="white-space:pre-wrap;">'.e($row['notes'] ?? '').'</pre></dd>';
        echo '</dl>';
        exit;
    }

    if ($useFeesRecords) {
        $row = safe_db_get_one("
            SELECT fr.*, 
                   COALESCE(s.first_name,'') AS student_first, COALESCE(s.middle_name,'') AS student_middle, COALESCE(s.last_name,'') AS student_last,
                   COALESCE(c.name,'') AS class_name,
                   COALESCE(sc.name,'') AS school_name,
                   COALESCE(u.name,'') AS collector_name
            FROM fees_records fr
            LEFT JOIN students s ON s.id = fr.student_id
            LEFT JOIN classes c ON c.id = fr.class_id
            LEFT JOIN schools sc ON sc.id = fr.school_id
            LEFT JOIN users u ON u.id = fr.collected_by
            WHERE fr.id = :id LIMIT 1
        ", [':id'=>$id]);

        if (!$row) { echo '<div class="text-muted p-3">Receipt not found.</div>'; exit; }

        $student = trim(($row['student_first'] ?? '') . ' ' . ($row['student_middle'] ?? '') . ' ' . ($row['student_last'] ?? ''));
        echo '<dl class="row p-3">';
        echo '<dt class="col-sm-4">Receipt ID</dt><dd class="col-sm-8">'.(int)$row['id'].'</dd>';
        echo '<dt class="col-sm-4">Receipt No</dt><dd class="col-sm-8">'.e($row['receipt_no'] ?? '—').'</dd>';
        echo '<dt class="col-sm-4">Student</dt><dd class="col-sm-8">'.($student ? e($student) : '—').'</dd>';
        echo '<dt class="col-sm-4">School</dt><dd class="col-sm-8">'.e($row['school_name'] ?? '—').'</dd>';
        echo '<dt class="col-sm-4">Class</dt><dd class="col-sm-8">'.e($row['class_name'] ?? '—').'</dd>';
        echo '<dt class="col-sm-4">Amount</dt><dd class="col-sm-8">₹ '.number_format((float)($row['paid_amount'] ?? $row['amount'] ?? 0),2).'</dd>';
        echo '<dt class="col-sm-4">Collected At</dt><dd class="col-sm-8">'.e($row['collected_at'] ?? $row['created_at'] ?? '—').'</dd>';
        echo '<dt class="col-sm-4">Collected By</dt><dd class="col-sm-8">'.e($row['collector_name'] ?? ($row['collected_by'] ?? '—')).'</dd>';
        echo '<dt class="col-sm-4">Status</dt><dd class="col-sm-8">'.e($row['status'] ?? '—').'</dd>';
        echo '<dt class="col-sm-4">Note</dt><dd class="col-sm-8"><pre style="white-space:pre-wrap;">'.e($row['note'] ?? '').'</pre></dd>';
        echo '</dl>';
        exit;
    }

    echo '<div class="text-muted p-3">No data source available.</div>';
    exit;
}

/* EXPORT: invoices OR receipts CSV */
if ($action === 'export') {
    // build filters
    $where = []; $params = [];
    $qraw = trim((string)($_GET['q'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $classFilter = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));

    if ($hasInvoices) {
        if ($qraw !== '') { $where[] = "(inv.invoice_no LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
        if ($statusFilter !== '') { $where[] = "inv.status = :status"; $params[':status'] = $statusFilter; }
        if ($classFilter) { $where[] = "inv.class_id = :class_id"; $params[':class_id'] = $classFilter; }
        if ($from !== '') { $where[] = "DATE(inv.issued_at) >= :from"; $params[':from'] = $from; }
        if ($to !== '') { $where[] = "DATE(inv.issued_at) <= :to"; $params[':to'] = $to; }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $rows = safe_db_get_all("
            SELECT inv.*, COALESCE(st.first_name,'') AS student_first, COALESCE(st.last_name,'') AS student_last, COALESCE(c.name,'') AS class_name
            FROM invoices inv
            LEFT JOIN students st ON st.id = inv.student_id
            LEFT JOIN classes c ON c.id = inv.class_id
            $whereSql
            ORDER BY inv.issued_at DESC
        ", $params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=invoices_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Invoice No','Student','Class','Amount','Status','Issued At','Due Date','Created At','Notes']);
        foreach ($rows as $r) {
            $student = trim(($r['student_first'] ?? '') . ' ' . ($r['student_last'] ?? ''));
            fputcsv($out, [
                $r['id'] ?? '',
                $r['invoice_no'] ?? '',
                $student,
                $r['class_name'] ?? '',
                isset($r['amount']) ? number_format((float)$r['amount'],2) : '',
                $r['status'] ?? '',
                $r['issued_at'] ?? '',
                $r['due_date'] ?? '',
                $r['created_at'] ?? '',
                preg_replace("/\r\n|\r|\n/"," ", $r['notes'] ?? '')
            ]);
        }
        fclose($out);
        exit;
    }

    if ($useFeesRecords) {
        if ($qraw !== '') { $where[] = "(fr.receipt_no LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
        if ($statusFilter !== '') { $where[] = "fr.status = :status"; $params[':status'] = $statusFilter; }
        if ($classFilter) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = $classFilter; }
        if ($from !== '') { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) >= :from"; $params[':from'] = $from; }
        if ($to !== '') { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) <= :to"; $params[':to'] = $to; }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $rows = safe_db_get_all("
            SELECT fr.*, COALESCE(st.first_name,'') AS student_first, COALESCE(st.middle_name,'') AS student_middle, COALESCE(st.last_name,'') AS student_last, COALESCE(c.name,'') AS class_name, COALESCE(sc.name,'') AS school_name
            FROM fees_records fr
            LEFT JOIN students st ON st.id = fr.student_id
            LEFT JOIN classes c ON c.id = fr.class_id
            LEFT JOIN schools sc ON sc.id = fr.school_id
            $whereSql
            ORDER BY COALESCE(fr.collected_at, fr.created_at) DESC
        ", $params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipts_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Receipt No','Student','School','Class','Amount','Paid Amount','Status','Collected At','Created At','Note']);
        foreach ($rows as $r) {
            $student = trim(($r['student_first'] ?? '') . ' ' . ($r['student_middle'] ?? '') . ' ' . ($r['student_last'] ?? ''));
            fputcsv($out, [
                $r['id'] ?? '',
                $r['receipt_no'] ?? '',
                $student,
                $r['school_name'] ?? '',
                $r['class_name'] ?? '',
                isset($r['amount']) ? number_format((float)$r['amount'],2) : '',
                isset($r['paid_amount']) ? number_format((float)$r['paid_amount'],2) : '',
                $r['status'] ?? '',
                $r['collected_at'] ?? '',
                $r['created_at'] ?? '',
                preg_replace("/\r\n|\r|\n/"," ", $r['note'] ?? '')
            ]);
        }
        fclose($out);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8'); echo "No data source available."; exit;
}

/* -------------------------
   List: filters, pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$qraw = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$classFilter = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$invoices = [];
$total = 0;
$errors = [];

/* If invoices table exists use original invoices logic, else use fees_records as receipts */
if ($hasInvoices) {
    $where = []; $params = [];
    if ($qraw !== '') { $where[] = "(inv.invoice_no LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
    if ($statusFilter !== '') { $where[] = "inv.status = :status"; $params[':status'] = $statusFilter; }
    if ($classFilter) { $where[] = "inv.class_id = :class_id"; $params[':class_id'] = $classFilter; }
    if ($from !== '') { $where[] = "DATE(inv.issued_at) >= :from"; $params[':from'] = $from; }
    if ($to !== '') { $where[] = "DATE(inv.issued_at) <= :to"; $params[':to'] = $to; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM invoices inv LEFT JOIN students st ON st.id = inv.student_id $whereSql", $params);
        $total = intval($cRow['c'] ?? 0);
    } catch (Throwable $e) { $total = 0; $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'); }

    try {
        $sql = "
            SELECT inv.*, COALESCE(st.first_name,'') AS student_first, COALESCE(st.last_name,'') AS student_last, COALESCE(c.name,'') AS class_name
            FROM invoices inv
            LEFT JOIN students st ON st.id = inv.student_id
            LEFT JOIN classes c ON c.id = inv.class_id
            $whereSql
            ORDER BY inv.issued_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $pdo = pdo_connect();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $invoices = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
        }
    } catch (Throwable $e) { $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'); $invoices = []; }

} elseif ($useFeesRecords) {
    // treat fees_records as receipts and show similar columns
    $where = []; $params = [];
    if ($qraw !== '') { $where[] = "(fr.receipt_no LIKE :q OR st.first_name LIKE :q OR st.last_name LIKE :q)"; $params[':q'] = '%'.$qraw.'%'; }
    if ($statusFilter !== '') { $where[] = "fr.status = :status"; $params[':status'] = $statusFilter; }
    if ($classFilter) { $where[] = "fr.class_id = :class_id"; $params[':class_id'] = $classFilter; }
    if ($from !== '') { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) >= :from"; $params[':from'] = $from; }
    if ($to !== '') { $where[] = "DATE(COALESCE(fr.collected_at, fr.created_at)) <= :to"; $params[':to'] = $to; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM fees_records fr LEFT JOIN students st ON st.id = fr.student_id $whereSql", $params);
        $total = intval($cRow['c'] ?? 0);
    } catch (Throwable $e) { $total = 0; $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'); }

    try {
        $sql = "
            SELECT fr.*, COALESCE(st.first_name,'') AS student_first, COALESCE(st.middle_name,'') AS student_middle, COALESCE(st.last_name,'') AS student_last, COALESCE(c.name,'') AS class_name, COALESCE(sc.name,'') AS school_name
            FROM fees_records fr
            LEFT JOIN students st ON st.id = fr.student_id
            LEFT JOIN classes c ON c.id = fr.class_id
            LEFT JOIN schools sc ON sc.id = fr.school_id
            $whereSql
            ORDER BY COALESCE(fr.collected_at, fr.created_at) DESC
            LIMIT :limit OFFSET :offset
        ";
        $pdo = pdo_connect();
        if ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            $invoices = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $invoices = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
        }
    } catch (Throwable $e) { $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error'); $invoices = []; }

} else {
    $errors[] = 'Invoices table does not exist and fees_records not available.';
    $invoices = [];
    $total = 0;
}

/* data for filters */
$classList = table_exists('classes') ? safe_db_get_all("SELECT id, name FROM classes ORDER BY name ASC") : [];
$statusOptions = ['draft','sent','paid','overdue'];
// extend status options with 'paid'/'unpaid' presence for receipts
if ($useFeesRecords) {
    $statusOptions = array_unique(array_merge($statusOptions, ['paid','unpaid']));
}

/* pagination */
$totalPages = (int)ceil(max(0, $total) / $perPage);

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

/* header include if exists */
$pageTitle = $useFeesRecords ? 'Receipts (from fees_records)' : 'Invoices';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="?">Refresh</a>
      <?php if ($useFeesRecords): ?>
        <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
      <?php else: ?>
        <a class="btn btn-sm btn-success" href="?action=export&<?php echo build_qs(); ?>">Export CSV</a>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?php echo $esc($m); ?></div><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?php echo $esc($er); ?></div><?php endforeach; ?>

  <!-- Filters -->
  <div class="card mb-3 p-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Search</label>
        <input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="<?php echo $useFeesRecords ? 'Receipt no or student name' : 'Invoice no or student name'; ?>"></div>
      <div class="col-md-2"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">Any</option><?php foreach ($statusOptions as $s): ?><option value="<?php echo $esc($s); ?>" <?php if($statusFilter===$s) echo 'selected'; ?>><?php echo $esc(ucfirst($s)); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">Class</label>
        <select name="class_id" class="form-select"><option value="">Any</option><?php foreach ($classList as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php if((int)$classFilter===(int)$c['id']) echo 'selected'; ?>><?php echo $esc($c['name']); ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo $esc($from); ?>"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo $esc($to); ?>"></div>
      <div class="col-12 text-end mt-2"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="?">Reset</a></div>
    </form>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead>
          <tr>
            <th style="width:70px">ID</th>
            <th style="width:160px"><?php echo $useFeesRecords ? 'Collected At' : 'Issued At'; ?></th>
            <th><?php echo $useFeesRecords ? 'Receipt No / Student' : 'Invoice No / Student'; ?></th>
            <th style="width:120px">Amount</th>
            <th style="width:120px"><?php echo $useFeesRecords ? 'Paid' : 'Due Date'; ?></th>
            <th style="width:120px">Status</th>
            <th style="width:180px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($invoices)): foreach ($invoices as $inv): ?>
            <?php
              if ($useFeesRecords) {
                  $student = trim(($inv['student_first'] ?? '') . ' ' . ($inv['student_middle'] ?? '') . ' ' . ($inv['student_last'] ?? ''));
                  $when = $inv['collected_at'] ?? $inv['created_at'] ?? '';
              } else {
                  $student = trim(($inv['student_first'] ?? '') . ' ' . ($inv['student_last'] ?? ''));
                  $when = $inv['issued_at'] ?? $inv['created_at'] ?? '';
              }
            ?>
            <tr>
              <td><?php echo (int)$inv['id']; ?></td>
              <td><?php echo $esc(substr($when,0,16)); ?></td>
              <td>
                <div class="fw-semibold"><?php echo $esc($useFeesRecords ? ($inv['receipt_no'] ?? '—') : ($inv['invoice_no'] ?? '—')); ?></div>
                <div class="small text-muted"><?php echo $esc($student ?: ($useFeesRecords ? ('Student ID ' . ((int)$inv['student_id'] ?? 0)) : ('Student ID ' . ((int)$inv['student_id'] ?? 0)))); ?></div>
                <?php if ($useFeesRecords && !empty($inv['school_name'])): ?><div class="small text-muted"><?php echo $esc($inv['school_name']); ?></div><?php endif; ?>
                <?php if (!empty($inv['class_name'])): ?><div class="small text-muted"><?php echo $esc($inv['class_name']); ?></div><?php endif; ?>
              </td>
              <td>₹ <?php echo number_format((float)($useFeesRecords ? ($inv['amount'] ?? 0) : ($inv['amount'] ?? 0)),2); ?></td>
              <td><?php echo $useFeesRecords ? ('₹ ' . number_format((float)($inv['paid_amount'] ?? 0),2)) : $esc($inv['due_date'] ?? '—'); ?></td>
              <td>
                <span class="badge <?php
                    $st = strtolower((string)($useFeesRecords ? ($inv['status'] ?? '') : ($inv['status'] ?? '')));
                    echo ($st === 'paid' ? 'bg-success' : ($st === 'overdue' ? 'bg-danger' : 'bg-secondary'));
                ?>">
                  <?php echo $esc(ucfirst((string)(($inv['status'] ?? '')))); ?>
                </span>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal" data-id="<?php echo (int)$inv['id']; ?>">View</button>
                <?php if ($useFeesRecords): ?>
                  <a class="btn btn-sm btn-outline-secondary" href="/accounts/receipt_print.php?id=<?php echo (int)$inv['id']; ?>" target="_blank">Print</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-outline-secondary" href="/accounts/invoice_print.php?id=<?php echo (int)$inv['id']; ?>" target="_blank">Print</a>
                <?php endif; ?>

                <!-- Open Receipts (fees_collection) prefiltered -->
                <a class="btn btn-sm btn-outline-primary" href="/demopreschoolapp/accounts/fees_collection.php?<?php
                    $rqs = [];
                    if (!empty($inv['student_id'])) $rqs['student_id'] = (int)$inv['student_id'];
                    if (!empty($inv['class_id'])) $rqs['class_id'] = (int)$inv['class_id'];
                    if (!empty($inv['school_id'])) $rqs['school_id'] = (int)$inv['school_id'];
                    echo http_build_query($rqs);
                ?>" target="_blank">Open Receipts</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3 d-flex justify-content-between align-items-center">
      <div>Showing <?php echo $total ? ($offset+1) : 0; ?> - <?php echo min($total, $offset + count($invoices)); ?> of <?php echo $total; ?></div>
      <nav>
        <ul class="pagination mb-0">
          <?php for ($p = 1; $p <= max(1, $totalPages); $p++): ?>
            <li class="page-item <?php if ($p === $page) echo 'active'; ?>"><a class="page-link" href="?<?php echo build_qs(['page'=>$p]); ?>"><?php echo $p; ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  </div>

</div>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><?php echo $useFeesRecords ? 'Receipt Details' : 'Invoice Details'; ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="viewModalBody"><div class="text-center text-muted">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var viewModal = document.getElementById('viewModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function (event) {
      var id = event.relatedTarget.getAttribute('data-id');
      var body = document.getElementById('viewModalBody');
      body.innerHTML = '<div class="text-center text-muted">Loading…</div>';
      fetch('?action=view&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function(resp){ return resp.ok ? resp.text() : Promise.reject(); })
        .then(function(html){ body.innerHTML = html; })
        .catch(function(){ body.innerHTML = '<div class="text-danger">Failed to load details.</div>'; });
    });
  }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>