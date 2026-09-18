<?php
/**
 * Set yearly class fees used on new admission. Classes themselves live in Class Setup.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');

$page_title = 'Fees Setup';
$pageTitle = $page_title;
$page_desc = 'Yearly fee for each class. New admission picks this amount automatically.';

$selfUrl = function_exists('site_url') ? site_url('/owner/fees_setup.php') : 'fees_setup.php';
$classSetupUrl = function_exists('site_url') ? site_url('/owner/class_setup.php') : 'class_setup.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = function_exists('get_csrf_token') ? get_csrf_token() : (string) $_SESSION['csrf_token'];

$hasClasses = function_exists('table_exists') && table_exists('classes');
$hasStudents = function_exists('table_exists') && table_exists('students');
$hasTotalFees = $hasStudents && function_exists('column_exists') && column_exists('students', 'total_fees');

$csrfOk = static function (string $token) use ($CSRF): bool {
    return $token !== '' && hash_equals($CSRF, $token);
};

$studentStatusSql = "LOWER(COALESCE(s.status,'active')) IN ('active','pending')";

if ($hasClasses && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!$csrfOk($token)) {
        set_flash('error', 'Could not save. Refresh the page and try again.');
        header('Location: ' . $selfUrl);
        exit;
    }

    if ($action === 'save') {
        $feesIn = $_POST['fees'] ?? [];
        $fillEmpty = $_POST['fill_empty'] ?? [];
        if (!is_array($feesIn)) {
            $feesIn = [];
        }
        $saved = 0;
        $filled = 0;
        foreach ($feesIn as $idRaw => $feeRaw) {
            $id = (int) $idRaw;
            if ($id <= 0) {
                continue;
            }
            $fee = (float) str_replace([',', ' '], '', (string) $feeRaw);
            if ($fee < 0) {
                $fee = 0;
            }
            $ok = safe_db_run(
                'UPDATE classes SET fees = :fees, updated_at = NOW() WHERE id = :id',
                [':fees' => $fee, ':id' => $id]
            );
            if ($ok) {
                $saved++;
            }
            if ($hasTotalFees && !empty($fillEmpty[$id])) {
                $n = 0;
                try {
                    $pdo = pdo_connect();
                    if ($pdo instanceof PDO) {
                        $st = $pdo->prepare(
                            "UPDATE students s
                             SET s.total_fees = :fees, s.updated_at = NOW()
                             WHERE s.class_id = :id
                               AND {$studentStatusSql}
                               AND COALESCE(s.total_fees,0) = 0"
                        );
                        $st->execute([':fees' => $fee, ':id' => $id]);
                        $n = $st->rowCount();
                    }
                } catch (Throwable $e) {
                    $n = 0;
                }
                $filled += max(0, $n);
            }
        }
        $msg = $saved > 0 ? 'Class fees saved.' : 'Nothing to save.';
        if ($filled > 0) {
            $msg .= ' Set fee on ' . $filled . ' child' . ($filled === 1 ? '' : 'ren') . ' who had ₹0.';
        }
        set_flash('success', $msg);
        header('Location: ' . $selfUrl);
        exit;
    }

    if ($action === 'apply_all' && $hasTotalFees) {
        $id = (int) ($_POST['class_id'] ?? 0);
        if ($id <= 0) {
            set_flash('error', 'Choose a class.');
            header('Location: ' . $selfUrl);
            exit;
        }
        $cls = safe_db_get_one('SELECT id, name, COALESCE(fees,0) AS fees FROM classes WHERE id = :id LIMIT 1', [':id' => $id]);
        if (!$cls) {
            set_flash('error', 'Class not found. Save fees first.');
            header('Location: ' . $selfUrl);
            exit;
        }
        $fee = (float) $cls['fees'];
        $n = 0;
        try {
            $pdo = pdo_connect();
            if ($pdo instanceof PDO) {
                $st = $pdo->prepare(
                    "UPDATE students s
                     SET s.total_fees = :fees, s.updated_at = NOW()
                     WHERE s.class_id = :id
                       AND {$studentStatusSql}"
                );
                $st->execute([':fees' => $fee, ':id' => $id]);
                $n = $st->rowCount();
            }
        } catch (Throwable $e) {
            $n = 0;
        }
        set_flash('success', 'Updated fee for ' . $n . ' child' . ($n === 1 ? '' : 'ren') . ' in this class.');
        header('Location: ' . $selfUrl);
        exit;
    }
}

$rows = [];
if ($hasClasses) {
    $kidsSql = $hasStudents
        ? "(SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND {$studentStatusSql})"
        : '0';
    $emptySql = ($hasStudents && $hasTotalFees)
        ? "(SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND {$studentStatusSql} AND COALESCE(s.total_fees,0) = 0)"
        : '0';
    $rows = safe_db_get_all(
        "SELECT c.id, c.name, c.age_group, COALESCE(c.fees,0) AS fees,
                {$kidsSql} AS kids,
                {$emptySql} AS kids_no_fee
         FROM classes c
         ORDER BY c.name ASC"
    ) ?: [];
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.fs-card { border: 0; border-radius: 12px; box-shadow: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.1); }
.fs-fee { font-size: 1.15rem; font-weight: 600; }
.fs-hint { font-size: .85rem; color: #64748b; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Class fees</h1>
    <p class="text-muted mb-0">Set the yearly amount for each class. New admission fills this in automatically. Change a child’s fee later on their student page.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?php echo e($classSetupUrl); ?>">Manage classes</a>
</div>

<?php if (!$hasClasses): ?>
  <div class="alert alert-danger">Classes table is missing. Ask support to check the database.</div>
<?php elseif ($rows === []): ?>
  <div class="alert alert-info">No classes yet. Add Playgroup, Nursery, LKG… on <a href="<?php echo e($classSetupUrl); ?>">Class Setup</a>, then come back here to set fees.</div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
  <input type="hidden" name="action" value="save">

  <div class="row g-3 mb-3">
    <?php foreach ($rows as $r):
        $id = (int) $r['id'];
        $fee = (float) $r['fees'];
        $kids = (int) $r['kids'];
        $empty = (int) $r['kids_no_fee'];
        $perMonth = $fee > 0 ? $fee / 12 : 0;
    ?>
    <div class="col-md-6 col-xl-4">
      <div class="card fs-card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <div class="fw-semibold"><?php echo e((string) $r['name']); ?></div>
              <div class="fs-hint">
                <?php echo e(trim((string) ($r['age_group'] ?? '')) !== '' ? (string) $r['age_group'] : 'Age group not set'); ?>
                · <?php echo $kids; ?> child<?php echo $kids === 1 ? '' : 'ren'; ?>
              </div>
            </div>
          </div>
          <label class="form-label mb-1">Yearly fee (₹)</label>
          <input class="form-control fs-fee" type="number" name="fees[<?php echo $id; ?>]" min="0" step="1" value="<?php echo e((string) (int) round($fee)); ?>" inputmode="numeric">
          <div class="fs-hint mt-1">About ₹ <?php echo number_format($perMonth, 0); ?> per month if paid in 12 parts.</div>
          <?php if ($hasTotalFees && $empty > 0): ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="fill_empty[<?php echo $id; ?>]" value="1" id="fill<?php echo $id; ?>">
              <label class="form-check-label small" for="fill<?php echo $id; ?>">
                Also set this on <?php echo $empty; ?> child<?php echo $empty === 1 ? '' : 'ren'; ?> who still have ₹0
              </label>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <button class="btn btn-primary btn-lg" type="submit">Save fees</button>
  </div>
</form>

<?php if ($hasTotalFees): ?>
  <details class="mb-4">
    <summary class="text-muted">Need to overwrite every child’s fee in a class?</summary>
    <p class="small text-muted mt-2 mb-3">Use this only if you meant to change the fee for children already admitted. It replaces their current total fee.</p>
    <div class="row g-2">
      <?php foreach ($rows as $r):
          $id = (int) $r['id'];
          $kids = (int) $r['kids'];
          if ($kids < 1) {
              continue;
          }
      ?>
      <div class="col-md-6 col-xl-4">
        <form method="post" class="d-flex gap-2 align-items-center" onsubmit="return confirm('Use the saved yearly fee for <?php echo e((string) $r['name']); ?> on all <?php echo $kids; ?> children? Save fees first if you just typed a new amount.');">
          <input type="hidden" name="csrf_token" value="<?php echo e($CSRF); ?>">
          <input type="hidden" name="action" value="apply_all">
          <input type="hidden" name="class_id" value="<?php echo $id; ?>">
          <button class="btn btn-sm btn-outline-warning" type="submit">Set on all in <?php echo e((string) $r['name']); ?> (<?php echo $kids; ?>)</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
