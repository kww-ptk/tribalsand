<?php /** Workspace Check-in tab. Expects $hold, $holdId. */ ?>
<?php if (!checkin_supported()): ?>
<div class="card"><div class="card__body">Run the <code>add_checkin.sql</code> migration to enable check-in.</div></div>
<?php else: ?>
<?php
$__ci     = fetch_checkin($holdId);
$__guests = fetch_checkin_guests($holdId);
$__adults = array_values(array_filter($__guests, fn($g) => empty($g['is_child'])));
$__kidsByParent = [];
foreach ($__guests as $g) {
    if (!empty($g['is_child'])) $__kidsByParent[(int)($g['parent_guest_id'] ?? 0)][] = $g;
}
$__canDocs = can_view_guest_docs($holdId);
$__state   = checkin_state($hold);
$__fmt     = fn($v) => ($v === null || $v === '') ? '—' : e((string)$v);

// Same rule the Frontdesk/Holds badge subquery uses: adult has both a complete
// passport and a signed waiver. Drives the "X of N adults checked in" status line
// and each row's own complete chip.
$__completeAdults = 0;
foreach ($__adults as $__g) {
    if (checkin_guest_passport_complete($__g) && checkin_guest_waiver_signed($__g)) $__completeAdults++;
}
$__party = checkin_party_status((int)($hold['guest_count'] ?? 1), $__completeAdults);
?>

<div class="card" style="margin-bottom:16px"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <div>
    <strong>Status:</strong>
    <?php if ($__state === 'complete'): ?><span class="ci-badge ci-badge--done">Checked in ✓</span> <span class="text-muted"><?= e(date('j M Y H:i', strtotime((string)$hold['checkin_completed_at']))) ?></span>
    <?php elseif ($__state === 'pending'): ?><span class="ci-badge ci-badge--pending"><?= (int)$__party['complete'] ?> of <?= (int)$__party['total'] ?> adults checked in</span>
    <?php else: ?><span class="text-muted">Not required</span><?php endif; ?>
  </div>
  <?php if ($__canDocs): ?>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="display:flex;align-items:center;gap:6px;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="hidden" name="action" value="guest_count_set">
      <label class="text-muted" style="font-size:12px" for="ciGuestCount">Adults</label>
      <input type="number" id="ciGuestCount" name="guest_count" min="1" max="12" value="<?= (int)($hold['guest_count'] ?? 1) ?>" class="inp inp--sm inp--num no-spin" style="width:64px" aria-label="Number of adults">
      <button type="submit" class="btn-sm btn-outline">Save</button>
    </form>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="hidden" name="action" value="checkin_toggle">
      <input type="hidden" name="require_checkin" value="<?= !empty($hold['require_checkin']) ? '0' : '1' ?>">
      <button class="btn-sm btn-outline"><?= !empty($hold['require_checkin']) ? 'Turn off requirement' : 'Require check-in' ?></button>
    </form>
    <?php if (share_reservation_supported()): ?>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="hidden" name="action" value="share_toggle">
      <input type="hidden" name="share_reservation" value="<?= !empty($hold['share_reservation']) ? '0' : '1' ?>">
      <button class="btn-sm btn-outline"><?= !empty($hold['share_reservation']) ? 'Stop sharing' : 'Share reservation' ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div></div>

<?php
// Also render for anyone who may manage guest docs, even when the guest has
// submitted nothing. Previously this required $__ci or $__adults, so a brand-new
// booking fell through to "Nothing submitted yet." and the "+ Add adult" form
// below — the only way for staff to start a roster — was unreachable. Reception
// could not fill anything in until the guest had already begun themselves.
?>
<?php if ($__ci || $__adults || $__canDocs): ?>

<?php if ($__ci): ?>
<?php
  $__amOn  = checkin_arrival_mode_supported();
  $__mode  = $__amOn ? trim((string)($__ci['arrival_mode'] ?? '')) : '';
  $__modes = checkin_arrival_modes();
