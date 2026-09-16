<?php
/** teacher/login.php — Teacher login via WhatsApp OTP. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/login_otp.php';

$otp = login_otp_process([
    'role_slug' => 'teacher',
    'ctx_key' => 'teacher_otp_ctx',
    'redirect' => '/teacher/dashboard.php',
    'deny_message' => 'No teacher account found for this number. Please contact the administrator.',
    'inactive_message' => 'Your account is not active. Contact administrator.',
    'verify_fail_message' => 'No teacher account found for the verified phone number. Contact administrator.',
    'log_prefix' => 'teacher',
]);

$msg_error = $otp['msg_error'];
$msg_info = $otp['msg_info'];
$otp_active = $otp['otp_active'];
$cooldownRemaining = $otp['cooldownRemaining'];
$ctx = $otp['ctx'];

$login_role = 'teacher';
$login_title = 'Teacher Login';
$login_subtitle = 'Enter your registered phone number to receive an OTP on WhatsApp.';
$login_hint = 'Only registered teacher numbers can log in.';
require_once __DIR__ . '/../includes/login_shell.php';
$csrf_token = get_csrf_token();
require_once __DIR__ . '/../includes/login_otp_view.php';
require_once __DIR__ . '/../includes/login_shell_end.php';
