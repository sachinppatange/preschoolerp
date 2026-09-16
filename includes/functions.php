<?php
/**
 * includes/functions.php
 * Common helper functions for Pioneer Play School (Non-MVC)
 *
 * Include this file after includes/config.php (and session_start if used).
 *
 * Functions provided (useful list):
 *  - ensure_session_started()
 *  - e($str)                      // escape for HTML output
 *  - sanitize_input($value)       // basic input sanitization
 *  - normalize_phone($phone, $countryCode = '91') // basic phone normalization to +E.164-like
 *  - redirect($url, $permanent = false)
 *  - flash_set($type, $message)
 *  - flash_get($clear = true)
 *  - generate_random_string($length = 16)
 *  - generate_otp($length = 6)
 *  - hash_otp($otp)
 *  - verify_otp_hash($otp, $hash)
 *  - send_json_response($data, $status = 200)
 *  - validate_csrf_token($token)
 *  - csrf_token()                 // generate/get token
 *  - require_login($roles = [])   // check session and role, otherwise redirect
 *  - has_role($role)
 *  - log_event($msg, $file = 'app.log')
 *
 * Security notes:
 *  - Use server-side validation always.
 *  - Keep secrets (if needed) in environment variables / config.local.php, not in code.
 */

if (!defined('BASE_PATH')) {
    // Try to load config automatically if not already loaded
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        // If neither config nor BASE_PATH is defined, that's a fatal misconfiguration.
        throw new RuntimeException('Missing includes/config.php — includes/functions.php expects configuration to be loaded first.');
    }
}

/* Ensure session is started when helpers rely on $_SESSION */
function ensure_session_started()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session params if defined in config (optional)
        if (defined('SESSION_COOKIE_NAME')) {
            session_name(SESSION_COOKIE_NAME);
        }
        $cookieParams = session_get_cookie_params();
        // secure cookie settings
        $secure = defined('SESSION_SECURE') ? (bool)SESSION_SECURE : false;
        $httponly = defined('SESSION_HTTPONLY') ? (bool)SESSION_HTTPONLY : true;
        $samesite = defined('SESSION_SAMESITE') ? SESSION_SAMESITE : 'Lax';

        // PHP < 7.3 compatibility: set cookie params without sameSite
        if (PHP_VERSION_ID < 70300) {
            session_set_cookie_params(
                $cookieParams['lifetime'],
                $cookieParams['path'],
                $cookieParams['domain'],
                $secure,
                $httponly
            );
        } else {
            session_set_cookie_params([
                'lifetime' => $cookieParams['lifetime'],
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite
            ]);
        }
        session_start();
    }
}

/* HTML escape helper */
function e($str)
{
    if ($str === null) return '';
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Currency display helper (INR) */
if (!function_exists('format_money')) {
    function format_money($v): string
    {
        return '₹ ' . number_format((float) $v, 2);
    }
}

/* Basic sanitization for inputs (trim, strip tags optionally) */
function sanitize_input($value, $strip_tags = true)
{
    if (is_array($value)) {
        return array_map(function ($v) use ($strip_tags) {
            return sanitize_input($v, $strip_tags);
        }, $value);
    }
    $v = trim((string)$value);
    if ($strip_tags) {
        // allow limited tags if needed by changing this line
        $v = strip_tags($v);
    }
    return $v;
}

/* Normalize a phone number roughly to +<country><number>
 * Note: This is a best-effort helper. For production you may want libphonenumber.
 * Examples:
 *   normalize_phone('09876543210') -> +919876543210   (countryCode default '91')
 *   normalize_phone('+1 555-222-3333', '1') -> +15552223333
 */
function normalize_phone($phone, $countryCode = '91')
{
    if (empty($phone)) {
        return null;
    }
    // remove everything except digits and plus
    $p = preg_replace('/[^\d+]/', '', $phone);

    // If already starts with +, assume it's full E.164-like and return after removing non-digits
    if (strpos($p, '+') === 0) {
        // ensure only + and digits
        $digits = preg_replace('/[^\d]/', '', $p);
        return '+' . $digits;
    }

    // Remove leading zeros
    $p = ltrim($p, '0');

    // If the number length looks like local (e.g., 10 for India) append country code
    if (strlen($p) <= 12) {
        // basic guard: prepend country code
        $p = '+' . $countryCode . $p;
    } else {
        // unknown long number, just prepend plus
        $p = '+' . $p;
    }
    return $p;
}

/**
 * Hosts whose stored absolute URLs should be rewritten to the current BASE_URL.
 */
function app_is_own_host(?string $host): bool
{
    $host = strtolower((string) $host);
    if ($host === '') {
        return false;
    }
    $current = defined('BASE_URL') ? strtolower((string) (parse_url((string) BASE_URL, PHP_URL_HOST) ?: '')) : '';
    if ($current !== '' && $host === $current) {
        return true;
    }
    if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.localhost')) {
        return true;
    }
    return str_ends_with($host, '.preschoolapp.in') || $host === 'preschoolapp.in';
}

