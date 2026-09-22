<?php
/**
 * The staff clock in/out kiosk. Full screen, no chrome, meant for a tablet
 * mounted at a property. Deliberately at the web root, not under /admin/.
 *
 * Two modes, chosen by whether the browser holds a device token:
 *   unregistered → a manager signs in and names the tablet (once, ever)
 *   registered   → camera, scan, confirm
 *
 * There is no staff session here. The DEVICE is the credential.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';        // session_init(), csrf_field()
require_once __DIR__ . '/includes/attendance-clock.php';

session_init();

$signedIn = !empty($_SESSION['admin_id']);
$canSetUp = $signedIn && (is_owner() || is_manager());

// The feature ships dark. While it is off the tablet says so plainly rather
// than showing a camera that can never record anything — and the JS below is
// not even loaded, so no camera permission is requested.
$kioskOn = clock_kiosk_enabled();

// The idle clock ticks from the SERVER's time, not the tablet's. The whole app
// is Africa/Nairobi and punches are stamped by PHP; a clock reading the
// tablet's own time could disagree with what a punch actually records, which
// is exactly the confusion a visible clock is supposed to prevent.
$nowTs    = time();
$nowLabel = date('H:i', $nowTs);
$dayLabel = date('l j F', $nowTs);

$venues = [];
if ($canSetUp) {
    $scope = admin_venue_ids();
    if ($scope === null) {
        $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
    } elseif ($scope) {
        $ph = []; $p = [];
        foreach ($scope as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
        $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Clock in — Tribal Sand</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
     background:#12262d;color:#f4efe6;min-height:100vh;display:flex;align-items:center;justify-content:center}
.kiosk{width:100%;max-width:640px;padding:24px;text-align:center}
.kiosk h1{font-size:24px;margin:0 0 4px;font-weight:500}
.kiosk p.sub{color:#9fb3ba;margin:0 0 24px;font-size:15px}
#video{width:100%;max-width:420px;border-radius:16px;background:#000;aspect-ratio:4/3;object-fit:cover}
.person{font-size:28px;margin:12px 0 2px}
.meta{color:#9fb3ba;font-size:15px;margin:0 0 20px}
.acts{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.big{border:0;border-radius:14px;padding:20px 28px;font-size:19px;font-weight:500;cursor:pointer;min-width:190px;min-height:64px}
.big--in{background:#2f6f4f;color:#fff}
.big--out{background:#8a4b2a;color:#fff}
.big--ghost{background:transparent;color:#9fb3ba;border:1.5px solid #34505a}
.msg{margin-top:18px;font-size:17px;min-height:26px}
.msg--bad{color:#ffb4a8}
.msg--good{color:#8fe0b4}
.setup{text-align:left;background:#193440;border-radius:14px;padding:20px}
.setup label{display:block;margin:0 0 12px;font-size:14px;color:#9fb3ba}
.setup input,.setup select{width:100%;padding:12px;border-radius:8px;border:1px solid #34505a;background:#0e1e24;color:#f4efe6;font-size:16px;margin-top:6px}
/* ── Idle screen ─────────────────────────────────────────────────────────── */
.idle{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:78vh}
.idle__glow{position:absolute;width:min(560px,90vw);aspect-ratio:1;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle,rgba(97,164,132,.20) 0%,rgba(18,38,45,0) 68%);
  animation:idleBreath 7s ease-in-out infinite}
.idle__logo{height:56px;width:auto;filter:brightness(0) invert(1);opacity:.92;position:relative;z-index:2;
  animation:idleRise 1.1s ease-out both, idlePulse 7s ease-in-out 1.1s infinite}
.idle__word{font-size:26px;letter-spacing:.18em;text-transform:uppercase;position:relative;z-index:2;
  animation:idleRise 1.1s ease-out both, idlePulse 7s ease-in-out 1.1s infinite}
