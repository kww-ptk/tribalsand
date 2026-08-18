/* ===== No-flicker admin shell — Tribal Sand admin (Phase 1 #1 + Phase 5 #18) =====
 *
 * Two cooperating layers, split by *swap region* so they never fight:
 *
 *  1. SHELL (#18) — cross-page navigation. Intercepts sidebar links, fetches the
 *     target with `?shell=1` (the server returns ONLY that page's `.admin-content`
 *     via _layout.php/_layout_end.php), shows a page skeleton, and swaps
 *     `.admin-content` — so the sidebar/topbar never repaint. history.pushState;
 *     cross-page back/forward re-swaps.
 *
 *  2. WORKSPACE (#1) — the booking workspace (admin/booking.php) tabs + in-panel
 *     action forms. Intercepts inside `[data-ws]`, fetches `?ajax=1` (server
 *     returns ONLY the panel inner), swaps `[data-ws-panel]`.
 *
 * `?shell=1` (whole content) and `?ajax=1` (inner dt-body / ws-panel) are
 * different params with different triggers — one swap owner per region. The list
 * pages' `admin-table.js` keeps owning `.dt-body` on `?ajax=1`.
 *
 * Progressive enhancement: with no JS every link/form does a full navigation and
 * the server renders the identical page. Everything here is purely additive.
 */
