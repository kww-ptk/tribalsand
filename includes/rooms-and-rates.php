<?php
/**
 * Tribal Sand "Rooms & Availability" section for a property (venue).
 * Usage: $rr_venue_slug = 'my-amani'; include __DIR__ . '/includes/rooms-and-rates.php';
 * Renders nothing if the venue has no published rooms.
 * Each card shows the nightly price, opens a per-room photo lightbox, and has its own
 * "Check availability" button. rrBook() (defined below) re-points the page's sidebar booking
 * widget (includes/booking-widget.php + js/booking-widget.js → window.tsLoadRoom) at the
 * chosen room and scrolls to it — so a guest can book their desired room directly. If a page
 * instead ships the booking modal (#bkModal), rrBook opens that instead.
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

// Gather every photo for these rooms in one query, grouped by room → per-room lightbox.
$__roomIds = array_map(fn($r) => (int)$r['id'], $__rooms);
$__imgsByRoom = [];
if ($__roomIds) {
    $__in = implode(',', array_fill(0, count($__roomIds), '?'));
    $__stmt = db_query(
        "SELECT room_id, filename, alt_text FROM room_images
         WHERE room_id IN ($__in) ORDER BY is_hero DESC, sort_order ASC, id ASC",
        $__roomIds
    );
    foreach ($__stmt->fetchAll() as $im) {
        $__imgsByRoom[(int)$im['room_id']][] = [
            'url' => storage_url($im['filename']),
            'alt' => $im['alt_text'] ?: '',
        ];
    }
}
?>
<section class="rr" id="rooms">
  <div class="rr__inner">
    <div class="sec-label">Accommodations</div>
    <h2 class="sec-h">Rooms &amp; <em>Availability</em></h2>
    <div class="sec-rule"></div>
    <div class="suites-grid">
      <?php foreach ($__rooms as $r):
        $rid    = (int)$r['id'];
        $photos = $__imgsByRoom[$rid] ?? [];
        $img    = !empty($r['hero']) ? storage_url($r['hero']) : (!empty($photos) ? $photos[0]['url'] : '/images/whitelogo11.png');
        $hasPhotos = count($photos) > 0;
        $guests = (int)($r['capacity'] ?? 0);
        $beds   = (int)($r['bed_count'] ?? 0);
        $meta = trim(($beds ? $beds . ' bed' . ($beds > 1 ? 's' : '') : '') . ($beds && $guests ? ' · ' : '') . ($guests ? 'Up to ' . $guests . ' guests' : ''));
        $price = (float)$r['price_amount'];
        $unit  = trim((string)($r['price_unit'] ?? ''));
      ?>
      <article class="suite-card rr-card"
               data-room-slug="<?= e($r['slug']) ?>" data-room-name="<?= e($r['name']) ?>"
               data-price="<?= e($price) ?>" data-currency="<?= e($r['price_currency']) ?>">
        <div class="suite-card-img<?= empty($r['hero']) && !$hasPhotos ? ' suite-card-img--placeholder' : '' ?>"<?= empty($r['hero']) && !$hasPhotos ? ' data-placeholder="1"' : '' ?>
             <?php if ($hasPhotos): ?>role="button" tabindex="0" aria-label="View photos of <?= e($r['name']) ?>" onclick="rrOpenLb('<?= e($r['slug']) ?>',0)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();rrOpenLb('<?= e($r['slug']) ?>',0)}"<?php endif; ?>>
          <img src="<?= e($img) ?>" alt="<?= e($r['name']) ?>" loading="lazy">
          <?php if (!empty($r['tag_label'])): ?><span class="suite-card-tag"><?= e($r['tag_label']) ?></span><?php endif; ?>
          <?php if ($hasPhotos): ?>
          <span class="suite-card-photos">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            <?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="suite-card-body">
          <div class="suite-card-name"><?= e($r['name']) ?></div>
          <?php if ($meta): ?><div class="suite-card-meta"><?= e($meta) ?></div><?php endif; ?>
          <div class="suite-card-price">
            <?php if ($price > 0): ?>
            <span class="suite-card-price__amt"><?= e($r['price_currency']) ?> <?= e(number_format($price, 0)) ?></span>
            <?php if ($unit !== ''): ?><span class="suite-card-price__unit"><?= e($unit) ?></span><?php endif; ?>
            <?php else: ?>
            <span class="suite-card-price__amt suite-card-price__amt--soft">Price on request</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($r['short_desc'])): ?><p class="suite-card-desc"><?= e($r['short_desc']) ?></p><?php endif; ?>
          <div class="suite-card-actions">
            <?php if ($hasPhotos): ?>
            <button type="button" class="rr-photos-btn" onclick="rrOpenLb('<?= e($r['slug']) ?>',0)">View photos</button>
            <?php endif; ?>
            <button type="button" class="rr-book-btn"
                    data-room-slug="<?= e($r['slug']) ?>" data-room-name="<?= e($r['name']) ?>"
                    data-price="<?= e($price) ?>" data-currency="<?= e($r['price_currency']) ?>"
                    onclick="rrBook(this)">
              Check availability
            </button>
          </div>
          <div class="rr-card__avail" hidden></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Per-room photo lightbox (shared element; images swapped in by room slug) -->
<div class="rr-lb" id="rrLb" hidden>
  <button class="rr-lb__close" type="button" data-rrlb-close aria-label="Close">&times;</button>
  <button class="rr-lb__nav rr-lb__prev" type="button" data-rrlb-prev aria-label="Previous">&#8249;</button>
  <figure class="rr-lb__stage"><img id="rrLbImg" alt=""></figure>
  <button class="rr-lb__nav rr-lb__next" type="button" data-rrlb-next aria-label="Next">&#8250;</button>
  <span class="rr-lb__count" id="rrLbCount"></span>
</div>
<script>
// Book a specific room: prefer a booking modal if the page has one, otherwise
// re-point the existing sidebar booking widget (js/booking-widget.js) at this room.
window.rrBook = function (btn) {
  var d = btn.dataset, slug = d.roomSlug, name = d.roomName, price = d.price, currency = d.currency;
  if (typeof window.tsOpenBookingModal === 'function' && document.getElementById('bkModal')) {
    window.tsOpenBookingModal(slug, name, price, currency, null);
    return;
  }
  if (typeof window.tsLoadRoom === 'function') window.tsLoadRoom(slug, price, currency, null);
  var lbl = document.getElementById('bkRoomLabel');
  if (lbl) { lbl.textContent = name; lbl.hidden = false; }
  var target = document.getElementById('availCalendar') || document.getElementById('book');
  if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
};
(function () {
  var sets = <?= json_encode(
      array_map(fn($r) => array_map(fn($p) => $p['url'], $__imgsByRoom[(int)$r['id']] ?? []), array_column($__rooms, null, 'slug')),
      JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_QUOT
  ) ?>;
  var lb = document.getElementById('rrLb'), img = document.getElementById('rrLbImg'),
      cnt = document.getElementById('rrLbCount'), cur = [], i = 0;
  window.rrOpenLb = function (slug, n) {
    cur = sets[slug] || []; if (!cur.length) return;
    i = (n + cur.length) % cur.length; render();
    lb.hidden = false; document.body.style.overflow = 'hidden';
  };
  function render() { img.src = cur[i]; cnt.textContent = (i + 1) + ' / ' + cur.length; }
  function close() { lb.hidden = true; document.body.style.overflow = ''; }
  function nav(d) { if (!cur.length) return; i = (i + d + cur.length) % cur.length; render(); }
  lb.querySelector('[data-rrlb-close]').addEventListener('click', close);
  lb.querySelector('[data-rrlb-prev]').addEventListener('click', function (e) { e.stopPropagation(); nav(-1); });
  lb.querySelector('[data-rrlb-next]').addEventListener('click', function (e) { e.stopPropagation(); nav(1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) {
    if (lb.hidden) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') nav(-1); else if (e.key === 'ArrowRight') nav(1);
  });
})();
</script>
