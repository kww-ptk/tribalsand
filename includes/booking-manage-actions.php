<?php /** Rendered inside booking.php for actionable holds. Expects $hold, $ref. */ ?>
<?php
  $bm_addons  = fetch_booking_addons((int)$hold['id']);
  $bm_changes = fetch_booking_change_requests((int)$hold['id']);
?>
<?php if ($bm_addons || $bm_changes): ?>
<div class="pa-card">
  <div class="pa-card__body">
    <h2 class="pa-h2" style="font-size:18px">Your requests</h2>
    <ul style="list-style:none;margin:0;padding:0">
      <?php foreach ($bm_addons as $a): ?>
        <li style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 0;font-size:14px;border-bottom:1px solid var(--pa-line)">
          <strong style="text-transform:capitalize"><?= e($a['kind']) ?></strong>
          <span style="color:var(--pa-muted)"><?= e(addon_label($a)) ?><?php if (!empty($a['scheduled_for'])): ?> · <?= e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) ?><?php endif; ?></span>
          <?= status_badge($a['status'], 'pill', 'style="margin-left:auto"') ?>
        </li>
      <?php endforeach; ?>
      <?php foreach ($bm_changes as $c): ?>
        <li style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:7px 0;font-size:14px;border-bottom:1px solid var(--pa-line)">
          <strong>Change</strong>
          <span style="color:var(--pa-muted)">
            <?php
              $parts = [];
              if ($c['requested_check_in'] || $c['requested_check_out'])
                  $parts[] = trim((string)($c['requested_check_in'] ?? '') . ' → ' . (string)($c['requested_check_out'] ?? ''), ' →');
              if ($c['requested_guests']) $parts[] = (int)$c['requested_guests'] . ' guests';
              if (($c['note'] ?? '') !== '') $parts[] = $c['note'];
              echo e(implode(' · ', $parts));
            ?>
          </span>
          <?= status_badge($c['status'], 'pill', 'style="margin-left:auto"') ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="pa-card">
  <div class="pa-card__body">
    <h2 class="pa-h2" style="font-size:18px">Request a change</h2>
    <p class="pa-sub">Update your dates or guest count — our team confirms availability by email.</p>
    <form data-bm action="/api/booking-change.php">
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <label class="pa-field">New check-in (optional)<input type="date" name="check_in"></label>
      <label class="pa-field">New check-out (optional)<input type="date" name="check_out"></label>
      <label class="pa-field">Guests (optional)<input type="number" name="guests" min="1" max="30"></label>
      <label class="pa-field">Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change"></textarea></label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
      <button type="submit" class="pa-btn pa-btn--primary">Send change request</button>
      <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
    </form>
  </div>
</div>
