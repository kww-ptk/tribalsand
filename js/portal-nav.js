/*
 * Guest page — switch tabs without reloading the page (Oct 2026).
 *
 * A full page load per tab made the browser show its loading spinner every time
 * a guest tapped Home / My trip / Extras / Messages. Links to another view of the
 * SAME booking page are fetched and only .pa-wrap (the content) and .pa-nav (the
 * tabs) are swapped; the address bar and Back button work as usual.
 *
 * After a swap the new content's own <script>s are run again, then
 * window.tsPortalInit (js/booking-manage.js) re-attaches forms, chat polling and
 * the hold countdown, and window.enhanceSelects styles new dropdowns.
 *
 * Left to the browser: the check-in view (its wizard scripts expect a fresh
 * page), links with target/download/data-no-swap, other pages, and any fetch that
 * fails — then the link simply loads normally.
 */
(function () {
  'use strict';
  if (window.__paNav) return;
  window.__paNav = true;
  if (!document.querySelector('.pa-wrap') || !window.fetch || !window.DOMParser || !history.pushState) return;

  function isPortal(url) {
    return url.origin === location.origin && /^\/booking(\.php)?$/.test(url.pathname);
  }
  function swappable(a) {
    if (!a || a.target || a.hasAttribute('download') || a.hasAttribute('data-no-swap')) return null;
    var url;
    try { url = new URL(a.getAttribute('href'), location.href); } catch (e) { return null; }
    if (!isPortal(url)) return null;
    if (url.searchParams.get('view') === 'checkin') return null;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return null;   // in-page anchor
    return url;
  }

  function runScripts(root) {
    root.querySelectorAll('script').forEach(function (old) {
      var type = (old.getAttribute('type') || '').toLowerCase();
      if (type && type !== 'text/javascript' && type !== 'module') return;   // data blocks (application/json) stay as they are
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) {
        var at = old.attributes[i];
        if (at.name !== 'defer' && at.name !== 'async') s.setAttribute(at.name, at.value);
      }
      if (old.src) s.async = false; else s.text = old.textContent;
      old.parentNode.replaceChild(s, old);
    });
  }

  var busy = null;
  function go(url, push) {
    if (busy) busy.abort();
    var ctl = window.AbortController ? new AbortController() : null;
    busy = ctl;
    document.documentElement.classList.add('pa-swapping');
    return fetch(url.href, { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        // A redirect to somewhere that isn't the booking page (expired link…) → let it load.
        if (res.redirected && !isPortal(new URL(res.url))) throw new Error('redirected');
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nextWrap = doc.querySelector('.pa-wrap');
        var curWrap = document.querySelector('.pa-wrap');
        if (!nextWrap || !curWrap) throw new Error('no content');
        // The check-in gate can answer any view with the wizard — that needs a real load.
        if (nextWrap.querySelector('script[src*="checkin-wizard"], #ciForm')) throw new Error('checkin');
        var wrap = document.importNode(nextWrap, true);
        curWrap.parentNode.replaceChild(wrap, curWrap);
        var nextNav = doc.querySelector('.pa-nav'), curNav = document.querySelector('.pa-nav');
        if (nextNav && curNav) curNav.parentNode.replaceChild(document.importNode(nextNav, true), curNav);
        if (doc.title) document.title = doc.title;
        if (push) history.pushState({ pa: 1 }, '', url.href);
        window.scrollTo(0, 0);
        runScripts(wrap);
        if (window.tsPortalInit) window.tsPortalInit(wrap);
        if (window.enhanceSelects) { try { window.enhanceSelects(); } catch (e) {} }
        var h = wrap.querySelector('h1, h2, .pa-h2');
        if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        location.href = url.href;   // fall back to a normal page load
      })
      .then(function () {
        if (busy === ctl) { busy = null; document.documentElement.classList.remove('pa-swapping'); }
      });
  }

  // Bubble phase on window, so page scripts that handle their own clicks run first.
  window.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    var url = swappable(a);
    if (!url) return;
    e.preventDefault();
    if (url.href === location.href) { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    go(url, true);
  });

  history.replaceState({ pa: 1 }, '', location.href);
  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.pa) go(new URL(location.href), false);
  });
})();
