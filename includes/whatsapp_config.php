<?php
declare(strict_types=1);
/**
 * includes/whatsapp_config.php
 *
 * WhatsApp Cloud API helper using PHP streams (file_get_contents + stream_context).
 * - Reads configuration from includes/config.php (if present) or environment variables via env().
 * - Supports sending OTP using an approved template and optionally including a URL/button parameter.
 * - Falls back to logging (development) when no token is provided.
 *
 * Returns from whatsapp_send_otp():
 *   [
 *     'ok' => bool,
 *     'provider' => string,
 *     'http' => int|null,
 *     'resp' => mixed,
 *     'error' => string|null
 *   ]
 *
 * SECURITY:
 *  - Keep WHATSAPP_PROVIDER_KEY (access token) secure and out of version control.
 *  - Do NOT log OTP values in production.
 */

/* Minimal env() helper if not provided */
if (!function_exists('env')) {
    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null) {
        $v = getenv($key);
        if ($v !== false) return $v;
        if (isset($_ENV[$key])) return $_ENV[$key];
        return $default;
    }
}

/* Load project config if available (allows overrides via includes/config.php) */
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

/* Simple logger */
function whatsapp_log(string $note, array $data = []): void {
    $logDir = rtrim(LOGS_PATH, '/\\');
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $file = $logDir . '/whatsapp.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $note . ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/* Build WhatsApp Graph API messages URL */
function whatsapp_api_messages_url(): string {
    $base = rtrim(WHATSAPP_API_BASE, '/');
    $ver  = trim(WHATSAPP_API_VERSION, '/');
    $phoneId = WHATSAPP_PHONE_NUMBER_ID;
    return "{$base}/{$ver}/{$phoneId}/messages";
}

/**
 * Low-level HTTP POST using PHP streams.
 * Returns: ['success'=>bool,'http_code'=>int,'response'=>mixed,'error'=>string|null]
 *
 * @param array $payload
 * @return array
 */
function whatsapp_api_post(array $payload): array {
    $url = whatsapp_api_messages_url();
    $token = WHATSAPP_PROVIDER_KEY;

    if (empty($token)) {
        return ['success' => false, 'http_code' => 0, 'response' => null, 'error' => 'Missing WHATSAPP_PROVIDER_KEY'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json)
    ];

    $sslOpts = [
        'verify_peer' => true,
        'verify_peer_name' => true
    ];
    if (!empty(WHATSAPP_SSL_CAFILE)) {
        $sslOpts['cafile'] = WHATSAPP_SSL_CAFILE;
    }

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $json,
            'timeout' => 15,
            'ignore_errors' => true // capture non-200 responses
        ],
        'ssl' => $sslOpts
    ];

    $context = stream_context_create($opts);

    // Suppress warnings; treat false as error
    $resp = @file_get_contents($url, false, $context);

    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', $hdr, $m)) {
                $httpCode = (int)$m[1];
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
        if ($decoded === null) $decoded = $resp;
    }

    $success = ($httpCode >= 200 && $httpCode < 300);

    // Log details for development or on errors
    if (!$success || APP_ENV === 'development') {
        whatsapp_log('whatsapp_api_post', [
            'url' => $url,
            'http_code' => $httpCode,
            'success' => $success,
            'response' => $decoded,
            'error' => $error
        ]);
    }

    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $decoded,
        'error' => $error
    ];
}

/**
 * Send a plain text WhatsApp message (useful for debugging; for production prefer templates)
 *
 * @param string $toPhone E.164 format (e.g., +919812345678)
 * @param string $message Plain text message body
 * @return array
 */
function whatsapp_send_text(string $toPhone, string $message): array {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toPhone,
        'type' => 'text',
        'text' => ['body' => $message]
    ];
    return whatsapp_api_post($payload);
}

/**
 * Send OTP via approved template (supports optional URL/button parameter)
 *
 * If your approved template includes a URL-type button that requires a parameter,
 * pass $buttonParam (e.g. a URL string or OTP text) so the API receives the required parameter.
 *
 * @param string $toPhone E.164
 * @param string $otp
 * @param string|null $templateName
 * @param string|null $language
 * @param string|null $buttonParam
 * @return array
 */
function whatsapp_send_template_otp(string $toPhone, string $otp, ?string $templateName = null, ?string $language = null, ?string $buttonParam = null): array {
    $tName = $templateName ?: WHATSAPP_TEMPLATE_OTP_NAME;
    $lang  = $language ?: WHATSAPP_TEMPLATE_LANGUAGE;

    if (empty($tName)) {
        return ['success' => false, 'http_code' => 0, 'response' => null, 'error' => 'Template name not configured'];
    }

    $components = [
        [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => (string)$otp]
            ]
        ]
    ];

    // If a button parameter is provided, append a button component
    if (!empty($buttonParam)) {
        $components[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
                ['type' => 'text', 'text' => (string)$buttonParam]
            ]
        ];
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $toPhone,
        'type' => 'template',
        'template' => [
            'name' => $tName,
            'language' => ['code' => $lang],
            'components' => $components
        ]
    ];

    return whatsapp_api_post($payload);
}

/**
 * High-level helper: send OTP and return normalized result.
 *
 * Reads optional config:
 *  - WHATSAPP_URL_BUTTON_PARAM_STATIC  (string) OR
 *  - WHATSAPP_URL_BUTTON_PARAM_USE_OTP (bool) to use OTP as the button parameter
 *
 * @param string $toPhone E.164
 * @param string $otp
 * @return array ['ok'=>bool,'provider'=>string,'http'=>int,'resp'=>mixed,'error'=>string|null]
 */
function whatsapp_send_otp(string $toPhone, string $otp): array {
    $shouldLogOtp = (defined('APP_ENV') && APP_ENV === 'development');

    // Determine button parameter if configured
    $buttonParam = null;
    if (!empty(WHATSAPP_URL_BUTTON_PARAM_STATIC)) {
        $buttonParam = WHATSAPP_URL_BUTTON_PARAM_STATIC;
    } elseif (!empty(WHATSAPP_URL_BUTTON_PARAM_USE_OTP) && WHATSAPP_URL_BUTTON_PARAM_USE_OTP === true) {
        $buttonParam = (string)$otp;
    }

    // Prefer template; fallback to text if no template name configured
    if (!empty(WHATSAPP_TEMPLATE_OTP_NAME)) {
        $res = whatsapp_send_template_otp($toPhone, $otp, null, null, $buttonParam);
    } else {
        $msg = "Your Pioneer Play School OTP is: {$otp}";
        $res = whatsapp_send_text($toPhone, $msg);
    }

    // Optionally log OTP in development (do not in production)
    if ($shouldLogOtp) {
        $logDir = rtrim(LOGS_PATH, '/\\');
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $otpLogFile = $logDir . '/whatsapp_otp_dev.log';
        $line = '[' . date('Y-m-d H:i:s') . '] OTP to ' . $toPhone . ' => ' . $otp . PHP_EOL;
        @file_put_contents($otpLogFile, $line, FILE_APPEND | LOCK_EX);
    }

    return [
        'ok' => !empty($res['success']),
        'provider' => WHATSAPP_PROVIDER,
        'http' => $res['http_code'] ?? $res['http'] ?? 0,
        'resp' => $res['response'] ?? $res['resp'] ?? null,
        'error' => $res['error'] ?? null
    ];
}

/* End of includes/whatsapp_config.php */