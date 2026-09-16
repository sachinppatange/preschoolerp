<?php
/**
 * includes/session_start.php
 * Secure session bootstrap for Pioneer Play School (Non-MVC)
 *
 * Responsibilities:
 *  - Configure secure session cookie params (Secure, HttpOnly, SameSite)
 *  - Set PHP session ini settings (use_strict_mode, cookie_lifetime, gc_maxlifetime)
 *  - Start the session if not already started
 *  - Provide idle timeout and absolute session lifetime handling
 *  - Helpers: destroy_session(), session_require_https(), session_regenerate_on_interval()
 *
 * Usage:
 *  require_once __DIR__ . '/config.php';
 *  require_once __DIR__ . '/session_start.php';
 *
 * Notes:
 *  - Ensure includes/config.php defines SESSION_TIMEOUT_SEC, SESSION_SECURE,
 *    SESSION_HTTPONLY and SESSION_SAMESITE. Defaults are provided if missing.
 *  - In production, serve app over HTTPS and set SESSION_SECURE = true.
 */

/* Load config if not loaded yet */
if (!defined('BASE_PATH')) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        throw new RuntimeException('includes/config.php required before includes/session_start.php');
    }
}

/* Provide sane defaults if config constants are missing */
if (!defined('SESSION_COOKIE_NAME')) define('SESSION_COOKIE_NAME', 'pps_session');
if (!defined('SESSION_TIMEOUT_SEC')) define('SESSION_TIMEOUT_SEC', 28800); // 8 hours
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', (APP_ENV === 'production'));
if (!defined('SESSION_HTTPONLY')) define('SESSION_HTTPONLY', true);
if (!defined('SESSION_SAMESITE')) define('SESSION_SAMESITE', 'Lax'); // Lax | Strict | None
if (!defined('SESSION_REGENERATE_INTERVAL')) define('SESSION_REGENERATE_INTERVAL', 300); // seconds

/* Configure session-related PHP INI settings before session_start() */
ini_set('session.use_strict_mode', '1');                // reject uninitialized session ids
ini_set('session.use_only_cookies', '1');               // do not use URL-based session ids
ini_set('session.cookie_httponly', SESSION_HTTPONLY ? '1' : '0');
ini_set('session.cookie_secure', SESSION_SECURE ? '1' : '0'); // only send over HTTPS when true
ini_set('session.gc_maxlifetime', (string)SESSION_TIMEOUT_SEC); // server-side garbage collection lifetime

// Session cookie lifetime: keep as session cookie (0) or set to SESSION_TIMEOUT_SEC for persistent sessions.
// We'll use 0 so cookie expires when browser is closed, but server-side GC will expire after SESSION_TIMEOUT_SEC.
// If you want persistent login, set lifetime to SESSION_TIMEOUT_SEC (in seconds) instead.
$cookie_lifetime = 0; // session cookie (until browser close)

/* Set cookie params with SameSite support */
$cookieParams = session_get_cookie_params();
$cookiePath = isset($cookieParams['path']) ? $cookieParams['path'] : '/';
$cookieDomain = isset($cookieParams['domain']) ? $cookieParams['domain'] : '';
$cookieSecure = SESSION_SECURE;
$cookieHttpOnly = SESSION_HTTPONLY;
$cookieSameSite = strtoupper(SESSION_SAMESITE);
if (!in_array($cookieSameSite, ['LAX', 'STRICT', 'NONE'], true)) {
    $cookieSameSite = 'LAX';
}

/* PHP >= 7.3 supports an array for session_set_cookie_params including 'samesite' */
if (PHP_VERSION_ID >= 70300) {
    session_name(SESSION_COOKIE_NAME);
    session_set_cookie_params([
        'lifetime' => $cookie_lifetime,
        'path' => $cookiePath,
        'domain' => $cookieDomain,
        'secure' => $cookieSecure,
        'httponly' => $cookieHttpOnly,
        'samesite' => $cookieSameSite // 'Lax' | 'Strict' | 'None'
    ]);
} else {
    // Older PHP: set cookie parameters and implement SameSite via header override after session_start()
    session_name(SESSION_COOKIE_NAME);
    session_set_cookie_params($cookie_lifetime, $cookiePath, $cookieDomain, $cookieSecure, $cookieHttpOnly);
    // We'll patch the cookie with SameSite after session_start() below.
}

