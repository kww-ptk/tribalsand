<?php /**
 * Home — the guest's stay overview. Shows the "Your stay" essentials (Wi-Fi,
 * check-out, house rules) plus the venue's "What's on" board when present.
 * Calendar and Requests are now their own bottom-nav tabs (see nav.php), so
 * Home no longer carries in-page section tabs.
 * Expects $hold, $ref, $status, $can_cancel, $cancel_blocked_reason.
 */ ?>
<?php include __DIR__ . '/_postcard.php';   // design 1: photo, booking card, featured extras ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>

<?php // Cancelling lives in Settings (includes/app/settings.php) — off the home screen. ?>

<?php // Party roster lives on Home for multi-adult bookings (it has no bottom-nav
      // tab of its own; calendar/requests moved to nav.php). Self-hides when solo.
if (max(1, (int)($hold['guest_count'] ?? 1)) > 1): ?>
<div style="margin-top:16px"><?php include __DIR__ . '/_party.php'; ?></div>
<?php endif; ?>

<?php include __DIR__ . '/_greeting_board.php'; ?>
