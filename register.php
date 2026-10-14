<?php
/**
 * General registration link (spec 2026-10-14). Everyone who enters a Tribal Sand
 * property is registered: a guest with a booking goes to their own check-in; a
 * visitor registers here once (details, ID photo, signed waiver, where they are
 * going) and gets a reusable pass. ?gate=1 = the same form on the guard's
 * tablet (signed-in gate account). ?t=<VP> = the person's own form (finish
 * registering / ?edit=1 their details); ?i=<VI> = an invited adult's one-time
 * invite (only until they have signed). Posts to api/visitor-register.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/turnstile.php';
require_once __DIR__ . '/includes/visitor-store.php';
session_init();

$gate = !empty($_GET['gate']);
if ($gate) { require_login(); require_gate(); }
$tok  = trim((string)($_GET['t'] ?? ''));
$me   = $tok !== '' ? visitor_find_by_token($tok) : null;
if ($tok !== '' && !$me) $tok = '';
$edit = $me && !empty($_GET['edit']);
if ($me && visitor_signed($me) && !$edit) { header('Location: ' . visitor_pass_url((int)$me['id'])); exit; }
// An invite (VI) opens the invited adult's form only until they have signed; then it is spent.
$itok = (!$me && !$gate) ? trim((string)($_GET['i'] ?? '')) : '';
$inviteUsed = false;
if ($itok !== '') {
    $me = visitor_find_by_invite($itok);
    if (!$me) { $itok = ''; $inviteUsed = true; }
}

$steps = ['start', 'booking', 'nocode', 'again', 'visitor', 'sent'];
$step  = $inviteUsed ? 'used' : (($me || $gate) ? 'visitor' : (in_array($_GET['step'] ?? '', $steps, true) ? (string)$_GET['step'] : 'start'));

$flash  = $_SESSION['vr_flash'] ?? []; unset($_SESSION['vr_flash']);
$errors = $flash['errors'] ?? []; $old = $flash['old'] ?? []; $notice = (string)($flash['notice'] ?? '');

$places   = visitor_places(true, $gate ? admin_venue_ids() : null);
$prePlace = (int)($old['place_id'] ?? ($_GET['place'] ?? 0));
if (!$prePlace && $me && !empty($me['invited_by']) && ($lv = visitor_latest_visit((int)$me['invited_by']))) $prePlace = (int)$lv['place_id'];
$mode = $edit ? 'edit' : 'register';
$ppAi = ai_assistant_supported();
$waiverText = visitor_waiver_text();
$supported = visitor_reg_supported();
$turnstile = !$gate && captcha_site_key() && !visitor_session_verified();

$page_title = 'Register to visit · Tribal Sand';
$page_desc  = 'Register before you enter a Tribal Sand property — it takes two minutes.';
$page_url   = site_url('/register.php');
$noindex = true; $portal_chrome = true; $hide_floating_chat = true;
include __DIR__ . '/includes/head.php';
$ver = fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: time();
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= $ver('css/portal-app.css') ?>">
<script src="/js/signature-pad.js?v=<?= $ver('js/signature-pad.js') ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= $ver('js/checkin-terms.js') ?>" defer></script>
<script src="/js/checkin-passport.js?v=<?= $ver('js/checkin-passport.js') ?>"></script>
<style>
  .vr-choices{display:grid;gap:12px}
  .vr-choice{display:block;padding:18px;border:1px solid #e4e1da;border-radius:14px;background:#fff;color:inherit;text-decoration:none}
  .vr-choice b{display:block;font-size:17px;margin-bottom:4px}
  .vr-places{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
  .vr-place{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e4e1da;border-radius:12px;cursor:pointer;min-height:48px}
  .vr-place:has(input:checked){border-color:#1E5C6B;background:#eef5f6}
  .vr-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}.vr-row .ci-in{flex:1;min-width:0}.vr-row .vr-age{flex:0 0 80px}
  .vr-note{background:#eef5f6;border-radius:12px;padding:12px 14px;margin-bottom:12px}
</style>
<div class="pa-app">
  <div class="pa-topbar"><div class="pa-topbar__inner"><div class="pa-topbar__brand"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= $gate ? 'Register a visitor' : 'Register to visit' ?></div></div></div></div>
  <div class="pa-wrap" style="padding-top:16px;max-width:720px">
  <?php if (!$supported): ?>
    <div class="pa-card" style="padding:18px">Registration isn’t available yet. Please ask at the gate.</div>
  <?php elseif ($step === 'start'): ?>
    <h1 class="ci-hero__title">Welcome</h1>
    <p class="ci-hero__sub">Everyone who enters a Tribal Sand property registers first. It takes two minutes.</p>
    <div class="vr-choices">
      <a class="vr-choice" href="/register.php?step=booking"><b>I have a booking</b>Go to your booking’s check-in.</a>
      <a class="vr-choice" href="/register.php?step=visitor<?= !empty($_GET['place']) ? '&amp;place=' . (int)$_GET['place'] : '' ?>"><b>I’m visiting (no booking)</b>Coming for a drink, a meal, to see a guest, to work…</a>
      <a class="vr-choice" href="/register.php?step=again"><b>I’ve registered before</b>We’ll email you your pass.</a>
    </div>
  <?php elseif ($step === 'booking' || $step === 'nocode' || $step === 'again'): ?>
    <a href="/register.php" class="pa-note">&larr; Back</a>
    <form method="post" action="/api/visitor-register.php" class="pa-card" style="padding:18px;margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="ret" value="/register.php?step=<?= e($step) ?>">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <?php if ($step === 'booking'): ?>
        <input type="hidden" name="action" value="lookup">
        <h2 class="ci-wg__title">Find your booking</h2>
        <label class="ci-l">Booking code <span class="ci-opt">(in your booking email)</span></label>
        <input class="ci-in" name="code" autocapitalize="characters" value="<?= e((string)($old['code'] ?? '')) ?>" required>
        <label class="ci-l">Your full name</label>
        <input class="ci-in" name="name" autocomplete="name" value="<?= e((string)($old['name'] ?? '')) ?>" required>
        <p class="pa-note" style="margin-top:8px"><a href="/register.php?step=nocode">I don’t have my code</a></p>
      <?php elseif ($step === 'nocode'): ?>
        <input type="hidden" name="action" value="nocode">
        <h2 class="ci-wg__title">Get your booking link</h2>
        <label class="ci-l">The email the booking was made with</label>
        <input class="ci-in" type="email" name="contact" autocomplete="email" required>
      <?php else: ?>
        <input type="hidden" name="action" value="again">
        <h2 class="ci-wg__title">Get your pass again</h2>
        <label class="ci-l">The phone number or email you registered with</label>
        <input class="ci-in" name="contact" required>
      <?php endif; ?>
      <?php if ($turnstile): ?><div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:12px 0"></div><?php endif; ?>
      <?php if (!empty($errors['general'])): ?><p class="ci-err" role="alert"><?= e($errors['general']) ?></p><?php endif; ?>
      <button class="pa-btn pa-btn--primary" style="margin-top:12px"><?= $step === 'booking' ? 'Continue' : 'Email me the link' ?></button>
    </form>
  <?php elseif ($step === 'used'): ?>
    <div class="pa-card" style="padding:18px"><p style="margin:0">This invitation has already been used. Open your pass from the email we sent you, or ask at the gate.</p></div>
  <?php elseif ($step === 'sent'): ?>
    <div class="pa-card" style="padding:18px"><p><?= e($notice !== '' ? $notice : 'Done.') ?></p><a class="pa-btn" href="/register.php">Back</a></div>
  <?php else: ?>
    <?php if (!$gate && !$me): ?><a href="/register.php" class="pa-note">&larr; Back</a><?php endif; ?>
    <h1 class="ci-hero__title"><?= $edit ? 'Your details' : ($me ? 'Welcome, ' . e(explode(' ', (string)$me['full_name'])[0]) : 'Register to visit') ?></h1>
    <?php if ($me && !$edit && !empty($me['invited_by'])): ?><p class="ci-hero__sub">You’ve been added to a group. Add your details and sign, and you’re ready to go.</p><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="vr-note"><?= e($notice) ?></div><?php endif; ?>
    <?php if (!$places && $mode === 'register'): ?>
      <div class="pa-card" style="padding:18px">No places are open for registration right now. Please ask at the gate.</div>
    <?php else: include __DIR__ . '/includes/visitor-form.php'; endif; ?>
  <?php endif; ?>
    <p class="ci-help">Questions? <a href="mailto:reservations@tribalsand.com">reservations@tribalsand.com</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
