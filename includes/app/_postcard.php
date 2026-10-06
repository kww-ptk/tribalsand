<?php
/**
 * Home header, design 1 "Postcard": the property's photo, the booking card over
 * it, then the extras picked for this property and a way to ask for services.
 * Expects $hold, $ref, $status. Sets $__xList for the shared add sheet.
 */
$__pu      = '/booking.php?ref=' . urlencode($ref);
$__venueId = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
$__photo   = venue_share_image($__venueId);
$__vname   = trim((string)($hold['venue_name'] ?? '')) ?: 'Tribal Sand';
$__town    = '';
try { if ($__venueId) $__town = trim((string)db_query('SELECT location FROM venues WHERE id = :v', [':v' => $__venueId])->fetchColumn()); } catch (Throwable $e) {}
$__nights  = max(1, (int)((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400));
// The hold's guest_count is the check-in party (staff raise it later); a converted
// request still carries the party the guest asked for, so show the larger.
$__guests  = max(1, (int)($hold['guest_count'] ?? 1));
if (!empty($hold['submission_id'])) {
    try {
        $__party = (int)db_query('SELECT COALESCE(guests_adults,0) + COALESCE(guests_children,0) FROM submissions WHERE id = :s',
                                 [':s' => (int)$hold['submission_id']])->fetchColumn();
        $__guests = max($__guests, $__party);
    } catch (Throwable $e) {}
}
$__pill    = ['pending' => ['Awaiting confirmation', 'pend'], 'confirmed' => ['Confirmed', 'ok'],
              'cancelled' => ['Cancelled', 'no'], 'expired' => ['Expired', 'no']][$status] ?? [ucfirst((string)$status), 'pend'];
$__unread  = 0;
try { $__unread = count_unread_guest((int)$hold['id']); } catch (Throwable $e) {}
$__xList   = guest_extras_for_hold($hold);
$__xFeat   = in_array($status, ['pending', 'confirmed'], true) ? guest_extras_featured($__xList) : [];
?>
<section class="pa-post">
  <div class="pa-post__photo"<?= $__photo !== '' ? ' style="background-image:url(\'' . e($__photo) . '\')"' : '' ?>>
    <div class="pa-post__top">
      <span class="pa-post__eyebrow">Your stay at</span>
      <a class="pa-post__icon" href="<?= e($__pu) ?>&amp;view=messages" aria-label="Messages<?= $__unread ? ' (' . (int)$__unread . ' new)' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/></svg>
        <?php if ($__unread): ?><span class="pa-post__dot"><?= (int)$__unread ?></span><?php endif; ?>
      </a>
    </div>
    <div class="pa-post__name"><?= e($__vname) ?></div>
    <?php if ($__town !== ''): ?><div class="pa-post__town"><?= e($__town) ?></div><?php endif; ?>
  </div>
  <div class="pa-post__card">
    <div class="pa-post__cardhead">
      <div><b><?= e((string)$hold['room_name']) ?></b><span><?= $__guests ?> guest<?= $__guests === 1 ? '' : 's' ?> · <?= $__nights ?> night<?= $__nights === 1 ? '' : 's' ?></span></div>
      <span class="pa-pill pa-pill--<?= e($__pill[1]) ?>"><?= e($__pill[0]) ?></span>
    </div>
    <dl class="pa-post__inset">
      <div><dt>Check in</dt><dd><?= e(date('D, j M Y', strtotime((string)$hold['check_in']))) ?></dd></div>
      <div><dt>Check out</dt><dd><?= e(date('D, j M Y', strtotime((string)$hold['check_out']))) ?></dd></div>
      <?php if (!empty($hold['access_code'])): ?><div><dt>Booking code</dt><dd class="pa-mono"><?= e((string)$hold['access_code']) ?></dd></div><?php endif; ?>
      <?php if ($status === 'pending' && !empty($hold['expires_at'])):
        $__left = strtotime((string)$hold['expires_at']) - time();
        $__fallback = $__left > 0 ? sprintf('%dh %02dm', intdiv($__left, 3600), intdiv($__left % 3600, 60)) : 'Expiring soon'; ?>
      <div><dt>Dates held for</dt><dd id="bkCountdown" class="pa-warn"><?= e($__fallback) ?></dd></div>
      <?php endif; ?>
    </dl>
    <a class="pa-btn pa-post__btn" href="<?= e($__pu) ?>&amp;view=calendar">Trip details</a>
  </div>
</section>

<?php if ($status === 'pending'): ?>
<p class="pa-note pa-note--pend">Your dates are held while our team confirms your booking. We’ll email you as soon as it’s confirmed.</p>
<?php elseif (in_array($status, ['expired', 'cancelled'], true)): ?>
<p class="pa-note pa-note--no"><?= $status === 'expired' ? 'This hold expired.' : 'This booking was cancelled.' ?> <a href="/properties">Browse our properties</a></p>
<?php endif; ?>

<?php if ($__xFeat): ?>
<div class="pa-xhead"><b>For your stay at <?= e($__vname) ?></b><a href="<?= e($__pu) ?>&amp;view=extras">See all</a></div>
<div class="pa-xrail">
  <?php foreach ($__xFeat as $x): ?>
  <button type="button" class="pa-xcard pa-xthumb--<?= e($x['kind'] === 'transfer' ? 'transfer' : preg_replace('/[^a-z]/', '', strtolower($x['category']))) ?>" data-extra="<?= e($x['key']) ?>"<?= $x['image'] !== '' ? ' style="background-image:url(\'' . e($x['image']) . '\')"' : '' ?>>
    <span class="pa-xcard__tag"><?= e($x['group']) ?></span>
    <span class="pa-xcard__text"><b><?= e($x['name']) ?></b><span><?= e(trim(($x['duration'] !== '' ? $x['duration'] . ' · ' : '') . $x['price_label'])) ?></span></span>
    <span class="pa-xcard__add" aria-hidden="true">Add +</span>
  </button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<a class="pa-services" href="<?= e($__pu) ?>&amp;view=requests">
  <span><b>Need something during your stay?</b><span>Housekeeping, laundry, restaurant, maintenance</span></span>
  <span aria-hidden="true">›</span>
</a>
<?php include __DIR__ . '/_extras_sheet.php'; ?>
