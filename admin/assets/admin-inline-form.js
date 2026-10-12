/* ===== Inline forms always have a close (×) — Tribal Sand admin =====
 * A form that a button opens INSIDE the page (New booking, Add highlight, Sign in
 * visitor…) is marked on its panel with `data-inline-form`. This script puts an ×
 * in the panel's header (its .card__head, or the <summary> of a <details>) and
 * closes it:
 *   • data-close-href="…" → go there (server-rendered forms opened by ?new=1);
 *   • a <details> panel   → close it;
 *   • otherwise           → press the page's own toggle (any [aria-controls=<id>]
 *     button, or the selector in data-inline-toggle) so the page's state stays
 *     right, then hide the panel the way the page shows it (style.display when the
 *     page uses that, else the `hidden` attribute) if it is still open.
 * Panels that already have a close control (data-inline-close) are left alone.
 * Runs on load and again whenever the admin shell swaps the page content.
 */
(function () {
  'use strict';

  var X = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

  function head(panel) {
    for (var i = 0; i < panel.children.length; i++) {
      var c = panel.children[i];
      if (c.tagName === 'SUMMARY' || c.classList.contains('card__head')) return c;
    }
    return null;
  }

  function wire(root) {
    (root || document).querySelectorAll('[data-inline-form]').forEach(function (panel) {
      if (panel.querySelector('[data-inline-close]')) return;
      var h = head(panel);
      if (!h) return;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn-icon inline-form__close';
      b.setAttribute('data-inline-close', '');
      b.setAttribute('aria-label', 'Close');
      b.setAttribute('data-tip', 'Close');
      b.innerHTML = X;
      h.appendChild(b);
      h.classList.add('inline-form__head');
    });
  }

  function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

  function close(panel) {
    var href = panel.getAttribute('data-close-href');
    if (href) {
      if (window.tsShellGo) window.tsShellGo(href); else location.href = href;
      return;
    }
    if (panel.tagName === 'DETAILS') { panel.open = false; return; }

    var toggles = [];
    if (panel.id) document.querySelectorAll('[aria-controls="' + panel.id + '"]').forEach(function (t) { toggles.push(t); });
    var sel = panel.getAttribute('data-inline-toggle');
    if (sel) document.querySelectorAll(sel).forEach(function (t) { if (toggles.indexOf(t) < 0) toggles.push(t); });

    var first = toggles.filter(visible)[0] || toggles[0];
    if (first) first.click();
    if (visible(panel)) {
      if (/display\s*:/.test(panel.getAttribute('style') || '')) panel.style.display = 'none';
      else panel.hidden = true;
    }
    toggles.forEach(function (t) { if (t.hasAttribute('aria-expanded')) t.setAttribute('aria-expanded', 'false'); });
    if (first && visible(first)) {
      var r = first.getBoundingClientRect();
      if (r.top < 0) first.scrollIntoView({ block: 'nearest' });
    }
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-inline-close]') : null;
    if (!b) return;
    var panel = b.closest('[data-inline-form]');
    if (!panel) return;
    e.preventDefault();      // inside a <summary> this would otherwise toggle the <details>
    e.stopPropagation();
    if (window.tsTipHide) window.tsTipHide();
    close(panel);
  });

  function start() {
    wire(document);
    var c = document.querySelector('.admin-content');
    if (c && 'MutationObserver' in window) {
      var t;
      new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { wire(document); }, 40); })
        .observe(c, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
  window.tsInlineFormWire = wire;
})();
