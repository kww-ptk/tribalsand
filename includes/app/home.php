<?php /**
 * Home — the guest's stay overview. Shows the "Your stay" essentials (Wi-Fi,
 * check-out, house rules) plus the venue's "What's on" board when present.
 * Calendar and Requests are now their own bottom-nav tabs (see nav.php), so
 * Home no longer carries in-page section tabs.
 * Expects $hold, $ref, $status, $can_cancel, $cancel_blocked_reason.
 */ ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>

<?php if ($can_cancel): ?>
<div class="pa-card" style="padding:16px;margin-top:16px">
  <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
  <p style="margin:0 0 20px;font-size:14px;color:var(--pa-muted);line-height:1.65">If your plans have changed you can cancel now. The dates will be freed and you will receive a cancellation confirmation by email.</p>
  <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This cannot be undone.')">
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <button type="submit" class="pa-btn pa-btn--danger">Cancel My Booking</button>
  </form>
</div>
<?php elseif ($cancel_blocked_reason): ?>
<div class="pa-card" style="padding:16px;margin-top:16px">
  <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
  <p style="margin:0;font-size:14px;color:var(--pa-muted)"><?= e($cancel_blocked_reason) ?></p>
</div>
<?php endif; ?>

<?php // Party roster lives on Home for multi-adult bookings (it has no bottom-nav
      // tab of its own; calendar/requests moved to nav.php). Self-hides when solo.
if (max(1, (int)($hold['guest_count'] ?? 1)) > 1): ?>
<div style="margin-top:16px"><?php include __DIR__ . '/_party.php'; ?></div>
<?php endif; ?>

<?php include __DIR__ . '/_greeting_board.php'; ?>
