<?php
/**
 * owner/whatsapp.php — incoming / outgoing WhatsApp Cloud API inbox.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/panel/bootstrap.php';
panel_bootstrap('owner');
require_once __DIR__ . '/../includes/otp_settings.php';
require_once __DIR__ . '/../includes/whatsapp_config.php';
require_once __DIR__ . '/../includes/whatsapp_inbox.php';

$page_title = 'WhatsApp';
$skip_panel_ay_banner = true;
wa_inbox_ensure_table();

$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$msgSuccess = '';
$msgError = '';
$phone = wa_inbox_phone((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $msgError = 'Invalid security token. Please reload the page.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $phone = wa_inbox_phone((string) ($_POST['phone'] ?? $phone));
        if ($action === 'reply') {
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($phone === '') {
                $msgError = 'Choose a conversation first.';
            } elseif ($body === '') {
                $msgError = 'Type a reply.';
            } else {
                $res = wa_inbox_send_text($phone, $body, false);
                if (!empty($res['ok'])) {
                    $msgSuccess = 'Reply sent.';
                } else {
                    $msgError = (string) ($res['error'] ?? 'Could not send reply.');
                }
            }
        } elseif ($action === 'new') {
            $phone = wa_inbox_phone((string) ($_POST['new_phone'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if (strlen(substr($phone, -10)) < 10) {
                $msgError = 'Enter a valid 10-digit mobile number.';
            } elseif ($body === '') {
                $msgError = 'Type a message.';
            } else {
                $res = wa_inbox_send_text($phone, $body, false);
                if (!empty($res['ok'])) {
                    $msgSuccess = 'Message sent.';
                } else {
                    $msgError = (string) ($res['error'] ?? 'Could not send message.');
                }
            }
        }
    }
}

if ($phone !== '') {
    wa_inbox_mark_read($phone);
}

$conversations = wa_inbox_conversations(80);
$thread = $phone !== '' ? wa_inbox_thread($phone) : [];
$contactName = $phone !== '' ? wa_inbox_contact_name($phone) : '';
$hookUrl = wa_inbox_webhook_url();
$self = site_url('/owner/whatsapp.php');
$fmtPhone = static function (string $p): string {
    $d = preg_replace('/\D+/', '', $p) ?? '';
    if (strlen($d) >= 10) {
        return substr($d, -10);
    }
    return $d;
};

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.wa-wrap { display:grid; grid-template-columns: minmax(240px, 320px) 1fr; gap: 1rem; min-height: 70vh; }
@media (max-width: 768px) { .wa-wrap { grid-template-columns: 1fr; } }
.wa-card { background:#fff; border:1px solid #dbe7fb; border-radius:18px; box-shadow:0 8px 24px rgba(47,111,237,.06); overflow:hidden; display:flex; flex-direction:column; }
.wa-card h2 { color:#2f6fed; font-size:1.02rem; font-weight:800; margin:0; }
.wa-head { padding:1rem 1.1rem; border-bottom:1px solid #e8eef8; }
.wa-list { overflow:auto; max-height: 68vh; }
.wa-item { display:block; padding:.8rem 1.05rem; border-bottom:1px solid #f0f4fb; text-decoration:none; color:inherit; }
.wa-item:hover { background:#f7fbff; }
.wa-item.active { background:#eef5ff; }
.wa-item .name { font-weight:700; color:#1e3a5f; }
.wa-item .meta { font-size:.78rem; color:#7a889c; }
.wa-item .snip { font-size:.85rem; color:#5b6b86; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wa-unread { background:#2f6fed; color:#fff; font-size:.7rem; font-weight:700; border-radius:999px; padding:.05rem .45rem; }
.wa-thread { flex:1; overflow:auto; padding:1rem 1.1rem; background:#f6f9fd; max-height: 52vh; display:flex; flex-direction:column; gap:.55rem; }
.wa-bubble { max-width:78%; padding:.65rem .85rem; border-radius:14px; font-size:.92rem; line-height:1.4; white-space:pre-wrap; word-break:break-word; }
.wa-in { align-self:flex-start; background:#fff; border:1px solid #e4ebf6; }
.wa-out { align-self:flex-end; background:#d9fdd3; }
.wa-bot { border:1px dashed #8bc48b; }
.wa-time { display:block; font-size:.7rem; color:#7a889c; margin-top:.2rem; }
.wa-compose { padding: .9rem 1.1rem; border-top:1px solid #e8eef8; background:#fff; }
.wa-hint { color:#7a889c; font-size:.82rem; }
</style>

<?php if ($msgSuccess !== ''): ?>
  <div class="alert alert-success"><?php echo e($msgSuccess); ?></div>
<?php endif; ?>
<?php if ($msgError !== ''): ?>
  <div class="alert alert-danger"><?php echo e($msgError); ?></div>
<?php endif; ?>

<p class="wa-hint mb-3">OTP, replies, and incoming parent messages appear here after Meta webhooks are connected. Callback URL: <code><?php echo e($hookUrl); ?></code> · <a href="<?php echo e(site_url('/owner/otp_settings.php')); ?>">Webhook settings</a></p>

<div class="wa-wrap">
  <div class="wa-card">
    <div class="wa-head d-flex justify-content-between align-items-center">
      <h2>Chats</h2>
      <span class="small text-muted"><?php echo count($conversations); ?></span>
    </div>
    <div class="wa-list">
      <?php if ($conversations === []): ?>
        <div class="p-3 wa-hint">No WhatsApp messages yet. After Meta Verify and save, incoming chats show here. Sent OTPs also appear.</div>
      <?php endif; ?>
      <?php foreach ($conversations as $c):
          $p = (string) ($c['phone'] ?? '');
          $nm = wa_inbox_contact_name($p);
          $active = $phone !== '' && $p === $phone;
          $un = (int) ($c['unread'] ?? 0);
      ?>
        <a class="wa-item<?php echo $active ? ' active' : ''; ?>" href="<?php echo e($self . '?phone=' . urlencode($p)); ?>">
          <div class="d-flex justify-content-between gap-2">
            <span class="name"><?php echo e($nm !== '' ? $nm : $fmtPhone($p)); ?></span>
            <?php if ($un > 0): ?><span class="wa-unread"><?php echo $un; ?></span><?php endif; ?>
          </div>
          <?php if ($nm !== ''): ?><div class="meta"><?php echo e($fmtPhone($p)); ?></div><?php endif; ?>
          <div class="snip"><?php echo e(trim((string) ($c['last_body'] ?? '')) !== '' ? (string) $c['last_body'] : '…'); ?></div>
          <div class="meta"><?php echo e((string) ($c['last_at'] ?? '')); ?> · <?php echo (($c['last_dir'] ?? '') === 'in') ? 'In' : 'Out'; ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="wa-compose">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="new">
        <label class="form-label fw-semibold small mb-1">New message</label>
        <input type="text" class="form-control form-control-sm mb-2" name="new_phone" maxlength="15" placeholder="10-digit mobile" value="<?php echo e($fmtPhone($phone)); ?>">
        <textarea class="form-control form-control-sm mb-2" name="body" rows="2" placeholder="Type a message"></textarea>
        <button type="submit" class="btn btn-sm btn-primary">Send</button>
      </form>
    </div>
  </div>

  <div class="wa-card">
    <div class="wa-head">
      <?php if ($phone === ''): ?>
        <h2>Select a chat</h2>
        <div class="wa-hint mt-1">Pick a conversation, or send a new message from the left.</div>
      <?php else: ?>
        <h2><?php echo e($contactName !== '' ? $contactName : $fmtPhone($phone)); ?></h2>
        <div class="wa-hint mt-1"><?php echo e($fmtPhone($phone)); ?></div>
      <?php endif; ?>
    </div>
    <div class="wa-thread">
      <?php if ($phone !== '' && $thread === []): ?>
        <div class="wa-hint">No messages in this thread yet.</div>
      <?php endif; ?>
      <?php foreach ($thread as $m):
          $dir = ($m['direction'] ?? '') === 'out' ? 'out' : 'in';
          $bot = !empty($m['is_bot']);
      ?>
        <div class="wa-bubble wa-<?php echo $dir; ?><?php echo $bot ? ' wa-bot' : ''; ?>">
          <?php echo nl2br(e((string) ($m['body'] ?? ''))); ?>
          <span class="wa-time">
            <?php echo e((string) ($m['created_at'] ?? '')); ?>
            <?php if ($dir === 'out'): ?> · <?php echo e((string) ($m['status'] ?? 'sent')); ?><?php echo $bot ? ' · bot' : ''; ?><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($phone !== ''): ?>
    <div class="wa-compose">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="phone" value="<?php echo e($phone); ?>">
        <div class="d-flex gap-2">
          <textarea class="form-control" name="body" rows="2" placeholder="Reply…" required></textarea>
          <button type="submit" class="btn btn-primary align-self-end">Send</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
