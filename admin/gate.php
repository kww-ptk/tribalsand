<?php
/**
 * Admin: Gate — front-gate view. Today's expected arrivals & departures for the
 * viewer's properties (from the Front Desk data) plus a walk-up visitor register
 * (sign in / sign out). Owner, managers and gate-security staff can use it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';    // team (visitor) helpers
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';   // checkin_badge() on arrival rows
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_login();
require_gate();

$pageTitle  = 'Gate';
$activeMenu = 'gate';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();                 // null = owner (all)
$inScope  = fn(int $vid): bool => $venueIds === null || in_array($vid, $venueIds, true);

// Venues in scope (for the sign-in form's property picker).
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}
$scopedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if (!visitors_supported()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'The visitor register isn’t enabled yet — run add_visitors.sql.'];
        header('Location: /admin/gate.php'); exit;
    }

    if ($action === 'log') {
        $venue   = (int)($_POST['venue_id'] ?? 0);
        $name    = trim((string)($_POST['visitor_name'] ?? ''));
        $visiting = trim((string)($_POST['visiting'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $vehicle = trim((string)($_POST['vehicle'] ?? ''));
        if (!in_array($venue, $scopedVenueIds, true)) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Choose a property you cover.'];
        } elseif ($name === '') {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Enter the visitor’s name.'];
        } else {
            db_query(
                "INSERT INTO visitors (venue_id, visitor_name, visiting, purpose, vehicle, logged_by)
                 VALUES (:v, :n, :vg, :p, :veh, :lb)",
                [':v'=>$venue, ':n'=>$name, ':vg'=>($visiting !== '' ? $visiting : null),
                 ':p'=>($purpose !== '' ? $purpose : null), ':veh'=>($vehicle !== '' ? $vehicle : null),
                 ':lb'=>$meId ?: null]
            );
            audit_log('visitor.in', 'visitor', (int)db()->lastInsertId(), $name);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Visitor signed in.'];
        }
        header('Location: /admin/gate.php'); exit;
    }

    if ($action === 'signout') {
        $id = (int)($_POST['id'] ?? 0);
        $vr = $id ? fetch_visitor($id) : false;
        if ($vr && $inScope((int)$vr['venue_id'])) {
            $n = db_query("UPDATE visitors SET time_out = NOW() WHERE id = :id AND time_out IS NULL", [':id'=>$id])->rowCount();
            if ($n) { audit_log('visitor.out', 'visitor', $id, ''); $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Visitor signed out.']; }
            else    { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Already signed out.']; }
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
        }
        header('Location: /admin/gate.php'); exit;
    }
}

$today  = frontdesk_today_ymd();
$day    = frontdesk_day($venueIds, $today);

// Live "on site" count — everyone still signed in, independent of the log filters.
$onsite = count(fetch_visitors($venueIds, true));

// ── Visitor log filters + pagination ────────────────────────────
$scopeKey = in_array($_GET['scope'] ?? '', ['onsite','all'], true) ? $_GET['scope'] : 'onsite';
$dateKey  = trim((string)($_GET['vdate'] ?? ''));
if ($dateKey !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) $dateKey = '';

$pg   = paginate_params(25);
$vpaged = fetch_visitors_paged($venueIds, [
    'scope'  => $scopeKey,
    'date'   => $dateKey,
    'q'      => $pg['q'],
    'per'    => $pg['per'],
    'offset' => 0,   // placeholder; recomputed against meta below
]);
$meta = paginate_meta($vpaged['total'], $pg['page'], $pg['per']);
// Re-fetch the correct page slice now that offset is known.
$vpaged = fetch_visitors_paged($venueIds, [
    'scope' => $scopeKey, 'date' => $dateKey, 'q' => $pg['q'],
    'per'   => $meta['per'], 'offset' => $meta['offset'],
]);
$log = $vpaged['rows'];

// ── Swappable body (visitor log table + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$log): ?>
    <?php dt_empty($pg['q'] !== '' ? 'No visitors match your search.' : 'No visitors match this filter.'); ?>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Visitor</th><th>Property</th><th>Visiting</th><th>Purpose</th><th>Vehicle</th><th>In</th><th>Out</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($log as $vr): $open = empty($vr['time_out']); ?>
        <tr<?= $open ? '' : ' style="opacity:.6"' ?>>
          <td><strong><?= e($vr['visitor_name']) ?></strong></td>
          <td><?= e($vr['venue_name'] ?? '') ?></td>
          <td><?= !empty($vr['visiting']) ? e($vr['visiting']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['purpose']) ? e($vr['purpose']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['vehicle']) ? e($vr['vehicle']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted"><?= e(date('j M H:i', strtotime((string)$vr['time_in']))) ?></td>
          <td class="text-muted"><?= $open ? '<span class="badge badge--green">On site</span>' : e(date('j M H:i', strtotime((string)$vr['time_out']))) ?></td>
          <td>
            <div class="row-actions">
            <?php if ($open): ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="signout"><input type="hidden" name="id" value="<?= (int)$vr['id'] ?>"><button class="btn-icon btn-icon--outline" title="Sign out visitor" aria-label="Sign out <?= e($vr['visitor_name']) ?>"><?= admin_icon('logout') ?></button></form>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

// AJAX fragment: emit only the swappable body and stop.
if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<?php
  // Reveal the sign-in form on load if a sign-in just failed (so the user sees
  // their entries + the error) — otherwise it stays collapsed behind the button.
  $signInOpen = $flash && ($flash['type'] ?? '') === 'error';
?>
<div class="page-header">
  <h1>Gate</h1>
  <div class="actions" style="display:flex;align-items:center;gap:14px">
    <span class="text-muted"><?= e(date('l j M', strtotime($today))) ?></span>
    <?php if (visitors_supported() && $venues): ?>
    <button type="button" class="btn-primary btn-sm" id="gateSignInToggle" aria-expanded="<?= $signInOpen ? 'true' : 'false' ?>" aria-controls="gateSignInCard"><?= admin_icon('plus', 15) ?> Sign in visitor</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="card__head"><span class="card__title">Arriving today (<?= count($day['arriving']) ?>)</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Room</th><th>Party</th><th></th></tr></thead>
        <tbody>
          <?php if (!$day['arriving']): ?>
          <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--muted)">No arrivals.</td></tr>
          <?php else: foreach ($day['arriving'] as $r): ?>
          <?php
            $__n     = (int)($r['guest_count'] ?? 0);
            $__badge = checkin_badge($r);
            $__vid   = (int)($r['venue_id'] ?? 0);
            $__room  = trim(((string)($r['venue_name'] ?? '')) . ' · ' . ((string)($r['room_name'] ?? '')), ' ·');
          ?>
          <tr>
            <td><strong><?= e($r['guest_name'] ?? 'Guest') ?></strong>
              <?php if (($r['status'] ?? '') !== 'confirmed'): ?> <span class="badge badge--orange">Pending</span><?php endif; ?>
            </td>
            <td class="text-muted"><?= e($__room) ?></td>
            <td>
              <?= $__n > 0 ? e($__n . ' adult' . ($__n === 1 ? '' : 's')) : '<span class="text-muted">—</span>' ?>
              <?php if ($__badge): ?><br><span class="ci-badge <?= e($__badge['class']) ?>"><?= e($__badge['label']) ?></span><?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php if (visitors_supported() && in_array($__vid, $scopedVenueIds, true)): ?>
              <button type="button" class="btn-sm btn-outline gate-signin"
                      data-name="<?= e((string)($r['guest_name'] ?? '')) ?>"
                      data-venue="<?= $__vid ?>"
                      data-room="<?= e($__room) ?>">Sign in</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card__head"><span class="card__title">Departing today (<?= count($day['departing']) ?>)</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Property</th><th>Room</th></tr></thead>
        <tbody>
          <?php if (!$day['departing']): ?>
          <tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--muted)">No departures.</td></tr>
          <?php else: foreach ($day['departing'] as $r): ?>
          <tr><td><strong><?= e($r['guest_name'] ?? 'Guest') ?></strong></td><td><?= e($r['venue_name'] ?? '') ?></td><td class="text-muted"><?= e($r['room_name'] ?? '') ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!visitors_supported()): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">The visitor register isn’t enabled yet. Ask the owner to run the <code>add_visitors.sql</code> migration.</p></div></div>
<?php else: ?>

<div class="card" id="gateSignInCard" style="margin-bottom:16px<?= $signInOpen ? '' : ';display:none' ?>">
  <div class="card__head"><span class="card__title">Sign in a visitor</span></div>
  <div class="card__body" style="padding:20px 24px">
    <?php if (!$venues): ?>
      <p class="text-muted" style="margin:0">You have no properties assigned yet.</p>
    <?php else: ?>
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:16px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="log">
      <label style="flex:1 1 200px;min-width:0">Visitor name
        <input type="text" name="visitor_name" required placeholder="Enter visitor name" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Property
        <select name="venue_id" required style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label style="flex:1 1 200px;min-width:0">Visiting <span class="text-muted">(guest / room)</span>
        <input type="text" name="visiting" placeholder="Enter guest or room being visited" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Purpose <span class="text-muted">(optional)</span>
        <input type="text" name="purpose" placeholder="Enter purpose of visit" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Vehicle / plate <span class="text-muted">(optional)</span>
        <input type="text" name="vehicle" placeholder="Enter vehicle or plate number" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <div style="flex:0 0 auto"><button type="submit" class="btn-primary">Sign in</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="page-header" style="margin-top:8px">
  <h2 style="margin:0;font-size:18px">Visitor log</h2>
  <span class="badge badge--blue"><?= (int)$onsite ?> on site</span>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/gate.php" class="filters" id="gateLogFilters">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$meta['per'] ?>">
  <label class="filter-field">Show
    <select name="scope" class="filter-select" aria-label="Filter by presence" onchange="this.form.submit()">
      <option value="onsite" <?= $scopeKey === 'onsite' ? 'selected' : '' ?>>On site now</option>
      <option value="all"    <?= $scopeKey === 'all'    ? 'selected' : '' ?>>All visitors</option>
    </select>
  </label>
  <label class="filter-field">Date
    <button type="button" class="dp-btn" data-dp-target="gateVdate" data-dp-placeholder="Any date" style="width:150px"><?= $dateKey !== '' ? e(date('j M Y', strtotime($dateKey))) : 'Any date' ?></button>
    <input type="hidden" id="gateVdate" name="vdate" value="<?= e($dateKey) ?>">
  </label>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, visiting, purpose or vehicle…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php endif; ?>

<script>
(function () {
  var btn  = document.getElementById('gateSignInToggle');
  var card = document.getElementById('gateSignInCard');
  if (btn && card) {
    btn.addEventListener('click', function () {
      var open = card.style.display !== 'none';
      card.style.display = open ? 'none' : '';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (!open) { var f = card.querySelector('input[name="visitor_name"]'); if (f) f.focus(); }
    });
  }
  // One-tap sign-in for an arriving booking: open the existing visitor form with
  // everything we already know filled in, leaving only the plate to type.
  document.querySelectorAll('.gate-signin').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!card) return;
      card.style.display = '';
      if (btn) btn.setAttribute('aria-expanded', 'true');
      var set = function (name, val) { var el = card.querySelector('[name="' + name + '"]'); if (el) el.value = val; };
      set('visitor_name', b.getAttribute('data-name') || '');
      set('venue_id',     b.getAttribute('data-venue') || '');
      set('visiting',     b.getAttribute('data-room') || '');
      set('purpose',      'Guest arrival');
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var veh = card.querySelector('[name="vehicle"]');
      if (veh) veh.focus();
    });
  });
  // Gate-log date filter — auto-submit when a date is picked.
  var vd = document.getElementById('gateVdate');
  var lf = document.getElementById('gateLogFilters');
  if (vd && lf) vd.addEventListener('change', function () { lf.submit(); });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
