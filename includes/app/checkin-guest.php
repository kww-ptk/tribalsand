<?php
/** Co-guest self-service check-in (opened via a per-guest ?g= link). Expects $hold, $me, $gtoken. */
declare(strict_types=1);
$holdId = (int)$hold['id'];
$cfg    = checkin_enabled_steps();
$showPassport = isset($cfg['passport']);
$showWaiver   = isset($cfg['waiver']);
$waiverText = checkin_waiver_text();

$guests = fetch_checkin_guests($holdId);
$others = array_values(array_filter($guests, fn($g) => (int)$g['id'] !== (int)$me['id'] && empty($g['is_child'])));
$myKids = array_values(array_filter($guests, fn($g) => !empty($g['is_child']) && (int)($g['parent_guest_id'] ?? 0) === (int)$me['id']));

$state  = checkin_coguest_view_state($me, checkin_config());
$done   = $state === 'done' || !empty($_GET['done']);
$name   = trim((string)($me['passport_name'] ?? ''));
$first  = $name !== '' ? explode(' ', $name)[0] : 'there';
$stayLoc = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
$v = fn($k) => e((string)($me[$k] ?? ''));
$otherStatus = function (array $g) use ($showWaiver) {
    $ok = checkin_guest_passport_complete($g) && (!$showWaiver || checkin_guest_waiver_signed($g));
    return $ok ? 'Checked in ✓' : 'Pending';
};
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/../../css/portal-app.css') ?: time() ?>">
<script src="/js/signature-pad.js?v=<?= @filemtime(__DIR__ . '/../../js/signature-pad.js') ?: time() ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-terms.js') ?: time() ?>" defer></script>
<div class="pa-app">
  <div class="pa-topbar"><div class="pa-topbar__inner"><div class="pa-topbar__brand"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title">Guest check-in</div></div></div></div>
  <div class="pa-wrap" style="padding-top:16px">
    <div class="ci-wizard<?= (!$done && $showWaiver) ? ' ci-wizard--split' : '' ?>">

    <?php if ($done): ?>
      <div class="pa-card ci-done-card">
        <div class="ci-done-card__check">&#10003;</div>
        <h2>You're all set<?= $first !== 'there' ? ', ' . e($first) : '' ?></h2>
        <p>Thanks — your check-in is complete. You can close this page.</p>
        <?php if (checkin_guest_waiver_signed($me)): ?>
        <a class="pa-btn pa-btn--ghost" href="/record.php?hold=<?= $holdId ?>&guest=<?= (int)$me['id'] ?>&g=<?= e($gtoken) ?>" target="_blank" style="margin-top:12px">Download my signed waiver</a>
        <?php endif; ?>
        <?php if (share_reservation_on($hold)): ?>
        <a class="pa-btn pa-btn--primary" href="/booking.php?g=<?= e($gtoken) ?>&view=home" style="margin-top:12px">Continue to your stay &rarr;</a>
        <?php endif; ?>
      </div>
    <?php else: ?>

      <?php if (!empty($_SESSION['ci_error'])): ?>
      <div class="ci-alert"><?= e($_SESSION['ci_error']) ?></div>
      <?php unset($_SESSION['ci_error']); endif; ?>

      <div class="ci-hero" style="padding:8px 6px 12px">
        <div class="ci-hero__eyebrow">Your check-in</div>
        <h1 class="ci-hero__title">Welcome<?= $first !== 'there' ? ', ' . e($first) : '' ?></h1>
        <p class="ci-hero__sub"><?php if ($state === 'review_sign'): ?>Your details are already on file for <strong><?= e($stayLoc) ?></strong> (<?= e(date('j M', strtotime((string)$hold['check_in']))) ?> &ndash; <?= e(date('j M', strtotime((string)$hold['check_out']))) ?>) — just sign below to finish.<?php else: ?>You've been added to a booking at <strong><?= e($stayLoc) ?></strong> (<?= e(date('j M', strtotime((string)$hold['check_in']))) ?> &ndash; <?= e(date('j M', strtotime((string)$hold['check_out']))) ?>). Please add your passport<?= $showWaiver ? ' and sign the waiver' : '' ?>.<?php endif; ?></p>
      </div>

      <form id="ciGForm" method="post" action="/api/checkin-save.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="g" value="<?= e($gtoken) ?>">
        <input type="hidden" name="via" value="<?= e((string)($_GET['via'] ?? '')) ?>">
        <div class="ci-cols">
          <!-- LEFT widget — identity & party -->
          <div class="ci-guest ci-guest--lead ci-wg">
            <h3 class="ci-wg__title">Your details</h3>
            <?php if ($showPassport): ?>
            <?php if ($state === 'review_sign'): ?>
            <details class="ci-review">
              <summary>Your details are on file — tap to review or edit</summary>
            <?php endif; ?>
            <div class="ci-pp" data-pp>
            <label class="ci-l">Passport photo <span class="ci-opt">(the page with your photo)</span></label>
            <p class="ci-pp-hint">Upload a photo of your passport’s photo page and we’ll fill in the details for you — or type them below.</p>
            <div class="ci-upload" data-has="<?= !empty($me['passport_file_key']) ? '1' : '0' ?>">
              <label class="ci-filebtn"><span class="ci-filebtn__t">&#128247; Choose photo</span><input type="file" id="ciGFile" accept="image/jpeg,image/png,application/pdf"></label>
              <span class="ci-upload__state"><?= !empty($me['passport_file_key']) ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
            </div>
            <div class="ci-pp-note" role="status" hidden></div>
            <div class="ci-grid2">
              <div class="ci-fld">
                <label class="ci-l">Full name <span class="ci-opt">(as on passport)</span></label>
                <input class="ci-in" name="passport_name" value="<?= $v('passport_name') ?>">
              </div>
              <div class="ci-fld">
                <label class="ci-l">Passport number</label>
                <input class="ci-in" name="passport_number" value="<?= $v('passport_number') ?>">
              </div>
              <div class="ci-fld">
                <label class="ci-l">Nationality</label>
                <input class="ci-in" name="nationality" value="<?= $v('nationality') ?>">
              </div>
              <div class="ci-fld">
                <label class="ci-l">Passport expiry</label>
                <input class="ci-in" type="date" name="passport_expiry" value="<?= $v('passport_expiry') ?>">
              </div>
            </div>
            </div>
            <?php if ($state === 'review_sign'): ?></details><?php endif; ?>
            <?php endif; ?>
            <div class="ci-kids" data-parent="me">
              <?php foreach ($myKids as $c): ?>
              <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
              <?php endforeach; ?>
              <button type="button" class="ci-addkid">+ Add child</button>
              <span class="ci-addkid-inline" hidden>
                <input type="text" class="ci-in ci-addkid-name" placeholder="Child's full name">
                <button type="button" class="ci-addkid-save">Add</button>
                <button type="button" class="ci-addkid-cancel" aria-label="Cancel">&times;</button>
              </span>
            </div>
            <?php if ($showWaiver): ?>
            <label class="ci-l">Terms &amp; conditions</label>
            <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
            <h3 class="ci-wg__title" style="margin-top:18px">Agree &amp; sign</h3>
            <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($me) ? 'checked' : '' ?>> I have read and agree to the terms</label>
            <?php endif; ?>
          </div>

          <!-- RIGHT widget — signature, who else is on this booking, and submit -->
          <div class="ci-guest ci-guest--lead ci-wg">
            <?php if ($showWaiver): ?>
            <h3 class="ci-wg__title">Signature</h3>
            <label class="ci-l">Type your full name to sign</label>
            <input class="ci-in" name="waiver_signed_name" value="<?= $v('waiver_signed_name') ?>" placeholder="Full name">
            <label class="ci-l">Sign below with your finger</label>
            <div class="ci-sign">
              <button type="button" class="ci-sign-clear">Clear</button>
              <canvas class="ci-sign-pad" data-target="#ciGSig"></canvas>
            </div>
            <input type="hidden" name="waiver_signature" id="ciGSig" data-signed="<?= checkin_guest_waiver_signed($me) ? '1' : '0' ?>">
            <?php endif; ?>
            <?php if ($others): ?>
            <div class="ci-others">
              <p class="ci-need__title">Others on this booking</p>
              <?php foreach ($others as $g): ?>
              <div class="ci-other__row"><span><?= e((string)($g['passport_name'] ?? 'Guest')) ?><?= !empty($g['is_lead']) ? ' (lead)' : '' ?></span><span class="ci-chip <?= checkin_guest_passport_complete($g) && (!$showWaiver || checkin_guest_waiver_signed($g)) ? 'ci-chip--ok' : '' ?>"><?= $otherStatus($g) ?></span></div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="ci-err" role="alert" hidden></div>
            <button type="submit" class="pa-btn pa-btn--primary ci-gsubmit" name="do" value="submit" style="margin-top:20px">Complete my check-in</button>
          </div>
        </div>
      </form>

    <?php endif; ?>

    <p class="ci-help">Questions? <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>
    </div>
  </div>