/**
 * Convert an old Pioneer / localhost / other-tenant absolute URL into an app-root path.
 * Returns null for external URLs (Facebook, Maps, CDN, …).
 */
function app_path_from_maybe_legacy_url(string $url): ?string
{
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!app_is_own_host(is_string($host) ? $host : null)) {
        return null;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $qs = parse_url($url, PHP_URL_QUERY);
    $frag = parse_url($url, PHP_URL_FRAGMENT);
    $rel = '/' . ltrim(normalize_media_path($path), '/');
    if ($rel === '/') {
        $rel = '/';
    }
    if (is_string($qs) && $qs !== '') {
        $rel .= '?' . $qs;
    }
    if (is_string($frag) && $frag !== '') {
        $rel .= '#' . $frag;
    }
    return $rel;
}

/**
 * Build absolute URL from app root (uses BASE_URL).
 * Absolute URLs that belong to this app (old Pioneer domain, localhost, live) are rewritten.
 */
function site_url(string $path = ''): string
{
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    if (preg_match('#^https?://#i', $path)) {
        $rewritten = app_path_from_maybe_legacy_url($path);
        if ($rewritten === null) {
            return $path;
        }
        $path = $rewritten;
    }
    if ($path === '' || $path === '/') {
        return $base ? $base . '/' : '/';
    }
    return ($base ?: '') . '/' . ltrim($path, '/');
}

/**
 * Public asset URL (CSS, images, uploads under app root).
 */
function asset_url(string $path): string
{
    return site_url('/' . ltrim($path, '/'));
}

/**
 * Strip legacy install folder prefixes from stored media paths.
 */
function normalize_media_path(string $path): string
{
    $path = '/' . ltrim($path, '/');
    foreach (['/demopreschoolapp', '/pioneerplayschool01', '/pioneerplayschool', '/apppreschool'] as $prefix) {
        if (str_starts_with($path, $prefix . '/')) {
            $path = substr($path, strlen($prefix));
            break;
        }
    }
    return ltrim($path, '/');
}

/**
 * Filesystem path for a public media file under the app root.
 */
function media_fs_path(string $relativePath): string
{
    $base = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/\\') : rtrim(dirname(__DIR__), '/\\');
    $rel = normalize_media_path($relativePath);
    return $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/**
 * Find an existing media file (case-insensitive match inside uploads).
 */
function media_resolve_existing_path(string $relativePath): ?string
{
    $rel = normalize_media_path($relativePath);
    if ($rel === '') {
        return null;
    }

    $fs = media_fs_path($rel);
    if (is_file($fs) && filesize($fs) > 0) {
        return $rel;
    }

    $dir = dirname($fs);
    $wanted = basename($fs);
    if (!is_dir($dir)) {
        return null;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $candidate = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($candidate) && strcasecmp($entry, $wanted) === 0 && filesize($candidate) > 0) {
            return normalize_media_path(dirname($rel) . '/' . $entry);
        }
    }

    return null;
}

/**
 * Default bundled fallback when an upload is missing on disk.
 */
function media_default_fallback_for(string $relativePath): string
{
    $lower = strtolower(normalize_media_path($relativePath));
    if ($lower === '' || str_contains($lower, 'hero') || preg_match('/\.jpe?g$/', $lower)) {
        $hero = media_resolve_existing_path('assets/images/hero.jpg');
        if ($hero !== null) {
            return asset_url($hero);
        }
    }
    if (str_contains($lower, 'logo') || str_ends_with($lower, '.png')) {
        $logo = media_resolve_existing_path('assets/images/logo.png');
        if ($logo !== null) {
            return asset_url($logo);
        }
    }
    return asset_url('assets/images/default-logo.png');
}

