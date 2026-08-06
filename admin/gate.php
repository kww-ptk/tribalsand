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
$log    = fetch_visitors($venueIds, false, $today);       // still-on-site + signed in today
$onsite = 0; foreach ($log as $r) { if (empty($r['time_out'])) $onsite++; }

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Gate</h1>
  <span class="text-muted"><?= e(date('l j M', strtotime($today))) ?></span>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="card__head"><span class="card__title">Arriving today (<?= count($day['arriving']) ?>)</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Property</th><th>Room</th></tr></thead>
        <tbody>
          <?php if (!$day['arriving']): ?>
          <tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--muted)">No arrivals.</td></tr>
          <?php else: foreach ($day['arriving'] as $r): ?>
          <tr><td><strong><?= e($r['guest_name'] ?? 'Guest') ?></strong></td><td><?= e($r['venue_name'] ?? '') ?></td><td class="text-muted"><?= e($r['room_name'] ?? '') ?></td></tr>
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

<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Sign in a visitor</span></div>
  <div class="card__body" style="padding:20px 24px">
    <?php if (!$venues): ?>
      <p class="text-muted" style="margin:0">You have no properties assigned yet.</p>
    <?php else: ?>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="log">
      <label>Visitor name
        <input type="text" name="visitor_name" required style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label>Property
        <select name="venue_id" required style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Visiting <span class="text-muted">(guest / room)</span>
        <input type="text" name="visiting" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label>Purpose <span class="text-muted">(optional)</span>
        <input type="text" name="purpose" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label>Vehicle / plate <span class="text-muted">(optional)</span>
        <input type="text" name="vehicle" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <div><button type="submit" class="btn-primary">Sign in</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head">
    <span class="card__title">Visitor log</span>
    <span class="badge badge--blue"><?= (int)$onsite ?> on site</span>
  </div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Visitor</th><th>Property</th><th>Visiting</th><th>Purpose</th><th>Vehicle</th><th>In</th><th>Out</th><th>Action</th></tr></thead>
      <tbody>
        <?php if (!$log): ?>
        <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">No visitors logged today.</td></tr>
        <?php else: foreach ($log as $vr): $open = empty($vr['time_out']); ?>
        <tr<?= $open ? '' : ' style="opacity:.6"' ?>>
          <td><strong><?= e($vr['visitor_name']) ?></strong></td>
          <td><?= e($vr['venue_name'] ?? '') ?></td>
          <td><?= !empty($vr['visiting']) ? e($vr['visiting']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['purpose']) ? e($vr['purpose']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['vehicle']) ? e($vr['vehicle']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted"><?= e(date('H:i', strtotime((string)$vr['time_in']))) ?></td>
          <td class="text-muted"><?= $open ? '<span class="badge badge--green">On site</span>' : e(date('H:i', strtotime((string)$vr['time_out']))) ?></td>
          <td>
            <div class="row-actions">
            <?php if ($open): ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="signout"><input type="hidden" name="id" value="<?= (int)$vr['id'] ?>"><button class="btn-icon btn-icon--outline" title="Sign out visitor" aria-label="Sign out <?= e($vr['visitor_name']) ?>"><?= admin_icon('logout') ?></button></form>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
