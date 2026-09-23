<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';           // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/sync-reserve.php';   // reservation_book(): Zuri /reserve for the synced venue
require_once __DIR__ . '/../includes/mail.php';
require_login();
require_reception();                 // owner or house manager

$scope = admin_venue_ids();        // null = owner (all); array = manager's venues

// ── POST: status actions + staff booking (scoped, per-row ownership re-check) ──
// Status moves go through set_reservation_status(), which enforces the §6 state
// machine and — for a booking that exists on Zuri — queues the change for Zuri.
$resActions = [
    'confirm'  => ['confirmed', 'reservation.confirm'],
    'seat'     => ['seated',    'reservation.seat'],
    'complete' => ['completed', 'reservation.complete'],
    'no_show'  => ['no_show',   'reservation.no_show'],
    'cancel'   => ['cancelled', 'reservation.cancel'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Staff booking: the SAME validator as the public form, then reservation_book()
    // (Zuri's /reserve for the synced venue, a local request everywhere else).
    if (($_POST['action'] ?? '') === 'new_booking') {
        $nb = [
            'venue_id'         => (int)($_POST['venue_id'] ?? 0),
            'reservation_date' => trim((string)($_POST['reservation_date'] ?? '')),
            'reservation_time' => trim((string)($_POST['reservation_time'] ?? '')),
            'party_size'       => (int)($_POST['party_size'] ?? 0),
            'guest_name'       => trim((string)($_POST['guest_name'] ?? '')),
            'guest_phone'      => trim((string)($_POST['guest_phone'] ?? '')),
            'guest_email'      => trim((string)($_POST['guest_email'] ?? '')),
            'notes'            => trim((string)($_POST['notes'] ?? '')),
            'preference'       => in_array($_POST['preference'] ?? '', ['Lunch', 'Dinner', 'Private Dining'], true) ? $_POST['preference'] : '',
        ];
        $nbVenue = ($scope === null || in_array($nb['venue_id'], $scope, true)) && $nb['venue_id'] > 0
            ? db_query('SELECT id, slug, is_published FROM venues WHERE id = :id', [':id' => $nb['venue_id']])->fetch()
            : false;
        $errs = reservation_validate($nb, $nbVenue);
        if (!$errs) {
            $booked = reservation_book($nb + [
                'reservation_date' => date('Y-m-d', (int)strtotime($nb['reservation_date'])),
                'menu_id'          => reservation_menu_id_for_venue($nb['venue_id']),
                'source'           => 'staff',
            ]);
            if ($booked['ok']) {
                $r = $booked['reservation'];
                audit_log('reservation.staff_create', 'reservation', (int)($r['id'] ?? 0), (string)($r['reference'] ?? ''));
                $_SESSION['res_flash'] = $booked['via'] === 'local_fallback'
                    ? ['type' => 'error', 'msg' => 'Zuri could not be reached — saved here as a pending request (' . ($r['reference'] ?? '') . '). Enter it on Zuri too.']
                    : ['type' => 'success', 'msg' => 'Booked ' . $nb['guest_name'] . ' — ' . ($r['reference'] ?? '') . ($booked['via'] === 'zuri' ? ' (on Zuri)' : '') . '.'];
                header('Location: /admin/reservations.php'); exit;
            }
            $alts = sync_reserve_alternatives_label($booked['alternatives'] ?? [], date('Y-m-d', (int)strtotime($nb['reservation_date'])));
            $errs['reservation_time'] = $alts !== '' ? 'That time is fully booked on Zuri. Available: ' . $alts . '.' : 'That time is fully booked on Zuri.';
        }
        $_SESSION['res_new'] = ['errors' => $errs, 'old' => $nb];
        header('Location: /admin/reservations.php#new-booking'); exit;
    }

    $rid = (int)($_POST['reservation_id'] ?? 0);
    $res = fetch_reservation($rid);
    $act = (string)($_POST['do'] ?? '');
    if (reservation_editable($scope, $res) && isset($resActions[$act])) {
        [$to, $auditKey] = $resActions[$act];
        $reason = $act === 'cancel' ? trim((string)($_POST['reason'] ?? '')) : null;
        if (set_reservation_status($rid, $to, $reason)) {
            audit_log($auditKey, 'reservation', $rid, $res['reference'] ?? '');
            if ($to === 'confirmed') {
                try { send_reservation_confirmed(fetch_reservation($rid) ?? $res); }
                catch (Throwable $e) { error_log('[reservation] confirm mail: ' . $e->getMessage()); }
            }
        } else {
            $_SESSION['res_flash'] = ['type' => 'error', 'msg' => 'That change isn’t allowed from “' . reservation_status_label((string)$res['status']) . '”.'];
        }
    }
    $ret = (string)($_POST['ret'] ?? '');
    header('Location: ' . (str_starts_with($ret, '/admin/reservations.php') ? $ret : '/admin/reservations.php'));
    exit;
}

$resFlash = $_SESSION['res_flash'] ?? null; unset($_SESSION['res_flash']);
$resNew   = $_SESSION['res_new'] ?? null;   unset($_SESSION['res_new']);

// ── Filters ──────────────────────────────────────────────────────────
$fStatus = trim((string)($_GET['status'] ?? ''));
$fVenue  = (int)($_GET['venue'] ?? 0);
$fDate   = trim((string)($_GET['date'] ?? ''));
if (!in_array($fStatus, sync_reservation_states(), true)) $fStatus = '';
if ($fDate !== '' && !strtotime($fDate)) $fDate = '';

$pg = paginate_params();           // page / per / q / offset / ajax

// Venue options for the filter (scoped).
$venueOpts = [];
if (reservations_supported()) {
    $vWhere = '';
    if ($scope !== null) {
        $vWhere = $scope ? ('WHERE id IN (' . implode(',', array_map('intval', $scope)) . ')') : 'WHERE FALSE';
    }
    $venueOpts = db_query("SELECT id, name FROM venues $vWhere ORDER BY sort_order, name")->fetchAll();
}

// Compose the WHERE (scope + filters + free-text search) directly here so the
// count and the page slice stay in lockstep for pagination.
$params = [];
$conds  = [];
if (sync_supported()) $conds[] = 'r.is_deleted = FALSE';   // Zuri-deleted bookings stay hidden
if ($scope !== null) {
    if (!$scope) { $conds[] = 'FALSE'; }
    else { $conds[] = 'r.venue_id IN (' . implode(',', array_map('intval', $scope)) . ')'; }
}
if ($fVenue > 0)  { $conds[] = 'r.venue_id = :fvenue'; $params[':fvenue'] = $fVenue; }
if ($fStatus !== '') { $conds[] = 'r.status = :fstatus'; $params[':fstatus'] = $fStatus; }
if ($fDate !== '')   { $conds[] = 'r.reservation_date = :fdate'; $params[':fdate'] = date('Y-m-d', strtotime($fDate)); }
$sw = search_where(
    ['r.guest_name', "COALESCE(r.guest_phone,'')", "COALESCE(r.guest_email,'')", "COALESCE(r.reference,'')"],
    $pg['q'], $params
);
if ($sw !== '') $conds[] = $sw;
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$supported = reservations_supported();
$total = 0; $rows = []; $counts = ['today'=>0,'upcoming'=>0,'pending'=>0];
if ($supported) {
    $base  = "FROM reservations r LEFT JOIN venues v ON v.id = r.venue_id $where";
    $total = (int) db_query("SELECT COUNT(*) $base", $params)->fetchColumn();
    $meta  = paginate_meta($total, $pg['page'], $pg['per']);
    $rows  = db_query(
        "SELECT r.*, v.name AS venue_name, v.slug AS venue_slug $base
          ORDER BY r.reservation_date DESC, r.reservation_time DESC, r.id DESC
          LIMIT {$meta['per']} OFFSET {$meta['offset']}",
        $params
    )->fetchAll();
    $counts = reservation_dashboard_counts($scope);
} else {
    $meta = paginate_meta(0, 1, $pg['per']);
}

$ret = e($_SERVER['REQUEST_URI'] ?? '/admin/reservations.php');

// ── Swappable body (card + pager) ────────────────────────────────────
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$supported): ?>
      <?php dt_empty('Reservations aren’t set up yet. Run the add_reservations migration.', 'calendar'); ?>
    <?php elseif (!$rows): ?>
      <?php dt_empty($pg['q'] !== '' || $fStatus || $fVenue || $fDate ? 'No reservations match your filters.' : 'No reservations yet.', 'calendar'); ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Property</th>
            <th>Guest</th>
            <th>Party</th>
            <th>Reference</th>
            <th>Status</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $dts = strtotime($r['reservation_date']);
            $tts = strtotime($r['reservation_time']);
          ?>
          <tr>
            <td style="white-space:nowrap">
              <strong><?= e($dts ? date('D, j M Y', $dts) : $r['reservation_date']) ?></strong>
              <div class="text-muted" style="font-size:12px"><?= e($tts ? date('g:i A', $tts) : $r['reservation_time']) ?></div>
            </td>
            <td class="text-muted"><?= e($r['venue_name'] ?? '—') ?></td>
            <td>
              <?= e($r['guest_name']) ?>
              <div class="text-muted" style="font-size:12px">
                <?php if ($r['guest_phone']): ?><a href="tel:<?= e($r['guest_phone']) ?>" style="color:inherit"><?= e($r['guest_phone']) ?></a><?php endif; ?>
                <?php if ($r['guest_email']): ?> · <a href="mailto:<?= e($r['guest_email']) ?>" style="color:inherit"><?= e($r['guest_email']) ?></a><?php endif; ?>
              </div>
              <?php if (trim((string)$r['notes']) !== ''): ?>
              <div class="text-muted" style="font-size:12px;margin-top:2px;font-style:italic">“<?= e($r['notes']) ?>”</div>
              <?php endif; ?>
            </td>
            <td><?= (int)$r['party_size'] ?></td>
            <td class="text-muted" style="font-size:12px"><code><?= e($r['reference'] ?? '—') ?></code></td>
            <td>
              <span class="badge <?= reservation_status_badge($r['status']) ?>"><?= e(reservation_status_label($r['status'])) ?></span>
              <?php if (reservation_on_zuri($r)): ?><div class="text-muted" style="font-size:11px;margin-top:3px" data-tip="This booking is on Zuri — status changes sync">on Zuri</div><?php endif; ?>
              <?php if (!empty($r['staff_notes']) && str_starts_with((string)$r['staff_notes'], 'NOT ON ZURI')): ?><div style="font-size:11px;margin-top:3px;color:#b45309">not on Zuri yet</div><?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php
                // One button per move the state machine allows from here.
                $moves = [];
                foreach ($resActions as $k => [$to, $_a]) {
                    if ($to !== $r['status'] && sync_reservation_transition_allowed((string)$r['status'], $to)) $moves[] = $k;
                }
                $btn = [
                    'confirm'  => ['check',       'Confirm',  '#166534', ''],
                    'seat'     => ['users',       'Seat',     '#1E5C6B', ''],
                    'complete' => ['check-check', 'Complete', '#374151', ''],
                    'no_show'  => ['x',           'No-show',  '#7c3aed', 'Mark this booking as a no-show? This can’t be undone.'],
                    'cancel'   => ['ban',         'Cancel',   '#b91c1c', 'Cancel this reservation? The guest is not emailed automatically.'],
                ];
              ?>
              <?php if ($moves): ?>
              <span class="dt-actions" style="display:inline-flex;gap:6px;justify-content:flex-end">
                <?php foreach ($moves as $k): [$ico, $lbl, $col, $ask] = $btn[$k]; ?>
                <form method="POST" action="/admin/reservations.php" style="display:inline"<?= $ask !== '' ? ' onsubmit="return confirm(\'' . e($ask) . '\')"' : '' ?>>
                  <?= csrf_field() ?>
                  <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="ret" value="<?= $ret ?>">
                  <button type="submit" name="do" value="<?= $k ?>" class="btn-icon btn-icon--outline" data-tip="<?= $lbl ?>" aria-label="<?= $lbl ?>" style="color:<?= $col ?>"><?= admin_icon($ico) ?></button>
                </form>
                <?php endforeach; ?>
              </span>
              <?php else: ?>
              <span class="text-muted" style="font-size:12px">—</span>
              <?php endif; ?>
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