/**
 * Resolve image path from DB / CMS to a working public URL on any host.
 * Rewrites old Pioneer / localhost / other-tenant absolute URLs to current BASE_URL.
 * Falls back to bundled assets when the upload file is missing.
 */
function resolve_image_url(?string $path, ?string $fallback = null): string
{
    if ($path === null || trim($path) === '') {
        return $fallback ?? asset_url('assets/images/default-logo.png');
    }

    $p = trim($path);
    $rel = null;

    if (preg_match('#^https?://#i', $p)) {
        $rewritten = app_path_from_maybe_legacy_url($p);
        if ($rewritten === null) {
            return $p;
        }
        $rel = normalize_media_path((string) (parse_url($p, PHP_URL_PATH) ?? ''));
    } else {
        $rel = normalize_media_path($p);
    }

    $existing = media_resolve_existing_path($rel);
    if ($existing !== null) {
        return asset_url($existing);
    }

    return $fallback ?? media_default_fallback_for($rel);
}

/* Redirect helper */
function redirect($url, $permanent = false)
{
    if (!preg_match('#^https?://#i', $url)) {
        // relative url - make absolute using BASE_URL if defined
        if (defined('BASE_URL')) {
            $url = rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
        }
    }
    if (headers_sent()) {
        echo "<script>window.location.href = '" . e($url) . "';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=" . e($url) . "'></noscript>";
        exit;
    }
    header('Location: ' . $url, true, $permanent ? 301 : 302);
    exit;
}

/* Flash message helpers (store messages in session) */
function flash_set($type, $message)
{
    ensure_session_started();
    if (!isset($_SESSION['flash_messages']) || !is_array($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message, 'time' => time()];
}

/* Get flash messages. By default clears them (so they display only once) */
function flash_get($clear = true)
{
    ensure_session_started();
    $msgs = [];
    if (isset($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
        $msgs = $_SESSION['flash_messages'];
        if ($clear) {
            unset($_SESSION['flash_messages']);
        }
    }
    return $msgs;
}

/* Convenience: show flash messages as simple Bootstrap-like HTML (returns string) */
function flash_render()
{
    $msgs = flash_get(true);
    $out = '';
    foreach ($msgs as $m) {
        $type = isset($m['type']) ? $m['type'] : 'info';
        $cls = 'alert-secondary';
        switch ($type) {
            case 'success': $cls = 'alert-success'; break;
            case 'error': case 'danger': $cls = 'alert-danger'; break;
            case 'warning': $cls = 'alert-warning'; break;
            case 'info': default: $cls = 'alert-info'; break;
        }
        $out .= '<div class="alert ' . e($cls) . '" role="alert">' . e($m['message']) . '</div>';
    }
    return $out;
}

/* Random string generator */
function generate_random_string($length = 16)
{
    if ($length <= 0) $length = 16;
    $bytes = random_bytes($length);
    return substr(bin2hex($bytes), 0, $length);
}

/* OTP helpers */
function generate_otp($length = 6)
{
    $length = max(4, (int)$length);
    $min = (int)str_pad('1', $length, '0');  // e.g., 100000 for 6
    $max = (int)str_pad('', $length, '9') + 0; // Not reliable; use random_int in loop
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= random_int(0, 9);
    }
    return $otp;
}

/* Hash OTP for storage (use password_hash so it's one-way) */
function hash_otp($otp)
{
    // password_hash is fine for short-lived OTPs; verify with password_verify
    return password_hash($otp, PASSWORD_DEFAULT);
}

function verify_otp_hash($otp, $hash)
{
    return password_verify($otp, $hash);
}

/* JSON response helper for API endpoints */
function send_json_response($data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* CSRF token helpers — defer to includes/csrf.php when already loaded */
if (!function_exists('get_csrf_token') && file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
}

if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (function_exists('get_csrf_token')) {
            return get_csrf_token();
        }
        ensure_session_started();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token, $max_age_sec = 3600)
    {
        ensure_session_started();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        $valid = hash_equals($_SESSION['csrf_token'], (string) $token);
        if (!$valid) {
            return false;
        }
        if ($max_age_sec > 0 && !empty($_SESSION['csrf_token_time'])) {
            if (time() - $_SESSION['csrf_token_time'] > $max_age_sec) {
                return false;
            }
        }
        return true;
    }
}

