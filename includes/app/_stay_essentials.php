<?php
/**
 * Your stay — design B "room key card" (Oct 2026). The thing guests look up most,
 * the Wi-Fi password, is the biggest thing on the card with a Copy button; the
 * check-in / check-out times and where they are in the stay sit under it; house
 * rules, early/late times, getting here and the area guide fold out below.
 * Times come from settings (checkin_times()); a property's own check-out text
 * (venues.stay_checkout) wins over the general window when it is set.
 * Expects $hold.
 */
$__venueId = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
$__st   = fetch_venue_stay($__venueId);
$__T    = checkin_times();
$__wifi = trim((string)($__st['stay_wifi'] ?? ''));
$__coTxt = trim((string)($__st['stay_checkout'] ?? '')) ?: ($__T['co_from'] . '–' . $__T['co_to']);
$__ciTxt = $__T['ci_from'] . '–' . $__T['ci_to'];
$__maps  = venue_maps_link($__st);

// Where the guest is in the stay (Nairobi dates).
$__today = date('Y-m-d');
$__ci = (string)$hold['check_in']; $__co = (string)$hold['check_out'];
$__nights = max(1, (int)((strtotime($__co) - strtotime($__ci)) / 86400));
$__phase = '';
$__pct = 0;
if ($__today < $__ci) {
    $__in = (int)((strtotime($__ci) - strtotime($__today)) / 86400);
    $__phase = $__in === 1 ? 'Arriving tomorrow'
             : ($__in <= 30 ? 'Arriving in ' . $__in . ' days · ' . date('D j M', strtotime($__ci)) : 'Arriving ' . date('D j M Y', strtotime($__ci)));
} elseif ($__today < $__co) {
    $__night = (int)((strtotime($__today) - strtotime($__ci)) / 86400) + 1;
    $__pct = (int)round($__night / $__nights * 100);
    $__phase = 'Night ' . $__night . ' of ' . $__nights . ' · checking out ' . date('D j M', strtotime($__co));
} elseif ($__today === $__co) {
    $__pct = 100;
    $__phase = 'Checking out today';
}
$__rows = [];
if (trim((string)$__st['stay_house_rules']) !== '') $__rows[] = ['House rules', (string)$__st['stay_house_rules'], ''];
if (trim((string)$__T['note']) !== '')               $__rows[] = ['Early check-in & late check-out', (string)$__T['note'], ''];
if ($__maps !== '' || trim((string)$__st['address']) !== '') $__rows[] = ['Getting here', (string)$__st['address'], $__maps];
if (trim((string)$__st['stay_area_guide']) !== '')  $__rows[] = ['Area guide', (string)$__st['stay_area_guide'], ''];
?>
<section class="pa-key" aria-label="Your stay">
  <div class="pa-key__card">
    <?php if ($__wifi !== ''): ?>
    <div class="pa-key__k">Wi-Fi</div>
    <div class="pa-key__wifi">
      <b class="pa-key__pw"><?= e($__wifi) ?></b>
      <button type="button" class="pa-key__copy" data-copy="<?= e($__wifi) ?>" aria-label="Copy the Wi-Fi details">Copy</button>
    </div>
    <?php else: ?>
    <div class="pa-key__k">Your stay</div>
    <?php endif; ?>
    <div class="pa-key__times">
      <span><small>Check-in</small><?= e($__ciTxt) ?></span>
      <span><small>Check-out</small><?= e($__coTxt) ?></span>
    </div>
    <?php if ($__phase !== ''): ?>
    <?php if ($__pct > 0): ?><div class="pa-key__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $__pct ?>" aria-label="How far into your stay"><i style="width:<?= $__pct ?>%"></i></div><?php endif; ?>
    <div class="pa-key__phase"><?= e($__phase) ?></div>
    <?php endif; ?>
  </div>
  <?php foreach ($__rows as [$__label, $__text, $__link]): ?>
  <details class="pa-key__row">
    <summary><?= e($__label) ?><span aria-hidden="true">›</span></summary>
    <?php if (trim($__text) !== ''): ?><div class="pa-key__text"><?= e($__text) ?></div><?php endif; ?>
    <?php if ($__link !== ''): ?><a class="pa-key__map" href="<?= e($__link) ?>" target="_blank" rel="noopener">Open in Google Maps →</a><?php endif; ?>
  </details>
  <?php endforeach; ?>
</section>
