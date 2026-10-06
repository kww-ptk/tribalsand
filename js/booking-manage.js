// Guest booking manage — fetch submit for add-on & change forms.
// Loaded once per full page. Tab switches swap .pa-wrap without a reload
// (js/portal-nav.js), so everything that binds to page content lives in
// init(root) and is re-run through window.tsPortalInit after each swap;
// document-level listeners are bound once.
(function () {
  if (window.tsPortalInit) return;
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

  function bindForms(root) {
  root.querySelectorAll('form[data-bm]:not([data-bm-bound])').forEach(function (form) {
    form.setAttribute('data-bm-bound', '1');
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
  }

  // Copy buttons for any .ci-linkrow outside the check-in form: the check-in
  // confirmation card and the party roster on Home. booking-manage.js loads on
  // every portal view; checkin-wizard.js only loads on the check-in view, so this
  // cannot live there. In-form buttons are skipped — checkin-wizard.js owns those.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains('ci-copy') || t.closest('#ciForm')) return;
    e.preventDefault();
    var row = t.closest('.ci-linkrow'); if (!row) return;
    var inp = row.querySelector('input'); if (!inp) return;
    inp.select();
    try { navigator.clipboard.writeText(inp.value); } catch (_) { try { document.execCommand('copy'); } catch (__) {} }
    var o = t.textContent; t.textContent = 'Copied ✓'; setTimeout(function () { t.textContent = o; }, 1500);
  });

  // ── Live chat: append on send + poll for incoming (no page refresh) ──
  // One poll timer for the whole page: a tab swap stops the old thread's poll.
  var chatTimer = null, chatPoll = null;
  document.addEventListener('visibilitychange', function () { if (!document.hidden && chatPoll) chatPoll(); });
  function initChat(root) {
  if (chatTimer) { clearInterval(chatTimer); chatTimer = null; chatPoll = null; }
  var thread = root.querySelector('#bmThread') || document.getElementById('bmThread');
  if (thread) {
    var lastId = parseInt(thread.dataset.last || '0', 10) || 0;
    var pollUrl = thread.dataset.pollUrl;
    var meSender = thread.dataset.me || 'guest';
    var meGuest  = parseInt(thread.dataset.meGuest || '0', 10) || 0;

    function atBottom() {
      return (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 120);
    }
    function appendMsg(m) {
      if (m.id && document.querySelector('[data-mid="' + m.id + '"]')) return; // dedupe
      var mine = m.sender === meSender;
      // On a shared booking every guest message has sender='guest'. Alignment
      // still keys on that; authorship needs the guest id. Matches the server
      // render in includes/app/messages.php.
      var authored = mine && (!meGuest || !m.guest_id || m.guest_id === meGuest);
      var empty = thread.querySelector('.bm-empty');
      if (empty) empty.style.display = 'none';
      var el = document.createElement('div');
      el.className = 'bm-msg';
      if (m.id) el.setAttribute('data-mid', m.id);
      el.style.cssText = 'max-width:80%;padding:9px 12px;font-size:14px;line-height:1.5;' + (mine
        ? 'align-self:flex-end;background:var(--pa-teal-d);color:#fff;border-radius:12px 12px 2px 12px'
        : 'align-self:flex-start;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px 12px 12px 2px');
      // One conversation (thread=all): say which request a message is about, and
      // keep the day dividers right for a message that arrives live.
      var whole = thread.dataset.thread === 'all';
      if (whole) {
        var today = new Date(); var ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var seps = thread.querySelectorAll('.pa-daysep');
        if (!seps.length || seps[seps.length - 1].getAttribute('data-day') !== ymd) {
          var sep = document.createElement('span'); sep.className = 'pa-daysep'; sep.setAttribute('data-day', ymd); sep.textContent = 'Today';
          thread.appendChild(sep);
        }
        var labels = {}; try { labels = JSON.parse(thread.dataset.labels || '{}'); } catch (_) {}
        if (m.addon_id && labels[m.addon_id]) {
          var ab = document.createElement('div'); ab.className = 'bm-about'; ab.textContent = 'About: ' + labels[m.addon_id];
          el.appendChild(ab);
        }
      }
      el.appendChild(document.createTextNode(m.body));
      var meta = document.createElement('div');
      meta.style.cssText = 'font-size:11px;margin-top:4px;' + (mine ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)');
      var tl = whole ? String(m.time_label).split(', ').pop() : m.time_label;   // the day divider already says the date
      meta.textContent = (mine ? (authored ? 'You' : (m.sender_name || 'Guest')) : 'Concierge') + ' · ' + tl;
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
    chatPoll = poll;
    chatTimer = setInterval(poll, 5000);

    // Send: append the guest's own message immediately, then let the poll fill in replies.
    var chatForm = root.querySelector('form[data-chat]') || document.querySelector('form[data-chat]');
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
  }

  // ── Hold countdown: any [data-expires] (ms) element ticks down ──
  var cdTimer = null;
  function initCountdown(root) {
    if (cdTimer) { clearInterval(cdTimer); cdTimer = null; }
    var el = root.querySelector('[data-expires]');
    if (!el) return;
    var expires = parseInt(el.getAttribute('data-expires'), 10) || 0;
    function tick() {
      var diff = Math.floor((expires - Date.now()) / 1000);
      if (diff <= 0) { el.textContent = 'Expiring…'; clearInterval(cdTimer); return; }
      var h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), sec = diff % 60;
      el.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(sec).padStart(2, '0') + 's';
    }
    tick(); cdTimer = setInterval(tick, 1000);
  }

  // ── Styled confirm for serious guest actions (no browser pop-up) ──
  // <form data-pa-confirm="Question" data-pa-confirm-yes="Yes, cancel" data-pa-confirm-no="Keep my booking">
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-pa-confirm]') || form.dataset.paConfirmed) return;
    e.preventDefault();
    var back = document.createElement('div');
    back.className = 'pa-modal-backdrop';
    back.innerHTML = '<div class="pa-modal" role="alertdialog" aria-modal="true" aria-labelledby="paCfT">'
      + '<h3 class="pa-modal__title" id="paCfT" style="font-size:20px"></h3>'
      + '<p class="pa-modal__body"></p>'
      + '<div class="pa-modal__actions"><button type="button" class="pa-btn pa-btn--danger" data-yes></button>'
      + '<button type="button" class="pa-btn" data-no></button></div></div>';
    back.querySelector('h3').textContent = form.getAttribute('data-pa-confirm');
    back.querySelector('p').textContent = form.getAttribute('data-pa-confirm-body') || '';
    back.querySelector('[data-yes]').textContent = form.getAttribute('data-pa-confirm-yes') || 'Yes';
    back.querySelector('[data-no]').textContent = form.getAttribute('data-pa-confirm-no') || 'Go back';
    document.body.appendChild(back);
    function done() { back.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(ev) { if (ev.key === 'Escape') done(); }
    document.addEventListener('keydown', onKey);
    back.addEventListener('click', function (ev) { if (ev.target === back) done(); });
    back.querySelector('[data-no]').addEventListener('click', done);
    back.querySelector('[data-yes]').addEventListener('click', function () { form.dataset.paConfirmed = '1'; done(); form.submit(); });
    back.querySelector('[data-no]').focus();
  });

  // ── One conversation: reply about a request, and the "+" services menu ──
  function plusSheet(open) {
    var sh = document.getElementById('paPlus'); if (!sh) return;
    sh.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) { var x = sh.querySelector('.pa-sheet__x'); if (x) x.focus(); }
  }
  document.addEventListener('click', function (e) {
    var t = e.target; if (!t || !t.closest) return;
    var r = t.closest('[data-reply]');
    if (r) {
      var form = document.querySelector('form[data-chat]'); if (!form) return;
      var thread = document.getElementById('bmThread'), labels = {};
      try { labels = JSON.parse((thread && thread.dataset.labels) || '{}'); } catch (_) {}
      var id = r.getAttribute('data-reply');
      form.querySelector('[data-reply-input]').value = id;
      form.querySelector('[data-reply-label]').textContent = labels[id] || 'this request';
      form.querySelector('[data-reply-chip]').hidden = false;
      var ta = form.querySelector('textarea'); if (ta) ta.focus();
      return;
    }
    if (t.closest('[data-reply-clear]')) {
      var f = document.querySelector('form[data-chat]'); if (!f) return;
      f.querySelector('[data-reply-input]').value = '';
      f.querySelector('[data-reply-chip]').hidden = true;
      return;
    }
    var qk = t.closest('[data-quick]');
    if (qk) {   // empty conversation: a ready-made question fills the box
      var qf = document.querySelector('form[data-chat] textarea');
      if (qf) { qf.value = qk.getAttribute('data-quick'); qf.focus(); }
      return;
    }
    var cp = t.closest('[data-copy]');
    if (cp) {   // room key card: copy the Wi-Fi details
      var txt = cp.getAttribute('data-copy'), orig = cp.textContent;
      var ok = function () { cp.textContent = 'Copied ✓'; setTimeout(function () { cp.textContent = orig; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(ok, function () { toast(txt, 'ok'); });
      else toast(txt, 'ok');
      return;
    }
    if (t.closest('[data-plus-open]')) { plusSheet(true); return; }
    if (t.closest('[data-plus-close]')) { plusSheet(false); return; }
  });
  document.addEventListener('keydown', function (e) {
    var sh = document.getElementById('paPlus');
    if (e.key === 'Escape' && sh && !sh.hidden) plusSheet(false);
  });
  function initConversation(root) {
    var thread = root.querySelector('#bmThread[data-thread="all"]');
    if (thread) setTimeout(function () { window.scrollTo(0, document.body.scrollHeight); }, 30);   // newest at the bottom, like any chat
    var sh = root.querySelector('#paPlus[data-open-on-load]');
    if (sh) plusSheet(true);
  }

  function init(root) {
    root = root || document;
    bindForms(root);
    initChat(root);
    initCountdown(root);
    initConversation(root);
  }
  window.tsPortalInit = init;
  init(document);
})();
