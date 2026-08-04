// Guest booking manage — fetch submit for add-on & change forms.
(function () {
  document.querySelectorAll('form[data-bm]').forEach(function (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      var status = form.querySelector('.bm-status');
      var payload = Object.fromEntries(new FormData(form).entries());
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }
      try {
        var res = await fetch(form.getAttribute('action'), {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        var data = await res.json();
        if (data.ok) {
          if (status) { status.textContent = form.getAttribute('data-bm-success') || 'Request sent — we’ll be in touch by email.'; status.className = 'bm-status ok'; }
          var next = data.redirect;
          setTimeout(function () { window.location = next ? next : window.location.href.split('#')[0]; }, 1200);
        } else {
          if (status) { status.textContent = data.error || 'Something went wrong. Please try again.'; status.className = 'bm-status err'; }
          if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
          if (window.turnstile) window.turnstile.reset();
        }
      } catch (_) {
        if (status) { status.textContent = 'Network error. Please try again.'; status.className = 'bm-status err'; }
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
      }
    });
  });
})();
