<?php
/**
 * Owner — select a parent to preview the parent portal (children, fees, notices).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
$DEBUG = panel_debug();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['parent_id'] ?? 0);
    if ($pid > 0) {
        $_SESSION['owner_view_parent_id'] = $pid;
        $target = $_POST['redirect'] ?? 'children';
        $map = [
            'children' => '/parent/children.php',
            'fees' => '/parent/fees.php',
            'attendance' => '/parent/attendance.php',
            'homeworks' => '/parent/homeworks.php',
            'notices' => '/parent/notices.php',
            'receipt' => '/parent/receipt.php',
            'events' => '/parent/events.php',
            'gallery' => '/parent/gallery.php',
        ];
        $path = $map[$target] ?? '/parent/children.php';
        $url = function_exists('site_url') ? site_url($path) : $path;
        header('Location: ' . $url);
        exit;
    }
}

if (!empty($_GET['clear'])) {
    unset($_SESSION['owner_view_parent_id']);
}

$parents = safe_db_get_all(
    "SELECT id, name, phone FROM users WHERE role = 'parent' AND is_active = 1 ORDER BY name ASC LIMIT 500"
) ?: [];

$selectedId = (int) ($_SESSION['owner_view_parent_id'] ?? 0);
$selectedName = '';
if ($selectedId > 0) {
    $row = safe_db_get_one("SELECT name FROM users WHERE id = :id AND role = 'parent' LIMIT 1", [':id' => $selectedId]);
    $selectedName = (string) ($row['name'] ?? '');
}

$pageTitle = 'Parent Portal Hub';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => (function_exists('site_url') ? site_url('/owner/dashboard.php') : '/owner/dashboard.php')],
    ['label' => 'Parent Hub'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card metric-card h-100">
      <div class="card-body">
        <h5 class="fw-bold mb-3"><i class="bi bi-eye me-2 text-primary"></i>View as Parent</h5>
        <p class="small-muted">Select a parent to open their portal — children, fees, homework and notices — exactly as they see on mobile.</p>

        <form method="post" class="mt-3">
          <label class="form-label fw-semibold">Parent</label>
          <select name="parent_id" class="form-select mb-3" required>
            <option value="">— Select parent —</option>
            <?php foreach ($parents as $p): ?>
              <option value="<?php echo (int) $p['id']; ?>"<?php echo $selectedId === (int) $p['id'] ? ' selected' : ''; ?>>
                <?php echo e((string) $p['name']); ?> (<?php echo e((string) $p['phone']); ?>)
              </option>
            <?php endforeach; ?>
          </select>

          <label class="form-label fw-semibold">Open page</label>
          <select name="redirect" class="form-select mb-3">
            <option value="children">My Children</option>
            <option value="fees">Fees</option>
            <option value="attendance">Attendance</option>
            <option value="homeworks">Homework</option>
            <option value="notices">Notices</option>
            <option value="receipt">Receipts</option>
            <option value="events">Events</option>
            <option value="gallery">Gallery</option>
          </select>

          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-up-right me-1"></i>Open Parent View</button>
        </form>

        <?php if ($selectedId > 0): ?>
          <div class="alert alert-info mt-3 mb-0 small">
            Currently viewing as: <strong><?php echo e($selectedName); ?></strong>
            <a href="?clear=1" class="ms-2">Clear</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card metric-card h-100">
      <div class="card-body">
        <h5 class="fw-bold mb-3">Quick links</h5>
        <div class="row g-2">
          <?php
          $base = function_exists('site_url') ? rtrim(site_url('/parent'), '/') : '/parent';
          $links = [
              ['children.php', 'bi-people', 'My Children'],
              ['fees.php', 'bi-cash-stack', 'Fees'],
              ['attendance.php', 'bi-calendar-check', 'Attendance'],
              ['homeworks.php', 'bi-journal-text', 'Homework'],
              ['notices.php', 'bi-megaphone', 'Notices'],
              ['receipt.php', 'bi-receipt', 'Receipts'],
          ];
          foreach ($links as [$file, $icon, $label]):
          ?>
            <div class="col-sm-6">
              <a class="btn quick-btn w-100" href="<?php echo e($base . '/' . $file); ?>">
                <i class="bi <?php echo e($icon); ?> me-2"></i><?php echo e($label); ?>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="small-muted mt-3 mb-0">Tip: select a parent first if the page shows no children.</p>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
