<?php
/**
 * Settings — the booking's details and the actions a guest rarely needs but must
 * be able to find: cancelling (moved off Home, Oct 2026). Only the person who
 * booked can cancel; the POST is re-checked in booking.php (!$isCoGuest).
 * Expects $hold, $ref, $status, $can_cancel, $cancel_blocked_reason, $isCoGuest.
 */
$__su = '/booking.php?ref=' . urlencode($ref);
$__nights = max(1, (int)((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400));
?>
<h2 class="pa-h2">Settings</h2>
<p class="pa-sub">Your booking details, and what to do if your plans change.</p>

<div class="pa-card pa-set">
  <div class="pa-set__h">Your booking</div>
  <dl class="pa-post__inset">
    <div><dt>Property</dt><dd><?= e(trim((string)($hold['venue_name'] ?? '')) ?: 'Tribal Sand') ?></dd></div>
    <div><dt>Room</dt><dd><?= e((string)$hold['room_name']) ?></dd></div>
    <div><dt>Dates</dt><dd><?= e(date('j M', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?> · <?= $__nights ?> night<?= $__nights === 1 ? '' : 's' ?></dd></div>
    <div><dt>Booked by</dt><dd><?= e((string)($hold['guest_name'] ?? '')) ?></dd></div>
    <?php if (!empty($hold['access_code'])): ?><div><dt>Booking code</dt><dd class="pa-mono"><?= e((string)$hold['access_code']) ?></dd></div><?php endif; ?>
    <div><dt>Status</dt><dd><?= e(ucfirst((string)$status)) ?></dd></div>
  </dl>
  <p class="pa-set__note">Keep your booking code: with it you can open this page from any device.</p>
</div>

<div class="pa-card pa-set">
  <div class="pa-set__h">Change of plans</div>
  <?php if (!empty($isCoGuest)): ?>
    <p class="pa-set__note">Only the person who made the booking can cancel it. <a href="<?= e($__su) ?>&amp;view=messages">Message us</a> if anything needs to change.</p>
  <?php elseif ($can_cancel): ?>
    <p class="pa-set__note">To change dates or rooms, <a href="<?= e($__su) ?>&amp;view=messages">message us</a>. If you need to cancel, the dates are freed and you get a confirmation by email.</p>
    <form method="POST" data-pa-confirm="Cancel this booking?"
          data-pa-confirm-body="Your dates will be released and this can’t be undone."
          data-pa-confirm-yes="Yes, cancel booking" data-pa-confirm-no="Keep my booking">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <button type="submit" class="pa-btn pa-btn--danger">Cancel my booking</button>
    </form>
  <?php elseif ($cancel_blocked_reason): ?>
    <p class="pa-set__note"><?= e($cancel_blocked_reason) ?></p>
    <a class="pa-btn" href="<?= e($__su) ?>&amp;view=messages">Message us</a>
  <?php else: ?>
    <p class="pa-set__note"><?= $status === 'cancelled' ? 'This booking is cancelled.' : 'Nothing to change here. Message us if you need anything.' ?></p>
  <?php endif; ?>
</div>
