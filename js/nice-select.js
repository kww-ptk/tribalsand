/**
 * Nice Select — progressive, accessible, non-native dropdown.
 *
 * Enhances any <select data-nice> into a styled listbox that matches the brand
 * (no native OS dropdown). The original <select> stays in the DOM, visually
 * hidden, as the single source of truth: choosing an option writes its value
 * back and dispatches a native `change` event, so existing form JS and no-JS
 * submission both keep working. Fails safe — if this script never runs, the
 * user just sees a normal native select.
 *
 * Usage:  <select id="foo" name="foo" data-nice> … </select>
 * Re-scan after injecting selects dynamically:  window.NiceSelect.scan(root)
 */
(function () {
  'use strict';

  var OPEN = null; // the currently open instance (only one at a time)

  function closeOpen() {
    if (OPEN) OPEN.close();
  }

  document.addEventListener('click', function (e) {
    if (OPEN && !OPEN.root.contains(e.target)) closeOpen();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && OPEN) { OPEN.trigger.focus(); closeOpen(); }
  });

  function build(select) {
    if (select.dataset.niceReady) return;
    select.dataset.niceReady = '1';

    var root = document.createElement('div');
    root.className = 'ns';

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ns-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    if (select.id) trigger.setAttribute('aria-labelledby', select.id + '-lbl');

    var label = document.createElement('span');
    label.className = 'ns-label';
    var caret = document.createElement('span');
    caret.className = 'ns-caret';
    caret.setAttribute('aria-hidden', 'true');
    trigger.appendChild(label);
    trigger.appendChild(caret);

    var list = document.createElement('ul');
    list.className = 'ns-list';
    list.setAttribute('role', 'listbox');
    if (select.id) list.id = select.id + '-list';

    var options = [];
    Array.prototype.forEach.call(select.options, function (opt, i) {
      var li = document.createElement('li');
      li.className = 'ns-opt';
      li.setAttribute('role', 'option');
      li.textContent = opt.textContent;
      li.dataset.value = opt.value;
      if (opt.disabled) li.setAttribute('aria-disabled', 'true');
      li.addEventListener('click', function () {
        if (opt.disabled) return;
        pick(i);
        close();
        trigger.focus();
      });
      list.appendChild(li);
      options.push(li);
    });

    function syncLabel() {
      var sel = select.options[select.selectedIndex];
      label.textContent = sel ? sel.textContent : '';
      root.classList.toggle('ns--placeholder', !!(sel && sel.value === ''));
      options.forEach(function (li, i) {
        var on = i === select.selectedIndex;
        li.classList.toggle('ns-opt--on', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function pick(i) {
      if (i < 0 || i >= select.options.length) return;
      if (select.options[i].disabled) return;
      select.selectedIndex = i;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncLabel();
    }

    var active = -1;
    function setActive(i) {
      options.forEach(function (li) { li.classList.remove('ns-opt--active'); });
      active = i;
      if (i >= 0 && options[i]) {
        options[i].classList.add('ns-opt--active');
        options[i].scrollIntoView({ block: 'nearest' });
      }
    }

    function open() {
      if (OPEN && OPEN !== inst) OPEN.close();
      root.classList.add('ns--open');
      trigger.setAttribute('aria-expanded', 'true');
      OPEN = inst;
      setActive(select.selectedIndex >= 0 ? select.selectedIndex : 0);
      list.focus();
    }
    function close() {
      root.classList.remove('ns--open');
      trigger.setAttribute('aria-expanded', 'false');
      if (OPEN === inst) OPEN = null;
    }

    trigger.addEventListener('click', function () {
      root.classList.contains('ns--open') ? close() : open();
    });
    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
        e.preventDefault(); open();
      }
    });

    list.tabIndex = -1;
    list.addEventListener('keydown', function (e) {
      var last = options.length - 1;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(last, active + 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(0, active - 1)); }
      else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
      else if (e.key === 'End') { e.preventDefault(); setActive(last); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(active); close(); trigger.focus(); }
      else if (e.key === 'Tab') { close(); }
    });

    // Assemble: hide native select, mount the styled control in its place.
    select.parentNode.insertBefore(root, select);
    root.appendChild(select);
    root.appendChild(trigger);
    root.appendChild(list);
    select.classList.add('ns-native');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');

    // Keep the styled label in sync if the value is changed elsewhere (reset, JS).
    select.addEventListener('change', syncLabel);

    var inst = { root: root, trigger: trigger, close: close };
    syncLabel();
  }

  function scan(root) {
    (root || document).querySelectorAll('select[data-nice]').forEach(build);
  }

  window.NiceSelect = { scan: scan, build: build };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }
})();
