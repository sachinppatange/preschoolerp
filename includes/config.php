<?php
/**
 * includes/config.php
 *
 * Simple direct-configuration file (no .env, no phpdotenv).
 * Edit the values below directly for your local/dev environment.
 *
 * IMPORTANT:
 *  - Do NOT commit real secrets (WHATSAPP_PROVIDER_KEY, DB_PASSWORD, etc.) to git.
 *  - For production, prefer server environment variables or a secrets manager.
 */

/* ---------------------------
 *  Local vs production (auto)
 *  localhost  → XAMPP / https://localhost/apppreschool/
 *  live site  → https://app.preschoolapp.in/
 * --------------------------- */
$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$httpHost = preg_replace('/:\d+$/', '', $httpHost) ?: '';
$isLocalHost = ($httpHost === 'localhost' || $httpHost === '127.0.0.1' || str_ends_with($httpHost, '.localhost'));
$isLiveHost = ($httpHost === 'app.preschoolapp.in' || $httpHost === 'www.app.preschoolapp.in');
/* CLI / cron: Mac XAMPP path = local, otherwise live */
if ($httpHost === '') {
    $isLocalHost = (PHP_OS_FAMILY === 'Darwin' || stripos(__DIR__, 'xampp') !== false);
    $isLiveHost = !$isLocalHost;
}
$isLocal = $isLocalHost && !$isLiveHost;

/* Application */
define('APP_ENV', $isLocal ? 'development' : 'production');
define('APP_DEBUG', $isLocal);              // true only on localhost
define('APP_NAME', 'Pioneer Play School');
define('BASE_URL', $isLocal ? 'https://localhost/apppreschool/' : 'https://app.preschoolapp.in/');
define('TIMEZONE', 'Asia/Kolkata');

/* Session / cookie */
define('SESSION_COOKIE_NAME', 'pps_session');
define('SESSION_TIMEOUT_SEC', 28800);      // 8 hours
define('SESSION_SECURE', !$isLocal);       // true in production with HTTPS
define('SESSION_HTTPONLY', true);
define('SESSION_SAMESITE', 'Lax');         // Lax | Strict | None

/* Database */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');
if ($isLocal) {
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'apppreschoolapp');
} else {
    define('DB_USER', 'u750208840_apppreyser');
    define('DB_PASS', 'Sachin@1078#');
    define('DB_NAME', 'u750208840_apppredbnew');
}


/* Paths */
define('BASE_PATH', rtrim(dirname(__DIR__), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('UPLOADS_PATH', BASE_PATH . 'uploads' . DIRECTORY_SEPARATOR);
define('LOGS_PATH', BASE_PATH . 'storage' . DIRECTORY_SEPARATOR);

/* Ensure directories exist (best-effort) */
@mkdir(UPLOADS_PATH, 0755, true);
@mkdir(LOGS_PATH, 0755, true);

/* OTP / WhatsApp behaviour */
define('OTP_LENGTH', 4);                    // digits
define('OTP_EXPIRY_SECONDS', 300);          // 5 minutes
define('OTP_RESEND_COOLDOWN', 60);          // 60 seconds between resends
define('OTP_MAX_ATTEMPTS', 5);
define('OTP_HOURLY_LIMIT', 10);
define('WA_COUNTRY_CODE', '91');            // default country code for local numbers

/* ---------------------------
 *  WhatsApp / Meta Cloud API
 * --------------------------- */
/*
 * Put your real token here ONLY on your local machine or server (do not commit).
 * Example token format: EAAR... (long string)
 */
define('WHATSAPP_PROVIDER', 'meta');
define('WHATSAPP_API_BASE', 'https://graph.facebook.com');
define('WHATSAPP_API_VERSION', 'v19.0');

/* Primary provider token (leave empty for development fallback/logging) */
define('WHATSAPP_PROVIDER_KEY', 'EAARb94KQlA0BPfSyTSYvTVjRyBU2Ag1azxiwQMwrINlnTmZBuDZAZC0ZCfEzGNJDZAhs3CXeKnKwOxEVTz3IVlyH2gHauSZCc7Lxd7r1SRZB5nWH6rQHY4qmcDZBfFZB6D5lZBHOYZCZAxwEdTxM28kZAlyKgzkmgra2nUdSsnuAIfNegaZBCoYSiZBJ1gpA7vR7BkZB9ZBdA8AZDZD');                // <-- PUT TOKEN HERE (or keep empty for dev)
define('WHATSAPP_PHONE_NUMBER_ID', '636471032888916'); // phone number id (string)

/* Template name & language (approved template in Meta Business Manager) */
define('WHATSAPP_TEMPLATE_OTP_NAME', 'atharvmediaotp');
define('WHATSAPP_TEMPLATE_LANGUAGE', 'en');

/* If template requires a URL/button parameter:
 * - Set WHATSAPP_URL_BUTTON_PARAM_STATIC to a short string (<=15 chars) OR
 * - Set WHATSAPP_URL_BUTTON_PARAM_USE_OTP = true to use the generated OTP (recommended in dev only)
 */
define('WHATSAPP_URL_BUTTON_PARAM_STATIC', 'true'); // e.g. 'shortcode1' (<=15 chars)
define('WHATSAPP_URL_BUTTON_PARAM_USE_OTP', true);

/* Optional: explicit CA bundle path for PHP streams (if needed) */
define('WHATSAPP_SSL_CAFILE', ''); // e.g. '/etc/ssl/certs/ca-certificates.crt' or leave empty

/* ---------------------------
 *  Backwards-compatible aliases
 * --------------------------- */
/* Older code may expect WA_TOKEN / WA_PHONE_ID — define safe aliases */
if (!defined('WA_TOKEN')) define('WA_TOKEN', WHATSAPP_PROVIDER_KEY);
if (!defined('WA_PHONE_ID')) define('WA_PHONE_ID', WHATSAPP_PHONE_NUMBER_ID);
if (!defined('WA_GRAPH_VERSION')) define('WA_GRAPH_VERSION', WHATSAPP_API_VERSION);
if (!defined('WA_API_BASE')) define('WA_API_BASE', WHATSAPP_API_BASE);

/* ---------------------------
 *  Runtime / error reporting
 * --------------------------- */
if (!defined('APP_DEBUG')) define('APP_DEBUG', APP_ENV === 'development');
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

/* ---------------------------
 *  Sanity warnings (development only)
 * --------------------------- */
if (APP_ENV === 'development') {
    $warn = [];
    if (WHATSAPP_PROVIDER_KEY === '') $warn[] = 'WHATSAPP_PROVIDER_KEY is empty (WhatsApp will fallback to dev logging).';
    if (WHATSAPP_PHONE_NUMBER_ID === '') $warn[] = 'WHATSAPP_PHONE_NUMBER_ID is empty.';
    if (!empty($warn)) {
        $f = LOGS_PATH . 'config_warnings.log';
        @file_put_contents($f, '[' . date('c') . '] ' . implode(' ; ', $warn) . PHP_EOL, FILE_APPEND | LOCK_EX);
        foreach ($warn as $w) {
            trigger_error("Config warning: $w", E_USER_NOTICE);
        }
    }
}

/* ---------------------------
 *  Optional local overrides
 *  If you need machine-specific secrets, create includes/config.local.php
 *  and add it to .gitignore — this file will be loaded and can override constants.
 * --------------------------- */
$local = __DIR__ . '/config.local.php';
if (file_exists($local)) {
    require_once $local;
}

/* End of includes/config.php */