<?php
/**
 * whatsapp/webhook.php
 *
 * WhatsApp Cloud API webhook handler (verification + delivery/read callbacks + incoming messages).
 *
 * Supports:
 *  - GET  : verification handshake (hub.mode, hub.verify_token, hub.challenge)
 *  - POST : delivery/read/status callbacks (X-Hub-Signature-256 verification)
 *
 * Security:
 *  - Verify GET verify_token (WHATSAPP_VERIFY_TOKEN)
 *  - Verify POST signature using app secret (WHATSAPP_APP_SECRET) and header X-Hub-Signature-256
 *  - Log events for audit but NEVER log OTP plaintext
 *
 * Usage:
 *  - Configure webhook URL in your Meta App (WhatsApp Cloud API)
 *  - Set environment variables in includes/config.php or .env:
 *      WHATSAPP_VERIFY_TOKEN=<random-string-for-verification>
 *      WHATSAPP_APP_SECRET=<your-app-secret-from-facebook>
 *
 * Note:
 *  - This handler intentionally keeps DB-side effects minimal (logging). If you want to
 *    persist status updates into DB, add appropriate table and update logic (use prepared statements).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_config.php'; // provides whatsapp_log()

// Read raw body once
$rawBody = file_get_contents('php://input');

// Helper: send JSON response and exit
function json_exit($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Verify GET challenge (subscription verification)
 * Meta sends: ?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? null;
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? null;

    // Accept either naming (some deployments differ)
    if ($mode === null && isset($_GET['hub.mode'])) {
        $mode = $_GET['hub.mode'];
    }
    if ($token === null && isset($_GET['hub.verify_token'])) {
        $token = $_GET['hub.verify_token'];
    }
    if ($challenge === null && isset($_GET['hub.challenge'])) {
        $challenge = $_GET['hub.challenge'];
    }

    // Use env verify token
    $expectedToken = env('pioneerplayschool_webhook', env('pioneerplayschool_webhook', ''));

    if ($mode === 'subscribe' && !empty($token) && $token === $expectedToken) {
        // Verified
        whatsapp_log('webhook_verify_success', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'time' => date('c')]);
        // echo challenge as plain text (as Facebook expects)
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    } else {
        whatsapp_log('webhook_verify_failed', ['mode' => $mode, 'token_provided' => !!$token, 'expected_present' => !empty($expectedToken)]);
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Verify request signature for POSTs.
 * Meta sends header 'X-Hub-Signature-256: sha256=...'
 */
function verify_signature($payload, $headerSignature)
{
    // If no app secret configured, skip verification (but log a warning)
    $appSecret = env('WHATSAPP_APP_SECRET', '');
    if (empty($appSecret)) {
        // Development mode: do not block but warn
        if (defined('APP_ENV') && APP_ENV === 'development') {
            whatsapp_log('webhook_signature_skipped', ['reason' => 'no_app_secret']);
        }
        return true;
    }

    if (empty($headerSignature)) {
        return false;
    }

    // header format: "sha256=..."
    if (strpos($headerSignature, 'sha256=') === 0) {
        $sig = substr($headerSignature, 7);
    } else {
        $sig = $headerSignature;
    }

    $computed = hash_hmac('sha256', $payload, $appSecret);
    // Use hash_equals to mitigate timing attacks
    return hash_equals($computed, $sig);
}

// Only handle POST from here
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, GET');
    echo 'Method Not Allowed';
    exit;
}

// Get signature header (can vary by server; try common names)
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? null;

// Verify signature
if (!verify_signature($rawBody, $sigHeader)) {
    whatsapp_log('webhook_signature_invalid', ['remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'header' => $sigHeader]);
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

// Parse JSON payload
$payload = json_decode($rawBody, true);
if ($payload === null) {
    whatsapp_log('webhook_invalid_json', ['raw' => substr($rawBody, 0, 4000)]);
    // Respond 400 but do not reveal internal details
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

// Log receipt (metadata only, not entire body in prod)
whatsapp_log('webhook_received', ['object' => $payload['object'] ?? '', 'entry_count' => isset($payload['entry']) ? count($payload['entry']) : 0]);

/**
 * Process each entry/change per WhatsApp Cloud API docs.
 * We are primarily interested in 'statuses' changes (delivery/read updates) and optionally inbound messages.
 */
if (isset($payload['entry']) && is_array($payload['entry'])) {
    foreach ($payload['entry'] as $entry) {
        // Each entry may contain changes array
        $changes = $entry['changes'] ?? [];
        foreach ($changes as $change) {
            $value = $change['value'] ?? [];
            // STATUS updates (message delivery / read)
            if (isset($value['statuses']) && is_array($value['statuses'])) {
                foreach ($value['statuses'] as $status) {
                    // Typical fields: id, status, timestamp, recipiend_id (to), conversation, pricing...
                    $msgId = $status['id'] ?? null;
                    $statusType = $status['status'] ?? null; // e.g., sent, delivered, read, failed
                    $recipient = $status['recipient_id'] ?? ($status['to'] ?? null); // sometimes 'recipient_id' or 'to'
                    $timestamp = isset($status['timestamp']) ? date('c', intval($status['timestamp'])) : null;
                    // Log structured info
                    $logData = [
                        'msg_id' => $msgId,
                        'status' => $statusType,
                        'recipient' => $recipient,
                        'timestamp' => $timestamp,
                        'raw' => $status // small raw dump; careful in prod
                    ];

                    // Important: Do NOT log OTP plaintext. status does not contain OTP content.
                    whatsapp_log('webhook_status_update', $logData);

                    // Optional: If you maintain a messages table, update delivery status there.
                    // Example pseudo-code:
                    // db_execute("UPDATE whatsapp_messages SET status = :status, delivered_at = NOW() WHERE message_id = :id", ['status'=>$statusType, 'id'=>$msgId]);

                    // Optional: If you wish to mark an OTP as "delivered" in otp_verifications:
                    // - You would need a linking value (e.g., store provider message_id when sending OTP)
                    // - Then you can update otp_verifications row for that message_id
                }
            }

            // INCOMING MESSAGES (users replying) - optional handling
            if (isset($value['messages']) && is_array($value['messages'])) {
                foreach ($value['messages'] as $message) {
                    // message fields: from, id, timestamp, text/body, type, etc.
                    $from = $message['from'] ?? null;
                    $msgId = $message['id'] ?? null;
                    $type = $message['type'] ?? null;
                    $timestamp = isset($message['timestamp']) ? date('c', intval($message['timestamp'])) : null;
                    // Extract text (if present)
                    $text = null;
                    if (isset($message['text']['body'])) {
                        $text = substr($message['text']['body'], 0, 2000); // limit size
                    }

                    $logData = [
                        'from' => $from,
                        'msg_id' => $msgId,
                        'type' => $type,
                        'timestamp' => $timestamp,
                        'text_preview' => $text !== null ? (strlen($text) > 500 ? substr($text, 0, 500) . '...' : $text) : null
                    ];
                    whatsapp_log('webhook_incoming_message', $logData);

                    // Optional: implement auto-reply, store message in DB, notify staff, etc.
                    // Be careful: follow WhatsApp policies for automated replies and opt-in.
                }
            }
        }
    }
}

// Respond 200 - Meta requires HTTP 200 within a few seconds to consider webhook delivered
http_response_code(200);
echo 'EVENT_RECEIVED';
exit;