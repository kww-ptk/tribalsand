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

<!-- Reusable confirm dialog: any <form data-confirm="…"> opens this instead of window.confirm() -->
<div class="confirm-overlay" id="confirmOverlay" aria-hidden="true">
  <div class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirmMsg">
    <p class="confirm-dialog__msg" id="confirmMsg"></p>
    <div class="confirm-dialog__actions">
      <button type="button" class="btn-outline btn-sm" id="confirmCancel">Cancel</button>
      <button type="button" class="btn-danger btn-sm" id="confirmOk">Confirm</button>
    </div>
  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('confirmOverlay');
  if (!overlay) return;
  var msgEl = document.getElementById('confirmMsg');
  var okBtn = document.getElementById('confirmOk');
  var cancelBtn = document.getElementById('confirmCancel');
  var pending = null;

  function open(form) {
    pending = form;
    msgEl.textContent = form.getAttribute('data-confirm') || 'Are you sure?';
    okBtn.textContent = form.getAttribute('data-confirm-yes') || 'Confirm';
    overlay.classList.add('is-open');
    okBtn.focus();
  }
  function close() { overlay.classList.remove('is-open'); pending = null; }

  // Capture phase so this runs before a form's own submit listeners (e.g. the "…" state).
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!(f instanceof HTMLFormElement) || !f.hasAttribute('data-confirm')) return;
    if (f.dataset.confirmed) return;           // already confirmed → let it through
    e.preventDefault();
    open(f);
  }, true);

  okBtn.addEventListener('click', function () {
    if (!pending) return;
    pending.dataset.confirmed = '1';
    var btn = pending.querySelector('button[name], button[type=submit], button');
    var f = pending;
    close();
    btn ? btn.click() : f.submit();            // click() keeps the button's name/value in the POST
  });
  cancelBtn.addEventListener('click', close);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
  });
})();
</script>

</body>
</html>