/* Auth helpers — delegated to includes/auth.php when available */
function is_logged_in()
{
    if (function_exists('auth_is_logged_in')) {
        return auth_is_logged_in();
    }
    ensure_session_started();
    return !empty($_SESSION['user_id'])
        || !empty($_SESSION['owner_auth_user'])
        || !empty($_SESSION['teacher_auth_user'])
        || !empty($_SESSION['parent_auth_user'])
        || !empty($_SESSION['reception_auth_user'])
        || !empty($_SESSION['accounts_auth_user']);
}

function has_role($role)
{
    if (function_exists('auth_is_logged_in')) {
        return auth_is_logged_in($role);
    }
    ensure_session_started();
    if (empty($_SESSION['role'])) {
        return false;
    }
    if (is_array($role)) {
        return in_array($_SESSION['role'], $role, true);
    }
    return $_SESSION['role'] === $role;
}

function require_login($roles = [])
{
    if (!function_exists('auth_require_roles')) {
        require_once __DIR__ . '/auth.php';
    }
    if (empty($roles)) {
        if (!auth_is_logged_in()) {
            redirect(site_url('/login.php'));
        }
        return;
    }
    auth_require_roles($roles);
}

/* Simple server-side logger to logs/ directory (configurable via LOGS_PATH) */
function log_event($msg, $file = 'app.log')
{
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg" . PHP_EOL;
    $path = defined('LOGS_PATH') ? rtrim(LOGS_PATH, '/\\') . '/' : (BASE_PATH . 'logs/');
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    @file_put_contents($path . $file, $line, FILE_APPEND | LOCK_EX);
}

/* Utility: simple pagination helper to calculate offset/limit */
function paginate_params($page, $perPage = 20)
{
    $page = max(1, (int)$page);
    $perPage = max(1, (int)$perPage);
    $offset = ($page - 1) * $perPage;
    return ['limit' => $perPage, 'offset' => $offset, 'page' => $page];
}

/**
 * Block unsafe GET-based delete attempts (Phase-A migration).
 */
if (!function_exists('secure_delete_blocked_get')) {
    function secure_delete_blocked_get(string $action): bool
    {
        return $action === 'delete' && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST';
    }
}

/**
 * Return a validated delete ID from POST + CSRF, or 0.
 */
if (!function_exists('secure_delete_id')) {
    function secure_delete_id(): int
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return 0;
        }

        $action = (string) ($_POST['action'] ?? $_REQUEST['action'] ?? '');
        if ($action !== 'delete') {
            return 0;
        }

        $token = (string) ($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
        if ($token === '' || !validate_csrf_token($token)) {
            return 0;
        }

        return max(0, (int) ($_POST['id'] ?? 0));
    }
}

/**
 * Render an inline POST delete button with CSRF protection.
 *
 * @param array<string, scalar|null> $extraHiddenFields
 */
if (!function_exists('render_secure_delete_button')) {
    function render_secure_delete_button(
        int $id,
        string $label = 'Delete',
        string $confirmMessage = 'Delete this item?',
        string $btnClass = 'btn btn-sm btn-outline-danger',
        array $extraHiddenFields = []
    ): string {
        $token = function_exists('get_csrf_token') ? get_csrf_token() : csrf_token();

        $html = '<form method="post" class="d-inline" onsubmit="return confirm(' . json_encode($confirmMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');">';
        $html .= '<input type="hidden" name="action" value="delete">';
        $html .= '<input type="hidden" name="id" value="' . (int) $id . '">';
        $html .= '<input type="hidden" name="csrf_token" value="' . e($token) . '">';

        foreach ($extraHiddenFields as $name => $value) {
            if ($value === null) {
                continue;
            }
            $html .= '<input type="hidden" name="' . e((string) $name) . '" value="' . e((string) $value) . '">';
        }

        $html .= '<button type="submit" class="' . e($btnClass) . '">' . e($label) . '</button>';
        $html .= '</form>';

        return $html;
    }
}

/* End of includes/functions.php */