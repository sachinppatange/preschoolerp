<?php
/** parent/login.php — Parent login via WhatsApp OTP. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/login_otp.php';

$otp = login_otp_process([
    'role_slug' => 'parent',
    'ctx_key' => 'parent_otp_ctx',
    'redirect' => '/parent/dashboard.php',
    'deny_message' => 'No parent account found for this number. Please register first or contact school.',
    'inactive_message' => 'Your account is not active. Please contact the school.',
    'verify_fail_message' => 'No parent account found for the verified phone number. Contact the school.',
    'log_prefix' => 'parent',
]);

$msg_error = $otp['msg_error'];
$msg_info = $otp['msg_info'];
$otp_active = $otp['otp_active'];
$cooldownRemaining = $otp['cooldownRemaining'];
$ctx = $otp['ctx'];

$login_role = 'parent';
$login_title = 'Parent Login';
$login_subtitle = 'Enter your registered phone number to receive an OTP on WhatsApp.';
$login_hint = 'Only registered parent numbers can log in.';
require_once __DIR__ . '/../includes/login_shell.php';
$csrf_token = get_csrf_token();
require_once __DIR__ . '/../includes/login_otp_view.php';
require_once __DIR__ . '/../includes/login_shell_end.php';
