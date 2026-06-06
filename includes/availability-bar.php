<?php
/**
 * Sticky "search availability" bar for a property page.
 * Usage: $rr_venue_slug = 'zuri'; include __DIR__ . '/includes/availability-bar.php';
 * Driven by js/availability-search.js. For pages with .suite-card[data-room-slug] cards it marks
 * those; otherwise (whole-villa) it checks the venue's first published room (the fallback).
 */
require_once __DIR__ . '/db.php';

$rr_venue_slug = $rr_venue_slug ?? '';
$__bv = $rr_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $rr_venue_slug])->fetch() : false;
$__fb = $__bv ? db_query(
    'SELECT slug, name, price_amount, price_currency FROM rooms WHERE venue_id = :vid AND is_published = TRUE ORDER BY is_entire_place DESC, sort_order ASC LIMIT 1',
    [':vid' => $__bv['id']]
)->fetch() : false;
?>
<div class="rr-bar" id="rrBar"
     data-fallback-slug="<?= e($__fb['slug'] ?? '') ?>"
     data-fallback-name="<?= e($__fb['name'] ?? ($__bv['name'] ?? '')) ?>"
     data-fallback-price="<?= e($__fb ? (float)$__fb['price_amount'] : 0) ?>"
     data-fallback-currency="<?= e($__fb['price_currency'] ?? 'USD') ?>">
  <div class="rr-bar__inner">
    <div class="rr-bar__field"><span>Check-in</span><button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="rrBar" data-dp-target="rrBarCheckin" data-dp-placeholder="Add date">Add date</button><input type="hidden" id="rrBarCheckin"></div>
    <div class="rr-bar__field"><span>Check-out</span><button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="rrBar" data-dp-target="rrBarCheckout" data-dp-placeholder="Add date">Add date</button><input type="hidden" id="rrBarCheckout"></div>
    <label class="rr-bar__field"><span>Guests</span>
      <select id="rrBarGuests">
        <?php for ($g = 1; $g <= 16; $g++): ?><option value="<?= $g ?>"<?= $g === 2 ? ' selected' : '' ?>><?= $g ?></option><?php endfor; ?>
      </select>
    </label>
    <button type="button" class="rr-bar__cta" id="rrBarCheck">Check availability</button>
  </div>
  <div class="rr-bar__result" id="rrBarResult" hidden></div>
</div>
