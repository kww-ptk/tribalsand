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
$showDeposit  = isset($cfg['deposit']);

// Optional add-ons for this property, minus anything already on the booking —
// so something picked during the enquiry is never offered a second time here.
require_once __DIR__ . '/../upsells.php';
$ciUpsells = isset($cfg['upsell'])
    ? fetch_upsell_items(((int)($hold['venue_id'] ?? 0)) ?: null, 'checkin', $holdId)
    : [];
$deposit      = checkin_venue_deposit($hold);   // ['amount','currency','formatted']
$depositNote  = checkin_deposit_note();
$depositOnFile = checkin_deposit_card_on_file($data);
// "I can't upload my card" → card or cash at arrival (add_checkin_deposit_plan.sql).
// With it, Complete check-in only appears once the deposit is dealt with.
$planOn        = $showDeposit && checkin_deposit_plan_supported();
$depositPlan   = $planOn ? (string)($data['deposit_plan'] ?? '') : '';
$depositHandled = checkin_deposit_handled($data);
// Sharing a guest's personal link (Your party step + the waiting-on-others card).
$shareProperty = trim((string)($hold['venue_name'] ?? ''));
$shareDates    = date('j M', strtotime((string)$hold['check_in'])) . ' – ' . date('j M', strtotime((string)$hold['check_out']));
$shareWa = function (string $name, string $link) use ($shareProperty, $shareDates): string {
    return checkin_whatsapp_url(checkin_guest_share_text($name, $shareProperty, $shareDates, $link));
};
$waIcon = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.5h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.75-.72 2-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.21-3.58.94.96-3.49-.23-.36a9.43 9.43 0 0 1-1.45-5.03c0-5.22 4.25-9.47 9.48-9.47 2.53 0 4.91.99 6.7 2.78a9.41 9.41 0 0 1 2.77 6.7c0 5.23-4.25 9.47-9.47 9.47zm8.06-17.53A11.33 11.33 0 0 0 12.04.63C5.76.63.65 5.74.65 12.02c0 2.01.52 3.97 1.52 5.7L.55 23.62l6.03-1.58a11.36 11.36 0 0 0 5.45 1.39h.01c6.28 0 11.39-5.11 11.39-11.39 0-3.04-1.18-5.9-3.33-8.05z"/></svg>';
$ppHint = 'Upload a photo of the passport’s photo page and we’ll fill in the details for you — or type them below.';
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
    // Nothing left to offer (property switched off, none placed here, or the
    // guest already took them all at enquiry) → no empty step in the wizard.
    if ($key === 'upsell' && !$ciUpsells) continue;
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

// ── Resume position ────────────────────────────────────────────────────────
// The first step the guest still has to deal with, derived entirely from what
// is already stored — so "Continue check-in", and the Resume button on the
// Messages tab, land where they left off instead of back at step 1. Nothing
// extra is persisted, so it survives a device switch and can never go stale
// against a changed step config.
//
// Only a REQUIRED step can hold the resume point: a deliberately blank optional
// step (dietary, special requests) is "incomplete" forever, and resuming there
// every time would trap the guest on a question they already chose to skip.
// Once everything required is done we land on the last step, which carries the
// Complete check-in button.
$flowComplete = function (string $key) use ($data, $lead, $fullCfg, $guests): bool {
    if ($key === 'you')   return checkin_guest_complete($lead ?: null, $fullCfg);
    if ($key === 'party') return checkin_outstanding_adults($guests, $fullCfg) === [];
    return checkin_step_complete($key, $data, $lead ?: null);
};
$flowKeys  = array_keys($flow);
$resumeIdx = 0;
foreach ($flowKeys as $idx => $fk) {
    $resumeIdx = $idx;
    if (!empty($flow[$fk]['required']) && !$flowComplete($fk)) break;
    $resumeIdx = min($idx + 1, max(0, count($flowKeys) - 1));
}
// ?resume=1 (the Messages button) skips the intro and drops straight back in.
$autoResume = ($_GET['resume'] ?? '') === '1';

$needs = [];
if ($showPassport)           $needs[] = ['&#128179;', 'A passport for every adult — a photo of the photo page, or just the details'];
if (isset($cfg['transfer'])) $needs[] = ['&#9992;&#65039;', 'If you&rsquo;d like us to collect you: your flight number &amp; landing time'];
if ($showDeposit)            $needs[] = ['&#128179;', 'Your credit card — for the security deposit'
                                . ($deposit['formatted'] !== '' ? ' (' . e($deposit['formatted']) . ')' : '')
                                . ', paid at the property on arrival'];

