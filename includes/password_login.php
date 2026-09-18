<?php
/**
 * Password login fallback when SMS / Email / WhatsApp OTP gateways are down.
 * User ID is the registered 10-digit mobile (or the account email).
 */
declare(strict_types=1);

function user_password_column_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        if (function_exists('db_fetch_one')) {
            $row = db_fetch_one("SHOW COLUMNS FROM users LIKE 'password_hash'");
            if (is_array($row) && $row) {
                $ok = true;
                return true;
            }
        }
        if (function_exists('db_execute')) {
            db_execute('ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL');
            $ok = true;
            return true;
        }
    } catch (Throwable $e) {
        $ok = false;
        return false;
    }
    $ok = false;
    return false;
}

function user_login_id_from_row(array $user): string
{
    $digits = preg_replace('/\D+/', '', (string) ($user['phone'] ?? '')) ?? '';
    if (strlen($digits) >= 10) {
        return substr($digits, -10);
    }
    if (function_exists('staff_user_email_from_meta')) {
        $email = staff_user_email_from_meta($user['meta'] ?? null);
        if ($email !== '') {
            return $email;
        }
    }
    return $digits;
}

function otp_login_channels_ready(): bool
{
    if (!function_exists('otp_settings_load')) {
        return true;
    }
    try {
        $cfg = otp_settings_load();
    } catch (Throwable $e) {
        return false;
    }
    $smsOn = !empty($cfg['sms']['enabled']) && trim((string) ($cfg['sms']['auth_key'] ?? '')) !== '';
    $emOn = !empty($cfg['email']['enabled']) && trim((string) ($cfg['email']['send_mail_token'] ?? '')) !== '';
    $waOn = !empty($cfg['whatsapp']['enabled']) && trim((string) ($cfg['whatsapp']['access_token'] ?? '')) !== '';
    return $smsOn || $emOn || $waOn;
}

function auth_role_dashboard_path(?string $role): string
{
    $role = strtolower((string) $role);
    $map = [
        'owner' => '/owner/dashboard.php',
        'accounts' => '/accounts/dashboard.php',
        'teacher' => '/teacher/dashboard.php',
        'reception' => '/reception/dashboard.php',
        'staff' => '/reception/dashboard.php',
        'parent' => '/parent/dashboard.php',
    ];
    return $map[$role] ?? '/login.php';
}

function login_remember_read(): string
{
    return trim((string) ($_COOKIE['pps_login_id'] ?? ''));
}

function login_remember_write(string $loginId, bool $remember): void
{
    $loginId = trim($loginId);
    $secure = defined('SESSION_SECURE') ? (bool) SESSION_SECURE : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $opts = [
        'expires' => $remember && $loginId !== '' ? time() + 60 * 60 * 24 * 30 : time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie('pps_login_id', $remember ? $loginId : '', $opts);
}

function login_password_min_length(): int
{
    return 6;
}

function login_set_user_password(int $userId, string $plain): bool
{
    if ($userId <= 0 || strlen($plain) < login_password_min_length() || !user_password_column_ready()) {
        return false;
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    try {
        if (function_exists('db_execute')) {
            db_execute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [$hash, $userId]);
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function login_finish_and_redirect(array $user, string $redirect, bool $remember = false): void
{
    if (!function_exists('auth_set_session')) {
        require_once __DIR__ . '/auth.php';
    }
    auth_set_session($user);
    $loginId = user_login_id_from_row($user);
    login_remember_write($loginId, $remember);
    $_SESSION['post_login_redirect'] = $redirect;
    $needsPassword = user_password_column_ready() && empty($user['password_hash']);
    if ($needsPassword) {
        $_SESSION['force_set_password'] = 1;
        $dest = function_exists('site_url') ? site_url('/account/password.php') : '/account/password.php';
    } else {
        unset($_SESSION['force_set_password']);
        $dest = function_exists('site_url') ? site_url($redirect) : $redirect;
    }
    header('Location: ' . $dest);
    exit;
}
