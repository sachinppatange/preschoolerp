<?php
/**
 * owner/class_setup.php
 *
 * Manage classes offered by the school (CRUD, search, filter, export).
 *
 * Table: classes
 * Columns:
 *   id (PK), school_id, name, age_group, fees, created_at, updated_at
 *
 * Place at: /pioneerplayschool01/owner/class_setup.php
 *
 * Notes:
 * - Requires session auth key: $_SESSION['owner_auth_user']
 * - Loads optional includes from ../includes (config.php, db.php, functions.php)
 * - Uses robust PDO helpers (pdo_connect / safe_db_* wrappers) similar to other owner pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

$esc = function(string $v) { return e($v); };

function table_exists(string $name): bool {
    try {
        $r = safe_db_get_one("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t", [':t'=>$name]);
        return !empty($r) && intval($r['cnt']) > 0;
    } catch (Throwable $e) { return false; }
}

/* -------------------------
   Ensure classes table exists
   ------------------------- */
if (!table_exists('classes')) {
    require_once __DIR__ . '/../includes/header.php';
echo '<div class="container py-4"><div class="alert alert-danger">The <strong>classes</strong> table does not exist. कृपया डेटाबेस तपासा.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* -------------------------
   Actions: list (default), add (POST), edit (POST), get (AJAX), delete, export
   ------------------------- */
$action = $_REQUEST['action'] ?? 'list';
$errors = [];
$messages = [];

/* ADD (POST) */
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $fees = trim((string)($_POST['fees'] ?? ''));
    $fees_val = $fees === '' ? null : (float)str_replace(',', '', $fees);

    if ($name === '') $errors[] = 'Class name is required.';
    if ($fees !== '' && !is_numeric($fees_val)) $errors[] = 'Fees must be a number.';

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO classes (school_id, name, age_group, fees, created_at, updated_at)
                    VALUES (:school_id, :name, :age_group, :fees, NOW(), NOW())";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':name' => $name,
                ':age_group' => $age_group !== '' ? $age_group : null,
                ':fees' => $fees_val !== null ? $fees_val : 0.00
            ]);
            if ($ok) {
                $messages[] = 'Class added successfully.';
                header('Location: ?'); exit;
            } else {
                $errors[] = 'Failed to add class.';
            }
        } catch (Throwable $e) {
            error_log('class add error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to add class.';
        }
    }
}

/* GET (AJAX) - fetch single record for edit */
if ($action === 'get' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    if ($id <= 0) { echo json_encode(['error'=>'Invalid id']); exit; }
    $row = safe_db_get_one("SELECT id, school_id, name, age_group, fees FROM classes WHERE id = :id LIMIT 1", [':id'=>$id]);
    if (!$row) { echo json_encode(['error'=>'Class not found']); exit; }
    echo json_encode(['ok' => true, 'data' => $row]);
    exit;
}

/* EDIT (POST) */
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $school_id = isset($_POST['school_id']) && $_POST['school_id'] !== '' ? (int)$_POST['school_id'] : 1;
    $name = trim((string)($_POST['name'] ?? ''));
    $age_group = trim((string)($_POST['age_group'] ?? ''));
    $fees = trim((string)($_POST['fees'] ?? ''));
    $fees_val = $fees === '' ? null : (float)str_replace(',', '', $fees);

    if ($id <= 0) $errors[] = 'Invalid class id.';
    if ($name === '') $errors[] = 'Class name is required.';
    if ($fees !== '' && !is_numeric($fees_val)) $errors[] = 'Fees must be a number.';

    if (empty($errors)) {
        try {
            $sql = "UPDATE classes SET school_id = :school_id, name = :name, age_group = :age_group, fees = :fees, updated_at = NOW() WHERE id = :id";
            $ok = safe_db_run($sql, [
                ':school_id' => $school_id,
                ':name' => $name,
                ':age_group' => $age_group !== '' ? $age_group : null,
                ':fees' => $fees_val !== null ? $fees_val : 0.00,
                ':id' => $id
            ]);
            if ($ok) {
                $messages[] = 'Class updated successfully.';
                header('Location: ?'); exit;
            } else {
                $errors[] = 'Failed to update class.';
            }
        } catch (Throwable $e) {
            error_log('class edit error: ' . $e->getMessage());
            $errors[] = $DEBUG ? 'DB error: ' . $e->getMessage() : 'Failed to update class.';
        }
    }
}

