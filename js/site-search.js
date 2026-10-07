/*
 * Site search — "find anything" (includes/site-search.php, api/site-search.php).
 *
 * Every [data-ss-box] form (the header overlay, the homepage / page bars) gets
 * live suggestions while typing: results grouped by kind, ↑ ↓ to move, Enter
 * to open, Esc to close; Enter with nothing highlighted (or "See all") opens
 * /site-search?q=…, which is also what the form does with JS off.
 * The overlay opens from the header button, "/" or Ctrl/⌘+K.
 * Recent searches are kept in localStorage (ts_site_search_recent_v1).
 */
(function () {
  'use strict';
  if (window.__tsSiteSearch) return;
  window.__tsSiteSearch = true;

  var API = '/api/site-search.php';
  var RECENT_KEY = 'ts_site_search_recent_v1';
  var ICONS = {
    page: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    stay: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    room: '<path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>',
    dining: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
    experience: '<path d="M2 6c.6.5 1.2 1 2.5 1C7 7 7 5 9.5 5c2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M2 12c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M2 18c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/>',
    journal: '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    recent: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'
  };
  var SUGGEST = ['Beachfront villa', 'Restaurants', 'Activities', 'Weddings', 'Kilifi', 'Watamu', 'Airport transfer', 'Kitesurfing'];

  function icon(name) {
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || ICONS.page) + '</svg>';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // Bold the typed words where they start a word in the title.
  function mark(text, q) {
    var safe = esc(text);
    var words = String(q || '').toLowerCase().split(/\s+/).filter(function (w) { return w.length > 1; });
    words.forEach(function (w) {
      var re = new RegExp('(^|[^a-z0-9])(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
      safe = safe.replace(re, '$1<mark>$2</mark>');
    });
    return safe;
  }
  function recent() {
    try { var r = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(r) ? r.slice(0, 5) : []; }
    catch (e) { return []; }
  }
  function remember(q) {
    q = String(q || '').trim();
    if (q.length < 2) return;
    try {
      var r = recent().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); });
      r.unshift(q);
      localStorage.setItem(RECENT_KEY, JSON.stringify(r.slice(0, 5)));
    } catch (e) { /* private mode */ }
  }
  function allUrl(q) { return '/site-search?q=' + encodeURIComponent(q); }

  // ── One search box ──
  function attach(form) {
    if (form.__ss) return;
    var input = form.querySelector('[data-ss-input]');
    var panel = form.querySelector('[data-ss-results]');
    if (!input || !panel) return;
    var isOverlay = form.hasAttribute('data-ss-overlay');
    var timer = null, ctrl = null, active = -1, lastQ = null, cache = {};
    form.__ss = true;

    var listId = (input.id || 'ss' + Math.random().toString(36).slice(2, 7)) + '-list';
    panel.id = listId;
    panel.setAttribute('role', 'listbox');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('aria-expanded', 'false');

    function options() { return panel.querySelectorAll('[data-ss-opt]'); }
    function setActive(i) {
      var opts = options();
      if (!opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
      active = (i + opts.length) % opts.length;
      opts.forEach(function (o, k) { o.classList.toggle('is-active', k === active); o.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
      input.setAttribute('aria-activedescendant', opts[active].id);
      opts[active].scrollIntoView({ block: 'nearest' });
    }
    function open(html) {
      panel.innerHTML = html;
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      form.classList.add('is-open');
      options().forEach(function (o, k) { o.id = listId + '-o' + k; });
      active = -1;
    }
    function close() {
      if (isOverlay) return;            // the overlay always shows its panel
      panel.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      form.classList.remove('is-open');
      active = -1;
    }

    function chipsHtml() {
      var r = recent(), html = '';
      if (r.length) {
        html += '<div class="ss-group"><div class="ss-group__lbl">Recent searches</div>';
        r.forEach(function (q) {
          html += '<a class="ss-opt ss-opt--q" href="' + allUrl(q) + '" data-ss-opt data-ss-q="' + esc(q) + '">' + icon('recent') + '<span class="ss-opt__t">' + esc(q) + '</span></a>';
        });
        html += '</div>';
      }
      html += '<div class="ss-group"><div class="ss-group__lbl">Popular</div><div class="ss-chips">';
      SUGGEST.forEach(function (q) { html += '<a class="ss-chip" href="' + allUrl(q) + '" data-ss-opt data-ss-q="' + esc(q) + '">' + esc(q) + '</a>'; });
      html += '</div></div>';
      return html;
    }

    function render(q, data) {
      var groups = (data && data.groups) || [];
      if (!groups.length) {
        open('<div class="ss-empty"><strong>No results for “' + esc(q) + '”.</strong><span>Try a property name, “villa”, “restaurant” or a town like “Kilifi”.</span>'
          + '<span class="ss-empty__links"><a href="/search">Check availability for your dates →</a><a href="/enquire">Ask us →</a></span></div>');
        return;
      }
      var html = '';
      groups.forEach(function (g) {
        html += '<div class="ss-group"><div class="ss-group__lbl">' + esc(g.label) + '</div>';
        g.items.forEach(function (r) {
          html += '<a class="ss-opt" role="option" href="' + esc(r.url) + '" data-ss-opt>'
            + '<span class="ss-opt__icon">' + icon(r.type) + '</span>'
            + '<span class="ss-opt__body"><span class="ss-opt__t">' + mark(r.title, q) + '</span>'
            + (r.sub ? '<span class="ss-opt__s">' + esc(r.sub) + '</span>' : '') + '</span>'
            + '<span class="ss-opt__go" aria-hidden="true">→</span></a>';
        });
        html += '</div>';
      });
      html += '<a class="ss-all" href="' + allUrl(q) + '" data-ss-opt>' + icon('search') + ' See all results for “' + esc(q) + '”</a>';
      open(html);
    }

    function run() {
      var q = input.value.trim();
      if (q === lastQ && !panel.hidden) return;
      lastQ = q;
      if (q.length < 2) { open(chipsHtml()); return; }
      if (cache[q]) { render(q, cache[q]); return; }
      form.classList.add('is-loading');
      if (ctrl && ctrl.abort) ctrl.abort();
      ctrl = window.AbortController ? new AbortController() : null;
      fetch(API + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) { cache[q] = data; if (input.value.trim() === q) render(q, data); })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          open('<div class="ss-empty"><strong>Search isn’t available right now.</strong><span>Press Enter to see the full results page, or <a href="/contact">contact us</a>.</span></div>');
        })
        .then(function () { form.classList.remove('is-loading'); });
    }

    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 160); });
    input.addEventListener('focus', function () { lastQ = null; run(); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) run(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Escape') {
        if (isOverlay) { closeOverlay(); }
        else if (!panel.hidden) { e.preventDefault(); close(); }
      } else if (e.key === 'Enter' && active >= 0) {
        var opt = options()[active];
        if (opt) { e.preventDefault(); remember(opt.getAttribute('data-ss-q') || input.value); location.href = opt.href; }
      }
    });
    form.addEventListener('submit', function (e) {
      var q = input.value.trim();
      if (q.length < 2) { e.preventDefault(); input.focus(); open(chipsHtml()); return; }
      remember(q);
    });
    panel.addEventListener('click', function (e) {
      var opt = e.target.closest('[data-ss-opt]');
      if (!opt) return;
      var q = opt.getAttribute('data-ss-q');
      if (q) {                         // a recent / popular search fills the box
        e.preventDefault();
        input.value = q;
        input.focus();
        lastQ = null;
        run();
        remember(q);
        return;
      }
      remember(input.value);
    });
    // Clicking outside an inline box closes its suggestions.
    document.addEventListener('mousedown', function (e) { if (!form.contains(e.target)) close(); });
    form.addEventListener('focusout', function () {
      setTimeout(function () { if (!form.contains(document.activeElement)) close(); }, 0);
    });

    form.__ssRun = function () { lastQ = null; run(); };
  }

  // ── Header overlay ──
  var overlay = null, lastFocus = null;
  function openOverlay(prefill) {
    overlay = overlay || document.getElementById('ssOverlay');
    if (!overlay) { location.href = '/site-search'; return; }
    var form = overlay.querySelector('[data-ss-box]');
    var input = overlay.querySelector('[data-ss-input]');
    attach(form);
    lastFocus = document.activeElement;
    overlay.hidden = false;
    document.documentElement.classList.add('ss-lock');
    requestAnimationFrame(function () { overlay.classList.add('is-in'); });
    if (typeof prefill === 'string') input.value = prefill;
    input.focus();
    input.select();
    if (form.__ssRun) form.__ssRun();
  }
  function closeOverlay() {
    if (!overlay || overlay.hidden) return;
    overlay.classList.remove('is-in');
    document.documentElement.classList.remove('ss-lock');
    setTimeout(function () { overlay.hidden = true; }, 180);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* gone */ } }
  }
  window.tsOpenSiteSearch = openOverlay;

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-ss-open]');
    if (t) { e.preventDefault(); openOverlay(); return; }
    if (e.target.closest('[data-ss-close]')) { e.preventDefault(); closeOverlay(); }
    var chip = e.target.closest('[data-ss-chip]');
    if (chip) {
      // Popular chips under a page bar search right there.
      var bar = chip.closest('.ssbar');
      var form = bar && bar.querySelector('[data-ss-box]');
      var input = form && form.querySelector('[data-ss-input]');
      if (input) { e.preventDefault(); input.value = chip.getAttribute('data-ss-chip'); input.focus(); if (form.__ssRun) form.__ssRun(); }
    }
  });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target && e.target.tagName) || '';
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable);
    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); openOverlay(); return; }
    if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); openOverlay(); return; }
    if (e.key === 'Escape' && overlay && !overlay.hidden) closeOverlay();
    // Keep Tab inside the open overlay.
    if (e.key === 'Tab' && overlay && !overlay.hidden) {
      var f = overlay.querySelectorAll('input, button, a[href]');
      f = Array.prototype.filter.call(f, function (el) { return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  function init() {
    document.querySelectorAll('[data-ss-box]').forEach(function (f) { if (!f.hasAttribute('data-ss-overlay')) attach(f); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