(function () {
  'use strict';

  // ── Shared: re-apply enhancement layers to freshly-swapped content ──
  function reenhance(root) {
    if (typeof window.tsAdminWire === 'function') window.tsAdminWire(root);
    if (typeof window.enhanceFilterSelects === 'function') window.enhanceFilterSelects(root);
    if (typeof window.tsChatInit === 'function') window.tsChatInit(root);
    if (typeof window.tsTipInit === 'function') window.tsTipInit(root);
    if (typeof window.tsDtDrag === 'function') window.tsDtDrag(root);
    if (typeof window.enhanceDataTables === 'function') window.enhanceDataTables(root);
    if (typeof window.initDatepickers === 'function') window.initDatepickers(root);
  }

  // innerHTML doesn't execute injected <script>s — re-create them so a swapped
  // page's own inline scripts (drag reorder, filter auto-submit, …) run again.
  function runScripts(container) {
    container.querySelectorAll('script').forEach(function (old) {
      if (old.src) return; // no external <script src> is emitted inside content
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
      s.textContent = old.textContent;
      old.parentNode.replaceChild(s, old);
    });
  }

  // ── Shared: skeleton templates (Phase 1 #2) ──
  function bars(n, cls) {
    var s = '';
    for (var i = 0; i < n; i++) s += '<span class="skeleton sk-bar ' + cls + '"></span>';
    return s;
  }
  function skeletonTable() {
    var rows = '';
    for (var i = 0; i < 5; i++) {
      rows += '<div class="sk-row">' +
        '<span class="skeleton sk-bar sk-bar--grow"></span>' +
        '<span class="skeleton sk-bar sk-bar--md"></span>' +
        '<span class="skeleton sk-pill"></span>' +
        '</div>';
    }
    return '<div class="sk-card">' + rows + '</div>';
  }
  function skeleton(kind) {
    if (kind === 'chat') {
      return '<div class="sk-card"><div class="sk-chat">' +
        '<span class="skeleton sk-bubble sk-bubble--them"></span>' +
        '<span class="skeleton sk-bubble sk-bubble--me"></span>' +
        '<span class="skeleton sk-bubble sk-bubble--them"></span>' +
        '<span class="skeleton sk-bubble sk-bubble--me"></span>' +
        '</div></div>';
    }
    if (kind === 'detail') {
      return '<div class="sk-card"><div class="sk-detail">' +
        '<div>' + bars(1, 'sk-bar--sm') + bars(1, 'sk-bar--lg') + '</div>' +
        '<div>' + bars(1, 'sk-bar--sm') + bars(1, 'sk-bar--lg') + '</div>' +
        '<div>' + bars(1, 'sk-bar--sm') + bars(1, 'sk-bar--md') + '</div>' +
        '<div>' + bars(1, 'sk-bar--sm') + bars(1, 'sk-bar--md') + '</div>' +
        '</div></div>';
    }
    return skeletonTable();
  }
  function pageSkeleton() {
    return '<div class="sk-pagehdr"><span class="skeleton sk-bar sk-bar--md"></span></div>' + skeletonTable();
  }

  function toastFlash(doc) {
    if (typeof window.tsToast !== 'function') return;
    var al = doc.querySelector('.alert.is-flash');
    if (!al) return;
    var text = (al.textContent || '').trim();
    if (text) window.tsToast(text, al.classList.contains('alert--error') ? 'err' : 'ok');
  }

  // Where cross-page navigation is currently parked (drives the popstate split:
  // same-path pops belong to the workspace/data-table layers; cross-path pops to
  // the shell).
  var shellPath = location.pathname;

  // ═══════════════════════════ WORKSPACE (booking.php) ═══════════════════════
  var wsEl = null, wsPanel = null;

  function setActiveTab(tab) {
    if (!wsEl) return;
    wsEl.querySelectorAll('[data-ws-tab]').forEach(function (a) {
      a.classList.toggle('is-active', a.getAttribute('data-tab') === tab);
    });
  }
  function showWsSkeleton() {
    if (!wsPanel) return;
    var kind = wsPanel.getAttribute('data-skeleton') || 'table';
    wsPanel.classList.remove('is-loading');
    wsPanel.innerHTML = skeleton(kind);
  }
  function applyDoc(doc) {
    if (!wsPanel) return;
    var np = doc.querySelector('[data-ws-panel]');
    if (np) {
      wsPanel.innerHTML = np.innerHTML;
      wsPanel.setAttribute('data-skeleton', np.getAttribute('data-skeleton') || 'table');
    }
    var nt = doc.querySelector('[data-ws-tabs]');
    var ct = wsEl.querySelector('[data-ws-tabs]');
    if (nt && ct) ct.innerHTML = nt.innerHTML;
    wsPanel.classList.remove('is-loading');
    runScripts(wsPanel);      // the panel's own inline scripts (check-in interactions…)
    reenhance(wsPanel);
  }
  function wsLoadTab(url, push) {
    showWsSkeleton();
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    fetch(url + sep + 'ajax=1', { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        wsPanel.innerHTML = html;
        wsPanel.classList.remove('is-loading');
        runScripts(wsPanel);      // the panel's own inline scripts (check-in interactions…)
        reenhance(wsPanel);
        try { if (push) history.pushState({ ws: 1 }, '', url); } catch (e) { /* non-fatal */ }
      })
      .catch(function () { window.location.href = url; });
  }
  function wsClick(e) {
    if (!e.target.closest) return;
    var tab = e.target.closest('[data-ws-tab]');
    if (tab && wsEl.contains(tab)) {
      e.preventDefault();
      wsPanel.setAttribute('data-skeleton', tab.getAttribute('data-skeleton') || 'table');
      setActiveTab(tab.getAttribute('data-tab'));
      wsLoadTab(tab.getAttribute('href'), true);
      return;
    }
    var load = e.target.closest('[data-ws-load]');
    if (load && wsPanel.contains(load)) {
      e.preventDefault();
      if (load.hasAttribute('data-skeleton')) wsPanel.setAttribute('data-skeleton', load.getAttribute('data-skeleton'));
      wsLoadTab(load.getAttribute('href'), true);
      return;
    }
    if (e.target.closest('a, button, input, select, textarea, label')) return;
    var row = e.target.closest('tr.dt-rowlink');
    if (row && wsPanel.contains(row)) {
      var link = row.querySelector('[data-ws-load]');
      if (link) { e.preventDefault(); wsLoadTab(link.getAttribute('href'), true); }
    }
  }
  function wsSubmit(e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.id === 'amForm' || form.hasAttribute('data-ws-skip')) return;
    if (!wsPanel.contains(form)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

    e.preventDefault();
    var action = form.getAttribute('action') || location.href;
    var fd = new FormData(form);
    var submitter = e.submitter;
    if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value);

    wsPanel.classList.add('is-loading');
    // The rejection handler is the SECOND argument to this .then, not a trailing
    // .catch, and that placement is the whole point: it can only ever see fetch()
    // itself failing — i.e. the request never completed, nothing was written, so
    // re-submitting natively is safe and is the only way to finish the action.
    //
    // A trailing .catch would ALSO swallow anything thrown while rendering the
    // response, and by then the server has already committed the POST. That is
    // what doubled every "+ Add adult": applyDoc() ends in reenhance(), which
    // calls seven optional enhancers, and one of them throwing re-POSTed a write
    // that had already landed — two checkin_guests rows, two audit entries.
    fetch(action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(
        function (r) { return r.text().then(function (text) { return { url: r.url, text: text }; }); },
        function () { wsPanel.classList.remove('is-loading'); form.submit(); return null; }
      )
      .then(function (res) {
        if (!res) return;                 // the request failed; the fallback above already ran
        try {
          var doc = new DOMParser().parseFromString(res.text, 'text/html');
          if (!doc.querySelector('[data-ws-panel]')) { window.location.href = res.url || location.href; return; }
          applyDoc(doc);
          toastFlash(doc);
          try { if (res.url) history.replaceState({ ws: 1 }, '', res.url); } catch (e) { /* non-fatal */ }
        } catch (err) {
          // Post-commit: never re-submit. Fall back to a full navigation, which
          // shows the guest the real server state instead of repeating the write.
          wsPanel.classList.remove('is-loading');
          window.location.href = res.url || location.href;
        }
      });
  }

  // Bind the workspace once per `[data-ws]` element (survives shell swaps).
  function initWorkspace(root) {
    var ws = (root || document).querySelector('[data-ws]');
    if (!ws) return;
    wsEl = ws;
    wsPanel = ws.querySelector('[data-ws-panel]');
    if (ws.dataset.wsBound) return;
    ws.dataset.wsBound = '1';
    if (!wsPanel) return;
    ws.addEventListener('click', wsClick);
    ws.addEventListener('submit', wsSubmit);
    // Stamp this entry so a BACK to it re-swaps the panel instead of leaving a stale tab.
    try { history.replaceState({ ws: 1 }, '', location.href); } catch (e) { /* non-fatal */ }
  }

  // ═══════════════════════════ SHELL (cross-page nav, #18) ═══════════════════
  var content = document.querySelector('.admin-content');

  function setActiveNav(url) {
    var path;
    try { path = new URL(url, location.href).pathname; } catch (e) { path = url; }
    document.querySelectorAll('.sidebar__link').forEach(function (a) {
      var ap;
      try { ap = new URL(a.getAttribute('href'), location.href).pathname; } catch (e) { ap = ''; }
      var on = ap === path;
      a.classList.toggle('is-active', on);
      if (on) { var g = a.closest('.navgroup'); if (g && !g.open) g.open = true; }
    });
  }

  function shellNavigate(url, push) {
    if (!content) { window.location.href = url; return; }
    content.classList.add('is-swapping');
    content.innerHTML = pageSkeleton();
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    fetch(url + sep + 'shell=1', { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var frag = doc.querySelector('[data-shell-frag]');
        if (!frag) { window.location.href = url; return; }   // login redirect / non-shell response
        content.innerHTML = frag.innerHTML;
        content.classList.remove('is-swapping');
        var title = frag.getAttribute('data-title');
        if (title) document.title = title;
        try { if (push) history.pushState({ shell: 1 }, '', url); } catch (e) { /* non-fatal */ }
        try { shellPath = new URL(url, location.href).pathname; } catch (e) { shellPath = location.pathname; }
        setActiveNav(url);
        runScripts(content);      // page's own inline scripts
        reenhance(content);       // shared enhancers (tips, selects, tables, drag…)
        initWorkspace(content);   // in case we swapped into the booking workspace
        window.scrollTo(0, 0);
      })
      .catch(function () { window.location.href = url; });
  }

  // ── Stage 2 (#18): generalised in-content action forms ──────────────────────
  // Any POST form inside `.admin-content` that opts in with [data-shell-form]
  // submits via fetch → follows the PRG redirect → swaps `.admin-content` from the
  // response → toasts the flash, so Settings/Rooms/Staff/… saves don't full-reload.
  //
  // Bound in the CAPTURE phase on document so it runs BEFORE the site-wide
  // double-submit guard + styled-confirm listeners in _layout_end.php (both bubble
  // on document): our preventDefault() makes those cleanly skip (they early-return
  // on e.defaultPrevented). Reuses the workspace submit's exact double-submit
  // safety — a rejection handler as the SECOND .then arg can only see fetch()
  // itself failing (request never completed → nothing written → native resubmit is
  // safe); a try/catch around RENDERING never resubmits post-commit, it falls back
  // to a full navigation. The form still works with no JS (plain POST + PRG).
  function shellSubmit(e) {
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.matches || !form.matches('[data-shell-form]')) return;
    if (!content || !content.contains(form)) return;
    if (form.closest('[data-ws-panel]')) return;                 // workspace owns its own forms
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

    e.preventDefault();
    // In-flight guard: because we preventDefault, the site-wide double-submit guard
    // in _layout_end.php (which early-returns on e.defaultPrevented) won't fire — so
    // block a second submit here until this one resolves (or replaces the form).
    if (form.dataset.shellBusy) return;
    form.dataset.shellBusy = '1';
    var action = form.getAttribute('action') || location.href;
    var fd = new FormData(form);
    var submitter = e.submitter;
    if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value);

    content.classList.add('is-swapping', 'is-saving');   // is-saving = dim/busy cue (form stays on screen, no skeleton)
    fetch(action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(
        function (r) { return r.text().then(function (text) { return { url: r.url, text: text }; }); },
        function () { content.classList.remove('is-swapping', 'is-saving'); form.submit(); return null; } // request never completed → safe native resubmit
      )
      .then(function (res) {
        if (!res) return;                    // the request failed; the fallback above already ran
        try {
          var doc = new DOMParser().parseFromString(res.text, 'text/html');
          var next = doc.querySelector('.admin-content');
          if (!next) { window.location.href = res.url || location.href; return; }  // login redirect / unexpected shape
          content.innerHTML = next.innerHTML;
          content.classList.remove('is-swapping', 'is-saving');
          if (doc.title) document.title = doc.title;
          try { if (res.url) history.replaceState({ shell: 1 }, '', res.url); } catch (e) { /* non-fatal */ }
          try { shellPath = new URL(res.url || location.href, location.href).pathname; } catch (e) { /* non-fatal */ }
          setActiveNav(res.url || location.href);
          // Surface the server flash as a toast (matches the initial-load behaviour
          // in _layout_end.php) and drop the now-redundant inline banner.
          content.querySelectorAll('.alert.is-flash').forEach(function (al) { al.remove(); });
          toastFlash(doc);
          runScripts(content);
          reenhance(content);
          initWorkspace(content);
        } catch (err) {
          // Post-commit: never resubmit. Show the real server state via a full load.
          content.classList.remove('is-swapping', 'is-saving');
          window.location.href = res.url || location.href;
        }
      });
  }

  if (content) {
    // Intercept sidebar links (always) + opt-in in-content links (Stage 3,
    // [data-shell-link]) → shell navigation. Other in-content links keep doing a
    // full navigation (safe); they can be brought in page-by-page later.
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest ? e.target.closest('a.sidebar__link, a[data-shell-link]') : null;
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!/^\/admin\//.test(href)) return;
      if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-shell')) return;
      if (/logout\.php/.test(href)) return;
      e.preventDefault();
      // Highlight the clicked link IMMEDIATELY (optimistic) so the active state
      // never lags behind the fetch or gets lost if the swap falls back to a full
      // load. shellNavigate() calls setActiveNav again after the swap (idempotent).
      setActiveNav(href);
      // Close the mobile drawer if open (the shell keeps the sidebar mounted).
      var sb = document.getElementById('adminSidebar');
      var ov = document.getElementById('sidebarOverlay');
      if (sb) sb.classList.remove('is-open');
      if (ov) ov.classList.remove('is-visible');
      document.body.style.overflow = '';
      var burger = document.getElementById('sidebarBurger');
      if (burger) burger.setAttribute('aria-expanded', 'false');
      shellNavigate(href, true);
    });

    // Stage 2 (#18): capture-phase so it precedes the bubble-phase guards.
    document.addEventListener('submit', shellSubmit, true);

    // Cross-page back/forward → shell re-swap. Same-path pops (workspace tabs,
    // data-table query strings) are left to those layers' own popstate handlers.
    window.addEventListener('popstate', function () {
      if (location.pathname !== shellPath) shellNavigate(location.href, false);
    });
    try { history.replaceState({ shell: 1 }, '', location.href); } catch (e) { /* non-fatal */ }
  }

  // Workspace back/forward (same-path only; cross-path handled by the shell).
  window.addEventListener('popstate', function () {
    if (!wsEl || !wsPanel) return;
    if (location.pathname !== shellPath) return;
    wsLoadTab(location.href, false);
  });

  // Initial bind.
  initWorkspace(document);
})();