$pageTitle  = 'Reservations';
$activeMenu = 'reservations';
include __DIR__ . '/_layout.php';
?>

<style>
.rz-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.rz-kpi{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:14px 16px}
.rz-kpi .n{font-size:26px;font-weight:800;color:#102F3A;line-height:1}
.rz-kpi .l{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:4px}
.rz-kpi--today .n{color:#1E5C6B}.rz-kpi--pending .n{color:#b45309}
.rz-new{margin-bottom:16px}
.rz-new > summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.rz-new > summary::-webkit-details-marker{display:none}
.rz-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
@media(max-width:900px){.rz-grid{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.rz-grid{grid-template-columns:1fr}}
.rz-form .field > label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.rz-form .inp{width:100%}
.field-error{color:var(--red,#b42318);font-size:12px;margin-top:4px}
@media(max-width:640px){.rz-kpis{grid-template-columns:1fr 1fr}}
</style>

<div class="page-header">
  <h1>Reservations</h1>
  <span style="display:inline-flex;align-items:center;gap:12px">
    <span style="color:var(--muted);font-size:13px"><?= number_format($total) ?> reservation<?= $total !== 1 ? 's' : '' ?></span>
    <?php if ($supported && $venueOpts): ?>
    <a href="#new-booking" class="btn-primary btn-sm" onclick="var d=document.getElementById('new-booking');if(d){d.open=true;}"><?= admin_icon('plus', 15) ?> New booking</a>
    <?php endif; ?>
  </span>
</div>
<?php if ($resFlash): ?><div class="alert alert--<?= e($resFlash['type']) ?> is-flash"><?= e($resFlash['msg']) ?></div><?php endif; ?>

<?php if ($supported && $venueOpts):
  $nbErr = $resNew['errors'] ?? [];
  $nbOld = $resNew['old'] ?? [];
  $nbV   = (int)($nbOld['venue_id'] ?? 0) ?: (int)$venueOpts[0]['id'];
  $nbSlots = reservation_slots();
?>
<details class="card rz-new" id="new-booking"<?= $resNew ? ' open' : '' ?>>
  <summary class="card__head"><span class="card__title">New booking</span><span class="text-muted" style="font-size:12px">Zuri bookings are checked against Zuri's tables when sync is on</span></summary>
  <div class="card__body" style="padding:18px">
    <?php if (!empty($nbErr['general'])): ?><div class="alert alert--error"><?= e($nbErr['general']) ?></div><?php endif; ?>
    <form method="POST" action="/admin/reservations.php" class="rz-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="new_booking">
      <div class="rz-grid">
        <div class="field"><label>Property</label>
          <select name="venue_id">
            <?php foreach ($venueOpts as $vo): ?>
            <option value="<?= (int)$vo['id'] ?>"<?= $nbV === (int)$vo['id'] ? ' selected' : '' ?>><?= e($vo['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($nbErr['venue_id'])): ?><div class="field-error"><?= e($nbErr['venue_id']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Date</label>
          <button type="button" class="dp-btn" data-dp-target="nbDate" data-dp-placeholder="Select date" style="width:100%"><?= !empty($nbOld['reservation_date']) ? e(date('j M Y', strtotime((string)$nbOld['reservation_date']))) : 'Select date' ?></button>
          <input type="hidden" id="nbDate" name="reservation_date" value="<?= e((string)($nbOld['reservation_date'] ?? '')) ?>">
          <?php if (isset($nbErr['reservation_date'])): ?><div class="field-error"><?= e($nbErr['reservation_date']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Time</label>
          <select name="reservation_time">
            <option value="">Select time…</option>
            <?php foreach ($nbSlots as $val => $lbl): ?>
            <option value="<?= e($val) ?>"<?= ($nbOld['reservation_time'] ?? '') === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($nbErr['reservation_time'])): ?><div class="field-error"><?= e($nbErr['reservation_time']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Guests</label>
          <input name="party_size" class="inp" inputmode="numeric" pattern="[0-9]*" value="<?= (int)($nbOld['party_size'] ?? 2) ?: 2 ?>">
          <?php if (isset($nbErr['party_size'])): ?><div class="field-error"><?= e($nbErr['party_size']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Guest name</label>
          <input name="guest_name" class="inp" maxlength="120" value="<?= e((string)($nbOld['guest_name'] ?? '')) ?>">
          <?php if (isset($nbErr['guest_name'])): ?><div class="field-error"><?= e($nbErr['guest_name']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Phone</label>
          <input name="guest_phone" class="inp" maxlength="40" inputmode="tel" value="<?= e((string)($nbOld['guest_phone'] ?? '')) ?>">
          <?php if (isset($nbErr['guest_phone'])): ?><div class="field-error"><?= e($nbErr['guest_phone']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Email <span class="text-muted">(optional)</span></label>
          <input name="guest_email" class="inp" maxlength="190" inputmode="email" value="<?= e((string)($nbOld['guest_email'] ?? '')) ?>">
          <?php if (isset($nbErr['guest_email'])): ?><div class="field-error"><?= e($nbErr['guest_email']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Preference <span class="text-muted">(optional)</span></label>
          <select name="preference">
            <option value="">—</option>
            <?php foreach (['Lunch', 'Dinner', 'Private Dining'] as $pf): ?>
            <option value="<?= e($pf) ?>"<?= ($nbOld['preference'] ?? '') === $pf ? ' selected' : '' ?>><?= e($pf) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field" style="margin-top:12px"><label>Guest's requests <span class="text-muted">(optional)</span></label>
        <input name="notes" class="inp" maxlength="500" value="<?= e((string)($nbOld['notes'] ?? '')) ?>" placeholder="e.g. Birthday, window table">
      </div>
      <div style="margin-top:14px"><button type="submit" class="btn-primary btn-sm">Book table</button></div>
    </form>
  </div>
</details>
<?php endif; ?>

<div class="rz-kpis">
  <div class="rz-kpi rz-kpi--today"><div class="n"><?= (int)$counts['today'] ?></div><div class="l">Today</div></div>
  <div class="rz-kpi"><div class="n"><?= (int)$counts['upcoming'] ?></div><div class="l">Upcoming</div></div>
  <div class="rz-kpi rz-kpi--pending"><div class="n"><?= (int)$counts['pending'] ?></div><div class="l">Pending</div></div>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
    <form method="GET" action="/admin/reservations.php" class="filters" id="resFilter">
      <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
      <input type="hidden" name="per" value="<?= (int)$pg['per'] ?>">
      <div class="filter-field">
        <span>Status</span>
        <select name="status" class="filter-select" aria-label="Filter by status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (sync_reservation_states() as $val): ?>
          <option value="<?= $val ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= e(reservation_status_label($val)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (count($venueOpts) > 1): ?>
      <div class="filter-field">
        <span>Property</span>
        <select name="venue" class="filter-select" aria-label="Filter by property" onchange="this.form.submit()">
          <option value="0">All properties</option>
          <?php foreach ($venueOpts as $vo): ?>
          <option value="<?= (int)$vo['id'] ?>" <?= $fVenue === (int)$vo['id'] ? 'selected' : '' ?>><?= e($vo['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="filter-field">
        <span>Date</span>
        <button type="button" class="dp-btn" data-dp-target="resDate" data-dp-past data-dp-placeholder="Any date" style="width:150px"><?= $fDate !== '' ? e(date('j M Y', strtotime($fDate))) : 'Any date' ?></button>
        <input type="hidden" id="resDate" name="date" value="<?= e($fDate) ?>">
      </div>
      <?php if ($fStatus || $fVenue || $fDate): ?>
      <a href="/admin/reservations.php" class="btn-outline btn-sm" style="align-self:flex-end"><?= admin_icon('x', 14) ?> Clear</a>
      <?php endif; ?>
    </form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search guest, phone, email or reference…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<script>
// Submit the filter form when the styled datepicker sets a date.
(function(){
  var d = document.getElementById('resDate');
  var f = document.getElementById('resFilter');
  if (d && f) d.addEventListener('change', function(){ f.submit(); });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
