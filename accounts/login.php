<?php
/** accounts/login.php — Accounts login via WhatsApp OTP. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/login_otp.php';

$otp = login_otp_process([
    'role_slug' => 'accounts',
    'ctx_key' => 'accounts_otp_ctx',
    'redirect' => '/accounts/dashboard.php',
    'deny_message' => 'You are not authorized to access the accounts area.',
    'inactive_message' => 'No active accounts account found for the provided phone.',
    'verify_fail_message' => 'No active accounts account found for the verified phone number. Contact administrator.',
    'log_prefix' => 'accounts',
]);

$msg_error = $otp['msg_error'];
$msg_info = $otp['msg_info'];
$otp_active = $otp['otp_active'];
$cooldownRemaining = $otp['cooldownRemaining'];
$ctx = $otp['ctx'];

$login_role = 'accounts';
$login_title = 'Accounts Login';
$login_subtitle = 'Enter your registered phone number to receive an OTP on WhatsApp.';
$login_hint = 'Only registered accounts staff numbers can log in.';
require_once __DIR__ . '/../includes/login_shell.php';
$csrf_token = get_csrf_token();
require_once __DIR__ . '/../includes/login_otp_view.php';
require_once __DIR__ . '/../includes/login_shell_end.php';
