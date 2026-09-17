<?php
/**
 * includes/login_otp.php
 *
 * Shared WhatsApp OTP login handler for all panel roles.
 *
 * Usage in {role}/login.php:
 *   $otp = login_otp_process([
 *       'role_slug' => 'owner',
 *       'ctx_key'   => 'owner_otp_ctx',
 *       'redirect'  => '/owner/dashboard.php',
 *       'deny_message' => 'You are not an admin.',
 *       'inactive_message' => 'No active owner account found for the provided phone.',
 *       'verify_fail_message' => 'Owner account not found or inactive.',
 *       'log_prefix' => 'owner',
 *   ]);
 *   extract($otp); // msg_error, msg_info, otp_active, cooldownRemaining
 */
declare(strict_types=1);

/**
 * Load dependencies required for OTP login pages.
 */
function login_otp_bootstrap(): void
{
    require_once __DIR__ . '/config.php';

    // Must use the same session cookie as panel pages (pps_session), not default PHPSESSID.
    if (file_exists(__DIR__ . '/session_start.php')) {
        require_once __DIR__ . '/session_start.php';
    } elseif (file_exists(__DIR__ . '/functions.php')) {
        require_once __DIR__ . '/functions.php';
        ensure_session_started();
    } elseif (session_status() === PHP_SESSION_NONE) {
        if (defined('SESSION_COOKIE_NAME')) {
            session_name(SESSION_COOKIE_NAME);
        }
        session_start();
    }

    require_once __DIR__ . '/csrf.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    if (file_exists(__DIR__ . '/otp_settings.php')) {
        require_once __DIR__ . '/otp_settings.php';
    }

    if (file_exists(__DIR__ . '/whatsapp_config.php')) {
        require_once __DIR__ . '/whatsapp_config.php';
    }
}

if (!function_exists('login_otp_to_e164')) {
    function login_otp_to_e164(string $mobile, string $countryCode = '91'): ?string
    {
        $digits = preg_replace('/\D+/', '', $mobile);
        if ($digits === '') {
            return null;
        }
        if (strpos($digits, $countryCode) === 0 && strlen($digits) >= strlen($countryCode) + 6) {
            return '+' . $digits;
        }
        if (strlen($digits) === 10) {
            return '+' . $countryCode . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '+' . $countryCode . substr($digits, 1);
        }
        return '+' . $countryCode . $digits;
    }
}

if (!function_exists('login_otp_has_active')) {
    function login_otp_has_active(array $ctx): bool
    {
        return !empty($ctx['mobile_e164']) && !empty($ctx['hash']) && time() < (int) ($ctx['expires_at'] ?? 0);
    }
}

if (!function_exists('login_otp_reset_ctx')) {
    function login_otp_reset_ctx(string $key): void
    {
        $_SESSION[$key] = [
            'hash' => null,
            'mobile_e164' => null,
            'expires_at' => 0,
            'attempts' => 0,
            'last_sent_at' => 0,
        ];
    }
}

/**
 * Build role SQL clause and params for user lookup.
 *
 * @param array<int,string> $roles
 * @return array{0:string,1:array<int,string>}
 */