?>
<div class="card" style="margin-bottom:16px"><div class="card__body">
  <table class="data-table" style="max-width:600px">
    <?php if ($__amOn): ?>
    <tr><td class="text-muted">Arriving</td><td><?= $__mode !== '' ? e($__modes[$__mode] ?? $__mode) : '<span class="text-muted">—</span>' ?></td></tr>
    <?php endif; ?>
    <?php if ($__mode === '' || $__mode === 'flight'): ?>
    <tr><td class="text-muted">Airport</td><td><?= $__fmt($__ci['arrival_airport'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Flight</td><td><?= $__fmt($__ci['flight_number'] ?? '') ?></td></tr>
    <?php elseif ($__mode === 'road'): ?>
    <tr><td class="text-muted">Vehicle</td><td><?= $__fmt($__ci['arrival_vehicle'] ?? '') ?></td></tr>
    <?php else: ?>
    <tr><td class="text-muted">Arriving by</td><td><?= $__fmt($__ci['arrival_note'] ?? '') ?></td></tr>
    <?php endif; ?>
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
    <tr><td class="text-muted">Dietary</td><td><?= $__fmt($__ci['dietary'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Requests</td><td><?= $__fmt($__ci['special_requests'] ?? '') ?></td></tr>
  </table>
</div></div>
<?php endif; ?>

<div class="card"><div class="card__head"><span class="card__title">Guests</span></div><div class="card__body" style="padding:0">
  <?php if (!$__adults): ?>
  <p class="text-muted" style="margin:0;padding:20px 20px 4px"><?= $__canDocs
      ? 'Nothing captured yet — add each adult below, then fill in their passport details. They sign for themselves.'
      : 'No guest identity captured yet.' ?></p>
  <?php if ($__canDocs): ?>
  <div style="padding:12px">
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="guest_add_adult">
      <input class="inp inp--sm" name="passport_name" placeholder="New adult's name (optional)">
      <button type="submit" class="btn-sm btn-outline">+ Add adult</button>
      <span class="text-muted" style="font-size:12px">Adding past the party size raises it automatically.</span>
    </form>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr><th>Adult</th><th>Nationality</th><th>Passport #</th><th>Expiry</th><th>Waiver</th><th>Scan</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($__adults as $g): ?>
      <?php
        $__passOk   = checkin_guest_passport_complete($g);
        $__waiverOk = checkin_guest_waiver_signed($g);
        $__done     = $__passOk && $__waiverOk;
        $__kids     = $__kidsByParent[(int)$g['id']] ?? [];
      ?>
      <tr>
        <td><?= $__fmt($g['passport_name'] ?? '') ?><?php if (!empty($g['is_lead'])): ?> <span class="badge badge--blue">Lead</span><?php endif; ?></td>
        <td><?= $__fmt($g['nationality'] ?? '') ?></td>
        <td><?= $__canDocs ? $__fmt($g['passport_number'] ?? '') : '<span class="text-muted">•••• (restricted)</span>' ?></td>
        <td><?= $__fmt($g['passport_expiry'] ?? '') ?></td>
        <td>
          <?php if ($__waiverOk): ?>
            Signed by <?= e((string)$g['waiver_signed_name']) ?> on <?= e(date('j M Y', strtotime((string)$g['waiver_signed_at']))) ?>
            <?php if ($__canDocs): ?><br><a href="/admin/consent-print.php?hold=<?= $holdId ?>&guest=<?= (int)$g['id'] ?>" target="_blank" class="btn-sm btn-outline" style="margin-top:4px">Download consent &rarr;</a><?php endif; ?>
          <?php else: ?>
            <span class="text-muted">Not signed</span>
            <?php $__signLink = make_guest_pass_url($holdId, (int)$g['id']); if ($__signLink !== ''): ?><br><a href="<?= e($__signLink) ?>&amp;via=reception" target="_blank" class="btn-sm btn-outline" style="margin-top:4px">Sign on this device &rarr;</a><?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if (empty($g['passport_file_key'])): ?><span class="text-muted">—</span>
          <?php elseif ($__canDocs): ?><a href="/admin/checkin-file.php?hold=<?= $holdId ?>&guest=<?= (int)$g['id'] ?>" target="_blank" class="btn-sm btn-outline">View scan →</a>
          <?php else: ?>On file ✓ <span class="text-muted">(restricted)</span><?php endif; ?>
        </td>
        <td><span class="ci-badge <?= $__done ? 'ci-badge--done' : 'ci-badge--pending' ?>"><?= $__done ? 'Complete' : 'Incomplete' ?></span></td>
      </tr>
      <?php if ($__canDocs): ?>
      <tr class="ci-admin-row">
        <td colspan="7" style="background:var(--bg-alt,#f7f7f5);padding:10px 12px">
          <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
            <details>
              <summary class="btn-sm btn-outline" style="cursor:pointer;display:inline-block">Edit details</summary>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:10px 0 0;display:grid;gap:6px;max-width:320px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_fill">
                <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
                <input class="inp inp--sm" name="passport_name" value="<?= e((string)($g['passport_name'] ?? '')) ?>" placeholder="Full name (as on passport)">
                <input class="inp inp--sm" name="passport_number" value="<?= e((string)($g['passport_number'] ?? '')) ?>" placeholder="Passport number">
                <input class="inp inp--sm" name="nationality" value="<?= e((string)($g['nationality'] ?? '')) ?>" placeholder="Nationality">
                <input class="inp inp--sm" type="date" name="passport_expiry" value="<?= e((string)($g['passport_expiry'] ?? '')) ?>">
                <button type="submit" class="btn-sm btn-primary">Save details</button>
              </form>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" enctype="multipart/form-data" style="margin:10px 0 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_upload">
                <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
                <input type="file" name="passport" accept="image/jpeg,image/png,application/pdf" required>
                <button type="submit" class="btn-sm btn-outline">Upload scan</button>
              </form>
            </details>

            <details>
              <summary class="btn-sm btn-outline" style="cursor:pointer;display:inline-block">+ Add child</summary>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:10px 0 0;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_add_child">
                <input type="hidden" name="parent_guest_id" value="<?= (int)$g['id'] ?>">
                <input class="inp inp--sm" name="passport_name" placeholder="Child's full name" required>
                <input class="inp inp--sm" type="date" name="date_of_birth" aria-label="Date of birth">
                <button type="submit" class="btn-sm btn-outline">Add child</button>
              </form>
            </details>

            <?php if (empty($g['is_lead'])): ?>
            <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0" onsubmit="return confirm('Remove this guest and their children from the booking?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="guest_remove">
              <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="btn-sm btn-outline" style="color:#b23">Remove guest</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php if ($__kids): ?>
      <tr class="ci-children-row">
        <td></td>
        <td colspan="6" style="padding-top:0;padding-bottom:12px">
          <div class="ci-children" style="padding:10px 12px;background:var(--bg-alt,#f7f7f5);border-radius:6px">
            <?php foreach ($__kids as $c): ?>
            <div style="font-size:13px;margin:4px 0">
              <span class="badge badge--grey">Child</span>
              <?= $__fmt($c['passport_name'] ?? '') ?><?php if (!empty($c['date_of_birth'])): ?> <span class="text-muted">· DOB <?= e(date('j M Y', strtotime((string)$c['date_of_birth']))) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($__canDocs): ?>
  <div style="padding:12px">
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="guest_add_adult">
      <input class="inp inp--sm" name="passport_name" placeholder="New adult's name (optional)">
      <button type="submit" class="btn-sm btn-outline">+ Add adult</button>
      <span class="text-muted" style="font-size:12px">Adding past the party size raises it automatically.</span>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div></div>

<?php else: ?>
<div class="card"><div class="card__body text-muted">Nothing submitted yet.</div></div>
<?php endif; ?>

<?php endif; ?>
