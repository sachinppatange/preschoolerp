<?php
/**
 * whatsapp/send_otp.php
 *
 * Secure, Intelephense-friendly version: uses a small cfg() helper to read
 * constants or environment variables without causing "Undefined constant" warnings.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_config.php';

/**
 * cfg - read the first available config value from:
 *  - defined PHP constant (checked with defined(), fetched with constant())
 *  - environment variable via env()
 *  - fallback default
 *
 * @param array $keys  list of keys to try in order (constant name then env key)
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

try {
    // Read input (JSON body or form-encoded)
    $raw = file_get_contents('php://input');
    $input = [];
    if ($raw) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $input = $parsed;
    }
    $input = array_merge($_POST, $input);

    $phoneInput = isset($input['phone']) ? trim((string)$input['phone']) : '';
    $roleInput  = isset($input['role'])  ? trim((string)$input['role'])  : '';

    if ($phoneInput === '' || $roleInput === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Missing phone or role.']);
        exit;
    }

    $allowedRoles = ['owner', 'accounts', 'teacher', 'reception', 'parent', 'staff'];
    if (!in_array($roleInput, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid role.']);
        exit;
    }

    // Determine default country code safely
    $defaultCountry = (string) cfg(['WA_COUNTRY_CODE', 'DEFAULT_COUNTRY_CODE'], '91');

    // Normalize phone to E.164-like format (uses existing normalize_phone if loaded)
    if (!function_exists('normalize_phone')) {
        function normalize_phone(string $input, string $country = '91'): string {
            $d = preg_replace('/\D+/', '', $input);
            if ($d === '') return '';
            if (strlen($d) >= 8 && $d[0] === '0') {
                // leading 0 -> strip and prefix country
                return '+' . $country . ltrim($d, '0');
            }
            if (strlen($d) === 10) return '+' . $country . $d;
            if (strpos(trim($input), '+') === 0) return '+' . $d;
            if (strpos($d, $country) === 0) return '+' . $d;
            return '+' . $country . $d;
        }
    }

    $phone = normalize_phone($phoneInput, $defaultCountry);

    // Validate E.164-like: + and 8-15 digits
    if (!preg_match('/^\+\d{8,15}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid phone number format.']);
        exit;
    }

    // Check user exists with phone & role and is active
    $user = db_fetch_one("SELECT id, name, role, is_active, school_id FROM users WHERE phone = :phone AND role = :role LIMIT 1", [
        'phone' => $phone,
        'role'  => $roleInput
    ]);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'No active account found for the provided phone and role.']);
        exit;
    }

    if (empty($user['is_active'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Account inactive. Contact administrator.']);
        exit;
    }

    // Rate limiting / cooldown configuration (safe reads)
    $resendCooldown = intval(cfg(['OTP_RESEND_COOLDOWN', 'OTP_RESEND_COOLDOWN_SEC', 'OTP_RESEND_COOLDOWN_SEC_ALT'], 60));
    $maxAttempts     = intval(cfg(['OTP_MAX_ATTEMPTS'], 5));
    $hourLimit       = intval(cfg(['OTP_HOURLY_LIMIT', 'OTP_HOURLY_LIMIT_ALT'], cfg(['OTP_HOURLY_LIMIT', 'OTP_HOURLY_LIMIT'], 10)));

    // Find latest OTP row for this phone+role
    $lastOtp = db_fetch_one(
        "SELECT id, attempts, created_at, otp_expires_at, verified FROM otp_verifications WHERE phone = :phone AND role = :role ORDER BY created_at DESC LIMIT 1",
        ['phone' => $phone, 'role' => $roleInput]
    );

    $now = time();
    if ($lastOtp && !empty($lastOtp['created_at'])) {
        $lastCreated = strtotime($lastOtp['created_at']);
        if ($lastCreated !== false && ($now - $lastCreated) < $resendCooldown) {
            $wait = $resendCooldown - ($now - $lastCreated);
            http_response_code(429);
            echo json_encode(['ok' => false, 'message' => "Please try again in {$wait} seconds."]);
            exit;
        }
        if (isset($lastOtp['attempts']) && intval($lastOtp['attempts']) >= $maxAttempts) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'message' => 'OTP attempts exceeded. Please try later or contact support.']);
            exit;
        }
    }

    // Hourly limit check
    if ($hourLimit > 0) {
        $cntRow = db_fetch_one(
            "SELECT COUNT(*) AS cnt FROM otp_verifications WHERE phone = :phone AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ['phone' => $phone]
        );
        $cnt = $cntRow ? intval($cntRow['cnt']) : 0;
        if ($cnt >= $hourLimit) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'message' => 'Too many OTP requests recently. Try again later.']);
            exit;
        }
    }

    // Prepare OTP helpers if missing
    if (!function_exists('generate_otp')) {
        function generate_otp(int $len = 4): string {
            $min = (int) pow(10, max(0, $len - 1));
            $max = (int) pow(10, $len) - 1;
            try {
                return (string) random_int($min, $max);
            } catch (Exception $e) {
                return (string) mt_rand($min, $max);
            }
        }
    }
    if (!function_exists('hash_otp')) {
        function hash_otp(string $otp): string {
            return password_hash($otp, PASSWORD_DEFAULT);
        }
    }

    $otpLength = intval(cfg(['OTP_LENGTH'], cfg(['OTP_LENGTH'], 4)));
    $otpPlain  = generate_otp($otpLength);
    $otpHash   = hash_otp($otpPlain);
    $expirySec = intval(cfg(['OTP_EXPIRY_SECONDS', 'OTP_EXPIRY_SEC'], 300));
    $expiresAt = date('Y-m-d H:i:s', time() + $expirySec);

    // Persist OTP: expire previous unverified rows + insert new one (transaction)
    db_transaction(function($pdo) use ($phone, $roleInput, $otpHash, $expiresAt) {
        $sqlExpire = "UPDATE otp_verifications SET otp_expires_at = NOW(), verified = 0 WHERE phone = :phone AND role = :role AND verified = 0";
        $stmt = $pdo->prepare($sqlExpire);
        $stmt->execute(['phone' => $phone, 'role' => $roleInput]);

        $sqlInsert = "INSERT INTO otp_verifications (phone, role, otp_hash, otp_expires_at, attempts, verified, created_at)
                      VALUES (:phone, :role, :otp_hash, :otp_expires_at, 0, 0, NOW())";
        $stmt2 = $pdo->prepare($sqlInsert);
        $stmt2->execute([
            'phone' => $phone,
            'role'  => $roleInput,
            'otp_hash' => $otpHash,
            'otp_expires_at' => $expiresAt
        ]);
    });

    // Send OTP via WhatsApp provider
    $sendResult = whatsapp_send_otp($phone, $otpPlain);

    // Log provider response but never OTP in production logs
    whatsapp_log('otp_send_attempt', [
        'phone' => $phone,
        'role'  => $roleInput,
        'provider_ok' => !empty($sendResult['ok']),
        'http' => $sendResult['http'] ?? null,
        'resp' => $sendResult['resp'] ?? null,
        'error' => $sendResult['error'] ?? null
    ]);

    if (!empty($sendResult['ok'])) {
        $resp = [
            'ok' => true,
            'message' => 'OTP sent via WhatsApp. It will expire in ' . $expirySec . ' seconds.',
            'data' => [
                'phone' => $phone,
                'role'  => $roleInput,
                'expires_in' => $expirySec
            ]
        ];
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $resp['debug'] = ['provider_resp' => $sendResult['resp'] ?? null];
        }
        echo json_encode($resp);
        exit;
    }

    $debugMsg = '';
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $debugMsg = ' Provider response: ' . json_encode($sendResult['resp'] ?? $sendResult['error']);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to send OTP via WhatsApp. Try again later.' . $debugMsg]);
    exit;

} catch (Throwable $e) {
    if (function_exists('whatsapp_log')) {
        whatsapp_log('send_otp_exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        error_log('send_otp exception: ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Internal server error.']);
    exit;
}