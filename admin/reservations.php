<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';           // admin_icon() — needed in AJAX branch too
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();
require_manager();                 // owner or house manager

$scope = admin_venue_ids();        // null = owner (all); array = manager's venues

// ── POST: confirm / cancel (scoped, per-row ownership re-check) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rid = (int)($_POST['reservation_id'] ?? 0);
    $res = fetch_reservation($rid);

    if (reservation_editable($scope, $res)) {
        if (isset($_POST['confirm'])) {
            if (set_reservation_status($rid, 'confirmed')) {
                audit_log('reservation.confirm', 'reservation', $rid, $res['reference'] ?? '');
                try { send_reservation_confirmed(fetch_reservation($rid) ?? $res); }
                catch (Throwable $e) { error_log('[reservation] confirm mail: ' . $e->getMessage()); }
            }
        } elseif (isset($_POST['cancel'])) {
            if (set_reservation_status($rid, 'cancelled')) {
                audit_log('reservation.cancel', 'reservation', $rid, $res['reference'] ?? '');
            }
        }
    }
    header('Location: ' . ($_POST['ret'] ?? '/admin/reservations.php'));
    exit;
}

// ── Filters ──────────────────────────────────────────────────────────
$fStatus = trim((string)($_GET['status'] ?? ''));
$fVenue  = (int)($_GET['venue'] ?? 0);
$fDate   = trim((string)($_GET['date'] ?? ''));
if (!in_array($fStatus, ['pending','confirmed','cancelled'], true)) $fStatus = '';
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
        "SELECT r.*, v.name AS venue_name $base
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
            <td><span class="badge <?= reservation_status_badge($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td style="text-align:right">
              <?php if ($r['status'] !== 'cancelled'): ?>
              <span class="dt-actions" style="display:inline-flex;gap:6px;justify-content:flex-end">
                <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" action="/admin/reservations.php" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="ret" value="<?= $ret ?>">
                  <button type="submit" name="confirm" value="1" class="btn-icon btn-icon--outline" title="Confirm" aria-label="Confirm" style="color:#166534"><?= admin_icon('check') ?></button>
                </form>
                <?php endif; ?>
                <form method="POST" action="/admin/reservations.php" style="display:inline" onsubmit="return confirm('Cancel this reservation? The guest is not emailed automatically.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="ret" value="<?= $ret ?>">
                  <button type="submit" name="cancel" value="1" class="btn-icon btn-icon--outline" title="Cancel" aria-label="Cancel" style="color:#b91c1c"><?= admin_icon('ban') ?></button>
                </form>
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
@media(max-width:640px){.rz-kpis{grid-template-columns:1fr 1fr}}
</style>

<div class="page-header">
  <h1>Reservations</h1>
  <span style="color:var(--muted);font-size:13px"><?= number_format($total) ?> reservation<?= $total !== 1 ? 's' : '' ?></span>
</div>

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
          <?php foreach (['pending'=>'Pending','confirmed'=>'Confirmed','cancelled'=>'Cancelled'] as $val=>$lbl): ?>
          <option value="<?= $val ?>" <?= $fStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
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
