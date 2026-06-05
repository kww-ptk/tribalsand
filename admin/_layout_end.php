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

</body>
</html>
