/* ===== Reusable data-table AJAX enhancement — Tribal Sand admin =====
 * Progressive enhancement for the server-side paginated list pages built with
 * includes/pagination.php + includes/admin-pagination.php.
 *
 * Baseline (no JS): the toolbar is a GET <form> and the pager is plain links —
 * everything works with full-page reloads.
 *
 * Enhanced: we intercept search typing (debounced), the per-page dropdown, and
 * pager clicks, fetch the same URL with &ajax=1 (which returns ONLY the inner of
 * .dt-body), swap it in, and update the address bar. No full reload.
 */
(function () {
  'use strict';

  var DEBOUNCE = 350;

  // `url` + ajax=1 as a real query parameter. Appending "&ajax=1" to a string that
  // carries a #hash put it AFTER the hash, the server never saw it and answered
  // with the whole page, which then landed inside the list (Reservations → New
  // booking, whose button is a #new-booking link).
  function withAjax(url) {
    try {
      var u = new URL(url, location.href);
      u.hash = '';
      u.searchParams.set('ajax', '1');
      return u.pathname + u.search;
    } catch (e) {
      var clean = String(url).split('#')[0];
      return clean + (clean.indexOf('?') >= 0 ? '&' : '?') + 'ajax=1';
    }
  }
  // The query string the lists on this page last showed: a history step that only
  // changes the #hash (an in-page link) must not reload them.
  var shownSearch = location.search;

  // Re-apply the shared admin enhancements (styled confirm / "Working…" wiring
  // from _layout_end.php, and enhanced dropdowns) to freshly-swapped content, so
  // AJAX-loaded rows behave exactly like server-rendered ones.
  function reenhance(root) {
    if (typeof window.tsAdminWire === 'function') window.tsAdminWire(root);
    if (typeof window.enhanceFilterSelects === 'function') window.enhanceFilterSelects(root);
    if (typeof window.tsDtDrag === 'function') window.tsDtDrag(root);   // re-bind drag on reorderable lists
    if (typeof window.tsTipInit === 'function') window.tsTipInit(root); // migrate title= on swapped rows
  }

  function enhance(root) {
    if (root.dataset.dtEnhanced) return;
    root.dataset.dtEnhanced = '1';
    shownSearch = location.search;   // a shell swap brought a new page's lists

    var form = root.querySelector('[data-dt-toolbar]');
    var body = root.querySelector('[data-dt-body]');
    if (!body) return; // nothing to swap into

    // Build the visible URL from the toolbar form's current fields.
    function toolbarUrl() {
      if (!form) return location.href;
      var params = new URLSearchParams();
      new FormData(form).forEach(function (v, k) {
        if (k === 'ajax') return;
        params.append(k, v);
      });
      params.set('page', '1'); // a new search / page-size always starts at page 1
      var action = form.getAttribute('action') || location.pathname;
      var qs = params.toString();
      return action + (qs ? '?' + qs : '');
    }

    var controller = null;

    // Load `url` via AJAX and swap the body. `push` true => history entry,
    // false => replace (used for debounced typing so history isn't spammed).
    function load(url, push) {
      var ajaxUrl = withAjax(url);

      if (controller) controller.abort();
      controller = ('AbortController' in window) ? new AbortController() : null;

      body.classList.add('is-loading');

      fetch(ajaxUrl, {
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function (html) {
          body.innerHTML = html;
          body.classList.remove('is-loading');
          reenhance(body);
          try {
            var st = window.tsShellState ? window.tsShellState({ dt: 1 }) : { dt: 1 };
            if (push) history.pushState(st, '', url);
            else history.replaceState(st, '', url);
            shownSearch = location.search;
          } catch (e) { /* file:// or blocked — non-fatal */ }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return; // superseded by a newer request
          body.classList.remove('is-loading');
          window.location.href = url; // hard fallback keeps the user un-stuck
        });
    }

    // --- Toolbar: search input (debounced) + per-page + submit ---
    if (form) {
      var searchInput = form.querySelector('input[name="q"]');
      var perSelect   = form.querySelector('select[name="per"]');
      var timer = null;

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (timer) { clearTimeout(timer); timer = null; }
        load(toolbarUrl(), true);
      });

      if (searchInput) {
        searchInput.addEventListener('input', function () {
          if (timer) clearTimeout(timer);
          timer = setTimeout(function () { load(toolbarUrl(), false); }, DEBOUNCE);
        });
      }

      if (perSelect) {
        // The enhanced dropdown (admin-select.js) fires a real 'change' on the
        // native <select>; catch it here for the no-reload path.
        perSelect.addEventListener('change', function () { load(toolbarUrl(), true); });
      }
    }

    // --- Pager: delegate clicks on links inside the (swappable) body ---
    body.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('[data-dt-pager] a.dt-page') : null;
      if (!a || !body.contains(a)) return;
      e.preventDefault();
      load(a.getAttribute('href'), true);
    });
  }

  // Back/forward: re-fetch the URL we're now on and sync the toolbar fields.
  window.addEventListener('popstate', function (e) {
    // Back/forward onto ANOTHER page is the shell's job (admin-nav.js) — re-fetching
    // this table would only race the page swap.
    if (window.tsCrossPagePop && window.tsCrossPagePop(e)) return;
    if (location.search === shownSearch) return;   // only the #hash moved (an in-page link)
    shownSearch = location.search;
    document.querySelectorAll('[data-dt][data-dt-enhanced]').forEach(function (root) {
      var body = root.querySelector('[data-dt-body]');
      var form = root.querySelector('[data-dt-toolbar]');
      if (!body) return;
      var params = new URLSearchParams(location.search);
      if (form) {
        var qi = form.querySelector('input[name="q"]');
        var ps = form.querySelector('select[name="per"]');
        if (qi) qi.value = params.get('q') || '';
        // Set the value only — do NOT dispatch 'change' here, or our own change
        // listener would fire load()+pushState and fight this popstate handler.
        if (ps && params.get('per')) ps.value = params.get('per');
      }
      body.classList.add('is-loading');
      fetch(withAjax(location.href), { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) { body.innerHTML = html; body.classList.remove('is-loading'); })
        .catch(function () { window.location.reload(); });
    });
  });

  // Whole-row links: a <tr data-href> navigates on click. Delegated at the
  // document level so it keeps working after an AJAX body swap. Real controls
  // inside the row (links, buttons, form fields) still act normally.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('a, button, input, select, textarea, label')) return;
    var row = t.closest('tr[data-href]');
    if (!row) return;
    var href = row.getAttribute('data-href');
    // No-reload page swap when the shell can load it; a real navigation otherwise.
    if (window.tsShellGo && window.tsShellGo(href)) return;
    window.location.href = href;
  });

  function init(root) {
    (root || document).querySelectorAll('[data-dt]').forEach(enhance);
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', function () { init(); });

  window.enhanceDataTables = init;
})();
