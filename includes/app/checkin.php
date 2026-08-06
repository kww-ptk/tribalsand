<?php
/** Guest check-in — landing screen + multi-step wizard. Expects $hold, $ref, $holdId (from booking.php). */
declare(strict_types=1);
$holdId  = (int)$hold['id'];
$cfg     = checkin_enabled_steps();
$data    = fetch_checkin($holdId) ?? [];
$lead    = checkin_lead_guest($holdId) ?? [];
$welcome = setting('checkin_welcome', '');
$waiver  = setting('checkin_waiver_text', '');
$done    = checkin_is_complete($hold);
$val     = fn($k, $src = null) => e((string)(($src ?? $data)[$k] ?? ''));
$arrDate = !empty($data['arrival_at']) ? date('Y-m-d\TH:i', strtotime((string)$data['arrival_at'])) : '';

$first   = trim((string)($hold['guest_name'] ?? ''));
$first   = $first !== '' ? explode(' ', $first)[0] : 'there';
$stayLoc = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
$nights  = max(1, (int) round((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400));
$hasProgress = !empty($data) || !empty($lead);

// "Have these handy" — only the steps that need the guest to gather something.
$needs = [];
if (isset($cfg['passport'])) $needs[] = ['&#128179;', 'Your passport — a clear photo or PDF'];
if (isset($cfg['arrival']))  $needs[] = ['&#9992;&#65039;', 'Flight number &amp; arrival time'];
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/../../css/portal-app.css') ?: time() ?>">

<?php if ($done): ?>
<div class="pa-card ci-done-card">
  <div class="ci-done-card__check">&#10003;</div>
  <h2>You're all checked in</h2>
  <p>Thank you, <?= e($first) ?>. Everything's set for your arrival — you can update your details any time before then.</p>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
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

  <?php if (!$done): ?>
  <!-- ── Landing ─────────────────────────────────────────────── -->
  <section class="ci-intro" id="ciIntro">
    <div class="ci-hero">
      <div class="ci-hero__eyebrow">Pre-arrival check-in</div>
      <h1 class="ci-hero__title">Karibu, <?= e($first) ?></h1>
      <p class="ci-hero__sub"><?= $welcome !== ''
          ? e($welcome)
          : 'A few quick details before you arrive — about three minutes now means a warm, paperwork-free welcome when you get here.' ?></p>
    </div>

    <div class="pa-card ci-trip">
      <?php if ($stayLoc !== ''): ?>
      <div class="ci-trip__row"><span>Your stay</span><strong><?= e($stayLoc) ?></strong></div>
      <?php endif; ?>
      <div class="ci-trip__row"><span>Dates</span><strong><?= e(date('D j M', strtotime((string)$hold['check_in']))) ?> &rarr; <?= e(date('D j M', strtotime((string)$hold['check_out']))) ?> <span class="ci-trip__muted">· <?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span></strong></div>
      <div class="ci-trip__row"><span>Booking</span><strong><code><?= e((string)($hold['access_code'] ?? '')) ?></code></strong></div>
    </div>

    <?php if ($needs): ?>
    <div class="ci-need">
      <p class="ci-need__title">Have these handy</p>
      <?php foreach ($needs as $nd): ?>
      <div class="ci-need__item"><span class="ci-need__ic"><?= $nd[0] ?></span><span><?= $nd[1] ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <button type="button" class="pa-btn pa-btn--primary ci-start" id="ciStart"><?= $hasProgress ? 'Continue check-in' : 'Start check-in' ?> &rarr;</button>
    <p class="ci-intro__note">🔒 Your details are private and shared only with the Tribal Sand team.</p>
  </section>
  <?php endif; ?>

  <!-- ── Steps (revealed on Start) ───────────────────────────── -->
  <div class="ci-steps" id="ciSteps" hidden>
    <div class="ci-progress"><div class="ci-progress__bar" id="ciBar"></div></div>

    <?php $i = 0; $n = count($cfg); foreach ($cfg as $key => $s): $i++; ?>
    <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>" hidden>
      <div class="ci-step__h"><span class="ci-step__num">Step <?= $i ?> of <?= $n ?></span><h3><?= e($s['label']) ?><?= $s['required'] ? ' <span class="ci-req">*</span>' : '' ?></h3></div>

      <?php if ($key === 'arrival'): ?>
        <label class="ci-l">Airport of arrival</label>
        <input class="ci-in" name="arrival_airport" value="<?= $val('arrival_airport') ?>" placeholder="e.g. Moi Intl (MBA)">
        <label class="ci-l">Flight number</label>
        <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
        <label class="ci-l">Arrival date &amp; time</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">

      <?php elseif ($key === 'transfer'): ?>
        <label class="ci-l">Would you like us to arrange your airport transfer?</label>
        <?php $nt = $data['needs_transfer'] ?? null; $ntYes = ($nt === true || $nt === 't' || $nt === '1'); $ntNo = ($nt === false || $nt === 'f' || $nt === '0'); ?>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I'll make my own way</label>
        <label class="ci-l">Transfer details (pickup point, pax, luggage)</label>
        <textarea class="ci-in" name="transfer_details" rows="3"><?= $val('transfer_details') ?></textarea>

      <?php elseif ($key === 'passport'): ?>
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
          <input type="file" id="ciPassportFile" accept="image/jpeg,image/png,application/pdf">
          <span class="ci-upload__state"><?= !empty($lead['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
        </div>

      <?php elseif ($key === 'dietary'): ?>
        <label class="ci-l">Dietary requirements / allergies</label>
        <textarea class="ci-in" name="dietary" rows="4"><?= $val('dietary') ?></textarea>

      <?php elseif ($key === 'requests'): ?>
        <label class="ci-l">Anything to make your stay special?</label>
        <textarea class="ci-in" name="special_requests" rows="4" placeholder="Birthday surprise, a bottle of wine in the room…"><?= $val('special_requests') ?></textarea>

      <?php elseif ($key === 'waiver'): ?>
        <div class="ci-waiver"><?= nl2br(e($waiver !== '' ? $waiver : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.')) ?></div>
        <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= !empty($data['waiver_signed_at']) ? 'checked' : '' ?>> I have read and agree</label>
        <label class="ci-l">Type your full name to sign</label>
        <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name') ?>">
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
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
