<?php
// debug_whatsapp_send.php — local test only
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/whatsapp_config.php';

$testPhone = '+919096463943'; // replace with a real E.164 test number you control
$testOtp = '1234';
$result = whatsapp_send_otp($testPhone, $testOtp);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'time' => date('c'),
    'config' => [
        'WHATSAPP_PROVIDER_KEY' => (defined('WHATSAPP_PROVIDER_KEY') ? (WHATSAPP_PROVIDER_KEY === '' ? '<empty>' : '<SET>') : '<undefined>'),
        'WHATSAPP_PHONE_NUMBER_ID' => (defined('WHATSAPP_PHONE_NUMBER_ID') ? (WHATSAPP_PHONE_NUMBER_ID === '' ? '<empty>' : WHATSAPP_PHONE_NUMBER_ID) : '<undefined>'),
        'APP_ENV' => (defined('APP_ENV') ? APP_ENV : '<undefined>'),
    ],
    'result' => $result
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);