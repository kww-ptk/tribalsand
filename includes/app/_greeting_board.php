<?php /** Greeting + guest board. Expects $hold, $ref. */ ?>
<?php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
$__first = trim((string)$hold['guest_name']); $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
?>
<div style="font-family:'Cormorant Garamond',serif;font-size:24px;margin:4px 0 12px">Karibu, <?= e($__first) ?></div>
<?php if ($__board): ?>
<div class="pa-grid" style="margin:0 0 16px">
  <?php foreach ($__board as $p): $bimg = trim((string)($p['image_filename'] ?? '')); ?>
  <div class="pa-card">
    <?php if ($bimg !== ''): ?><div class="pa-media" style="background-image:url('<?= e(storage_url($bimg)) ?>')"></div><?php endif; ?>
    <div class="pa-card__body">
      <span class="pa-tag <?= e($__tagClass[$p['category']] ?? '') ?>"><?= e($p['category']) ?></span>
      <p class="pa-card__title" style="margin-top:8px"><?= e($p['title']) ?></p>
      <?php if (($p['body'] ?? '') !== ''): ?><p class="pa-card__meta" style="display:block;margin-top:4px;line-height:1.5"><?= e($p['body']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