</div>

<?php if (!$done): ?>
<script>
(function () {
  var form = document.getElementById('ciGForm'); if (!form) return;
  var CSRF = form.querySelector('input[name=csrf_token]').value;
  var GTOK = form.querySelector('input[name=g]').value;

  var file = document.getElementById('ciGFile');
  if (file) file.addEventListener('change', function () {
    var f = file.files && file.files[0]; if (!f) return;
    var wrap = file.closest('.ci-upload'), state = wrap.querySelector('.ci-upload__state');
    state.textContent = /^image\//.test(f.type || '') ? 'Uploading and reading your passport…' : 'Uploading…';
    var fd = new FormData(); fd.append('g', GTOK); fd.append('csrf_token', CSRF); fd.append('passport', f);
    fetch('/api/checkin-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        state.innerHTML = 'Uploaded ✓'; wrap.setAttribute('data-has', '1');
        if (d && (d.fields || d.read === false || d.warnings)) fillPassport(wrap.closest('[data-pp]'), d);
      })
      .catch(function () { state.textContent = 'Upload failed — try again'; });
  });

  // Same rules as fillPassport() in js/checkin-wizard.js: never overwrite what the
  // guest typed; highlight what we filled until they edit it.
  function fillPassport(pp, d) {
    if (!pp) return;
    var fields = d.fields || {}, warnings = d.warnings || [], filled = 0;
    Object.keys(fields).forEach(function (k) {
      var el = pp.querySelector('[name="' + k + '"]');
      if (!el || (String(el.value).trim() !== '' && !el.hasAttribute('data-ai-filled'))) return;
      el.value = fields[k]; el.setAttribute('data-ai-filled', '1'); el.classList.add('ci-in--ai'); filled++;
    });
    var note = pp.querySelector('.ci-pp-note'); if (!note) return;
    var msgs = [];
    if (filled) msgs.push('We filled in your details from the photo — please check them.');
    else if (d.read === false || (Object.keys(fields).length === 0 && !warnings.length)) msgs.push('We couldn’t read the photo clearly — please type your details below.');
    msgs = msgs.concat(warnings);
    note.textContent = msgs.join(' ');
    note.classList.toggle('ci-pp-note--warn', warnings.length > 0);
    note.hidden = msgs.length === 0;
  }
  form.addEventListener('input', function (e) {
    var el = e.target;
    if (el && el.hasAttribute && el.hasAttribute('data-ai-filled')) { el.removeAttribute('data-ai-filled'); el.classList.remove('ci-in--ai'); }
  });

  // Finish: an unticked terms box opens the terms in a dialog (accepting ticks it
  // and carries on); a missing name or signature is said here, without a reload.
  // api/checkin-save.php still checks everything (checkin_consent_missing()).
  form.addEventListener('submit', function (e) {
    var agree = form.querySelector('.ci-agree');
    if (!agree) return;   // no terms step
    var err = form.querySelector('.ci-err'); if (err) err.hidden = true;
    var submitter = e.submitter || form.querySelector('.ci-gsubmit');
    if (!agree.checked && window.ciTermsDialog) {
      e.preventDefault();
      window.ciTermsDialog(form.querySelector('.ci-waiver'), function () {
        agree.checked = true;
        if (form.requestSubmit) form.requestSubmit(submitter); else submitter.click();
      });
      return;
    }
    var missing = [];
    var nm = form.querySelector('[name="waiver_signed_name"]');
    if (nm && nm.value.trim() === '') missing.push('type your full name');
    var sig = document.getElementById('ciGSig');
    if (sig && sig.getAttribute('data-signed') !== '1' && sig.value === '') missing.push('draw your signature');
    if (missing.length && err) {
      e.preventDefault();
      err.textContent = 'Before you finish, please ' + missing.join(' and ') + '.';
      err.hidden = false;
      err.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  // Reveal the inline add-child field for a given .ci-kids block.
  function openAddKid(wrap){
    var btn = wrap.querySelector('.ci-addkid'), inline = wrap.querySelector('.ci-addkid-inline');
    if (!inline) return;
    if (btn) btn.setAttribute('hidden','');
    inline.removeAttribute('hidden');
    var inp = inline.querySelector('.ci-addkid-name'); if (inp) { inp.value=''; inp.focus(); }
  }
  function closeAddKid(wrap){
    var btn = wrap.querySelector('.ci-addkid'), inline = wrap.querySelector('.ci-addkid-inline');
    if (inline) inline.setAttribute('hidden','');
    if (btn) btn.removeAttribute('hidden');
  }
  // Save the typed child name via AJAX, append a chip, reset the inline field.
  function saveAddKid(wrap){
    var inline = wrap.querySelector('.ci-addkid-inline'), inp = wrap.querySelector('.ci-addkid-name');
    var name = (inp && inp.value || '').trim(); if (!name) { if (inp) inp.focus(); return; }
    var save = wrap.querySelector('.ci-addkid-save'); if (save) save.disabled = true;
    fetch('/api/checkin-guest.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin',
      body: 'g=' + encodeURIComponent(GTOK) + '&csrf_token=' + encodeURIComponent(CSRF) + '&action=add_child&passport_name=' + encodeURIComponent(name) })
      .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(d){
        var c=document.createElement('span'); c.className='ci-kid'; c.setAttribute('data-guest-id', d.guest_id);
        c.innerHTML=esc(name)+'<button type="button" class="ci-kid__x" aria-label="Remove">&times;</button>';
        wrap.insertBefore(c, wrap.querySelector('.ci-addkid'));
        closeAddKid(wrap);
      })
      .catch(function(){ /* leave the field open so the guest can retry */ })
      .then(function(){ if (save) save.disabled = false; });
  }

  form.addEventListener('click', function (e) {
    var t = e.target;
    if (t.classList.contains('ci-addkid'))        { e.preventDefault(); openAddKid(t.closest('.ci-kids'));  return; }
    if (t.classList.contains('ci-addkid-cancel')) { e.preventDefault(); closeAddKid(t.closest('.ci-kids')); return; }
    if (t.classList.contains('ci-addkid-save'))   { e.preventDefault(); saveAddKid(t.closest('.ci-kids'));  return; }
    if (t.classList.contains('ci-kid__x')) {
      e.preventDefault(); var kc = t.closest('.ci-kid'), kid = kc.getAttribute('data-guest-id');
      fetch('/api/checkin-guest.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, credentials:'same-origin',
        body: 'g=' + encodeURIComponent(GTOK) + '&csrf_token=' + encodeURIComponent(CSRF) + '&action=remove&guest_id=' + encodeURIComponent(kid) })
        .then(function(){ kc.remove(); });
      return;
    }
  });
  // Enter in the inline name field saves; Escape cancels.
  form.addEventListener('keydown', function (e) {
    if (!e.target.classList || !e.target.classList.contains('ci-addkid-name')) return;
    if (e.key === 'Enter') { e.preventDefault(); saveAddKid(e.target.closest('.ci-kids')); }
    else if (e.key === 'Escape') { e.preventDefault(); closeAddKid(e.target.closest('.ci-kids')); }
  });
})();
</script>
<?php endif; ?>
