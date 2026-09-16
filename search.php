<?php
/**
 * Global search — full results page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/panel/bootstrap.php';
panel_bootstrap(null, ['skip_auth' => true]);

$role = function_exists('auth_role') ? auth_role() : 'owner';
if (!auth_is_logged_in(['owner', 'teacher', 'reception', 'accounts', 'staff'])) {
    header('Location: ' . (function_exists('auth_login_url') ? auth_login_url($role) : '/owner/login.php'));
    exit;
}

require_once __DIR__ . '/includes/panel/global_search.php';
$panel_role = function_exists('panel_shell_role') ? panel_shell_role($role) : $role;
if (!panel_search_enabled($panel_role)) {
    $panel_role = 'owner';
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? panel_global_search($q, 25) : [];

$grouped = ['student' => [], 'parent' => [], 'fee' => [], 'enquiry' => []];
foreach ($results as $r) {
    $t = $r['type'] ?? 'other';
    if (!isset($grouped[$t])) {
        $grouped[$t] = [];
    }
    $grouped[$t][] = $r;
}

$labels = [
    'student' => 'Students',
    'parent' => 'Parents',
    'fee' => 'Fee Receipts',
    'enquiry' => 'Enquiries',
];

$page_title = $q !== '' ? 'Search: ' . $q : 'Search';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card metric-card mb-4">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-center">
      <div class="col-md-10">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" name="q" class="form-control form-control-lg" value="<?php echo e($q); ?>"
                 placeholder="Student name, form no, parent phone, receipt no…" autofocus>
        </div>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-lg w-100">Search</button>
      </div>
    </form>
    <p class="small text-muted mt-2 mb-0">Tip: Press <kbd>Ctrl+K</kbd> from any page to open quick search.</p>
  </div>
</div>

<?php if ($q === ''): ?>
  <div class="panel-empty-state">
    <i class="bi bi-search d-block"></i>
    <p class="mb-0">Type a name, phone number, form number or receipt to search across the school.</p>
  </div>
<?php elseif (empty($results)): ?>
  <div class="alert alert-warning">No results for <strong><?php echo e($q); ?></strong>. Try phone number or form no.</div>
<?php else: ?>
  <p class="text-muted small mb-3"><?php echo count($results); ?> result(s) for <strong><?php echo e($q); ?></strong></p>
  <?php foreach ($grouped as $type => $items):
      if (empty($items)) continue;
  ?>
    <div class="card metric-card mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi <?php echo e($items[0]['icon'] ?? 'bi-dot'); ?> me-1"></i><?php echo e($labels[$type] ?? ucfirst($type)); ?></h6>
        <div class="list-group list-group-flush">
          <?php foreach ($items as $item): ?>
            <a href="<?php echo e($item['url']); ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-0">
              <div>
                <div class="fw-semibold"><?php echo e($item['title']); ?></div>
                <div class="small text-muted"><?php echo e($item['subtitle']); ?></div>
              </div>
              <i class="bi bi-chevron-right text-muted"></i>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
