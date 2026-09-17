<?php
/**
 * OTP channel settings (SMS / Email / WhatsApp) stored in schools.settings.otp
 * plus send helpers used by login and the owner settings page.
 */
declare(strict_types=1);

function otp_school_id(): int
{
    if (function_exists('auth_school_id')) {
        $id = auth_school_id();
        if ($id > 0) {
            return $id;
        }
    }
    return (int) ($_SESSION['school_id'] ?? 1);
}

function otp_settings_defaults(): array
{
    $app = defined('APP_NAME') ? (string) APP_NAME : 'Preschool App';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#f4f6f9;">'
        . '<div style="background:#ffffff;border-radius:12px;padding:28px 24px;border-top:4px solid #0d3b8c;">'
        . '<p style="margin:0 0 8px;font-size:12px;letter-spacing:.08em;color:#f15a22;font-weight:700;">' . htmlspecialchars($app, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<h2 style="margin:0 0 16px;color:#0d3b8c;font-size:22px;">Your login OTP</h2>'
        . '<p style="margin:0 0 12px;color:#334155;font-size:15px;line-height:1.5;">Use this one-time password to log in. Do not share it with anyone.</p>'
        . '<p style="margin:16px 0;font-size:32px;letter-spacing:8px;font-weight:800;color:#0d3b8c;text-align:center;">{otp}</p>'
        . '<p style="margin:0;color:#64748b;font-size:13px;">Valid for {minutes} minutes.</p>'
        . '</div></div>';

    return [
        'validity_minutes' => 5,
        'sms' => [
            'enabled' => true,
            'auth_key' => '',
            'sender_id' => '',
            'template_name' => '',
            'template_id' => '',
            'dlt_template_id' => '',
            'dlt_content' => 'Your OTP for account verification is ##var1##. Valid for ##var2## minutes. Do not share it.',
        ],
        'email' => [
            'enabled' => true,
            'data_center' => 'in',
            'send_method' => 'api',
            'send_mail_token' => '',
            'from_email' => '',
            'from_name' => $app,
            'bounce_email' => '',
            'reply_to' => '',
            'subject' => 'Your login OTP',
            'html' => $html,
        ],
        'whatsapp' => [
            'enabled' => true,
            'access_token' => '',
            'phone_number_id' => '',
            'api_base' => '',
            'api_version' => '',
            'template_name' => '',
            'template_language' => '',
            'url_button_param' => '',
            'url_button_use_otp' => false,
        ],
    ];
}

function otp_settings_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = otp_settings_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function otp_settings_load(?int $schoolId = null): array
{
    $defaults = otp_settings_defaults();
    $schoolId = $schoolId ?? otp_school_id();
    try {
        $row = db_fetch_one('SELECT settings FROM schools WHERE id = ? LIMIT 1', [$schoolId]);
        $all = [];
        if ($row && isset($row['settings'])) {
            $decoded = json_decode((string) $row['settings'], true);
            if (is_array($decoded)) {
                $all = $decoded;
            }
        }
        if (!empty($all['otp']) && is_array($all['otp'])) {
            return otp_settings_merge($defaults, $all['otp']);
        }
    } catch (Throwable $e) {
        // fall through to defaults
    }
    return $defaults;
}

function otp_settings_save(array $otp, ?int $schoolId = null): bool
{
    $schoolId = $schoolId ?? otp_school_id();
    $row = db_fetch_one('SELECT id, settings FROM schools WHERE id = ? LIMIT 1', [$schoolId]);
    $all = [];
    if ($row && isset($row['settings'])) {
        $decoded = json_decode((string) $row['settings'], true);
        if (is_array($decoded)) {
            $all = $decoded;
        }
    }
    $all['otp'] = $otp;
    $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    if ($row) {
        db_execute('UPDATE schools SET settings = ? WHERE id = ?', [$json, $schoolId]);
        return true;
    }
    db_execute('INSERT INTO schools (id, name, settings) VALUES (?, ?, ?)', [$schoolId, 'School', $json]);
    return true;
}

function otp_mask_secret(?string $secret): string
{
    $s = (string) $secret;
    $len = strlen($s);
    if ($len === 0) {
        return '';
    }
    if ($len <= 4) {
        return str_repeat('•', $len);
    }
    return str_repeat('•', max(8, $len - 4)) . substr($s, -4);
}

function otp_validity_minutes(?array $cfg = null): int
{
    $cfg = $cfg ?? otp_settings_load();
    $m = (int) ($cfg['validity_minutes'] ?? 5);
    if ($m < 1) {
        $m = 1;
    }
    if ($m > 30) {
        $m = 30;
    }
    return $m;
}

function otp_msisdn(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($d) === 10) {
        return '91' . $d;
    }
    if (strlen($d) === 11 && $d[0] === '0') {
        return '91' . substr($d, 1);
    }
    return $d;
}

