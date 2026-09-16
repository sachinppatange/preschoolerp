<?php
declare(strict_types=1);
/**
 * whatsapp/verify_otp.php
 *
 * Verify OTP submitted by user, create session on success and redirect to role dashboard OR return JSON.
 *
 * Accepts POST (application/x-www-form-urlencoded or JSON):
 *  - phone : phone number submitted earlier (local or international)
 *  - role  : role for which OTP was requested (owner/accounts/teacher/reception/parent/staff)
 *  - otp   : the numeric OTP the user received on WhatsApp
 *  - ajax/json (optional): if true returns JSON responses instead of redirects (useful for mobile/app)
 *
 * Behavior:
 *  - Normalizes phone consistently with send_otp.php
 *  - Loads config safely using cfg() helper to avoid undefined constant warnings
 *  - Logs helpful debug events when APP_ENV === 'development'
 *
 * Security:
 *  - Does not log OTP plaintext in production
 *  - Uses password_verify for OTP hash check
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/session_start.php')) {
    require_once __DIR__ . '/../includes/session_start.php';
}
require_once __DIR__ . '/../includes/whatsapp_config.php';

/**
 * cfg - safe config reader: checks defined constant, then env(), then default
 *
 * @param array $keys
 * @param mixed $default
 * @return mixed
 */
if (!function_exists('cfg')) {
    function cfg(array $keys, $default = null) {
        foreach ($keys as $k) {
            if (defined($k)) {
                return constant($k);
            }
            $v = env($k, null);
            if ($v !== null && $v !== '') return $v;
        }
        return $default;
    }
}

/* Lightweight helpers (only defined if missing) */
if (!function_exists('sanitize_input')) {
    function sanitize_input($v) {
        if (is_array($v)) return array_map('sanitize_input', $v);
        return trim((string)$v);
    }
}

if (!function_exists('normalize_phone')) {
    function normalize_phone(string $input, string $country = '91'): string {
        $raw = trim($input);
        // preserve leading + if provided
        $hasPlus = (strpos($raw, '+') === 0);
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return '';
        // if raw had leading +, return +digits (assume already e164)
        if ($hasPlus) return '+' . $digits;
        // if digits starts with country and seems long enough
        if (strpos($digits, $country) === 0 && strlen($digits) >= (strlen($country) + 6)) {
            return '+' . $digits;
        }
        // if local 10-digit number -> prefix country
        if (strlen($digits) === 10) {
            return '+' . $country . $digits;
        }
        // if leading 0 and 11 digits -> strip leading 0 and prefix country
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '+' . $country . substr($digits, 1);
        }
        // fallback: prefix country
        return '+' . $country . $digits;
    }
}

if (!function_exists('verify_otp_hash')) {
    function verify_otp_hash(string $otp, string $hash): bool {
        return password_verify($otp, $hash);
    }
}

