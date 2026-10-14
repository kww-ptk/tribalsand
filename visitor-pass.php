<?php
/**
 * A visitor's own pass (spec 2026-10-14 §3.3). Sign again when the terms changed
 * or a detail edit voided the signature; choose today's place and who's coming;
 * the QR the guard scans (it links to the Gate page and grants nothing by
 * itself); the group with each adult's invite link.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/frontdesk.php';
require_once __DIR__ . '/includes/visitor-store.php';
session_init();

$tok = trim((string)($_GET['t'] ?? ''));
$me  = $tok !== '' ? visitor_find_by_token($tok) : null;
if (!$me || !empty($me['parent_id'])) { header('Location: /register.php?step=again'); exit; }
if (!visitor_signed($me) && empty($me['waiver_reference'])) { header('Location: ' . visitor_register_url((int)$me['id'])); exit; }   // never signed: finish registering (their own VP form)

$id     = (int)$me['id'];
$today  = frontdesk_today_ymd();
$ver    = waiver_version(visitor_waiver_text());
$current = visitor_waiver_current($me, $ver);
$visit  = visitor_visit_on($id, $today);
$group  = visitor_group($id);
$places = visitor_places(true);
$flash  = $_SESSION['vr_flash'] ?? []; unset($_SESSION['vr_flash']);
$errors = $flash['errors'] ?? []; $notice = (string)($flash['notice'] ?? '');
$first  = explode(' ', (string)$me['full_name'])[0];
$adultCurrent = fn(array $a): bool => visitor_waiver_current($a, $ver);

$page_title = 'Your visitor pass · Tribal Sand';
$page_url = site_url('/visitor-pass.php'); $noindex = true; $portal_chrome = true; $hide_floating_chat = true;
include __DIR__ . '/includes/head.php';
$v = fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: time();
$err = fn(string $k): string => isset($errors[$k]) ? '<p class="ci-err" role="alert">' . e($errors[$k]) . '</p>' : '';
$hidden = csrf_field() . '<input type="hidden" name="t" value="' . e($tok) . '"><input type="hidden" name="ret" value="/visitor-pass.php?t=' . e(rawurlencode($tok)) . '">';
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= $v('css/portal-app.css') ?>">
<script src="/js/signature-pad.js?v=<?= $v('js/signature-pad.js') ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= $v('js/checkin-terms.js') ?>" defer></script>
<style>
  .vr-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
  .vr-place{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e4e1da;border-radius:12px;min-height:48px}
  .vr-place:has(input:checked){border-color:#1E5C6B;background:#eef5f6}
  .vr-member{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 0;border-top:1px solid #eee;flex-wrap:wrap}
  .vr-note{background:#eef5f6;border-radius:12px;padding:12px 14px;margin-bottom:12px}
</style>
<div class="pa-app">
  <div class="pa-topbar"><div class="pa-topbar__inner"><div class="pa-topbar__brand"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title">Visitor pass</div></div></div></div>
  <div class="pa-wrap" style="padding-top:16px;max-width:620px">
    <?php if ($notice !== ''): ?><div class="vr-note"><?= e($notice) ?></div><?php endif; ?>

    <?php if (!$current): ?>
    <form method="post" action="/api/visitor-register.php" class="ci-guest ci-wg" id="vrSign">
      <?= $hidden ?><input type="hidden" name="action" value="sign">
      <h2 class="ci-wg__title"><?= visitor_signed($me) ? 'Our terms have changed' : 'Please sign again' ?></h2>
      <p class="pa-note">Read the terms and sign before your next visit.</p>
      <div class="ci-waiver"><?= nl2br(e(visitor_waiver_text())) ?></div>
      <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1"> I have read and agree to the terms</label><?= $err('agree') ?>
      <label class="ci-l">Type your full name to sign</label>
      <input class="ci-in" name="waiver_signed_name" value="<?= e((string)$me['full_name']) ?>"><?= $err('waiver_signed_name') ?>
      <label class="ci-l">Sign below with your finger</label>
      <div class="ci-sign"><button type="button" class="ci-sign-clear">Clear</button><canvas class="ci-sign-pad" data-target="#vrSig2"></canvas></div>
      <input type="hidden" name="waiver_signature" id="vrSig2"><?= $err('signature') ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px">Sign</button>
    </form>
    <?php else: ?>

    <?php if (!$visit || !empty($_GET['change'])): ?>
    <form method="post" action="/api/visitor-register.php" class="ci-guest ci-wg">
      <?= $hidden ?><input type="hidden" name="action" value="visit">
      <h2 class="ci-wg__title">Where are you going today?</h2>
      <div class="vr-places">
        <?php foreach ($places as $p): ?>
        <label class="vr-place"><input type="radio" name="place_id" value="<?= (int)$p['id'] ?>" <?= $visit && (int)$visit['place_id'] === (int)$p['id'] ? 'checked' : '' ?> required><span><?= e((string)$p['name']) ?></span></label>
        <?php endforeach; ?>
      </div><?= $err('place_id') ?>
      <?php if ($group['adults'] || $group['children']): ?>
      <p class="ci-l" style="margin-top:14px">Who’s coming with you?</p>
      <?php foreach ($group['adults'] as $a): ?><label class="ci-radio"><input type="checkbox" name="with_adult[]" value="<?= (int)$a['id'] ?>" checked> <?= e((string)$a['full_name']) ?></label><?php endforeach; ?>
      <?php foreach ($group['children'] as $c): ?><label class="ci-radio"><input type="checkbox" name="with_child[]" value="<?= (int)$c['id'] ?>" checked> <?= e((string)$c['full_name']) ?> (<?= (int)$c['age'] ?>)</label><?php endforeach; ?>
      <?php endif; ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px">Save</button>
    </form>
    <?php else: $party = visitor_visit_party($visit, $id); $waiting = array_filter($party['adults'], fn($a) => !$adultCurrent($a)); ?>
    <section class="pa-pass">
      <div class="pa-pass__eyebrow">Visitor pass · <?= e(date('D j M', strtotime($today))) ?></div>
      <div class="pa-pass__qr" data-gp-qr="<?= e(visitor_gate_url($id)) ?>" role="img" aria-label="Visitor pass QR code"></div>
      <h2 class="pa-pass__name"><?= e((string)$me['full_name']) ?></h2>
      <dl class="pa-pass__facts"><div><dt>Going to</dt><dd><?= e((string)$visit['place_name']) ?> · <a href="?t=<?= e(rawurlencode($tok)) ?>&amp;change=1">Change</a></dd></div></dl>
      <?php if ($party['adults'] || $party['children']): ?>
      <ul class="pa-pass__names">
        <?php foreach ($party['adults'] as $a): ?><li><?= e((string)$a['full_name']) ?> <?= $adultCurrent($a) ? '✓' : '— waiting to sign' ?></li><?php endforeach; ?>
        <?php foreach ($party['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child)</li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p class="pa-pass__hint"><?= $waiting ? 'Your group can enter once everyone above has signed.' : 'Show this screen to security at the gate. Turn your screen brightness up if the scan doesn’t work.' ?></p>
    </section>
    <?php endif; ?>

    <div class="ci-guest ci-wg" id="group">
      <h2 class="ci-wg__title">Your group</h2>
      <?= $err('group') ?>
      <?php foreach ($group['adults'] as $a): $link = visitor_invite_url((int)$a['id']); $txt = visitor_invite_share_text((string)$a['full_name'], (string)($visit['place_name'] ?? ''), $link); ?>
      <div class="vr-member">
        <span><?= e((string)$a['full_name']) ?> · <?= $adultCurrent($a) ? 'Signed ✓' : 'Waiting to sign' ?></span>
        <?php if (!$adultCurrent($a)): ?>
        <span><a class="pa-btn pa-btn--ghost" href="https://wa.me/?text=<?= e(rawurlencode($txt)) ?>" target="_blank" rel="noopener">Send on WhatsApp</a>
          <button type="button" class="pa-btn pa-btn--ghost" data-copy="<?= e($link) ?>">Copy link</button></span>
        <?php endif; ?>
        <form method="post" action="/api/visitor-register.php" style="display:inline"><?= $hidden ?><input type="hidden" name="action" value="drop"><input type="hidden" name="member_id" value="<?= (int)$a['id'] ?>"><button class="ci-kid__x" aria-label="Remove <?= e((string)$a['full_name']) ?>">&times;</button></form>
      </div>
      <?php endforeach; ?>
      <?php foreach ($group['children'] as $c): ?>
      <div class="vr-member"><span><?= e((string)$c['full_name']) ?> (<?= (int)$c['age'] ?>)</span>
        <form method="post" action="/api/visitor-register.php" style="display:inline"><?= $hidden ?><input type="hidden" name="action" value="drop"><input type="hidden" name="member_id" value="<?= (int)$c['id'] ?>"><button class="ci-kid__x" aria-label="Remove <?= e((string)$c['full_name']) ?>">&times;</button></form></div>
      <?php endforeach; ?>
      <form method="post" action="/api/visitor-register.php" class="vr-member"><?= $hidden ?><input type="hidden" name="action" value="invite">
        <input class="ci-in" name="name" placeholder="Another adult’s full name" style="flex:1;min-width:0" required><button class="pa-btn pa-btn--ghost">+ Add adult</button></form>
      <form method="post" action="/api/visitor-register.php" class="vr-member"><?= $hidden ?><input type="hidden" name="action" value="child">
        <input class="ci-in" name="name" placeholder="Child’s full name" style="flex:1;min-width:0" required><input class="ci-in" type="number" name="age" min="0" max="17" placeholder="Age" style="width:80px" required><button class="pa-btn pa-btn--ghost">+ Add child</button></form>
    </div>
    <?php endif; ?>

    <p class="pa-note" style="margin-top:12px">
      <a href="<?= e(visitor_register_url($id)) ?>&amp;edit=1">Update my details</a>
      <?php if (visitor_signed($me)): ?> · <a href="/record.php?vp=<?= e(rawurlencode($tok)) ?>" target="_blank">My signed waiver</a><?php endif; ?>
    </p>
  </div>
</div>
<script src="/js/vendor/qrcode.js?v=<?= $v('js/vendor/qrcode.js') ?>"></script>
<script>
(function () {
  document.querySelectorAll('[data-gp-qr]').forEach(function (el) {
    if (el.firstChild || !window.qrcode) return;
    var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-gp-qr')); qr.make();
    el.innerHTML = qr.createSvgTag({ scalable: true, margin: 8 });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]'); if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'Copied ✓'; });
  });
  var sign = document.getElementById('vrSign');
  if (sign) sign.addEventListener('submit', function (e) {
    var agree = sign.querySelector('.ci-agree');
    if (!agree.checked && window.ciTermsDialog) { e.preventDefault(); window.ciTermsDialog(sign.querySelector('.ci-waiver'), function () { agree.checked = true; sign.submit(); }); }
  });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
