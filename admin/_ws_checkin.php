<?php /** Workspace Check-in tab. Expects $hold, $holdId. */ ?>
<?php if (!checkin_supported()): ?>
<div class="card"><div class="card__body">Run the <code>add_checkin.sql</code> migration to enable check-in.</div></div>
<?php else: ?>
<?php
$__ci     = fetch_checkin($holdId);
// Reception should be able to fill in the lead guest before that guest has ever
// opened the portal, so make sure the row exists rather than showing an empty
// roster. Idempotent (ON CONFLICT), and gated on check-in actually being
// required so bookings that need no roster never get a stray row.
if (checkin_required($hold)) checkin_ensure_lead_guest_id($holdId);
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

<?php
  // Status header descriptor — icon tint + headline + subline, one source of truth.
  if ($__state === 'complete') {
      $__hIcon = 'check'; $__hVar = 'done';
      $__hTitle = 'Checked in';
      $__hSub = 'Completed ' . e(date('j M Y \a\t H:i', strtotime((string)$hold['checkin_completed_at'])));
  } elseif ($__state === 'pending') {
      $__hIcon = 'clock'; $__hVar = 'pending';
      $__hTitle = (int)$__party['complete'] . ' of ' . (int)$__party['total'] . ' adults checked in';
      $__hSub = 'Check-in required — awaiting the rest of the party';
  } else {
      $__hIcon = 'ban'; $__hVar = 'none';
      $__hTitle = 'Check-in not required';
      $__hSub = 'This booking has no pre-arrival check-in';
  }
?>
<div class="card" style="margin-bottom:16px"><div class="card__body ci-head">
  <div class="ci-head__status">
    <span class="ci-head__icon ci-head__icon--<?= $__hVar ?>"><?= admin_icon($__hIcon, 20) ?></span>
    <div class="ci-head__lines">
      <div class="ci-head__title"><?= $__hTitle ?></div>
      <div class="ci-head__sub"><?= $__hSub ?></div>
    </div>
  </div>
  <?php if ($__canDocs): ?>
  <div class="ci-head__actions">
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" class="ci-adults">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="hidden" name="action" value="guest_count_set">
      <label class="ci-adults__lbl" for="ciGuestCount">Adults</label>
      <input type="number" id="ciGuestCount" name="guest_count" min="1" max="12" value="<?= (int)($hold['guest_count'] ?? 1) ?>" class="inp inp--sm inp--num no-spin" aria-label="Number of adults">
      <button type="submit" class="btn-sm btn-primary">Save</button>
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
  // Blank = a legacy row from the flight-only form (or a pre-migration read),
  // which is why the field group below has always shown Airport + Flight for it.
  // The timestamp label must follow the same rule or reception reads a landing
  // time as "Arrival". Not the guest-side formula: nothing normalises a blank
  // mode to 'flight' here, so $__mode stays '' even post-migration.
  $__isFlight = ($__mode === '' || $__mode === 'flight');
  $__paOn  = checkin_property_arrival_supported();
  // Flag the time the guest wants their room against the check-in window, so
  // reception reads "early"/"late" instead of comparing the clock themselves.
  // The time comes from checkin_desired_time() — the same resolver the guest
  // portal renders the field with (the dedicated column, else a legacy
  // road/other arrival_at that already IS that time), so admin and the portal
  // cannot disagree, and neither can this badge and the number it sits next to.
  $__T     = checkin_times();
  $__pat   = checkin_desired_time($__ci);
  $__flag  = checkin_arrival_flag($__pat, $__T['ci_from'], $__T['ci_to']);
  $__badge = $__flag === ''
    ? ''
    : ' <span class="badge ' . ($__flag === 'early' ? 'badge--orange' : 'badge--blue') . '">'
      . $__flag . '</span>';