.idle__sub{color:#9fb3ba;font-size:15px;margin:16px 0 26px;position:relative;z-index:2}
.idle__start{position:relative;z-index:2;min-width:260px;min-height:76px;font-size:21px;letter-spacing:.05em}
.idle__foot{position:absolute;bottom:18px;left:0;right:0;color:#6d868f;font-size:13px;z-index:2}
.idle__time{font-size:17px;color:#9fb3ba;display:block;margin-bottom:2px}

@keyframes idleBreath{0%,100%{transform:scale(.9);opacity:.55}50%{transform:scale(1.1);opacity:1}}
@keyframes idleRise{from{opacity:0;transform:translateY(14px)}to{opacity:.92;transform:none}}
@keyframes idlePulse{0%,100%{transform:scale(1)}50%{transform:scale(1.035)}}

/* A wall tablet runs this animation every waking hour. Honour the setting. */
@media (prefers-reduced-motion: reduce){
  .idle__glow,.idle__logo,.idle__word{animation:none}
}
.hidden{display:none}
</style>
</head>
<body>
<div class="kiosk">

<?php if (!$kioskOn): ?>
  <h1>Clocking in is switched off</h1>
  <p class="sub">
    <?php if ($canSetUp): ?>
      Turn it on in Admin → Clock kiosks, then reload this page.
    <?php else: ?>
      Ask a manager to switch it on.
    <?php endif; ?>
  </p>
<?php else: ?>

  <div id="kioskMode" class="hidden">
    <div id="idleMode" class="idle">
      <div class="idle__glow"></div>
      <img class="idle__logo" src="<?= e(asset_url('images/whitelogo11.png')) ?>" alt="Tribal Sand"
           onerror="this.outerHTML='<div class=\'idle__word\'>Tribal Sand</div>'">
      <p class="idle__sub">Tap to clock in or out</p>
      <button class="big big--in idle__start" id="startBtn">START</button>
      <p class="msg" id="idleMsg"></p>
      <div class="idle__foot">
        <span class="idle__time" id="idleClock" data-now="<?= e($nowLabel) ?>"><?= e($nowLabel) ?></span>
        <span id="idleWhere"><?= e($dayLabel) ?></span>
      </div>
    </div>

    <div id="scanMode" class="hidden">
      <h1>Scan your card</h1>
      <p class="sub">Hold it up to the camera</p>
      <video id="video" playsinline muted></video>
      <canvas id="frame" class="hidden"></canvas>
      <div class="acts" style="margin-top:18px">
        <button class="big big--ghost" data-cancel>Cancel</button>
      </div>
    </div>

    <div id="person" class="hidden">
      <div class="person" id="personName"></div>
      <p class="meta" id="personMeta"></p>
      <div class="acts" id="personActs"></div>
    </div>

    <p class="msg" id="msg"></p>
  </div>

  <div id="setupMode">
    <h1>Set up this tablet</h1>
    <?php if (!$canSetUp): ?>
      <p class="sub">An owner or manager needs to sign in on this device once to set it up.</p>
      <a class="big big--in" style="display:inline-block;text-decoration:none;line-height:24px"
         href="/admin/login.php?next=<?= e(urlencode('/clock.php')) ?>">Sign in</a>
    <?php elseif (!$venues): ?>
      <p class="sub">No properties are assigned to your account, so there is nothing to register this tablet against.</p>
    <?php else: ?>
      <p class="sub">This is a one-time step. The tablet stays signed in afterwards.</p>
      <div class="setup">
        <?= csrf_field() ?>
        <label>Name this tablet
          <input type="text" id="devName" placeholder="Zuri reception tablet" autocomplete="off">
        </label>
        <label>Property
          <select id="devVenue">
            <?php foreach ($venues as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="big big--in" id="devSave" style="width:100%">Register this tablet</button>
        <p class="msg" id="setupMsg"></p>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

</div>
<?php if ($kioskOn): ?>
<script src="/js/vendor/jsqr.js?v=<?= @filemtime(__DIR__ . '/js/vendor/jsqr.js') ?: time() ?>"></script>
<script src="/js/clock-kiosk.js?v=<?= @filemtime(__DIR__ . '/js/clock-kiosk.js') ?: time() ?>"></script>
<?php endif; ?>
</body>
</html>
