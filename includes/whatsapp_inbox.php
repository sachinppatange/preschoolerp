<?php
/**
 * WhatsApp inbox: store inbound/outbound Cloud API messages and simple auto-replies.
 */
declare(strict_types=1);

function wa_inbox_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('db_execute')) {
        return;
    }
    try {
        db_execute("CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            school_id INT UNSIGNED NOT NULL DEFAULT 1,
            direction ENUM('in','out') NOT NULL,
            phone VARCHAR(32) NOT NULL,
            contact_name VARCHAR(120) DEFAULT NULL,
            wa_message_id VARCHAR(128) DEFAULT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'text',
            body TEXT NULL,
            status VARCHAR(32) DEFAULT NULL,
            is_bot TINYINT(1) NOT NULL DEFAULT 0,
            raw_json MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wa_message_id (wa_message_id),
            KEY idx_wa_phone (phone, created_at),
            KEY idx_wa_dir (direction, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $done = false;
        return;
    }
    try {
        $col = db_fetch_one("SHOW COLUMNS FROM whatsapp_messages LIKE 'contact_name'");
        if (!$col) {
            db_execute('ALTER TABLE whatsapp_messages ADD COLUMN contact_name VARCHAR(120) NULL AFTER phone');
        }
    } catch (Throwable $e) {
        // older servers without ALTER privilege still work without the column
    }
}

function wa_inbox_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($d) === 10) {
        return '91' . $d;
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        return '91' . substr($d, 1);
    }
    return $d;
}

function wa_inbox_webhook_url(): string
{
    if (function_exists('site_url')) {
        return site_url('/whatsapp/webhook.php');
    }
    return rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/') . '/whatsapp/webhook.php';
}

function wa_inbox_settings(?array $cfg = null): array
{
    if ($cfg === null && function_exists('otp_settings_load')) {
        $cfg = otp_settings_load();
    }
    $wa = is_array($cfg['whatsapp'] ?? null) ? $cfg['whatsapp'] : [];
    $token = trim((string) ($wa['verify_token'] ?? ''));
    if ($token === '' && function_exists('env')) {
        $token = trim((string) env('WHATSAPP_VERIFY_TOKEN', env('pioneerplayschool_webhook', '')));
    }
    $secret = trim((string) ($wa['app_secret'] ?? ''));
    if ($secret === '' && function_exists('env')) {
        $secret = trim((string) env('WHATSAPP_APP_SECRET', ''));
    }
    return [
        'verify_token' => $token !== '' ? $token : 'preschoolapp_wa_hook',
        'app_secret' => $secret,
        'autobot' => !empty($wa['autobot']),
        'autobot_default' => (string) ($wa['autobot_default'] ?? 'Thank you for messaging the school. We will reply here. For Parent Portal login use your registered mobile number.'),
        'autobot_rules' => (string) ($wa['autobot_rules'] ?? "fees | Please check Parent Portal → Fees, or call the school office.\nadmission | New admission is done at the school reception.\notp | Use your registered 10-digit mobile on the Parent / Staff login page."),
    ];
}

