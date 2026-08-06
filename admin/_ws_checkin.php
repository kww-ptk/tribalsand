<?php /** Workspace Check-in tab. Expects $hold, $holdId. */ ?>
<?php
$__ci   = fetch_checkin($holdId);
$__lead = checkin_lead_guest($holdId);
$__canDocs = can_view_guest_docs($holdId);
$__state = checkin_state($hold);
$__fmt = fn($v) => ($v === null || $v === '') ? '—' : e((string)$v);
?>
<?php if (!checkin_supported()): ?>
<div class="card"><div class="card__body">Run the <code>add_checkin.sql</code> migration to enable check-in.</div></div>
<?php else: ?>

<div class="card" style="margin-bottom:16px"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <div>
    <strong>Status:</strong>
    <?php if ($__state === 'complete'): ?><span class="ci-badge ci-badge--done">Checked in ✓</span> <span class="text-muted"><?= e(date('j M Y H:i', strtotime((string)$hold['checkin_completed_at']))) ?></span>
    <?php elseif ($__state === 'pending'): ?><span class="ci-badge ci-badge--pending">Pending</span>
    <?php else: ?><span class="text-muted">Not required</span><?php endif; ?>
  </div>
  <?php if (is_owner()): ?>
  <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= $holdId ?>">
    <input type="hidden" name="action" value="checkin_toggle">
    <input type="hidden" name="require_checkin" value="<?= !empty($hold['require_checkin']) ? '0' : '1' ?>">
    <button class="btn-sm btn-outline"><?= !empty($hold['require_checkin']) ? 'Turn off requirement' : 'Require check-in' ?></button>
  </form>
  <?php endif; ?>
</div></div>

<?php if ($__ci || $__lead): ?>
<div class="card" style="margin-bottom:16px"><div class="card__body">
  <table class="data-table" style="max-width:600px">
    <tr><td class="text-muted">Airport</td><td><?= $__fmt($__ci['arrival_airport'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Flight</td><td><?= $__fmt($__ci['flight_number'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
    <tr><td class="text-muted">Dietary</td><td><?= $__fmt($__ci['dietary'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Requests</td><td><?= $__fmt($__ci['special_requests'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Waiver</td><td><?php echo !empty($__ci['waiver_signed_at']) ? 'Signed by '.e((string)$__ci['waiver_signed_name']).' · '.e(date('j M Y', strtotime((string)$__ci['waiver_signed_at']))) : '—'; ?></td></tr>
  </table>
</div></div>

<div class="card"><div class="card__head"><span class="card__title">Lead guest — identity</span></div><div class="card__body">
  <?php if ($__lead): ?>
  <table class="data-table" style="max-width:600px">
    <tr><td class="text-muted">Name</td><td><?= $__fmt($__lead['passport_name'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Nationality</td><td><?= $__fmt($__lead['nationality'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Passport #</td><td><?= $__canDocs ? $__fmt($__lead['passport_number'] ?? '') : '<span class="text-muted">•••• (restricted)</span>' ?></td></tr>
    <tr><td class="text-muted">Expiry</td><td><?= $__fmt($__lead['passport_expiry'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Scan</td><td>
      <?php if (empty($__lead['passport_file_key'])): ?>—
      <?php elseif ($__canDocs): ?><a href="/admin/checkin-file.php?hold=<?= $holdId ?>&guest=<?= (int)$__lead['id'] ?>" target="_blank" class="btn-sm btn-outline">View scan →</a>
      <?php else: ?>On file ✓ <span class="text-muted">(restricted)</span><?php endif; ?>
    </td></tr>
  </table>
  <?php else: ?><p class="text-muted" style="margin:0">No identity captured yet.</p><?php endif; ?>
</div></div>
<?php else: ?>
<div class="card"><div class="card__body text-muted">Nothing submitted yet.</div></div>
<?php endif; ?>
<?php endif; ?>
