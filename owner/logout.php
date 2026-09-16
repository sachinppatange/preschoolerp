<?php
/**
 * owner/logout.php
 *
 * Fully-featured logout handler for the owner/admin area.
 *
 * Behavior:
 *  - Destroys the current PHP session (unsets data, destroys server session, removes session cookie).
 *  - Removes common persistent login cookies (example: "remember_me") if present.
 *  - Optionally attempts to revoke server-side session tokens (example commented out).
 *  - Returns JSON {ok:true, redirect: <url>} for AJAX requests or redirects to the login page for regular requests.
 *  - Prevents open-redirects by allowing only local redirects (path-only or same-host).
 *
 * Usage:
 *  - Prefer POST to logout (protects against CSRF). This script accepts GET as well for convenience,
 *    but if you require strict CSRF protection, only call this via POST with a valid csrf_token.
 *
 * Security notes:
 *  - If your app stores "remember me" tokens in a DB, delete/revoke them here to fully log out the user.
 *  - If you use a session table, consider deleting the row associated with this session id / user id.
 *
 * Place at: /pioneerplayschool01/owner/logout.php
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/* Optional includes (if your app provides helper functions / DB connection) */
if (file_exists(__DIR__ . '/../includes/config.php')) require_once __DIR__ . '/../includes/config.php';
if (file_exists(__DIR__ . '/../includes/db.php'))     require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/functions.php')) require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/auth.php')) require_once __DIR__ . '/../includes/auth.php';

/* Helper: determine if request is AJAX */
function is_ajax_request(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
}

/* Helper: safe redirect only to local paths (prevent open redirect) */
function safe_redirect_url(string $url, string $fallback = '/owner/login.php'): string {
    // If empty, use fallback
    $url = trim($url);
    if ($url === '') return $fallback;

    // If url begins with '/', treat as local path and safe
    if (strpos($url, '/') === 0) return $url;

    // If url is absolute and host matches current host, allow only same-host redirects
    $parsed = parse_url($url);
    if ($parsed !== false && isset($parsed['host'])) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if (strcasecmp($parsed['host'], $currentHost) === 0) {
            // rebuild minimal path + query + fragment
            $path = $parsed['path'] ?? '/';
            $qs = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            $frag = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
            return $path . $qs . $frag;
        }
        // host mismatch -> not allowed
        return $fallback;
    }

    // Not an absolute URL and not starting with '/', fallback
    return $fallback;
}

/* If you use a DB to track sessions/tokens, you can use your DB helper to revoke tokens.
   Example (commented): require includes/db.php and implement safe_db_run() or your own helper.

if (function_exists('safe_db_run') && !empty(auth_user_id())) {
    try {
        $uid = (int)auth_user_id();
        // Delete server-side session tokens (example table: user_sessions)
        safe_db_run("DELETE FROM user_sessions WHERE user_id = :uid AND session_id = :sid", [
            ':uid' => $uid,
            ':sid' => session_id()
        ]);
    } catch (Throwable $e) {
        // log but continue logout
        error_log('Failed to revoke user sessions: ' . $e->getMessage());
    }
}
*/

/* Optional: If your app sets persistent login cookie name, clear it here */
$persistent_cookie_names = [
    'remember_me',   // common name for persistent login
    // add other cookie names your app uses for auth/session
];

/* If you want to protect logout with CSRF, require POST + valid token.
   Many apps allow GET logout; change $require_post_for_logout to true to force POST. */
$require_post_for_logout = false;

if ($require_post_for_logout && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Logout must be performed with a POST request.']);
        exit;
    } else {
        // Show a small page explaining logout requires POST
        http_response_code(405);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Method Not Allowed</title></head><body>';
        echo '<h1>Method Not Allowed</h1><p>Logout must be performed using a POST request.</p>';
        echo '</body></html>';
        exit;
    }
}

/* If POST and a csrf_token exists in session, validate it if present in request. If your app doesn't
   use csrf tokens for logout, this block will be skipped when token not present. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['csrf_token'])) {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token)) {
        if (is_ajax_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']);
            exit;
        } else {
            // show warning then continue to destroy session? We will block.
            http_response_code(400);
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Invalid CSRF</title></head><body>';
            echo '<h1>Invalid CSRF token</h1><p>Logout request failed security checks.</p>';
            echo '</body></html>';
            exit;
        }
    }
}

/* Preserve desired redirect after logout (optional). Accept only safe redirect. */
$requested_redirect = $_REQUEST['redirect'] ?? '';
$redirect_after = safe_redirect_url($requested_redirect, function_exists('site_url') ? site_url('/owner/login.php') : '/owner/login.php');

/* Now perform logout: unset session vars, destroy session, remove cookies */
$oldSessionId = session_id();

if (function_exists('auth_clear_session')) {
    auth_clear_session();
}

// Unset all session variables
$_SESSION = [];

// If session uses cookies, delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    // Set cookie expiration to past and send with same path/domain/secure/httponly settings
    setcookie(session_name(), '', time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

// Delete any persistent auth cookies used by the app
foreach ($persistent_cookie_names as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        // delete the cookie
        setcookie($cookieName, '', time() - 42000, '/');
        unset($_COOKIE[$cookieName]);
    }
}

// Optionally clear custom cookies you use for auth/session
// setcookie('custom_auth_cookie', '', time() - 42000, '/');

// Destroy session data on server
session_destroy();

// Some apps also regenerate session id to prevent fixation (not strictly necessary after destroy)
// session_regenerate_id(true);

/* Optionally log logout action (if you have a logger or DB)
if (function_exists('safe_db_run') && !empty($uid)) {
    safe_db_run("INSERT INTO audit_logs (user_id, event, ip, created_at) VALUES (:uid, :event, :ip, NOW())", [
        ':uid' => $uid,
        ':event' => 'logout',
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}
*/

/* Return response: JSON for AJAX, redirect for normal */
if (is_ajax_request()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'redirect' => $redirect_after]);
    exit;
}

// Non-AJAX: redirect to login (or provided safe redirect)
header('Location: ' . $redirect_after);
exit;