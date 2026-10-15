<?php
/** Gate → Places & posters (owner): the "Where are you going?" list and a QR poster per place. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login();
require_owner();

$pageTitle  = 'Places & posters';
$activeMenu = 'gate';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $id  = (int)($_POST['id'] ?? 0) ?: null;
        $pid = visitor_place_save($id, (string)($_POST['name'] ?? ''), (int)($_POST['venue_id'] ?? 0), (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']));
        audit_log('visitor.place_save', 'visitor_place', $pid, (string)($_POST['name'] ?? ''));
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Place saved.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: /admin/visitor-places.php');
    exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$places = visitor_places(false);
$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
/** A venue picker; $form ties it to a <form> elsewhere in the row (a <form> cannot sit in a <tr>). */
$venueSelect = function (int $sel, string $form = '') use ($venues): string {
    $o = '<select name="venue_id" class="inp" required' . ($form !== '' ? ' form="' . e($form) . '"' : '') . '><option value="">Behind the gate of…</option>';
    foreach ($venues as $v) {
        $o .= '<option value="' . (int)$v['id'] . '"' . ((int)$v['id'] === $sel ? ' selected' : '') . '>' . e((string)$v['name']) . '</option>';
    }
    return $o . '</select>';
};
$supported = visitor_reg_supported();
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Places &amp; posters</h1>
  <?php if ($supported): ?><button type="button" class="btn-primary btn-sm" id="vpNewBtn" aria-controls="vpNew" aria-expanded="false"><?= admin_icon('plus', 15) ?> Add a place</button><?php endif; ?></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>
<?php if (!$supported): ?>
<div class="alert alert--error">Run add_visitor_registration.sql (Settings → Your account → Migrations) to turn on visitor registration.</div>
<?php else: ?>
<div class="card" id="vpNew" data-inline-form style="margin-bottom:16px" hidden>
  <div class="card__head"><span class="card__title">New place</span></div>
  <form method="POST" action="/admin/visitor-places.php" class="card__body" style="padding:16px 20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?= csrf_field() ?>
    <input class="inp" id="vpNewName" name="name" placeholder="e.g. Tribal Table" maxlength="80" required style="max-width:260px">
    <?= $venueSelect(0) ?>
    <input class="inp" type="number" name="sort_order" value="<?= count($places) + 1 ?>" style="width:90px" aria-label="Order">
    <input type="hidden" name="is_active" value="1">
    <button class="btn-primary btn-sm">Add</button>
  </form>
</div>
<script>
(function () {
  var b = document.getElementById('vpNewBtn'), c = document.getElementById('vpNew');
  if (!b || !c || b.dataset.ready) return; b.dataset.ready = '1';
  b.addEventListener('click', function () {
    var open = c.hidden;
    c.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { var t = document.getElementById('vpNewName'); if (t) t.focus(); }
  });
})();
</script>
<div class="card"><div class="card__body" style="padding:0"><div class="table-wrap">
<?php if (!$places): ?><div class="dt-empty"><p class="dt-empty__msg">No places yet. Add one to let visitors choose where they are going.</p></div><?php else: ?>
<table class="data-table" data-help="vr-places">
  <thead><tr><th>Place</th><th>Gate of</th><th>Order</th><th>Shown</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($places as $p): $f = 'vpRow' . (int)$p['id']; ?>
    <tr>
      <td><input class="inp" name="name" form="<?= $f ?>" value="<?= e((string)$p['name']) ?>" maxlength="80" required aria-label="Place name"></td>
      <td><?= $venueSelect((int)$p['venue_id'], $f) ?></td>
      <td><input class="inp" type="number" name="sort_order" form="<?= $f ?>" value="<?= (int)$p['sort_order'] ?>" style="width:80px" aria-label="Order"></td>
      <td><label class="optchip"><input type="checkbox" name="is_active" form="<?= $f ?>" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> Shown</label></td>
      <td style="white-space:nowrap;text-align:right">
        <form id="<?= $f ?>" method="POST" action="/admin/visitor-places.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn-icon btn-icon--outline" title="Save" aria-label="Save <?= e((string)$p['name']) ?>"><?= admin_icon('check') ?></button></form>
        <a class="btn-outline btn-sm" href="/admin/visitor-poster-print.php?place=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" data-no-shell>Print poster</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div></div></div>
<p class="text-muted" style="margin-top:12px">The general link is <strong><?= e(site_url('/register')) ?></strong>. A poster’s QR opens it with that place already chosen.</p>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
