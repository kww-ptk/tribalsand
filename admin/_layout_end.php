<?php
// No-flicker shell (#18): in `?shell=1` mode we captured just the page content in
// _layout.php — emit it as a fragment (+ menu/title metadata) and stop before any
// chrome or the site-wide script layer (both already live on the parent page).
if (!empty($__shellFrag)) {
    admin_shell_emit(ob_get_clean(), (string)($activeMenu ?? ''), (string)($pageTitle ?? 'Admin') . ' — Tribal Sand Admin');
}
?>
    </div><!-- /.admin-content -->
  </main><!-- /.admin-main -->

</div><!-- /.admin-wrap -->

<script>
(function () {
  var burger  = document.getElementById('sidebarBurger');
  var sidebar = document.getElementById('adminSidebar');
  var overlay = document.getElementById('sidebarOverlay');
  if (!burger || !sidebar || !overlay) return;

  function open() {
    sidebar.classList.add('is-open');
    overlay.classList.add('is-visible');
    burger.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }
  function close() {
    sidebar.classList.remove('is-open');
    overlay.classList.remove('is-visible');
    burger.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  burger.addEventListener('click', function () {
    sidebar.classList.contains('is-open') ? close() : open();
  });
  overlay.addEventListener('click', close);

  // Close on nav link tap (mobile)
  sidebar.querySelectorAll('.sidebar__link').forEach(function (link) {
    link.addEventListener('click', close);
  });
})();
</script>

<script>
/* Admin UX layer: styled confirm dialog, "Working…" feedback, self-clearing banners. */
(function () {
  // --- Styled confirm (reusable replacement for the browser's confirm popup) ---
  function styledConfirm(message, onYes) {
    var back = document.createElement('div');
    back.className = 'adm-confirm-back';
    back.innerHTML =
      '<div class="adm-confirm" role="dialog" aria-modal="true" aria-label="Please confirm">' +
        '<h3 class="adm-confirm__title">Please confirm</h3>' +
        '<p class="adm-confirm__body"></p>' +
        '<div class="adm-confirm__actions">' +
          '<button type="button" class="btn-outline btn-sm" data-c-no>Cancel</button>' +
          '<button type="button" class="btn-danger btn-sm" data-c-yes>Confirm</button>' +
        '</div>' +
      '</div>';
    back.querySelector('.adm-confirm__body').textContent = message;
    document.body.appendChild(back);
    document.body.style.overflow = 'hidden';
    var yes = back.querySelector('[data-c-yes]');
    yes.focus();
    function close() { back.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    back.querySelector('[data-c-no]').addEventListener('click', close);
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    document.addEventListener('keydown', onKey);
    yes.addEventListener('click', function () { close(); onYes(); });
  }
  // Exposed so other admin scripts (e.g. gallery bulk-delete) can raise the same
  // no-native confirm dialog programmatically instead of the browser's confirm().
  window.tsConfirm = styledConfirm;

  // Show "Working…" on the button WITHOUT dropping its submitted value.
  // A disabled submitter is excluded from the POST, so we first mirror its
  // name/value into a hidden input, then disable it. (This is the fix for the
  // old "action quietly lost because the button switched off too early" bug.)
  function armSubmit(form, btn) {
    if (btn && btn.name) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
      form.appendChild(h);
    }
    // Icon-only buttons have no text to swap — just show a muted "busy" state so
    // the SVG glyph survives (replacing textContent would delete the <svg>).
    if (btn && btn.classList.contains('btn-icon')) { btn.classList.add('is-loading'); btn.disabled = true; return; }
    if (btn) { btn.dataset.label = btn.textContent; btn.disabled = true; btn.textContent = 'Working…'; }
  }

  // --- Auto-upgrade native confirm() dialogs to the styled modal (site-wide) ---
  // Reroutes any inline onclick="return confirm('…')" (on a button) or
  // onsubmit="return confirm('…')" (on a form) through styledConfirm, so every
  // admin page gets the same polished dialog with no per-page markup changes.
  //
  // Wrapped in wire(root) so it can be re-applied to content injected later by
  // the data-table AJAX layer (admin-table.js). Each element is marked once so
  // re-running over an overlapping subtree never double-binds. Exposed as
  // window.tsAdminWire(root) for admin-table.js to call after a body swap.
  function confirmMsg(attr) {
    var m = attr && attr.match(/confirm\(\s*(['"])([\s\S]*?)\1\s*\)/);
    if (!m) return null;
    return m[2].replace(/\\n/g, '\n').replace(/\\(['"])/g, '$1');
  }
  function wire(root) {
    root = root || document;

    root.querySelectorAll('button[onclick*="confirm("]').forEach(function (btn) {
      var msg = confirmMsg(btn.getAttribute('onclick'));
      if (!msg) return;
      btn.removeAttribute('onclick');
      btn.setAttribute('data-confirm', msg);   // handled by the block just below
    });
    root.querySelectorAll('form[onsubmit*="confirm("]').forEach(function (form) {
      var msg = confirmMsg(form.getAttribute('onsubmit'));
      if (!msg) return;
      form.removeAttribute('onsubmit');
      form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed) return;   // second pass (programmatic submit) — let it through
        e.preventDefault();
        var btn = e.submitter;
        styledConfirm(msg, function () {
          form.dataset.confirmed = '1';
          if (btn && !btn.hasAttribute('data-confirm')) armSubmit(form, btn);
          form.submit();
        });
      });
    });

    // Buttons that need confirmation first (e.g. Decline).
    root.querySelectorAll('button[data-confirm]').forEach(function (btn) {
      if (btn.dataset.confirmWired) return;
      btn.dataset.confirmWired = '1';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var form = btn.form;
        styledConfirm(btn.getAttribute('data-confirm'), function () {
          armSubmit(form, btn);
          form.submit(); // native submit() skips the submit event below — no double-arm
        });
      });
    });

    // Remaining request-action forms (Accept / Mark done): "Working…" on submit.
    root.querySelectorAll('form[action$="booking-request-action.php"]').forEach(function (form) {
      if (form.dataset.armWired) return;
      form.dataset.armWired = '1';
      form.addEventListener('submit', function (e) {
        var btn = e.submitter;
        if (btn && !btn.hasAttribute('data-confirm')) armSubmit(form, btn);
      });
    });
  }
  wire(document);
  window.tsAdminWire = wire;

  // --- Generic double-submit guard (site-wide) ---------------------------------
  // A fast double-click (or Enter + click) on any plain create/save form posts
  // twice — the cause of the "task created twice" bug. After the FIRST real submit
  // of a form, disable its submitter so a second one can't fire. We skip forms
  // whose submit was already handled/prevented (the workspace AJAX shell and the
  // styled-confirm flow manage their own buttons), and mirror the submitter's
  // name/value first so a named button (e.g. status=done) isn't dropped from POST.
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;              // handled by another layer (AJAX / confirm)
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitGuarded) { e.preventDefault(); return; } // second attempt — block it
    form.dataset.submitGuarded = '1';
    var btn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn && !btn.disabled) {
      if (btn.name && !form.querySelector('input[type="hidden"][data-guard-mirror="' + btn.name + '"]')) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
        h.setAttribute('data-guard-mirror', btn.name);
        form.appendChild(h);
      }
      if (btn.classList.contains('btn-icon')) { btn.classList.add('is-loading'); btn.disabled = true; }
      else if (!btn.dataset.label) { btn.dataset.label = btn.textContent; btn.disabled = true; btn.textContent = 'Working…'; }
    }
    // Safety net: if the submit didn't actually navigate away (rare), let the form
    // be usable again rather than trapping the user.
    setTimeout(function () {
      form.removeAttribute('data-submit-guarded');
      if (btn) {
        btn.disabled = false;
        btn.classList.remove('is-loading');
        if (btn.dataset.label) { btn.textContent = btn.dataset.label; delete btn.dataset.label; }
      }
    }, 8000);
  });

  // Toast — a small card that slides up from the bottom, then fades out.
  function toast(message, type) {
    var wrap = document.getElementById('ts-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ts-toasts'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'ts-toast ts-toast--' + (type === 'err' ? 'err' : 'ok');
    t.setAttribute('role', 'status');
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
  // Exposed so the workspace AJAX shell (admin-nav.js) can surface a server flash
  // as a toast after a no-reload action.
  window.tsToast = toast;

  // Turn the server-rendered flash message (from an action) into a toast.
  document.querySelectorAll('.alert.is-flash').forEach(function (al) {
    var type = al.classList.contains('alert--error') ? 'err' : 'ok';
    var text = al.textContent.trim();
    al.remove();
    if (text) toast(text, type);
  });
})();
</script>

</body>
</html>
