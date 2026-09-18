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
    if (file_exists(__DIR__ . '/db_compat.php')) {
        require_once __DIR__ . '/db_compat.php';
    }
    require_once __DIR__ . '/auth.php';
    if (file_exists(__DIR__ . '/panel/academic_year.php')) {
        require_once __DIR__ . '/panel/academic_year.php';
    }
    if (file_exists(__DIR__ . '/parent_account.php')) {
        require_once __DIR__ . '/parent_account.php';
    }
    if (file_exists(__DIR__ . '/otp_settings.php')) {
        require_once __DIR__ . '/otp_settings.php';
    }
    if (file_exists(__DIR__ . '/password_login.php')) {
        require_once __DIR__ . '/password_login.php';
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
 * @return array{msg_error:string,msg_info:string,otp_active:bool,cooldownRemaining:int,ctx:array,login_mode:string,otp_gateways_ok:bool,remembered_login_id:string}
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

    if (function_exists('user_password_column_ready')) {
        user_password_column_ready();
    }
    $otpGatewaysOk = function_exists('otp_login_channels_ready') ? otp_login_channels_ready() : true;
    $loginMode = strtolower(trim((string) ($_POST['login_mode'] ?? $_GET['mode'] ?? '')));
    if (!in_array($loginMode, ['otp', 'password', 'forgot'], true)) {
        $loginMode = $otpGatewaysOk ? 'otp' : 'password';
    }

    if (!isset($_SESSION[$ctxKey]) || !is_array($_SESSION[$ctxKey])) {
        login_otp_reset_ctx($ctxKey);
    }
    $ctx = &$_SESSION[$ctxKey];

    $msgError = '';
    $msgInfo = '';

    $findUser = function (string $phonePlus, string $phonePlain, bool $fullRow = false) use ($roles): ?array {
        [$roleSql, $roleParams] = login_otp_role_clause($roles);
        $hasPw = function_exists('user_password_column_ready') && user_password_column_ready();
        $cols = $fullRow
            ? 'id, name, phone, role, school_id, whatsapp_id, is_active, meta' . ($hasPw ? ', password_hash' : '')
            : 'id, name, role, is_active, meta' . ($hasPw ? ', password_hash' : '');
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
        return db_fetch_one($sql, $params) ?: null;
    };

    $emailFromMeta = static function (?array $user): string {
        if (!$user) {
            return '';
        }
        if (function_exists('staff_user_email_from_meta')) {
            return staff_user_email_from_meta($user['meta'] ?? null);
        }
        $meta = $user['meta'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $email = is_array($meta) ? strtolower(trim((string) ($meta['email'] ?? ''))) : '';
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    };

    $findByLoginId = function (string $loginId) use ($findUser, $emailFromMeta, $roles, $waCountry): ?array {
        $loginId = trim($loginId);
        if ($loginId === '') {
            return null;
        }
        if (str_contains($loginId, '@')) {
            $want = strtolower($loginId);
            [$roleSql, $roleParams] = login_otp_role_clause($roles);
            $hasPw = function_exists('user_password_column_ready') && user_password_column_ready();
            $cols = 'id, name, phone, role, school_id, whatsapp_id, is_active, meta' . ($hasPw ? ', password_hash' : '');
            $rows = db_fetch_all("SELECT {$cols} FROM users WHERE {$roleSql} AND IFNULL(meta,'') LIKE ? ORDER BY id ASC LIMIT 25", array_merge($roleParams, ['%' . $want . '%'])) ?: [];
            foreach ($rows as $row) {
                if ($emailFromMeta($row) === $want) {
                    return $row;
                }
            }
            return null;
        }
        $digits = preg_replace('/\D+/', '', $loginId) ?? '';
        if (strlen($digits) !== 10) {
            return null;
        }
        $e164 = login_otp_to_e164($digits, $waCountry) ?? ('+91' . $digits);
        return $findUser($e164, $digits, true);
    };

    $completeLogin = function (array $userRow, bool $remember = false) use ($redirect, $ctxKey): void {
        login_otp_reset_ctx($ctxKey);
        if (function_exists('login_finish_and_redirect')) {
            login_finish_and_redirect($userRow, $redirect, $remember);
        }
        auth_set_session($userRow);
        $dest = function_exists('site_url') ? site_url($redirect) : $redirect;
        header('Location: ' . $dest);
        exit;
    };

    $roleAllowed = function (?array $user) use ($roles): bool {
        if (!$user) {
            return false;
        }
        $role = strtolower((string) ($user['role'] ?? ''));
        return in_array($role, array_map('strtolower', $roles), true);
    };

    $parentYearOk = static function (?array $user) use ($roles): bool {
        if (!$user) {
            return false;
        }
        $role = strtolower((string) ($user['role'] ?? ''));
        if ($role !== 'parent') {
            return true;
        }
        if (!in_array('parent', array_map('strtolower', $roles), true)) {
            return true;
        }
        return function_exists('parent_portal_login_allowed') ? parent_portal_login_allowed($user) : true;
    };
    $parentBlockedMsg = function_exists('parent_portal_blocked_message')
        ? parent_portal_blocked_message()
        : $inactiveMessage;

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

            if ($action === 'password_login') {
                $loginMode = 'password';
                $loginId = trim((string) ($_POST['user_id'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $remember = !empty($_POST['remember_me']);
                $user = $findByLoginId($loginId);
                if (!$roleAllowed($user)) {
                    $msgError = 'No account found for this User ID.';
                } elseif (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                    $msgError = $inactiveMessage;
                } elseif (!$parentYearOk($user)) {
                    $msgError = $parentBlockedMsg;
                } elseif (empty($user['password_hash'])) {
                    $msgError = $otpGatewaysOk
                        ? 'No password is set yet. Use Login using OTP, then create a password. Or ask the school to set one.'
                        : 'No password is set for this User ID. Ask the school to set a password, or wait until OTP is available.';
                } elseif (!password_verify($password, (string) $user['password_hash'])) {
                    $msgError = 'Incorrect password.';
                } else {
                    $completeLogin($user, $remember);
                }
            }

            if ($action === 'forgot_send') {
                $loginMode = 'forgot';
                $loginId = trim((string) ($_POST['user_id'] ?? ''));
                $user = $findByLoginId($loginId);
                if (!$otpGatewaysOk) {
                    $msgError = 'OTP gateways are off, so password reset by OTP is not available. Contact the school to set a password.';
                } elseif (!$roleAllowed($user)) {
                    $msgError = 'No account found for this User ID.';
                } elseif (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                    $msgError = $inactiveMessage;
                } elseif (!$parentYearOk($user)) {
                    $msgError = $parentBlockedMsg;
                } else {
                    $digits = preg_replace('/\D+/', '', (string) ($user['phone'] ?? '')) ?? '';
                    $last10 = strlen($digits) >= 10 ? substr($digits, -10) : '';
                    if (strlen($last10) !== 10) {
                        $msgError = 'This account has no mobile number for OTP. Contact the school.';
                    } else {
                        $toE164 = login_otp_to_e164($last10, $waCountry);
                        if ($toE164 === null) {
                            $msgError = 'Invalid phone number on this account.';
                        } else {
                            $result = $sendOtp($toE164, $user);
                            if (!empty($result['ok'])) {
                                $ctx['purpose'] = 'reset';
                                $ctx['reset_user_id'] = (int) $user['id'];
                                $msgInfo = $result['info'] ?? 'OTP sent. Enter it below to set a new password.';
                                $loginMode = 'forgot';
                            } else {
                                $msgError = $result['error'] ?? 'Failed to send OTP.';
                            }
                        }
                    }
                }
            }

            if ($action === 'forgot_save') {
                $loginMode = 'forgot';
                $otpInput = preg_replace('/\D+/', '', (string) ($_POST['otp'] ?? ''));
                $newPw = (string) ($_POST['new_password'] ?? '');
                $newPw2 = (string) ($_POST['new_password_confirm'] ?? '');
                $minLen = function_exists('login_password_min_length') ? login_password_min_length() : 6;
                if (!preg_match('/^\d{' . $otpLength . '}$/', $otpInput)) {
                    $msgError = 'Please enter the ' . $otpLength . '-digit OTP.';
                } elseif (empty($ctx['hash']) || empty($ctx['mobile_e164']) || ($ctx['purpose'] ?? '') !== 'reset') {
                    $msgError = 'Request a password-reset OTP first.';
                } elseif (time() > (int) $ctx['expires_at']) {
                    $msgError = 'OTP expired. Please request a new one.';
                } elseif ($newPw !== $newPw2) {
                    $msgError = 'New password and confirmation do not match.';
                } elseif (strlen($newPw) < $minLen) {
                    $msgError = 'Password must be at least ' . $minLen . ' characters.';
                } elseif (!password_verify($otpInput, (string) $ctx['hash'])) {
                    $msgError = 'Incorrect OTP. Please try again.';
                } else {
                    $phonePlus = $ctx['mobile_e164'];
                    $userRow = $findUser($phonePlus, ltrim($phonePlus, '+'), true);
                    if ($userRow && $roleAllowed($userRow) && (int) ($userRow['is_active'] ?? 1) === 1 && $parentYearOk($userRow)) {
                        if (function_exists('login_set_user_password')) {
                            login_set_user_password((int) $userRow['id'], $newPw);
                            $userRow['password_hash'] = 'set';
                        }
                        login_otp_reset_ctx($ctxKey);
                        $completeLogin($userRow, false);
                    } else {
                        $msgError = $verifyFailMessage;
                    }
                }
            }

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
                        } elseif (!$parentYearOk($user)) {
                            $msgError = $parentBlockedMsg;
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
                    } elseif (!$parentYearOk($user)) {
                        $msgError = $parentBlockedMsg;
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

                            if ($userRow && $roleAllowed($userRow) && (int) ($userRow['is_active'] ?? 1) === 1 && $parentYearOk($userRow)) {
                                $completeLogin($userRow, false);
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
        'login_mode' => $loginMode,
        'otp_gateways_ok' => $otpGatewaysOk,
        'remembered_login_id' => function_exists('login_remember_read') ? login_remember_read() : '',
    ];
}
