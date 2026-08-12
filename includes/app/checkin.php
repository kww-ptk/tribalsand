<?php
/** Guest check-in — landing + wizard with a multi-guest party roster. Expects $hold, $ref, $holdId. */
declare(strict_types=1);
$holdId  = (int)$hold['id'];
$cfg     = checkin_enabled_steps();
$data    = fetch_checkin($holdId) ?? [];
$lead    = checkin_lead_guest($holdId) ?? [];
$welcome = setting('checkin_welcome', '');
$waiverText = checkin_waiver_text();
$done    = checkin_is_complete($hold);
$val     = fn($k, $src = null) => e((string)(($src ?? $data)[$k] ?? ''));
$arrDate = !empty($data['arrival_at']) ? date('Y-m-d\TH:i', strtotime((string)$data['arrival_at'])) : '';

$first   = trim((string)($hold['guest_name'] ?? ''));
$first   = $first !== '' ? explode(' ', $first)[0] : 'there';
$stayLoc = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
$nights  = max(1, (int) round((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400));
$hasProgress = !empty($data) || !empty($lead);

// Which identity/consent fields to render — used by the "Your details" step and
// the intro checklist. The flow build below decides where those steps land.
$showPassport = isset($cfg['passport']);
$showWaiver   = isset($cfg['waiver']);
$guests   = fetch_checkin_guests($holdId);
$adults   = array_values(array_filter($guests, fn($g) => empty($g['is_child'])));
$kids     = [];
foreach ($guests as $g) if (!empty($g['is_child'])) $kids[(int)($g['parent_guest_id'] ?? 0)][] = $g;
$need     = max(1, (int)($hold['guest_count'] ?? 1));

// The lead has finished their own part but the party has not — acknowledge them
// and show who is still outstanding. The portal itself is already unlocked for
// them (booking.php lifts the gate on submitted_at), so this is information,
// not a lock.
// NB: $fullCfg is checkin_config() — every step with its enabled/required flags.
// It is NOT the same as $cfg (checkin_enabled_steps()) already defined above:
// checkin_guest_complete() reads $config['passport']['enabled'], so it needs the
// full map, including disabled steps.
$fullCfg      = checkin_config();
$outstanding  = checkin_outstanding_adults($guests, $fullCfg);
$unnamedSlots = max(0, $need - count($adults));   // adult slots never added to the roster
$leadDone     = checkin_guest_complete($lead ?: null, $fullCfg)
                && checkin_missing_steps($fullCfg, $data, $lead ?: null) === [];
$leadWaiting  = !$done && $leadDone && ($outstanding || $unnamedSlots > 0);

// Short labels for the sentence ("waiting on Patrik and Sarah"); the itemised
// list below uses the full-name form of the same helper.
$waitingNames = [];
foreach ($outstanding as $__g) { $waitingNames[] = checkin_guest_label($__g, $adults, true); }
$waitingLabel = checkin_waiting_on_label($waitingNames, $unnamedSlots);

// Wizard flow: passport + waiver collapse into "Your details" — the lead's own
// identity, consent and signature. Other adults get their own "Your party" step
// so adding a guest can never disturb the lead's signature (the old combined
// step reloaded the page and appeared to wipe it).
$flow = [];
foreach ($cfg as $key => $s) {
    if ($key === 'passport' || $key === 'waiver') {
        if (!isset($flow['you'])) $flow['you'] = ['label' => 'Your details', 'required' => true];
        continue;
    }
    $flow[$key] = $s;
}
// "Your party" only exists when there is something to manage: more than one
// adult, and at least one of passport/waiver enabled (so "you" was created).
if (isset($flow['you']) && $need > 1) {
    $rebuilt = [];
    foreach ($flow as $k => $v) {
        $rebuilt[$k] = $v;
        if ($k === 'you') $rebuilt['party'] = ['label' => 'Your party', 'required' => true];
    }
    $flow = $rebuilt;
}

$needs = [];
if ($showPassport)          $needs[] = ['&#128179;', 'A passport for every adult — a clear photo or PDF'];
if (isset($cfg['arrival'])) $needs[] = ['&#9992;&#65039;', 'Flight number &amp; arrival time'];

?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/../../css/portal-app.css') ?: time() ?>">

<?php if ($done): ?>
<div class="pa-card ci-done-card">
  <div class="ci-done-card__check">&#10003;</div>
  <h2>You're all checked in</h2>
  <p>Thank you, <?= e($first) ?>. Everyone in your party is set — you can update details any time before arrival.</p>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
  <?php if (checkin_guest_waiver_signed($lead)): ?>
  <a class="pa-btn pa-btn--ghost" href="/admin/consent-print.php?hold=<?= $holdId ?>&guest=<?= (int)($lead['id'] ?? 0) ?>&ref=<?= e($ref) ?>" target="_blank">Download my signed waiver</a>
  <?php endif; ?>
  <button type="button" class="pa-btn pa-btn--ghost" id="ciEdit">Update my details</button>
</div>
<?php endif; ?>

<?php if ($leadWaiting): ?>
<div class="pa-card ci-done-card">
  <div class="ci-done-card__check">&#10003;</div>
  <h2>Thank you, <?= e($first) ?>. Your check-in is complete.</h2>
  <p>
    <?php if ($waitingLabel !== ''): ?>We&rsquo;re still waiting on <strong><?= e($waitingLabel) ?></strong>. <?php endif; ?>
    Once everyone in your party has checked in, your reservation is fully confirmed.
  </p>
  <?php if ($outstanding): ?>
  <div class="ci-others" style="text-align:left">
    <p class="ci-need__title">Still to check in</p>
    <?php foreach ($outstanding as $og): $ogid = (int)($og['id'] ?? 0); if (!$ogid) continue; ?>
    <div class="ci-other__row">
      <span><?= e(checkin_guest_label($og, $adults)) ?></span>
      <span class="ci-chip">Pending</span>
    </div>
    <div class="ci-linkrow" style="margin-bottom:12px">
      <input class="ci-in" readonly value="<?= e(make_guest_pass_url($holdId, $ogid)) ?>" onclick="this.select()">
      <button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
  <?php if (checkin_guest_waiver_signed($lead)): ?>
  <a class="pa-btn pa-btn--ghost" href="/admin/consent-print.php?hold=<?= $holdId ?>&guest=<?= (int)($lead['id'] ?? 0) ?>&ref=<?= e($ref) ?>" target="_blank">Download my signed waiver</a>
  <?php endif; ?>
  <button type="button" class="pa-btn pa-btn--ghost" id="ciEdit">Update my details</button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['ci_error'])): ?>
