<script>
(function () {
  const phone = document.getElementById('phone');
  const sendBtn = document.getElementById('sendOtpBtn');
  if (phone && sendBtn) {
    phone.addEventListener('input', function (e) {
      const cleaned = e.target.value.replace(/\D+/g, '').slice(0, 10);
      e.target.value = cleaned;
      sendBtn.disabled = cleaned.length !== 10;
    });
    sendBtn.disabled = true;
  }

  const otpInput = document.getElementById('otp');
  const otpForm = document.getElementById('otpForm');
  if (otpInput && otpForm) {
    const maxLen = <?php echo (int)($OTP_LENGTH ?? 4); ?>;
    otpInput.addEventListener('input', function (e) {
      let v = e.target.value.replace(/\D+/g, '').slice(0, maxLen);
      e.target.value = v;
      if (v.length === maxLen) {
        setTimeout(function () { try { otpForm.submit(); } catch (err) {} }, 150);
      }
    });
    setTimeout(function () { try { otpInput.focus(); } catch (e) {} }, 120);
  }

  const resendBtn = document.getElementById('resendBtn');
  if (resendBtn) {
    let remaining = <?php echo (int)($cooldownRemaining ?? 0); ?>;
    if (remaining > 0) {
      resendBtn.setAttribute('disabled', 'true');
      const timer = setInterval(function () {
        if (remaining > 0) {
          remaining--;
          resendBtn.textContent = 'Resend in ' + ('0' + remaining).slice(-2) + 's';
        } else {
          resendBtn.textContent = 'Resend OTP';
          resendBtn.removeAttribute('disabled');
          clearInterval(timer);
        }
      }, 1000);
    }
  }

  const togglePw = document.getElementById('toggleLoginPw');
  const pwInput = document.getElementById('login_password');
  if (togglePw && pwInput) {
    togglePw.addEventListener('click', function () {
      const show = pwInput.type === 'password';
      pwInput.type = show ? 'text' : 'password';
      togglePw.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
  }
})();
</script>
