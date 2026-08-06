// Guest booking manage — fetch submit for add-on & change forms.
(function () {
  // Confirmation toast that survives the post-submit reload (set in sessionStorage before reload).
  function showToast(msg) {
    var el = document.createElement('div');
    el.className = 'bm-toast';
    el.setAttribute('role', 'status');
    el.textContent = msg;
    document.body.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });
    setTimeout(function () {
      el.classList.remove('show');
      setTimeout(function () { el.remove(); }, 300);
    }, 4000);
  }
  try {
    var pending = sessionStorage.getItem('bm_toast');
    if (pending) { sessionStorage.removeItem('bm_toast'); showToast(pending); }
  } catch (_) {}

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
          var msg = 'Request sent — we’ll confirm by email.';
          if (status) { status.textContent = msg; status.className = 'bm-status ok'; }
          try { sessionStorage.setItem('bm_toast', msg); } catch (_) {}
          setTimeout(function () { window.location.reload(); }, 900);
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