<div class="ci-alert"><?= e($_SESSION['ci_error']) ?></div>
<?php unset($_SESSION['ci_error']); endif; ?>

<form id="ciForm" class="ci-wizard" method="post" action="/api/checkin-save.php" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="do" value="save">

  <?php if (!$done && !$leadWaiting): ?>
  <section class="ci-intro" id="ciIntro">
    <div class="ci-hero">
      <div class="ci-hero__eyebrow">Pre-arrival check-in</div>
      <h1 class="ci-hero__title">Karibu, <?= e($first) ?></h1>
      <p class="ci-hero__sub"><?= $welcome !== '' ? e($welcome) : 'A few quick details before you arrive — every adult in your party checks in, then you\'re set for a warm, paperwork-free welcome.' ?></p>
    </div>
    <div class="pa-card ci-trip">
      <?php if ($stayLoc !== ''): ?><div class="ci-trip__row"><span>Your stay</span><strong><?= e($stayLoc) ?></strong></div><?php endif; ?>
      <div class="ci-trip__row"><span>Dates</span><strong><?= e(date('D j M', strtotime((string)$hold['check_in']))) ?> &rarr; <?= e(date('D j M', strtotime((string)$hold['check_out']))) ?> <span class="ci-trip__muted">· <?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span></strong></div>
      <div class="ci-trip__row"><span>Party</span><strong><?= $need ?> adult<?= $need === 1 ? '' : 's' ?></strong></div>
      <div class="ci-trip__row"><span>Booking</span><strong><code><?= e((string)($hold['access_code'] ?? '')) ?></code></strong></div>
    </div>
    <?php if ($needs): ?>
    <div class="ci-need"><p class="ci-need__title">Have these handy</p>
      <?php foreach ($needs as $nd): ?><div class="ci-need__item"><span class="ci-need__ic"><?= $nd[0] ?></span><span><?= $nd[1] ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="button" class="pa-btn pa-btn--primary ci-start" id="ciStart"><?= $hasProgress ? 'Continue check-in' : 'Start check-in' ?> &rarr;</button>
    <p class="ci-intro__note">🔒 Your details are private and shared only with the Tribal Sand team.</p>
  </section>
  <?php endif; ?>

  <div class="ci-steps" id="ciSteps" hidden>
    <div class="ci-progress"><div class="ci-progress__bar" id="ciBar"></div></div>

    <?php $i = 0; $n = count($flow); foreach ($flow as $key => $s): $i++; ?>
    <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>"<?= ($key === 'you' && $showPassport && !empty($cfg['passport']['required'])) ? ' data-passport-required' : '' ?> hidden>
      <div class="ci-step__h"><span class="ci-step__num">Step <?= $i ?> of <?= $n ?></span><h3><?= e($s['label']) ?><?= $s['required'] ? ' <span class="ci-req">*</span>' : '' ?></h3></div>

      <?php if ($key === 'arrival'): ?>
        <?php
          $amOn     = checkin_arrival_mode_supported();
          $modes    = checkin_arrival_modes();
          $airports = checkin_airports();
          $mode     = $amOn ? trim((string)($data['arrival_mode'] ?? '')) : '';
          if (!array_key_exists($mode, $modes)) $mode = $amOn ? 'flight' : '';
          $savedAir = trim((string)($data['arrival_airport'] ?? ''));
          // A saved airport that isn't in the catalog came from the "Other" box.
          $airOther = $savedAir !== '' && !array_key_exists($savedAir, $airports);
          $T        = checkin_times();
          $paOn     = checkin_property_arrival_supported();
          // Flight mode captures the landing time in arrival_at, so the time the
          // guest reaches us is a separate field. In road/other, arrival_at IS
          // the time they reach us, so the flag reads its time part.
          $paSaved  = $paOn ? trim((string)($data['property_arrival_time'] ?? '')) : '';
          $paSaved  = $paSaved !== '' ? substr($paSaved, 0, 5) : '';
          $atTime   = !empty($data['arrival_at']) ? date('H:i', strtotime((string)$data['arrival_at'])) : '';
          // No arrival_mode column yet → the legacy form was flight-only, so arrival_at
          // holds a LANDING time. Treat that as flight (same fallback as line 170 and
          // checkin_arrival_complete()) or we would warn on the landing time itself.
          $isFlight = $amOn ? ($mode === 'flight') : true;
          $flagTime = $isFlight ? $paSaved : $atTime;
          $flag     = checkin_arrival_flag($flagTime, $T['ci_from'], $T['ci_to']);
        ?>
        <?php if ($amOn): ?>
        <label class="ci-l">How will you arrive?</label>
        <div class="ci-modes">
          <?php foreach ($modes as $mk => $ml): ?>
          <label class="ci-radio"><input type="radio" class="ci-f-mode" name="arrival_mode" value="<?= e($mk) ?>" <?= $mode === $mk ? 'checked' : '' ?>> <?= e($ml) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="ci-mode-fields" data-mode="flight"<?= ($amOn && $mode !== 'flight') ? ' hidden' : '' ?>>
          <label class="ci-l">Airport of arrival</label>
          <select class="ci-in ci-f-airport" name="arrival_airport">
            <option value="">— select —</option>
            <?php foreach ($airports as $av => $al): ?>
            <option value="<?= e($av) ?>" <?= $savedAir === $av ? 'selected' : '' ?>><?= e($al) ?></option>
            <?php endforeach; ?>
            <option value="__other" <?= $airOther ? 'selected' : '' ?>>Other — I&rsquo;ll type it</option>
          </select>
          <div class="ci-airport-other"<?= $airOther ? '' : ' hidden' ?>>
            <label class="ci-l">Which airport?</label>
            <input class="ci-in" name="arrival_airport_other" value="<?= $airOther ? e($savedAir) : '' ?>" placeholder="e.g. Nairobi JKIA">
          </div>
          <label class="ci-l">Flight number</label>
          <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
        </div>

        <div class="ci-mode-fields" data-mode="road"<?= ($amOn && $mode === 'road') ? '' : ' hidden' ?>>
          <label class="ci-l">Vehicle / number plate <span class="ci-opt">(optional)</span></label>
          <input class="ci-in" name="arrival_vehicle" value="<?= $val('arrival_vehicle') ?>" placeholder="e.g. white Land Cruiser, KDD 123A">
        </div>

        <div class="ci-mode-fields" data-mode="other"<?= ($amOn && $mode === 'other') ? '' : ' hidden' ?>>
          <label class="ci-l">How are you arriving?</label>
          <input class="ci-in" name="arrival_note" value="<?= $val('arrival_note') ?>" placeholder="e.g. by boat, or dropped off by a tour operator">
        </div>

        <label class="ci-l" data-mode-label="flight"<?= $mode === 'flight' ? '' : ' hidden' ?>>Flight arrival <span class="ci-opt">(landing time)</span></label>
        <label class="ci-l" data-mode-label="other"<?= $mode === 'flight' ? ' hidden' : '' ?>>When do you expect to reach us?</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">

        <?php if ($paOn): ?>
        <div class="ci-mode-fields" data-mode="flight"<?= ($amOn && $mode !== 'flight') ? ' hidden' : '' ?>>
          <label class="ci-l">What time do you expect to reach us?</label>
          <input class="ci-in ci-f-patime" type="time" name="property_arrival_time" value="<?= e($paSaved) ?>">
        </div>
        <?php endif; ?>

        <p class="ci-times" data-ci-from="<?= e($T['ci_from']) ?>" data-ci-to="<?= e($T['ci_to']) ?>">
          Check-in is from <strong><?= e($T['ci_from']) ?></strong> to <strong><?= e($T['ci_to']) ?></strong>.
          Check-out is between <strong><?= e($T['co_from']) ?></strong> and <strong><?= e($T['co_to']) ?></strong>.
        </p>
        <div class="ci-arrwarn" aria-live="polite"<?= $flag === '' ? ' hidden' : '' ?>>
          <p class="ci-arrwarn__t"></p>
          <p class="ci-arrwarn__n"><?= e($T['note']) ?></p>
          <a class="ci-arrwarn__a" href="/booking.php?ref=<?= e($ref) ?>&amp;view=messages">Message the team &rarr;</a>
        </div>

      <?php elseif ($key === 'transfer'): ?>
        <label class="ci-l">Would you like us to arrange your airport transfer?</label>
        <?php $nt = $data['needs_transfer'] ?? null; $ntYes = ($nt === true || $nt === 't' || $nt === '1'); $ntNo = ($nt === false || $nt === 'f' || $nt === '0'); ?>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I'll make my own way</label>
        <label class="ci-l">Transfer details (pickup point, pax, luggage)</label>
        <textarea class="ci-in" name="transfer_details" rows="3"><?= $val('transfer_details') ?></textarea>

      <?php elseif ($key === 'you'): ?>
        <!-- Lead card — part of #ciForm (no guest_id → the lead row) -->
        <div class="ci-guest ci-guest--lead">
          <div class="ci-guest__title"><span class="ci-guest__who">You (lead guest)</span>
            <span class="ci-chip <?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'ci-chip--ok' : '' ?>"><?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'Complete' : 'Your details' ?></span></div>
          <?php if ($showPassport): ?>
          <label class="ci-l">Full name (as on passport)</label>
          <input class="ci-in" name="passport_name" value="<?= $val('passport_name', $lead) ?>">
          <label class="ci-l">Passport number</label>
          <input class="ci-in" name="passport_number" value="<?= $val('passport_number', $lead) ?>">
          <label class="ci-l">Nationality</label>
          <input class="ci-in" name="nationality" value="<?= $val('nationality', $lead) ?>">
          <label class="ci-l">Passport expiry</label>
          <input class="ci-in" type="date" name="passport_expiry" value="<?= $val('passport_expiry', $lead) ?>">
          <label class="ci-l">Passport scan (photo or PDF)</label>
          <div class="ci-upload" data-has="<?= !empty($lead['passport_file_key']) ? '1' : '0' ?>">
            <input type="file" accept="image/jpeg,image/png,application/pdf">
            <span class="ci-upload__state"><?= !empty($lead['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
          </div>
          <?php endif; ?>
          <?php if ($showWaiver): $leadSigned = checkin_guest_waiver_signed($lead); ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1" <?= $leadSigned ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <!-- data-signed drives the client gate: an existing signature satisfies it
               without the guest having to draw again. The hidden input stays empty
               unless they re-sign, and api/checkin-save.php only overwrites a stored
               signature when a valid new one is posted. -->
          <div class="ci-signwrap" data-signed="<?= $leadSigned ? '1' : '0' ?>">
            <?php if ($leadSigned): ?>
            <div class="ci-signed">
              <img class="ci-signed__img" src="<?= e((string)$lead['waiver_signature']) ?>" alt="Your signature">
              <span class="ci-signed__meta">Signed by <?= e((string)$lead['waiver_signed_name']) ?><br><?= e(date('j M Y', strtotime((string)$lead['waiver_signed_at']))) ?></span>
              <button type="button" class="ci-signed__redo" data-resign>Re-sign</button>
            </div>
            <?php endif; ?>
            <div class="ci-signpad"<?= $leadSigned ? ' hidden' : '' ?>>
              <label class="ci-l">Sign below with your finger</label>
              <div class="ci-sign">
                <button type="button" class="ci-sign-clear">Clear</button>
                <canvas class="ci-sign-pad" data-target="#ciLeadSig"></canvas>
              </div>
              <p class="ci-sign-hint">Reception can fill your details, but you sign yourself.</p>
            </div>
          </div>
          <input type="hidden" name="waiver_signature" id="ciLeadSig">
          <?php endif; ?>
          <div class="ci-kids" data-parent="<?= (int)($lead['id'] ?? 0) ?>">
            <?php foreach (($kids[(int)($lead['id'] ?? 0)] ?? []) as $c): ?>
            <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
            <?php endforeach; ?>
            <button type="button" class="ci-addkid">+ Add child</button>
          </div>
        </div>

      <?php elseif ($key === 'party'): ?>
        <p class="ci-party__head">Every other adult needs <?= $showPassport ? 'their own passport' : '' ?><?= $showPassport && $showWaiver ? ' and ' : '' ?><?= $showWaiver ? 'to sign the waiver' : '' ?>. Add each guest, then fill their details or send them their own link.</p>

        <!-- Additional adult cards (data-field inputs → saved via per-guest AJAX, NOT the main submit) -->
        <?php foreach ($adults as $g): if (!empty($g['is_lead'])) continue; $gid = (int)$g['id'];
              $gc = checkin_guest_passport_complete($g) && (!$showWaiver || checkin_guest_waiver_signed($g)); ?>
        <div class="ci-guest" data-guest-id="<?= $gid ?>">
          <div class="ci-guest__title">
            <input class="ci-in ci-guest__name" data-field="passport_name" value="<?= e((string)$g['passport_name']) ?>" placeholder="Guest full name">
            <span class="ci-chip <?= $gc ? 'ci-chip--ok' : '' ?>"><?= $gc ? 'Complete' : 'Pending' ?></span>
            <button type="button" class="ci-guest__remove" aria-label="Remove guest">&times;</button>
          </div>
          <div class="ci-guest__modes">
            <button type="button" class="ci-mode ci-guest__fill">Fill in for them</button>
            <button type="button" class="ci-mode ci-guest__share">Send them a link</button>
          </div>
          <div class="ci-guest__inline" hidden>
            <?php if ($showPassport): ?>
            <label class="ci-l">Passport number</label>
            <input class="ci-in" data-field="passport_number" value="<?= e((string)$g['passport_number']) ?>">
            <label class="ci-l">Nationality</label>
            <input class="ci-in" data-field="nationality" value="<?= e((string)$g['nationality']) ?>">
            <label class="ci-l">Passport expiry</label>
            <input class="ci-in" type="date" data-field="passport_expiry" value="<?= e((string)$g['passport_expiry']) ?>">
            <label class="ci-l">Passport scan</label>
            <div class="ci-upload" data-has="<?= !empty($g['passport_file_key']) ? '1' : '0' ?>">
              <input type="file" accept="image/jpeg,image/png,application/pdf">
              <span class="ci-upload__state"><?= !empty($g['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($showWaiver): ?>
            <p class="ci-hint">They sign the waiver themselves — use “Send them a link”, or “Sign on this device” from the admin check-in tab if they’re with you.</p>
            <?php endif; ?>
            <button type="button" class="pa-btn pa-btn--primary ci-guest__save">Save this guest</button>
          </div>
          <div class="ci-guest__link" hidden>
            <label class="ci-l">Their private check-in link</label>
            <div class="ci-linkrow"><input class="ci-in" readonly value="<?= e(make_guest_pass_url($holdId, $gid)) ?>" onclick="this.select()"><button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button></div>
          </div>
          <div class="ci-kids" data-parent="<?= $gid ?>">
            <?php foreach (($kids[$gid] ?? []) as $c): ?>
            <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
            <?php endforeach; ?>
            <button type="button" class="ci-addkid">+ Add child</button>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Cloned by js/checkin-wizard.js when a new adult is added. Template
             content is inert, so its inputs never post and never match a
             document querySelectorAll. -->
        <template id="ciGuestTpl">
          <div class="ci-guest" data-guest-id="">
            <div class="ci-guest__title">
              <input class="ci-in ci-guest__name" data-field="passport_name" value="" placeholder="Guest full name">
              <span class="ci-chip">Pending</span>
              <button type="button" class="ci-guest__remove" aria-label="Remove guest">&times;</button>
            </div>
            <div class="ci-guest__modes">
              <button type="button" class="ci-mode ci-guest__fill">Fill in for them</button>
              <button type="button" class="ci-mode ci-guest__share">Send them a link</button>
            </div>
            <div class="ci-guest__inline" hidden>
              <?php if ($showPassport): ?>
              <label class="ci-l">Passport number</label>
              <input class="ci-in" data-field="passport_number" value="">
              <label class="ci-l">Nationality</label>
              <input class="ci-in" data-field="nationality" value="">
              <label class="ci-l">Passport expiry</label>
              <input class="ci-in" type="date" data-field="passport_expiry" value="">
              <label class="ci-l">Passport scan</label>
              <div class="ci-upload" data-has="0">
                <input type="file" accept="image/jpeg,image/png,application/pdf">
                <span class="ci-upload__state">No file yet</span>
              </div>
              <?php endif; ?>
              <?php if ($showWaiver): ?>
              <p class="ci-hint">They sign the waiver themselves — use “Send them a link”, or “Sign on this device” from the admin check-in tab if they’re with you.</p>
              <?php endif; ?>
              <button type="button" class="pa-btn pa-btn--primary ci-guest__save">Save this guest</button>
            </div>
            <div class="ci-guest__link" hidden>
              <label class="ci-l">Their private check-in link</label>
              <div class="ci-linkrow"><input class="ci-in" readonly value="" onclick="this.select()"><button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button></div>
            </div>
            <div class="ci-kids" data-parent="">
              <button type="button" class="ci-addkid">+ Add child</button>
            </div>
          </div>
        </template>

        <button type="button" class="pa-btn pa-btn--ghost ci-addguest" data-need="<?= $need ?>" <?= count($adults) >= $need ? 'hidden' : '' ?>>+ Add adult (<?= count($adults) ?>/<?= $need ?>)</button>

      <?php elseif ($key === 'dietary'): ?>
        <label class="ci-l">Dietary requirements / allergies</label>
        <textarea class="ci-in" name="dietary" rows="4"><?= $val('dietary') ?></textarea>

      <?php elseif ($key === 'requests'): ?>
        <label class="ci-l">Anything to make your stay special?</label>
        <textarea class="ci-in" name="special_requests" rows="4" placeholder="Birthday surprise, a bottle of wine in the room…"><?= $val('special_requests') ?></textarea>
      <?php endif; ?>

      <div class="ci-nav">
        <button type="button" class="pa-btn pa-btn--ghost ci-back">&larr; Back</button>
        <?php if ($i < $n): ?>
          <button type="button" class="pa-btn pa-btn--primary ci-next">Save &amp; continue &rarr;</button>
        <?php else: ?>
          <button type="submit" class="pa-btn pa-btn--primary ci-submit" name="do" value="submit">Complete check-in</button>
        <?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <p class="ci-help"><a href="/booking.php?ref=<?= e($ref) ?>&view=messages">Message the team</a> if you need help.</p>
</form>
<script src="/js/signature-pad.js?v=<?= @filemtime(__DIR__ . '/../../js/signature-pad.js') ?: time() ?>" defer></script>
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
