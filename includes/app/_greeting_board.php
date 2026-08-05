<?php /** Greeting + guest board. Expects $hold, $ref. */ ?>
<?php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion','event'=>'pa-tag--event'];
$__active = in_array($hold['status'] ?? '', ['pending','confirmed'], true);
$__cur    = setting('site_currency', 'USD');
?>
<?php if ($__board): ?>
<div class="pa-h2" style="margin-top:20px">What's on</div>
<?php endif; ?>
<?php if ($__board): ?>
<div class="pa-grid" style="margin:0 0 20px">
  <?php foreach ($__board as $p): $bimg = trim((string)($p['image_filename'] ?? '')); ?>
  <div class="pa-card">
    <?php if ($bimg !== ''): ?><div class="pa-media" style="background-image:url('<?= e(storage_url($bimg)) ?>')"></div><?php endif; ?>
    <div class="pa-card__body">
      <span class="pa-tag <?= e($__tagClass[$p['category']] ?? '') ?>"><?= e($p['category']) ?></span>
      <p class="pa-card__title" style="margin-top:8px"><?= e($p['title']) ?></p>
      <?php if (($p['body'] ?? '') !== ''): ?><p class="pa-card__meta" style="display:block;margin-top:4px;line-height:1.5"><?= e($p['body']) ?></p><?php endif; ?>
      <?php if ($p['category'] === 'event'): ?>
      <?php
        $__evMeta = [];
        if (!empty($p['event_date'])) $__evMeta[] = date('D j M, H:i', strtotime((string)$p['event_date']));
        $__evPriced = isset($p['price_amount']) && $p['price_amount'] !== null && (float)$p['price_amount'] > 0;
        if ($__evPriced) $__evMeta[] = format_price((float)$p['price_amount'], $__cur);
      ?>
      <?php if ($__evMeta): ?><p class="pa-card__meta" style="display:block;margin-top:6px;font-weight:600;color:var(--pa-ink)"><?= e(implode(' · ', $__evMeta)) ?></p><?php endif; ?>
      <?php if ($__active): ?>
        <?php if (guest_joined_event((int)$hold['id'], (int)$p['id'])): ?>
        <span class="pa-pill pa-pill--confirmed" style="margin-top:10px;display:inline-block">Requested</span>
        <?php else: ?>
        <form data-bm data-bm-success="Requested — opening your chat…" action="/api/booking-addon.php" style="margin-top:10px">
          <input type="hidden" name="ref" value="<?= e($ref) ?>">
          <input type="hidden" name="kind" value="event">
          <input type="hidden" name="board_post_id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="pa-btn pa-btn--primary"><?= $__evPriced ? 'Request · ' . e(format_price((float)$p['price_amount'], $__cur)) : 'Join event' ?></button>
          <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
        </form>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