/* Start session if not already started */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    // For PHP < 7.3, set SameSite by resetting cookie header
    if (PHP_VERSION_ID < 70300) {
        if (headers_sent() === false) {
            $cookie = session_name() . '=' . session_id()
                . '; Path=' . $cookiePath
                . ($cookieDomain ? '; Domain=' . $cookieDomain : '')
                . ($cookieSecure ? '; Secure' : '')
                . ($cookieHttpOnly ? '; HttpOnly' : '')
                . '; SameSite=' . $cookieSameSite;
            header('Set-Cookie: ' . $cookie, false);
        }
    }
}

/* Ensure session has tracking timestamps for timeout management */
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
}
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

/* Idle timeout: if user inactive for SESSION_TIMEOUT_SEC, destroy session */
if (isset($_SESSION['last_activity']) && (time() - intval($_SESSION['last_activity']) > intval(SESSION_TIMEOUT_SEC))) {
    // Session expired due to inactivity
    destroy_session();
    // Optionally: redirect user to landing or login page if you want immediate redirect.
    // e.g. header('Location: ' . BASE_URL . '/'); exit;
} else {
    // update last activity timestamp
    $_SESSION['last_activity'] = time();
}

/* Absolute session lifetime (optional): force logout after e.g., 24h even with activity
   Use a defined constant SESSION_ABSOLUTE_TIMEOUT_SEC to enable this behaviour.
   We access the constant value via constant() only after checking defined() to avoid analyzer warnings.
*/
if (defined('SESSION_ABSOLUTE_TIMEOUT_SEC')) {
    $absTimeout = intval(constant('SESSION_ABSOLUTE_TIMEOUT_SEC'));
    if ($absTimeout > 0 && isset($_SESSION['created_at']) && (time() - intval($_SESSION['created_at']) > $absTimeout)) {
        destroy_session();
    }
}

/**
 * destroy_session
 * Securely destroy session data and cookie
 */
function destroy_session()
{
    // unset all session variables
    $_SESSION = [];

    // delete session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        // set cookie expiration in past
        setcookie(session_name(), '', time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
        // For PHP < 7.3 attach SameSite if needed (best effort)
        if (PHP_VERSION_ID < 70300 && defined('SESSION_SAMESITE')) {
            header('Set-Cookie: ' . session_name() . '=; Expires=' . gmdate('D, d-M-Y H:i:s T', time() - 42000) . '; Path=' . ($params['path'] ?? '/') . ($params['domain'] ? '; Domain=' . $params['domain'] : '') . (SESSION_SECURE ? '; Secure' : '') . (SESSION_HTTPONLY ? '; HttpOnly' : '') . '; SameSite=' . SESSION_SAMESITE, false);
        }
    }

    // destroy session data on server
    @session_destroy();
}

/**
 * session_require_https
 * Optionally enforce HTTPS in production. If SESSION_SECURE is true but current request is not HTTPS,
 * you may want to redirect or throw an error. This function does not auto-redirect by default; it returns boolean.
 *
 * @param bool $redirectIfNotSecure If true, redirect to HTTPS version of the URL
 * @return bool true if current request is secure or enforcement is disabled
 */
function session_require_https($redirectIfNotSecure = false)
{
    // If we don't require secure, return true
    if (!defined('SESSION_SECURE') || !SESSION_SECURE) {
        return true;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off');

    if ($isSecure) return true;

    if ($redirectIfNotSecure) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $httpsUrl = 'https://' . $host . $uri;
        header('Location: ' . $httpsUrl, true, 301);
        exit;
    }

    return false;
}

/**
 * session_regenerate_on_interval
 * Regenerate session id periodically to mitigate session fixation.
 * Call this on pages that are frequently accessed (e.g., at top of dashboard).
 *
 * @param int $interval Seconds between regenerations (default SESSION_REGENERATE_INTERVAL)
 * @return void
 */
function session_regenerate_on_interval($interval = null)
{
    if ($interval === null) $interval = defined('SESSION_REGENERATE_INTERVAL') ? SESSION_REGENERATE_INTERVAL : 300;
    $now = time();
    if (!isset($_SESSION['__last_regenerated'])) {
        $_SESSION['__last_regenerated'] = $now;
        return;
    }
    if (($now - intval($_SESSION['__last_regenerated'])) >= intval($interval)) {
        // create new session id while keeping session data
        session_regenerate_id(true);
        $_SESSION['__last_regenerated'] = $now;
    }
}

/**
 * session_regenerate_on_login
 * Call this immediately after a successful login to prevent session fixation.
 */
function session_regenerate_on_login()
{
    // regenerate id and reset created_at/last_activity timestamps
    session_regenerate_id(true);
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['__last_regenerated'] = time();
}

/* OPTIONAL: If you want to use DB-backed sessions, set up handler here.
   For simplicity this file uses default filesystem session handler. */

/* End of includes/session_start.php */