?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Arrival &amp; preferences</span></div>
  <div class="ci-facts">
    <?php if ($__amOn): ?>
    <div class="ci-fact"><span class="ci-fact__k">Arriving</span><span class="ci-fact__v"><?= $__mode !== '' ? e($__modes[$__mode] ?? $__mode) : '<span class="text-muted">—</span>' ?></span></div>
    <?php endif; ?>
    <?php if ($__isFlight): ?>
    <div class="ci-fact"><span class="ci-fact__k">Airport</span><span class="ci-fact__v"><?= $__fmt($__ci['arrival_airport'] ?? '') ?></span></div>
    <div class="ci-fact"><span class="ci-fact__k">Flight</span><span class="ci-fact__v"><?= $__fmt($__ci['flight_number'] ?? '') ?></span></div>
    <?php elseif ($__mode === 'road'): ?>
    <div class="ci-fact"><span class="ci-fact__k">Vehicle</span><span class="ci-fact__v"><?= $__fmt($__ci['arrival_vehicle'] ?? '') ?></span></div>
    <?php else: ?>
    <div class="ci-fact"><span class="ci-fact__k">Arriving by</span><span class="ci-fact__v"><?= $__fmt($__ci['arrival_note'] ?? '') ?></span></div>
    <?php endif; ?>
    <?php // Pre-migration there is no desired-time row, so a road/other arrival_at
          // keeps carrying the badge here exactly as it always did. ?>
    <div class="ci-fact"><span class="ci-fact__k"><?= $__isFlight ? 'Flight lands' : 'Arrival' ?></span><span class="ci-fact__v"><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?><?= (!$__isFlight && !$__paOn) ? $__badge : '' ?></span></div>
    <?php if ($__paOn): ?>
    <?php // Asked of every mode since the arrival step was restructured, so it is
          // shown for every mode — a road guest's answer is reception's too. ?>
    <div class="ci-fact"><span class="ci-fact__k">Wants to check in</span><span class="ci-fact__v"><?php
      echo $__pat !== '' ? e($__pat) . $__badge : '<span class="text-muted">—</span>';
    ?></span></div>
    <?php endif; ?>
    <?php // "Yes" with no detail is now the normal flier path — the pickup box only
          // renders for a non-flier, so transfer_details is legitimately empty for
          // everyone who flies. Only append the dash when there is something to show. ?>
    <div class="ci-fact"><span class="ci-fact__k">Transfer</span><span class="ci-fact__v"><?php
      $nt = $__ci['needs_transfer'] ?? null;
      $__td = trim((string)($__ci['transfer_details'] ?? ''));
      echo ($nt === null) ? '<span class="text-muted">—</span>'
         : (($nt === true || $nt === 't') ? ($__td !== '' ? 'Yes — ' . e($__td) : 'Yes') : 'No');
    ?></span></div>
    <?php if (checkin_departure_transfer_supported()): ?>
    <div class="ci-fact"><span class="ci-fact__k">Check-out transfer</span><span class="ci-fact__v"><?php
      $__dt = $__ci['needs_departure_transfer'] ?? null;
      if ($__dt === null) { echo '<span class="text-muted">—</span>'; }
      elseif ($__dt === true || $__dt === 't') {
        $__dd = trim((string)($__ci['departure_destination'] ?? ''));
        $__dp = trim((string)($__ci['departure_time'] ?? ''));
        echo 'Yes — ' . ($__dd !== '' ? e($__dd) : '<span class="text-muted">no destination</span>')
           . ($__dp !== '' ? ' at <strong>' . e(substr($__dp, 0, 5)) . '</strong>' : '');
      } else { echo 'No'; }
    ?></span></div>
    <?php endif; ?>
    <div class="ci-fact"><span class="ci-fact__k">Dietary</span><span class="ci-fact__v"><?= $__fmt($__ci['dietary'] ?? '') ?></span></div>
    <div class="ci-fact"><span class="ci-fact__k">Requests</span><span class="ci-fact__v"><?= $__fmt($__ci['special_requests'] ?? '') ?></span></div>
    <?php if (checkin_deposit_supported()): $__dep = checkin_venue_deposit($hold); $__depCard = checkin_deposit_card_on_file($__ci); ?>
    <div class="ci-fact"><span class="ci-fact__k">Security deposit</span><span class="ci-fact__v">
      <?= $__dep['formatted'] !== '' ? '<strong>' . e($__dep['formatted']) . '</strong> · taken at property' : '<span class="text-muted">no amount set</span>' ?>
    </span></div>
    <div class="ci-fact"><span class="ci-fact__k">Card image</span><span class="ci-fact__v">
      <?php if ($__depCard && $__canDocs): ?><a href="/admin/checkin-file.php?hold=<?= $holdId ?>&kind=deposit" target="_blank" class="btn-sm btn-outline">View card</a>
      <?php elseif ($__depCard): ?>On file ✓ <span class="text-muted">(restricted)</span>
      <?php else: ?><span class="text-muted">Not uploaded</span><?php endif; ?>
    </span></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card"><div class="card__head"><span class="card__title">Guests</span><?php if ($__canDocs): ?><button type="button" class="btn-sm btn-outline" data-ci-addadult><?= admin_icon('plus', 14) ?> Add adult</button><?php endif; ?></div><div class="card__body" style="padding:0">
  <?php if (!$__adults): ?>
  <p class="text-muted" style="margin:0;padding:20px"><?= $__canDocs
      ? 'Nothing captured yet — use “Add adult” above to add each adult, then fill in their passport details. They sign for themselves.'
      : 'No guest identity captured yet.' ?></p>
  <?php else: ?>
  <?php $__ncol = $__canDocs ? 7 : 6; ?>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr><th>Adult</th><th>Nationality</th><th>Passport #</th><th>Waiver</th><th>Scan</th><th>Status</th><?php if ($__canDocs): ?><th>Actions</th><?php endif; ?></tr>
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
        <td><?php if (!$__canDocs): ?><span class="text-muted">•••• (restricted)</span><?php else:
              $__pn = trim((string)($g['passport_number'] ?? ''));
              $__ex = trim((string)($g['passport_expiry'] ?? ''));
              if ($__pn === '' && $__ex === ''): ?><span class="text-muted">—</span><?php else:
                echo $__pn !== '' ? e($__pn) : '<span class="text-muted">—</span>'; ?>
                <div class="text-muted" style="font-size:12px">Exp <?= $__ex !== '' ? e(date('j M Y', strtotime($__ex))) : '—' ?></div>
              <?php endif; ?>
        <?php endif; ?></td>
        <td>
          <?php if ($__waiverOk): ?>
            Signed by <?= e((string)$g['waiver_signed_name']) ?> on <?= e(date('j M Y', strtotime((string)$g['waiver_signed_at']))) ?>
            <?php if ($__canDocs): ?><br><a href="/record.php?hold=<?= $holdId ?>&guest=<?= (int)$g['id'] ?>" target="_blank" class="btn-sm btn-outline" style="margin-top:4px"><?= admin_icon('download', 14) ?> Consent</a><?php endif; ?>
          <?php else: ?>
            <span class="text-muted">Not signed</span>
            <?php $__signLink = make_guest_pass_url($holdId, (int)$g['id']); if ($__signLink !== ''): ?><br><a href="<?= e($__signLink) ?>&amp;via=reception" target="_blank" class="btn-sm btn-outline" style="margin-top:4px">Sign in</a><?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if (empty($g['passport_file_key'])): ?><span class="text-muted">—</span>
          <?php elseif ($__canDocs): ?><a href="/admin/checkin-file.php?hold=<?= $holdId ?>&guest=<?= (int)$g['id'] ?>" target="_blank" class="btn-sm btn-outline">View scan</a>
          <?php else: ?>On file ✓ <span class="text-muted">(restricted)</span><?php endif; ?>
        </td>
        <td><span class="ci-badge <?= $__done ? 'ci-badge--done' : 'ci-badge--pending' ?>"><?= $__done ? 'Complete' : 'Incomplete' ?></span></td>
        <?php if ($__canDocs): $__gid = (int)$g['id']; ?>
        <td class="ci-actions">
          <button type="button" class="btn-icon" data-ci-toggle="ciEdit<?= $__gid ?>" title="Edit details" aria-label="Edit details" aria-expanded="false"><?= admin_icon('edit') ?></button>
          <button type="button" class="btn-icon" data-ci-toggle="ciChild<?= $__gid ?>" title="Add child" aria-label="Add child" aria-expanded="false"><?= admin_icon('plus') ?></button>
        </td>
        <?php endif; ?>
      </tr>
      <?php if ($__canDocs): ?>
      <?php $__exp = substr((string)($g['passport_expiry'] ?? ''), 0, 10); ?>
      <tr class="ci-editrow" id="ciEdit<?= $__gid ?>" hidden>
        <td colspan="<?= $__ncol ?>" class="ci-editcell"><div class="ci-editinner">
          <button type="button" class="ci-edit__close btn-icon" data-ci-toggle="ciEdit<?= $__gid ?>" title="Close" aria-label="Close"><?= admin_icon('x') ?></button>
          <p class="ci-editinner__title">Edit details — <?= $__fmt($g['passport_name'] ?? '') ?: 'guest' ?></p>

          <form id="ciFill<?= $__gid ?>" method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" class="ci-edit__grid"<?php if ($__waiverOk): ?> onsubmit="return confirm('This guest has already signed the waiver. Saving changes to their passport details will void that signature and require a new one. Continue?')"<?php endif; ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="guest_fill">
            <input type="hidden" name="guest_id" value="<?= $__gid ?>">
            <div class="ci-field">
              <label for="ciName<?= $__gid ?>">Full name (as on passport)</label>
              <input id="ciName<?= $__gid ?>" class="inp inp--sm" name="passport_name" value="<?= e((string)($g['passport_name'] ?? '')) ?>" placeholder="Enter full name">
            </div>
            <div class="ci-field">
              <label for="ciNum<?= $__gid ?>">Passport number</label>
              <input id="ciNum<?= $__gid ?>" class="inp inp--sm" name="passport_number" value="<?= e((string)($g['passport_number'] ?? '')) ?>" placeholder="Enter passport number">
            </div>
            <div class="ci-field">
              <label for="ciNat<?= $__gid ?>">Nationality</label>
              <input id="ciNat<?= $__gid ?>" class="inp inp--sm" name="nationality" value="<?= e((string)($g['nationality'] ?? '')) ?>" placeholder="Enter nationality">
            </div>
            <div class="ci-field">
              <label>Passport expiry</label>
              <button type="button" class="dp-btn inp inp--sm" data-dp-target="ciExp<?= $__gid ?>" data-dp-placeholder="Select date">Select date</button>
              <input type="hidden" id="ciExp<?= $__gid ?>" name="passport_expiry" value="<?= e($__exp) ?>">
            </div>
          </form>

          <div class="ci-edit__foot">
            <div class="ci-edit__foot-left">
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" enctype="multipart/form-data" class="ci-upload-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_upload">
                <input type="hidden" name="guest_id" value="<?= $__gid ?>">
                <div class="filefield">
                  <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('image', 15) ?> Choose file<input type="file" name="passport" accept="image/jpeg,image/png,application/pdf" data-file-input required></label>
                  <span class="filefield__name" data-file-name>No file chosen</span>
                </div>
                <button type="submit" class="btn-sm btn-outline">Upload scan</button>
              </form>
            </div>
            <div class="ci-edit__foot-right">
              <?php if (empty($g['is_lead'])): ?>
              <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0" onsubmit="return confirm('Remove this guest and their children from the booking?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guest_remove">
                <input type="hidden" name="guest_id" value="<?= $__gid ?>">
                <button type="submit" class="btn-sm btn-danger"><?= admin_icon('trash', 14) ?> Remove</button>
              </form>
              <?php endif; ?>
              <button type="submit" form="ciFill<?= $__gid ?>" class="btn-sm btn-primary">Save</button>
            </div>
          </div>
        </div></td>
      </tr>
      <tr class="ci-childrow" id="ciChild<?= $__gid ?>" hidden>
        <td colspan="<?= $__ncol ?>" class="ci-editcell"><div class="ci-editinner">
          <button type="button" class="ci-edit__close btn-icon" data-ci-toggle="ciChild<?= $__gid ?>" title="Close" aria-label="Close"><?= admin_icon('x') ?></button>
          <p class="ci-editinner__title">Add a child — <?= $__fmt($g['passport_name'] ?? '') ?: 'guest' ?></p>
          <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" class="ci-add__form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="guest_add_child">
            <input type="hidden" name="parent_guest_id" value="<?= $__gid ?>">
            <div class="ci-field">
              <label for="ciKid<?= $__gid ?>">Child's full name</label>
              <input id="ciKid<?= $__gid ?>" class="inp inp--sm" name="passport_name" placeholder="Enter child's name" required>
            </div>
            <div class="ci-field">
              <label>Date of birth</label>
              <button type="button" class="dp-btn inp inp--sm" data-dp-target="ciDob<?= $__gid ?>" data-dp-past data-dp-placeholder="Select date">Select date</button>
              <input type="hidden" id="ciDob<?= $__gid ?>" name="date_of_birth">
            </div>
            <div class="ci-field ci-field--action">
              <button type="submit" class="btn-sm btn-primary">Add child</button>
            </div>
          </form>
        </div></td>
      </tr>
      <?php endif; ?>
      <?php if ($__kids): ?>
      <tr class="ci-children-row">
        <td></td>
        <td colspan="<?= $__ncol - 1 ?>" style="padding-top:0;padding-bottom:12px">
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
  <?php endif; ?>
</div></div>

<?php if ($__canDocs): ?>
<div class="ci-addadult-modal" id="ciAddAdultModal" hidden>
  <div class="ci-addadult-back" data-ci-addadult-cancel></div>
  <div class="ci-addadult-dialog" role="dialog" aria-modal="true" aria-labelledby="ciAddAdultTitle">
    <button type="button" class="ci-addadult-x btn-icon" data-ci-addadult-cancel title="Close" aria-label="Close"><?= admin_icon('x') ?></button>
    <h3 class="ci-addadult-dialog__title" id="ciAddAdultTitle">Add adult</h3>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" class="ci-addadult-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="guest_add_adult">
      <label class="ci-addadult-form__label" for="ciAddAdultName">New adult's name <span class="text-muted">(optional)</span></label>
      <input id="ciAddAdultName" class="inp inp--sm" name="passport_name" placeholder="Full name">
      <p class="text-muted" style="font-size:12px;margin:8px 0 0">Adding past the party size raises it automatically.</p>
      <div class="ci-addadult-actions">
        <button type="button" class="btn-sm btn-outline" data-ci-addadult-cancel>Cancel</button>
        <button type="submit" class="btn-sm btn-primary">Add adult</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card"><div class="card__body text-muted">Nothing submitted yet.</div></div>
<?php endif; ?>

<script>
/* Check-in tab interactions. Delegated + guarded so they survive the booking-
   workspace AJAX tab swaps and bind only once. */
(function(){
  if (window.__ciTabWired) return; window.__ciTabWired = true;

  /* Styled file inputs (.filefield): reflect the chosen filename. */
  document.addEventListener('change', function(e){
    var fi = e.target && e.target.closest ? e.target.closest('[data-file-input]') : null;
    if (!fi) return;
    var box = fi.closest('.filefield'); if (!box) return;
    var fn = box.querySelector('[data-file-name]'); if (!fn) return;
    fn.textContent = (fi.files && fi.files.length) ? fi.files[0].name : 'No file chosen';
  });

  /* Actions column: the edit / add-child icons toggle the guest's inline row.
     Single-open model — opening one panel closes any other. */
  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('[data-ci-toggle]') : null;
    if (!btn) return;
    e.preventDefault();
    var row = document.getElementById(btn.getAttribute('data-ci-toggle'));
    if (!row) return;
    var willOpen = row.hasAttribute('hidden');
    document.querySelectorAll('.ci-editrow, .ci-childrow').forEach(function(r){ r.setAttribute('hidden',''); });
    document.querySelectorAll('[data-ci-toggle]').forEach(function(b){ b.classList.remove('is-active'); b.setAttribute('aria-expanded','false'); });
    if (willOpen) {
      row.removeAttribute('hidden');
      btn.classList.add('is-active'); btn.setAttribute('aria-expanded','true');
      var f = row.querySelector('input:not([type=hidden]), .dp-btn'); if (f) try { f.focus(); } catch(_){}
    }
  });

  /* "+ Add adult": open the modal popup; the backdrop, Cancel and × close it. */
  function ciCloseAddAdult(){ var m = document.getElementById('ciAddAdultModal'); if (m) m.setAttribute('hidden',''); }
  document.addEventListener('click', function(e){
    var add = e.target && e.target.closest ? e.target.closest('[data-ci-addadult]') : null;
    if (add){
      e.preventDefault();
      var modal = document.getElementById('ciAddAdultModal');
      if (modal){ modal.removeAttribute('hidden');
        var i = modal.querySelector('input[name=passport_name]'); if (i){ i.value=''; try { i.focus(); } catch(_){} } }
      return;
    }
    if (e.target && e.target.closest && e.target.closest('[data-ci-addadult-cancel]')){
      e.preventDefault(); ciCloseAddAdult(); return;
    }
  });
  /* Escape closes the modal. */
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape'){ var m = document.getElementById('ciAddAdultModal'); if (m && !m.hasAttribute('hidden')) ciCloseAddAdult(); }
  });
})();
</script>

<?php endif; ?>
