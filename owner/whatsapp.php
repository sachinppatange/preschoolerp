<?php
/**
 * owner/whatsapp.php — WhatsApp-style inbox for Cloud API chats.
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
$self = site_url('/owner/whatsapp.php');
$phone = wa_inbox_phone((string) ($_GET['phone'] ?? $_POST['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string) ($_POST['csrf'] ?? '');
    if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
        $msgError = 'Invalid security token. Please reload the page.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $phone = wa_inbox_phone((string) ($_POST['phone'] ?? $phone));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($action === 'new') {
            $phone = wa_inbox_phone((string) ($_POST['new_phone'] ?? ''));
        }
        if (strlen(substr($phone, -10)) < 10) {
            $msgError = 'Choose a chat or enter a 10-digit mobile number.';
        } elseif ($body === '') {
            $msgError = 'Type a message.';
        } else {
            $res = wa_inbox_send_text($phone, $body, false);
            if (!empty($res['ok'])) {
                header('Location: ' . $self . '?phone=' . urlencode($phone) . '&sent=1');
                exit;
            }
            $msgError = (string) ($res['error'] ?? 'Could not send message.');
        }
    }
}

if (isset($_GET['sent'])) {
    $msgSuccess = 'Message sent.';
}

$conversations = wa_inbox_conversations(120);
if ($phone === '' && $conversations !== []) {
    $phone = wa_inbox_phone((string) ($conversations[0]['phone'] ?? ''));
}
if ($phone !== '') {
    wa_inbox_mark_read($phone);
    $conversations = wa_inbox_conversations(120);
}

$thread = $phone !== '' ? wa_inbox_thread($phone) : [];
$contactName = '';
if ($phone !== '') {
    $contactName = wa_inbox_contact_name($phone);
    if ($contactName === '') {
        foreach ($conversations as $c) {
            if (wa_inbox_phone((string) ($c['phone'] ?? '')) === $phone) {
                $contactName = trim((string) ($c['contact_name'] ?? ''));
                break;
            }
        }
    }
}
$showName = $contactName !== '' ? $contactName : wa_inbox_display_phone($phone);
$q = trim((string) ($_GET['q'] ?? ''));

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.wa-app { --wa:#008069; --wa-dark:#075e54; --wa-mint:#25d366; --wa-chat:#efeae2; --wa-out:#d9fdd3; height: calc(100vh - 108px); min-height: 560px; display:grid; grid-template-columns: 380px 1fr; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #d1d7db; box-shadow:0 4px 24px rgba(11,20,26,.08); }
@media (max-width: 900px) {
  .wa-app { grid-template-columns: 1fr; height: calc(100vh - 96px); }
  .wa-app.is-thread .wa-sidebar { display:none; }
  .wa-app:not(.is-thread) .wa-stage { display:none; }
}
.wa-sidebar { display:flex; flex-direction:column; min-width:0; border-right:1px solid #e9edef; background:#fff; }
.wa-side-head { background:var(--wa); color:#fff; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.wa-side-head h2 { margin:0; font-size:1.15rem; font-weight:700; color:#fff; }
.wa-side-head a { color:#fff; opacity:.9; font-size:.85rem; }
.wa-search { padding:8px 10px; background:#fff; }
.wa-search input { width:100%; border:none; background:#f0f2f5; border-radius:8px; padding:8px 12px 8px 36px; font-size:.9rem; }
.wa-search { position:relative; }
.wa-search i { position:absolute; left:22px; top:50%; transform:translateY(-50%); color:#667781; font-size:.9rem; }
.wa-list { flex:1; overflow:auto; min-height:0; }
.wa-row { display:flex; gap:12px; padding:10px 14px; text-decoration:none; color:#111b21; border-bottom:1px solid #f0f2f5; }
.wa-row:hover { background:#f5f6f6; color:#111b21; }
.wa-row.active { background:#f0f2f5; }
.wa-av { width:48px; height:48px; border-radius:50%; background:#dfe5e7; color:#54656f; display:flex; align-items:center; justify-content:center; font-weight:700; flex:0 0 48px; }
.wa-row-main { min-width:0; flex:1; }
.wa-row-top { display:flex; justify-content:space-between; gap:8px; }
.wa-row-top .nm { font-weight:600; font-size:.98rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wa-row-top .tm { font-size:.72rem; color:#667781; white-space:nowrap; }
.wa-row-top .tm.unread { color:var(--wa-mint); font-weight:700; }
.wa-row-bot { display:flex; justify-content:space-between; gap:8px; margin-top:2px; }
.wa-row-bot .sn { color:#667781; font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wa-badge { min-width:20px; height:20px; border-radius:999px; background:var(--wa-mint); color:#fff; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; padding:0 6px; }
.wa-empty { padding:2rem 1.2rem; color:#667781; text-align:center; }
.wa-new { padding:10px 12px; border-top:1px solid #e9edef; background:#f0f2f5; }
.wa-new summary { cursor:pointer; font-weight:600; color:var(--wa); list-style:none; }
.wa-stage { display:flex; flex-direction:column; min-width:0; background:var(--wa-chat); background-image: radial-gradient(rgba(0,0,0,.04) 1px, transparent 1px); background-size:18px 18px; }
.wa-chat-head { background:var(--wa); color:#fff; padding:10px 14px; display:flex; align-items:center; gap:12px; }
.wa-back { display:none; color:#fff; background:transparent; border:0; font-size:1.2rem; }
@media (max-width: 900px) { .wa-back { display:inline-flex; } }
.wa-chat-head .meta { min-width:0; }
.wa-chat-head .nm { font-weight:700; font-size:1rem; }
.wa-chat-head .ph { font-size:.78rem; opacity:.9; }
.wa-thread { flex:1; overflow:auto; min-height:0; padding:12px 8% 18px; display:flex; flex-direction:column; gap:4px; }
.wa-bubble { max-width:min(75%, 560px); padding:6px 8px 4px 9px; border-radius:8px; font-size:.95rem; line-height:1.4; box-shadow:0 1px 1px rgba(11,20,26,.06); white-space:pre-wrap; word-break:break-word; }
.wa-in { align-self:flex-start; background:#fff; border-top-left-radius:0; }
.wa-out { align-self:flex-end; background:var(--wa-out); border-top-right-radius:0; }
.wa-bubble .ft { display:flex; justify-content:flex-end; align-items:center; gap:4px; margin-top:2px; font-size:.68rem; color:#667781; }
.wa-ticks { letter-spacing:-1px; font-size:.85rem; }
.wa-ticks.read { color:#53bdeb; }
.wa-ticks.failed { color:#e53e3e; font-weight:700; letter-spacing:0; }
.wa-bot-tag { font-size:.68rem; color:#667781; margin-bottom:2px; }
.wa-compose { background:#f0f2f5; padding:10px 12px; display:flex; gap:8px; align-items:flex-end; }
.wa-compose textarea { flex:1; border:none; border-radius:8px; padding:10px 12px; resize:none; min-height:44px; max-height:120px; }
.wa-send { width:46px; height:46px; border:0; border-radius:50%; background:var(--wa); color:#fff; display:inline-flex; align-items:center; justify-content:center; }
.wa-send:hover { background:var(--wa-dark); color:#fff; }
.wa-blank { flex:1; display:flex; align-items:center; justify-content:center; color:#667781; text-align:center; padding:2rem; }
.panel-page-body:has(.wa-app) { padding-bottom: 0; }
</style>

<?php if ($msgSuccess !== ''): ?>
  <div class="alert alert-success py-2"><?php echo e($msgSuccess); ?></div>
<?php endif; ?>
<?php if ($msgError !== ''): ?>
  <div class="alert alert-danger py-2"><?php echo e($msgError); ?></div>
<?php endif; ?>

<div class="wa-app<?php echo $phone !== '' ? ' is-thread' : ''; ?>">
  <aside class="wa-sidebar">
    <div class="wa-side-head">
      <h2>Chats</h2>
      <a href="<?php echo e(site_url('/owner/otp_settings.php')); ?>">Settings</a>
    </div>
    <div class="wa-search">
      <i class="bi bi-search"></i>
      <input type="search" id="waFilter" value="<?php echo e($q); ?>" placeholder="Search or start new chat" autocomplete="off">
    </div>
    <div class="wa-list" id="waList">
      <?php if ($conversations === []): ?>
        <div class="wa-empty">No chats yet. When a parent messages the school WhatsApp, it appears here — same as WhatsApp. Sent OTPs also show in that chat.</div>
      <?php endif; ?>
      <?php foreach ($conversations as $c):
          $p = wa_inbox_phone((string) ($c['phone'] ?? ''));
          if ($p === '') {
              continue;
          }
          $nm = trim((string) ($c['contact_name'] ?? ''));
          if ($nm === '') {
              $nm = wa_inbox_contact_name($p);
          }
          if ($nm === '') {
              $nm = wa_inbox_display_phone($p);
          }
          $un = (int) ($c['unread'] ?? 0);
          $active = $p === $phone;
          $snip = trim((string) ($c['last_body'] ?? ''));
          if ($snip === '') {
              $snip = 'Message';
          }
          if (($c['last_dir'] ?? '') === 'out') {
              $snip = 'You: ' . $snip;
          }
          $hay = strtolower($nm . ' ' . $p . ' ' . $snip);
      ?>
        <a class="wa-row<?php echo $active ? ' active' : ''; ?>" data-hay="<?php echo e($hay); ?>" href="<?php echo e($self . '?phone=' . urlencode($p)); ?>">
          <div class="wa-av"><?php echo e(wa_inbox_initials($nm)); ?></div>
          <div class="wa-row-main">
            <div class="wa-row-top">
              <span class="nm"><?php echo e($nm); ?></span>
              <span class="tm<?php echo $un > 0 ? ' unread' : ''; ?>"><?php echo e(wa_inbox_when((string) ($c['last_at'] ?? ''))); ?></span>
            </div>
            <div class="wa-row-bot">
              <span class="sn"><?php echo e($snip); ?></span>
              <?php if ($un > 0): ?><span class="wa-badge"><?php echo $un > 99 ? '99+' : $un; ?></span><?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="wa-new">
      <details>
        <summary><i class="bi bi-plus-circle me-1"></i>New chat</summary>
        <form method="post" class="mt-2">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="action" value="new">
          <input class="form-control form-control-sm mb-2" name="new_phone" maxlength="15" placeholder="10-digit mobile">
          <textarea class="form-control form-control-sm mb-2" name="body" rows="2" placeholder="Type a message"></textarea>
          <button class="btn btn-sm btn-success" type="submit">Send</button>
        </form>
      </details>
    </div>
  </aside>

  <section class="wa-stage">
    <?php if ($phone === ''): ?>
      <div class="wa-blank">
        <div>
          <i class="bi bi-whatsapp" style="font-size:3rem;color:#008069"></i>
          <h3 class="mt-3">WhatsApp inbox</h3>
          <p class="mb-0">Select a chat to read incoming and outgoing messages.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="wa-chat-head">
        <a class="wa-back" href="<?php echo e($self); ?>" aria-label="Back"><i class="bi bi-arrow-left"></i></a>
        <div class="wa-av" style="background:#fff;color:#008069;"><?php echo e(wa_inbox_initials($showName)); ?></div>
        <div class="meta">
          <div class="nm"><?php echo e($showName); ?></div>
          <div class="ph"><?php echo e(wa_inbox_display_phone($phone)); ?></div>
        </div>
      </div>
      <div class="wa-thread" id="waThread">
        <?php if ($thread === []): ?>
          <div class="wa-empty">No messages in this chat yet.</div>
        <?php endif; ?>
        <?php foreach ($thread as $m):
            $dir = ($m['direction'] ?? '') === 'out' ? 'out' : 'in';
            $bot = !empty($m['is_bot']);
            $ticks = $dir === 'out' ? wa_inbox_ticks((string) ($m['status'] ?? 'sent')) : '';
        ?>
          <div class="wa-bubble wa-<?php echo $dir; ?>">
            <?php if ($bot): ?><div class="wa-bot-tag">Auto reply</div><?php endif; ?>
            <?php echo nl2br(e((string) ($m['body'] ?? ''))); ?>
            <div class="ft">
              <span><?php echo e(wa_inbox_clock((string) ($m['created_at'] ?? ''))); ?></span>
              <?php if ($ticks === 'read'): ?>
                <span class="wa-ticks read">✓✓</span>
              <?php elseif ($ticks === 'delivered'): ?>
                <span class="wa-ticks">✓✓</span>
              <?php elseif ($ticks === 'failed'): ?>
                <span class="wa-ticks failed">!</span>
              <?php elseif ($ticks === 'sent'): ?>
                <span class="wa-ticks">✓</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <form class="wa-compose" method="post">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="phone" value="<?php echo e($phone); ?>">
        <textarea name="body" rows="1" placeholder="Type a message" required></textarea>
        <button class="wa-send" type="submit" aria-label="Send"><i class="bi bi-send-fill"></i></button>
      </form>
    <?php endif; ?>
  </section>
</div>
<script>
(function () {
  var thread = document.getElementById('waThread');
  if (thread) thread.scrollTop = thread.scrollHeight;
  var filter = document.getElementById('waFilter');
  var list = document.getElementById('waList');
  if (!filter || !list) return;
  filter.addEventListener('input', function () {
    var q = (filter.value || '').toLowerCase().trim();
    list.querySelectorAll('.wa-row').forEach(function (row) {
      var hay = row.getAttribute('data-hay') || '';
      row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
