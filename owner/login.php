<?php
/** owner/login.php — Owner login via WhatsApp OTP. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/login_otp.php';

$otp = login_otp_process([
    'role_slug' => 'owner',
    'ctx_key' => 'owner_otp_ctx',
    'redirect' => '/owner/dashboard.php',
    'deny_message' => 'You are not an admin.',
    'inactive_message' => 'No active owner account found for the provided phone.',
    'verify_fail_message' => 'Owner account not found or inactive.',
    'log_prefix' => 'owner',
    'regenerate_session' => false,
]);

$msg_error = $otp['msg_error'];
$msg_info = $otp['msg_info'];
$otp_active = $otp['otp_active'];
$cooldownRemaining = $otp['cooldownRemaining'];
$ctx = $otp['ctx'];

$login_role = 'owner';
$login_title = 'Owner Login';
$login_subtitle = 'Enter your registered phone number to receive an OTP on WhatsApp.';
$login_hint = 'Only registered owner numbers can log in.';
require_once __DIR__ . '/../includes/login_shell.php';
$csrf_token = get_csrf_token();
require_once __DIR__ . '/../includes/login_otp_view.php';
require_once __DIR__ . '/../includes/login_shell_end.php';