/* DELETE (GET) */
/* BACKUP: Phase-A — delete requires POST + CSRF (was unsafe GET) */
if (function_exists('secure_delete_blocked_get') && secure_delete_blocked_get($action)) {
    if (isset($errors) && is_array($errors)) { $errors[] = 'Delete requires confirmation (POST).'; }
    elseif (isset($messages) && is_array($messages)) { $messages[] = 'Delete requires confirmation (POST).'; }
}
$deleteId = function_exists('secure_delete_id') ? secure_delete_id() : 0;
if ($deleteId > 0) {
    $id = $deleteId;
    if ($id > 0) {
        $ok = safe_db_run("DELETE FROM classes WHERE id = :id", [':id'=>$id]);
        if ($ok) $messages[] = 'Class deleted.';
        else $errors[] = 'Failed to delete class.';
    } else $errors[] = 'Invalid id.';
}

/* EXPORT CSV */
if ($action === 'export') {
    $where = []; $params = [];
    if (!empty($_GET['q'])) { $where[] = "(name LIKE :q OR age_group LIKE :q)"; $params[':q'] = '%'.trim($_GET['q']).'%'; }
    if (!empty($_GET['min_fees'])) { $where[] = "fees >= :min_fees"; $params[':min_fees'] = (float)$_GET['min_fees']; }
    if (!empty($_GET['max_fees'])) { $where[] = "fees <= :max_fees"; $params[':max_fees'] = (float)$_GET['max_fees']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $rows = safe_db_get_all("SELECT id, school_id, name, age_group, fees, created_at, updated_at FROM classes $whereSql ORDER BY name ASC", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=classes_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','School ID','Name','Age Group','Fees','Created At','Updated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['school_id'] ?? '',
            $r['name'] ?? '',
            $r['age_group'] ?? '',
            isset($r['fees']) ? number_format((float)$r['fees'], 2, '.', '') : '',
            $r['created_at'] ?? '',
            $r['updated_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

/* -------------------------
   List: filters, pagination
   ------------------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = []; $params = [];
$qraw = trim((string)($_GET['q'] ?? ''));
if ($qraw !== '') { $where[] = "(c.name LIKE :q OR c.age_group LIKE :q)"; $params[':q'] = '%' . $qraw . '%'; }
if (isset($_GET['min_fees']) && $_GET['min_fees'] !== '') { $where[] = "c.fees >= :min_fees"; $params[':min_fees'] = (float)$_GET['min_fees']; }
if (isset($_GET['max_fees']) && $_GET['max_fees'] !== '') { $where[] = "c.fees <= :max_fees"; $params[':max_fees'] = (float)$_GET['max_fees']; }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cRow = safe_db_get_one("SELECT COUNT(*) AS c FROM classes c " . ($whereSql ? $whereSql : ''), $params);
    $total = intval($cRow['c'] ?? 0);
} catch (Throwable $e) {
    $total = 0;
    $errors[] = 'Count query failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$classes = [];
try {
    $sql = "SELECT c.id, c.school_id, c.name, c.age_group, c.fees, c.created_at, c.updated_at
            FROM classes c
            $whereSql
            ORDER BY c.name ASC
            LIMIT :limit OFFSET :offset";
    $pdo = pdo_connect();
    if ($pdo instanceof \PDO) {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', (int)$perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $classes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } else {
        $classes = safe_db_get_all($sql, array_merge($params, [':limit'=>$perPage, ':offset'=>$offset]));
    }
} catch (Throwable $e) {
    $errors[] = 'List fetch failed: ' . ($DEBUG ? $e->getMessage() : 'Internal error');
}

$totalPages = (int)ceil(max(0, $total) / $perPage);

$stats = ['cnt' => 0, 'min_fees' => 0, 'max_fees' => 0, 'avg_fees' => 0];
try {
    $statsRow = safe_db_get_one(
        "SELECT COUNT(*) AS cnt, COALESCE(MIN(fees),0) AS min_fees, COALESCE(MAX(fees),0) AS max_fees, COALESCE(AVG(fees),0) AS avg_fees FROM classes"
    );
    if (is_array($statsRow)) {
        $stats = array_merge($stats, $statsRow);
    }
} catch (Throwable $e) {
    // non-fatal
}

$hasFilters = $qraw !== '' || (isset($_GET['min_fees']) && $_GET['min_fees'] !== '') || (isset($_GET['max_fees']) && $_GET['max_fees'] !== '');
$ownerBase = function_exists('site_url') ? rtrim(site_url('/owner'), '/') : '/owner';

function build_qs(array $over = []): string {
    $qs = $_GET;
    foreach ($over as $k=>$v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return http_build_query($qs);
}

function format_class_fee($value): string {
    $amount = (float) $value;
    return '₹' . number_format($amount, $amount == floor($amount) ? 0 : 2);
}

/* -------------------------
   Render (header/footer if available)
   ------------------------- */
$pageTitle = 'Class Setup';
$page_desc = 'Manage classes, age groups and annual fees for admissions and billing.';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => $ownerBase . '/dashboard.php'],
    ['label' => 'Class Setup'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card metric-card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="metric-icon primary"><i class="bi bi-mortarboard"></i></div>
        <div>
          <div class="small-muted">Total Classes</div>
          <div class="fs-4 fw-bold mb-0"><?php echo (int) ($stats['cnt'] ?? 0); ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card metric-card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="metric-icon success"><i class="bi bi-cash-stack"></i></div>
        <div>
          <div class="small-muted">Average Fee</div>
          <div class="fs-4 fw-bold mb-0"><?php echo format_class_fee($stats['avg_fees'] ?? 0); ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card metric-card h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="metric-icon warning"><i class="bi bi-graph-up-arrow"></i></div>
        <div>
          <div class="small-muted">Fee Range</div>
          <div class="fs-5 fw-bold mb-0"><?php echo format_class_fee($stats['min_fees'] ?? 0); ?> – <?php echo format_class_fee($stats['max_fees'] ?? 0); ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="panel-toolbar">
  <p class="panel-page-lead">Classes are used in admission forms, student records and fee collection.</p>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?action=export&amp;<?php echo $esc(build_qs()); ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="bi bi-plus-lg me-1"></i>Add Class</button>
  </div>
</div>

<?php foreach ($messages as $m): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo $esc($m); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo $esc($err); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endforeach; ?>

<div class="panel-filter-card">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label small fw-semibold mb-1">Search class</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input name="q" class="form-control" value="<?php echo $esc($qraw); ?>" placeholder="e.g. Nursery, Playgroup, 3-4 Years">
        </div>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Min fee (₹)</label>
        <input name="min_fees" type="number" min="0" step="100" class="form-control" value="<?php echo $esc($_GET['min_fees'] ?? ''); ?>" placeholder="18000">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Max fee (₹)</label>
        <input name="max_fees" type="number" min="0" step="100" class="form-control" value="<?php echo $esc($_GET['max_fees'] ?? ''); ?>" placeholder="21000">
      </div>
      <div class="col-md-3 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply</button>
        <?php if ($hasFilters): ?>
          <a class="btn btn-outline-secondary" href="?" title="Clear filters"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!empty($classes)): ?>
  <div class="row g-3 mb-3">
    <?php foreach ($classes as $c):
        $fee = (float) ($c['fees'] ?? 0);
        $ageGroup = trim((string) ($c['age_group'] ?? ''));
    ?>
      <div class="col-md-6 col-xl-3">
        <article class="class-card">
          <div class="card-body pb-2">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <h5 class="fw-bold mb-0"><?php echo $esc((string) $c['name']); ?></h5>
              <span class="badge text-bg-light border">#<?php echo (int) $c['id']; ?></span>
            </div>
            <?php if ($ageGroup !== ''): ?>
              <div class="class-meta mb-2"><i class="bi bi-people me-1"></i><?php echo $esc($ageGroup); ?></div>
            <?php else: ?>
              <div class="class-meta mb-2 text-muted"><i class="bi bi-people me-1"></i>Age group not set</div>
            <?php endif; ?>
            <div class="class-fee"><?php echo format_class_fee($fee); ?></div>
            <div class="class-meta">Annual fee</div>
          </div>
          <div class="class-card-actions">
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editClassModal" data-id="<?php echo (int) $c['id']; ?>"><i class="bi bi-pencil me-1"></i>Edit</button>
            <?php echo render_secure_delete_button((int) $c['id'], 'Delete', 'Delete this class? Students linked to it may need reassignment.', 'btn btn-sm btn-outline-danger', []); ?>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small-muted">
    <div>Showing <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($total, $offset + count($classes)); ?> of <?php echo $total; ?> classes</div>
    <?php if ($totalPages > 1): ?>
      <nav aria-label="Class pagination">
        <ul class="pagination pagination-sm mb-0">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>">
              <a class="page-link" href="?<?php echo $esc(build_qs(['page' => $p])); ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="panel-empty-state">
    <i class="bi bi-mortarboard d-block"></i>
    <h5 class="fw-bold mb-2"><?php echo $hasFilters ? 'No classes match your filters' : 'No classes yet'; ?></h5>
    <p class="small-muted mb-3"><?php echo $hasFilters ? 'Try a different search or clear filters.' : 'Add Playgroup, Nursery, L.K.G. and U.K.G. to get started.'; ?></p>
    <?php if ($hasFilters): ?>
      <a class="btn btn-outline-secondary btn-sm" href="?">Clear filters</a>
    <?php else: ?>
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="bi bi-plus-lg me-1"></i>Add first class</button>
    <?php endif; ?>
  </div>
<?php endif; ?>

<datalist id="ageGroupOptions">
  <option value="2-3 Years"></option>
  <option value="3-4 Years"></option>
  <option value="4-5 Years"></option>
  <option value="5-6 Years"></option>
</datalist>

<!-- Add Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2 text-primary"></i>Add Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body pt-2">
          <div class="mb-3">
            <label class="form-label fw-semibold">Class name <span class="text-danger">*</span></label>
            <input name="name" class="form-control form-control-lg" placeholder="e.g. Nursery" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Age group</label>
            <input name="age_group" class="form-control" list="ageGroupOptions" placeholder="e.g. 3-4 Years">
            <div class="form-text">Used on admission forms and public website.</div>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Annual fee (₹)</label>
            <div class="input-group">
              <span class="input-group-text">₹</span>
              <input name="fees" type="number" min="0" step="100" class="form-control" placeholder="18000">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Save Class</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Class Modal -->
<div class="modal fade" id="editClassModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="?action=edit" id="editClassForm">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body pt-2">
          <input type="hidden" name="id" id="edit_id">
          <div class="mb-3">
            <label class="form-label fw-semibold">Class name <span class="text-danger">*</span></label>
            <input name="name" id="edit_name" class="form-control form-control-lg" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Age group</label>
            <input name="age_group" id="edit_age_group" class="form-control" list="ageGroupOptions">
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Annual fee (₹)</label>
            <div class="input-group">
              <span class="input-group-text">₹</span>
              <input name="fees" id="edit_fees" type="number" min="0" step="100" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Update Class</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const editModal = document.getElementById('editClassModal');
  if (!editModal) return;

  editModal.addEventListener('show.bs.modal', function (event) {
    const trigger = event.relatedTarget;
    const id = trigger ? trigger.getAttribute('data-id') : '';
    const fields = ['edit_id', 'edit_name', 'edit_age_group', 'edit_fees'];
    fields.forEach(function (fieldId) {
      const el = document.getElementById(fieldId);
      if (el) el.value = '';
    });

    if (!id) return;

    fetch('?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (resp) { return resp.ok ? resp.json() : Promise.reject(); })
      .then(function (json) {
        if (!json || !json.ok || !json.data) {
          throw new Error(json && json.error ? json.error : 'Load failed');
        }
        const d = json.data;
        document.getElementById('edit_id').value = d.id || '';
        document.getElementById('edit_name').value = d.name || '';
        document.getElementById('edit_age_group').value = d.age_group || '';
        document.getElementById('edit_fees').value = (d.fees !== null && d.fees !== undefined) ? d.fees : '';
      })
      .catch(function (err) {
        alert(err && err.message ? err.message : 'Failed to load class for edit.');
        const mdl = bootstrap.Modal.getInstance(editModal);
        if (mdl) mdl.hide();
      });
  });
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>