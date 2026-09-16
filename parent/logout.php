<?php
/**
 * parent/logout.php
 *
 * Parent area logout handler.
 *
 * Behavior:
 *  - Destroys the PHP session and clears the session cookie.
 *  - Removes common persistent-login cookies (e.g. 'remember_me', 'parent_remember').
 *  - Optionally revokes server-side tokens if your app stores them (example commented).
 *  - Returns JSON { ok: true, redirect: "<url>" } for AJAX requests or redirects to the parent login page for normal requests.
 *  - Prevents open-redirects by only allowing local path redirects or same-host redirects.
 *
 * Security notes:
 *  - Prefer POST for logout (set $require_post_for_logout = true if you'd like to enforce).
 *  - If you store persistent login tokens in DB, revoke them here.
 *
 * Place at: /pioneerplayschool01/parent/logout.php
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Optional project includes for DB / helpers */
if (file_exists(__DIR__ . '/../includes/config.php'))      require_once __DIR__ . '/../includes/config.php';
if (file_exists(__DIR__ . '/../includes/db.php'))          require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/functions.php'))   require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/auth.php'))        require_once __DIR__ . '/../includes/auth.php';

/* Helper: detect AJAX (XMLHttpRequest or JSON accept) */
if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return (is_string($xhr) && strtolower($xhr) === 'xmlhttprequest')
            || (is_string($accept) && stripos($accept, 'application/json') !== false);
    }
}

/* Safe redirect builder to avoid open redirects */
if (!function_exists('safe_redirect_url')) {
    /**
     * Return a safe redirect path.
     * - If $url is an absolute path (starts with /) returns it.
     * - If $url is an absolute URL, allows only when host matches current host.
     * - Otherwise returns $fallback.
     */
    function safe_redirect_url(string $url, string $fallback = '/pioneerplayschool01/parent/login.php'): string {
        $url = trim($url);
        if ($url === '') return $fallback;

        // Path-only (absolute path) -> allow
        if (strpos($url, '/') === 0) return $url;

        // Absolute URL: permit only when host equals current host
        $parsed = parse_url($url);
        if ($parsed !== false && isset($parsed['host'])) {
            $currentHost = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
            if ($currentHost !== '' && strcasecmp($parsed['host'], $currentHost) === 0) {
                $path = $parsed['path'] ?? '/';
                $qs = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                $frag = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
                return $path . $qs . $frag;
            }
            return $fallback;
        }

        // Anything else -> fallback
        return $fallback;
    }
}

/* Optionally require POST for logout to protect against CSRF attacks. */
$require_post_for_logout = false;

/* If POST is required but not used, return error */
if ($require_post_for_logout && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Logout must be a POST request.']);
        exit;
    } else {
        http_response_code(405);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Method Not Allowed</title></head><body>';
        echo '<h1>Method Not Allowed</h1><p>Logout must be performed with a POST request.</p></body></html>';
        exit;
    }
}

/* If POST and a CSRF helper exists in project, validate token (non-strict: only if function present) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('validate_csrf_token')) {
    $incoming = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!validate_csrf_token($incoming)) {
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
            exit;
        } else {
            http_response_code(400);
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Invalid CSRF</title></head><body>';
            echo '<h1>Invalid CSRF token</h1><p>Logout failed security checks.</p></body></html>';
            exit;
        }
    }
}

/* Determine safe redirect after logout */
$requested = $_REQUEST['redirect'] ?? '';
$redirect_after = safe_redirect_url((string)$requested, '/pioneerplayschool01/parent/login.php');

/* Capture parent session info for optional revocation/audit */
$parent_user = auth_user();

/* Optionally revoke server-side tokens for this user (adapt to your schema)
if (!empty($parent_user) && function_exists('db_run')) {
    try {
        $uid = is_array($parent_user) ? ($parent_user['id'] ?? $parent_user) : $parent_user;
        // Example: delete tokens for this user and area 'parent'
        // db_run("DELETE FROM user_tokens WHERE user_id = ? AND area = 'parent'", [$uid]);
    } catch (Throwable $e) {
        // ignore
    }
}
*/

/* Remove persistent cookies commonly used by apps. Add any app-specific cookie names here. */
$persistent_cookie_names = [
    'remember_me',
    'parent_remember',
    'auth_token',
    'remember_parent'
];

/* Perform logout: unset session, destroy session cookie, remove persistent cookies */
$oldSessionId = session_id();

if (function_exists('auth_clear_session')) {
    auth_clear_session();
}

// Unset all session variables
$_SESSION = [];

// If session uses cookies, remove it
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

// Remove persistent cookies by setting expiration to past
foreach ($persistent_cookie_names as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        setcookie($cookieName, '', time() - 42000, '/');
        unset($_COOKIE[$cookieName]);
    }
}

// Destroy session data on server
session_destroy();

/* Optional: audit log (uncomment and adapt if you have db_run() or similar)
if (!empty($parent_user) && function_exists('db_run')) {
    try {
        $uid = is_array($parent_user) ? ($parent_user['id'] ?? $parent_user) : $parent_user;
        db_run("INSERT INTO audit_logs (user_id, event, ip, user_agent, created_at) VALUES (?, 'parent_logout', ?, ?, NOW())", [$uid, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    } catch (Throwable $e) {
        // ignore audit failures
    }
}
*/

/* Return JSON for AJAX, otherwise redirect to login (or provided safe redirect) */
if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'redirect' => $redirect_after]);
    exit;
}

// Non-AJAX: safe redirect
header('Location: ' . $redirect_after);
exit;