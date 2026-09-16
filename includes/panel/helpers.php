<?php
/**
 * Shared panel layout helpers.
 */
declare(strict_types=1);

/**
 * Detect active panel role from script path or explicit override.
 */
function panel_detect_role(string $script, ?string $override = null): ?string
{
    if ($override !== null && $override !== '') {
        return strtolower($override);
    }

    foreach (['owner', 'teacher', 'parent', 'reception', 'accounts'] as $role) {
        if (!str_contains($script, '/' . $role . '/')) {
            continue;
        }
        if (str_ends_with($script, '/' . $role . '/login.php') || str_ends_with($script, '/' . $role . '/logout.php')) {
            continue;
        }
        return $role;
    }

    return null;
}

/**
 * Check if nav item is active for current script.
 */
function panel_nav_is_active(string $match, string $script): bool
{
    if ($match === '') {
        return false;
    }
    $base = basename($script, '.php');
    if ($match === $base) {
        return true;
    }
    return str_contains($base, $match);
}

/**
 * Panel metadata per role (scalable registry).
 *
 * @return array{slug: string, label: string, menu_fn: string, user_default: string, accent?: string}|null
 */
function panel_role_meta(string $role): ?array
{
    $map = [
        'owner' => [
            'slug' => 'owner',
            'label' => 'Owner Panel',
            'menu_fn' => 'owner_panel_menu',
            'user_default' => 'Owner',
        ],
        'teacher' => [
            'slug' => 'teacher',
            'label' => 'Teacher Panel',
            'menu_fn' => 'teacher_panel_menu',
            'user_default' => 'Teacher',
        ],
        'parent' => [
            'slug' => 'parent',
            'label' => 'Parent Panel',
            'menu_fn' => 'parent_panel_menu',
            'user_default' => 'Parent',
        ],
        'reception' => [
            'slug' => 'reception',
            'label' => 'Reception Panel',
            'menu_fn' => 'reception_panel_menu',
            'user_default' => 'Reception',
        ],
        'accounts' => [
            'slug' => 'accounts',
            'label' => 'Accounts Panel',
            'menu_fn' => 'accounts_panel_menu',
            'user_default' => 'Accounts',
        ],
    ];

    return $map[$role] ?? null;
}

function panel_base_url(string $role): string
{
    if (function_exists('site_url')) {
        return rtrim(site_url('/' . $role), '/');
    }
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    return $base . '/' . $role;
}

if (!function_exists('auth_is_owner_super') && file_exists(__DIR__ . '/scope.php')) {
    require_once __DIR__ . '/scope.php';
}

/**
 * Floating quick-action menu (staff panels).
 */
function render_panel_quick_fab(?string $panelRole): void
{
    if (!in_array($panelRole, ['owner', 'reception', 'accounts', 'teacher'], true)) {
        return;
    }
    $root = function_exists('site_url') ? rtrim(site_url('/'), '/') : rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
    $actions = [
        ['url' => $root . '/reception/admission.php', 'icon' => 'bi-person-plus', 'label' => 'New Admission'],
        ['url' => $root . '/accounts/fees_collection.php', 'icon' => 'bi-cash-coin', 'label' => 'Collect Fee'],
        ['url' => $root . '/teacher/attendance_mark.php', 'icon' => 'bi-check2-square', 'label' => 'Mark Attendance'],
    ];
    ?>
    <div class="panel-quick-fab" id="panelQuickFab">
      <div class="panel-quick-fab-menu" id="panelQuickFabMenu" hidden>
        <?php foreach ($actions as $a): ?>
          <a href="<?php echo htmlspecialchars($a['url'], ENT_QUOTES, 'UTF-8'); ?>" class="panel-quick-fab-item">
            <i class="bi <?php echo htmlspecialchars($a['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            <span><?php echo htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8'); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <button type="button" class="panel-quick-fab-btn" id="panelQuickFabToggle" aria-label="Quick actions" aria-expanded="false">
        <i class="bi bi-plus-lg"></i>
      </button>
    </div>
    <?php
}
