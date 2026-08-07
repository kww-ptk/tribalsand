/* ===== Enhanced filter dropdown — Tribal Sand admin =====
 * Progressive enhancement for <select class="filter-select">.
 * The native <select> stays in the DOM (so the form still submits and the page
 * works without JS); JS hides it visually and drives a styled button + listbox.
 * Choosing an option updates the native select and fires a real `change` event,
 * so existing `onchange="this.form.submit()"` handlers keep working.
 */
(function () {
  'use strict';

  var CHEVRON =
    '<svg class="eselect__chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" ' +
    'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="m6 9 6 6 6-6"/></svg>';

  function enhance(select) {
    if (select.dataset.enhanced) return;
    select.dataset.enhanced = '1';

    // Measure the native select BEFORE hiding it, so the styled control can keep
    // the layout the page intended: full-width form fields stay full-width; inline
    // filter/table selects keep their compact size.
    var rect    = select.getBoundingClientRect();
    var parentW = (select.parentNode && select.parentNode.clientWidth) || 0;
    var fullWidth = rect.width > 0 && parentW > 0 && rect.width >= parentW * 0.9;

    var wrap = document.createElement('div');
    wrap.className = 'eselect';
    if (fullWidth) wrap.classList.add('eselect--block');
    else if (rect.width > 0) wrap.style.minWidth = Math.ceil(rect.width) + 'px';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('eselect__native');
    select.setAttribute('tabindex', '-1');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'eselect__btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (select.getAttribute('aria-label')) btn.setAttribute('aria-label', select.getAttribute('aria-label'));

    var label = document.createElement('span');
    label.className = 'eselect__label';
    btn.appendChild(label);
    btn.insertAdjacentHTML('beforeend', CHEVRON);
    wrap.appendChild(btn);

    var menu = document.createElement('ul');
    menu.className = 'eselect__menu';
    menu.setAttribute('role', 'listbox');
    menu.tabIndex = -1;
    wrap.appendChild(menu);

    var options = [];
    var open = false;

    function addOpt(opt) {
      var li = document.createElement('li');
      li.className = 'eselect__opt';
      li.setAttribute('role', 'option');
      li.textContent = opt.textContent;
      li.dataset.index = String(opt.index); // index within select.options (optgroup-safe)
      if (opt.disabled) li.setAttribute('aria-disabled', 'true');
      menu.appendChild(li);
      options.push(li);
    }

    function build() {
      menu.innerHTML = '';
      options = [];
      // Walk children so <optgroup> labels render as non-selectable headers.
      Array.prototype.forEach.call(select.children, function (node) {
        if (node.tagName === 'OPTGROUP') {
          var gl = document.createElement('li');
          gl.className = 'eselect__group';
          gl.setAttribute('role', 'presentation');
          gl.textContent = node.label;
          menu.appendChild(gl);
          Array.prototype.forEach.call(node.children, function (opt) {
            if (opt.tagName === 'OPTION') addOpt(opt);
          });
        } else if (node.tagName === 'OPTION') {
          addOpt(node);
        }
      });
      sync();
    }

    function sync() {
      var sel = select.options[select.selectedIndex];
      label.textContent = sel ? sel.textContent : '';
      options.forEach(function (li) {
        var on = (+li.dataset.index === select.selectedIndex);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
        li.classList.toggle('is-selected', on);
      });
    }

    function setActive(i) {
      i = Math.max(0, Math.min(options.length - 1, i));
      options.forEach(function (li) { li.classList.remove('is-active'); });
      if (options[i]) { options[i].classList.add('is-active'); options[i].scrollIntoView({ block: 'nearest' }); }
    }
    function activeIndex() {
      for (var i = 0; i < options.length; i++) if (options[i].classList.contains('is-active')) return i;
      return select.selectedIndex;
    }

    // The menu is position:fixed so it OVERLAYS everything and escapes any
    // overflow-clipping ancestor (e.g. .table-wrap). Fixed elements also don't
    // inflate an ancestor's scroll area, so this kills the phantom scrollbar the
    // old absolute menu caused inside scroll-wrapped tables. Coords are computed
    // from the button each time it opens (and on scroll/resize while open).
    function positionMenu() {
      var r = btn.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var gap = 6;
      var maxH = 288; // matches CSS max-height
      menu.style.left = Math.round(r.left) + 'px';
      menu.style.minWidth = Math.round(r.width) + 'px';
      // Flip above the button when there isn't room below.
      var below = vh - r.bottom;
      if (below < Math.min(maxH, 220) && r.top > below) {
        menu.style.top = 'auto';
        menu.style.bottom = Math.round(vh - r.top + gap) + 'px';
      } else {
        menu.style.bottom = 'auto';
        menu.style.top = Math.round(r.bottom + gap) + 'px';
      }
    }

    function openMenu() {
      if (open) return; open = true;
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      positionMenu();
      setActive(select.selectedIndex);
      document.addEventListener('click', onDoc, true);
      document.addEventListener('keydown', onKey, true);
      window.addEventListener('scroll', positionMenu, true); // capture inner scrollers too
      window.addEventListener('resize', positionMenu);
    }
    function closeMenu() {
      if (!open) return; open = false;
      wrap.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      options.forEach(function (li) { li.classList.remove('is-active'); });
      menu.style.top = '-9999px'; // park it offscreen so it never intercepts anything
      menu.style.bottom = 'auto';
      document.removeEventListener('click', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
      window.removeEventListener('scroll', positionMenu, true);
      window.removeEventListener('resize', positionMenu);
    }
    function choose(i) {
      var opt = select.options[i];
      if (!opt || opt.disabled) return;
      closeMenu();
      if (i !== select.selectedIndex) {
        select.selectedIndex = i;
        sync();
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function onDoc(e) { if (!wrap.contains(e.target)) closeMenu(); }
    function onKey(e) {
      if (!open) return;
      if (e.key === 'Escape') { e.preventDefault(); closeMenu(); btn.focus(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIndex() + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex() - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); choose(activeIndex()); }
      else if (e.key === 'Tab') { closeMenu(); }
    }

    btn.addEventListener('click', function () { open ? closeMenu() : openMenu(); });
    btn.addEventListener('keydown', function (e) {
      if (!open && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openMenu(); }
    });
    menu.addEventListener('click', function (e) {
      var li = e.target.closest('.eselect__opt');
      if (li) choose(+li.dataset.index);
    });
    menu.addEventListener('mousemove', function (e) {
      var li = e.target.closest('.eselect__opt');
      if (li) setActive(+li.dataset.index);
    });

    // Keep the button in sync if the select is changed programmatically elsewhere.
    select.addEventListener('change', sync);
    build();
  }

  // Which selects to enhance: every <select> in the admin content, EXCEPT
  //  • multi-selects / list boxes (this widget is single-select only)
  //  • anything opted out with data-no-enhance
  //  • the hidden native element we ourselves keep in the DOM
  function shouldEnhance(sel) {
    if (sel.multiple) return false;
    if (sel.size > 1) return false;
    if (sel.hasAttribute('data-no-enhance')) return false;
    if (sel.classList.contains('eselect__native')) return false;
    if (sel.dataset.enhanced) return false;
    return true;
  }

  function init(root) {
    root = root || document;
    // Scope to the admin content area when present so we never touch unrelated
    // selects; fall back to the whole root (e.g. an AJAX-swapped fragment).
    var scope = root.querySelector ? (root.querySelector('.admin-content') || root) : root;
    var sels = scope.querySelectorAll ? scope.querySelectorAll('select') : [];
    Array.prototype.forEach.call(sels, function (sel) { if (shouldEnhance(sel)) enhance(sel); });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', function () { init(); });

  // Back-compat name kept (admin-table.js calls this after AJAX swaps); it now
  // enhances every eligible select in the given root, not just .filter-select.
  window.enhanceFilterSelects = init;
  window.enhanceSelects = init;
})();