?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/../../css/portal-app.css') ?: time() ?>">

<?php if ($done): ?>
<div class="pa-card ci-done-card">
  <div class="ci-done-card__check">&#10003;</div>
  <h2>You're all checked in</h2>
  <p>Thank you, <?= e($first) ?>. Everyone in your party is set — you can update details any time before arrival.</p>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
  <?php if (checkin_guest_waiver_signed($lead)): ?>
  <a class="pa-btn pa-btn--ghost" href="/record.php?hold=<?= $holdId ?>&guest=<?= (int)($lead['id'] ?? 0) ?>&ref=<?= e($ref) ?>" target="_blank">Download my signed waiver</a>
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
    <?php $__ogLink = make_guest_pass_url($holdId, $ogid); ?>
    <div class="ci-linkrow" style="margin-bottom:6px">
      <input class="ci-in" readonly value="<?= e($__ogLink) ?>" onclick="this.select()">
      <button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button>
    </div>
    <a class="pa-btn ci-wa" style="margin-bottom:14px" target="_blank" rel="noopener" href="<?= e($shareWa((string)($og['passport_name'] ?? ''), $__ogLink)) ?>"><?= $waIcon ?>Send on WhatsApp</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
  <?php if (checkin_guest_waiver_signed($lead)): ?>
  <a class="pa-btn pa-btn--ghost" href="/record.php?hold=<?= $holdId ?>&guest=<?= (int)($lead['id'] ?? 0) ?>&ref=<?= e($ref) ?>" target="_blank">Download my signed waiver</a>
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

  <div class="ci-steps" id="ciSteps" data-resume="<?= (int)$resumeIdx ?>"<?= $autoResume ? ' data-autoresume="1"' : '' ?> hidden>
    <div class="ci-progress"><div class="ci-progress__bar" id="ciBar"></div></div>

    <?php $i = 0; $n = count($flow); foreach ($flow as $key => $s): $i++; ?>
    <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>"<?= ($key === 'you' && $showPassport && !empty($cfg['passport']['required'])) ? ' data-passport-required' : '' ?><?= $key === 'transfer' ? ' data-arrival-mode="' . e(checkin_effective_mode($data)) . '"' : '' ?> hidden>
      <div class="ci-step__h"><span class="ci-step__num">Step <?= $i ?> of <?= $n ?></span><h3><?= e($s['label']) ?><?= $s['required'] ? ' <span class="ci-req">*</span>' : '' ?></h3></div>

      <?php if ($key === 'arrival'): ?>
        <?php
          $amOn     = checkin_arrival_mode_supported();
          $modes    = checkin_arrival_modes();
          $mode     = $amOn ? trim((string)($data['arrival_mode'] ?? '')) : '';
          if (!array_key_exists($mode, $modes)) $mode = $amOn ? 'flight' : '';
          $T        = checkin_times();
          $paOn     = checkin_property_arrival_supported();
          // One field for every mode now, so the server render and the live JS
          // read the same string. checkin_desired_time() also prefills from a
          // legacy road/other arrival_at, which the next save then heals.
          $paSaved  = $paOn ? checkin_desired_time($data) : '';
          $flag     = checkin_arrival_flag($paSaved, $T['ci_from'], $T['ci_to']);
        ?>
        <?php if ($amOn): ?>
        <label class="ci-l">How will you arrive?</label>
        <div class="ci-modes">
          <?php foreach ($modes as $mk => $ml): ?>
          <label class="ci-radio"><input type="radio" class="ci-f-mode" name="arrival_mode" value="<?= e($mk) ?>" <?= $mode === $mk ? 'checked' : '' ?>> <?= e($ml) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="ci-mode-fields" data-mode="road"<?= ($amOn && $mode === 'road') ? '' : ' hidden' ?>>
          <label class="ci-l">Vehicle / number plate <span class="ci-opt">(optional)</span></label>
          <input class="ci-in" name="arrival_vehicle" value="<?= $val('arrival_vehicle') ?>" placeholder="e.g. white Land Cruiser, KDD 123A">
        </div>

        <div class="ci-mode-fields" data-mode="other"<?= ($amOn && $mode === 'other') ? '' : ' hidden' ?>>
          <label class="ci-l">How are you arriving?</label>
          <input class="ci-in" name="arrival_note" value="<?= $val('arrival_note') ?>" placeholder="e.g. by boat, or dropped off by a tour operator">
        </div>

        <?php if ($paOn): ?>
        <label class="ci-l">Desired check-in time <span class="ci-opt">(optional)</span></label>
        <input class="ci-in ci-f-patime" type="time" name="property_arrival_time" value="<?= e($paSaved) ?>">
        <?php endif; ?>

        <p class="ci-times" data-ci-from="<?= e($T['ci_from']) ?>" data-ci-to="<?= e($T['ci_to']) ?>">
          Check-in is from <strong><?= e($T['ci_from']) ?></strong> to <strong><?= e($T['ci_to']) ?></strong>.
          Check-out is between <strong><?= e($T['co_from']) ?></strong> and <strong><?= e($T['co_to']) ?></strong>.
        </p>
        <div class="ci-arrwarn" aria-live="polite"<?= $flag === '' ? ' hidden' : '' ?>>
          <p class="ci-arrwarn__t"></p>
          <p class="ci-arrwarn__n"><?= e($T['note']) ?></p>
          <a class="ci-arrwarn__a" data-ci-leave href="/booking.php?ref=<?= e($ref) ?>&amp;view=messages">Message the team &rarr;</a>
        </div>

      <?php elseif ($key === 'upsell'): ?>
        <p class="ci-note">Optional extras we can arrange for your stay. Nothing is charged now &mdash; we&rsquo;ll confirm each one with you.</p>
        <div class="ci-ups">
          <?php foreach ($ciUpsells as $__cu): $__cpl = upsell_price_label($__cu); ?>
          <label class="ci-up">
            <input type="checkbox" name="upsell[]" value="<?= (int)$__cu['id'] ?>">
            <span class="ci-up__tick" aria-hidden="true"></span>
            <span class="ci-up__body">
              <span class="ci-up__name"><?= e((string)$__cu['name']) ?></span>
              <?php if (trim((string)($__cu['short_desc'] ?? '')) !== ''): ?>
              <span class="ci-up__desc"><?= e(mb_strimwidth(trim((string)$__cu['short_desc']), 0, 120, '…')) ?></span>
              <?php endif; ?>
              <span class="ci-up__meta">
                <?php if (trim((string)($__cu['duration'] ?? '')) !== ''): ?><span><?= e((string)$__cu['duration']) ?></span><?php endif; ?>
                <?php if ($__cpl !== ''): ?><span class="ci-up__price"><?= e($__cpl) ?></span><?php endif; ?>
              </span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>

      <?php elseif ($key === 'transfer'): ?>
        <?php
          // Guard on the VALUE, not on the column existing: add_checkin_arrival.sql
          // has no backfill, so the legacy rows this fallback is for have
          // arrival_mode present and NULL. Reading it as flight keeps this step
          // agreeing with the Flight radio step 1 pre-checks for the same row.
          $isFlight2 = checkin_effective_mode($data) === 'flight';
          $airports2 = checkin_airports();
          $savedAir2 = trim((string)($data['arrival_airport'] ?? ''));
          $airOther2 = $savedAir2 !== '' && !array_key_exists($savedAir2, $airports2);
          $dtOn      = checkin_departure_transfer_supported();
          $yn = function ($v): array {
              return [($v === true  || $v === 't' || $v === '1'),
                      ($v === false || $v === 'f' || $v === '0')];
          };
          [$ntYes, $ntNo] = $yn($data['needs_transfer'] ?? null);
          [$dtYes, $dtNo] = $yn($data['needs_departure_transfer'] ?? null);
          $depTime = $dtOn ? trim((string)($data['departure_time'] ?? '')) : '';
          $depTime = $depTime !== '' ? substr($depTime, 0, 5) : '';
        ?>
        <label class="ci-l">Would you like us to arrange a transfer when you arrive?</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tin" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tin" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I&rsquo;ll make my own way</label>

        <?php // Both blocks stay in the DOM and are only hidden — a hidden input
              // still submits, so nothing the guest already entered is silently
              // dropped when they toggle. ?>
        <div class="ci-tin-fields" data-tmode="flight"<?= ($ntYes && $isFlight2) ? '' : ' hidden' ?>>
          <label class="ci-l">Airport of arrival</label>
          <select class="ci-in ci-f-airport" name="arrival_airport">
            <option value="">— select —</option>
            <?php foreach ($airports2 as $av => $al): ?>
            <option value="<?= e($av) ?>" <?= $savedAir2 === $av ? 'selected' : '' ?>><?= e($al) ?></option>
            <?php endforeach; ?>
            <option value="__other" <?= $airOther2 ? 'selected' : '' ?>>Other — I&rsquo;ll type it</option>
          </select>
          <div class="ci-airport-other"<?= $airOther2 ? '' : ' hidden' ?>>
            <label class="ci-l">Which airport?</label>
            <input class="ci-in" name="arrival_airport_other" value="<?= $airOther2 ? e($savedAir2) : '' ?>" placeholder="e.g. Nairobi JKIA">
          </div>
          <label class="ci-l">Flight number</label>
          <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
          <label class="ci-l">Flight arrival <span class="ci-opt">(landing time)</span></label>
          <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
        </div>

        <div class="ci-tin-fields" data-tmode="other"<?= ($ntYes && !$isFlight2) ? '' : ' hidden' ?>>
          <label class="ci-l">Where should we collect you?</label>
          <textarea class="ci-in" name="transfer_details" rows="3" placeholder="e.g. Likoni ferry, or the Serena in Mombasa"><?= $val('transfer_details') ?></textarea>
        </div>

        <?php if ($dtOn): ?>
        <label class="ci-l" style="margin-top:18px">Do you need a transfer when you check out?</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tout" name="needs_departure_transfer" value="1" <?= $dtYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tout" name="needs_departure_transfer" value="0" <?= $dtNo ? 'checked' : '' ?>> No, thank you</label>
        <div class="ci-tout-fields"<?= $dtYes ? '' : ' hidden' ?>>
          <label class="ci-l">Where are we taking you?</label>
          <input class="ci-in" name="departure_destination" value="<?= $val('departure_destination') ?>" placeholder="e.g. Moi International Airport">
          <label class="ci-l">What time should we collect you?</label>
          <input class="ci-in" type="time" name="departure_time" value="<?= e($depTime) ?>">
        </div>
        <?php endif; ?>

      <?php elseif ($key === 'you'): ?>
        <!-- Lead card — part of #ciForm (no guest_id → the lead row) -->
        <div class="ci-guest ci-guest--lead">
          <div class="ci-guest__title"><span class="ci-guest__who">You (lead guest)</span>
            <span class="ci-chip <?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'ci-chip--ok' : '' ?>"><?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'Complete' : 'Your details' ?></span></div>
          <?php if ($showPassport): ?>
          <div class="ci-pp" data-pp>
            <label class="ci-l">Passport photo <span class="ci-opt">(the page with your photo)</span></label>
            <p class="ci-pp-hint"><?= e($ppHint) ?></p>
            <div class="ci-upload" data-has="<?= !empty($lead['passport_file_key']) ? '1' : '0' ?>">
              <label class="ci-filebtn">&#128247; Choose photo<input type="file" accept="image/jpeg,image/png,application/pdf"></label>
              <span class="ci-upload__state"><?= !empty($lead['passport_file_key']) ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
            </div>
            <div class="ci-pp-note" role="status" hidden></div>
            <label class="ci-l">Full name (as on passport)</label>
            <input class="ci-in" name="passport_name" value="<?= $val('passport_name', $lead) ?>">
            <label class="ci-l">Passport number</label>
            <input class="ci-in" name="passport_number" value="<?= $val('passport_number', $lead) ?>">
            <label class="ci-l">Nationality</label>
            <input class="ci-in" name="nationality" value="<?= $val('nationality', $lead) ?>">
            <label class="ci-l">Passport expiry</label>
            <input class="ci-in" type="date" name="passport_expiry" value="<?= $val('passport_expiry', $lead) ?>">
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
        <p class="ci-party__head">Every other adult needs <?= $showPassport ? 'their own passport' : '' ?><?= $showPassport && $showWaiver ? ' and ' : '' ?><?= $showWaiver ? 'to sign the terms' : '' ?>. Add each guest and save — then send them their own link on WhatsApp<?= $showPassport ? ', or fill their passport in for them' : '' ?>.</p>

        <?php
        // One card, three uses: saved guests (collapsed to a summary + their link),
        // a guest still being filled in (open), and the <template> js/checkin-wizard.js
        // clones when "+ Add adult" is pressed ($g = null). A saved guest = has a name.
        $guestCard = function (?array $g) use ($showPassport, $showWaiver, $holdId, $kids, $shareWa, $waIcon, $ppHint): void {
            $gid   = (int)($g['id'] ?? 0);
            $name  = trim((string)($g['passport_name'] ?? ''));
            $done  = $g !== null && $name !== '';
            $gc    = $g !== null && checkin_guest_passport_complete($g) && (!$showWaiver || checkin_guest_waiver_signed($g));
            $link  = $gid ? make_guest_pass_url($holdId, $gid) : '';
            $fv    = fn($k) => e((string)($g[$k] ?? ''));
            ?>
        <div class="ci-guest" data-guest-id="<?= $gid ?: '' ?>" data-link="<?= e($link) ?>">
          <div class="ci-guest__form"<?= $done ? ' hidden' : '' ?>>
            <div class="ci-guest__title">
              <input class="ci-in ci-guest__name" data-field="passport_name" value="<?= e($name) ?>" placeholder="Guest full name">
              <button type="button" class="ci-guest__remove" aria-label="Remove guest">&times;</button>
            </div>
            <?php if ($showPassport): ?>
            <div class="ci-pp" data-pp>
              <p class="ci-pp-hint"><?= e($ppHint) ?> Or leave it for them to add from their own link.</p>
              <div class="ci-upload" data-has="<?= !empty($g['passport_file_key']) ? '1' : '0' ?>">
                <label class="ci-filebtn">&#128247; Choose photo<input type="file" accept="image/jpeg,image/png,application/pdf"></label>
                <span class="ci-upload__state"><?= !empty($g['passport_file_key']) ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
              </div>
              <div class="ci-pp-note" role="status" hidden></div>
              <label class="ci-l">Passport number</label>
              <input class="ci-in" data-field="passport_number" value="<?= $fv('passport_number') ?>">
              <label class="ci-l">Nationality</label>
              <input class="ci-in" data-field="nationality" value="<?= $fv('nationality') ?>">
              <label class="ci-l">Passport expiry</label>
              <input class="ci-in" type="date" data-field="passport_expiry" value="<?= $fv('passport_expiry') ?>">
            </div>
            <?php endif; ?>
            <?php if ($showWaiver): ?>
            <p class="ci-hint">They sign the terms themselves — from the link you send them after saving.</p>
            <?php endif; ?>
            <button type="button" class="pa-btn pa-btn--primary ci-guest__save">Save this guest</button>
          </div>
          <div class="ci-guest__done"<?= $done ? '' : ' hidden' ?>>
            <span class="ci-guest__done-name"><?= e($name) ?></span>
            <span class="ci-chip <?= $gc ? 'ci-chip--ok' : '' ?>"><?= $gc ? 'Complete' : 'Saved &#10003;' ?></span>
            <button type="button" class="ci-guest__edit">Edit</button>
          </div>
          <div class="ci-send"<?= ($done && !$gc) ? '' : ' hidden' ?>>
            <p class="ci-send__t">Send <span class="ci-send__name"><?= e($name !== '' ? explode(' ', $name)[0] : 'them') ?></span> their check-in link</p>
            <div class="ci-send__btns">
              <a class="pa-btn ci-wa" target="_blank" rel="noopener" href="<?= $done ? e($shareWa($name, $link)) : '#' ?>"><?= $waIcon ?>WhatsApp</a>
            </div>
            <div class="ci-linkrow"><input class="ci-in" readonly value="<?= e($link) ?>" onclick="this.select()"><button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy link</button></div>
          </div>
          <div class="ci-kids" data-parent="<?= $gid ?: '' ?>">
            <?php foreach (($kids[$gid] ?? []) as $c): ?>
            <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
            <?php endforeach; ?>
            <button type="button" class="ci-addkid">+ Add child</button>
          </div>
        </div>
            <?php
        };
        ?>
        <div class="ci-party" data-share-property="<?= e($shareProperty) ?>" data-share-dates="<?= e($shareDates) ?>">
          <!-- Additional adult cards (data-field inputs → saved via per-guest AJAX, NOT the main submit) -->
          <?php foreach ($adults as $g) { if (!empty($g['is_lead'])) continue; $guestCard($g); } ?>
        </div>

        <!-- Cloned by js/checkin-wizard.js when a new adult is added. Template
             content is inert, so its inputs never post and never match a
             document querySelectorAll. -->
        <template id="ciGuestTpl"><?php $guestCard(null); ?></template>

        <button type="button" class="pa-btn pa-btn--ghost ci-addguest" data-need="<?= $need ?>" <?= count($adults) >= $need ? 'hidden' : '' ?>>+ Add adult (<?= count($adults) ?>/<?= $need ?>)</button>

      <?php elseif ($key === 'dietary'): ?>
        <label class="ci-l">Dietary requirements / allergies</label>
        <textarea class="ci-in" name="dietary" rows="4"><?= $val('dietary') ?></textarea>

      <?php elseif ($key === 'requests'): ?>
        <label class="ci-l">Anything to make your stay special?</label>
        <textarea class="ci-in" name="special_requests" rows="4" placeholder="Birthday surprise, a bottle of wine in the room…"><?= $val('special_requests') ?></textarea>

      <?php elseif ($key === 'deposit'): ?>
        <div class="ci-deposit"<?= ($showDeposit && !empty($cfg['deposit']['required'])) ? ' data-deposit-required' : '' ?><?= $planOn ? ' data-deposit-gate' : '' ?>>
          <?php if ($deposit['formatted'] !== ''): ?>
          <div class="ci-deposit__amt">
            <span class="ci-deposit__amt-k">Security deposit</span>
            <span class="ci-deposit__amt-v"><?= e($deposit['formatted']) ?></span>
          </div>
          <?php else: ?>
          <div class="ci-deposit__amt">
            <span class="ci-deposit__amt-k">Security deposit</span>
            <span class="ci-deposit__amt-v ci-deposit__amt-v--tbc">Confirmed at arrival</span>
          </div>
          <?php endif; ?>
          <p class="ci-deposit__note"><?= nl2br(e($depositNote)) ?></p>
          <label class="ci-l">Photo of your credit card <span class="ci-opt">(front only — card you&rsquo;ll pay with)</span></label>
          <div class="ci-upload" data-kind="deposit" data-has="<?= $depositOnFile ? '1' : '0' ?>">
            <label class="ci-filebtn"><?= '&#128247;' ?> Choose photo<input type="file" accept="image/jpeg,image/png"></label>
            <span class="ci-upload__state"><?= $depositOnFile ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
          </div>
          <p class="ci-hint">Your card image is private, encrypted at rest and shared only with the Tribal Sand front desk. We never charge it online.</p>
          <?php if ($planOn): ?>
          <div class="ci-noncard">
            <button type="button" class="ci-noncard__toggle" aria-expanded="<?= $depositPlan !== '' ? 'true' : 'false' ?>">I can&rsquo;t upload my card now</button>
            <div class="ci-noncard__opts"<?= $depositPlan !== '' ? '' : ' hidden' ?>>
              <p class="ci-hint" style="margin-top:0">That&rsquo;s fine — the deposit is still needed when you arrive. How will you pay it?</p>
              <!-- The empty value posts first, so the key is always present when this
                   step is on the page; a ticked radio (later in the form) wins. -->
              <input type="hidden" name="deposit_plan" value="">
              <?php foreach (checkin_deposit_plans() as $__pk => $__pl):
                    $__lbl = $__pl . ($__pk === 'cash_at_arrival' && $deposit['formatted'] !== '' ? ' (' . $deposit['formatted'] . ')' : ''); ?>
              <label class="ci-radio"><input type="radio" class="ci-f-plan" name="deposit_plan" value="<?= e($__pk) ?>" <?= $depositPlan === $__pk ? 'checked' : '' ?>> <?= e($__lbl) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($planOn && $i === $n): ?>
        <p class="ci-hint ci-deposit__wait"<?= $depositHandled ? ' hidden' : '' ?>>Upload a photo of your card, or choose how you&rsquo;ll pay the deposit, to complete your check-in.</p>
        <?php endif; ?>
      <?php endif; ?>

      <div class="ci-nav">
        <button type="button" class="pa-btn pa-btn--ghost ci-back">&larr; Back</button>
        <?php if ($i < $n): ?>
          <button type="button" class="pa-btn pa-btn--primary ci-next">Save &amp; continue &rarr;</button>
        <?php else: ?>
          <button type="submit" class="pa-btn pa-btn--primary ci-submit" name="do" value="submit"<?= ($key === 'deposit' && $planOn && !$depositHandled) ? ' hidden' : '' ?>>Complete check-in</button>
        <?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <p class="ci-help"><a data-ci-leave href="/booking.php?ref=<?= e($ref) ?>&view=messages">Message the team</a> if you need help.</p>
</form>
<script src="/js/signature-pad.js?v=<?= @filemtime(__DIR__ . '/../../js/signature-pad.js') ?: time() ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-terms.js') ?: time() ?>" defer></script>
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