function otp_user_email(array $user): string
{
    if (!empty($user['email']) && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
        return (string) $user['email'];
    }
    $meta = $user['meta'] ?? null;
    if (is_string($meta) && $meta !== '') {
        $decoded = json_decode($meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    if (is_array($meta)) {
        foreach (['email', 'mail', 'parent_email'] as $k) {
            $v = trim((string) ($meta[$k] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return $v;
            }
        }
    }
    return '';
}

function otp_http_json(string $url, array $headers, $body, int $timeout = 20): array
{
    $json = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headerLines = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    $headerLines[] = 'Content-Length: ' . strlen((string) $json);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', $hdr, $m)) {
                $httpCode = (int) $m[1];
                break;
            }
        }
    }
    $decoded = null;
    $error = null;
    if ($resp === false) {
        $error = 'HTTP request failed or timed out';
    } else {
        $decoded = json_decode($resp, true);
        if ($decoded === null) {
            $decoded = $resp;
        }
    }
    $ok = $httpCode >= 200 && $httpCode < 300;
    return [
        'ok' => $ok,
        'http' => $httpCode,
        'resp' => $decoded,
        'error' => $error,
        'raw' => $resp,
    ];
}

function otp_summarize_resp($resp): string
{
    if (is_string($resp)) {
        $t = trim($resp);
        return strlen($t) > 220 ? substr($t, 0, 217) . '...' : $t;
    }
    if (!is_array($resp)) {
        return '';
    }
    $json = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }
    return strlen($json) > 220 ? substr($json, 0, 217) . '...' : $json;
}

