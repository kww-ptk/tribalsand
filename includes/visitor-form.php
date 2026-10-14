<?php
/**
 * The visitor details form. Expects: $mode ('register'|'edit'), $gate (bool),
 * $me (?array, the visitor when opened with ?t=), $places, $prePlace (int),
 * $errors, $old, $ppAi (bool), $waiverText, $tok (string).
 * Field names follow check-in so js/checkin-passport.js fills them.
 */
$val = function (string $k, string $col = '') use ($old, $me): string {
    if (array_key_exists($k, $old)) return e((string)$old[$k]);
    return $me && $col !== '' ? e((string)($me[$col] ?? '')) : '';
};
$err = fn(string $k): string => isset($errors[$k]) ? '<p class="ci-err" role="alert">' . e($errors[$k]) . '</p>' : '';
$reg = $mode === 'register';
$draftNonce = (string)($_SESSION['vr_photo_nonce'] ?? '');
$draftOld   = (string)($old['photo_draft'] ?? '');
$hasPhoto = !empty($me['id_photo_key'])
    || (!empty($_SESSION['vr_photo']) && $draftNonce !== '' && $draftOld !== '' && hash_equals($draftNonce, $draftOld));
$idType = (string)($old['id_type'] ?? ($me['id_type'] ?? 'passport'));
?>
<form id="vrForm" method="post" action="/api/visitor-register.php" data-help="vr-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="<?= $reg ? 'register' : 'update' ?>">
  <input type="hidden" name="ret" value="<?= e($_SERVER['REQUEST_URI'] ?? '/register.php') ?>">
  <input type="hidden" name="photo_draft" value="<?= e((string)($old['photo_draft'] ?? '')) ?>">
  <?php if ($tok !== ''): ?><input type="hidden" name="t" value="<?= e($tok) ?>"><?php endif; ?>
  <?php if ($gate): ?><input type="hidden" name="gate" value="1"><?php endif; ?>
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

  <?php if ($reg): ?>
  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Where are you going?</h3>
    <div class="vr-places" role="radiogroup">
      <?php foreach ($places as $p): $pid = (int)$p['id']; ?>
      <label class="vr-place"><input type="radio" name="place_id" value="<?= $pid ?>" <?= $pid === $prePlace ? 'checked' : '' ?>><span><?= e((string)$p['name']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <?= $err('place_id') ?>
  </div>
  <?php endif; ?>

  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Your details</h3>
    <div class="ci-pp" data-pp data-pp-ai="<?= $ppAi ? '1' : '0' ?>">
      <?= checkin_id_switch_html($idType, 'name="id_type"') ?>
      <label class="ci-l">Passport or ID photo</label>
      <p class="ci-pp-hint"><?= $ppAi ? 'Take a picture of your passport photo page or the front of your Kenyan ID and we’ll fill in the details — or type them below.' : 'Take a picture of your passport photo page or the front of your Kenyan ID.' ?></p>
      <div class="ci-upload" data-has="<?= $hasPhoto ? '1' : '0' ?>">
        <label class="ci-filebtn">&#128247; <span class="ci-filebtn__t"><?= $hasPhoto ? 'Retake picture' : 'Take picture' ?></span><input type="file" accept="image/jpeg,image/png" capture="environment"></label>
        <span class="ci-upload__state"><?= $hasPhoto ? 'Uploaded &#10003;' : 'No photo yet' ?></span>
      </div>
      <?= $err('photo') ?>
      <div class="ci-pp-note" role="status" hidden></div>
      <div class="ci-grid2">
        <div class="ci-fld"><label class="ci-l">Full name <span class="ci-opt">(as on the document)</span></label>
          <input class="ci-in" name="passport_name" autocomplete="name" value="<?= $val('passport_name', 'full_name') ?>"><?= $err('passport_name') ?></div>
        <div class="ci-fld"><?= checkin_id_label_html('number', $idType) ?>
          <input class="ci-in" name="passport_number" value="<?= $val('passport_number', 'id_number') ?>"><?= $err('passport_number') ?></div>
        <div class="ci-fld"><label class="ci-l">Nationality</label>
          <input class="ci-in" name="nationality" value="<?= $val('nationality', 'nationality') ?>"><?= $err('nationality') ?></div>
        <div class="ci-fld"><?= checkin_id_label_html('expiry', $idType) ?>
          <input class="ci-in" type="date" name="passport_expiry" value="<?= $val('passport_expiry', 'id_expiry') ?>"></div>
        <div class="ci-fld"><label class="ci-l">Phone</label>
          <input class="ci-in" type="tel" name="phone" autocomplete="tel" value="<?= $val('phone', 'phone') ?>"><?= $err('phone') ?></div>
        <div class="ci-fld"><label class="ci-l">Email <?= $gate ? '<span class="ci-opt">(optional)</span>' : '<span class="ci-opt">(we send your pass here)</span>' ?></label>
          <input class="ci-in" type="email" name="email" autocomplete="email" value="<?= $val('email', 'email') ?>"><?= $err('email') ?></div>
      </div>
    </div>
  </div>

  <?php if ($reg && !$me): ?>
  <div class="pa-card ci-wg">
    <h3 class="ci-wg__title">Who's with you? <span class="ci-opt">(optional)</span></h3>
    <p class="ci-pp-hint">Add children under 18 here. Other adults register themselves: after you finish, you get a link to send each of them.</p>
    <div id="vrKids"></div>
    <div id="vrAdults"></div>
    <button type="button" class="pa-btn pa-btn--ghost" data-vr-add="kid">+ Add a child</button>
    <button type="button" class="pa-btn pa-btn--ghost" data-vr-add="adult">+ Add an adult</button>
    <template id="vrKidTpl"><div class="vr-row"><input class="ci-in" name="child_name[]" placeholder="Child’s full name"><input class="ci-in vr-age" name="child_age[]" type="number" min="0" max="17" placeholder="Age"><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></div></template>
    <template id="vrAdultTpl"><div class="vr-row"><input class="ci-in" name="adult_name[]" placeholder="Adult’s full name"><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></div></template>
  </div>
  <?php endif; ?>

  <?php if ($reg): ?>
  <div class="pa-card ci-wg">
    <label class="ci-l">Terms &amp; conditions</label>
    <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
    <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1"> I have read and agree to the terms</label><?= $err('agree') ?>
    <label class="ci-l">Type your full name to sign</label>
    <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name') ?>" placeholder="Full name"><?= $err('waiver_signed_name') ?>
    <label class="ci-l">Sign below with your finger</label>
    <div class="ci-sign"><button type="button" class="ci-sign-clear">Clear</button><canvas class="ci-sign-pad" data-target="#vrSig"></canvas></div>
    <input type="hidden" name="waiver_signature" id="vrSig"><?= $err('signature') ?>
  </div>
  <?php endif; ?>

  <?php if (!$gate && !$me && captcha_site_key() && !visitor_session_verified()): ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:12px 0"></div>
  <?php endif; ?>
  <?= $err('general') ?>
  <div class="ci-err" data-vr-err role="alert" hidden></div>
  <button type="submit" class="pa-btn pa-btn--primary" style="margin-top:12px"><?= $reg ? 'Register' : 'Save my details' ?></button>
</form>
<script>
(function () {
  var form = document.getElementById('vrForm'); if (!form) return;
  var csrf = form.querySelector('input[name=csrf_token]').value;
  if (window.tsCheckinPassport) window.tsCheckinPassport.bind(form, {
    endpoint: '/api/visitor-upload.php',
    formData: function () { var fd = new FormData(); fd.append('csrf_token', csrf); <?= $gate ? "fd.append('gate', '1');" : '' ?> return fd; },
    onDone: function (wrap, d) { if (d && d.draft) form.querySelector('[name=photo_draft]').value = d.draft; }
  });
  form.addEventListener('click', function (e) {
    var add = e.target.closest('[data-vr-add]');
    if (add) {
      var kid = add.getAttribute('data-vr-add') === 'kid';
      var tpl = document.getElementById(kid ? 'vrKidTpl' : 'vrAdultTpl');
      var box = document.getElementById(kid ? 'vrKids' : 'vrAdults');
      box.appendChild(tpl.content.cloneNode(true));
      var inp = box.lastElementChild.querySelector('input'); if (inp) inp.focus();
      return;
    }
    if (e.target.classList.contains('ci-kid__x') && e.target.closest('.vr-row')) { e.target.closest('.vr-row').remove(); }
  });
  form.addEventListener('submit', function (e) {
    var err = form.querySelector('[data-vr-err]');
    if (window.tsCheckinPassport && window.tsCheckinPassport.busy(form)) {
      e.preventDefault(); err.textContent = 'Please wait a moment — your photo is still uploading.'; err.hidden = false; return;
    }
    var agree = form.querySelector('.ci-agree'); if (!agree) return;
    if (!agree.checked && window.ciTermsDialog) {
      e.preventDefault();
      window.ciTermsDialog(form.querySelector('.ci-waiver'), function () { agree.checked = true; form.requestSubmit ? form.requestSubmit() : form.submit(); });
      return;
    }
    var missing = [];
    if (!form.querySelector('[name=place_id]:checked')) missing.push('choose where you are going');
    if (form.querySelector('[name=waiver_signed_name]').value.trim() === '') missing.push('type your full name');
    if (document.getElementById('vrSig').value === '') missing.push('draw your signature');
    if (missing.length) { e.preventDefault(); err.textContent = 'Before you register, please ' + missing.join(', ') + '.'; err.hidden = false; err.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  });
})();
</script>
