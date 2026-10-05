<?php
/**
 * The staff "New booking" form, shown INLINE at the top of admin/holds.php
 * (above the Pending / Confirmed / New today cards) — it used to be a page of
 * its own. It posts to admin/hold-new.php, which keeps every rule (scope,
 * Maya Ilai villa guard, pending + no TTL) and redirects back here: to the list
 * on success, or to ?new=1 with the error and the typed values on a refusal.
 *
 * Expects: auth.php, db.php, checkin.php loaded. Reads its refusal state from
 * $_SESSION['hold_new_state'] (set by hold-new.php) once.
 */
$__hn = $_SESSION['hold_new_state'] ?? null;
unset($_SESSION['hold_new_state']);
$__hnErr  = (string)($__hn['error'] ?? '');
$__hnOld  = ($__hn['old'] ?? []) + ['unit_id' => '', 'check_in' => '', 'check_out' => '', 'guest_name' => '', 'guest_email' => '', 'guest_count' => 1];
$__hnCheckinDefault = checkin_supported() && setting('checkin_required_default', '0') === '1';
$__hnCheckin = array_key_exists('want_checkin', (array)$__hn) ? (bool)$__hn['want_checkin'] : $__hnCheckinDefault;
$__hnOpen = $__hn !== null || (($_GET['new'] ?? '') === '1');

$__hnOpts  = fetch_room_unit_options();
$__hnScope = admin_venue_ids();          // null = owner (all venues)
if ($__hnScope !== null) {
    $__hnOpts = array_values(array_filter($__hnOpts, fn($o) => in_array((int)($o['venue_id'] ?? 0), $__hnScope, true)));
}
$__hnByVenue = [];
foreach ($__hnOpts as $o) $__hnByVenue[trim((string)($o['venue_name'] ?? '')) ?: 'Other'][] = $o;
$__hnDate = fn($d) => $d ? date('j M Y', strtotime((string)$d)) : '';
?>
<div class="card hn-card" id="newBooking"<?= $__hnOpen ? '' : ' hidden' ?>>
  <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
    <span class="card__title">New booking</span>
    <button type="button" class="btn-icon btn-icon--outline" data-hn-close data-tip="Close" aria-label="Close"><?= admin_icon('x') ?></button>
  </div>
  <div class="card__body" style="padding:18px 20px">
    <?php if ($__hnErr !== ''): ?><div class="alert alert--error" style="margin:0 0 14px"><?= e($__hnErr) ?></div><?php endif; ?>
    <?php if (!$__hnOpts): ?>
      <p style="margin:0;color:var(--muted)">No rooms with units yet — add units to a room first (Website › Rooms).</p>
    <?php else: ?>
    <form method="POST" action="/admin/hold-new.php" class="hn-form" data-shell-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="hn-grid">
        <div class="field hn-span2"><label for="hnUnit">Room</label>
          <select id="hnUnit" name="unit_id" required style="width:100%">
            <option value="">Select a room</option>
            <?php foreach ($__hnByVenue as $vname => $opts): ?>
            <optgroup label="<?= e($vname) ?>">
              <?php foreach ($opts as $o): ?>
              <option value="<?= (int)$o['unit_id'] ?>" <?= (int)$o['unit_id'] === (int)$__hnOld['unit_id'] ? 'selected' : '' ?>><?= e($o['room_name']) ?><?= count(array_filter($opts, fn($x) => $x['room_name'] === $o['room_name'])) > 1 ? ' — ' . e($o['unit_name']) : '' ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Check-in</label>
          <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="hnDates" data-dp-target="hnCheckin" data-dp-placeholder="Select check-in" style="width:100%"><?= e($__hnDate($__hnOld['check_in']) ?: 'Select check-in') ?></button>
          <input type="hidden" id="hnCheckin" name="check_in" value="<?= e((string)$__hnOld['check_in']) ?>">
        </div>
        <div class="field"><label>Check-out</label>
          <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="hnDates" data-dp-target="hnCheckout" data-dp-placeholder="Select check-out" style="width:100%"><?= e($__hnDate($__hnOld['check_out']) ?: 'Select check-out') ?></button>
          <input type="hidden" id="hnCheckout" name="check_out" value="<?= e((string)$__hnOld['check_out']) ?>">
        </div>
        <div class="field"><label for="hnName">Guest name</label>
          <input id="hnName" type="text" name="guest_name" class="inp" required value="<?= e((string)$__hnOld['guest_name']) ?>" placeholder="Enter guest name" style="width:100%"></div>
        <div class="field"><label for="hnEmail">Guest email</label>
          <input id="hnEmail" type="email" name="guest_email" class="inp" required value="<?= e((string)$__hnOld['guest_email']) ?>" placeholder="Enter guest email" style="width:100%"></div>
        <?php if (checkin_supported()): ?>
        <div class="field"><label for="hnCount">Adults</label>
          <input id="hnCount" type="text" inputmode="numeric" pattern="[0-9]*" name="guest_count" class="inp" value="<?= (int)$__hnOld['guest_count'] ?>" placeholder="Enter number of adults" style="width:100%"></div>
        <div class="field hn-check"><label class="ckwrap"><input type="checkbox" name="require_checkin" value="1" <?= $__hnCheckin ? 'checked' : '' ?>><span class="ck"></span> Guest must finish Pre-Check-in first</label></div>
        <?php endif; ?>
      </div>
      <div class="hn-foot">
        <p class="text-muted">Creates a <strong>pending</strong> booking with the guest's login code and blocks the dates. It does not expire — confirm it from the list. Overlaps are not checked, except a Maya Ilai villa with a bedroom already sold.</p>
        <div class="hn-actions">
          <button type="button" class="btn-outline btn-sm" data-hn-close>Cancel</button>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 14) ?> Create booking</button>
        </div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<style>
.hn-card{margin:0 0 16px}
.hn-card[hidden]{display:none}
.hn-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 14px;align-items:end}
.hn-span2{grid-column:span 2}
.hn-grid .field{margin:0;min-width:0}
.hn-grid .field > label:not(.ckwrap){display:block;font-size:12px;color:var(--muted);margin-bottom:4px}
.hn-check{grid-column:span 3;padding-bottom:8px}
.hn-check .ckwrap{display:inline-flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer}
.hn-foot{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-top:16px;flex-wrap:wrap}
.hn-foot p{margin:0;font-size:12.5px;max-width:80ch}
.hn-actions{display:flex;gap:8px;margin-left:auto}
@media (max-width:1000px){.hn-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.hn-check{grid-column:span 2}}
@media (max-width:560px){.hn-grid{grid-template-columns:minmax(0,1fr)}.hn-span2,.hn-check{grid-column:auto}}
</style>
<script>
(function () {
  var card = document.getElementById('newBooking');
  if (!card) return;
  var open = document.querySelector('[data-hn-open]');
  function show(on) {
    card.hidden = !on;
    if (open) open.style.display = on ? 'none' : '';
    if (on) {
      card.scrollIntoView({ block: 'start', behavior: 'smooth' });
      var sel = card.querySelector('.eselect__btn') || card.querySelector('select');
      if (sel) try { sel.focus({ preventScroll: true }); } catch (e) {}
    }
  }
  if (open) {
    open.style.display = card.hidden ? '' : 'none';
    open.addEventListener('click', function (e) { e.preventDefault(); show(true); });
  }
  card.querySelectorAll('[data-hn-close]').forEach(function (b) { b.addEventListener('click', function () { show(false); }); });
})();
</script>
