/* ===== Copy-to-clipboard for portal links — Tribal Sand admin =====
 * Delegated on `.copy-link` (carrying data-link), so it works across AJAX-swapped
 * table bodies and the workspace shell. Two feedback styles:
 *   • icon buttons (data-copy-icon): flash a check glyph + "Copied!" tooltip
 *   • text buttons: swap the label to "Copied!" briefly
 */
(function () {
  'use strict';

  var COPIED = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

  function flash(btn) {
    if (btn.dataset.copyBusy) return;
    btn.dataset.copyBusy = '1';
    if (btn.hasAttribute('data-copy-icon')) {
      var original = btn.innerHTML;
      var tip = btn.getAttribute('data-tip');
      btn.innerHTML = COPIED;
      btn.classList.add('is-copied');
      btn.setAttribute('data-tip', 'Copied!');
      setTimeout(function () {
        btn.innerHTML = original;
        btn.classList.remove('is-copied');
        if (tip) btn.setAttribute('data-tip', tip);
        delete btn.dataset.copyBusy;
      }, 1500);
    } else {
      var t = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = t; delete btn.dataset.copyBusy; }, 1500);
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.copy-link') : null;
    if (!btn) return;
    var link = btn.getAttribute('data-link');
    if (!link) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(link).then(function () { flash(btn); })
        .catch(function () { window.prompt('Copy this portal link:', link); });
    } else {
      window.prompt('Copy this portal link:', link);
    }
  });
})();
