// assets/js/whatsapp-auth.js
// Client-side helpers for WhatsApp OTP flows (send OTP, show modal, verify OTP).
// Expects endpoints:
//  - POST /whatsapp/send_otp.php   (JSON) { phone, role } -> { ok, message, data:{ expires_in } }
//  - POST /whatsapp/verify_otp.php (JSON) { phone, role, otp } -> { ok, message, data:{ redirect } }
// Uses Bootstrap modal markup created dynamically.

(function () {
  // Utility: simple fetch JSON wrapper
  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(resp => resp.json().catch(() => { throw new Error('Invalid JSON response'); }));
  }

  // Show ephemeral toast/alert (Bootstrap alert fallback)
  function showToast(message, type = 'info', container = document.body, timeout = 6000) {
    const div = document.createElement('div');
    div.className = 'alert alert-' + (type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info'));
    div.style.position = 'fixed';
    div.style.right = '1rem';
    div.style.top = '1rem';
    div.style.zIndex = 11000;
    div.innerText = message;
    container.appendChild(div);
    setTimeout(() => { div.remove(); }, timeout);
  }

  // Create OTP modal DOM and return helper object
  function createOtpModal() {
    // If already exists, return it
    let existing = document.getElementById('waOtpModal');
    if (existing) {
      return {
        el: existing,
        modal: bootstrap.Modal.getOrCreateInstance(existing)
      };
    }

    const modalHtml = `
      <div class="modal fade" id="waOtpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">WhatsApp OTP Verification</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div id="wa-otp-alert"></div>
              <p class="mb-2 small text-muted">We have sent a 4-digit OTP to <strong id="wa-otp-phone"></strong>. Enter it below to continue.</p>
              <div class="mb-3">
                <input type="text" id="wa-otp-input" class="form-control form-control-lg" maxlength="6" placeholder="Enter OTP" inputmode="numeric" pattern="[0-9]*" autofocus>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <button id="wa-resend-btn" class="btn btn-link p-0">Resend</button>
                </div>
                <div>
                  <button id="wa-verify-btn" class="btn btn-primary">Verify</button>
                </div>
              </div>
              <div class="mt-2 small text-muted" id="wa-countdown"></div>
            </div>
          </div>
        </div>
      </div>
    `;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = modalHtml;
    document.body.appendChild(wrapper.firstElementChild);
    const el = document.getElementById('waOtpModal');
    return { el, modal: bootstrap.Modal.getOrCreateInstance(el) };
  }

  // Start cooldown for resend button
  function startResendCooldown(btn, seconds, countdownEl) {
    btn.disabled = true;
    let remaining = seconds;
    countdownEl.textContent = `You can resend OTP in ${remaining}s`;
    const timer = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(timer);
        btn.disabled = false;
        countdownEl.textContent = '';
      } else {
        countdownEl.textContent = `You can resend OTP in ${remaining}s`;
      }
    }, 1000);
  }

  // Public: initiate OTP flow (phone in E.164 or local, role required)
  async function initiateOtp(phone, role, options = {}) {
    if (!phone || !role) throw new Error('phone and role required');
    const modalObj = createOtpModal();
    const modalEl = modalObj.el;
    const modal = modalObj.modal;
    const phoneLabel = modalEl.querySelector('#wa-otp-phone');
    const alertBox = modalEl.querySelector('#wa-otp-alert');
    const otpInput = modalEl.querySelector('#wa-otp-input');
    const verifyBtn = modalEl.querySelector('#wa-verify-btn');
    const resendBtn = modalEl.querySelector('#wa-resend-btn');
    const countdownEl = modalEl.querySelector('#wa-countdown');

    // Helper to set alert
    function setAlert(msg, type = 'info') {
      alertBox.innerHTML = `<div class="alert alert-${type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info')}" role="alert">${msg}</div>`;
    }

    phoneLabel.textContent = phone;
    setAlert('Sending OTP...', 'info');
    otpInput.value = '';

    try {
      const resp = await postJson('/whatsapp/send_otp.php', { phone: phone, role: role });
      if (resp.ok) {
        setAlert('OTP sent. Please check WhatsApp.', 'success');
        // start cooldown (use server-configured expires_in if provided)
        const expires = (resp.data && resp.data.expires_in) ? parseInt(resp.data.expires_in) : (options.expires_in || 300);
        startResendCooldown(resendBtn, Math.min(60, Math.max(10, Math.floor(expires / 5))), countdownEl);
      } else {
        setAlert(resp.message || 'Failed to send OTP. Try later.', 'error');
      }
    } catch (err) {
      console.error('send_otp error', err);
      setAlert('Network or server error. Try again later.', 'error');
    }

    // Show modal after sending attempt
    modal.show();

    // Wire verify action
    async function doVerify() {
      const otp = otpInput.value.trim();
      if (!/^\d{3,6}$/.test(otp)) {
        setAlert('Enter the 4-digit code you received on WhatsApp.', 'error');
        return;
      }
      setAlert('Verifying OTP...', 'info');
      verifyBtn.disabled = true;
      try {
        const r = await postJson('/whatsapp/verify_otp.php', { phone: phone, role: role, otp: otp });
        if (r.ok) {
          setAlert('OTP verified. Redirecting...', 'success');
          // If server gives redirect, follow it; else reload
          const redirectUrl = r.data && r.data.redirect ? r.data.redirect : null;
          setTimeout(() => {
            if (redirectUrl) window.location.href = redirectUrl;
            else window.location.reload();
          }, 900);
        } else {
          setAlert(r.message || 'Invalid OTP. Please try again.', 'error');
        }
      } catch (err) {
        console.error('verify_otp error', err);
        setAlert('Verification error. Try again later.', 'error');
      } finally {
        verifyBtn.disabled = false;
      }
    }

    verifyBtn.onclick = doVerify;
    otpInput.onkeydown = function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        doVerify();
      }
    };

    // Wire resend action
    resendBtn.onclick = async function (ev) {
      ev.preventDefault();
      if (resendBtn.disabled) return;
      setAlert('Resending OTP...', 'info');
      try {
        const r = await postJson('/whatsapp/send_otp.php', { phone: phone, role: role });
        if (r.ok) {
          setAlert('OTP resent. Check WhatsApp.', 'success');
          const expires = (r.data && r.data.expires_in) ? parseInt(r.data.expires_in) : (options.expires_in || 300);
          startResendCooldown(resendBtn, Math.min(60, Math.max(10, Math.floor(expires / 5))), countdownEl);
        } else {
          setAlert(r.message || 'Failed to resend OTP.', 'error');
        }
      } catch (err) {
        console.error('resend error', err);
        setAlert('Network error. Try again later.', 'error');
      }
    };

    // return control to caller
    return { modal, modalEl };
  }

  // Auto-bind to elements with data-wa-login attributes
  document.querySelectorAll('[data-wa-login="true"]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      // Accept data attributes: data-wa-phone or data-wa-phone-sel (CSS selector)
      const role = btn.getAttribute('data-wa-role') || btn.dataset.role || null;
      let phone = btn.getAttribute('data-wa-phone') || null;
      const phoneSel = btn.getAttribute('data-wa-phone-sel');
      if (!phone && phoneSel) {
        const el = document.querySelector(phoneSel);
        if (el) phone = el.value || el.textContent || null;
      }
      if (!phone) {
        // prompt fallback
        phone = window.prompt('Enter phone number (with country code, e.g. +9198...)');
      }
      if (!phone) {
        showToast('Phone number required for WhatsApp login', 'error');
        return;
      }
      if (!role) {
        showToast('Login role not specified', 'error');
        return;
      }
      // Initiate OTP flow
      initiateOtp(phone.trim(), role);
    });
  });

  // Expose API to global scope (if other scripts want to call)
  window.ppsWhatsAppAuth = {
    initiateOtp: initiateOtp
  };
})();