function wa_inbox_save(array $row): int
{
    wa_inbox_ensure_table();
    $waId = trim((string) ($row['wa_message_id'] ?? ''));
    if ($waId !== '' && function_exists('db_fetch_one')) {
        $exists = db_fetch_one('SELECT id FROM whatsapp_messages WHERE wa_message_id = ? LIMIT 1', [$waId]);
        if ($exists) {
            if (!empty($row['status'])) {
                db_execute('UPDATE whatsapp_messages SET status = ? WHERE id = ?', [(string) $row['status'], (int) $exists['id']]);
            }
            return (int) $exists['id'];
        }
    }
    $phone = wa_inbox_phone((string) ($row['phone'] ?? ''));
    if ($phone === '') {
        return 0;
    }
    try {
        $name = trim((string) ($row['contact_name'] ?? ''));
        if ($waId === '') {
            $waId = 'local-' . bin2hex(random_bytes(8));
        }
        db_execute(
            'INSERT INTO whatsapp_messages (school_id, direction, phone, contact_name, wa_message_id, type, body, status, is_bot, raw_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) ($row['school_id'] ?? 1),
                ($row['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                $phone,
                $name !== '' ? $name : null,
                $waId,
                (string) ($row['type'] ?? 'text'),
                $row['body'] ?? null,
                $row['status'] ?? null,
                !empty($row['is_bot']) ? 1 : 0,
                isset($row['raw_json']) ? (is_string($row['raw_json']) ? $row['raw_json'] : json_encode($row['raw_json'])) : null,
                $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
        return function_exists('db_last_insert_id') ? (int) db_last_insert_id() : 0;
    } catch (Throwable $e) {
        try {
            db_execute(
                'INSERT INTO whatsapp_messages (school_id, direction, phone, wa_message_id, type, body, status, is_bot, raw_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) ($row['school_id'] ?? 1),
                    ($row['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                    $phone,
                    $waId,
                    (string) ($row['type'] ?? 'text'),
                    $row['body'] ?? null,
                    $row['status'] ?? null,
                    !empty($row['is_bot']) ? 1 : 0,
                    isset($row['raw_json']) ? (is_string($row['raw_json']) ? $row['raw_json'] : json_encode($row['raw_json'])) : null,
                    $row['created_at'] ?? date('Y-m-d H:i:s'),
                ]
            );
            return function_exists('db_last_insert_id') ? (int) db_last_insert_id() : 0;
        } catch (Throwable $e2) {
            if (function_exists('whatsapp_log')) {
                whatsapp_log('inbox_save_failed', ['error' => $e2->getMessage(), 'phone' => $phone]);
            }
            return 0;
        }
    }
}

function wa_inbox_update_status(string $waMessageId, string $status): void
{
    if ($waMessageId === '' || !function_exists('db_execute')) {
        return;
    }
    wa_inbox_ensure_table();
    try {
        db_execute('UPDATE whatsapp_messages SET status = ? WHERE wa_message_id = ?', [$status, $waMessageId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function wa_inbox_mark_read(string $phone): void
{
    wa_inbox_ensure_table();
    $phone = wa_inbox_phone($phone);
    if ($phone === '') {
        return;
    }
    db_execute("UPDATE whatsapp_messages SET read_at = NOW() WHERE phone = ? AND direction = 'in' AND read_at IS NULL", [$phone]);
}

function wa_inbox_extract_body(array $message): string
{
    $type = (string) ($message['type'] ?? 'text');
    if ($type === 'text') {
        return trim((string) ($message['text']['body'] ?? ''));
    }
    if ($type === 'button') {
        return trim((string) ($message['button']['text'] ?? $message['button']['payload'] ?? '[button]'));
    }
    if ($type === 'interactive') {
        $nfm = $message['interactive']['nfm_reply']['response_json'] ?? '';
        $btn = $message['interactive']['button_reply']['title'] ?? '';
        $lst = $message['interactive']['list_reply']['title'] ?? '';
        return trim((string) ($btn ?: $lst ?: $nfm ?: '[interactive]'));
    }
    if ($type === 'image') {
        return '[image] ' . trim((string) ($message['image']['caption'] ?? ''));
    }
    if ($type === 'audio') {
        return '[audio]';
    }
    if ($type === 'document') {
        return '[document] ' . trim((string) ($message['document']['filename'] ?? ''));
    }
    return '[' . $type . ']';
}

function wa_inbox_match_reply(string $incoming, array $settings): string
{
    $incoming = strtolower(trim($incoming));
    if ($incoming === '') {
        return '';
    }
    $rules = (string) ($settings['autobot_rules'] ?? '');
    foreach (preg_split("/\r\n|\n|\r/", $rules) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '|')) {
            continue;
        }
        [$keys, $reply] = array_map('trim', explode('|', $line, 2));
        if ($reply === '') {
            continue;
        }
        foreach (array_map('trim', explode(',', $keys)) as $key) {
            $key = strtolower($key);
            if ($key !== '' && str_contains($incoming, $key)) {
                return $reply;
            }
        }
    }
    return trim((string) ($settings['autobot_default'] ?? ''));
}

function wa_inbox_recent_bot(string $phone, int $seconds = 120): bool
{
    wa_inbox_ensure_table();
    $phone = wa_inbox_phone($phone);
    $seconds = max(10, min(600, $seconds));
    $row = db_fetch_one(
        "SELECT id FROM whatsapp_messages
         WHERE phone = ? AND direction = 'out' AND is_bot = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)
         LIMIT 1",
        [$phone]
    );
    return (bool) $row;
}

function wa_inbox_send_text(string $toPhone, string $body, bool $isBot = false): array
{
    if (!function_exists('whatsapp_send_text')) {
        $f = __DIR__ . '/whatsapp_config.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
    if (!function_exists('whatsapp_send_text')) {
        return ['ok' => false, 'error' => 'WhatsApp send helper not loaded'];
    }
    $phone = wa_inbox_phone($toPhone);
    $to = $phone !== '' && $phone[0] !== '+' ? '+' . $phone : $phone;
    $res = whatsapp_send_text($to, $body);
    $ok = !empty($res['success']);
    $waId = '';
    $resp = $res['response'] ?? null;
    if (is_array($resp)) {
        $waId = (string) ($resp['messages'][0]['id'] ?? '');
    }
    $err = $ok ? null : wa_inbox_api_error($resp, (string) ($res['error'] ?? 'Send failed'));
    wa_inbox_save([
        'direction' => 'out',
        'phone' => $phone,
        'wa_message_id' => $waId,
        'type' => 'text',
        'body' => $body,
        'status' => $ok ? 'sent' : 'failed',
        'is_bot' => $isBot,
        'raw_json' => is_array($resp) ? json_encode($resp) : null,
    ]);
    wa_inbox_event($ok ? ($isBot ? 'bot' : 'out') : 'error', $phone, $ok ? $body : (string) $err);
    return [
        'ok' => $ok,
        'error' => $err,
        'wa_message_id' => $waId,
    ];
}

function wa_inbox_handle_incoming(array $message, array $settings, string $profileName = ''): void
{
    $from = wa_inbox_phone((string) ($message['from'] ?? ''));
    $body = wa_inbox_extract_body($message);
    $waId = (string) ($message['id'] ?? '');
    $ts = isset($message['timestamp']) ? date('Y-m-d H:i:s', (int) $message['timestamp']) : date('Y-m-d H:i:s');
    wa_inbox_save([
        'direction' => 'in',
        'phone' => $from,
        'contact_name' => $profileName,
        'wa_message_id' => $waId,
        'type' => (string) ($message['type'] ?? 'text'),
        'body' => $body,
        'status' => 'received',
        'created_at' => $ts,
        'raw_json' => $message,
    ]);
    wa_inbox_event('in', $from, $body !== '' ? $body : ('[' . (string) ($message['type'] ?? 'message') . ']'));
    if (empty($settings['autobot']) || $from === '' || $body === '') {
        return;
    }
    if (wa_inbox_recent_bot($from)) {
        return;
    }
    $reply = wa_inbox_match_reply($body, $settings);
    if ($reply === '') {
        return;
    }
    wa_inbox_send_text($from, $reply, true);
}

function wa_inbox_conversations(int $limit = 80): array
{
    wa_inbox_ensure_table();
    $limit = max(1, min(200, $limit));
    try {
        $rows = db_fetch_all(
            "SELECT m.phone, m.body AS last_body, m.created_at AS last_at, m.direction AS last_dir,
                    m.contact_name,
                    (SELECT COUNT(*) FROM whatsapp_messages u
                     WHERE u.phone = m.phone AND u.direction = 'in' AND u.read_at IS NULL) AS unread
             FROM whatsapp_messages m
             INNER JOIN (
                 SELECT phone, MAX(id) AS max_id FROM whatsapp_messages GROUP BY phone
             ) t ON t.max_id = m.id
             ORDER BY m.created_at DESC
             LIMIT {$limit}"
        ) ?: [];
        return $rows;
    } catch (Throwable $e) {
        $rows = db_fetch_all(
            "SELECT m.phone, m.body AS last_body, m.created_at AS last_at, m.direction AS last_dir,
                    (SELECT COUNT(*) FROM whatsapp_messages u
                     WHERE u.phone = m.phone AND u.direction = 'in' AND u.read_at IS NULL) AS unread
             FROM whatsapp_messages m
             INNER JOIN (
                 SELECT phone, MAX(id) AS max_id FROM whatsapp_messages GROUP BY phone
             ) t ON t.max_id = m.id
             ORDER BY m.created_at DESC
             LIMIT {$limit}"
        ) ?: [];
        return $rows;
    }
}

function wa_inbox_thread(string $phone, int $limit = 200): array
{
    wa_inbox_ensure_table();
    $phone = wa_inbox_phone($phone);
    $limit = max(1, min(500, $limit));
    $rows = db_fetch_all(
        'SELECT * FROM whatsapp_messages WHERE phone = ? ORDER BY created_at ASC, id ASC LIMIT ' . $limit,
        [$phone]
    ) ?: [];
    return $rows;
}

function wa_inbox_contact_name(string $phone): string
{
    $phone = wa_inbox_phone($phone);
    $last10 = substr($phone, -10);
    if ($last10 === '' || !function_exists('db_fetch_one')) {
        return '';
    }
    try {
        $row = db_fetch_one(
            "SELECT name FROM users
             WHERE RIGHT(REPLACE(REPLACE(IFNULL(phone,''),'+',''),' ',''), 10) = ?
                OR RIGHT(REPLACE(REPLACE(IFNULL(whatsapp_id,''),'+',''),' ',''), 10) = ?
             LIMIT 1",
            [$last10, $last10]
        );
        return trim((string) ($row['name'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function wa_inbox_display_phone(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) >= 10) {
        $ten = substr($d, -10);
        return '+91 ' . substr($ten, 0, 5) . ' ' . substr($ten, 5);
    }
    return $d !== '' ? $d : 'Unknown';
}

function wa_inbox_when(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $t = strtotime($dt);
    if ($t === false) {
        return $dt;
    }
    $today = date('Y-m-d');
    $day = date('Y-m-d', $t);
    if ($day === $today) {
        return date('g:i A', $t);
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    if (date('Y', $t) === date('Y')) {
        return date('d M', $t);
    }
    return date('d/m/Y', $t);
}

function wa_inbox_clock(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $t = strtotime($dt);
    return $t ? date('g:i A', $t) : '';
}

function wa_inbox_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    $a = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1));
    $b = strtoupper(substr((string) ($parts[1] ?? ''), 0, 1));
    return $b !== '' ? $a . $b : $a;
}

function wa_inbox_ticks(string $status): string
{
    $st = strtolower($status);
    if (in_array($st, ['failed', 'undelivered'], true)) {
        return '!';
    }
    if (in_array($st, ['read', 'played'], true)) {
        return 'read';
    }
    if (in_array($st, ['delivered'], true)) {
        return 'delivered';
    }
    if (in_array($st, ['sent', 'echo', 'accepted'], true)) {
        return 'sent';
    }
    return 'sent';
}

function wa_inbox_ensure_events(): void
{
    static $done = false;
    if ($done || !function_exists('db_execute')) {
        return;
    }
    $done = true;
    try {
        db_execute("CREATE TABLE IF NOT EXISTS whatsapp_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(32) NOT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wa_ev_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $done = false;
    }
}

function wa_inbox_event(string $kind, string $phone, string $detail): void
{
    wa_inbox_ensure_events();
    $phone = $phone !== '' ? wa_inbox_phone($phone) : '';
    $detail = trim($detail);
    if (function_exists('mb_substr')) {
        $detail = mb_substr($detail, 0, 240);
    } else {
        $detail = substr($detail, 0, 240);
    }
    try {
        db_execute(
            'INSERT INTO whatsapp_events (kind, phone, detail, created_at) VALUES (?, ?, ?, ?)',
            [$kind, $phone !== '' ? $phone : null, $detail, date('Y-m-d H:i:s')]
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function wa_inbox_events(int $limit = 40): array
{
    wa_inbox_ensure_events();
    $limit = max(1, min(100, $limit));
    try {
        return db_fetch_all(
            "SELECT id, kind, phone, detail, created_at FROM whatsapp_events ORDER BY id DESC LIMIT {$limit}"
        ) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function wa_inbox_api_error($resp, string $fallback): string
{
    if (!is_array($resp) || !isset($resp['error'])) {
        return $fallback !== '' ? $fallback : 'Could not send message.';
    }
    $err = is_array($resp['error']) ? $resp['error'] : [];
    $code = (int) ($err['code'] ?? 0);
    $msg = trim((string) ($err['error_user_msg'] ?? $err['message'] ?? ''));
    $blob = strtolower($msg . ' ' . (string) ($err['error_data']['details'] ?? ''));
    if ($code === 131047 || str_contains($blob, '24 hour') || str_contains($blob, 're-engagement')) {
        return 'Parent must message first. After their WhatsApp message, you can reply here for 24 hours.';
    }
    if ($code === 131026) {
        return 'Message not delivered. The number may not have WhatsApp.';
    }
    return $msg !== '' ? $msg : $fallback;
}

function wa_inbox_label(string $phone, ?string $hint = null): string
{
    $phone = wa_inbox_phone($phone);
    $hint = trim((string) $hint);
    if ($hint !== '') {
        return $hint;
    }
    if ($phone === '') {
        return 'Unknown';
    }
    try {
        $row = db_fetch_one(
            "SELECT contact_name FROM whatsapp_messages
             WHERE phone = ? AND contact_name IS NOT NULL AND contact_name <> ''
             ORDER BY id DESC LIMIT 1",
            [$phone]
        );
        $waName = trim((string) ($row['contact_name'] ?? ''));
        if ($waName !== '') {
            return $waName;
        }
    } catch (Throwable $e) {
        // ignore
    }
    $user = wa_inbox_contact_name($phone);
    return $user !== '' ? $user : wa_inbox_display_phone($phone);
}

function wa_inbox_chat_dto(array $c): array
{
    $phone = wa_inbox_phone((string) ($c['phone'] ?? ''));
    $name = wa_inbox_label($phone, (string) ($c['contact_name'] ?? ''));
    $body = trim((string) ($c['last_body'] ?? ''));
    if ($body === '') {
        $body = 'Message';
    }
    if (($c['last_dir'] ?? '') === 'out') {
        $body = 'You: ' . $body;
    }
    return [
        'phone' => $phone,
        'name' => $name,
        'initials' => wa_inbox_initials($name),
        'last_body' => $body,
        'last_at' => (string) ($c['last_at'] ?? ''),
        'when' => wa_inbox_when((string) ($c['last_at'] ?? '')),
        'unread' => (int) ($c['unread'] ?? 0),
        'last_dir' => (string) ($c['last_dir'] ?? ''),
    ];
}

function wa_inbox_msg_dto(array $m): array
{
    $dir = ($m['direction'] ?? '') === 'out' ? 'out' : 'in';
    $status = (string) ($m['status'] ?? '');
    return [
        'id' => (int) ($m['id'] ?? 0),
        'dir' => $dir,
        'body' => (string) ($m['body'] ?? ''),
        'bot' => !empty($m['is_bot']),
        'status' => $status,
        'ticks' => $dir === 'out' ? wa_inbox_ticks($status) : '',
        'time' => wa_inbox_clock((string) ($m['created_at'] ?? '')),
        'created_at' => (string) ($m['created_at'] ?? ''),
    ];
}

function wa_inbox_event_dto(array $e): array
{
    $kind = (string) ($e['kind'] ?? '');
    $label = match ($kind) {
        'in' => 'Incoming',
        'out' => 'Sent',
        'bot' => 'Auto reply',
        'status' => 'Status',
        'webhook' => 'Webhook',
        'error' => 'Error',
        default => $kind,
    };
    return [
        'id' => (int) ($e['id'] ?? 0),
        'kind' => $kind,
        'label' => $label,
        'phone' => wa_inbox_display_phone((string) ($e['phone'] ?? '')),
        'detail' => (string) ($e['detail'] ?? ''),
        'when' => wa_inbox_when((string) ($e['created_at'] ?? '')),
        'created_at' => (string) ($e['created_at'] ?? ''),
    ];
}

function wa_inbox_sync_payload(string $phone, bool $markRead = false): array
{
    $phone = wa_inbox_phone($phone);
    $chats = array_map('wa_inbox_chat_dto', wa_inbox_conversations(120));
    if ($phone === '' && $chats !== []) {
        $phone = (string) ($chats[0]['phone'] ?? '');
    }
    if ($markRead && $phone !== '') {
        wa_inbox_mark_read($phone);
        $chats = array_map('wa_inbox_chat_dto', wa_inbox_conversations(120));
    }
    $messages = $phone !== '' ? array_map('wa_inbox_msg_dto', wa_inbox_thread($phone)) : [];
    $events = array_map('wa_inbox_event_dto', wa_inbox_events(30));
    $lastAt = $events[0]['created_at'] ?? '';
    if ($messages !== []) {
        $lastAt = max($lastAt, (string) ($messages[array_key_last($messages)]['created_at'] ?? ''));
    }
    $live = $lastAt !== '' && (time() - (int) strtotime($lastAt)) < 600;
    $unread = 0;
    foreach ($chats as $c) {
        $unread += (int) ($c['unread'] ?? 0);
    }
    return [
        'ok' => true,
        'phone' => $phone,
        'name' => $phone !== '' ? wa_inbox_label($phone) : '',
        'display_phone' => $phone !== '' ? wa_inbox_display_phone($phone) : '',
        'chats' => $chats,
        'messages' => $messages,
        'events' => $events,
        'live' => $live,
        'unread' => $unread,
    ];
}
