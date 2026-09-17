<?php
/**
 * owner/otp_settings.php — SMS, Email, WhatsApp OTP provider settings.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
require_once __DIR__ . '/../includes/otp_settings.php';
if (file_exists(__DIR__ . '/../includes/whatsapp_config.php')) {
    require_once __DIR__ . '/../includes/whatsapp_config.php';
}

$page_title = 'OTP Settings';
$skip_panel_ay_banner = true;
$cfg = otp_settings_load();
$waRt = otp_whatsapp_runtime($cfg);
$msgSuccess = '';
$msgError = '';

$csrfOk = static function (): bool {
    $tok = $_POST['csrf'] ?? '';
    return function_exists('validate_csrf_token') && validate_csrf_token($tok);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!$csrfOk()) {
        $msgError = 'Invalid security token. Please reload the page.';
    } elseif ($action === 'save_sms') {
        $cfg['sms']['enabled'] = !empty($_POST['sms_enabled']);
        $cfg['sms']['auth_key'] = otp_keep_secret((string) ($_POST['sms_auth_key'] ?? ''), (string) ($cfg['sms']['auth_key'] ?? ''));
        $cfg['sms']['sender_id'] = strtoupper(trim((string) ($_POST['sms_sender_id'] ?? '')));
        $cfg['sms']['template_name'] = trim((string) ($_POST['sms_template_name'] ?? ''));
        $cfg['sms']['template_id'] = trim((string) ($_POST['sms_template_id'] ?? ''));
        $cfg['sms']['dlt_template_id'] = trim((string) ($_POST['sms_dlt_template_id'] ?? ''));
        $cfg['sms']['dlt_content'] = (string) ($_POST['sms_dlt_content'] ?? '');
        $cfg['validity_minutes'] = otp_validity_minutes(['validity_minutes' => (int) ($_POST['otp_validity_minutes'] ?? 5)]);
        otp_settings_save($cfg);
        $msgSuccess = 'SMS settings saved.';
    } elseif ($action === 'save_email') {
        $cfg['email']['enabled'] = !empty($_POST['email_enabled']);
        $cfg['email']['data_center'] = trim((string) ($_POST['email_data_center'] ?? 'in'));
        $cfg['email']['send_method'] = trim((string) ($_POST['email_send_method'] ?? 'api'));
        $cfg['email']['send_mail_token'] = otp_keep_secret((string) ($_POST['email_send_mail_token'] ?? ''), (string) ($cfg['email']['send_mail_token'] ?? ''));
        $cfg['email']['from_email'] = trim((string) ($_POST['email_from_email'] ?? ''));
        $cfg['email']['from_name'] = trim((string) ($_POST['email_from_name'] ?? ''));
        $cfg['email']['bounce_email'] = trim((string) ($_POST['email_bounce'] ?? ''));
        $cfg['email']['reply_to'] = trim((string) ($_POST['email_reply_to'] ?? ''));
        $cfg['email']['subject'] = trim((string) ($_POST['email_subject'] ?? ''));
        $cfg['email']['html'] = (string) ($_POST['email_html'] ?? '');
        otp_settings_save($cfg);
        $msgSuccess = 'Email settings saved.';
    } elseif ($action === 'save_whatsapp') {
        $cfg['whatsapp']['enabled'] = !empty($_POST['wa_enabled']);
        $cfg['whatsapp']['access_token'] = otp_keep_secret((string) ($_POST['wa_access_token'] ?? ''), (string) ($cfg['whatsapp']['access_token'] ?? ''));
        $cfg['whatsapp']['phone_number_id'] = trim((string) ($_POST['wa_phone_number_id'] ?? ''));
        $cfg['whatsapp']['api_base'] = trim((string) ($_POST['wa_api_base'] ?? ''));
        $cfg['whatsapp']['api_version'] = trim((string) ($_POST['wa_api_version'] ?? ''));
        $cfg['whatsapp']['template_name'] = trim((string) ($_POST['wa_template_name'] ?? ''));
        $cfg['whatsapp']['template_language'] = trim((string) ($_POST['wa_template_language'] ?? 'en'));
        $cfg['whatsapp']['url_button_param'] = trim((string) ($_POST['wa_url_button_param'] ?? ''));
        $cfg['whatsapp']['url_button_use_otp'] = !empty($_POST['wa_url_button_use_otp']);
        otp_settings_save($cfg);
        $msgSuccess = 'WhatsApp settings saved.';
    } elseif ($action === 'test_sms') {
        $to = trim((string) ($_POST['test_sms_mobile'] ?? ''));
        if (!preg_match('/^\d{10}$/', $to) && !preg_match('/^\d{11,15}$/', preg_replace('/\D+/', '', $to) ?? '')) {
            $msgError = 'Enter a valid 10-digit mobile number.';
        } else {
            try {
                $otp = (string) random_int(1000, 9999);
            } catch (Throwable $e) {
                $otp = (string) mt_rand(1000, 9999);
            }
            $res = otp_send_sms($to, $otp, $cfg);
            if (!empty($res['ok'])) {
                $msgSuccess = 'Test SMS sent. OTP for testing only: ' . $otp;
            } else {
                $msgError = $res['error'] ?? 'Failed to send test SMS.';
            }
        }
    } elseif ($action === 'test_email') {
        $to = trim((string) ($_POST['test_email_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $msgError = 'Enter a valid email address.';
        } else {
            try {
                $otp = (string) random_int(1000, 9999);
            } catch (Throwable $e) {
                $otp = (string) mt_rand(1000, 9999);
            }
            $res = otp_send_email($to, $otp, $cfg);
            if (!empty($res['ok'])) {
                $msgSuccess = 'Test email sent. OTP for testing only: ' . $otp;
            } else {
                $msgError = $res['error'] ?? 'Failed to send test email.';
            }
        }
    } elseif ($action === 'test_whatsapp') {
        $to = trim((string) ($_POST['test_wa_mobile'] ?? ''));
        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if (strlen($digits) < 10) {
            $msgError = 'Enter a valid mobile number.';
        } else {
            $e164 = function_exists('login_otp_to_e164') ? login_otp_to_e164($digits) : ('+91' . substr($digits, -10));
            if ($e164 === null) {
                $e164 = '+' . $digits;
            }
            try {
                $otp = (string) random_int(1000, 9999);
            } catch (Throwable $e) {
                $otp = (string) mt_rand(1000, 9999);
            }
            $res = otp_send_whatsapp($e164, $otp);
            if (!empty($res['ok'])) {
                $msgSuccess = 'Test WhatsApp sent. OTP for testing only: ' . $otp;
            } else {
                $msgError = $res['error'] ?? 'Failed to send test WhatsApp.';
            }
        }
    } elseif ($action === 'clear_logs') {
        otp_logs_clear();
        $msgSuccess = 'OTP send logs cleared.';
    }
    $cfg = otp_settings_load();
    $waRt = otp_whatsapp_runtime($cfg);
}

$logFilter = strtolower(trim((string) ($_GET['log'] ?? 'all')));
if (!in_array($logFilter, ['all', 'sms', 'email', 'whatsapp'], true)) {
    $logFilter = 'all';
}
$logs = otp_logs_list($logFilter);
$logCounts = [
    'all' => count(otp_logs_list('all')),
    'sms' => count(otp_logs_list('sms')),
    'email' => count(otp_logs_list('email')),
    'whatsapp' => count(otp_logs_list('whatsapp')),
];
$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$sms = $cfg['sms'];
$em = $cfg['email'];
$wa = $cfg['whatsapp'];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.otp-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; padding:1.35rem 1.5rem 1.5rem; margin-bottom:1.25rem; box-shadow:0 8px 24px rgba(47,111,237,.06); }
.otp-card h2 { color:#2f6fed; font-size:1.08rem; font-weight:800; margin:0 0 .35rem; }
.otp-lead { color:#5b6b86; font-size:.9rem; margin-bottom:1rem; }
.otp-hint { color:#7a889c; font-size:.8rem; margin-top:.25rem; }
.otp-test { background:#f7fbff; border:1px dashed #c5d8f7; border-radius:14px; padding:1rem 1.15rem; }
.otp-log-msg { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.78rem; color:#334155; word-break:break-all; }
.otp-status-ok { background:#e7f8ee; color:#137a3a; font-weight:700; font-size:.78rem; padding:.15rem .5rem; border-radius:999px; }
.otp-status-fail { background:#fde8e8; color:#b42318; font-weight:700; font-size:.78rem; padding:.15rem .5rem; border-radius:999px; }
</style>

<?php if ($msgSuccess !== ''): ?>
  <div class="alert alert-success"><?php echo e($msgSuccess); ?></div>
<?php endif; ?>
<?php if ($msgError !== ''): ?>
  <div class="alert alert-danger"><?php echo e($msgError); ?></div>
<?php endif; ?>

<p class="text-muted mb-3">Login OTP can be sent on WhatsApp, SMS, and Email together. Save each channel, then use the test boxes. Secrets stay masked — leave a field blank to keep the saved key.</p>

<div class="otp-card">
  <h2>MSG91 SMS OTP</h2>
  <p class="otp-lead">Login OTP is sent by SMS to parents, staff, and admins. Copy the auth key and template ID from the MSG91 dashboard.</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save_sms">
    <div class="col-12">
      <label class="form-check-label">
        <input type="checkbox" class="form-check-input me-2" name="sms_enabled" value="1" <?php echo !empty($sms['enabled']) ? 'checked' : ''; ?>>
        Enable SMS OTP
      </label>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">MSG91 Auth Key</label>
      <div class="input-group">
        <input type="password" class="form-control js-secret" name="sms_auth_key" autocomplete="off" placeholder="<?php echo $sms['auth_key'] !== '' ? e(otp_mask_secret($sms['auth_key']) . ' — leave blank to keep') : 'Paste auth key'; ?>">
        <button class="btn btn-outline-secondary js-toggle-secret" type="button">Show</button>
      </div>
      <div class="otp-hint"><?php echo $sms['auth_key'] !== '' ? 'Saved key ends with ' . e(substr((string) $sms['auth_key'], -4)) . '. Leave blank to keep the existing key.' : 'Required for sending.'; ?></div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Sender ID</label>
      <input type="text" class="form-control" name="sms_sender_id" maxlength="6" value="<?php echo e((string) $sms['sender_id']); ?>" placeholder="ATHMDA">
      <div class="otp-hint">DLT-approved 6-character sender, e.g. KAGPIF.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Template name</label>
      <input type="text" class="form-control" name="sms_template_name" value="<?php echo e((string) $sms['template_name']); ?>" placeholder="athmdaotp">
      <div class="otp-hint">Template name as shown in MSG91. For identification only.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">MSG91 Template ID</label>
      <input type="text" class="form-control" name="sms_template_id" value="<?php echo e((string) $sms['template_id']); ?>">
      <div class="otp-hint">ID used by the Flow API (currently in use).</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">DLT Template ID</label>
      <input type="text" class="form-control" name="sms_dlt_template_id" value="<?php echo e((string) $sms['dlt_template_id']); ?>">
      <div class="otp-hint">ID from DLT. Used if the MSG91 template ID is empty.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">OTP validity (minutes)</label>
      <input type="number" class="form-control" name="otp_validity_minutes" min="1" max="30" value="<?php echo (int) otp_validity_minutes($cfg); ?>">
      <div class="otp-hint">Applies to both SMS and email OTP. 1 to 30 minutes.</div>
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">Approved DLT content</label>
      <textarea class="form-control" name="sms_dlt_content" rows="3"><?php echo e((string) $sms['dlt_content']); ?></textarea>
      <div class="otp-hint">Approved DLT text. ##var1## = OTP, ##var2## = minutes. For reference / checking only.</div>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary">Save SMS settings</button>
    </div>
  </form>
  <div class="otp-test mt-4">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="test_sms">
      <div class="col-md-6">
        <div class="fw-semibold text-primary mb-1">Test SMS OTP</div>
        <label class="form-label">Send test to mobile</label>
        <input type="text" class="form-control" name="test_sms_mobile" maxlength="15" placeholder="9096463943">
        <div class="otp-hint">A real SMS is sent using the saved settings. The OTP is shown on screen for testing only.</div>
      </div>
      <div class="col-md-6 text-md-end">
        <button type="submit" class="btn btn-primary">Send test SMS</button>
      </div>
    </form>
  </div>
</div>

<div class="otp-card">
  <h2>Zoho ZeptoMail · Email OTP</h2>
  <p class="otp-lead">Copy the token from Agent → SMTP/API → Send Mail Token. In the HTML use <code>{otp}</code> and <code>{minutes}</code>.</p>
  <div class="alert alert-info py-2 px-3 small mb-3">
    The From email must be verified in the ZeptoMail Agent. Wrong data center returns HTTP 401. API (Send Mail Token) is recommended.
  </div>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save_email">
    <div class="col-12">
      <label class="form-check-label">
        <input type="checkbox" class="form-check-input me-2" name="email_enabled" value="1" <?php echo !empty($em['enabled']) ? 'checked' : ''; ?>>
        Enable Email OTP
      </label>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Data center</label>
      <select class="form-select" name="email_data_center">
        <?php
        $dcs = ['in' => 'India (zeptomail.zoho.in)', 'us' => 'United States', 'eu' => 'Europe', 'au' => 'Australia', 'cn' => 'China'];
        $curDc = (string) ($em['data_center'] ?? 'in');
        foreach ($dcs as $k => $lab):
        ?>
          <option value="<?php echo e($k); ?>" <?php echo $curDc === $k ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="otp-hint">Select the region of your Zoho account. Wrong DC = 401 error.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Send method</label>
      <select class="form-select" name="email_send_method">
        <option value="api" <?php echo (($em['send_method'] ?? 'api') === 'api') ? 'selected' : ''; ?>>API (Send Mail Token) — recommended</option>
        <option value="smtp" <?php echo (($em['send_method'] ?? '') === 'smtp') ? 'selected' : ''; ?>>SMTP (stored only; sending uses API)</option>
      </select>
      <div class="otp-hint">API is simpler and more stable. For SMTP, the token is used as the password.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Send Mail Token</label>
      <div class="input-group">
        <input type="password" class="form-control js-secret" name="email_send_mail_token" autocomplete="off" placeholder="<?php echo $em['send_mail_token'] !== '' ? 'Token saved. Leave blank to keep the existing token.' : 'Paste Send Mail Token'; ?>">
        <button class="btn btn-outline-secondary js-toggle-secret" type="button">Show</button>
      </div>
      <div class="otp-hint"><?php echo $em['send_mail_token'] !== '' ? 'Token saved. Leave blank to keep the existing token.' : 'Required for sending.'; ?></div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">From email (verified in Agent)</label>
      <input type="email" class="form-control" name="email_from_email" value="<?php echo e((string) $em['from_email']); ?>" placeholder="noreply@example.com">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">From name</label>
      <input type="text" class="form-control" name="email_from_name" value="<?php echo e((string) $em['from_name']); ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Bounce address</label>
      <input type="email" class="form-control" name="email_bounce" value="<?php echo e((string) $em['bounce_email']); ?>">
      <div class="otp-hint">Use the Agent bounce mailbox only (example: bounce@bounce.yourdomain.com). Do not use the From address — that causes ZeptoMail SM_111 / HTTP 500. Leave blank if unsure.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Reply-to email</label>
      <input type="email" class="form-control" name="email_reply_to" value="<?php echo e((string) $em['reply_to']); ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">OTP email subject</label>
      <input type="text" class="form-control" name="email_subject" value="<?php echo e((string) $em['subject']); ?>">
    </div>
    <div class="col-12">
      <label class="form-label fw-semibold">OTP email HTML</label>
      <textarea class="form-control font-monospace" name="email_html" rows="10"><?php echo e((string) $em['html']); ?></textarea>
      <div class="otp-hint">Placeholders: {otp} = OTP, {minutes} = validity.</div>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary">Save email settings</button>
    </div>
  </form>
  <div class="otp-test mt-4">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="test_email">
      <div class="col-md-6">
        <div class="fw-semibold text-primary mb-1">Test email OTP</div>
        <label class="form-label">Send test to email</label>
        <input type="email" class="form-control" name="test_email_to" placeholder="you@example.com">
        <div class="otp-hint">Email is sent with the saved HTML and subject. The OTP is shown on screen for testing only.</div>
      </div>
      <div class="col-md-6 text-md-end">
        <button type="submit" class="btn btn-primary">Send test email</button>
      </div>
    </form>
  </div>
</div>

<div class="otp-card">
  <h2>Meta WhatsApp OTP</h2>
  <p class="otp-lead">Uses the existing WhatsApp Cloud API configuration. Values below are prefilled from current app settings; you can override them here without editing code.</p>
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save_whatsapp">
    <div class="col-12">
      <label class="form-check-label">
        <input type="checkbox" class="form-check-input me-2" name="wa_enabled" value="1" <?php echo !empty($waRt['enabled']) ? 'checked' : ''; ?>>
        Enable WhatsApp OTP
      </label>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Access token</label>
      <div class="input-group">
        <input type="password" class="form-control js-secret" name="wa_access_token" autocomplete="off" placeholder="<?php echo $waRt['token'] !== '' ? e(otp_mask_secret($waRt['token']) . ' — leave blank to keep') : 'Paste Meta access token'; ?>">
        <button class="btn btn-outline-secondary js-toggle-secret" type="button">Show</button>
      </div>
      <div class="otp-hint"><?php echo $waRt['token'] !== '' ? 'Saved token ends with ' . e(substr($waRt['token'], -4)) . '. Leave blank to keep the existing token.' : 'Required for sending.'; ?></div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Phone number ID</label>
      <input type="text" class="form-control" name="wa_phone_number_id" value="<?php echo e((string) ($wa['phone_number_id'] !== '' ? $wa['phone_number_id'] : $waRt['phone_number_id'])); ?>">
      <div class="otp-hint">From Meta WhatsApp → API Setup.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Graph API base</label>
      <input type="text" class="form-control" name="wa_api_base" value="<?php echo e((string) ($wa['api_base'] !== '' ? $wa['api_base'] : $waRt['api_base'])); ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Graph API version</label>
      <input type="text" class="form-control" name="wa_api_version" value="<?php echo e((string) ($wa['api_version'] !== '' ? $wa['api_version'] : $waRt['api_version'])); ?>" placeholder="v19.0">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">OTP template name</label>
      <input type="text" class="form-control" name="wa_template_name" value="<?php echo e((string) ($wa['template_name'] !== '' ? $wa['template_name'] : $waRt['template_name'])); ?>">
      <div class="otp-hint">Approved WhatsApp template, e.g. atharvmediaotp.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Template language</label>
      <input type="text" class="form-control" name="wa_template_language" value="<?php echo e((string) ($wa['template_language'] !== '' ? $wa['template_language'] : $waRt['template_language'])); ?>" placeholder="en">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">URL button parameter</label>
      <input type="text" class="form-control" name="wa_url_button_param" maxlength="15" value="<?php echo e((string) ($wa['url_button_param'] !== '' ? $wa['url_button_param'] : $waRt['url_button_param'])); ?>">
      <div class="otp-hint">Static value for a URL button (max 15 characters), if the template needs one.</div>
    </div>
    <div class="col-md-6 d-flex align-items-end">
      <label class="form-check-label mb-2">
        <input type="checkbox" class="form-check-input me-2" name="wa_url_button_use_otp" value="1" <?php echo !empty($waRt['url_button_use_otp']) ? 'checked' : ''; ?>>
        Use OTP as URL button parameter
      </label>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary">Save WhatsApp settings</button>
    </div>
  </form>
  <div class="otp-test mt-4">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="test_whatsapp">
      <div class="col-md-6">
        <div class="fw-semibold text-primary mb-1">Test WhatsApp OTP</div>
        <label class="form-label">Send test to mobile</label>
        <input type="text" class="form-control" name="test_wa_mobile" maxlength="15" placeholder="9096463943">
        <div class="otp-hint">A real WhatsApp template message is sent. The OTP is shown on screen for testing only.</div>
      </div>
      <div class="col-md-6 text-md-end">
        <button type="submit" class="btn btn-primary">Send test WhatsApp</button>
      </div>
    </form>
  </div>
</div>

<div class="otp-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h2 class="mb-1">SMS / email / WhatsApp error log</h2>
      <div class="otp-hint mb-0">Last 600 sends. OTP codes are not stored in the log. Failed sends appear here.</div>
    </div>
    <form method="post" onsubmit="return confirm('Clear all OTP send logs for this school?');">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="action" value="clear_logs">
      <button type="submit" class="btn btn-sm btn-danger">Clear logs</button>
    </form>
  </div>
  <div class="mb-3">
    <?php
    $self = site_url('/owner/otp_settings.php');
    foreach (['all' => 'All', 'sms' => 'SMS', 'email' => 'Email', 'whatsapp' => 'WhatsApp'] as $k => $lab):
        $n = (int) ($logCounts[$k] ?? 0);
        $cls = $logFilter === $k ? 'btn-primary' : 'btn-outline-primary';
    ?>
      <a class="btn btn-sm <?php echo $cls; ?> me-1" href="<?php echo e($self . '?log=' . $k); ?>"><?php echo e($lab); ?> (<?php echo $n; ?>)</a>
    <?php endforeach; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Time</th>
          <th>Channel</th>
          <th>Status</th>
          <th>To</th>
          <th>Message</th>
          <th>HTTP</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="6" class="text-muted">No sends logged yet.</td></tr>
      <?php else: foreach ($logs as $row):
          $ok = strcasecmp((string) ($row['status'] ?? ''), 'Ok') === 0;
      ?>
        <tr>
          <td class="text-nowrap"><?php echo e(date('d M Y, h:i A', strtotime((string) ($row['created_at'] ?? 'now')))); ?></td>
          <td><?php echo e(strtoupper((string) ($row['channel'] ?? ''))); ?></td>
          <td><span class="<?php echo $ok ? 'otp-status-ok' : 'otp-status-fail'; ?>"><?php echo e((string) ($row['status'] ?? '')); ?></span></td>
          <td><?php echo e((string) ($row['to_addr'] ?? '')); ?></td>
          <td class="otp-log-msg"><?php echo e((string) ($row['message'] ?? '')); ?></td>
          <td><?php echo e((string) ($row['http_code'] ?? '')); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.querySelectorAll('.js-toggle-secret').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = btn.parentElement.querySelector('.js-secret');
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
  });
});
</script>
<?php
require_once __DIR__ . '/../includes/footer.php';
