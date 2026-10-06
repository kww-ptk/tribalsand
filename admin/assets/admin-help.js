/*
 * Help & guides — the on-page Help panel and "Show me" walkthroughs (design B).
 *
 * The guide index (#helpIndex, from includes/help-guides.php) and the panel sit
 * OUTSIDE .admin-content, so no-reload page swaps keep them; the panel works out
 * which page is showing each time it opens. Every guide in the index is one this
 * account may read (filtered server-side from the resolved nav).
 *
 * "Show me" highlights each step's target on the real page. A step whose target
 * isn't on the page (a different state, a role without that button) is shown as
 * a plain card, never an error. A walkthrough started from another page (the
 * library) is handed over in sessionStorage and starts once that page is showing.
 */
(function () {
  'use strict';
  if (window.__tsHelp) return;
  window.__tsHelp = true;

  var TOUR_KEY = 'ts_help_tour';
  var guides = [];
  try { guides = JSON.parse((document.getElementById('helpIndex') || {}).textContent || '[]') || []; } catch (e) { guides = []; }
  var drawer = document.getElementById('helpDrawer');
  var body = document.getElementById('helpBody');
  var search = document.getElementById('helpSearch');
  if (!drawer || !body) return;

  function page() {
    var p = (location.pathname.split('/').pop() || 'dashboard.php');
    if (p.indexOf('.') === -1) p += '.php';
    return p;
  }
  function forPage(p) { return guides.filter(function (g) { return g.p.indexOf(p) !== -1; }); }
  function bySlug(s) { for (var i = 0; i < guides.length; i++) if (guides[i].s === s) return guides[i]; return null; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // ── Help buttons show how many guides this page has ──
  function markButtons() {
    var n = forPage(page()).length;
    document.querySelectorAll('[data-help-open]').forEach(function (b) {
      if (n) b.setAttribute('data-count', n); else b.removeAttribute('data-count');
    });
  }

  // ── Panel views ──
  function card(g) {
    return '<button type="button" class="help-card" data-help-guide="' + esc(g.s) + '"><strong>' + esc(g.t) + '</strong>'
         + '<span>' + esc(g.sum) + ' · ' + g.m + ' min</span></button>';
  }
  function showList() {
    var p = page(), mine = forPage(p);
    var html = '';
    if (mine.length) {
      html += '<p class="help-label">On this page</p>' + mine.map(card).join('');
    } else {
      html += '<p class="help-empty">There’s no guide for this page yet. These may help:</p>';
    }
    var others = guides.filter(function (g) { return mine.indexOf(g) === -1; }).slice(0, mine.length ? 3 : 5);
    if (others.length) html += '<p class="help-label">' + (mine.length ? 'Also useful' : 'Popular guides') + '</p>' + others.map(card).join('');
    body.innerHTML = html;
  }
  function showSearch(q) {
    var words = q.toLowerCase().split(/\s+/).filter(Boolean);
    var hits = guides.filter(function (g) {
      var hay = (g.t + ' ' + g.sum + ' ' + g.a).toLowerCase();
      return words.every(function (w) { return hay.indexOf(w) !== -1; });
    });
    body.innerHTML = hits.length ? '<p class="help-label">' + hits.length + ' guide' + (hits.length === 1 ? '' : 's') + '</p>' + hits.map(card).join('')
                                 : '<p class="help-empty">No guide matches “' + esc(q) + '”.</p>';
  }
  function showGuide(g) {
    var here = g.p.indexOf(page()) !== -1;
    var html = '<button type="button" class="help-back" data-help-back>‹ Back</button>'
      + '<div class="help-guide"><h3>' + esc(g.t) + '</h3>'
      + '<div class="help-guide__meta">' + esc(g.a) + ' · ' + g.m + ' min</div>'
      + '<p style="margin:0 0 12px">' + esc(g.sum) + '</p>'
      + '<ol class="help-steps">' + g.st.map(function (s) { return '<li><span>' + s.h + '</span></li>'; }).join('') + '</ol>'
      + (g.tips && g.tips.length ? '<div class="help-tips">' + g.tips.map(function (t) { return '<p>' + t + '</p>'; }).join('') + '</div>' : '')
      + '<div class="help-actions">'
      + (here ? '<button type="button" class="btn-primary btn-sm" data-help-tour="' + esc(g.s) + '">Show me on this page</button>'
              : '<a class="btn-primary btn-sm" href="/admin/' + esc(g.p[0]) + '" data-help-tour-link="' + esc(g.s) + '">Open the page and show me</a>')
      + '<a class="btn-outline btn-sm" href="/admin/help.php?g=' + encodeURIComponent(g.s) + '">Open full guide</a>'
      + '</div></div>';
    body.innerHTML = html;
    body.scrollTop = 0;
  }

  function open() {
    drawer.hidden = false;
    document.body.classList.add('help-open');
    if (search) search.value = '';
    showList();
    var t = drawer.querySelector('[data-help-close]'); if (t) t.focus();
  }
  function close() {
    drawer.hidden = true;
    document.body.classList.remove('help-open');
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-help-open]')) { e.preventDefault(); drawer.hidden ? open() : close(); return; }
    if (t.closest('[data-help-close]')) { close(); return; }
    if (t.closest('[data-help-back]')) { if (search && search.value.trim()) showSearch(search.value.trim()); else showList(); return; }
    var c = t.closest('[data-help-guide]');
    if (c) { var g = bySlug(c.getAttribute('data-help-guide')); if (g) showGuide(g); return; }
    var tour = t.closest('[data-help-tour]');
    if (tour) { var tg = bySlug(tour.getAttribute('data-help-tour')); if (tg) { close(); startTour(tg); } return; }
    var link = t.closest('[data-help-tour-link]');
    if (link) {
      // Hand over to the target page; the shell (or a full load) takes the link.
      try { sessionStorage.setItem(TOUR_KEY, link.getAttribute('data-help-tour-link')); } catch (x) {}
      close();
    }
  });
  if (search) search.addEventListener('input', function () {
    var q = search.value.trim();
    if (q) showSearch(q); else showList();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && tourState) { endTour(); return; }
    if (e.key === 'Escape' && !drawer.hidden && !document.querySelector('.navsearch:not([hidden])')) close();
  });

  // ── "Show me" walkthrough ──
  var tourState = null, ring = null, tip = null;

  function visible(el) { return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function findTarget(sel) {
    if (!sel) return null;
    var list; try { list = document.querySelectorAll(sel); } catch (e) { return null; }
    for (var i = 0; i < list.length; i++) if (visible(list[i])) return list[i];
    return null;
  }
  function startTour(g) {
    tourState = { g: g, i: 0 };
    ring = document.createElement('div'); ring.className = 'helptour__ring';
    tip = document.createElement('div'); tip.className = 'helptour__tip'; tip.setAttribute('role', 'dialog'); tip.setAttribute('aria-live', 'polite');
    document.body.appendChild(ring); document.body.appendChild(tip);
    tip.addEventListener('click', function (e) {
      if (e.target.closest('[data-tour-next]')) { if (tourState.i >= tourState.g.st.length - 1) endTour(); else showStep(tourState.i + 1); }
      else if (e.target.closest('[data-tour-back]')) showStep(Math.max(0, tourState.i - 1));
      else if (e.target.closest('[data-tour-end]')) endTour();
    });
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    showStep(0);
  }
  function showStep(i) {
    tourState.i = i;
    var s = tourState.g.st[i], n = tourState.g.st.length;
    if (s.o) { var opener = findTarget(s.o); if (opener) opener.click(); }
    tourState.el = findTarget(s.q);
    if (tourState.el) tourState.el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    tip.innerHTML = '<div class="helptour__count">' + esc(tourState.g.t) + ' · step ' + (i + 1) + ' of ' + n + '</div>'
      + '<div>' + s.h + '</div>'
      + '<div class="helptour__nav"><button type="button" data-tour-end>Close</button><span style="display:flex;gap:8px">'
      + '<button type="button" data-tour-back' + (i === 0 ? ' disabled' : '') + '>Back</button>'
      + '<button type="button" data-tour-next>' + (i === n - 1 ? 'Done' : 'Next') + '</button></span></div>';
    place();
    setTimeout(place, 350);   // after the smooth scroll settles
    var nx = tip.querySelector('[data-tour-next]'); if (nx) nx.focus();
  }
  function place() {
    if (!tourState) return;
    var el = tourState.el;
    if (!el || !visible(el)) {
      ring.style.cssText = 'left:50%;top:50%;width:0;height:0';
      tip.classList.add('is-center'); tip.style.left = ''; tip.style.top = '';
      return;
    }
    tip.classList.remove('is-center');
    var r = el.getBoundingClientRect(), pad = 6;
    ring.style.cssText = 'left:' + (r.left - pad) + 'px;top:' + (r.top - pad) + 'px;width:' + (r.width + pad * 2) + 'px;height:' + (r.height + pad * 2) + 'px';
    var tw = tip.offsetWidth, th = tip.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
    var top = r.bottom + 14;
    if (top + th > vh - 8) top = Math.max(8, r.top - th - 14);
    var left = Math.min(Math.max(8, r.left), vw - tw - 8);
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function endTour() {
    if (ring) ring.remove(); if (tip) tip.remove();
    ring = tip = null; tourState = null;
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
  }

  // A walkthrough handed over from another page starts once that page is showing.
  function pendingTour() {
    var slug = null;
    try { slug = sessionStorage.getItem(TOUR_KEY); } catch (e) {}
    if (!slug) return;
    var g = bySlug(slug);
    if (!g || g.p.indexOf(page()) === -1) return;
    try { sessionStorage.removeItem(TOUR_KEY); } catch (e) {}
    setTimeout(function () { if (!tourState) startTour(g); }, 250);
  }

  // Page swaps replace .admin-content: refresh the counts, close a stale panel, run a handed-over tour.
  var content = document.querySelector('.admin-content');
  if (content && window.MutationObserver) {
    var t = null;
    new MutationObserver(function () {
      clearTimeout(t);
      t = setTimeout(function () {
        markButtons();
        if (tourState && tourState.el && !document.body.contains(tourState.el)) endTour();
        if (!drawer.hidden) showList();
        pendingTour();
      }, 60);
    }).observe(content, { childList: true });
  }
  markButtons();
  pendingTour();

  // Library page: open a guide's "Show me" from the full guide.
  window.tsHelpStart = function (slug) { var g = bySlug(slug); if (g) startTour(g); };
})();
