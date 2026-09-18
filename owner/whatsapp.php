<?php
/**
 * owner/whatsapp.php — live WhatsApp inbox (poll + send) with simple activity log.
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
wa_inbox_ensure_events();

$csrf = function_exists('get_csrf_token') ? get_csrf_token() : '';
$self = site_url('/owner/whatsapp.php');
$isAjax = isset($_GET['ajax']) || isset($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $ajax = (string) ($_POST['ajax'] ?? $_GET['ajax'] ?? 'sync');
    $phone = wa_inbox_phone((string) ($_POST['phone'] ?? $_GET['phone'] ?? ''));
    if ($ajax === 'send') {
        $tok = (string) ($_POST['csrf'] ?? '');
        if (!function_exists('validate_csrf_token') || !validate_csrf_token($tok)) {
            echo json_encode(['ok' => false, 'error' => 'Please reload the page and try again.']);
            exit;
        }
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($phone === '' && isset($_POST['new_phone'])) {
            $phone = wa_inbox_phone((string) $_POST['new_phone']);
        }
        if (strlen(substr($phone, -10)) < 10) {
            echo json_encode(['ok' => false, 'error' => 'Choose a chat or enter a 10-digit mobile.']);
            exit;
        }
        if ($body === '') {
            echo json_encode(['ok' => false, 'error' => 'Type a message.']);
            exit;
        }
        $res = wa_inbox_send_text($phone, $body, false);
        $payload = wa_inbox_sync_payload($phone, true);
        $payload['ok'] = !empty($res['ok']);
        $payload['error'] = $res['error'] ?? null;
        $payload['phone'] = $phone;
        echo json_encode($payload);
        exit;
    }
    echo json_encode(wa_inbox_sync_payload($phone, true));
    exit;
}

$phone = wa_inbox_phone((string) ($_GET['phone'] ?? ''));
$boot = wa_inbox_sync_payload($phone, true);
$phone = (string) ($boot['phone'] ?? $phone);

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.wa-app { --wa:#008069; --wa-dark:#075e54; --wa-mint:#25d366; --wa-chat:#efeae2; --wa-out:#d9fdd3; height: calc(100vh - 118px); min-height: 540px; display:grid; grid-template-columns: 360px 1fr 280px; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #d1d7db; }
@media (max-width: 1200px) { .wa-app { grid-template-columns: 320px 1fr; } .wa-log { display:none; } .wa-app.show-log { grid-template-columns: 1fr 280px; } .wa-app.show-log .wa-sidebar, .wa-app.show-log .wa-stage { display:none; } .wa-app.show-log .wa-log { display:flex; } }
@media (max-width: 800px) {
  .wa-app { grid-template-columns: 1fr; }
  .wa-app.is-thread .wa-sidebar, .wa-app.show-log .wa-sidebar { display:none; }
  .wa-app:not(.is-thread):not(.show-log) .wa-stage { display:none; }
  .wa-log { display:none; }
  .wa-app.show-log .wa-log { display:flex; }
  .wa-app.show-log .wa-stage { display:none; }
}
.wa-sidebar, .wa-log { display:flex; flex-direction:column; min-width:0; background:#fff; }
.wa-sidebar { border-right:1px solid #e9edef; }
.wa-log { border-left:1px solid #e9edef; background:#f8faf9; }
.wa-head { background:var(--wa); color:#fff; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
.wa-head h2 { margin:0; font-size:1.05rem; font-weight:700; color:#fff; }
.wa-head a, .wa-head button { color:#fff; background:transparent; border:0; font-size:.82rem; }
.wa-live { font-size:.72rem; background:rgba(255,255,255,.18); border-radius:999px; padding:2px 8px; }
.wa-live.on { background:#25d366; color:#053b27; font-weight:700; }
.wa-search { padding:8px 10px; position:relative; }
.wa-search i { position:absolute; left:20px; top:50%; transform:translateY(-50%); color:#667781; }
.wa-search input { width:100%; border:none; background:#f0f2f5; border-radius:8px; padding:8px 12px 8px 34px; }
.wa-list, .wa-thread, .wa-log-body { flex:1; overflow:auto; min-height:0; }
.wa-row { display:flex; gap:10px; padding:10px 12px; text-decoration:none; color:#111b21; border-bottom:1px solid #f0f2f5; cursor:pointer; }
.wa-row:hover { background:#f5f6f6; color:#111b21; }
.wa-row.active { background:#f0f2f5; }
.wa-av { width:44px; height:44px; border-radius:50%; background:#dfe5e7; color:#54656f; display:flex; align-items:center; justify-content:center; font-weight:700; flex:0 0 44px; }
.wa-row-main { min-width:0; flex:1; }
.wa-row-top { display:flex; justify-content:space-between; gap:8px; }
.wa-row-top .nm { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wa-row-top .tm { font-size:.72rem; color:#667781; }
.wa-row-top .tm.unread { color:var(--wa-mint); font-weight:700; }
.wa-row-bot { display:flex; justify-content:space-between; gap:8px; margin-top:2px; color:#667781; font-size:.85rem; }
.wa-row-bot .sn { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.wa-badge { min-width:18px; height:18px; border-radius:999px; background:var(--wa-mint); color:#fff; font-size:.68rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; padding:0 5px; }
.wa-new { padding:8px 12px; border-top:1px solid #e9edef; background:#f0f2f5; }
.wa-stage { display:flex; flex-direction:column; min-width:0; background:var(--wa-chat); background-image: radial-gradient(rgba(0,0,0,.04) 1px, transparent 1px); background-size:18px 18px; }
.wa-chat-head { background:var(--wa); color:#fff; padding:8px 12px; display:flex; align-items:center; gap:10px; }
.wa-back { display:none; color:#fff; border:0; background:transparent; font-size:1.15rem; }
@media (max-width: 800px) { .wa-back { display:inline-flex; } }
.wa-thread { padding:12px 7% 16px; display:flex; flex-direction:column; gap:4px; }
.wa-bubble { max-width:min(78%, 560px); padding:6px 8px 4px 9px; border-radius:8px; font-size:.95rem; line-height:1.4; box-shadow:0 1px 1px rgba(11,20,26,.06); white-space:pre-wrap; word-break:break-word; }
.wa-in { align-self:flex-start; background:#fff; border-top-left-radius:0; }
.wa-out { align-self:flex-end; background:var(--wa-out); border-top-right-radius:0; }
.wa-out.failed { background:#ffe8e6; }
.wa-bubble .ft { display:flex; justify-content:flex-end; gap:4px; margin-top:2px; font-size:.68rem; color:#667781; }
.wa-ticks.read { color:#53bdeb; }
.wa-compose { background:#f0f2f5; padding:8px 10px; display:flex; gap:8px; align-items:flex-end; }
.wa-compose textarea { flex:1; border:none; border-radius:8px; padding:10px 12px; resize:none; min-height:44px; }
.wa-send { width:44px; height:44px; border:0; border-radius:50%; background:var(--wa); color:#fff; }
.wa-send:disabled { opacity:.5; }
.wa-err { display:none; margin:0; padding:6px 12px; background:#ffe8e6; color:#b42318; font-size:.82rem; }
.wa-err.on { display:block; }
.wa-blank, .wa-empty { padding:2rem 1rem; text-align:center; color:#667781; }
.wa-log-item { padding:8px 12px; border-bottom:1px solid #e8eee9; font-size:.8rem; }
.wa-log-item .k { font-weight:700; }
.wa-log-item.in .k { color:#008069; }
.wa-log-item.out .k { color:#1a7f37; }
.wa-log-item.error .k { color:#b42318; }
.wa-log-item .d { color:#3b4a54; margin-top:2px; word-break:break-word; }
.wa-log-item .t { color:#667781; font-size:.7rem; }
</style>

<div class="wa-app<?php echo $phone !== '' ? ' is-thread' : ''; ?>" id="waApp">
  <aside class="wa-sidebar">
    <div class="wa-head">
      <h2>Chats</h2>
      <span class="wa-live" id="waLive">Connecting</span>
    </div>
    <div class="wa-search">
      <i class="bi bi-search"></i>
      <input type="search" id="waFilter" placeholder="Search chats">
    </div>
    <div class="wa-list" id="waList"></div>
    <div class="wa-new">
      <form id="waNewForm">
        <div class="small fw-semibold mb-1">New chat</div>
        <input class="form-control form-control-sm mb-1" name="new_phone" maxlength="15" placeholder="10-digit mobile">
        <div class="d-flex gap-1">
          <input class="form-control form-control-sm" name="body" placeholder="Message">
          <button class="btn btn-sm btn-success" type="submit">Send</button>
        </div>
      </form>
    </div>
  </aside>

  <section class="wa-stage">
    <div class="wa-chat-head" id="waChatHead">
      <button class="wa-back" type="button" id="waBack" aria-label="Back"><i class="bi bi-arrow-left"></i></button>
      <div class="wa-av" id="waHeadAv">?</div>
      <div>
        <div class="fw-bold" id="waHeadName">Select a chat</div>
        <div class="small" id="waHeadPhone" style="opacity:.9"></div>
      </div>
      <button type="button" class="ms-auto" id="waLogBtn">Log</button>
    </div>
    <div class="wa-err" id="waErr"></div>
    <div class="wa-thread" id="waThread"></div>
    <form class="wa-compose" id="waSendForm">
      <textarea name="body" id="waInput" rows="1" placeholder="Type a message"></textarea>
      <button class="wa-send" type="submit" id="waSendBtn" aria-label="Send"><i class="bi bi-send-fill"></i></button>
    </form>
  </section>

  <aside class="wa-log" id="waLog">
    <div class="wa-head">
      <h2>Activity</h2>
      <a href="<?php echo e(site_url('/owner/otp_settings.php')); ?>">Settings</a>
    </div>
    <div class="wa-log-body" id="waLogBody"></div>
  </aside>
</div>

<script>
(function () {
  var boot = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  var csrf = <?php echo json_encode($csrf); ?>;
  var endpoint = <?php echo json_encode($self); ?>;
  var state = boot || { chats: [], messages: [], events: [], phone: '' };
  var sending = false;
  var lastSig = '';
  var stickBottom = true;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
  }
  function ticks(t) {
    if (t === 'read') return '<span class="wa-ticks read">✓✓</span>';
    if (t === 'delivered') return '<span>✓✓</span>';
    if (t === 'failed' || t === '!') return '<span style="color:#e53e3e">!</span>';
    if (t === 'sent') return '<span>✓</span>';
    return '';
  }
  function showErr(msg) {
    var el = document.getElementById('waErr');
    if (!el) return;
    if (!msg) { el.classList.remove('on'); el.textContent = ''; return; }
    el.textContent = msg;
    el.classList.add('on');
  }
  function renderChats() {
    var q = (document.getElementById('waFilter').value || '').toLowerCase().trim();
    var html = '';
    (state.chats || []).forEach(function (c) {
      var hay = ((c.name || '') + ' ' + (c.phone || '') + ' ' + (c.last_body || '')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      html += '<div class="wa-row' + (c.phone === state.phone ? ' active' : '') + '" data-phone="' + esc(c.phone) + '">'
        + '<div class="wa-av">' + esc(c.initials || '?') + '</div>'
        + '<div class="wa-row-main"><div class="wa-row-top"><span class="nm">' + esc(c.name) + '</span>'
        + '<span class="tm' + (c.unread > 0 ? ' unread' : '') + '">' + esc(c.when) + '</span></div>'
        + '<div class="wa-row-bot"><span class="sn">' + esc(c.last_body) + '</span>'
        + (c.unread > 0 ? '<span class="wa-badge">' + (c.unread > 99 ? '99+' : c.unread) + '</span>' : '')
        + '</div></div></div>';
    });
    if (!html) html = '<div class="wa-empty">No chats yet. When a parent messages, it appears here automatically.</div>';
    document.getElementById('waList').innerHTML = html;
  }
  function renderThread() {
    var thread = document.getElementById('waThread');
    var msgs = state.messages || [];
    if (!state.phone) {
      thread.innerHTML = '<div class="wa-blank"><i class="bi bi-whatsapp" style="font-size:2.4rem;color:#008069"></i><p class="mt-2 mb-0">Select a chat to talk live.</p></div>';
      return;
    }
    if (!msgs.length) {
      thread.innerHTML = '<div class="wa-empty">No messages yet. Type below to send.</div>';
      return;
    }
    thread.innerHTML = msgs.map(function (m) {
      return '<div class="wa-bubble wa-' + m.dir + (m.status === 'failed' ? ' failed' : '') + '">'
        + (m.bot ? '<div class="small text-muted">Auto reply</div>' : '')
        + esc(m.body).replace(/\n/g, '<br>')
        + '<div class="ft"><span>' + esc(m.time) + '</span>' + ticks(m.ticks) + '</div></div>';
    }).join('');
    if (stickBottom) thread.scrollTop = thread.scrollHeight;
  }
  function renderHead() {
    document.getElementById('waHeadName').textContent = state.name || 'Select a chat';
    document.getElementById('waHeadPhone').textContent = state.display_phone || '';
    document.getElementById('waHeadAv').textContent = (state.name || '?').trim().split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase() || '?';
    document.getElementById('waApp').classList.toggle('is-thread', !!state.phone);
    var live = document.getElementById('waLive');
    live.textContent = state.live ? 'Live' : 'Waiting';
    live.classList.toggle('on', !!state.live);
    document.getElementById('waSendBtn').disabled = !state.phone;
  }
  function renderLog() {
    var html = (state.events || []).map(function (e) {
      return '<div class="wa-log-item ' + esc(e.kind) + '"><div class="k">' + esc(e.label) + '</div>'
        + '<div class="d">' + esc(e.detail) + '</div>'
        + '<div class="t">' + esc(e.when) + (e.phone && e.phone !== 'Unknown' ? ' · ' + esc(e.phone) : '') + '</div></div>';
    }).join('');
    document.getElementById('waLogBody').innerHTML = html || '<div class="wa-empty">Incoming, sent, and webhook updates show here.</div>';
  }
  function sig(data) {
    var lastMsg = (data.messages && data.messages.length) ? data.messages[data.messages.length - 1].id : 0;
    var lastEv = (data.events && data.events[0]) ? data.events[0].id : 0;
    return [data.phone, lastMsg, lastEv, data.unread, (data.chats || []).length].join(':');
  }
  function apply(data, force) {
    if (!data) return;
    var next = sig(data);
    if (!force && next === lastSig && !data.error) return;
    lastSig = next;
    state = data;
    renderChats();
    renderThread();
    renderHead();
    renderLog();
    if (data.error) showErr(data.error);
  }
  function sync(phone) {
    var url = endpoint + '?ajax=sync&phone=' + encodeURIComponent(phone || state.phone || '');
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { apply(data); })
      .catch(function () {});
  }
  function send(body, extra) {
    if (sending) return;
    extra = extra || {};
    var phone = extra.phone || state.phone;
    if (!body) return;
    sending = true;
    document.getElementById('waSendBtn').disabled = true;
    var fd = new FormData();
    fd.append('ajax', 'send');
    fd.append('csrf', csrf);
    fd.append('phone', phone || '');
    fd.append('body', body);
    if (extra.new_phone) fd.append('new_phone', extra.new_phone);
    fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        sending = false;
        document.getElementById('waSendBtn').disabled = false;
        if (data && data.ok) {
          showErr('');
          document.getElementById('waInput').value = '';
          history.replaceState({}, '', endpoint + '?phone=' + encodeURIComponent(data.phone || phone));
        }
        apply(data || {}, true);
        if (data && !data.ok) showErr(data.error || 'Could not send.');
        stickBottom = true;
        renderThread();
      })
      .catch(function () {
        sending = false;
        document.getElementById('waSendBtn').disabled = false;
        showErr('Network error. Try again.');
      });
  }

  document.getElementById('waList').addEventListener('click', function (e) {
    var row = e.target.closest('.wa-row');
    if (!row) return;
    state.phone = row.getAttribute('data-phone') || '';
    history.replaceState({}, '', endpoint + '?phone=' + encodeURIComponent(state.phone));
    stickBottom = true;
    sync(state.phone);
  });
  document.getElementById('waBack').addEventListener('click', function () {
    document.getElementById('waApp').classList.remove('is-thread', 'show-log');
  });
  document.getElementById('waLogBtn').addEventListener('click', function () {
    document.getElementById('waApp').classList.toggle('show-log');
  });
  document.getElementById('waFilter').addEventListener('input', renderChats);
  document.getElementById('waSendForm').addEventListener('submit', function (e) {
    e.preventDefault();
    send((document.getElementById('waInput').value || '').trim());
  });
  document.getElementById('waInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send((document.getElementById('waInput').value || '').trim());
    }
  });
  document.getElementById('waThread').addEventListener('scroll', function () {
    var el = document.getElementById('waThread');
    stickBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
  });
  document.getElementById('waNewForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var phone = (this.new_phone.value || '').trim();
    var body = (this.body.value || '').trim();
    send(body, { new_phone: phone, phone: phone });
    this.body.value = '';
  });

  apply(state, true);
  setInterval(function () { if (!sending) sync(state.phone); }, 2500);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
