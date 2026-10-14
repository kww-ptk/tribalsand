/* Support page — the guide viewer, design B "Chapters" (Oct 2026).
 *
 * Draws the guide's own PDF pages with pdf.js (cdnjs, pinned) one page at a time,
 * sized to the reading area's WIDTH (max READER_MAX_W) so the text is readable,
 * with zoom − / +. Left: the guide's chapters — from the PDF's bookmarks, else
 * from the headings on its pages (the large text), else page by page — with the
 * page each starts on and how much has been read. Previous / next: the side
 * arrows, the bar under the page, ← →, or a swipe on a phone. On a narrow viewer
 * the chapters fold into a "Contents" button.
 *
 * Markup: admin/support.php (.sp-rd[data-reader][data-src]). If pdf.js or the PDF
 * can't load, it falls back to the browser's viewer (the hidden iframe), so a
 * guide always opens. Safe to run again after an admin shell page swap.
 */
(function () {
  'use strict';
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var READER_MAX_W = 900;        // px — the page never gets wider than this at 100%
  var NARROW = 760;              // px of viewer width below which the chapters fold away
  var HEADING_SCAN_MAX = 200;    // pages read for headings (a huge PDF falls back to page labels)

  function loadPdfJs(cb) {
    if (window.pdfjsLib) return cb(true);
    var cbs = loadPdfJs.cbs || (loadPdfJs.cbs = []);
    cbs.push(cb);
    if (cbs.length > 1) return;
    var s = document.createElement('script');
    s.src = PDFJS + 'pdf.min.js';
    s.onload = function () {
      if (window.pdfjsLib) window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
      loadPdfJs.cbs.splice(0).forEach(function (f) { f(!!window.pdfjsLib); });
    };
    s.onerror = function () { loadPdfJs.cbs.splice(0).forEach(function (f) { f(false); }); };
    document.head.appendChild(s);
  }

  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* The heading on a page: the largest text that is clearly bigger than the page's
     body text (≥ 1.3×), read top to bottom, words on one line joined. '' when none. */
  function pageHeading(tc) {
    var items = (tc.items || []).filter(function (it) { return it.str && it.str.trim() !== ''; });
    if (!items.length) return '';
    var size = function (it) { return Math.round(Math.hypot(it.transform[2], it.transform[3]) * 10) / 10; };
    // Body size = the size carrying the most characters.
    var weight = {};
    items.forEach(function (it) { var s = size(it); weight[s] = (weight[s] || 0) + it.str.length; });
    var body = +Object.keys(weight).sort(function (a, b) { return weight[b] - weight[a]; })[0];
    var big = items.filter(function (it) { return size(it) >= body * 1.3; });
    if (!big.length) return '';
    var top = Math.max.apply(null, big.map(size));
    // The biggest text, top-most line first; join the pieces of that line.
    var line = big.filter(function (it) { return size(it) >= top * 0.92; })
                  .sort(function (a, b) { return b.transform[5] - a.transform[5] || a.transform[4] - b.transform[4]; });
    var y = line[0].transform[5];
    var text = line.filter(function (it) { return Math.abs(it.transform[5] - y) < top * 0.6; })
                   .map(function (it) { return it.str; }).join(' ').replace(/\s+/g, ' ').trim();
    if (text.length < 3 || /^page\s+\d+/i.test(text) || /^\d+$/.test(text)) return '';
    return text.length > 70 ? text.slice(0, 68) + '…' : text;
  }

  function init(root) {
    if (root.dataset.readerBound) return;
    root.dataset.readerBound = '1';
    var $ = function (s) { return root.querySelector(s); };
    // The chapter list sits under the open guide in the page's guide list (outside the reader).
    var $d = function (s) { return root.querySelector(s) || document.querySelector(s); };
    var stage = $('[data-rd-stage]'), holder = $('[data-rd-page]'), toc = $d('[data-rd-toc]');
    var pdf = null, N = 0, cur = 1, zoom = 1, chapters = [], seq = 0, task = null, readMax = 1;

    function fallback() {
      root.classList.add('is-fallback');
      var f = $('[data-rd-fallback]');
      if (f) { f.hidden = false; if (!f.getAttribute('src')) f.src = f.getAttribute('data-src'); }
    }
    function chapterOf(p) { var c = null; chapters.forEach(function (x) { if (p >= x.page) c = x; }); return c; }

    function drawToc() {
      var list = chapters.length ? chapters : Array.from({ length: N }, function (_, i) { return { page: i + 1, title: 'Page ' + (i + 1) }; });
      toc.innerHTML = list.map(function (c, i) {
        return '<button type="button" data-rd-go="' + c.page + '"><span class="sp-rd__num">' + (chapters.length ? i + 1 : '') + '</span><span class="sp-rd__t">' + esc(c.title) + '</span><em>p. ' + c.page + '</em></button>';
      }).join('');
      mark();
    }
    function mark() {
      var c = chapterOf(cur), on = c ? c.page : cur;
      toc.querySelectorAll('[data-rd-go]').forEach(function (b) {
        var hit = +b.getAttribute('data-rd-go') === on;
        b.classList.toggle('is-on', hit);
        if (hit && !root.classList.contains('is-narrow')) { var box = toc, top = b.offsetTop - box.offsetTop; if (top < box.scrollTop || top > box.scrollTop + box.clientHeight - b.offsetHeight) box.scrollTop = top - 8; }
      });
      readMax = Math.max(readMax, cur);
      var pct = Math.round(readMax / N * 100);
      $d('[data-rd-done]').style.width = pct + '%';
      $d('[data-rd-donetxt]').textContent = pct + '% read';
      var crumb = $('[data-rd-crumb]');
      crumb.textContent = c ? c.title : '';
      $('[data-rd-pn]').textContent = 'Page ' + cur + ' of ' + N;
      root.querySelectorAll('[data-rd-prev]').forEach(function (b) { b.disabled = cur <= 1; });
      root.querySelectorAll('[data-rd-next]').forEach(function (b) { b.disabled = cur >= N; });
      $('[data-rd-zl]').textContent = Math.round(zoom * 100) + '%';
    }

    function layout() {
      root.classList.toggle('is-narrow', root.clientWidth < NARROW);
    }

    function render(dir) {
      if (!pdf) return;
      layout();
      var my = ++seq;
      if (task) { try { task.cancel(); } catch (e) {} task = null; }
      var narrow = root.classList.contains('is-narrow');
      var fitW = Math.min(stage.clientWidth - (narrow ? 16 : 36), READER_MAX_W);   // the side arrows have their own columns
      var cssW = Math.max(200, Math.floor(fitW * zoom));
      pdf.getPage(cur).then(function (page) {
        if (my !== seq) return;
        var base = page.getViewport({ scale: 1 });
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var vp = page.getViewport({ scale: (cssW / base.width) * dpr });
        var c = document.createElement('canvas');
        c.className = 'sp-rd__canvas' + (dir ? ' is-turn-' + (dir > 0 ? 'next' : 'prev') : '');
        c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
        c.style.width = cssW + 'px'; c.style.height = Math.round(cssW * base.height / base.width) + 'px';
        c.setAttribute('role', 'img'); c.setAttribute('aria-label', 'Page ' + cur + ' of ' + N);
        task = page.render({ canvasContext: c.getContext('2d'), viewport: vp });
        return task.promise.then(function () {
          if (my !== seq) return;
          holder.innerHTML = ''; holder.appendChild(c);
          var l = $('[data-rd-loading]'); if (l) l.hidden = true;
          if (dir) stage.scrollTop = 0;
        });
      }).catch(function () {});
      mark();
    }

    function go(p, dir) {
      p = Math.max(1, Math.min(N, p));
      if (p === cur && !dir) return;
      cur = p; render(dir || 0);
      // On a phone the chapters are above the page: after picking one, come back to the page.
      if (root.classList.contains('is-narrow') && toc && toc.contains(document.activeElement)) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Chapters: bookmarks → headings on the pages → page by page.
    function loadChapters() {
      return pdf.getOutline().then(function (items) {
        if (!items || !items.length) return [];
        return Promise.all(items.map(function (it) {
          var d = typeof it.dest === 'string' ? pdf.getDestination(it.dest) : Promise.resolve(it.dest);
          return d.then(function (dest) {
            return dest && dest[0] ? pdf.getPageIndex(dest[0]).then(function (i) { return { page: i + 1, title: String(it.title || '').trim() }; }) : null;
          }).catch(function () { return null; });
        }));
      }).catch(function () { return []; }).then(function (list) {
        list = (list || []).filter(function (x) { return x && x.title; });
        if (list.length) return list.sort(function (a, b) { return a.page - b.page; });
        if (N > HEADING_SCAN_MAX) return [];
        var found = [], chain = Promise.resolve();
        for (var n = 1; n <= N; n++) (function (n) {
          chain = chain.then(function () {
            return pdf.getPage(n).then(function (pg) { return pg.getTextContent(); }).then(function (tc) {
              var h = pageHeading(tc);
              if (h && (!found.length || found[found.length - 1].title !== h)) found.push({ page: n, title: h });
            }).catch(function () {});
          });
        })(n);
        return chain.then(function () {
          // One heading on most pages = a running header, not chapters.
          return found.length >= 2 ? found : [];
        });
      }).then(function (list) {
        if (list.length && list[0].page !== 1) list.unshift({ page: 1, title: 'Introduction' });
        chapters = list;
      });
    }

    // Controls
    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rd-go]');
      if (b) { var p = +b.getAttribute('data-rd-go'); return go(p, p > cur ? 1 : -1); }
      if (e.target.closest('[data-rd-prev]')) return go(cur - 1, -1);
      if (e.target.closest('[data-rd-next]')) return go(cur + 1, 1);
      if ((b = e.target.closest('[data-rd-zoom]'))) {
        zoom = Math.max(0.6, Math.min(2, Math.round((zoom + 0.2 * +b.getAttribute('data-rd-zoom')) * 10) / 10));
        return render(0);
      }
      if (e.target.closest('[data-rd-fit]')) { zoom = 1; return render(0); }
      if (e.target.closest('[data-rd-contents]')) { var w = $d('[data-rd-tocwrap]'); if (w) w.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
    // Chapter clicks happen outside the reader (in the guide list).
    if (toc && !root.contains(toc)) toc.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rd-go]');
      if (b) { var p = +b.getAttribute('data-rd-go'); go(p, p > cur ? 1 : -1); }
    });
    function onKey(e) {
      if (!root.isConnected) { document.removeEventListener('keydown', onKey); return; }
      if (!pdf || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName || '') || e.target.isContentEditable) return;
      if (document.querySelector('.sp-sheet[style*="flex"]')) return;
      if (e.key === 'ArrowRight') { e.preventDefault(); go(cur + 1, 1); }
      if (e.key === 'ArrowLeft')  { e.preventDefault(); go(cur - 1, -1); }
    }
    document.addEventListener('keydown', onKey);

    var tx = null, ty = null;
    stage.addEventListener('touchstart', function (e) { var t = e.changedTouches[0]; tx = t.clientX; ty = t.clientY; }, { passive: true });
    stage.addEventListener('touchend', function (e) {
      if (tx === null) return;
      var t = e.changedTouches[0], dx = t.clientX - tx, dy = t.clientY - ty;
      tx = null;
      if (zoom > 1) return;   // zoomed in: a sideways drag pans the page
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) go(cur + (dx < 0 ? 1 : -1), dx < 0 ? 1 : -1);
    }, { passive: true });

    var rt = 0, lastW = 0;
    if ('ResizeObserver' in window) {
      new ResizeObserver(function () {
        if (!root.isConnected || !pdf || Math.abs(root.clientWidth - lastW) < 2) return;
        lastW = root.clientWidth;
        clearTimeout(rt); rt = setTimeout(function () { render(0); }, 120);
      }).observe(root);
    }

    loadPdfJs(function (ok) {
      if (!ok) return fallback();
      window.pdfjsLib.getDocument({ url: root.getAttribute('data-src'), withCredentials: true }).promise.then(function (doc) {
        pdf = doc; N = doc.numPages; lastW = root.clientWidth;
        drawToc();
        render(0);
        return loadChapters().then(drawToc);
      }).catch(function (err) {
        if (window.console) console.warn('[support] guide viewer fell back to the browser viewer:', err);
        fallback();
      });
    });
  }

  function boot() { document.querySelectorAll('[data-reader]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
