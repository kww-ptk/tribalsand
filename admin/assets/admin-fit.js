/* ===== Table fit — Tribal Sand admin =====
 *
 * Two small behaviours every admin list shares:
 *
 *  1. PAGINATION for plain tables. A table that opts in with
 *     <table data-paginate="25"> gets the same footer the server-paginated lists
 *     have (count · page buttons) plus a per-page choice (10 · 25 · 50 · All).
 *     Rows of other pages are hidden with the class `pg-off`, never the `hidden`
 *     attribute — that attribute belongs to the page's own search/filter, and the
 *     pager pages through whatever the filter leaves. Hidden rows stay in the DOM,
 *     so a form still submits every row's fields and drag-reorder still sends the
 *     full order ("All" shows every row for dragging across pages).
 *
 *  2. FILL HEIGHT. When a page's last block is a table, its card is stretched to
 *     the bottom of the window instead of ending mid-screen, and its footer sits
 *     on the bottom edge. Skipped on phones, where the page simply scrolls.
 *
 * Both re-run when the content changes (no-reload page swaps, AJAX list swaps,
 * filters, sorting) via one MutationObserver on `.admin-content`.
 */
(function () {
  'use strict';

  var CHEV = {
    first: '<path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>',
    prev:  '<path d="m15 18-6-6 6-6"/>',
    next:  '<path d="m9 18 6-6-6-6"/>',
    last:  '<path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/>'
  };
  function icon(k) {
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + CHEV[k] + '</svg>';
  }
  function storeKey(table) { return 'ts_pg:' + location.pathname + ':' + (table.id || table.className); }

  // ─────────────────────────── pagination ───────────────────────────
  function render(table) {
    var st = table._pg, body = table.tBodies[0];
    if (!st || !body) return;
    var all = Array.prototype.slice.call(body.rows);
    var rows = all.filter(function (r) { return !r.hidden; });
    var n = rows.length;
    if (st.lastCount !== undefined && st.lastCount !== n) st.page = 1;   // the filter changed what is listed
    st.lastCount = n;
    var per = st.per === 0 ? Math.max(n, 1) : st.per;
    var pages = Math.max(1, Math.ceil(n / per));
    if (st.page > pages) st.page = pages;
    var from = (st.page - 1) * per, to = Math.min(n, from + per);
    all.forEach(function (r) { r.classList.remove('pg-off'); });
    rows.forEach(function (r, i) { if (i < from || i >= to) r.classList.add('pg-off'); });

    var btn = function (k, page, off, label) {
      return off ? '<span class="dt-page is-disabled" aria-disabled="true">' + icon(k) + '</span>'
                 : '<a class="dt-page" role="button" tabindex="0" data-pg="' + page + '" aria-label="' + label + '">' + icon(k) + '</a>';
    };
    var nums = '', a = Math.max(1, st.page - 2), z = Math.min(pages, st.page + 2);
    if (a > 1) nums += '<span class="dt-ellipsis">…</span>';
    for (var i = a; i <= z; i++) {
      nums += i === st.page ? '<span class="dt-page is-current" aria-current="page">' + i + '</span>'
                            : '<a class="dt-page" role="button" tabindex="0" data-pg="' + i + '">' + i + '</a>';
    }
    if (z < pages) nums += '<span class="dt-ellipsis">…</span>';
    var per_ = [10, 25, 50, 0].map(function (p) {
      return '<a role="button" tabindex="0" data-per="' + p + '"' + (p === st.per ? ' class="is-current"' : '') + '>' + (p === 0 ? 'All' : p) + '</a>';
    }).join('');

    st.foot.innerHTML =
      '<div class="dt-count" aria-live="polite">' +
        (n === 0 ? 'No entries' : 'Showing <strong>' + (from + 1) + '–' + to + '</strong> of <strong>' + n + '</strong> ' + (n === 1 ? 'entry' : 'entries')) +
        '<span class="dt-per">Show ' + per_ + '</span></div>' +
      '<nav class="dt-pager" aria-label="Pagination">' +
        btn('first', 1, st.page <= 1, 'First page') + btn('prev', st.page - 1, st.page <= 1, 'Previous page') + nums +
        btn('next', st.page + 1, st.page >= pages, 'Next page') + btn('last', pages, st.page >= pages, 'Last page') +
      '</nav>';
  }

  function bind(table) {
    if (table._pg || !table.tBodies[0]) return;
    var per = parseInt(table.getAttribute('data-paginate'), 10);
    if (isNaN(per) || per < 0) per = 25;
    try { var saved = localStorage.getItem(storeKey(table)); if (saved !== null && !isNaN(parseInt(saved, 10))) per = parseInt(saved, 10); } catch (e) {}
    var wrap = table.closest('.table-wrap, .ig-wrap') || table;
    var foot = document.createElement('div');
    foot.className = 'dt-pager-wrap dt-pager-wrap--plain';
    foot.setAttribute('data-pg-foot', '');
    wrap.parentNode.insertBefore(foot, wrap.nextSibling);
    table._pg = { page: 1, per: per, foot: foot };

    var act = function (e) {
      var t = e.target.closest ? e.target.closest('[data-pg],[data-per]') : null;
      if (!t || (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ')) return;
      e.preventDefault();
      if (t.hasAttribute('data-per')) {
        table._pg.per = parseInt(t.getAttribute('data-per'), 10) || 0;
        table._pg.page = 1;
        try { localStorage.setItem(storeKey(table), String(table._pg.per)); } catch (err) {}
      } else {
        table._pg.page = parseInt(t.getAttribute('data-pg'), 10) || 1;
      }
      render(table);
      var top = wrap.getBoundingClientRect().top;
      if (top < 0) window.scrollBy(0, top - 12);
      if (wrap.scrollTop) wrap.scrollTop = 0;
      schedule();
    };
    foot.addEventListener('click', act);
    foot.addEventListener('keydown', act);

    // Re-page when the page's own script filters (row.hidden), sorts or swaps rows.
    var t;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { render(table); }, 30); })
      .observe(table.tBodies[0], { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    render(table);
  }

  // ─────────────────────────── fill height ──────────────────────────
  function fill() {
    var c = document.querySelector('.admin-content');
    if (!c) return;
    c.querySelectorAll('[data-filled]').forEach(function (el) {
      el.style.minHeight = ''; el.removeAttribute('data-filled'); el.classList.remove('is-filled');
    });
    if (window.innerWidth <= 768) return;

    var kids = Array.prototype.filter.call(c.children, function (el) {
      if (/^(SCRIPT|STYLE|TEMPLATE|DIALOG|LINK)$/.test(el.tagName) || el.hidden || !el.offsetHeight) return false;
      var pos = getComputedStyle(el).position;
      return pos !== 'fixed' && pos !== 'absolute';
    });
    var last = kids[kids.length - 1];
    if (!last) return;

    var target = null, flex = false;
    var bodies = last.matches('.dt-body') ? [last] : last.querySelectorAll('.dt-body');
    if (bodies.length === 1) target = bodies[0];
    else if (last.matches('[data-pg-foot]') && kids.length > 1) target = kids[kids.length - 2];
    else if (last.matches('.card, .ig-wrap, .table-wrap') && last.querySelector('table')) {
      target = last;
      // Only a card with its own footer needs to become a column (to seat the footer on
      // the bottom edge); doing it to every card would change how its inner margins stack.
      flex = last.matches('.card') && !!last.querySelector(':scope > .dt-pager-wrap');
    }
    else {
      // A spreadsheet wrapped in its form (Inventory, an order): stretch the one grid box inside it.
      var grids = last.querySelectorAll('.ig-wrap');
      if (grids.length === 1) target = grids[0];
    }
    if (!target || !target.querySelector('table, .dt-empty')) return;

    var gap = window.innerHeight - (c.getBoundingClientRect().bottom + window.scrollY);
    if (gap <= 4) return;
    var want = Math.floor(target.offsetHeight + gap);
    target.setAttribute('data-filled', '');
    if (flex) target.classList.add('is-filled');
    target.style.minHeight = want + 'px';
    // min-height is measured on the content box for some cards (padding, borders) and
    // becoming a flex column can add a few pixels: never leave the page scrolling by them.
    var over = Math.ceil(c.getBoundingClientRect().bottom + window.scrollY - window.innerHeight);
    if (over > 0) target.style.minHeight = Math.max(0, want - over) + 'px';
    if (c.getBoundingClientRect().bottom + window.scrollY - window.innerHeight > 1) {
      // Still too tall: the content itself needs the room. Leave the page as it was.
      target.style.minHeight = ''; target.removeAttribute('data-filled'); target.classList.remove('is-filled');
    }
  }

  // ─────────────────────────── wiring ───────────────────────────────
  var pending, fitted = -1;
  function run() {
    document.querySelectorAll('table[data-paginate]').forEach(bind);
    fill();
    var c = document.querySelector('.admin-content');
    fitted = c ? c.offsetHeight : -1;
  }
  function schedule() { clearTimeout(pending); pending = setTimeout(run, 60); }

  function start() {
    run();
    var c = document.querySelector('.admin-content');
    if (c) new MutationObserver(schedule).observe(c, { childList: true, subtree: true });
    window.addEventListener('resize', schedule);
    window.addEventListener('load', schedule);
    // Heights also move without any DOM change (web fonts, images, an enhanced
    // dropdown): re-fit when the content's height is not the one the last fit left.
    if (c && 'ResizeObserver' in window) {
      new ResizeObserver(function () { if (Math.abs(c.offsetHeight - fitted) > 1) schedule(); }).observe(c);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
  window.tsTableFit = schedule;
})();
