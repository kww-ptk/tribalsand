/*
 * Password fields — ONE behaviour for every password / PIN input.
 *
 * Every <input type="password"> on the page (and any that arrives later through
 * the admin shell's no-reload swaps) is enhanced automatically:
 *   • typed characters stay hidden; an eye button shows / hides them
 *     (aria-pressed + a label that says what the button will do);
 *   • a Caps Lock warning while typing;
 *   • a live checklist on new-password fields ("At least 10 characters",
 *     "Passwords match");
 *   • validation on submit — required, minlength, pattern and "must match" —
 *     shown under the field (.field-error, .is-invalid) AND as the reusable
 *     toast (window.tsToast). The server re-checks everything; this is UX only.
 *
 * Opt-ins on the input:
 *   data-pw-match="<name of the other field>"  must equal that field
 *   data-pw-label="New PIN"                    name used in messages
 *   data-pw-pattern-msg="PIN must be 4–6 digits."
 *   data-pw-rules="off"                        no live checklist
 * A submit button with `formnovalidate` (e.g. "Remove PIN") skips the checks.
 *
 * Loaded by admin/_layout.php, admin/login.php, admin/reset-password.php and
 * agent/_layout.php. Styles: `.pwfield*` in admin/assets/admin.css.
 */
(function () {
  'use strict';
  if (window.__tsPasswordBound) return;
  window.__tsPasswordBound = true;

  var EYE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" x2="22" y1="2" y2="22"/></svg>';

  // ── Toast: the admin layout's one, else the same markup (admin.css styles it) ──
  function fallbackToast(message, type) {
    var wrap = document.getElementById('ts-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ts-toasts'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'ts-toast ts-toast--' + (type === 'err' ? 'err' : 'ok');
    t.setAttribute('role', type === 'err' ? 'alert' : 'status');
    var icon = document.createElement('span'); icon.className = 'ts-toast__icon'; icon.setAttribute('aria-hidden', 'true');
    icon.textContent = type === 'err' ? '✕' : '✓';
    var msg = document.createElement('span'); msg.className = 'ts-toast__msg'; msg.textContent = message;
    var x = document.createElement('button'); x.type = 'button'; x.className = 'ts-toast__x';
    x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    t.appendChild(icon); t.appendChild(msg); t.appendChild(x);
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    var timer;
    function remove() { clearTimeout(timer); t.classList.remove('is-in'); t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 260); }
    x.addEventListener('click', remove);
    timer = setTimeout(remove, type === 'err' ? 6500 : 4000);
  }
  if (typeof window.tsToast !== 'function') {
    window.tsToast = fallbackToast;
    // Pages without the admin layout (login, reset, agent portal): their
    // server flash (.alert.is-flash) becomes the same toast.
    var flashes = function () {
      document.querySelectorAll('.alert.is-flash').forEach(function (al) {
        var text = al.textContent.trim();
        var type = al.classList.contains('alert--error') ? 'err' : 'ok';
        al.remove();
        if (text) fallbackToast(text, type);
      });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', flashes); else flashes();
  }
  function toast(msg, type) { window.tsToast(msg, type); }

  // ── Helpers ──
  function isPin(inp) { return (inp.getAttribute('inputmode') || '') === 'numeric'; }
  function noun(inp) {
    return inp.getAttribute('data-pw-label') || (isPin(inp) ? 'PIN' : 'password');
  }
  function lc(s) { return s.charAt(0).toLowerCase() + s.slice(1); }
  function partner(inp) {
    var n = inp.getAttribute('data-pw-match');
    if (!n || !inp.form) return null;
    return inp.form.querySelector('[name="' + n + '"]');
  }
  function isNew(inp) {
    return inp.getAttribute('autocomplete') === 'new-password' || inp.hasAttribute('data-pw-match')
      || inp.hasAttribute('minlength');
  }
  function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

  function fieldOf(inp) { return inp.closest('.pwfield'); }
  // The pieces printed after the field (error, Caps Lock note, checklist) are
  // its next siblings, all carrying a pwfield__ / pwrules class.
  function sibling(inp, cls) {
    var n = (fieldOf(inp) || inp).nextElementSibling;
    while (n && (n.classList.contains('pwrules') || String(n.className).indexOf('pwfield__') !== -1)) {
      if (n.classList.contains(cls)) return n;
      n = n.nextElementSibling;
    }
    return null;
  }
  function errEl(inp) { return sibling(inp, 'pwfield__error'); }
  function setError(inp, msg) {
    var w = fieldOf(inp) || inp;
    inp.classList.add('is-invalid');
    inp.setAttribute('aria-invalid', 'true');
    var e = errEl(inp);
    if (!e) {
      e = document.createElement('span');
      e.className = 'field-error pwfield__error';
      e.id = (inp.id || ('pw' + Math.random().toString(36).slice(2, 8))) + '-err';
      e.setAttribute('role', 'alert');
      w.parentNode.insertBefore(e, w.nextSibling);
    }
    e.textContent = msg;
    var desc = (inp.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    if (desc.indexOf(e.id) < 0) { desc.push(e.id); inp.setAttribute('aria-describedby', desc.join(' ')); }
  }
  function clearError(inp) {
    inp.classList.remove('is-invalid');
    inp.removeAttribute('aria-invalid');
    var e = errEl(inp);
    if (e) e.remove();
  }

  // The first problem with one field, or '' when it is fine.
  function problem(inp) {
    var v = inp.value;
    var what = noun(inp);
    if (v === '') {
      if (!inp.required) return '';
      if (inp.hasAttribute('data-pw-match')) return 'Please re-enter the ' + lc(what) + '.';
      return 'Please enter ' + (/^[aeiou]/i.test(what) ? 'an ' : 'a ') + lc(what) + '.';
    }
    var min = parseInt(inp.getAttribute('minlength') || '0', 10);
    if (min && v.length < min) {
      return what.charAt(0).toUpperCase() + what.slice(1) + ' must be at least ' + min + ' characters.';
    }
    var pat = inp.getAttribute('pattern');
    if (pat) {
      var ok = false;
      try { ok = new RegExp('^(?:' + pat + ')$').test(v); } catch (e) { ok = true; }
      if (!ok) return inp.getAttribute('data-pw-pattern-msg') || inp.getAttribute('title') || 'Please check the ' + lc(what) + '.';
    }
    var other = partner(inp);
    if (other && other.value !== v) return (isPin(inp) ? 'The PINs' : 'The passwords') + ' don’t match.';
    return '';
  }

  // ── Live checklist (new passwords) ──
  function rulesFor(inp) {
    var rules = [];
    var min = parseInt(inp.getAttribute('minlength') || '0', 10);
    if (min) rules.push({ key: 'len', text: 'At least ' + min + ' characters', test: function () { return inp.value.length >= min; } });
    if (inp.hasAttribute('data-pw-match')) {
      rules.push({ key: 'match', text: (isPin(inp) ? 'PINs' : 'Passwords') + ' match', test: function () { var o = partner(inp); return !!o && inp.value !== '' && o.value === inp.value; } });
    }
    return rules;
  }
  function renderRules(inp) {
    var list = inp.__pwRules;
    if (!list) return;
    var started = inp.value !== '';
    list.hidden = !started;
    list.querySelectorAll('li').forEach(function (li, i) {
      var ok = inp.__pwRuleDefs[i].test();
      li.classList.toggle('is-ok', ok);
      li.querySelector('.pwrules__mark').textContent = ok ? '✓' : '•';
    });
  }

  // ── Enhance one input ──
  function enhance(inp) {
    if (inp.__pwReady || inp.type !== 'password') return;
    inp.__pwReady = true;

    var wrap = document.createElement('span');
    wrap.className = 'pwfield';
    // A full-width field keeps its width; an inline one (a table row) stays inline.
    var inline = !inp.closest('.field') && !/100%/.test(inp.style.width || '');
    if (inline) wrap.classList.add('pwfield--inline');
    if (inp.classList.contains('inp--sm')) wrap.classList.add('pwfield--sm');
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    if (!inp.getAttribute('autocomplete')) {
      inp.setAttribute('autocomplete', isNew(inp) ? 'new-password' : 'current-password');
    }
    inp.setAttribute('autocapitalize', 'off');
    inp.setAttribute('spellcheck', 'false');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pwfield__eye';
    btn.setAttribute('aria-pressed', 'false');
    if (inp.id) btn.setAttribute('aria-controls', inp.id);
    var label = function (shown) {
      var l = (shown ? 'Hide ' : 'Show ') + lc(noun(inp));
      btn.setAttribute('aria-label', l);
      btn.setAttribute('title', l);
      btn.innerHTML = shown ? EYE_OFF : EYE;
    };
    label(false);
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep focus + caret in the field
    btn.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      label(show);
      var end = inp.value.length;
      try { inp.focus({ preventScroll: true }); inp.setSelectionRange(end, end); } catch (e) { /* number-like inputs */ }
    });
    wrap.appendChild(btn);

    // Caps Lock warning (passwords only — a PIN pad has no letters).
    if (!isPin(inp)) {
      var caps = document.createElement('span');
      caps.className = 'pwfield__caps';
      caps.textContent = 'Caps Lock is on';
      caps.hidden = true;
      caps.setAttribute('role', 'status');
      wrap.parentNode.insertBefore(caps, wrap.nextSibling);
      var capsCheck = function (e) {
        if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock');
      };
      inp.addEventListener('keydown', capsCheck);
      inp.addEventListener('keyup', capsCheck);
      inp.addEventListener('blur', function () { caps.hidden = true; });
    }

    // Live checklist on new-password fields with something to check.
    var defs = isNew(inp) && inp.getAttribute('data-pw-rules') !== 'off' && !inline ? rulesFor(inp) : [];
    if (defs.length) {
      var ul = document.createElement('ul');
      ul.className = 'pwrules';
      ul.setAttribute('aria-live', 'polite');
      ul.hidden = true;
      defs.forEach(function (d) {
        var li = document.createElement('li');
        li.innerHTML = '<span class="pwrules__mark" aria-hidden="true">•</span> ';
        li.appendChild(document.createTextNode(d.text));
        ul.appendChild(li);
      });
      wrap.parentNode.insertBefore(ul, wrap.nextSibling);
      inp.__pwRules = ul;
      inp.__pwRuleDefs = defs;
    }

    inp.addEventListener('input', function () {
      if (inp.classList.contains('is-invalid') && !problem(inp)) clearError(inp);
      renderRules(inp);
      // Typing in the first password re-checks its confirmation partner.
      if (inp.form) {
        inp.form.querySelectorAll('[data-pw-match="' + inp.name + '"]').forEach(function (c) {
          renderRules(c);
          if (c.classList.contains('is-invalid') && !problem(c)) clearError(c);
        });
      }
    });
    // Once a field has been left, tell the person straight away (not on every key).
    inp.addEventListener('blur', function () {
      if (inp.value === '') return;               // an untouched field waits for submit
      var p = problem(inp);
      if (p) setError(inp, p); else clearError(inp);
    });
  }

  function scan(root) {
    (root || document).querySelectorAll('input[type="password"]').forEach(enhance);
  }

  // Check every password field of a form: errors under each, and the first
  // problem (field + message) for the toast — or null when all is well.
  function checkForm(form) {
    var fields = Array.prototype.filter.call(form.querySelectorAll('input'), function (i) {
      return i.__pwReady && !i.disabled && visible(i);
    });
    var first = null;
    fields.forEach(function (inp) {
      var p = problem(inp);
      if (p) { setError(inp, p); if (!first) first = { inp: inp, msg: p }; } else clearError(inp);
    });
    return first;
  }
  function report(first) {
    toast(first.msg, 'err');
    try { first.inp.focus(); } catch (err) { /* hidden */ }
  }

  // ── Validate on submit — window capture runs before the admin shell, the
  // styled confirm and the double-submit guard, so a bad form never posts. ──
  window.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (e.submitter && e.submitter.hasAttribute('formnovalidate')) return;
    var first = checkForm(form);
    if (!first) return;
    e.preventDefault();
    e.stopPropagation();
    report(first);
  }, true);

  // Forms without `novalidate` run the browser's own check first, which would
  // pop a native bubble and never fire `submit`. Catch it for our fields and
  // show the same inline errors + ONE toast instead.
  var pending = null;
  document.addEventListener('invalid', function (e) {
    var inp = e.target;
    if (!inp || !inp.__pwReady) return;
    e.preventDefault();
    if (pending || !inp.form) return;
    pending = inp.form;
    setTimeout(function () {
      var form = pending; pending = null;
      var first = checkForm(form);
      if (!first) first = { inp: inp, msg: inp.validationMessage || 'Please check this field.' };
      report(first);
    }, 0);
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); });
  else scan();
  // Admin shell swaps (and anything else that injects a form) — enhance new fields.
  if (window.MutationObserver) {
    var queued = false;
    new MutationObserver(function (muts) {
      if (queued) return;
      for (var i = 0; i < muts.length; i++) {
        if (muts[i].addedNodes.length) {
          queued = true;
          setTimeout(function () { queued = false; scan(document); }, 0);
          return;
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
  window.tsPasswordScan = scan;
})();