function otp_ensure_log_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('table_exists') && table_exists('otp_send_logs')) {
        return;
    }
    try {
        db_execute(
            "CREATE TABLE IF NOT EXISTS otp_send_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                school_id INT UNSIGNED NOT NULL DEFAULT 1,
                channel VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                to_addr VARCHAR(191) NOT NULL DEFAULT '',
                message VARCHAR(500) DEFAULT NULL,
                http_code INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_school_created (school_id, created_at),
                KEY idx_channel (channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // ignore — logging is optional
    }
}

function otp_log_send(string $channel, bool $ok, string $to, string $message, ?int $http = null, ?int $schoolId = null): void
{
    otp_ensure_log_table();
    $schoolId = $schoolId ?? otp_school_id();
    try {
        db_execute(
            'INSERT INTO otp_send_logs (school_id, channel, status, to_addr, message, http_code) VALUES (?,?,?,?,?,?)',
            [$schoolId, $channel, $ok ? 'Ok' : 'Fail', $to, substr($message, 0, 500), $http]
        );
    } catch (Throwable $e) {
        // ignore
    }
}

function otp_logs_list(string $channel = 'all', int $limit = 600, ?int $schoolId = null): array
{
    otp_ensure_log_table();
    if (!function_exists('table_exists') || !table_exists('otp_send_logs')) {
        return [];
    }
    $schoolId = $schoolId ?? otp_school_id();
    $limit = max(1, min(1000, $limit));
    try {
        if ($channel !== 'all' && $channel !== '') {
            return db_fetch_all(
                'SELECT * FROM otp_send_logs WHERE school_id = ? AND channel = ? ORDER BY id DESC LIMIT ' . $limit,
                [$schoolId, $channel]
            ) ?: [];
        }
        return db_fetch_all(
            'SELECT * FROM otp_send_logs WHERE school_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$schoolId]
        ) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function otp_logs_clear(?int $schoolId = null): void
{
    otp_ensure_log_table();
    $schoolId = $schoolId ?? otp_school_id();
    try {
        db_execute('DELETE FROM otp_send_logs WHERE school_id = ?', [$schoolId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function otp_whatsapp_runtime(?array $cfg = null): array
{
    $cfg = $cfg ?? otp_settings_load();
    $wa = is_array($cfg['whatsapp'] ?? null) ? $cfg['whatsapp'] : [];
    $token = trim((string) ($wa['access_token'] ?? ''));
    if ($token === '' && defined('WHATSAPP_PROVIDER_KEY')) {
        $token = (string) WHATSAPP_PROVIDER_KEY;
    }
    $phoneId = trim((string) ($wa['phone_number_id'] ?? ''));
    if ($phoneId === '' && defined('WHATSAPP_PHONE_NUMBER_ID')) {
        $phoneId = (string) WHATSAPP_PHONE_NUMBER_ID;
    }
    $apiBase = trim((string) ($wa['api_base'] ?? ''));
    if ($apiBase === '' && defined('WHATSAPP_API_BASE')) {
        $apiBase = (string) WHATSAPP_API_BASE;
    }
    $apiVer = trim((string) ($wa['api_version'] ?? ''));
    if ($apiVer === '' && defined('WHATSAPP_API_VERSION')) {
        $apiVer = (string) WHATSAPP_API_VERSION;
    }
    $tpl = trim((string) ($wa['template_name'] ?? ''));
    if ($tpl === '' && defined('WHATSAPP_TEMPLATE_OTP_NAME')) {
        $tpl = (string) WHATSAPP_TEMPLATE_OTP_NAME;
    }
    $lang = trim((string) ($wa['template_language'] ?? ''));
    if ($lang === '' && defined('WHATSAPP_TEMPLATE_LANGUAGE')) {
        $lang = (string) WHATSAPP_TEMPLATE_LANGUAGE;
    }
    $btn = (string) ($wa['url_button_param'] ?? '');
    if ($btn === '' && defined('WHATSAPP_URL_BUTTON_PARAM_STATIC')) {
        $btn = (string) WHATSAPP_URL_BUTTON_PARAM_STATIC;
    }
    $useOtp = !empty($wa['url_button_use_otp']);
    if (!$useOtp && empty($wa['url_button_param']) && defined('WHATSAPP_URL_BUTTON_PARAM_USE_OTP')) {
        $useOtp = (bool) WHATSAPP_URL_BUTTON_PARAM_USE_OTP;
    }
    return [
        'enabled' => !isset($wa['enabled']) || !empty($wa['enabled']),
        'token' => $token,
        'phone_number_id' => $phoneId,
        'api_base' => $apiBase !== '' ? $apiBase : 'https://graph.facebook.com',
        'api_version' => $apiVer !== '' ? $apiVer : 'v19.0',
        'template_name' => $tpl,
        'template_language' => $lang !== '' ? $lang : 'en',
        'url_button_param' => $btn,
        'url_button_use_otp' => $useOtp,
    ];
}

function otp_send_whatsapp(string $toPhone, string $otp): array
{
    if (!function_exists('whatsapp_send_otp')) {
        $file = __DIR__ . '/whatsapp_config.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    if (!function_exists('whatsapp_send_otp')) {
        return ['ok' => false, 'http' => 0, 'resp' => null, 'error' => 'WhatsApp helper not loaded', 'channel' => 'whatsapp'];
    }
    $res = whatsapp_send_otp($toPhone, $otp);
    $ok = !empty($res['ok']);
    $http = (int) ($res['http'] ?? 0);
    $err = (string) ($res['error'] ?? '');
    $msg = $ok ? 'OTP WhatsApp sent' : ('WhatsApp failed' . ($err !== '' ? ': ' . $err : ''));
    if ($ok && is_array($res['resp'] ?? null)) {
        $msg .= ' via Meta ' . otp_summarize_resp($res['resp']);
    }
    otp_log_send('whatsapp', $ok, otp_msisdn($toPhone), $msg, $http);
    return [
        'ok' => $ok,
        'http' => $http,
        'resp' => $res['resp'] ?? null,
        'error' => $err !== '' ? $err : null,
        'channel' => 'whatsapp',
        'message' => $msg,
    ];
}

function otp_send_sms(string $toPhone, string $otp, ?array $cfg = null): array
{
    $cfg = $cfg ?? otp_settings_load();
    $sms = is_array($cfg['sms'] ?? null) ? $cfg['sms'] : [];
    $key = trim((string) ($sms['auth_key'] ?? ''));
    $templateId = trim((string) ($sms['template_id'] ?? ''));
    if ($templateId === '') {
        $templateId = trim((string) ($sms['dlt_template_id'] ?? ''));
    }
    $minutes = (string) otp_validity_minutes($cfg);
    $mobile = otp_msisdn($toPhone);
    if ($key === '' || $templateId === '') {
        $msg = 'SMS skipped: MSG91 auth key or template ID is missing.';
        otp_log_send('sms', false, $mobile, $msg, 0);
        return ['ok' => false, 'http' => 0, 'resp' => null, 'error' => $msg, 'channel' => 'sms', 'message' => $msg];
    }
    $payload = [
        'template_id' => $templateId,
        'short_url' => '0',
        'realTimeResponse' => '1',
        'recipients' => [[
            'mobiles' => $mobile,
            'var1' => $otp,
            'var2' => $minutes,
            'VAR1' => $otp,
            'VAR2' => $minutes,
        ]],
    ];
    $sender = trim((string) ($sms['sender_id'] ?? ''));
    if ($sender !== '') {
        $payload['sender'] = $sender;
        $payload['recipients'][0]['sender'] = $sender;
    }
    $res = otp_http_json('https://control.msg91.com/api/v5/flow/', ['authkey: ' . $key], $payload);
    $ok = !empty($res['ok']);
    $type = '';
    if (is_array($res['resp'] ?? null)) {
        $type = (string) ($res['resp']['type'] ?? '');
        if (strcasecmp($type, 'success') === 0) {
            $ok = true;
        } elseif (strcasecmp($type, 'error') === 0) {
            $ok = false;
        }
    }
    $msg = $ok
        ? ('OTP SMS sent via MSG91 ' . otp_summarize_resp($res['resp']))
        : ('SMS failed: ' . ($res['error'] ?? otp_summarize_resp($res['resp'])));
    otp_log_send('sms', $ok, $mobile, $msg, (int) ($res['http'] ?? 0));
    return [
        'ok' => $ok,
        'http' => (int) ($res['http'] ?? 0),
        'resp' => $res['resp'] ?? null,
        'error' => $ok ? null : (string) ($res['error'] ?? $msg),
        'channel' => 'sms',
        'message' => $msg,
    ];
}

function otp_zeptomail_url(string $dc): string
{
    $map = [
        'in' => 'https://api.zeptomail.in/v1.1/email',
        'india' => 'https://api.zeptomail.in/v1.1/email',
        'us' => 'https://api.zeptomail.com/v1.1/email',
        'eu' => 'https://api.zeptomail.eu/v1.1/email',
        'au' => 'https://api.zeptomail.com.au/v1.1/email',
        'cn' => 'https://api.zeptomail.com.cn/v1.1/email',
    ];
    $k = strtolower(trim($dc));
    return $map[$k] ?? $map['in'];
}

function otp_send_email(string $toEmail, string $otp, ?array $cfg = null): array
{
    $cfg = $cfg ?? otp_settings_load();
    $em = is_array($cfg['email'] ?? null) ? $cfg['email'] : [];
    $toEmail = trim($toEmail);
    $token = trim((string) ($em['send_mail_token'] ?? ''));
    $from = trim((string) ($em['from_email'] ?? ''));
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Email skipped: no valid recipient address.';
        otp_log_send('email', false, $toEmail, $msg, 0);
        return ['ok' => false, 'http' => 0, 'resp' => null, 'error' => $msg, 'channel' => 'email', 'message' => $msg];
    }
    if ($token === '' || $from === '') {
        $msg = 'Email skipped: ZeptoMail token or from address is missing.';
        otp_log_send('email', false, $toEmail, $msg, 0);
        return ['ok' => false, 'http' => 0, 'resp' => null, 'error' => $msg, 'channel' => 'email', 'message' => $msg];
    }
    $minutes = (string) otp_validity_minutes($cfg);
    $html = (string) ($em['html'] ?? '');
    $html = str_replace(['{otp}', '{minutes}'], [htmlspecialchars($otp, ENT_QUOTES, 'UTF-8'), htmlspecialchars($minutes, ENT_QUOTES, 'UTF-8')], $html);
    $subject = str_replace(['{otp}', '{minutes}'], [$otp, $minutes], (string) ($em['subject'] ?? 'Your login OTP'));
    $fromName = trim((string) ($em['from_name'] ?? ''));
    $payload = [
        'from' => ['address' => $from, 'name' => $fromName],
        'to' => [['email_address' => ['address' => $toEmail, 'name' => '']]],
        'subject' => $subject,
        'htmlbody' => $html,
    ];
    $reply = trim((string) ($em['reply_to'] ?? ''));
    if ($reply !== '') {
        $payload['reply_to'] = [['address' => $reply, 'name' => $fromName]];
    }
    $bounce = trim((string) ($em['bounce_email'] ?? ''));
    if ($bounce !== '') {
        $payload['bounce_address'] = $bounce;
    }
    $url = otp_zeptomail_url((string) ($em['data_center'] ?? 'in'));
    $res = otp_http_json($url, ['Authorization: Zoho-enczapikey ' . $token], $payload);
    $ok = !empty($res['ok']);
    $msg = $ok
        ? ('OTP email sent via ZeptoMail ' . otp_summarize_resp($res['resp']))
        : ('Email failed: ' . ($res['error'] ?? otp_summarize_resp($res['resp'])));
    otp_log_send('email', $ok, $toEmail, $msg, (int) ($res['http'] ?? 0));
    return [
        'ok' => $ok,
        'http' => (int) ($res['http'] ?? 0),
        'resp' => $res['resp'] ?? null,
        'error' => $ok ? null : (string) ($res['error'] ?? $msg),
        'channel' => 'email',
        'message' => $msg,
    ];
}

/**
 * Send OTP on every enabled channel that has credentials.
 *
 * @return array{ok:bool,channels:array<int,string>,results:array,error:?string,info:string}
 */
function otp_send_login_channels(string $phoneE164, string $otp, array $user = [], ?array $cfg = null): array
{
    $cfg = $cfg ?? otp_settings_load();
    $attempted = [];
    $okNames = [];
    $results = [];
    $errors = [];

    $wa = is_array($cfg['whatsapp'] ?? null) ? $cfg['whatsapp'] : [];
    if (!isset($wa['enabled']) || !empty($wa['enabled'])) {
        $attempted[] = 'WhatsApp';
        $r = otp_send_whatsapp($phoneE164, $otp);
        $results['whatsapp'] = $r;
        if (!empty($r['ok'])) {
            $okNames[] = 'WhatsApp';
        } else {
            $errors[] = (string) ($r['error'] ?? 'WhatsApp failed');
        }
    }

    $sms = is_array($cfg['sms'] ?? null) ? $cfg['sms'] : [];
    if (!empty($sms['enabled']) && trim((string) ($sms['auth_key'] ?? '')) !== '') {
        $attempted[] = 'SMS';
        $r = otp_send_sms($phoneE164, $otp, $cfg);
        $results['sms'] = $r;
        if (!empty($r['ok'])) {
            $okNames[] = 'SMS';
        } else {
            $errors[] = (string) ($r['error'] ?? 'SMS failed');
        }
    }

    $em = is_array($cfg['email'] ?? null) ? $cfg['email'] : [];
    if (!empty($em['enabled']) && trim((string) ($em['send_mail_token'] ?? '')) !== '') {
        $emailTo = otp_user_email($user);
        if ($emailTo !== '') {
            $attempted[] = 'Email';
            $r = otp_send_email($emailTo, $otp, $cfg);
            $results['email'] = $r;
            if (!empty($r['ok'])) {
                $okNames[] = 'Email';
            } else {
                $errors[] = (string) ($r['error'] ?? 'Email failed');
            }
        }
    }

    if ($okNames === [] && $attempted === []) {
        return [
            'ok' => false,
            'channels' => [],
            'results' => $results,
            'error' => 'No OTP channel is enabled. Open Owner → OTP Settings.',
            'info' => '',
        ];
    }

    $ok = $okNames !== [];
    $info = $ok
        ? ('OTP has been sent. Please check ' . implode(' / ', $okNames) . '.')
        : '';
    return [
        'ok' => $ok,
        'channels' => $okNames,
        'results' => $results,
        'error' => $ok ? null : ('Failed to send OTP. ' . implode(' ', $errors)),
        'info' => $info,
    ];
}

function otp_keep_secret(string $posted, string $existing): string
{
    $posted = trim($posted);
    return $posted === '' ? $existing : $posted;
}
