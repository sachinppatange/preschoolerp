<?php
/**
 * Meta WhatsApp Cloud API webhook.
 * Configure Callback URL: https://app.preschoolapp.in/whatsapp/webhook.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/db_compat.php')) {
    require_once __DIR__ . '/../includes/db_compat.php';
}
if (file_exists(__DIR__ . '/../includes/functions.php')) {
    require_once __DIR__ . '/../includes/functions.php';
}
if (file_exists(__DIR__ . '/../includes/otp_settings.php')) {
    require_once __DIR__ . '/../includes/otp_settings.php';
}
require_once __DIR__ . '/../includes/whatsapp_config.php';
require_once __DIR__ . '/../includes/whatsapp_inbox.php';

$rawBody = (string) file_get_contents('php://input');
$settings = wa_inbox_settings();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $qs = [];
    parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $qs);
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? $qs['hub.mode'] ?? $qs['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? $qs['hub.verify_token'] ?? $qs['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? $qs['hub.challenge'] ?? $qs['hub_challenge'] ?? '');
    $expected = (string) $settings['verify_token'];
    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
        whatsapp_log('webhook_verify_success', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    whatsapp_log('webhook_verify_failed', ['mode' => $mode, 'token_ok' => $token !== '']);
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$sigHeader = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '');
$appSecret = (string) $settings['app_secret'];
if ($appSecret !== '') {
    $sig = str_starts_with($sigHeader, 'sha256=') ? substr($sigHeader, 7) : $sigHeader;
    $computed = hash_hmac('sha256', $rawBody, $appSecret);
    if ($sig === '' || !hash_equals($computed, $sig)) {
        whatsapp_log('webhook_signature_invalid', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        http_response_code(403);
        echo 'Invalid signature';
        exit;
    }
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

whatsapp_log('webhook_received', [
    'object' => $payload['object'] ?? '',
    'entry_count' => isset($payload['entry']) && is_array($payload['entry']) ? count($payload['entry']) : 0,
]);

foreach (($payload['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        foreach (($value['statuses'] ?? []) as $status) {
            if (!is_array($status)) {
                continue;
            }
            $msgId = (string) ($status['id'] ?? '');
            $st = (string) ($status['status'] ?? '');
            if ($msgId !== '' && $st !== '') {
                wa_inbox_update_status($msgId, $st);
            }
        }
        $profileName = '';
        foreach (($value['contacts'] ?? []) as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $n = trim((string) ($contact['profile']['name'] ?? ''));
            if ($n !== '') {
                $profileName = $n;
                break;
            }
        }
        foreach (($value['messages'] ?? []) as $message) {
            if (is_array($message)) {
                wa_inbox_handle_incoming($message, $settings, $profileName);
            }
        }
        foreach (($value['message_echoes'] ?? []) as $echo) {
            if (!is_array($echo)) {
                continue;
            }
            wa_inbox_save([
                'direction' => 'out',
                'phone' => wa_inbox_phone((string) ($echo['to'] ?? $echo['recipient_id'] ?? '')),
                'wa_message_id' => (string) ($echo['id'] ?? ''),
                'type' => (string) ($echo['type'] ?? 'text'),
                'body' => wa_inbox_extract_body($echo),
                'status' => 'echo',
                'raw_json' => $echo,
            ]);
        }
    }
}

http_response_code(200);
echo 'EVENT_RECEIVED';
exit;
