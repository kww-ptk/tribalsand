/* ===== Live submission-notes thread — append on send + poll for incoming =====
 * Progressive enhancement over the plain reply form on the admin submission view
 * and the agent request view. Mirrors admin-chat.js: a single poll loop re-reads
 * #stThread each tick, dedupes by data-nid, and sticks to the bottom. Sending goes
 * through /api/submission-thread.php (JSON) and appends the returned note instantly
 * — no page refresh. Works with JS off via the underlying PRG form.
 *
 * On the admin form, a reply that also emails the guest (the send_email checkbox)
 * is left to the normal PRG submit so the email path is untouched.
 */
(function () {
  'use strict';

  function thread() { return document.getElementById('stThread'); }
  function lastId(el) { return parseInt(el.dataset.last || '0', 10) || 0; }

  function atBottom(el) {
    if (el.scrollHeight > el.clientHeight + 4) return (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 40);
    return true;
  }
  function toBottom(el) { if (el.scrollHeight > el.clientHeight + 4) el.scrollTop = el.scrollHeight; }

  function append(el, n) {
    if (n.id && el.querySelector('[data-nid="' + n.id + '"]')) return; // dedupe
    var empty = el.querySelector('.st-empty'); if (empty) empty.style.display = 'none';
    var b = document.createElement('div');
    b.className = 'stm ' + (n.mine ? 'stm--me' : 'stm--them');
    if (n.id) b.setAttribute('data-nid', n.id);
    var h = document.createElement('div');
    h.className = 'stm__head';
    var s = document.createElement('strong'); s.textContent = n.author || '';
    h.appendChild(s); h.appendChild(document.createTextNode(' · ' + (n.time_label || '')));
    var body = document.createElement('div');
    body.className = 'stm__body'; body.textContent = n.body || '';
    b.appendChild(h); b.appendChild(body);
    el.appendChild(b);
    if (n.id && n.id > lastId(el)) el.dataset.last = String(n.id);
  }

  var polling = false;
  function poll() {
    var el = thread();
    if (!el || polling || document.hidden) return;
    polling = true;
    var url = el.dataset.pollUrl + '?id=' + encodeURIComponent(el.dataset.id) + '&after=' + lastId(el);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok && d.notes && d.notes.length) {
          var stick = atBottom(el);
          d.notes.forEach(function (n) { append(el, n); });
          if (stick) toBottom(el);
        }
      })
      .catch(function () { /* transient — retry next tick */ })
      .then(function () { polling = false; });
  }

  function bind(form) {
    if (!form || form.dataset.stWired) return;
    form.dataset.stWired = '1';
    form.addEventListener('submit', function (e) {
      // A staff reply that also emails the guest keeps the normal PRG submit.
      var emailCb = form.querySelector('[name=send_email]');
      if (emailCb && emailCb.checked) return;

      var el = thread();
      var ta = form.querySelector('[name=body]');
      var body = (ta && ta.value || '').trim();
      if (!el || !ta) return;
      if (!body) { e.preventDefault(); return; }
      e.preventDefault();

      var btn = form.querySelector('button[type=submit]');
      var status = form.querySelector('.st-status');
      var csrf = form.querySelector('[name=csrf_token]');
      var kindEl = form.querySelector('[name=kind]:checked') || form.querySelector('[name=kind]');
      var payload = { id: el.dataset.id, body: body, csrf_token: csrf ? csrf.value : '' };
      if (kindEl) payload.kind = kindEl.value;

      if (btn) btn.disabled = true;
      if (status) { status.style.color = ''; status.textContent = 'Sending…'; }
      fetch(el.dataset.pollUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            if (d.note) append(el, d.note);
            ta.value = '';
            if (status) status.textContent = '';
            toBottom(el);
          } else if (status) {
            status.style.color = '#b91c1c';
            status.textContent = (d && d.error) || 'Could not send. Please try again.';
          }
        })
        .catch(function () { if (status) { status.style.color = '#b91c1c'; status.textContent = 'Network error. Please try again.'; } })
        .then(function () { if (btn) btn.disabled = false; });
    });
  }

  var started = false;
  function init() {
    var el = thread();
    if (!el) return;
    bind(document.getElementById('stForm'));
    toBottom(el);
    if (!started) {
      started = true;
      setInterval(poll, 5000);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    }
  }

  window.tsSubmissionThreadInit = init;
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
