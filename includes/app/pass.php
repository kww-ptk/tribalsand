<?php
/**
 * The gate pass, full screen: a big QR code the gate guard scans, with the
 * party, where they are going, the dates and the booking code (what the guard
 * types when a phone can't be scanned). The QR only names the booking — the
 * guard's signed-in Gate page decides what happens. Expects $hold, $ref, $status.
 */
require_once __DIR__ . '/../gate-pass.php';
$__gp  = gate_pass_ready_for($hold);
$__pu  = '/booking.php?ref=' . urlencode($ref);
$__bk  = $__gp['ok'] ? gate_pass_booking((int)$hold['id']) : null;
$__url = $__bk ? gate_pass_url((int)$hold['id']) : '';
?>
<?php if (!$__bk || $__url === ''): ?>
<div class="pa-card" style="padding:18px">
  <b>Gate pass</b>
  <p class="pa-note" style="margin:8px 0 12px"><?= e($__gp['why'] !== '' ? $__gp['why'] : 'Your gate pass isn’t available yet.') ?></p>
  <?php if (strpos($__gp['why'], 'check-in') !== false): ?><a class="pa-btn pa-btn--primary" href="<?= e($__pu) ?>&amp;view=checkin">Go to check-in</a><?php endif; ?>
</div>
<?php else: $__p = $__bk['party']; ?>
<section class="pa-pass">
  <div class="pa-pass__eyebrow">Gate pass</div>
  <div class="pa-pass__qr" data-gp-qr="<?= e($__url) ?>" role="img" aria-label="Gate pass QR code"></div>
  <div class="pa-pass__code"><span>Booking code</span><b><?= e((string)($__bk['access_code'] ?: '—')) ?></b></div>
  <h2 class="pa-pass__name"><?= e((string)($__bk['guest_name'] ?: 'Guest')) ?></h2>
  <div class="pa-pass__party"><?= e(gate_party_label($__p)) ?></div>
  <dl class="pa-pass__facts">
    <div><dt>Going to</dt><dd><?= e(trim(((string)($__bk['venue_name'] ?? '')) . ' · ' . ((string)($__bk['room_name'] ?? '')), ' ·')) ?></dd></div>
    <div><dt>Stay</dt><dd><?= e(date('D j M', strtotime((string)$__bk['check_in'])) . ' – ' . date('D j M Y', strtotime((string)$__bk['check_out']))) ?></dd></div>
  </dl>
  <?php if ($__p['names']): ?>
  <ul class="pa-pass__names"><?php foreach ($__p['names'] as $g): ?><li><?= e($g['name']) ?><?= $g['child'] ? ' (child)' : '' ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <p class="pa-pass__hint">Show this screen to security at the gate. Turn your screen brightness up if the scan doesn’t work.</p>
</section>
<a class="pa-btn" href="<?= e($__pu) ?>&amp;view=home" style="margin-top:12px">Back to my stay</a>
<script src="/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__ . '/../../js/vendor/qrcode.js') ?: 1 ?>"></script>
<script>
(function () {
  document.querySelectorAll('[data-gp-qr]').forEach(function (el) {
    if (el.firstChild || !window.qrcode) return;
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-gp-qr')); qr.make();
    el.innerHTML = qr.createSvgTag({ scalable: true, margin: 8 });
  });
})();
</script>
<?php endif; ?>
