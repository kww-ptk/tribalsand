<?php
/**
 * Register THIS tablet as a POS terminal — done on the tablet itself, by a
 * signed-in owner or manager (same pattern as the clock kiosk). The terminal
 * token is created here and lives only in this browser's httpOnly cookie; the
 * DB keeps its sha256.
 *
 * On success the admin session on this tablet is ENDED: a shared till must never
 * carry someone's admin login. From then on the tablet shows the PIN lock screen.
 * A manager can only register against outlets they manage.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/pos-auth.php';

session_init();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$admin   = current_admin();
$canSet  = $admin && pos_supported() && in_array($admin['role'] ?? '', ['owner', 'manager'], true);
$current = pos_supported() ? pos_current_terminal() : null;
$error   = '';

$outletIds = $canSet ? pos_manageable_outlet_ids($admin) : [];
$outlets   = $outletIds ? pos_fetch_outlets($outletIds) : [];
$venues    = [];
if ($canSet) {
    $scope = ($admin['role'] === 'owner') ? null
        : array_map('intval', db_query('SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :u', [':u' => (int)$admin['id']])->fetchAll(PDO::FETCH_COLUMN));
    $venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
    if ($scope !== null) $venues = array_values(array_filter($venues, fn($v) => in_array((int)$v['id'], $scope, true)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSet) {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $vid  = (int)($_POST['venue_id'] ?? 0);
    $vid  = in_array($vid, array_map(fn($v) => (int)$v['id'], $venues), true) ? $vid : null;
    $sel  = array_values(array_intersect(array_map('intval', (array)($_POST['outlets'] ?? [])), $outletIds));
    if ($name === '' || mb_strlen($name) > 120) $error = 'Give this tablet a name, e.g. "Zuri front desk".';
    elseif (!$sel) $error = 'Pick at least one outlet this till sells for.';
    else {
        // Re-registering retires this tablet's previous row (its token is about to be replaced).
        if ($current) db_query('UPDATE pos_terminals SET is_active = FALSE WHERE id = :i', [':i' => (int)$current['id']]);
        $tid = pos_register_terminal($name, $vid, $sel, (int)$admin['id']);
        audit_log('pos.terminal_register', 'pos_terminal', $tid, $name);
        // End the admin login on this shared device; the terminal cookie stays.
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: /pos/'); exit;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Set up a till — Tribal Sand POS</title>
<link rel="stylesheet" href="/css/pos.css?v=<?= (int) @filemtime(__DIR__ . '/../css/pos.css') ?>">
<style>
  .reg{background:#fff;color:var(--text);border-radius:16px;padding:24px;text-align:left;max-width:520px;margin:0 auto}
  .reg h1{font-size:20px;margin:0 0 6px}.reg p{color:var(--muted);margin:0 0 16px}
  .reg .lbl{margin-top:16px}
  .reg .field{margin-top:6px}
  .chiprow{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
  .chipopt{position:relative;display:inline-flex;align-items:center;padding:9px 14px;border-radius:20px;border:1px solid var(--border);cursor:pointer;user-select:none}
  .chipopt input{position:absolute;opacity:0;width:0;height:0}
  .chipopt:has(input:checked){background:var(--brand);border-color:var(--brand);color:#fff}
  .chipopt:has(input:focus-visible){outline:2px solid var(--accent);outline-offset:2px}
  .reg .btn{margin-top:22px;width:100%}
</style>
</head>
<body class="pos">
<div class="lock"><div class="lock__card">
  <div class="lock__brand">TRIBAL SAND POS</div>
  <?php if (!pos_supported()): ?>
    <h1 class="lock__title">The POS isn’t switched on yet</h1>
  <?php elseif (!$admin): ?>
    <h1 class="lock__title">Set up this tablet as a till</h1>
    <p class="lock__text">An owner or manager signs in on this tablet once, then comes back to this page.</p>
    <a class="lock__btn" href="/admin/login.php">Sign in</a>
  <?php elseif (!$canSet): ?>
    <h1 class="lock__title">Only an owner or manager can set up a till</h1>
    <a class="lock__btn" href="/admin/">Back to admin</a>
  <?php elseif (!$outlets): ?>
    <h1 class="lock__title">You don’t manage any POS outlets</h1>
    <p class="lock__text">Outlets are created in Admin → POS outlets.</p>
    <a class="lock__btn" href="/admin/">Back to admin</a>
  <?php else: ?>
    <form method="POST" class="reg">
      <?= csrf_field() ?>
      <h1><?= $current ? 'Re-register this tablet' : 'Set up this tablet as a till' ?></h1>
      <p><?= $current ? 'It is currently “' . e($current['name']) . '”. Registering again replaces that.' : 'Staff will unlock it with their PIN. Your admin sign-in ends on this tablet once it’s set up.' ?></p>
      <?php if ($error): ?><div class="err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <span class="lbl">Tablet name</span>
      <label class="field"><input name="name" maxlength="120" required placeholder="e.g. Zuri front desk" value="<?= e((string)($_POST['name'] ?? '')) ?>" autocomplete="off"></label>
      <?php if ($venues): ?>
      <span class="lbl">Property</span>
      <div class="chiprow">
        <?php if ($admin['role'] === 'owner'): ?><label class="chipopt"><input type="radio" name="venue_id" value="0" <?= empty($_POST['venue_id']) ? 'checked' : '' ?>>Shared</label><?php endif; ?>
        <?php foreach ($venues as $i => $v): ?><label class="chipopt"><input type="radio" name="venue_id" value="<?= (int)$v['id'] ?>" <?= (int)($_POST['venue_id'] ?? 0) === (int)$v['id'] || ($admin['role'] !== 'owner' && $i === 0 && empty($_POST['venue_id'])) ? 'checked' : '' ?>><?= e($v['name']) ?></label><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <span class="lbl">Sells for</span>
      <div class="chiprow">
        <?php foreach ($outlets as $o): ?><label class="chipopt"><input type="checkbox" name="outlets[]" value="<?= (int)$o['id'] ?>" <?= in_array((int)$o['id'], array_map('intval', (array)($_POST['outlets'] ?? [])), true) ? 'checked' : '' ?>><?= e($o['name']) ?></label><?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn--p">Register this tablet</button>
    </form>
  <?php endif; ?>
</div></div>
</body>
</html>
