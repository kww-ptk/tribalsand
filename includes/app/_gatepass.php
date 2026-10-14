<?php
/**
 * Home: the gate pass card. Once the guest has done online check-in (or none is
 * needed) on a confirmed booking, a small QR + "Show gate pass" opens the
 * full-screen pass (view=pass) to show security at the gate. Before that, a line
 * telling them to finish check-in. Hidden for anything that is not a booking.
 * Expects $hold, $ref, $status. Logic: includes/gate-pass.php.
 */
require_once __DIR__ . '/../gate-pass.php';
if (!in_array($status, ['confirmed', 'pending'], true)) return;
$__gp = gate_pass_ready_for($hold);
$__gpUrl = $__gp['ok'] ? gate_pass_url((int)$hold['id']) : '';
$__pu = '/booking.php?ref=' . urlencode($ref);
if ($__gp['ok'] && $__gpUrl !== ''): ?>
<a class="pa-gp" href="<?= e($__pu) ?>&amp;view=pass">
  <span class="pa-gp__qr" data-gp-qr="<?= e($__gpUrl) ?>" aria-hidden="true"></span>
  <span class="pa-gp__text"><b>Your gate pass</b><span>Show this at the gate when you arrive</span></span>
  <span aria-hidden="true">›</span>
</a>
<script src="/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__ . '/../../js/vendor/qrcode.js') ?: 1 ?>"></script>
<script>
(function () {
  document.querySelectorAll('[data-gp-qr]').forEach(function (el) {
    if (el.firstChild || !window.qrcode) return;
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-gp-qr')); qr.make();
    el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
  });
})();
</script>
<?php elseif (!$__gp['ok'] && $status === 'confirmed' && strpos($__gp['why'], 'check-in') !== false): ?>
<a class="pa-gp pa-gp--wait" href="<?= e($__pu) ?>&amp;view=checkin">
  <span class="pa-gp__text"><b>Gate pass</b><span><?= e($__gp['why']) ?></span></span>
  <span aria-hidden="true">›</span>
</a>
<?php endif; ?>
