/* ===== Admin live messaging — append on send + poll for incoming (no refresh) =====
 * Progressive enhancement over the plain PRG reply form on admin/messages.php and
 * the booking workspace Messages tab.
 *
 * Re-runnable: exposes window.tsChatInit() so it re-binds after the workspace
 * shell swaps in a thread panel via AJAX. The poll loop is a single global
 * interval that re-resolves the CURRENT #amThread each tick, so switching threads
 * (or swapping the whole panel) just works. lastId lives on the element's dataset
 * so every thread tracks its own high-water mark.
 */
(function () {
  'use strict';

  var intervalStarted = false;

  // Team chat (admin-internal-chat.js) uses the same ids — only the guest inbox is ours.
  function thread() { var el = document.getElementById('amThread'); return el && !/internal-messages/.test(el.dataset.pollUrl || '') ? el : null; }

  function lastId(el) { return parseInt(el.dataset.last || '0', 10) || 0; }

  function atBottom(el) {
    // The thread is its own scroll container now; fall back to window for safety.
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
    var mine = m.sender === 'admin';
    var empty = el.querySelector('.am-empty');
    if (empty) empty.style.display = 'none';
    window.tsChatDay(el);
    var bubble = document.createElement('div');
    bubble.className = 'am-msg ' + (mine ? 'am-msg--staff' : 'am-msg--guest');
    if (m.id) bubble.setAttribute('data-mid', m.id);
    bubble.appendChild(document.createTextNode(m.body));
    var meta = document.createElement('div');
    meta.className = 'am-msg__meta';
    meta.textContent = (mine ? 'Staff' : (m.sender_name || 'Guest')) + ' · ' + m.time_label;
    bubble.appendChild(meta);
    el.appendChild(bubble);
    if (m.id && m.id > lastId(el)) el.dataset.last = String(m.id);
  }

  var polling = false;
  function poll() {
    var el = thread();
    if (!el || polling || document.hidden) return;
    polling = true;
    var url = el.dataset.pollUrl + '?hold=' + encodeURIComponent(el.dataset.hold) +
      '&thread=' + encodeURIComponent(el.dataset.thread) + '&after=' + lastId(el);
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok && data.messages && data.messages.length) {
          var stick = atBottom(el);
          data.messages.forEach(function (m) { appendMsg(el, m); });
          if (stick) scrollToBottom(el);
        }
      })
      .catch(function () { /* transient — retry next tick */ })
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

  window.tsChatInit = init;

  // A live message lands today: make sure the thread's last day separator says so
  // (server-rendered threads group bubbles under .am-day labels). Shared with
  // admin-internal-chat.js.
  window.tsChatDay = function (el) {
    var days = el.querySelectorAll('.am-day');
    var last = days.length ? days[days.length - 1] : null;
    if (last && last.textContent === 'Today') return;
    var d = document.createElement('div');
    d.className = 'am-day';
    d.textContent = 'Today';
    el.appendChild(d);
  };

  // ── Composer behaviour for every chat (.mx-composer): bound once, delegated, so
  // it survives no-reload page swaps. Enter sends, Shift+Enter is a new line, the
  // box grows with its text, quick-reply chips drop their text in.
  if (!window.__mxComposerBound) {
    window.__mxComposerBound = true;
    var grow = function (ta) { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 160) + 'px'; };
    document.addEventListener('keydown', function (e) {
      var ta = e.target;
      if (!ta || ta.tagName !== 'TEXTAREA' || !ta.closest('.mx-composer')) return;
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        if (ta.value.trim() === '') return;
        var f = ta.form;
        if (f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event('submit', { cancelable: true }));
        setTimeout(function () { grow(ta); }, 0);
      }
    });
    document.addEventListener('input', function (e) {
      var ta = e.target;
      if (ta && ta.tagName === 'TEXTAREA' && ta.closest('.mx-composer')) grow(ta);
      // Team chat's "Find a channel" box filters the list in place.
      if (ta && ta.hasAttribute && ta.hasAttribute('data-mx-filter')) {
        var q = ta.value.trim().toLowerCase(), pane = ta.closest('.mx-pane');
        pane.querySelectorAll('.mx-item[data-mx-name]').forEach(function (it) {
          it.hidden = q !== '' && it.getAttribute('data-mx-name').indexOf(q) < 0;
        });
      }
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-mx-quick]');
      if (!b) return;
      var f = b.closest('form'), ta = f && f.querySelector('textarea');
      if (!ta) return;
      var t = b.getAttribute('data-mx-quick');
      ta.value = ta.value.trim() ? ta.value.replace(/\s*$/, ' ') + t : t;
      grow(ta);
      ta.focus();
    });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
