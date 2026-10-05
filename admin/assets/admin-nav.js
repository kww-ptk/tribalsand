/* ===== No-flicker admin shell — Tribal Sand admin (Phase 1 #1 + Phase 5 #18) =====
 *
 * Two cooperating layers, split by *swap region* so they never fight:
 *
 *  1. SHELL (#18) — cross-page navigation. Intercepts every link to an admin
 *     page (sidebar, tab strips, links and whole-row links inside a page, GET
 *     filter forms — see shellableUrl() for what is left to the browser), fetches the
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
  // page's own scripts (drag reorder, filter auto-submit, team chat, …) run again.
  // Several pages print a <script src> inside their content (assistant, team chat,
  // attendance, timetable…), so external scripts are loaded too, and everything
  // runs IN DOCUMENT ORDER: an inline script after a <script src> may use it.
  function runScripts(container) {
    var list = Array.prototype.slice.call(container.querySelectorAll('script'));
    function next() {
      var old = list.shift();
      if (!old) return;
      if (!old.parentNode) { next(); return; }
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
      if (old.src) {
        s.async = false;
        s.onload = s.onerror = next;
        old.parentNode.replaceChild(s, old);
      } else {
        s.textContent = old.textContent;
        old.parentNode.replaceChild(s, old);
        next();
      }
    }
    next();
  }

  // ── Shared: skeleton templates (Phase 1 #2) ──
  function bars(n, cls) {
    var s = '';
    for (var i = 0; i < n; i++) s += '<span class="skeleton sk-bar ' + cls + '"></span>';
    return s;
  }
  function skeletonTable() {
    var head = '<div class="sk-row sk-thead">' +
      '<span class="skeleton sk-pill"></span>' +
      '<span class="skeleton sk-pill"></span>' +
      '<span class="skeleton sk-bar sk-bar--grow"></span>' +
      '<span class="skeleton sk-bar sk-bar--md"></span>' +
      '<span class="skeleton sk-bar sk-bar--sm"></span>' +
      '</div>';
    var rows = '';
    for (var i = 0; i < 6; i++) {
      rows += '<div class="sk-row">' +
        '<span class="skeleton sk-pill"></span>' +          /* type badge   */
        '<span class="skeleton sk-pill"></span>' +          /* status badge */
        '<span class="sk-guest">' +                         /* two-line guest cell */
          '<span class="skeleton sk-bar sk-bar--md"></span>' +
          '<span class="skeleton sk-bar sk-bar--sm"></span>' +
        '</span>' +
        '<span class="skeleton sk-bar sk-bar--md"></span>' + /* room / date */
        '<span class="skeleton sk-dot"></span>' +            /* row action  */
        '</div>';
    }
    return '<div class="sk-card">' + head + rows + '</div>';
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
  // Match the loading shape to the destination: single-record views/editors
  // (…-view.php, …-edit.php, the booking workspace) get a form/detail shape;
  // every other page (lists, dashboards) gets the list shape. Falls back to
  // the list shape for anything unrecognised.
  function isDetailPath(url) {
    var p = url || '';
    try { p = new URL(url, location.href).pathname; } catch (e) {}
    return /(?:-view|-edit|\/booking)\.php$/.test(p);
  }
  function pageSkeleton(url) {
    var hdr = '<div class="sk-pagehdr">' +
        '<span class="skeleton sk-title"></span>' +
        '<span class="skeleton sk-btn"></span>' +
      '</div>';
    if (isDetailPath(url)) {
      // A record page is several stacked cards, not a table.
      return hdr + skeleton('detail') + skeleton('detail');
    }
    return hdr +
      '<div class="sk-toolbar">' +
        '<span class="skeleton sk-chip"></span>' +
        '<span class="skeleton sk-chip"></span>' +
        '<span class="skeleton sk-chip"></span>' +
        '<span class="skeleton sk-search"></span>' +
      '</div>' +
      skeletonTable();
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
  // The URL whose content the shell is showing. Stamped on every history entry
  // (as `sh`) so back/forward can tell "another page" from "same page, other tab".
  var shellHref = location.pathname + location.search;
  function shellState(obj) { obj = obj || {}; obj.sh = shellHref; return obj; }
  function crossPagePop(e) {
    var st = e && e.state;
    if (st && typeof st.sh === 'string') return st.sh !== shellHref;
    return location.pathname !== shellPath;
  }
  window.tsShellState = shellState;
  window.tsCrossPagePop = crossPagePop;

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
        try { if (push) history.pushState(shellState({ ws: 1 }), '', url); } catch (e) { /* non-fatal */ }
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
          try { if (res.url) history.replaceState(shellState({ ws: 1 }), '', res.url); } catch (e) { /* non-fatal */ }
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
    try { history.replaceState(shellState({ ws: 1 }), '', location.href); } catch (e) { /* non-fatal */ }
  }

  // ═══════════════════════════ SHELL (cross-page nav, #18) ═══════════════════
  var content = document.querySelector('.admin-content');

  // A sidebar link can stand for several pages (its tabs + their detail pages):
  // it lists their file names in data-pages (includes/admin-nav.php). Match on
  // the file name so "/admin/conflicts.php" and the clean "/admin/conflicts"
  // both light up Calendar.
  function pageFile(url) {
    var p;
    try { p = new URL(url, location.href).pathname; } catch (e) { p = String(url || ''); }
    var f = p.replace(/\/+$/, '').split('/').pop() || '';
    return f && f.indexOf('.') < 0 ? f + '.php' : f;
  }
  function setActiveNav(url) {
    var file = pageFile(url);
    document.querySelectorAll('.sidebar__link').forEach(function (a) {
      var pages = (a.getAttribute('data-pages') || '').split(' ');
      var on = !!file && pages.indexOf(file) >= 0;
      a.classList.toggle('is-active', on);
      if (on) {
        var g = a.closest('.navgroup');
        // autoOpen: opened by navigation — the sidebar script must not remember it
        // as the person's own choice.
        if (g && !g.open) { g.dataset.autoOpen = '1'; g.open = true; }
      }
    });
  }

  function shellNavigate(url, push) {
    if (!content) { window.location.href = url; return; }
    content.classList.add('is-swapping');
    content.innerHTML = pageSkeleton(url);
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
        try { var nu = new URL(url, location.href); shellPath = nu.pathname; shellHref = nu.pathname + nu.search; }
        catch (e) { shellPath = location.pathname; shellHref = location.pathname + location.search; }
        try {
          if (push) history.pushState(shellState({ shell: 1 }), '', url);
          else history.replaceState(shellState({ shell: 1 }), '', location.href);
        } catch (e) { /* non-fatal */ }
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
          try { var ru = new URL(res.url || location.href, location.href); shellPath = ru.pathname; shellHref = ru.pathname + ru.search; } catch (e) { /* non-fatal */ }
          try { if (res.url) history.replaceState(shellState({ shell: 1 }), '', res.url); } catch (e) { /* non-fatal */ }
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

  // ── Which URLs the shell may load ──────────────────────────────────────────
  // Every admin page that draws the normal layout answers `?shell=1` with just its
  // content, so ANY link to one can swap instead of reloading — not only the
  // sidebar. Left to the browser: pages without the layout (print views, files,
  // pollers, login/logout, one-shot actions) and downloads/exports.
  var NO_SHELL_PAGE = /^\/admin\/(?:_|index|login|logout|forgot-password|reset-password|sync-export|attendance-photo|rate-calendar-frag)|(?:-print|-file|-poll|-action)(?:\.php)?$/;
  var NO_SHELL_QUERY = /[?&](?:export|format|download|print|shell|ajax)=/;
  function shellableUrl(href) {
    if (!href || href.charAt(0) === '#') return null;
    if (/^(?:mailto|tel|javascript|data):/i.test(href)) return null;
    var u;
    try { u = new URL(href, location.href); } catch (e) { return null; }
    if (u.origin !== location.origin) return null;
    if (u.pathname.indexOf('/admin/') !== 0) return null;
    if (NO_SHELL_PAGE.test(u.pathname) || NO_SHELL_QUERY.test(u.search)) return null;
    // Same page, only a different #hash → let the browser scroll.
    if (u.hash && u.pathname === location.pathname && u.search === location.search) return null;
    return u.pathname + u.search + u.hash;
  }
  function closeDrawer() {
    var sb = document.getElementById('adminSidebar');
    var ov = document.getElementById('sidebarOverlay');
    if (sb) sb.classList.remove('is-open');
    if (ov) ov.classList.remove('is-visible');
    document.body.style.overflow = '';
    var burger = document.getElementById('sidebarBurger');
    if (burger) burger.setAttribute('aria-expanded', 'false');
  }
  // Public entry point: other scripts (whole-row links in admin-table.js, inline
  // page scripts) call this instead of setting location.href. Returns false when
  // the URL must be a real navigation — the caller then does that itself.
  function go(href) {
    var url = shellableUrl(href);
    if (!url || !content) return false;
    // Highlight the target IMMEDIATELY (optimistic) so the active state never lags
    // behind the fetch; shellNavigate() sets it again after the swap.
    setActiveNav(url);
    closeDrawer();
    shellNavigate(url, true);
    return true;
  }
  window.tsShellGo = go;

  function shellGetForm(form, submitter) {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return false;
    if (form.hasAttribute('data-no-shell') || (form.target && form.target !== '_self')) return false;
    var u;
    try { u = new URL(form.getAttribute('action') || location.pathname, location.href); } catch (err) { return false; }
    var fd = new FormData(form);
    if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
    var qs = new URLSearchParams();
    fd.forEach(function (v, k) { if (typeof v === 'string') qs.append(k, v); });
    var s = qs.toString();
    return go(u.pathname + (s ? '?' + s : ''));
  }

  if (content) {
    // Links anywhere on the page (sidebar, tab strips, links inside a page's
    // content) → shell navigation. The workspace's own tab/thread links are
    // claimed first by its listener on [data-ws] (it preventDefaults), and a
    // link can opt out with data-no-shell. Bound on WINDOW so it runs after every
    // document-level handler — a page script that claims its own links (calendar
    // prev/next, …) preventDefaults first, whatever order the scripts loaded in.
    window.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a) return;
      if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-shell')) return;
      if (go(a.getAttribute('href'))) e.preventDefault();
    });

    // Stage 2 (#18): capture-phase so it precedes the bubble-phase guards.
    document.addEventListener('submit', shellSubmit, true);

    // GET forms in the content (filters, searches) → shell navigation too.
    // On window (last), so a form's own handler (the data-table toolbar) wins.
    window.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      if (!(form instanceof HTMLFormElement) || !content.contains(form)) return;
      if (shellGetForm(form, e.submitter)) e.preventDefault();
    });
    // Many filters auto-submit with `onchange="this.form.submit()"`, which fires no
    // submit event — route those GET forms through the shell as well.
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
      if (content.contains(this) && shellGetForm(this, null)) return;
      return nativeSubmit.call(this);
    };

    // Back/forward. Every entry records which shell page it belongs to (`sh`), so
    // a pop that lands on another page — even one with the same path, like
    // another booking — re-swaps the content; pops within one page (workspace
    // tabs, data-table searches) are left to those layers.
    window.addEventListener('popstate', function (e) {
      if (crossPagePop(e)) shellNavigate(location.href, false);
    });
    try { history.replaceState(shellState({ shell: 1 }), '', location.href); } catch (e) { /* non-fatal */ }
  }

  // Workspace back/forward (same page only; another page is the shell's job).
  window.addEventListener('popstate', function (e) {
    if (!wsEl || !wsPanel) return;
    if (crossPagePop(e)) return;
    wsLoadTab(location.href, false);
  });

  // Initial bind.
  initWorkspace(document);
})();
