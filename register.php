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
require_once __DIR__ . '/includes/page-content.php';
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
$page_image = page_image('register', 'og_image');   // Admin → Website content → Pages; empty = the site default
$noindex = true; $portal_chrome = true; $hide_floating_chat = true;
include __DIR__ . '/includes/head.php';
$ver = fn(string $f) => @filemtime(__DIR__ . '/' . $f) ?: time();
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= $ver('css/portal-app.css') ?>">
<script src="/js/signature-pad.js?v=<?= $ver('js/signature-pad.js') ?>" defer></script>
<script src="/js/checkin-terms.js?v=<?= $ver('js/checkin-terms.js') ?>" defer></script>
<script src="/js/checkin-passport.js?v=<?= $ver('js/checkin-passport.js') ?>"></script>
<style>
  .vr-start__t{font-family:'Cormorant Garamond',Georgia,serif;font-weight:600;font-size:34px;color:#102F3A;margin:4px 0 6px}
  .vr-start__sub{font-size:15px;line-height:1.5;color:#6B6050;margin:0 0 18px}
  .vr-tiles{display:flex;flex-direction:column;gap:12px}
  .vr-tile{position:relative;display:flex;flex-direction:column;justify-content:flex-end;min-height:clamp(150px,27vh,220px);padding:18px 52px 18px 18px;border-radius:20px;text-decoration:none}
  .vr-tile b{font-size:21px;font-weight:600;line-height:1.2}
  .vr-tile span{font-size:13.5px;margin-top:3px;opacity:.85}
  .vr-tile__ic{position:absolute;top:18px;left:18px;width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0!important;opacity:1!important}
  .vr-tile__ic svg,.vr-privacy svg{width:26px;height:26px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
  .vr-tile__go{position:absolute;right:20px;bottom:20px;font-size:22px!important;opacity:1!important}
  .vr-tile--b{background:#102F3A;color:#fff}.vr-tile--b .vr-tile__ic{background:rgba(255,255,255,.12)}
  .vr-tile--v{background:#fff;border:1.5px solid #e4e1da;color:#102F3A}.vr-tile--v .vr-tile__ic{background:#F2E8D6;color:#B8965A}
  .vr-tile:active{transform:scale(.99)}
  .vr-privacy{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.45;color:#6B6050;margin:18px 2px 0}
  .vr-privacy svg{width:18px;height:18px;flex:none;margin-top:1px;color:#1E5C6B}
  .vr-small{display:block;text-align:center;margin:16px 0 4px;font-size:14px;color:#1E5C6B;text-decoration:underline;text-underline-offset:3px}
  .vr-hint{font-size:13.5px;color:#6B6050;margin:-2px 0 10px}
  .vr-back{display:inline-block;font-size:14px;color:#1E5C6B;text-decoration:none;padding:4px 0 8px}
  .vr-code{font-size:20px!important;letter-spacing:4px;font-weight:600;text-transform:uppercase}
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
    <?php // Design A (owner's pick, Oct 2026 — docs/design/register-start-designs.html): two big tiles. ?>
    <div class="vr-start">
      <h1 class="vr-start__t">Welcome</h1>
      <p class="vr-start__sub">To keep everyone safe — our guests, our team and you — everyone registers before entering. It takes two minutes.</p>
      <div class="vr-tiles">
        <a class="vr-tile vr-tile--b" href="/register.php?step=booking">
          <span class="vr-tile__ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg></span>
          <b>I have a booking</b><span>Check in to your stay</span><span class="vr-tile__go" aria-hidden="true">&rarr;</span>
        </a>
        <a class="vr-tile vr-tile--v" href="/register.php?step=visitor<?= !empty($_GET['place']) ? '&amp;place=' . (int)$_GET['place'] : '' ?>">
          <span class="vr-tile__ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3h8l-1 7a3 3 0 0 1-6 0z"/><path d="M12 13v8M9 21h6"/></svg></span>
          <b>I’m a visitor</b><span>A drink, a meal, seeing a guest, work…</span><span class="vr-tile__go" aria-hidden="true">&rarr;</span>
        </a>
      </div>
      <p class="vr-privacy"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Your details are kept private and used only for security at our gates.</p>
      <a class="vr-small" href="/register.php?step=again">Registered before? Get your pass</a>
    </div>
  <?php elseif ($step === 'booking' || $step === 'nocode' || $step === 'again'): ?>
    <a href="/register.php" class="vr-back">&larr; Back</a>
    <form method="post" action="/api/visitor-register.php" class="pa-card" style="padding:18px;margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="ret" value="/register.php?step=<?= e($step) ?>">
      <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <?php if ($step === 'booking'): ?>
        <input type="hidden" name="action" value="lookup">
        <h2 class="ci-wg__title">Find your booking</h2>
        <p class="vr-hint">It’s in your booking email — or on your invitation if you’re with a group.</p>
        <label class="ci-l" for="vrCode">Booking or group code</label>
        <input class="ci-in vr-code" id="vrCode" name="code" autocapitalize="characters" autocomplete="off" autocorrect="off" spellcheck="false" value="<?= e((string)($old['code'] ?? '')) ?>" required>
        <?php // A booking code matches on the last word typed (the surname); a group code needs
              // first name + surname to find one person in the group (gi_group_lookup()). ?>
        <label class="ci-l" for="vrSurname">Your name</label>
        <input class="ci-in" id="vrSurname" name="name" autocomplete="name" placeholder="First name and surname" value="<?= e((string)($old['name'] ?? '')) ?>" required>
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
      <button class="pa-btn pa-btn--primary" style="margin-top:12px"><?= $step === 'booking' ? 'Continue to check-in' : 'Email me the link' ?></button>
      <?php if ($step === 'booking'): ?><a class="vr-small" href="/register.php?step=nocode">I don’t have my code</a><?php endif; ?>
    </form>
  <?php elseif ($step === 'used'): ?>
    <div class="pa-card" style="padding:18px"><p style="margin:0">This invitation has already been used. Open your pass from the email we sent you, or ask at the gate.</p></div>
  <?php elseif ($step === 'sent'): ?>
    <div class="pa-card" style="padding:18px"><p><?= e($notice !== '' ? $notice : 'Done.') ?></p><a class="pa-btn" href="/register.php">Back</a></div>
  <?php else: ?>
    <?php if (!$gate && !$me): ?><a href="/register.php" class="vr-back">&larr; Back</a><?php endif; ?>
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
