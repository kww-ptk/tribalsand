<?php
/**
 * Extras — everything this property offers the guest to add to their stay,
 * curated by the owner (Admin → Properties → Guest extras). Tapping one opens the
 * shared add sheet (_extras_sheet.php). Expects $hold, $ref, $status.
 */
$__xList   = guest_extras_for_hold($hold);
$__xActive = in_array($status ?? '', ['pending', 'confirmed'], true);
$__xVenue  = trim((string)($hold['venue_name'] ?? '')) ?: 'your stay';
$__xGroups = array_values(array_unique(array_column($__xList, 'group')));
$__xU      = '/booking.php?ref=' . urlencode($ref);
?>
<h2 class="pa-h2">Extras</h2>
<p class="pa-sub">Add to your stay at <?= e($__xVenue) ?>. Our team confirms each one, and it goes on your bill once confirmed. Nothing is paid now.</p>

<?php if (!$__xList): ?>
  <div class="pa-card" style="padding:16px">
    <p style="margin:0 0 10px">There are no extras to add for this stay yet.</p>
    <a class="pa-btn" href="<?= e($__xU) ?>&amp;view=messages">Ask us for anything</a>
  </div>
<?php else: ?>
  <?php if (!$__xActive): ?><p class="pa-note">This booking can no longer take extras.</p><?php endif; ?>
  <?php if (count($__xGroups) > 1): ?>
  <div class="pa-chips" id="paXChips" role="tablist" aria-label="Kinds of extras">
    <button type="button" class="pa-chip is-active" data-xgroup="" role="tab" aria-selected="true">All</button>
    <?php foreach ($__xGroups as $g): ?><button type="button" class="pa-chip" data-xgroup="<?= e($g) ?>" role="tab" aria-selected="false"><?= e($g) ?></button><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="pa-xlist">
  <?php foreach ($__xList as $x): [$__sl, $__st] = guest_extra_status_view($x['status']); ?>
    <div class="pa-xitem" data-xitem-group="<?= e($x['group']) ?>">
      <button type="button" class="pa-xitem__main" data-extra="<?= e($x['key']) ?>"<?= $__xActive ? '' : ' disabled' ?>>
        <span class="pa-xitem__thumb pa-xthumb--<?= e($x['kind'] === 'transfer' ? 'transfer' : preg_replace('/[^a-z]/', '', strtolower($x['category']))) ?>"<?= $x['image'] !== '' ? ' style="background-image:url(\'' . e($x['image']) . '\')"' : '' ?>></span>
        <span class="pa-xitem__text">
          <b><?= e($x['name']) ?></b>
          <span><?= e(trim(($x['duration'] !== '' ? $x['duration'] . ' · ' : '') . $x['price_label'])) ?></span>
        </span>
      </button>
      <span class="pa-xitem__side" data-extra-status="<?= e($x['key']) ?>">
        <?php if ($__sl !== ''): ?><span class="pa-pill pa-pill--<?= e($__st) ?>"><?= e($__sl) ?></span>
        <?php elseif ($__xActive): ?><button type="button" class="pa-addc" data-extra="<?= e($x['key']) ?>" aria-label="Add <?= e($x['name']) ?>">+</button><?php endif; ?>
      </span>
    </div>
  <?php endforeach; ?>
  </div>
  <p class="pa-sub" style="margin-top:14px">Need something else? <a href="<?= e($__xU) ?>&amp;view=requests">Housekeeping, laundry, restaurant and more</a></p>
<?php endif; ?>
<?php include __DIR__ . '/_extras_sheet.php'; ?>
