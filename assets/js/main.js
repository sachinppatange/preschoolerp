// assets/js/main.js
// General UI helpers and AJAX for enquiry form.
// Assumes Bootstrap bundle is already loaded.

document.addEventListener('DOMContentLoaded', function () {
  // Simple helper to show an alert inside a container element (Bootstrap alert)
  function showAlert(container, message, type = 'success', timeout = 6000) {
    const wrapper = document.createElement('div');
    wrapper.className = 'alert injected-alert alert-' + (type === 'success' ? 'success' : 'danger');
    wrapper.role = 'alert';
    wrapper.innerHTML = message;

    container.prepend(wrapper);

    if (timeout > 0) {
      setTimeout(() => {
        if (wrapper && wrapper.remove) wrapper.remove();
      }, timeout);
    }
  }

  // Enquiry form AJAX submit with graceful fallback
  const enquiryForm = document.getElementById('enquiryForm');
  if (enquiryForm) {
    enquiryForm.addEventListener('submit', function (e) {
      // If user agents don't support fetch, allow normal submit
      if (!window.fetch) return;

      e.preventDefault();
      const submitBtn = enquiryForm.querySelector('button[type="submit"]');
      const originalText = submitBtn ? submitBtn.innerHTML : null;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Sending...';
      }

      const formData = new FormData(enquiryForm);
      const action = enquiryForm.getAttribute('action') || window.location.href;
      // Attempt AJAX POST, expecting JSON response: { ok: bool, message: "", redirect: "" }
      fetch(action, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      }).then(response => {
        // If server redirected (e.g., PHP does a redirect), follow it by allowing normal navigation
        if (response.redirected) {
          window.location.href = response.url;
          return null;
        }
        // Try parse JSON
        return response.json().catch(() => null);
      }).then(data => {
        if (!data) {
          // Not JSON or handled by redirect — fallback to full form submit
          enquiryForm.submit();
          return;
        }

        if (data.ok) {
          showAlert(enquiryForm, data.message || 'Enquiry submitted successfully.', 'success', 8000);
          enquiryForm.reset();
        } else {
          showAlert(enquiryForm, data.message || 'Failed to submit enquiry. Please try again.', 'error', 8000);
        }
      }).catch(err => {
        console.error('Enquiry submit error', err);
        showAlert(enquiryForm, 'Internal error. Please try again later.', 'error', 8000);
      }).finally(() => {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
        }
      });
    });
  }

  // Mobile behavior: collapse nav after clicking a nav link (Bootstrap collapse)
  const navLinks = document.querySelectorAll('.navbar-collapse .nav-link');
  const bsCollapseEl = document.querySelector('.navbar-collapse');
  if (bsCollapseEl) {
    navLinks.forEach(function (link) {
      link.addEventListener('click', function () {
        // If collapse is visible, collapse it
        const bsCollapse = bootstrap.Collapse.getInstance(bsCollapseEl) || null;
        if (bsCollapse && window.getComputedStyle(bsCollapseEl).display !== 'none') {
          bsCollapse.hide();
        }
      });
    });
  }

  // Smooth scroll for anchor links to in-page sections
  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      const href = anchor.getAttribute('href');
      if (href.length > 1) {
        const target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
  });

  // Tiny back-to-top button (create on the fly)
  const backTop = document.createElement('button');
  backTop.className = 'btn btn-primary';
  backTop.style.position = 'fixed';
  backTop.style.right = '1rem';
  backTop.style.bottom = '1rem';
  backTop.style.display = 'none';
  backTop.style.zIndex = '1050';
  backTop.innerHTML = '<i class="bi bi-arrow-up"></i>';
  document.body.appendChild(backTop);
  backTop.addEventListener('click', function () { window.scrollTo({top:0,behavior:'smooth'}); });
  window.addEventListener('scroll', function () {
    if (window.scrollY > 300) backTop.style.display = 'block'; else backTop.style.display = 'none';
  });
});