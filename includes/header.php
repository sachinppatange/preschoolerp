<?php
/**
 * includes/header.php — Panel + legacy page header.
 * Auto-detects role panels (owner/teacher/parent/reception/accounts).
 * Login pages should use includes/login_shell.php.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/flash.php';
if (!function_exists('resolve_image_url') && file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}
require_once __DIR__ . '/panel/helpers.php';

if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('ay_selected') && file_exists(__DIR__ . '/panel/academic_year.php')) {
    require_once __DIR__ . '/panel/academic_year.php';
}

$script = $_SERVER['SCRIPT_NAME'] ?? '';
$panelRole = panel_detect_role($script, $panel_role ?? null);
if (function_exists('panel_shell_role')) {
    $panelRole = panel_shell_role($panelRole);
}
$is_panel = $panelRole !== null;
$GLOBALS['panelRole'] = $panelRole;
$ownerSuperView = function_exists('auth_is_owner_super') && auth_is_owner_super()
    && !str_contains($script, '/owner/');

if (empty($page_title) && !empty($pageTitle)) {
    $page_title = (string) $pageTitle;
}
if (empty($page_title)) {
    $page_title = defined('APP_NAME') ? APP_NAME : 'Website';
}

$base = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/');
$appName = defined('APP_NAME') ? APP_NAME : 'Preschool App';
$panelLogoUrl = function_exists('resolve_image_url') ? resolve_image_url('assets/images/logo.png') : $base . '/assets/images/logo.png';
$panelLogoFallback = function_exists('resolve_image_url') ? resolve_image_url('assets/images/default-logo.png') : $base . '/assets/images/default-logo.png';

function render_breadcrumbs(array $breadcrumbs = []): void
{
    if (empty($breadcrumbs)) {
        return;
    }
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">';
    $lastIdx = count($breadcrumbs) - 1;
    foreach ($breadcrumbs as $i => $b) {
        $label = htmlspecialchars($b['label'] ?? '');
        $url = $b['url'] ?? null;
        if ($i === $lastIdx) {
            echo '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
        } elseif (!empty($url)) {
            echo '<li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . $label . '</a></li>';
        } else {
            echo '<li class="breadcrumb-item">' . $label . '</li>';
        }
    }
    echo '</ol></nav>';
}

function render_flashes(): void
{
    $flashes = get_flashes();
    if (empty($flashes)) {
        return;
    }
    foreach ($flashes as $f) {
        $type = $f['type'] ?? 'info';
        $msg = $f['msg'] ?? '';
        $cls = match ($type) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        echo '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($msg);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($page_desc ?? ''); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?php echo htmlspecialchars($base); ?>/assets/css/tokens.css" rel="stylesheet">
  <link href="<?php echo htmlspecialchars($base); ?>/assets/css/main.css" rel="stylesheet">
  <link href="<?php echo htmlspecialchars($base); ?>/assets/css/responsive.css" rel="stylesheet">
<?php if ($is_panel): ?>
  <link href="<?php echo htmlspecialchars($base); ?>/assets/css/panel.css" rel="stylesheet">
<?php endif; ?>
  <link rel="icon" href="<?php echo htmlspecialchars($base); ?>/assets/images/favicon.ico" type="image/x-icon">
</head>
<body class="<?php echo $is_panel ? 'panel-body' : ''; ?>">

<?php if ($is_panel):
    $meta = panel_role_meta($panelRole);
    if ($meta === null) {
        $is_panel = false;
    } else {
        $menuFile = __DIR__ . '/panel/' . $meta['slug'] . '_menu.php';
        if ($meta['slug'] === 'owner') {
            $menuFile = __DIR__ . '/panel/owner_menu.php';
        }
        require_once $menuFile;
        $menuFn = $meta['menu_fn'];
        $menu = $menuFn();
        $userName = function_exists('auth_user_name') ? auth_user_name($meta['user_default']) : $meta['user_default'];
        $initial = strtoupper(substr($userName, 0, 1));
        $roleBase = panel_base_url($meta['slug']);
        $logoutUrl = function_exists('site_url') ? site_url('/' . $meta['slug'] . '/logout.php') : $roleBase . '/logout.php';
        $homeUrl = function_exists('site_url') ? site_url('/') : $base . '/';
        $dashUrl = $roleBase . '/dashboard.php';
        $profileUrl = $roleBase . '/profile.php';
?>
<div class="panel-overlay panel-backdrop-close" id="panelOverlay" aria-hidden="true"></div>
<div class="panel-shell">
  <aside class="panel-sidebar" id="panelSidebar" aria-label="<?php echo htmlspecialchars($meta['label']); ?>">
    <div class="panel-sidebar-brand">
      <a href="<?php echo htmlspecialchars($dashUrl); ?>">
        <img src="<?php echo htmlspecialchars($panelLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?>" onerror="this.src='<?php echo htmlspecialchars($panelLogoFallback); ?>'">
      </a>
      <div>
        <div class="title"><?php echo htmlspecialchars($appName); ?></div>
        <div class="role"><?php echo htmlspecialchars($meta['label']); ?></div>
      </div>
    </div>
    <nav>
      <?php foreach ($menu as $section): ?>
        <div class="panel-nav-section"><?php echo htmlspecialchars($section['section']); ?></div>
        <?php foreach ($section['items'] as $item):
            $active = panel_nav_is_active($item['match'] ?? '', $script) ? ' active' : '';
        ?>
          <a class="panel-nav-link<?php echo $active; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
            <i class="bi <?php echo htmlspecialchars($item['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($item['label']); ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="panel-sidebar-footer">
      <a href="<?php echo htmlspecialchars($homeUrl); ?>"><i class="bi bi-house"></i> Public Website</a>
      <a href="<?php echo htmlspecialchars($logoutUrl); ?>"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
  </aside>
  <div class="panel-main">
    <header class="panel-topbar">
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="panel-toggle" id="panelToggle" aria-label="Toggle menu"><i class="bi bi-list fs-4"></i></button>
        <button type="button" class="panel-collapse-toggle d-none d-lg-inline-flex" id="panelCollapse" aria-label="Collapse sidebar" title="Collapse sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>
        <a href="<?php echo htmlspecialchars($dashUrl); ?>" class="panel-topbar-logo d-lg-none" aria-label="<?php echo htmlspecialchars($appName); ?>">
          <img src="<?php echo htmlspecialchars($panelLogoUrl); ?>" alt="<?php echo htmlspecialchars($appName); ?>" width="40" height="40" onerror="this.src='<?php echo htmlspecialchars($panelLogoFallback); ?>'">
        </a>
        <div class="panel-topbar-title">
          <h1><?php echo htmlspecialchars($page_title); ?></h1>
          <?php if (!empty($ownerSuperView)): ?>
            <div class="small text-primary fw-semibold"><i class="bi bi-shield-check me-1"></i>Owner access — all modules</div>
          <?php endif; ?>
          <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): render_breadcrumbs($breadcrumbs); endif; ?>
        </div>
      </div>
      <div class="panel-topbar-actions">
        <?php if (function_exists('render_panel_global_search')) {
            render_panel_global_search();
        } ?>
        <?php if (function_exists('render_panel_academic_year_selector')) {
            render_panel_academic_year_selector();
        } ?>
        <div class="panel-user-chip d-none d-sm-flex">
          <span class="avatar"><?php echo htmlspecialchars($initial); ?></span>
          <span><?php echo htmlspecialchars($userName); ?></span>
        </div>
        <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="btn btn-sm btn-outline-secondary" title="Settings"><i class="bi bi-gear"></i></a>
        <a href="<?php echo htmlspecialchars($logoutUrl); ?>" class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-right"></i></a>
      </div>
    </header>
    <main class="panel-content" id="main">
      <?php render_flashes(); ?>
      <?php if (empty($skip_panel_ay_banner) && function_exists('render_panel_academic_year_banner')) {
          render_panel_academic_year_banner();
      } ?>
      <?php if (function_exists('render_ay_lock_banner')) {
          render_ay_lock_banner();
      } ?>
<?php } endif; ?>

<?php if (!$is_panel): ?>
  <header class="site-header bg-white shadow-sm">
    <div class="container py-2">
      <a class="navbar-brand d-flex align-items-center text-decoration-none" href="<?php echo htmlspecialchars($base); ?>/">
        <img src="<?php echo htmlspecialchars($base); ?>/assets/images/logo.png" alt="<?php echo htmlspecialchars($appName); ?>" style="height:56px;width:auto" class="me-2">
        <span class="fw-bold text-dark"><?php echo htmlspecialchars($appName); ?></span>
      </a>
    </div>
    <div class="container">
      <?php render_flashes(); ?>
      <?php if (!empty($breadcrumbs) && is_array($breadcrumbs)): ?>
        <div class="mt-2 mb-3"><?php render_breadcrumbs($breadcrumbs); ?></div>
      <?php endif; ?>
    </div>
  </header>
  <main id="main" class="site-main">
  <div class="container py-4">
<?php endif; ?>
