// Guest booking manage — fetch submit for add-on & change forms.
(function () {
  // Toast — a small card that slides up from the bottom, then fades out.
  function toast(message, type) {
    var wrap = document.getElementById('ts-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ts-toasts'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'ts-toast ts-toast--' + (type === 'err' ? 'err' : 'ok');
    t.setAttribute('role', 'status');
    var icon = document.createElement('span'); icon.className = 'ts-toast__icon'; icon.setAttribute('aria-hidden', 'true');
    icon.textContent = type === 'err' ? '✕' : '✓';
    var msg = document.createElement('span'); msg.className = 'ts-toast__msg'; msg.textContent = message;
    var x = document.createElement('button'); x.type = 'button'; x.className = 'ts-toast__x';
    x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    t.appendChild(icon); t.appendChild(msg); t.appendChild(x);
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    var timer;
    function remove() { clearTimeout(timer); t.classList.remove('is-in'); t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 260); }
    x.addEventListener('click', remove);
    timer = setTimeout(remove, type === 'err' ? 6500 : 4000);
    return t;
  }

  // Collapse any open concierge tile/form (after a request is sent).
  function collapseConciergeForms() {
    document.querySelectorAll('.cx-form.open').forEach(function (f) { f.classList.remove('open'); });
    document.querySelectorAll('.cx-tile[aria-expanded="true"]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }

  // "Request sent" popup — offered when the request opened a message thread.
  function showRequestSentModal(redirectUrl, form, btn) {
    var back = document.createElement('div');
    back.className = 'pa-modal-backdrop';
    back.innerHTML =
      '<div class="pa-modal" role="dialog" aria-modal="true" aria-label="Request sent">' +
        '<div class="pa-modal__icon" aria-hidden="true">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
        '</div>' +
        '<h3 class="pa-modal__title">Request sent</h3>' +
        '<p class="pa-modal__body">We’ve started a conversation for this — our team will confirm shortly.</p>' +
        '<div class="pa-modal__actions">' +
          '<button type="button" class="pa-btn pa-btn--primary" data-pa-manage>Manage request</button>' +
          '<button type="button" class="pa-btn" data-pa-continue>Continue</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(back);
    document.body.style.overflow = 'hidden';

    function done() { back.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); }
    function cont() {
      done();
      if (form) form.reset();
      if (btn) { btn.disabled = false; if (btn.dataset.label) btn.textContent = btn.dataset.label; }
      collapseConciergeForms();
    }
    function onKey(e) { if (e.key === 'Escape') cont(); }

    back.querySelector('[data-pa-manage]').addEventListener('click', function () { window.location = redirectUrl; });
    back.querySelector('[data-pa-continue]').addEventListener('click', cont);
    back.addEventListener('click', function (e) { if (e.target === back) cont(); });
    document.addEventListener('keydown', onKey);
  }

  document.querySelectorAll('form[data-bm]').forEach(function (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      var payload = Object.fromEntries(new FormData(form).entries());
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }
      try {
        var res = await fetch(form.getAttribute('action'), {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        var data = await res.json();
        if (data.ok) {
          if (data.redirect) {
            // A request that opened a message thread — offer Manage / Continue.
            showRequestSentModal(data.redirect, form, btn);
          } else {
            toast(form.getAttribute('data-bm-success') || 'Request sent — we’ll be in touch by email.', 'ok');
            setTimeout(function () { window.location = window.location.href.split('#')[0]; }, 1200);
          }
        } else {
          toast(data.error || 'Something went wrong. Please try again.', 'err');
          if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
          if (window.turnstile) window.turnstile.reset();
        }
      } catch (_) {
        toast('Network error. Please try again.', 'err');
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
      }
    });
  });

  // ── Live chat: append on send + poll for incoming (no page refresh) ──
  var thread = document.getElementById('bmThread');
  if (thread) {
    var lastId = parseInt(thread.dataset.last || '0', 10) || 0;
    var pollUrl = thread.dataset.pollUrl;
    var meSender = thread.dataset.me || 'guest';

    function atBottom() {
      return (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 120);
    }
    function appendMsg(m) {
      if (m.id && document.querySelector('[data-mid="' + m.id + '"]')) return; // dedupe
      var mine = m.sender === meSender;
      var empty = thread.querySelector('.bm-empty');
      if (empty) empty.style.display = 'none';
      var el = document.createElement('div');
      el.className = 'bm-msg';
      if (m.id) el.setAttribute('data-mid', m.id);
      el.style.cssText = 'max-width:80%;padding:9px 12px;font-size:14px;line-height:1.5;' + (mine
        ? 'align-self:flex-end;background:var(--pa-teal-d);color:#fff;border-radius:12px 12px 2px 12px'
        : 'align-self:flex-start;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px 12px 12px 2px');
      el.appendChild(document.createTextNode(m.body));
      var meta = document.createElement('div');
      meta.style.cssText = 'font-size:11px;margin-top:4px;' + (mine ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)');
      meta.textContent = (mine ? 'You' : 'Concierge') + ' · ' + m.time_label;
      el.appendChild(meta);
      thread.appendChild(el);
      if (m.id && m.id > lastId) lastId = m.id;
    }

    var polling = false;
    async function poll() {
      if (polling || document.hidden) return;
      polling = true;
      try {
        var url = pollUrl + '?ref=' + encodeURIComponent(thread.dataset.ref)
          + '&thread=' + encodeURIComponent(thread.dataset.thread) + '&after=' + lastId;
        var res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        var data = await res.json();
        if (data.ok && data.messages && data.messages.length) {
          var stick = atBottom();
          data.messages.forEach(appendMsg);
          if (stick) window.scrollTo(0, document.body.scrollHeight);
        }
      } catch (_) { /* transient — try again next tick */ }
      polling = false;
    }
    setInterval(poll, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

    // Send: append the guest's own message immediately, then let the poll fill in replies.
    var chatForm = document.querySelector('form[data-chat]');
    if (chatForm) {
      chatForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        var ta = chatForm.querySelector('textarea[name=body]');
        var btn = chatForm.querySelector('button[type=submit]');
        var status = chatForm.querySelector('.bm-status');
        var body = (ta && ta.value || '').trim();
        if (!body) return;
        if (btn) btn.disabled = true;
        if (status) { status.style.color = 'var(--pa-muted)'; status.textContent = 'Sending…'; }
        try {
          var res = await fetch(chatForm.getAttribute('action'), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.fromEntries(new FormData(chatForm).entries())),
          });
          var data = await res.json();
          if (data.ok) {
            if (data.message) appendMsg(data.message);
            if (ta) ta.value = '';
            if (status) status.textContent = '';
            window.scrollTo(0, document.body.scrollHeight);
          } else if (status) {
            status.style.color = '#b91c1c';
            status.textContent = data.error || 'Could not send. Please try again.';
          }
        } catch (_) {
          if (status) { status.style.color = '#b91c1c'; status.textContent = 'Network error. Please try again.'; }
        }
        if (btn) btn.disabled = false;
      });
    }
  }
})();
