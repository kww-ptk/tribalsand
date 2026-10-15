<?php
/**
 * Settings — the booking's details, travel documents, and where to go if plans
 * change. Guests don't cancel online (owner, Oct 2026): changes and cancellations
 * go through Messages, and staff act on the booking.
 * Expects $hold, $ref, $status, $isCoGuest.
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

<?php
// Kenya eTA accommodation letters (includes/eta-letter.php): the lead sees one per
// adult on the booking, a co-guest only their own. Kenyan ID holders need none.
require_once __DIR__ . '/../eta-letter.php';
$__etaRows = [];
if (checkin_supported()) {
    $__people = !empty($isCoGuest) ? (isset($me) && $me ? [$me] : [])
              : array_values(array_filter(fetch_checkin_guests((int)$hold['id']), fn($g) => empty($g['is_child'])));
    foreach ($__people as $__p) {
        $__st = eta_letter_status($hold, $__p);
        if ($__st['hidden'] && checkin_id_type($__p['id_type'] ?? null) !== 'kenya_id') continue;
        $__etaRows[] = [$__p, $__st];
    }
}
?>
<?php if ($__etaRows): ?>
<div class="pa-card pa-set">
  <div class="pa-set__h">Travel documents</div>
  <p class="pa-set__note">Your <strong>eTA letter</strong> confirms your stay with us — upload it as proof of accommodation when you apply for the Kenya eTA at etakenya.go.ke.</p>
  <?php foreach ($__etaRows as [$__p, $__st]): $__nm = trim((string)($__p['passport_name'] ?? '')) ?: 'Guest'; ?>
  <div class="pa-set__row">
    <span><?= e($__nm) ?><?= !empty($__p['is_lead']) ? ' <span class="pa-set__muted">(you)</span>' : '' ?></span>
    <?php if ($__st['ok']): ?>
      <a class="pa-btn pa-btn--ghost pa-set__btn" target="_blank" rel="noopener"
         href="<?= e(!empty($isCoGuest) ? eta_letter_url((int)$hold['id'], (int)$__p['id'], null, $ref) : eta_letter_url((int)$hold['id'], (int)$__p['id'], $ref)) ?>">eTA letter</a>
    <?php else: ?>
      <span class="pa-set__muted"><?= e($__st['reason']) ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="pa-card pa-set">
  <div class="pa-set__h">Change of plans</div>
  <?php if ($status === 'cancelled'): ?>
    <p class="pa-set__note">This booking is cancelled.</p>
  <?php else: ?>
    <p class="pa-set__note">Need to change your dates, your room, or cancel? Message us and we’ll take care of it.</p>
    <a class="pa-btn" href="<?= e($__su) ?>&amp;view=messages">Message us</a>
  <?php endif; ?>
</div>