function login_otp_role_clause(array $roles): array
{
    $roles = array_values(array_filter(array_map('strval', $roles)));
    if (count($roles) === 1) {
        return ["role = ?", [$roles[0]]];
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    return ["role IN ({$placeholders})", $roles];
}

/**
 * @param array<string,mixed> $config
 * @return array{msg_error:string,msg_info:string,otp_active:bool,cooldownRemaining:int,ctx:array}
 */
function login_otp_process(array $config): array
{
    login_otp_bootstrap();

    $roleSlug = (string) ($config['role_slug'] ?? 'owner');
    $roles = $config['roles'] ?? [$roleSlug];
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    $ctxKey = (string) ($config['ctx_key'] ?? ($roleSlug . '_otp_ctx'));
    $redirect = (string) ($config['redirect'] ?? ('/' . $roleSlug . '/dashboard.php'));
    $denyMessage = (string) ($config['deny_message'] ?? 'You are not authorized to log in.');
    $inactiveMessage = (string) ($config['inactive_message'] ?? 'Account is not active.');
    $verifyFailMessage = (string) ($config['verify_fail_message'] ?? $denyMessage);
    $logPrefix = (string) ($config['log_prefix'] ?? $roleSlug);
    $regenerateSession = (bool) ($config['regenerate_session'] ?? ($roleSlug !== 'owner'));

    $otpLength = defined('OTP_LENGTH') ? (int) constant('OTP_LENGTH') : 4;
    $otpExpiry = defined('OTP_EXPIRY_SECONDS') ? (int) constant('OTP_EXPIRY_SECONDS') : 300;
    if (function_exists('otp_validity_minutes')) {
        $mins = otp_validity_minutes();
        if ($mins > 0) {
            $otpExpiry = $mins * 60;
        }
    }
    $otpCooldown = defined('OTP_RESEND_COOLDOWN') ? (int) constant('OTP_RESEND_COOLDOWN') : 60;
    $waCountry = defined('WA_COUNTRY_CODE') ? (string) constant('WA_COUNTRY_CODE') : '91';
    $appEnv = defined('APP_ENV') ? (string) constant('APP_ENV') : 'production';
    $appDebug = ($appEnv === 'development') || (defined('APP_DEBUG') && constant('APP_DEBUG'));

    if (!isset($_SESSION[$ctxKey]) || !is_array($_SESSION[$ctxKey])) {
        login_otp_reset_ctx($ctxKey);
    }
    $ctx = &$_SESSION[$ctxKey];

    $msgError = '';
    $msgInfo = '';

    $findUser = function (string $phonePlus, string $phonePlain, bool $fullRow = false) use ($roles): ?array {
        [$roleSql, $roleParams] = login_otp_role_clause($roles);
        $cols = $fullRow
            ? 'id, name, phone, role, school_id, whatsapp_id, is_active, meta'
            : 'id, name, role, is_active, meta';
        $digits = preg_replace('/\D+/', '', $phonePlain) ?? '';
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
        $variants = array_values(array_unique(array_filter([
            $phonePlus,
            $phonePlain,
            $last10,
            '91' . $last10,
            '+91' . $last10,
            '0' . $last10,
        ])));
        $ph = implode(',', array_fill(0, count($variants), '?'));
        $sql = "SELECT {$cols} FROM users WHERE {$roleSql}
                  AND (
                    phone IN ({$ph})
                    OR whatsapp_id IN ({$ph})
                    OR RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(phone,''), '+', ''), ' ', ''), '-', ''), 10) = ?
                    OR RIGHT(REPLACE(REPLACE(REPLACE(IFNULL(whatsapp_id,''), '+', ''), ' ', ''), '-', ''), 10) = ?
                  )
                ORDER BY id ASC LIMIT 1";
        $params = array_merge($roleParams, $variants, $variants, [$last10, $last10]);
        return db_fetch_one($sql, $params);
    };

    $roleAllowed = function (?array $user) use ($roles): bool {
        if (!$user) {
            return false;
        }
        $role = strtolower((string) ($user['role'] ?? ''));
        return in_array($role, array_map('strtolower', $roles), true);
    };

    $sendOtp = function (string $phonePlus, array $user = []) use (&$ctx, $otpLength, $otpExpiry, $otpCooldown, $logPrefix, $appDebug): array {
        $now = time();
        if (!empty($ctx['last_sent_at']) && ($now - (int) $ctx['last_sent_at']) < $otpCooldown) {
            $wait = $otpCooldown - ($now - (int) $ctx['last_sent_at']);
            return ['ok' => false, 'error' => "Please try again in {$wait} seconds."];
        }

        $min = (int) pow(10, max(0, $otpLength - 1));
        $max = (int) pow(10, $otpLength) - 1;
        try {
            $otp = (string) random_int($min, $max);
        } catch (Throwable $e) {
            $otp = (string) mt_rand($min, $max);
        }

        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $expires = time() + $otpExpiry;

        $simulateDev = static function () use ($logPrefix, $phonePlus, $otp): array {
            $logDir = defined('LOGS_PATH') ? rtrim((string) LOGS_PATH, '/\\') : (dirname(__DIR__) . '/storage');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $logLine = '[' . date('Y-m-d H:i:s') . "] {$logPrefix} OTP (dev/simulated) to {$phonePlus} => {$otp}" . PHP_EOL;
            @file_put_contents($logDir . '/whatsapp_otp_dev.log', $logLine, FILE_APPEND | LOCK_EX);
            return ['ok' => true, 'resp' => 'simulated', 'info' => 'OTP has been sent (development log).'];
        };

        if (function_exists('otp_send_login_channels')) {
            $res = otp_send_login_channels($phonePlus, $otp, $user);
            $sent = !empty($res['ok']);
            if (!$sent && $appDebug) {
                $res = $simulateDev();
                $sent = true;
            }
        } elseif (function_exists('whatsapp_send_otp')) {
            $res = whatsapp_send_otp($phonePlus, $otp);
            $sent = !empty($res['ok']);
            if ($sent) {
                $res['info'] = 'OTP has been sent. Please check WhatsApp.';
            }
            if (!$sent && $appDebug) {
                $res = $simulateDev();
                $sent = true;
            }
        } else {
            $res = $simulateDev();
            $sent = true;
        }

        if (!$sent) {
            $err = (string) ($res['error'] ?? 'Failed to send OTP. Please try again later.');
            if ($appDebug && isset($res['error'])) {
                $err .= ' Debug: ' . htmlspecialchars((string) $res['error']);
            }
            return ['ok' => false, 'error' => $err];
        }

        $ctx['hash'] = $hash;
        $ctx['mobile_e164'] = $phonePlus;
        $ctx['expires_at'] = $expires;
        $ctx['attempts'] = 0;
        $ctx['last_sent_at'] = time();

        return ['ok' => true, 'info' => (string) ($res['info'] ?? 'OTP has been sent.')];
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $incomingCsrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!validate_csrf_token($incomingCsrf)) {
            $msgError = 'Invalid security token. Please reload the page and try again.';
        } else {
            $action = $_POST['action'] ?? '';

            if ($action === 'send_otp') {
                $mobileInput = trim((string) ($_POST['mobile'] ?? ''));
                if (!preg_match('/^\d{10}$/', $mobileInput)) {
                    $msgError = 'Please enter a valid 10-digit phone number (e.g. 9812345678).';
                } else {
                    $toE164 = login_otp_to_e164($mobileInput, $waCountry);
                    if ($toE164 === null) {
                        $msgError = 'Invalid phone number.';
                    } else {
                        $phonePlus = $toE164;
                        $phonePlain = ltrim($toE164, '+');
                        $user = $findUser($phonePlus, $phonePlain);

                        if (!$roleAllowed($user)) {
                            $msgError = $denyMessage;
                        } elseif (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                            $msgError = $inactiveMessage;
                        } else {
                            $result = $sendOtp($phonePlus, is_array($user) ? $user : []);
                            if ($result['ok']) {
                                $msgInfo = $result['info'] ?? 'OTP sent.';
                                if (function_exists('whatsapp_log')) {
                                    whatsapp_log($logPrefix . '_send_otp', [
                                        'phone' => $phonePlus,
                                        'user_id' => $user['id'] ?? null,
                                    ]);
                                }
                            } else {
                                $msgError = $result['error'] ?? 'Failed to send OTP.';
                            }
                        }
                    }
                }
            }

            if ($action === 'resend_otp') {
                if (empty($ctx['mobile_e164'])) {
                    $msgError = 'Please request an OTP first by entering your phone number.';
                } else {
                    $phonePlus = $ctx['mobile_e164'];
                    $phonePlain = ltrim($phonePlus, '+');
                    $user = $findUser($phonePlus, $phonePlain);

                    if (!$roleAllowed($user)) {
                        $msgError = $denyMessage;
                    } else {
                        $result = $sendOtp($phonePlus, is_array($user) ? $user : []);
                        if ($result['ok']) {
                            $msgInfo = $result['info'] ?? 'OTP resent.';
                            if (function_exists('whatsapp_log')) {
                                whatsapp_log($logPrefix . '_resend_otp', [
                                    'phone' => $phonePlus,
                                    'user_id' => $user['id'] ?? null,
                                ]);
                            }
                        } else {
                            $msgError = $result['error'] ?? 'Failed to resend OTP.';
                        }
                    }
                }
            }

            if ($action === 'change_phone') {
                login_otp_reset_ctx($ctxKey);
                $msgInfo = 'You may now enter a different phone number.';
            }

            if ($action === 'login') {
                $otpInput = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));

                if (!preg_match('/^\d{' . $otpLength . '}$/', $otpInput)) {
                    $msgError = 'Please enter the ' . $otpLength . '-digit OTP.';
                } elseif (empty($ctx['hash']) || empty($ctx['mobile_e164'])) {
                    $msgError = 'No active OTP. Please request an OTP first.';
                } elseif (time() > (int) $ctx['expires_at']) {
                    $msgError = 'OTP expired. Please request a new one.';
                } else {
                    $ctx['attempts'] = (int) $ctx['attempts'] + 1;
                    if ($ctx['attempts'] > 5) {
                        login_otp_reset_ctx($ctxKey);
                        $msgError = 'Too many incorrect attempts. Please request a new OTP.';
                    } else {
                        $ok = password_verify($otpInput, (string) $ctx['hash']);
                        if ($appDebug && function_exists('whatsapp_log')) {
                            whatsapp_log($logPrefix . '_login_verify_result', [
                                'result' => $ok ? 'OK' : 'FAIL',
                                'attempts' => $ctx['attempts'],
                            ]);
                        }

                        if ($ok) {
                            if ($regenerateSession) {
                                session_regenerate_id(true);
                            }

                            $phonePlus = $ctx['mobile_e164'];
                            $phonePlain = ltrim($phonePlus, '+');
                            $userRow = $findUser($phonePlus, $phonePlain, true);

                            if ($userRow && $roleAllowed($userRow) && (int) ($userRow['is_active'] ?? 1) === 1) {
                                auth_set_session($userRow);
                                login_otp_reset_ctx($ctxKey);
                                $dest = function_exists('site_url') ? site_url($redirect) : $redirect;
                                header('Location: ' . $dest);
                                exit;
                            }

                            $msgError = $verifyFailMessage;
                        } else {
                            $msgError = 'Incorrect OTP. Please try again.';
                        }
                    }
                }
            }
        }
    }

    $otpActive = login_otp_has_active($ctx);
    $cooldownRemaining = 0;
    if ($otpActive && !empty($ctx['last_sent_at'])) {
        $cooldownRemaining = max(0, $otpCooldown - (time() - (int) $ctx['last_sent_at']));
    }

    return [
        'msg_error' => $msgError,
        'msg_info' => $msgInfo,
        'otp_active' => $otpActive,
        'cooldownRemaining' => $cooldownRemaining,
        'ctx' => $ctx,
    ];
}
