/* Support page — the guide viewer, design C "Book spread" (Oct 2026).
 *
 * Draws the guide's own PDF pages with pdf.js (cdnjs, pinned) instead of the
 * browser's PDF viewer: two pages side by side like an open book (the cover alone
 * on the right) on a dark background, previous / next arrows, the chapter the
 * pages belong to (from the PDF's bookmarks, when it has them) and a strip of
 * every page underneath. One page at a time when the viewer is narrower than
 * BOOK_TWO_UP_MIN (phones, small windows) — swipe to turn. Keyboard ← → turns,
 * Full screen uses the browser's full-screen mode where it exists.
 *
 * Markup: admin/support.php (.sp-book[data-book][data-src]). When pdf.js can't
 * load or the PDF can't be read, the page falls back to the browser's own viewer
 * (the hidden iframe), so a guide always opens. Safe to run again after an admin
 * shell page swap: each viewer binds once (data-book-bound).
 */
(function () {
  'use strict';
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var BOOK_TWO_UP_MIN = 760;   // px of viewer width for two pages side by side

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

  function init(root) {
    if (root.dataset.bookBound) return;
    root.dataset.bookBound = '1';
    var $ = function (s) { return root.querySelector(s); };
    var stage = $('[data-book-stage]'), spread = $('[data-book-spread]'), strip = $('[data-book-strip]');
    var pnEl = $('[data-book-pn]'), capEl = $('[data-book-cap]'), loading = $('[data-book-loading]');
    var pdf = null, N = 0, aspect = 1.414, cur = 1, two = false, chapters = [], thumbsDone = {}, renderSeq = 0, tasks = [];

    function fallback() {
      root.classList.add('is-fallback');
      var f = $('[data-book-fallback]');
      if (f) { f.hidden = false; if (!f.src) f.src = f.getAttribute('data-src'); }
    }

    // A spread starts at its left page: cover alone (1), then 2–3, 4–5…
    function spreadStart(p) { return !two || p <= 1 ? p : (p % 2 === 0 ? p : p - 1); }
    function pagesOf(start) {
      if (!two) return [start];
      if (start === 1) return [1];
      return start + 1 <= N ? [start, start + 1] : [start];
    }
    function chapterOf(p) {
      var c = '';
      chapters.forEach(function (x) { if (p >= x.page) c = x.title; });
      return c;
    }

    function drawPage(num, canvas, cssW, seq) {
      return pdf.getPage(num).then(function (page) {
        if (seq !== renderSeq) return;
        var base = page.getViewport({ scale: 1 });
        var dpr = Math.min(window.devicePixelRatio || 1, 2);
        var vp = page.getViewport({ scale: (cssW / base.width) * dpr });
        canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
        canvas.style.width = cssW + 'px'; canvas.style.height = (cssW * base.height / base.width) + 'px';
        var t = page.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
        tasks.push(t);
        return t.promise.catch(function () {});
      });
    }

    function layout() {
      var w = stage.clientWidth;
      two = w >= BOOK_TWO_UP_MIN && N > 1;
      root.classList.toggle('is-two', two);
      root.classList.toggle('is-narrow', w < 640);
    }

    function render(dir) {
      if (!pdf) return;
      layout();
      cur = spreadStart(cur);
      var pages = pagesOf(cur), seq = ++renderSeq;
      tasks.splice(0).forEach(function (t) { try { t.cancel(); } catch (e) {} });

      // Size: fit the stage's height on a wide screen; the width on a narrow one.
      var narrow = root.classList.contains('is-narrow');
      var navSpace = narrow ? 0 : 72;
      var availW = stage.clientWidth - navSpace * 2;
      var pageW;
      if (narrow) {
        pageW = Math.max(160, availW - 8);
      } else {
        var availH = stage.clientHeight - 24;
        pageW = availH / aspect;
        var slots = two ? 2 : 1;
        if (pageW * slots > availW) pageW = availW / slots;
      }
      pageW = Math.floor(pageW);

      var frag = document.createElement('div');
      frag.className = 'sp-book__pages' + (dir ? ' is-turn-' + (dir > 0 ? 'next' : 'prev') : '');
      if (two && pages[0] === 1) frag.classList.add('is-cover');
      var jobs = pages.map(function (n) {
        var c = document.createElement('canvas');
        c.className = 'sp-book__page';
        c.setAttribute('aria-label', 'Page ' + n + ' of ' + N);
        c.style.width = pageW + 'px'; c.style.height = Math.round(pageW * aspect) + 'px';
        frag.appendChild(c);
        return drawPage(n, c, pageW, seq);
      });
      spread.innerHTML = '';
      spread.appendChild(frag);
      Promise.all(jobs).then(function () { if (loading) loading.hidden = true; });

      pnEl.textContent = (pages.length > 1 ? 'Pages ' + pages[0] + '–' + pages[1] : 'Page ' + pages[0]) + ' of ' + N;
      var ch = chapterOf(pages[pages.length - 1]);
      capEl.textContent = ch; capEl.hidden = !ch;
      $('[data-book-prev]').disabled = cur <= 1;
      $('[data-book-next]').disabled = pages[pages.length - 1] >= N;
      strip.querySelectorAll('[data-book-go]').forEach(function (b) {
        var on = pages.indexOf(+b.getAttribute('data-book-go')) >= 0;
        b.classList.toggle('is-on', on);
        if (on && b === strip.querySelector('[data-book-go="' + pages[0] + '"]')) {
          var bl = b.offsetLeft - strip.clientWidth / 2 + b.offsetWidth / 2;
          strip.scrollTo({ left: bl, behavior: 'smooth' });
        }
      });
    }

    function go(p, dir) {
      p = Math.max(1, Math.min(N, p));
      if (spreadStart(p) === cur && !dir) return;
      cur = p;
      render(dir || 0);
    }
    function step(d) {
      var pages = pagesOf(spreadStart(cur));
      go(d > 0 ? pages[pages.length - 1] + 1 : spreadStart(cur) - 1, d);
    }

    function buildStrip() {
      var h = '';
      for (var n = 1; n <= N; n++) h += '<button type="button" data-book-go="' + n + '" aria-label="Go to page ' + n + '"><canvas></canvas><span>' + n + '</span></button>';
      strip.innerHTML = h;
      var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (ents) {
        ents.forEach(function (en) {
          if (!en.isIntersecting) return;
          var b = en.target, n = +b.getAttribute('data-book-go');
          io.unobserve(b);
          if (thumbsDone[n]) return;
          thumbsDone[n] = true;
          pdf.getPage(n).then(function (page) {
            var base = page.getViewport({ scale: 1 }), cv = b.querySelector('canvas');
            var vp = page.getViewport({ scale: 96 / base.width });
            cv.width = Math.floor(vp.width); cv.height = Math.floor(vp.height);
            page.render({ canvasContext: cv.getContext('2d'), viewport: vp });
          });
        });
      }, { root: strip, rootMargin: '0px 200px' }) : null;
      strip.querySelectorAll('[data-book-go]').forEach(function (b) { if (io) io.observe(b); });
    }

    // Chapters from the PDF's bookmarks (top level). No bookmarks → no caption.
    function loadOutline() {
      return pdf.getOutline().then(function (items) {
        if (!items || !items.length) return;
        return Promise.all(items.map(function (it) {
          var d = typeof it.dest === 'string' ? pdf.getDestination(it.dest) : Promise.resolve(it.dest);
          return d.then(function (dest) {
            if (!dest || !dest[0]) return null;
            return pdf.getPageIndex(dest[0]).then(function (i) { return { page: i + 1, title: String(it.title || '').trim() }; });
          }).catch(function () { return null; });
        })).then(function (list) {
          chapters = list.filter(function (x) { return x && x.title; }).sort(function (a, b) { return a.page - b.page; });
        });
      }).catch(function () {});
    }

    // Controls
    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-book-go]');
      if (b) return go(+b.getAttribute('data-book-go'), +b.getAttribute('data-book-go') > cur ? 1 : -1);
      if (e.target.closest('[data-book-prev]')) return step(-1);
      if (e.target.closest('[data-book-next]')) return step(1);
      if (e.target.closest('[data-book-full]')) {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (root.requestFullscreen) root.requestFullscreen().catch(function () {});
      }
    });
    var full = $('[data-book-full]');
    if (full && !(document.fullscreenEnabled && root.requestFullscreen)) full.hidden = true;
    document.addEventListener('fullscreenchange', function () { if (root.isConnected) setTimeout(function () { render(0); }, 60); });

    function onKey(e) {
      if (!root.isConnected) { document.removeEventListener('keydown', onKey); return; }
      if (!pdf || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) || e.target.isContentEditable) return;
      if (document.querySelector('.sp-sheet[style*="flex"]')) return;   // an import / edit panel is open
      if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
      if (e.key === 'ArrowLeft')  { e.preventDefault(); step(-1); }
    }
    document.addEventListener('keydown', onKey);

    // Swipe on touch screens
    var tx = null, ty = null;
    stage.addEventListener('touchstart', function (e) { var t = e.changedTouches[0]; tx = t.clientX; ty = t.clientY; }, { passive: true });
    stage.addEventListener('touchend', function (e) {
      if (tx === null) return;
      var t = e.changedTouches[0], dx = t.clientX - tx, dy = t.clientY - ty;
      tx = null;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.4) step(dx < 0 ? 1 : -1);
    }, { passive: true });

    // Re-fit when the viewer changes size (window, sidebar, rotate), keeping the page.
    var rt = 0, lastW = 0, lastH = 0;
    if ('ResizeObserver' in window) {
      new ResizeObserver(function () {
        if (!root.isConnected || !pdf) return;
        if (Math.abs(stage.clientWidth - lastW) < 2 && Math.abs(stage.clientHeight - lastH) < 2) return;
        lastW = stage.clientWidth; lastH = stage.clientHeight;
        clearTimeout(rt); rt = setTimeout(function () { render(0); }, 120);
      }).observe(stage);
    }

    loadPdfJs(function (ok) {
      if (!ok) return fallback();
      window.pdfjsLib.getDocument({ url: root.getAttribute('data-src'), withCredentials: true }).promise.then(function (doc) {
        pdf = doc; N = doc.numPages;
        return doc.getPage(1).then(function (p) {
          var v = p.getViewport({ scale: 1 }); aspect = v.height / v.width;
          root.style.setProperty('--book-aspect', String(aspect));
        });
      }).then(function () {
        buildStrip();
        lastW = stage.clientWidth; lastH = stage.clientHeight;
        render(0);
        return loadOutline().then(function () { render(0); });
      }).catch(function (err) {
        if (window.console) console.warn('[support] guide viewer fell back to the browser viewer:', err);
        fallback();
      });
    });
  }

  function boot() { document.querySelectorAll('[data-book]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
