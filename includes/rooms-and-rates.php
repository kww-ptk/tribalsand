<?php
/**
 * Tribal Sand "Rooms & Availability" section for a property (venue).
 * Usage: $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php';
 * Renders nothing if the venue has no published rooms. Requires includes/availability-bar.php
 * and includes/booking-modal.php on the page; availability is driven by js/availability-search.js.
 */
require_once __DIR__ . '/db.php';

$rr_venue_slug = $rr_venue_slug ?? '';
$__v = $rr_venue_slug ? db_query('SELECT * FROM venues WHERE slug = :s', [':s' => $rr_venue_slug])->fetch() : false;
if (!$__v) { return; }
$__rooms = db_query(
    "SELECT r.*, (SELECT filename FROM room_images WHERE room_id = r.id AND is_hero = TRUE LIMIT 1) AS hero
     FROM rooms r WHERE r.venue_id = :vid AND r.is_published = TRUE ORDER BY r.is_entire_place ASC, r.sort_order ASC",
    [':vid' => $__v['id']]
)->fetchAll();
if (!$__rooms) { return; }
?>
<section class="rr" id="rooms">
  <div class="rr__inner">
    <div class="sec-label">Accommodations</div>
    <h2 class="sec-h">Rooms &amp; <em>Availability</em></h2>
    <div class="sec-rule"></div>
    <div class="suites-grid">
      <?php foreach ($__rooms as $r):
        $img = !empty($r['hero']) ? storage_url($r['hero']) : '/images/whitelogo11.png';
        $guests = (int)($r['capacity'] ?? 0);
        $beds   = (int)($r['bed_count'] ?? 0);
        $meta = trim(($beds ? $beds . ' bed' . ($beds > 1 ? 's' : '') : '') . ($beds && $guests ? ' · ' : '') . ($guests ? 'Up to ' . $guests . ' guests' : ''));
      ?>
      <article class="suite-card rr-card"
               data-room-slug="<?= e($r['slug']) ?>" data-room-name="<?= e($r['name']) ?>"
               data-price="<?= e((float)$r['price_amount']) ?>" data-currency="<?= e($r['price_currency']) ?>">
        <div class="suite-card-img"<?= empty($r['hero']) ? ' data-placeholder="1"' : '' ?>>
          <img src="<?= e($img) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
          <?php if (!empty($r['tag_label'])): ?><span class="suite-card-tag"><?= e($r['tag_label']) ?></span><?php endif; ?>
        </div>
        <div class="suite-card-body">
          <div class="suite-card-name"><?= e($r['name']) ?></div>
          <?php if ($meta): ?><div class="suite-card-meta"><?= e($meta) ?></div><?php endif; ?>
          <?php if (!empty($r['short_desc'])): ?><p class="suite-card-desc"><?= e($r['short_desc']) ?></p><?php endif; ?>
          <div class="rr-card__avail" hidden></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
