/* ===== Lightweight tooltip system — Tribal Sand admin (Phase 1 #3) =====
 * Replaces native `title=` (slow, unstyled) with a single delegated, theme-token
 * bubble. Opt in with `data-tip="…"`. Keeps `aria-label` for a11y (this is a
 * visual affordance only). Hover/focus driven, so it never fires on touch —
 * which is exactly why we keep it admin-side (desktop) per the plan.
 *
 * Idempotent + re-runnable: exposes window.tsTipInit(root) so AJAX-swapped
 * fragments (the workspace shell, data-tables) get their tips wired too. The
 * actual positioning is fully delegated at the document level, so re-running is
 * only needed to migrate any late-injected `title=` attributes.
 */
(function () {
  'use strict';

  var tip = null, arrow = null, current = null, showTimer = null;

  function ensureEl() {
    if (tip) return;
    tip = document.createElement('div');
    tip.className = 'ts-tip';
    tip.setAttribute('role', 'tooltip');
    arrow = document.createElement('span');
    arrow.className = 'ts-tip__arrow';
    tip.appendChild(arrow);
    var text = document.createElement('span');
    text.className = 'ts-tip__text';
    tip.appendChild(text);
    document.body.appendChild(tip);
  }

  function position(target) {
    var r = target.getBoundingClientRect();
    var tr = tip.getBoundingClientRect();
    var gap = 8;
    // Prefer above; flip below when there isn't room.
    var above = r.top - tr.height - gap;
    var placeBelow = above < 8;
    var top = placeBelow ? r.bottom + gap : above;
    var left = r.left + (r.width - tr.width) / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tr.width - 8));
    tip.style.top = Math.round(top) + 'px';
    tip.style.left = Math.round(left) + 'px';
    // Arrow points at the target centre.
    var ax = r.left + r.width / 2 - left - 4;
    ax = Math.max(8, Math.min(ax, tr.width - 16));
    arrow.style.left = Math.round(ax) + 'px';
    arrow.style.top = placeBelow ? '-4px' : (tr.height - 4) + 'px';
  }

  function show(target) {
    var msg = target.getAttribute('data-tip');
    if (!msg) return;
    ensureEl();
    current = target;
    tip.querySelector('.ts-tip__text').textContent = msg;
    tip.style.opacity = '0';
    tip.classList.remove('is-in');
    // Two frames: render (to measure), then position + fade in.
    requestAnimationFrame(function () {
      if (current !== target) return;
      position(target);
      tip.classList.add('is-in');
      tip.style.opacity = '';
    });
  }

  function hide() {
    current = null;
    if (showTimer) { clearTimeout(showTimer); showTimer = null; }
    if (tip) { tip.classList.remove('is-in'); }
  }

  function onEnter(e) {
    var t = e.target.closest ? e.target.closest('[data-tip]') : null;
    if (!t || t === current) return;
    if (showTimer) clearTimeout(showTimer);
    showTimer = setTimeout(function () { show(t); }, 120);
  }
  function onLeave(e) {
    var t = e.target.closest ? e.target.closest('[data-tip]') : null;
    if (t && t === current) hide();
  }

  document.addEventListener('mouseover', onEnter);
  document.addEventListener('mouseout', onLeave);
  document.addEventListener('focusin', onEnter);
  document.addEventListener('focusout', onLeave);
  // Any scroll/resize invalidates the fixed position — just dismiss.
  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });

  // Migrate `title=` → `data-tip` on eligible controls so hovering doesn't show
  // BOTH the native tooltip and ours. Keeps title's a11y value in aria-label.
  function migrate(root) {
    root = root || document;
    var scope = root.querySelectorAll ? root : document;
    scope.querySelectorAll('.btn-icon[title], .dt-page[title], [data-tip-adopt][title]').forEach(function (el) {
      var t = el.getAttribute('title');
      if (!t) return;
      el.setAttribute('data-tip', t);
      if (!el.getAttribute('aria-label')) el.setAttribute('aria-label', t);
      el.removeAttribute('title');
    });
  }

  function init(root) { migrate(root); }
  window.tsTipInit = init;

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', function () { init(); });
})();
