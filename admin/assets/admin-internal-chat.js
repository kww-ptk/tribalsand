/* ===== Admin internal team chat — append on send + poll (no refresh) =====
 * Sibling of admin-chat.js for the guest inbox, but for team ↔ team messages:
 * "mine" comes from the payload (m.mine), and every bubble shows the sender's
 * name. Single global poll loop that re-resolves the current #amThread each tick.
 */
(function () {
  'use strict';

  var intervalStarted = false;
  function thread() { return document.getElementById('amThread'); }
  function lastId(el) { return parseInt(el.dataset.last || '0', 10) || 0; }

  function atBottom(el) {
    if (el.scrollHeight > el.clientHeight + 4) {
      return (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 40);
    }
    return (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 120);
  }
  function scrollToBottom(el) {
    if (el.scrollHeight > el.clientHeight + 4) el.scrollTop = el.scrollHeight;
    else window.scrollTo(0, document.body.scrollHeight);
  }

  function appendMsg(el, m) {
    if (m.id && el.querySelector('[data-mid="' + m.id + '"]')) return; // dedupe
    var empty = el.querySelector('.am-empty');
    if (empty) empty.style.display = 'none';
    var bubble = document.createElement('div');
    bubble.className = 'am-msg ' + (m.mine ? 'am-msg--staff' : 'am-msg--guest');
    if (m.id) bubble.setAttribute('data-mid', m.id);
    bubble.appendChild(document.createTextNode(m.body));
    var meta = document.createElement('div');
    meta.className = 'am-msg__meta';
    meta.textContent = (m.sender_name || 'Team') + ' · ' + m.time_label;
    bubble.appendChild(meta);
    el.appendChild(bubble);
    if (m.id && m.id > lastId(el)) el.dataset.last = String(m.id);
  }

  var polling = false;
  function poll() {
    var el = thread();
    if (!el || polling || document.hidden) return;
    polling = true;
    var url = el.dataset.pollUrl + '?channel=' + encodeURIComponent(el.dataset.channel) +
      '&after=' + lastId(el);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok && data.messages && data.messages.length) {
          var stick = atBottom(el);
          data.messages.forEach(function (m) { appendMsg(el, m); });
          if (stick) scrollToBottom(el);
        }
      })
      .catch(function () { /* transient */ })
      .then(function () { polling = false; });
  }

  function bindForm(form) {
    if (form.dataset.chatWired) return;
    form.dataset.chatWired = '1';
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var el = thread();
      var ta = form.querySelector('textarea[name=body]');
      var btn = form.querySelector('button[type=submit]');
      var status = form.querySelector('.am-status');
      var body = (ta && ta.value || '').trim();
      if (!body || !el) return;
      if (btn) btn.disabled = true;
      if (status) { status.style.color = ''; status.textContent = 'Sending…'; }
      fetch(el.dataset.pollUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify(Object.fromEntries(new FormData(form).entries()))
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.ok) {
            if (data.message) appendMsg(el, data.message);
            if (ta) ta.value = '';
            if (status) status.textContent = '';
            scrollToBottom(el);
          } else if (status) {
            status.style.color = '#b91c1c';
            status.textContent = (data && data.error) || 'Could not send. Please try again.';
          }
        })
        .catch(function () {
          if (status) { status.style.color = '#b91c1c'; status.textContent = 'Network error. Please try again.'; }
        })
        .then(function () { if (btn) btn.disabled = false; });
    });
  }

  function init() {
    var el = thread();
    var form = document.getElementById('amForm');
    if (!el || !form) return;
    bindForm(form);
    scrollToBottom(el);
    if (!intervalStarted) {
      intervalStarted = true;
      setInterval(poll, 5000);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    }
  }

  window.tsInternalChatInit = init;
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
