<?php
/**
 * The POS till — full screen, tablet-landscape first. UI reference:
 * docs/pos/pos-prototype.html. Behaviour lives in js/pos.js; every write goes
 * through api/pos/*.php, which re-checks everything server-side.
 *
 * Modes (includes/pos-auth.php):
 *   • registered tablet → lock screen (tap name + PIN) → till
 *   • no tablet cookie  → the signed-in admin's own till (any device)
 * Root-relative URLs only, so a pos.tribalsand.com alias later needs no code change.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/pos-auth.php';

session_init();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$state = 'till';
$msg   = '';
$ctx   = null;
$term  = null;
$people = [];

if (!pos_supported()) {
    $state = 'off';
} else {
    $term = pos_current_terminal();
    $ctx  = pos_current();
    if (!$ctx) {
        if ($term)                        { $state = 'lock'; $people = pos_terminal_people($term); }
        elseif (pos_has_terminal_cookie()) { $state = 'revoked'; }
        else                              { $state = 'signin'; }
    } elseif (!$ctx['outlet_ids']) {
        $state = 'none';
    }
}

$kindIcon = ['experiences' => 'wave', 'shop' => 'bag', 'salon_spa' => 'flower', 'kite' => 'wind', 'other' => 'store'];
$boot = null;
if ($state === 'till') {
    $outlets = pos_fetch_outlets($ctx['outlet_ids']);
    $u = $ctx['user'];
    $boot = [
        'mode'      => $ctx['mode'],
        'csrf'      => csrf_token(),
        'idleLock'  => $ctx['mode'] === 'pin' ? pos_idle_lock_seconds() : 0,
        'terminal'  => $term ? (string)$term['name'] : null,
        'user'      => ['id' => (int)$u['id'], 'name' => (string)($u['name'] ?: $u['email']), 'initials' => pos_initials((string)($u['name'] ?: $u['email'])), 'role' => pos_role_label($u)],
        'outlets'   => array_map(fn($o) => ['id' => (int)$o['id'], 'name' => (string)$o['name'], 'icon' => $kindIcon[$o['kind']] ?? 'store'], $outlets),
        'payments'  => POS_PAYMENT_METHODS,
        'adminUrl'  => $ctx['mode'] === 'admin' ? '/admin/' : null,
    ];
}
$v = fn(string $rel) => (int) @filemtime(__DIR__ . '/../' . $rel);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#102F3A">
<title>Tribal Sand POS</title>
<link rel="manifest" href="/pos/manifest.json">
<link rel="stylesheet" href="/css/pos.css?v=<?= $v('css/pos.css') ?>">
</head>
<body class="pos pos--<?= e($state) ?>">
<?php include __DIR__ . '/_icons.php'; ?>

<?php if ($state === 'off'): ?>
  <div class="lock"><div class="lock__card"><div class="lock__brand">TRIBAL SAND POS</div>
    <h1 class="lock__title">The till isn’t switched on yet</h1>
    <p class="lock__text">An administrator needs to run the POS migration first.</p></div></div>

<?php elseif ($state === 'signin'): ?>
  <div class="lock"><div class="lock__card"><div class="lock__brand">TRIBAL SAND POS</div>
    <h1 class="lock__title">Sign in to open the till</h1>
    <p class="lock__text">Use your admin account, or ask a manager to register this tablet as a till.</p>
    <a class="lock__btn" href="/admin/login.php">Sign in</a></div></div>

<?php elseif ($state === 'revoked'): ?>
  <div class="lock"><div class="lock__card"><div class="lock__brand">TRIBAL SAND POS</div>
    <h1 class="lock__title">This tablet is no longer a till</h1>
    <p class="lock__text">Its registration was removed. A manager can set it up again.</p>
    <a class="lock__btn" href="/pos/register.php">Set up this tablet</a></div></div>

<?php elseif ($state === 'none'): ?>
  <div class="lock"><div class="lock__card"><div class="lock__brand">TRIBAL SAND POS</div>
    <h1 class="lock__title">No outlets for you here</h1>
    <p class="lock__text"><?= $ctx['mode'] === 'pin' ? 'You are not assigned to any outlet this tablet sells for.' : 'You are not assigned to any POS outlet yet. Ask the owner to add you under Admin → POS outlets.' ?></p>
    <?php if ($ctx['mode'] === 'pin'): ?><button class="lock__btn" data-action="lock" type="button">Back to the lock screen</button>
    <?php else: ?><a class="lock__btn" href="/admin/">Back to admin</a><?php endif; ?></div></div>
  <script>window.POS_CSRF = <?= json_encode(csrf_token()) ?>;</script>

<?php elseif ($state === 'lock'): ?>
  <div class="lock" id="lock">
    <div class="lock__card">
      <div class="lock__brand">TRIBAL SAND POS</div>
      <div class="lock__term"><?= e($term['name']) ?></div>
      <?php if (!$people): ?>
        <h1 class="lock__title">No one can unlock this till yet</h1>
        <p class="lock__text">A manager sets staff PINs in Admin → POS → Staff PINs, and assigns this tablet’s outlets in Admin → POS → Terminals.</p>
      <?php else: ?>
        <div class="lock__people" id="people">
          <?php foreach ($people as $p): ?>
          <button type="button" class="person" data-p="<?= (int)$p['id'] ?>"><span class="avatar"><?= e(pos_initials($p['name'])) ?></span><?= e($p['name']) ?><small><?= e($p['role']) ?></small></button>
          <?php endforeach; ?>
        </div>
        <div class="pin-dots" id="dots" aria-live="polite"></div>
        <div class="lock__err" id="pinErr" role="alert"></div>
        <div class="keypad" id="keypad" hidden>
          <?php foreach (['1','2','3','4','5','6','7','8','9'] as $k): ?><button type="button" data-k="<?= $k ?>"><?= $k ?></button><?php endforeach; ?>
          <button type="button" data-k="clear" aria-label="Clear"><svg><use href="#i-x"/></svg></button>
          <button type="button" data-k="0">0</button>
          <button type="button" data-k="ok" class="keypad__ok" aria-label="Unlock"><svg><use href="#i-check"/></svg></button>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>window.POS_CSRF = <?= json_encode(csrf_token()) ?>;</script>

<?php else: ?>
<div class="app" id="app">
  <aside class="side">
    <div class="logo">TRIBAL SAND</div>
    <div id="outlets" class="side__outlets"></div>
    <div class="side__sep"></div>
    <button class="outlet" id="histBtn" type="button"><svg><use href="#i-clock"/></svg><span>Sales history</span></button>
    <?php if ($boot['mode'] === 'pin'): ?>
    <button class="outlet" id="lockBtn" type="button"><svg><use href="#i-lock"/></svg><span>Lock</span></button>
    <?php else: ?>
    <a class="outlet" href="/admin/"><svg><use href="#i-back"/></svg><span>Admin</span></a>
    <?php endif; ?>
    <div class="side__foot"><?= $boot['terminal'] ? e($boot['terminal']) . '<br>' : '' ?><?= $boot['idleLock'] ? 'Locks after ' . (int)ceil($boot['idleLock'] / 60) . ' min idle' : 'Signed in via admin' ?></div>
  </aside>

  <main class="main">
    <div class="top">
      <h1 id="outletTitle">&nbsp;</h1>
      <div class="who"><span class="avatar"><?= e($boot['user']['initials']) ?></span><div><div class="who__n"><?= e($boot['user']['name']) ?></div><div class="who__r"><?= e($boot['user']['role']) ?></div></div></div>
    </div>
    <div class="chips" id="chips"></div>
    <label class="search"><svg><use href="#i-search"/></svg><input id="q" placeholder="Search items…" autocomplete="off" enterkeyhint="search"></label>
    <div class="grid" id="grid"><div class="empty">Loading…</div></div>
  </main>

  <section class="panel" id="panel">
    <button class="panel__handle" id="panelHandle" type="button" aria-label="Show order"><span id="handleText">Order</span><svg><use href="#i-up"/></svg></button>
    <div class="panel__scroll">
      <div class="panel__sec">
        <h3>Customer</h3>
        <div class="seg">
          <button type="button" id="segIn" class="is-on"><svg><use href="#i-bed"/></svg>In-house guest</button>
          <button type="button" id="segWalk"><svg><use href="#i-user"/></svg>Walk-in</button>
        </div>
        <div id="custBox"></div>
      </div>
      <div class="panel__sec order" id="orderSec">
        <div class="order__head"><h3 id="orderTitle">Order</h3><button type="button" class="linkbtn" id="clearBtn">Clear all</button></div>
        <div id="lines"></div>
      </div>
    </div>
    <div class="totals" id="totals"></div>
    <div class="pay" id="pay"></div>
    <div class="note" id="payNote"></div>
    <button type="button" class="cta" id="cta" disabled>Review sale <svg><use href="#i-arrow"/></svg></button>
  </section>
</div>
<div class="modal hidden" id="modal" role="dialog" aria-modal="true"><div class="sheet" id="sheet"></div></div>
<div class="toast hidden" id="toast" role="status"></div>
<script>window.POS_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php endif; ?>
<?php if ($state === 'till'): ?><script src="/js/signature-pad.js?v=<?= $v('js/signature-pad.js') ?>"></script><?php endif; ?>
<script src="/js/pos.js?v=<?= $v('js/pos.js') ?>"></script>
</body>
</html>
