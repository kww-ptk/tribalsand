<?php /** Workspace Details tab. Expects $hold, $holdId, $portalUrl. Owner-only. */ ?>
<div class="card">
  <div class="card__head"><span class="card__title">Booking details</span></div>
  <div class="card__body" style="padding:20px">
    <div class="detail-grid">
      <div>
        <div class="detail-item__label">Guest</div>
        <div class="detail-item__value"><?= e($hold['guest_name'] ?: '—') ?></div>
      </div>
      <div>
        <div class="detail-item__label">Email</div>
        <div class="detail-item__value"><?php if (!empty($hold['guest_email'])): ?><a href="mailto:<?= e($hold['guest_email']) ?>"><?= e($hold['guest_email']) ?></a><?php else: ?>—<?php endif; ?></div>
      </div>
      <div>
        <div class="detail-item__label">Property</div>
        <div class="detail-item__value"><?= e(trim(($hold['venue_name'] ?? '') . ' · ' . $hold['room_name'], ' ·')) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Dates</div>
        <div class="detail-item__value"><?= e(date('j M Y', strtotime((string)$hold['check_in']))) ?> → <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Status</div>
        <div class="detail-item__value"><span class="badge badge--<?= ['pending'=>'orange','confirmed'=>'green','cancelled'=>'red','expired'=>'grey'][$hold['status']] ?? 'grey' ?>"><?= e($hold['status']) ?></span></div>
      </div>
      <div>
        <div class="detail-item__label">Guest portal</div>
        <div class="detail-item__value"><?php copy_link_control((string)($hold['access_code'] ?? ''), (string)$portalUrl); ?></div>
      </div>
    </div>

    <?php if (in_array($hold['status'], ['pending','confirmed'], true)): ?>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:14px">
      <?php if ($hold['status'] === 'pending'): ?>
      <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=details" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="hold_id" value="<?= $holdId ?>">
        <input type="hidden" name="action" value="confirm">
        <button class="btn-primary btn-sm" data-confirm="Confirm this booking? The guest is emailed only if the switch beside the button is on."><?= admin_icon('check', 15) ?> Confirm booking</button>
        <?= email_guest_toggle($hold, 'confirm') ?>
      </form>
      <?php endif; ?>
      <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=details" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="hold_id" value="<?= $holdId ?>">
        <input type="hidden" name="action" value="cancel">
        <button class="btn-danger btn-sm" data-confirm="Cancel this booking? Dates will be freed; the guest is emailed only if the switch beside the button is on."><?= admin_icon('x', 15) ?> Cancel booking</button>
        <?= email_guest_toggle($hold, 'cancel') ?>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
