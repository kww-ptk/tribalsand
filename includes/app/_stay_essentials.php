<?php
/**
 * Your stay — bento design A (Oct 2026). Wi-Fi (big, Copy) and the check-in /
 * check-out tiles across the top; the property's real map (Google Maps embed,
 * venue_map_embed_url()) with Directions + Copy address; house rules as short
 * items (one per line of the property's text, stay_rules_list()); early/late
 * check-in as its own tile. On a phone it stacks: Wi-Fi, the two times side by
 * side, the map, rules, early/late. Times come from settings (checkin_times());
 * a property's own check-out text (venues.stay_checkout) wins when set.
 * Expects $hold, $ref.
 */
$__venueId = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
$__st    = fetch_venue_stay($__venueId);
$__T     = checkin_times();
$__wifi  = trim((string)($__st['stay_wifi'] ?? ''));
$__coTxt = trim((string)($__st['stay_checkout'] ?? '')) ?: ($__T['co_from'] . '–' . $__T['co_to']);
$__ciTxt = $__T['ci_from'] . '–' . $__T['ci_to'];
$__vname = trim((string)($hold['venue_name'] ?? '')) ?: 'Tribal Sand';
$__town  = '';
try { if ($__venueId) $__town = trim((string)db_query('SELECT location FROM venues WHERE id = :v', [':v' => $__venueId])->fetchColumn()); } catch (Throwable $e) {}
$__addr  = trim((string)($__st['address'] ?? ''));
$__place = $__addr !== '' && $__addr !== $__vname ? $__addr : $__vname;
$__embed = venue_map_embed_url($__st, $__vname, $__town);
$__dirs  = venue_maps_link($__st) ?: ($__embed !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', array_filter([$__place, $__town]))) : '');
$__rules = stay_rules_list((string)($__st['stay_house_rules'] ?? ''));
$__guide = trim((string)($__st['stay_area_guide'] ?? ''));
$__su    = '/booking.php?ref=' . urlencode($ref);
$__cls   = 'pa-bento' . ($__wifi === '' ? ' pa-bento--nowifi' : '') . ($__embed === '' ? ' pa-bento--nomap' : '');
$__ico   = fn(string $p) => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
?>
<section class="<?= e($__cls) ?>" aria-label="Your stay">
  <?php if ($__wifi !== ''): ?>
  <div class="pa-bento__t pa-bento__wifi">
    <span class="pa-bento__k">Wi-Fi</span>
    <b class="pa-bento__pw"><?= e($__wifi) ?></b>
    <button type="button" class="pa-bento__btn" data-copy="<?= e($__wifi) ?>" aria-label="Copy the Wi-Fi details">Copy password</button>
  </div>
  <?php endif; ?>
  <div class="pa-bento__t pa-bento__ci">
    <span class="pa-bento__ico"><?= $__ico('<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>') ?></span>
    <span class="pa-bento__k">Check-in</span>
    <b class="pa-bento__v"><?= e($__ciTxt) ?></b>
    <span class="pa-bento__s"><?= e(date('D j M', strtotime((string)$hold['check_in']))) ?></span>
  </div>
  <div class="pa-bento__t pa-bento__co">
    <span class="pa-bento__ico"><?= $__ico('<path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4"/><path d="M14 17l5-5-5-5M19 12H7"/>') ?></span>
    <span class="pa-bento__k">Check-out</span>
    <b class="pa-bento__v"><?= preg_match('/^\d/', $__coTxt) && !str_contains($__coTxt, '–') ? 'by ' . e($__coTxt) : e($__coTxt) ?></b>
    <span class="pa-bento__s"><?= e(date('D j M', strtotime((string)$hold['check_out']))) ?></span>
  </div>
  <?php if ($__embed !== ''): ?>
  <div class="pa-bento__t pa-bento__map">
    <div class="pa-bento__frame">
      <iframe src="<?= e($__embed) ?>" title="Map of <?= e($__vname) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
    </div>
    <div class="pa-bento__place">
      <span><b><?= e($__place) ?></b><?php if ($__town !== '' && stripos($__place, $__town) === false): ?><span><?= e($__town) ?></span><?php endif; ?></span>
      <span class="pa-bento__acts">
        <button type="button" class="pa-bento__btn pa-bento__btn--lt" data-copy="<?= e(implode(', ', array_filter([$__place, $__town]))) ?>">Copy address</button>
        <?php if ($__dirs !== ''): ?><a class="pa-bento__btn" href="<?= e($__dirs) ?>" target="_blank" rel="noopener">Directions ↗</a><?php endif; ?>
      </span>
    </div>
  </div>
  <?php endif; ?>
  <div class="pa-bento__t pa-bento__rules">
    <span class="pa-bento__k">House rules</span>
    <?php if ($__rules): ?>
    <ul class="pa-bento__chips"><?php foreach ($__rules as $__r): ?><li><?= e($__r) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
    <span class="pa-bento__s">Our team will share the house rules at check-in.</span>
    <?php endif; ?>
    <?php if ($__guide !== ''): ?>
    <details class="pa-bento__more"><summary>Area guide</summary><p><?= e($__guide) ?></p></details>
    <?php endif; ?>
  </div>
  <div class="pa-bento__t pa-bento__early">
    <span class="pa-bento__k">Early check-in · late check-out</span>
    <span class="pa-bento__s pa-bento__s--ink"><?= e(trim((string)$__T['note']) !== '' ? (string)$__T['note'] : 'Ask us and we’ll check what’s possible.') ?></span>
    <a class="pa-bento__btn pa-bento__btn--lt" href="<?= e($__su) ?>&amp;view=messages">Ask us</a>
  </div>
</section>
