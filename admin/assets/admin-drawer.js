/* ===== Side drawer — Tribal Sand admin (POS design A) =====
 * A row or link with data-drawer="<url>" opens that URL's DETAIL in a panel on
 * the right instead of leaving the list: the page answers `<url>&drawer=1` with a
 * fragment (head · body · foot). The list, its filters and its scroll stay put.
 *   • data-drawer-key="sale-12" → the address gets ?open=sale-12 while it is open,
 *     so a reload, or a POST that redirects back with it, reopens the same panel.
 *   • × button, Esc or a click on the dimmed page closes it.
 *   • No JS (or a failed fetch): the link simply opens the full page at <url>.
 * Forms inside post normally (full page); pages redirect back with ?open= to land
 * on the same panel. The panel lives inside .admin-content, so a shell page swap
 * removes it with the page.
 */
(function () {
  'use strict';

  var X = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
  var req = 0;

  function host() { return document.querySelector('.admin-content') || document.body; }
  function els() {
    var h = host();
    var scrim = h.querySelector(':scope > .adrawer-scrim');
    var panel = h.querySelector(':scope > .adrawer');
    if (!panel) {
      scrim = document.createElement('div'); scrim.className = 'adrawer-scrim'; scrim.hidden = true;
      panel = document.createElement('aside'); panel.className = 'adrawer'; panel.hidden = true;
      panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-label', 'Details');
      h.appendChild(scrim); h.appendChild(panel);
    }
    return { scrim: scrim, panel: panel };
  }

  function setOpenParam(key) {
    try {
      var u = new URL(location.href);
      if (key) u.searchParams.set('open', key); else u.searchParams.delete('open');
      var st = history.state || {};
      history.replaceState(st, '', u.pathname + u.search + u.hash);
    } catch (e) { /* non-fatal */ }
  }

  function wire(root) {
    if (window.tsAdminWire) window.tsAdminWire(root);                  // styled confirm, double-submit guard
    if (window.enhanceFilterSelects) window.enhanceFilterSelects(root); // styled dropdowns
    if (window.initDatepickers) { try { window.initDatepickers(root); } catch (e) {} }
    if (window.tsTipInit) window.tsTipInit(root);
    if (window.tsInlineFormWire) window.tsInlineFormWire(root);
  }

  function close() {
    var e = els();
    e.panel.hidden = true; e.scrim.hidden = true; e.panel.innerHTML = '';
    document.querySelectorAll('.is-drawer-on').forEach(function (r) { r.classList.remove('is-drawer-on'); });
    document.documentElement.classList.remove('has-adrawer');
    setOpenParam(null);
  }

  function open(url, key, row) {
    var e = els(), my = ++req;
    document.querySelectorAll('.is-drawer-on').forEach(function (r) { r.classList.remove('is-drawer-on'); });
    if (row) row.classList.add('is-drawer-on');
    e.panel.hidden = false; e.scrim.hidden = false;
    e.panel.classList.add('is-loading');
    if (!e.panel.innerHTML) e.panel.innerHTML = '<div class="adrawer__body"><div class="adrawer__wait"></div></div>';
    document.documentElement.classList.add('has-adrawer');
    var u;
    try { u = new URL(url, location.href); u.searchParams.set('drawer', '1'); u = u.pathname + u.search; }
    catch (err) { u = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'drawer=1'; }
    fetch(u, { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        if (my !== req) return;
        e.panel.classList.remove('is-loading');
        e.panel.innerHTML = '<button type="button" class="adrawer__x btn-icon" data-adrawer-close aria-label="Close" data-tip="Close">' + X + '</button>' + html;
        if (key) setOpenParam(key);
        // Forms in the panel come back to THIS list (filters kept) with the panel open.
        e.panel.querySelectorAll('input[data-drawer-ret]').forEach(function (i) { i.value = location.pathname + location.search; });
        wire(e.panel);
        // A page's own form script (stock toggles, hints…) hooks in here.
        try { document.dispatchEvent(new CustomEvent('adrawer:loaded', { detail: { panel: e.panel, key: key } })); } catch (err) {}
        var f = e.panel.querySelector('[autofocus]'); if (f) f.focus();
      })
      .catch(function () { if (my === req) location.href = url; });
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-adrawer-close]') || t.classList.contains('adrawer-scrim')) { ev.preventDefault(); close(); return; }
    var trg = t.closest('[data-drawer]');
    if (!trg) return;
    // Real controls inside a drawer row act on their own (except the row's own link).
    var ctl = t.closest('a, button, input, select, textarea, label, form');
    if (ctl && ctl !== trg && !ctl.hasAttribute('data-drawer')) return;
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button === 1) return;   // new tab: let the link go
    ev.preventDefault();
    ev.stopPropagation();
    var row = trg.closest('tr') || trg;
    open(trg.getAttribute('data-drawer'), trg.getAttribute('data-drawer-key'), row);
  }, true);

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var p = document.querySelector('.adrawer:not([hidden])');
    if (!p) return;
    if (document.querySelector('.adm-confirm-back, .eselect.is-open, .dp-pop:not([hidden])')) return;
    close();
  });

  // ?open=<key> on load (or after a shell swap): reopen that row's panel.
  function autoOpen() {
    var key;
    try { key = new URL(location.href).searchParams.get('open'); } catch (e) { key = null; }
    if (!key) return;
    var trg = document.querySelector('[data-drawer-key="' + key.replace(/"/g, '') + '"]');
    if (trg) open(trg.getAttribute('data-drawer'), key, trg.closest('tr') || trg);
  }
  function start() {
    autoOpen();
    var c = document.querySelector('.admin-content');
    if (c && 'MutationObserver' in window) {
      var last = location.pathname;
      new MutationObserver(function () {
        if (location.pathname !== last) { last = location.pathname; setTimeout(autoOpen, 30); }
      }).observe(c, { childList: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
  window.tsDrawerOpen = open;
  window.tsDrawerClose = close;
})();
