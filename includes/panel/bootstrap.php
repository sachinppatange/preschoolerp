<?php
/**
 * includes/panel/bootstrap.php
 *
 * Single entry point for authenticated panel pages.
 *
 * Usage at top of owner/teacher/parent/reception/accounts pages:
 *   require_once __DIR__ . '/../includes/panel/bootstrap.php';
 *   panel_bootstrap('owner'); // or teacher|parent|reception|accounts
 *   $DEBUG = panel_debug();
 */
declare(strict_types=1);

/**
 * Bootstrap session, config, DB, helpers, and optional role auth.
 *
 * @param string|null $role owner|teacher|parent|reception|accounts|null
 * @param array<string,mixed> $options skip_auth => true to skip require_*_auth()
 */
function panel_bootstrap(?string $role = null, array $options = []): void
{
    $includesDir = dirname(__DIR__);

    if (file_exists($includesDir . '/session_start.php')) {
        require_once $includesDir . '/session_start.php';
    } elseif (function_exists('ensure_session_started')) {
        ensure_session_started();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once $includesDir . '/config.php';
    require_once $includesDir . '/db.php';
    require_once $includesDir . '/db_compat.php';

    require_once $includesDir . '/functions.php';

    require_once $includesDir . '/auth.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/scope.php';
    require_once __DIR__ . '/academic_year.php';
    require_once __DIR__ . '/ay_admin.php';
    require_once __DIR__ . '/global_search.php';
    if (file_exists(__DIR__ . '/dashboard_widgets.php')) {
        require_once __DIR__ . '/dashboard_widgets.php';
    }
    ay_init();

    if (file_exists($includesDir . '/csrf.php')) {
        require_once $includesDir . '/csrf.php';
    }

    $GLOBALS['DEBUG'] = panel_debug();

    if ($role !== null && !($options['skip_auth'] ?? false)) {
        $fn = 'require_' . $role . '_auth';
        if (function_exists($fn)) {
            $fn();
        }
    }
}

/**
 * Whether verbose DB errors should surface (dev only).
 */
function panel_debug(): bool
{
    if (defined('DEV_SHOW_ERRORS')) {
        return (bool) constant('DEV_SHOW_ERRORS');
    }

    $env = getenv('DEV_SHOW_ERRORS');
    if ($env !== false) {
        return in_array(strtolower((string) $env), ['1', 'true', 'on', 'yes'], true);
    }

    return false;
}