if (!function_exists('ensure_session_started')) {
    function ensure_session_started(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }
}
if (!function_exists('session_regenerate_on_login')) {
    function session_regenerate_on_login(): void {
        @session_regenerate_id(true);
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $type, string $msg): void {
        ensure_session_started();
        $_SESSION['_flash'] = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'][$type] = $msg;
    }
}
if (!function_exists('redirect')) {
    function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

/* Main handler */
try {
    // Read input (JSON body or form data)
    $raw = file_get_contents('php://input');
    $input = [];
    if ($raw) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $input = $parsed;
    }
    $input = array_merge($_POST, $input);

    $phoneInput = isset($input['phone']) ? sanitize_input($input['phone']) : '';
    $roleInput  = isset($input['role'])  ? sanitize_input($input['role'])  : '';
    $otpInput   = isset($input['otp'])   ? sanitize_input($input['otp'])   : '';
    $ajaxMode   = isset($input['ajax']) || isset($input['json']) ||
                  (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($phoneInput === '' || $roleInput === '' || $otpInput === '') {
        $resp = ['ok' => false, 'message' => 'Missing phone, role or otp.'];
        if ($ajaxMode) { http_response_code(400); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    $allowedRoles = ['owner', 'accounts', 'teacher', 'reception', 'parent', 'staff'];
    if (!in_array($roleInput, $allowedRoles, true)) {
        $resp = ['ok' => false, 'message' => 'Invalid role.'];
        if ($ajaxMode) { http_response_code(400); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Determine default country code safely (matches send_otp logic)
    $defaultCountry = (string) cfg(['WA_COUNTRY_CODE', 'DEFAULT_COUNTRY_CODE'], '91');

    // Normalize incoming phone
    $phone = normalize_phone($phoneInput, $defaultCountry);

    // Debug: incoming attempt
    if (defined('APP_ENV') && APP_ENV === 'development') {
        // Do not include OTP plaintext in logs
        whatsapp_log('verify_otp_incoming', [
            'phone_raw' => $phoneInput,
            'phone_normalized' => $phone,
            'role' => $roleInput,
            'otp_length' => strlen($otpInput),
            'time' => date('c')
        ]);
    }

    if (!preg_match('/^\+\d{8,15}$/', $phone)) {
        $resp = ['ok' => false, 'message' => 'Invalid phone format.'];
        if ($ajaxMode) { http_response_code(400); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Fetch the most recent OTP row for this phone+role
    $otpRow = db_fetch_one(
        "SELECT id, otp_hash, attempts, otp_expires_at, verified, created_at FROM otp_verifications WHERE phone = :phone AND role = :role ORDER BY created_at DESC LIMIT 1",
        ['phone' => $phone, 'role' => $roleInput]
    );

    if (!$otpRow) {
        $resp = ['ok' => false, 'message' => 'No OTP request found. Please request a new OTP.'];
        if ($ajaxMode) { http_response_code(404); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Debug: fetched row
    if (defined('APP_ENV') && APP_ENV === 'development') {
        whatsapp_log('verify_otp_fetched_row', [
            'row_id' => $otpRow['id'] ?? null,
            'has_hash' => !empty($otpRow['otp_hash']),
            'attempts' => $otpRow['attempts'] ?? null,
            'expires_at' => $otpRow['otp_expires_at'] ?? null,
            'verified' => $otpRow['verified'] ?? null,
            'time' => date('c')
        ]);
    }

    // Check if already verified
    if (!empty($otpRow['verified'])) {
        $resp = ['ok' => false, 'message' => 'OTP already used. Request a new OTP.'];
        if ($ajaxMode) { http_response_code(409); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Check expiry
    $expirySec = intval(cfg(['OTP_EXPIRY_SECONDS', 'OTP_EXPIRY_SEC'], 300));
    $expiresAtVal = $otpRow['otp_expires_at'] ?? null;
    if ($expiresAtVal !== null) {
        $expiresUnix = strtotime($expiresAtVal);
        if ($expiresUnix === false || time() > $expiresUnix) {
            $resp = ['ok' => false, 'message' => 'OTP expired. Please request a new one.'];
            if ($ajaxMode) { http_response_code(410); echo json_encode($resp); exit; }
            flash_set('error', $resp['message']);
            redirect('/');
        }
    }

    // Check attempts limit
    $maxAttempts = intval(cfg(['OTP_MAX_ATTEMPTS'], 5));
    $attempts = isset($otpRow['attempts']) ? intval($otpRow['attempts']) : 0;
    if ($attempts >= $maxAttempts) {
        $resp = ['ok' => false, 'message' => 'Maximum OTP attempts exceeded. Please request a new OTP or contact support.'];
        if ($ajaxMode) { http_response_code(429); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Verify OTP (do not log plaintext)
    $isValid = verify_otp_hash($otpInput, $otpRow['otp_hash']);

    // Debug: password_verify result
    if (defined('APP_ENV') && APP_ENV === 'development') {
        whatsapp_log('verify_otp_password_verify', [
            'result' => $isValid ? 'OK' : 'FAIL',
            'row_id' => $otpRow['id'],
            'time' => date('c')
        ]);
    }

    if (!$isValid) {
        // Increment attempts atomically
        db_execute("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id", ['id' => $otpRow['id']]);

        $remaining = max(0, $maxAttempts - ($attempts + 1));
        $resp = ['ok' => false, 'message' => 'Invalid OTP. ' . ($remaining > 0 ? "You have {$remaining} attempts left." : "No attempts left.")];

        // Log failure (no OTP)
        if (function_exists('whatsapp_log')) {
            whatsapp_log('otp_verify_failed', ['phone' => $phone, 'role' => $roleInput, 'attempts_before' => $attempts]);
        }

        if ($ajaxMode) { http_response_code(401); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);

        // Redirect to role-specific login
        $roleLoginMap = [
            'owner' => '/owner/login.php',
            'accounts' => '/accounts/login.php',
            'teacher' => '/teacher/login.php',
            'reception' => '/reception/login.php',
            'parent' => '/parent/login.php',
            'staff' => '/owner/login.php'
        ];
        $redirect = $roleLoginMap[$roleInput] ?? '/';
        redirect($redirect);
    }

    // Valid OTP -> mark verified, reset attempts, create session, fetch user
    db_transaction(function($pdo) use ($otpRow, $phone, $roleInput, &$user) {
        $stmt = $pdo->prepare("UPDATE otp_verifications SET verified = 1, verified_at = NOW(), attempts = 0 WHERE id = :id");
        $stmt->execute(['id' => $otpRow['id']]);

        $stmt2 = $pdo->prepare("SELECT id, name, role, school_id, is_active FROM users WHERE phone = :phone AND role = :role LIMIT 1");
        $stmt2->execute(['phone' => $phone, 'role' => $roleInput]);
        $user = $stmt2->fetch(PDO::FETCH_ASSOC);
    });

    if (empty($user) || empty($user['is_active'])) {
        // User not found or deactivated after OTP sent
        if (function_exists('whatsapp_log')) {
            whatsapp_log('otp_verify_user_missing_or_inactive', ['phone' => $phone, 'role' => $roleInput]);
        }
        $resp = ['ok' => false, 'message' => 'Account not available. Contact administrator.'];
        if ($ajaxMode) { http_response_code(403); echo json_encode($resp); exit; }
        flash_set('error', $resp['message']);
        redirect('/');
    }

    // Create session and set auth data
    ensure_session_started();
    session_regenerate_on_login();

    $_SESSION['user_id'] = intval($user['id']);
    $_SESSION['role'] = $user['role'];
    $_SESSION['school_id'] = isset($user['school_id']) ? intval($user['school_id']) : 0;
    $_SESSION['user_name'] = $user['name'] ?? '';
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();

    // Log success
    if (function_exists('whatsapp_log')) {
        whatsapp_log('otp_verify_success', ['phone' => $phone, 'role' => $roleInput, 'user_id' => $user['id']]);
    }

    // Redirect or return JSON
    $roleDashboardMap = [
        'owner' => '/owner/dashboard.php',
        'accounts' => '/accounts/dashboard.php',
        'teacher' => '/teacher/dashboard.php',
        'reception' => '/reception/dashboard.php',
        'parent' => '/parent/dashboard.php',
        'staff' => '/owner/dashboard.php'
    ];
    $redirectUrl = $roleDashboardMap[$roleInput] ?? '/';

    if ($ajaxMode) {
        echo json_encode(['ok' => true, 'message' => 'OTP verified. Logged in successfully.', 'data' => ['redirect' => $redirectUrl]]);
        exit;
    }

    redirect($redirectUrl);

} catch (Throwable $e) {
    // Log exception (avoid leaking details to user)
    if (function_exists('whatsapp_log')) {
        whatsapp_log('verify_otp_exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        error_log('verify_otp exception: ' . $e->getMessage());
    }
    $resp = ['ok' => false, 'message' => 'Internal server error.'];
    if (isset($ajaxMode) && $ajaxMode) {
        http_response_code(500);
        echo json_encode($resp);
        exit;
    }
    flash_set('error', $resp['message']);
    redirect('/');
}