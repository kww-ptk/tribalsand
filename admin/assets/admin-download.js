/**
 * Download a file in the background — no page navigation, so the browser tab
 * never shows the whole-page loading spinner while the server builds the file.
 *
 *   <a href="/admin/x.php?download=1" data-fetch-download [data-download-current]
 *      data-download-busy="Preparing ZIP…">Download</a>
 *
 * The link is fetched with the session cookie, read as a blob (with a % while
 * it arrives, when the server sends Content-Length) and saved under the name
 * from Content-Disposition. The button shows its progress and is disabled
 * meanwhile; a failure is a toast and the button comes back. With JavaScript
 * off the link simply downloads as before.
 *
 * data-download-current: take the filters from the CURRENT address (a list
 * whose search box swaps the table without reloading has moved on since the
 * link was rendered), adding the link's own extra params (download=1).
 *
 * Bound once per window (shell page swaps keep the listener; it is delegated).
 */
(function () {
  'use strict';
  if (window.__tsFetchDownload) return;
  window.__tsFetchDownload = true;

  function toast(msg, type) {
    if (typeof window.tsToast === 'function') window.tsToast(msg, type);
    else if (type === 'err') window.alert(msg);
  }

  function fileName(res, href) {
    var cd = res.headers.get('Content-Disposition') || '';
    var m = /filename\*=UTF-8''([^;]+)/i.exec(cd);
    if (m) { try { return decodeURIComponent(m[1]); } catch (e) {} }
    m = /filename="?([^";]+)"?/i.exec(cd);
    if (m) return m[1];
    return (href.split('?')[0].split('/').pop() || 'download');
  }

  // A failed request returns an HTML/text page — show its words, not its markup.
  function errorText(res, body) {
    var t = String(body || '').replace(/<style[\s\S]*?<\/style>|<script[\s\S]*?<\/script>/gi, ' ')
      .replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (t.length > 200) t = t.slice(0, 200) + '…';
    return 'Download failed (' + res.status + ')' + (t ? ': ' + t : '.');
  }

  async function readBlob(res, onProgress) {
    var total = parseInt(res.headers.get('Content-Length') || '0', 10);
    if (!res.body || !res.body.getReader || !total) return res.blob();
    var reader = res.body.getReader(), chunks = [], got = 0;
    for (;;) {
      var step = await reader.read();
      if (step.done) break;
      chunks.push(step.value); got += step.value.length;
      onProgress(Math.min(99, Math.floor(got * 100 / total)));
    }
    return new Blob(chunks, { type: res.headers.get('Content-Type') || 'application/octet-stream' });
  }

  function currentHref(a) {
    var link = new URL(a.getAttribute('href'), location.href);
    if (!a.hasAttribute('data-download-current') || link.pathname !== location.pathname) return link.href;
    var q = new URLSearchParams(location.search);
    ['page', 'per', 'ajax', 'shell'].forEach(function (k) { q.delete(k); });
    link.searchParams.forEach(function (v, k) { if (!q.has(k)) q.set(k, v); });
    q.set('download', link.searchParams.get('download') || '1');
    return link.pathname + '?' + q.toString();
  }

  async function run(a) {
    var href = currentHref(a);
    var label = a.innerHTML;
    var busy = a.getAttribute('data-download-busy') || 'Preparing…';
    var set = function (text) { a.textContent = text; };
    a.setAttribute('aria-busy', 'true'); a.classList.add('is-busy');
    set(busy);
    try {
      var res = await fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
      var type = res.headers.get('Content-Type') || '';
      if (!res.ok || /text\/html/i.test(type)) throw new Error(errorText(res, await res.text()));
      var blob = await readBlob(res, function (pct) { set('Downloading ' + pct + '%'); });
      var url = URL.createObjectURL(blob);
      var tmp = document.createElement('a');
      tmp.href = url; tmp.download = fileName(res, href); tmp.style.display = 'none';
      document.body.appendChild(tmp); tmp.click(); tmp.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
      toast('Downloaded ' + tmp.download);
    } catch (err) {
      toast(err && err.message ? err.message : 'Download failed — check your connection and try again.', 'err');
    } finally {
      a.innerHTML = label; a.removeAttribute('aria-busy'); a.classList.remove('is-busy');
    }
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-fetch-download]');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (!window.fetch || !window.URL || !URL.createObjectURL) return;   // old browser: plain link
    e.preventDefault();
    if (a.getAttribute('aria-busy') === 'true') return;                 // one download at a time
    run(a);
  });
})();
