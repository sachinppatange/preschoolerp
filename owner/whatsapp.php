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
.panel-content:has(.wa-app) { padding: 0.6rem; overflow: hidden; display: flex; flex-direction: column; min-height: 0; }
.panel-main:has(.wa-app) { overflow: hidden; }
.wa-app {
  --wa:#008069; --wa-mint:#25d366; --wa-chat:#efeae2; --wa-out:#d9fdd3;
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  width: 100%;
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  background: #fff;
  border: 1px solid #d1d7db;
  border-radius: 12px;
  overflow: hidden;
}
@media (max-width: 800px) {
  .wa-app { grid-template-columns: 1fr; }
  .wa-app.is-thread .wa-sidebar { display: none; }
  .wa-app:not(.is-thread) .wa-stage { display: none; }
}
.wa-sidebar, .wa-stage, .wa-log { min-width: 0; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
.wa-sidebar { border-right: 1px solid #e9edef; background: #fff; }
.wa-head, .wa-chat-head { flex: 0 0 auto; background: var(--wa); color: #fff; padding: 8px 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.wa-head h2, .wa-chat-head .nm { margin: 0; font-size: 1.05rem; font-weight: 700; color: #fff; }
.wa-head button, .wa-chat-head button, .wa-head a { color: #fff; background: transparent; border: 0; font-size: .82rem; }
.wa-live { font-size: .72rem; background: rgba(255,255,255,.2); border-radius: 999px; padding: 2px 8px; }
.wa-live.on { background: var(--wa-mint); color: #053b27; font-weight: 700; }
.wa-search { flex: 0 0 auto; padding: 8px 10px; }
.wa-search input { width: 100%; border: 0; background: #f0f2f5; border-radius: 8px; padding: 8px 12px; }
.wa-list, .wa-thread, .wa-log-body {
  flex: 1 1 0;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: scroll;
  -webkit-overflow-scrolling: touch;
}
.wa-row { display: flex; gap: 10px; padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f2f5; }
.wa-row:hover { background: #f5f6f6; }
.wa-row.active { background: #f0f2f5; }
.wa-av { width: 42px; height: 42px; border-radius: 50%; background: #dfe5e7; color: #54656f; display: flex; align-items: center; justify-content: center; font-weight: 700; flex: 0 0 42px; }
.wa-row-main { min-width: 0; flex: 1; }
.wa-row-top { display: flex; justify-content: space-between; gap: 8px; }
.wa-row-top .nm { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wa-row-top .tm { font-size: .72rem; color: #667781; white-space: nowrap; }
.wa-snip { color: #667781; font-size: .85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wa-stage { background: var(--wa-chat); }
.wa-chat-head { justify-content: flex-start; }
.wa-chat-head .meta { min-width: 0; flex: 1; }
.wa-chat-head .ph { font-size: .78rem; opacity: .9; }
.wa-back { display: none; }
@media (max-width: 800px) { .wa-back { display: inline-flex; } }
.wa-err { display: none; flex: 0 0 auto; padding: 6px 12px; background: #ffe8e6; color: #b42318; font-size: .82rem; }
.wa-err.on { display: block; }
.wa-thread { padding: 10px 12px 14px; }
.wa-bubble { max-width: 78%; padding: 7px 9px 4px; border-radius: 8px; font-size: .95rem; line-height: 1.4; box-shadow: 0 1px 1px rgba(11,20,26,.06); white-space: pre-wrap; word-break: break-word; margin-bottom: 6px; }
.wa-in { margin-right: auto; background: #fff; }
.wa-out { margin-left: auto; background: var(--wa-out); }
.wa-out.failed { background: #ffe8e6; }
.wa-bubble .ft { display: flex; justify-content: flex-end; gap: 4px; margin-top: 2px; font-size: .68rem; color: #667781; }
.wa-ticks.read { color: #53bdeb; }
.wa-compose { flex: 0 0 auto; background: #f0f2f5; padding: 8px 10px; display: flex; gap: 8px; align-items: center; border-top: 1px solid #e9edef; }
.wa-compose input { flex: 1; border: 0; border-radius: 22px; padding: 10px 14px; min-height: 44px; }
.wa-send { flex: 0 0 auto; border: 0; border-radius: 22px; background: var(--wa); color: #fff; font-weight: 700; padding: 10px 16px; min-height: 44px; }
.wa-send:disabled { opacity: .5; }
.wa-empty { padding: 2rem 1rem; text-align: center; color: #667781; }
.wa-log {
  display: none;
  position: absolute;
  top: 0; right: 0; bottom: 0;
  width: min(320px, 90vw);
  background: #fff;
  border-left: 1px solid #e9edef;
  box-shadow: -8px 0 24px rgba(0,0,0,.08);
  z-index: 6;
}
.wa-app.show-log .wa-log { display: flex; }
.wa-log-item { padding: 8px 12px; border-bottom: 1px solid #eef2f0; font-size: .8rem; }
.wa-log-item .k { font-weight: 700; color: #008069; }
.wa-log-item.error .k { color: #b42318; }
.wa-log-item .d { color: #3b4a54; margin-top: 2px; word-break: break-word; }
.wa-log-item .t { color: #667781; font-size: .7rem; }
.wa-plus { width: 36px; height: 36px; border: 0; border-radius: 50%; background: rgba(255,255,255,.2); color: #fff; }
.wa-new { display: none; flex: 0 0 auto; padding: 8px 10px; border-top: 1px solid #e9edef; background: #f8faf9; }
.wa-app.show-new .wa-new { display: block; }
</style>

<div class="wa-app<?php echo $phone !== '' ? ' is-thread' : ''; ?>" id="waApp">
  <aside class="wa-sidebar">
    <div class="wa-head">
      <h2>Chats</h2>
      <div class="d-flex align-items-center gap-2">
        <span class="wa-live" id="waLive">Live</span>
        <button type="button" class="wa-plus" id="waNewBtn" title="New chat">+</button>
      </div>
    </div>
    <div class="wa-search"><input type="search" id="waFilter" placeholder="Search"></div>
    <div class="wa-list" id="waList"></div>
    <div class="wa-new" id="waNewBox">
      <form id="waNewForm">
        <input class="form-control form-control-sm mb-1" name="new_phone" maxlength="15" placeholder="10-digit mobile">
        <div class="d-flex gap-1">
          <input class="form-control form-control-sm" name="body" placeholder="Message">
          <button class="btn btn-sm btn-success" type="submit">Send</button>
        </div>
      </form>
    </div>
  </aside>

  <section class="wa-stage">
    <div class="wa-chat-head">
      <button class="wa-back" type="button" id="waBack" aria-label="Back"><i class="bi bi-arrow-left"></i></button>
      <div class="wa-av" id="waHeadAv">?</div>
      <div class="meta">
        <div class="nm" id="waHeadName">Select a chat</div>
        <div class="ph" id="waHeadPhone"></div>
      </div>
      <button type="button" class="ms-auto" id="waLogBtn">Log</button>
    </div>
    <div class="wa-err" id="waErr"></div>
    <div class="wa-thread" id="waThread"></div>
    <form class="wa-compose" id="waSendForm">
      <input type="text" id="waInput" placeholder="Type a message" autocomplete="off">
      <button class="wa-send" type="submit" id="waSendBtn">Send</button>
    </form>
  </section>

  <aside class="wa-log" id="waLog">
    <div class="wa-head">
      <h2>Log</h2>
      <button type="button" id="waLogClose">Close</button>
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

  function fit() {
    var app = document.getElementById('waApp');
    if (!app) return;
    var top = app.getBoundingClientRect().top;
    var h = Math.max(420, window.innerHeight - top - 8);
    app.style.height = h + 'px';
  }
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
        + '<span class="tm">' + esc(c.when) + '</span></div>'
        + '<div class="wa-snip">' + esc(c.last_body) + '</div></div></div>';
    });
    document.getElementById('waList').innerHTML = html || '<div class="wa-empty">No chats yet.</div>';
  }
  function renderThread() {
    var thread = document.getElementById('waThread');
    var msgs = state.messages || [];
    if (!state.phone) {
      thread.innerHTML = '<div class="wa-empty">Select a chat, then type below and press Send.</div>';
      return;
    }
    if (!msgs.length) {
      thread.innerHTML = '<div class="wa-empty">No messages yet. Type below and press Send.</div>';
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
    live.textContent = state.live ? 'Live' : 'On';
    live.classList.toggle('on', !!state.live);
    document.getElementById('waSendBtn').disabled = !state.phone || sending;
    document.getElementById('waInput').disabled = !state.phone;
  }
  function renderLog() {
    var html = (state.events || []).map(function (e) {
      return '<div class="wa-log-item ' + esc(e.kind) + '"><div class="k">' + esc(e.label) + '</div>'
        + '<div class="d">' + esc(e.detail) + '</div>'
        + '<div class="t">' + esc(e.when) + (e.phone && e.phone !== 'Unknown' ? ' · ' + esc(e.phone) : '') + '</div></div>';
    }).join('');
    document.getElementById('waLogBody').innerHTML = html || '<div class="wa-empty">No activity yet.</div>';
  }
  function sig(data) {
    var lastMsg = (data.messages && data.messages.length) ? data.messages[data.messages.length - 1].id : 0;
    var lastEv = (data.events && data.events[0]) ? data.events[0].id : 0;
    return [data.phone, lastMsg, lastEv, (data.chats || []).length].join(':');
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
    fit();
  }
  function sync(phone) {
    var url = endpoint + '?ajax=sync&phone=' + encodeURIComponent(phone || state.phone || '');
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { apply(data); })
      .catch(function () {});
  }
  function send(body, extra) {
    extra = extra || {};
    var phone = extra.phone || extra.new_phone || state.phone;
    body = (body || '').trim();
    if (!body) { showErr('Type a message first.'); return; }
    if (!phone) { showErr('Choose a chat first.'); return; }
    if (sending) return;
    sending = true;
    showErr('');
    document.getElementById('waSendBtn').disabled = true;
    var fd = new FormData();
    fd.append('ajax', 'send');
    fd.append('csrf', csrf);
    fd.append('phone', phone);
    fd.append('body', body);
    if (extra.new_phone) fd.append('new_phone', extra.new_phone);
    fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        sending = false;
        if (data && data.ok) {
          var inp = document.getElementById('waInput');
          if (inp) inp.value = '';
          history.replaceState({}, '', endpoint + '?phone=' + encodeURIComponent(data.phone || phone));
          stickBottom = true;
        }
        apply(data || {}, true);
        if (data && !data.ok) showErr(data.error || 'Could not send. Parent must message first (24-hour window).');
        document.getElementById('waInput').focus();
      })
      .catch(function () {
        sending = false;
        renderHead();
        showErr('Network error. Try Send again.');
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
    var log = document.getElementById('waLogBody');
    if (log) log.scrollTop = 0;
  });
  document.getElementById('waLogClose').addEventListener('click', function () {
    document.getElementById('waApp').classList.remove('show-log');
  });
  document.getElementById('waNewBtn').addEventListener('click', function () {
    document.getElementById('waApp').classList.toggle('show-new');
  });
  document.getElementById('waFilter').addEventListener('input', renderChats);
  document.getElementById('waSendForm').addEventListener('submit', function (e) {
    e.preventDefault();
    send(document.getElementById('waInput').value);
  });
  document.getElementById('waThread').addEventListener('scroll', function () {
    var el = document.getElementById('waThread');
    stickBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
  });
  document.getElementById('waNewForm').addEventListener('submit', function (e) {
    e.preventDefault();
    send(this.body.value, { new_phone: this.new_phone.value, phone: this.new_phone.value });
    this.body.value = '';
  });
  window.addEventListener('resize', fit);
  fit();
  apply(state, true);
  setTimeout(fit, 50);
  setInterval(function () { if (!sending) sync(state.phone); }, 2500);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
