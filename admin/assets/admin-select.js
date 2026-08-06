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

    var wrap = document.createElement('div');
    wrap.className = 'eselect';
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

    function build() {
      menu.innerHTML = '';
      options = [];
      Array.prototype.forEach.call(select.options, function (opt, i) {
        var li = document.createElement('li');
        li.className = 'eselect__opt';
        li.setAttribute('role', 'option');
        li.textContent = opt.textContent;
        li.dataset.index = String(i);
        if (opt.disabled) li.setAttribute('aria-disabled', 'true');
        menu.appendChild(li);
        options.push(li);
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

    function openMenu() {
      if (open) return; open = true;
      wrap.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      setActive(select.selectedIndex);
      document.addEventListener('click', onDoc, true);
      document.addEventListener('keydown', onKey, true);
    }
    function closeMenu() {
      if (!open) return; open = false;
      wrap.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
      options.forEach(function (li) { li.classList.remove('is-active'); });
      document.removeEventListener('click', onDoc, true);
      document.removeEventListener('keydown', onKey, true);
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

  function init(root) {
    (root || document).querySelectorAll('select.filter-select').forEach(enhance);
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', function () { init(); });

  window.enhanceFilterSelects = init;
})();
