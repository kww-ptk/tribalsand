<?php
/**
 * Admin: restaurant setup — opening hours (ONE record per property) and the
 * floor-plan tables. Both are Tribalsand-owned and sync to Zuri (TS → Zuri) for
 * the synced property (SYNC_VENUE_SLUG); every other property keeps them local.
 *
 * Owner + manager, scoped by admin_venue_ids(): ?venue= is validated against the
 * account's own list, and every table write re-checks the row's venue, so a
 * posted foreign id is refused. All forms are PRG + CSRF.
 * Helpers: includes/restaurant-hours.php, includes/restaurant-tables.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/restaurant-hours.php';
require_once __DIR__ . '/../includes/restaurant-tables.php';
require_login();
require_manager();   // owner or house manager

$pageTitle  = 'Restaurant setup';
$activeMenu = 'restaurant_setup';

$scope  = admin_venue_ids();   // null = owner (all)
$venues = ($scope === null)
    ? db_query("SELECT id, name, slug FROM venues ORDER BY sort_order, name")->fetchAll()
    : ($scope ? db_query("SELECT id, name, slug FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ") ORDER BY sort_order, name")->fetchAll() : []);
$venueById = [];
foreach ($venues as $v) $venueById[(int)$v['id']] = $v;

// Default to the synced property (Zuri) when it is in scope, else the first one.
$venueId = (int)($_GET['venue'] ?? $_POST['venue_id'] ?? 0);
if (!isset($venueById[$venueId])) {
    $venueId = 0;
    foreach ($venues as $v) if ($v['slug'] === sync_venue_slug()) { $venueId = (int)$v['id']; break; }
    if (!$venueId && $venues) $venueId = (int)$venues[0]['id'];
}
$venue   = $venueById[$venueId] ?? null;
$selfUrl = '/admin/restaurant-setup.php' . ($venueId ? '?venue=' . $venueId : '');

$flash = $_SESSION['rsetup_flash'] ?? null;   unset($_SESSION['rsetup_flash']);
$old   = $_SESSION['rsetup_old'] ?? [];       unset($_SESSION['rsetup_old']);
$errs  = $_SESSION['rsetup_errors'] ?? [];    unset($_SESSION['rsetup_errors']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $venue) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'save_hours' && rhours_supported()) {
        $v = rhours_validate($_POST);
        if ($v['errors']) {
            $_SESSION['rsetup_errors'] = ['hours' => $v['errors']];
            $_SESSION['rsetup_old']    = ['hours' => $v['data']];
        } else {
            save_restaurant_hours($venueId, $v['data']);
            audit_log('restaurant.hours_save', 'venue', $venueId, (string)$venue['name']);
            $_SESSION['rsetup_flash'] = ['type' => 'success', 'msg' => 'Opening hours saved.'];
        }
        header('Location: ' . $selfUrl . '#hours'); exit;
    }

    if ($act === 'save_table' && rtables_supported()) {
        $tid = (int)($_POST['table_id'] ?? 0);
        $cur = $tid ? fetch_restaurant_table($tid) : null;
        if ($tid && (!$cur || (int)$cur['venue_id'] !== $venueId || $cur['is_deleted'])) {
            $_SESSION['rsetup_flash'] = ['type' => 'error', 'msg' => 'That table belongs to another property or no longer exists.'];
            header('Location: ' . $selfUrl . '#tables'); exit;
        }
        $v = rtable_validate($_POST);
        if (!isset($v['errors']['label']) && rtable_label_taken($venueId, $v['data']['label'], $tid)) {
            $v['errors']['label'] = 'Another table already uses that number.';
        }
        if ($v['errors']) {
            $_SESSION['rsetup_errors'] = ['table' => $v['errors']];
            $_SESSION['rsetup_old']    = ['table' => $v['data'] + ['id' => $tid]];
            header('Location: ' . $selfUrl . ($tid ? '&edit=' . $tid : '') . '#tform'); exit;
        }
        if ($tid) {
            update_restaurant_table($tid, $v['data']);
            audit_log('restaurant.table_update', 'restaurant_table', $tid, $v['data']['label']);
            $_SESSION['rsetup_flash'] = ['type' => 'success', 'msg' => 'Table ' . $v['data']['label'] . ' updated.'];
        } else {
            $row = create_restaurant_table($v['data'] + ['venue_id' => $venueId]);
            audit_log('restaurant.table_create', 'restaurant_table', (int)($row['id'] ?? 0), $v['data']['label']);
            $_SESSION['rsetup_flash'] = ['type' => 'success', 'msg' => 'Table ' . $v['data']['label'] . ' added.'];
        }
        header('Location: ' . $selfUrl . '#tables'); exit;
    }

    if ($act === 'delete_table' && rtables_supported()) {
        $tid = (int)($_POST['table_id'] ?? 0);
        $cur = fetch_restaurant_table($tid);
        if ($cur && (int)$cur['venue_id'] === $venueId && delete_restaurant_table($tid)) {
            audit_log('restaurant.table_delete', 'restaurant_table', $tid, (string)$cur['label']);
            $_SESSION['rsetup_flash'] = ['type' => 'success', 'msg' => 'Table ' . $cur['label'] . ' removed.'];
        } else {
            $_SESSION['rsetup_flash'] = ['type' => 'error', 'msg' => 'That table belongs to another property or no longer exists.'];
        }
        header('Location: ' . $selfUrl . '#tables'); exit;
    }
    header('Location: ' . $selfUrl); exit;
}

$synced = $venue && $venue['slug'] === sync_venue_slug();

// Hours form: re-show a failed post, else the stored row, else today's rules.
$hoursRow = $venue ? fetch_restaurant_hours($venueId) : null;
$hours    = $old['hours'] ?? ($hoursRow ? rhours_comparable($hoursRow) : rhours_defaults());
$hErr     = $errs['hours'] ?? [];

$tables = $venue ? fetch_restaurant_tables([$venueId]) : [];
$editId = (int)($_GET['edit'] ?? 0);
$tform  = ['id' => 0, 'label' => '', 'name' => '', 'seats' => 2, 'section' => '', 'sort_order' => count($tables), 'is_active' => true];
$hasName = rtables_name_supported();
if ($editId) {
    foreach ($tables as $t) if ((int)$t['id'] === $editId) { $tform = array_intersect_key($t, $tform) + $tform; break; }
}
if (isset($old['table'])) $tform = array_merge($tform, $old['table']);
$tErr   = $errs['table'] ?? [];
$tEdit  = (int)$tform['id'] > 0;
$seatsTotal = array_sum(array_map(fn($t) => $t['is_active'] ? (int)$t['seats'] : 0, $tables));

include __DIR__ . '/_layout.php';
?>
<link rel="stylesheet" href="/css/timepicker.css?v=<?= @filemtime(__DIR__ . '/../css/timepicker.css') ?: '1' ?>">

<?php
$__q = strpos($selfUrl, '?') !== false ? '&' : '?';
$hoursOpen = !empty($hErr) || isset($_GET['hours']);
$tableOpen = $tEdit || !empty($tErr) || isset($_GET['add']);
$__fmtHeld = function (int $m): string { return $m >= 60 && $m % 60 === 0 ? ($m / 60) . ' h' : $m . ' min'; };
?>
<div class="page-header">
  <h1>Restaurant setup <?php if ($venue && $synced): ?><span class="badge badge--blue" style="vertical-align:middle;font-size:10.5px" data-tip="Hours and tables are sent to Zuri's booking system automatically — edit them here, not on Zuri.">Syncs to Zuri</span><?php endif; ?></h1>
  <div class="actions">
    <?php if (count($venues) > 1): ?>
    <form method="GET" action="/admin/restaurant-setup.php" class="rs-venue">
      <select name="venue" onchange="this.form.submit()" aria-label="Property">
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $venueId ? ' selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn-outline btn-sm">Show</button></noscript>
    </form>
    <?php endif; ?>
    <?php if ($venue && rhours_supported()): ?>
    <button type="button" class="btn-outline btn-sm" id="rsHoursBtn" aria-controls="hours" aria-expanded="<?= $hoursOpen ? 'true' : 'false' ?>"><?= admin_icon('clock', 15) ?> Opening hours</button>
    <?php endif; ?>
    <?php if ($venue && rtables_supported()): ?>
    <button type="button" class="btn-primary btn-sm" id="rsAddBtn" aria-controls="tform" aria-expanded="<?= $tableOpen ? 'true' : 'false' ?>"><?= admin_icon('plus', 15) ?> Add table</button>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$venue): ?>
  <div class="alert alert--info">No properties are assigned to your account.</div>
<?php else: ?>

<?php if (rhours_supported()): ?>
<!-- What guests see now; the form opens from "Opening hours". -->
<div class="rs-hours" aria-label="Opening hours">
  <div><span>Lunch</span><b><?= e(trim((string)($hours['lunch'] ?? '')) ?: '—') ?></b></div>
  <div><span>Dinner</span><b><?= e(trim((string)($hours['dinner'] ?? '')) ?: '—') ?></b></div>
  <div><span>Booking slots</span><b><?= e((string)($hours['first_slot'] ?? '') ?: '—') ?> – <?= e((string)($hours['last_slot'] ?? '') ?: '—') ?> · every <?= (int)($hours['slot_minutes'] ?? 30) ?> min</b></div>
  <div><span>Table held for</span><b><?= e($__fmtHeld((int)($hours['duration_minutes'] ?? 90))) ?></b></div>
</div>

<div class="card rs-panel" id="hours" data-inline-form<?= $hoursOpen ? '' : ' hidden' ?>>
  <div class="card__head"><span class="card__title">Opening hours</span></div>
  <div class="card__body" style="padding:18px 20px">
    <form method="POST" action="<?= e($selfUrl) ?>" class="rs-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_hours">
      <input type="hidden" name="venue_id" value="<?= $venueId ?>">
      <div class="rs-row">
        <div class="field"><label for="rsLunch">Lunch <span class="text-muted">(shown to guests)</span></label>
          <input id="rsLunch" name="lunch" class="inp<?= isset($hErr['lunch']) ? ' is-invalid' : '' ?>" maxlength="120" value="<?= e((string)($hours['lunch'] ?? '')) ?>" placeholder="e.g. 12:00 – 15:00">
          <?php if (isset($hErr['lunch'])): ?><div class="field-error"><?= e($hErr['lunch']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label for="rsDinner">Dinner <span class="text-muted">(shown to guests)</span></label>
          <input id="rsDinner" name="dinner" class="inp<?= isset($hErr['dinner']) ? ' is-invalid' : '' ?>" maxlength="120" value="<?= e((string)($hours['dinner'] ?? '')) ?>" placeholder="e.g. 18:00 – 22:00">
          <?php if (isset($hErr['dinner'])): ?><div class="field-error"><?= e($hErr['dinner']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>First booking slot</label>
          <input name="first_slot" class="inp tp-input" value="<?= e((string)($hours['first_slot'] ?? '')) ?>" placeholder="12:00">
          <?php if (isset($hErr['first_slot'])): ?><div class="field-error"><?= e($hErr['first_slot']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Last booking slot</label>
          <input name="last_slot" class="inp tp-input" value="<?= e((string)($hours['last_slot'] ?? '')) ?>" placeholder="22:00">
          <?php if (isset($hErr['last_slot'])): ?><div class="field-error"><?= e($hErr['last_slot']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Slot every</label>
          <select name="slot_minutes">
            <?php foreach ([15, 30, 45, 60, 90, 120] as $m): ?>
            <option value="<?= $m ?>"<?= (int)($hours['slot_minutes'] ?? 30) === $m ? ' selected' : '' ?>><?= $m ?> min</option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($hErr['slot_minutes'])): ?><div class="field-error"><?= e($hErr['slot_minutes']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Table held for</label>
          <select name="duration_minutes">
            <?php foreach ([60, 75, 90, 105, 120, 150, 180, 240] as $m): ?>
            <option value="<?= $m ?>"<?= (int)($hours['duration_minutes'] ?? 90) === $m ? ' selected' : '' ?>><?= e($__fmtHeld($m)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($hErr['duration_minutes'])): ?><div class="field-error"><?= e($hErr['duration_minutes']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="rs-actions">
        <button type="submit" class="btn-primary btn-sm">Save hours</button>
        <?php if ($synced && $hoursRow && is_owner()): ?><span class="text-muted rs-uuid">Sync id <code><?= e((string)$hoursRow['sync_uuid']) ?></code></span><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php else: ?>
<p class="text-muted" style="font-size:13px">Run the <code>add_restaurant_hours.sql</code> migration (Settings → Your account → Migrations) to edit opening hours.</p>
<?php endif; ?>

<?php if (rtables_supported()): ?>
<div class="card rs-panel" id="tform" data-inline-form<?= $tEdit ? ' data-close-href="' . e($selfUrl) . '"' : '' ?><?= $tableOpen ? '' : ' hidden' ?>>
  <div class="card__head"><span class="card__title"><?= $tEdit ? 'Edit table ' . e((string)$tform['label']) : 'Add a table' ?></span></div>
  <div class="card__body" style="padding:18px 20px">
    <form method="POST" action="<?= e($selfUrl) ?>" class="rs-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_table">
      <input type="hidden" name="venue_id" value="<?= $venueId ?>">
      <input type="hidden" name="table_id" value="<?= (int)$tform['id'] ?>">
      <div class="rs-row">
        <div class="field"><label for="rsLabel">Table number <span class="text-muted">(as on Zuri)</span></label>
          <input id="rsLabel" name="label" class="inp<?= isset($tErr['label']) ? ' is-invalid' : '' ?>" maxlength="10" value="<?= e((string)$tform['label']) ?>" placeholder="e.g. 7" required>
          <?php if (isset($tErr['label'])): ?><div class="field-error"><?= e($tErr['label']) ?></div><?php endif; ?>
        </div>
        <?php if ($hasName): ?>
        <div class="field"><label for="rsName">Name <span class="text-muted">(optional)</span></label>
          <input id="rsName" name="name" class="inp<?= isset($tErr['name']) ? ' is-invalid' : '' ?>" maxlength="80" value="<?= e((string)($tform['name'] ?? '')) ?>" placeholder="e.g. Pool 1">
          <?php if (isset($tErr['name'])): ?><div class="field-error"><?= e($tErr['name']) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="field"><label for="rsSeats">Seats</label>
          <input id="rsSeats" name="seats" class="inp<?= isset($tErr['seats']) ? ' is-invalid' : '' ?>" inputmode="numeric" pattern="[0-9]*" value="<?= (int)$tform['seats'] ?>" required>
          <?php if (isset($tErr['seats'])): ?><div class="field-error"><?= e($tErr['seats']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label for="rsZone">Zone <span class="text-muted">(optional)</span></label>
          <input id="rsZone" name="section" class="inp<?= isset($tErr['section']) ? ' is-invalid' : '' ?>" maxlength="60" value="<?= e((string)$tform['section']) ?>" placeholder="e.g. Terrace">
          <?php if (isset($tErr['section'])): ?><div class="field-error"><?= e($tErr['section']) ?></div><?php endif; ?>
        </div>
        <div class="field"><label for="rsSort">Order</label>
          <input id="rsSort" name="sort_order" class="inp" inputmode="numeric" pattern="[0-9]*" value="<?= (int)$tform['sort_order'] ?>">
        </div>
      </div>
      <div class="rs-actions">
        <label class="optchip rs-chip"><input type="checkbox" name="is_active" value="1"<?= $tform['is_active'] ? ' checked' : '' ?>> In service</label>
        <button type="submit" class="btn-primary btn-sm"><?= $tEdit ? 'Save table' : 'Add table' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card" id="tables">
  <div class="card__head">
    <span class="card__title">Tables</span>
    <?php if ($tables): ?><span class="text-muted" style="font-size:12px"><?= count($tables) ?> table<?= count($tables) === 1 ? '' : 's' ?> · <?= $seatsTotal ?> seats in service</span><?php endif; ?>
  </div>
  <?php if (!rtables_supported()): ?>
    <div class="card__body" style="padding:18px"><p class="text-muted" style="margin:0;font-size:13px">Run the <code>add_restaurant_sync_models.sql</code> migration (Settings → Your account → Migrations) to manage tables.</p></div>
  <?php elseif (!$tables): ?>
    <div style="padding:40px 20px;text-align:center"><p class="text-muted" style="margin:0">No tables yet. Press <strong>Add table</strong> to add the first one.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data-table is-phone-fit" id="rsTables" data-paginate="25">
      <thead><tr><th style="width:90px">No.</th><?php if ($hasName): ?><th>Name</th><?php endif; ?><th style="width:110px">Seats</th><th>Zone</th><th style="width:150px">Status</th><th style="width:1%;text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tables as $t): ?>
        <tr<?= (int)$t['id'] === $editId ? ' class="is-selected"' : '' ?>>
          <td><strong><?= e($t['label']) ?></strong></td>
          <?php if ($hasName): ?><td><?= !empty($t['name']) ? e($t['name']) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
          <td><?= (int)$t['seats'] ?></td>
          <td class="text-muted"><?= $t['section'] ? e($t['section']) : '—' ?></td>
          <td><span class="badge <?= $t['is_active'] ? 'badge--green' : 'badge--grey' ?>"><?= $t['is_active'] ? 'In service' : 'Out of service' ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="<?= e($selfUrl . $__q) ?>edit=<?= (int)$t['id'] ?>#tform" class="btn-icon btn-icon--outline" data-tip="Edit" aria-label="Edit table <?= e($t['label']) ?>"><?= admin_icon('edit', 15) ?></a>
            <form method="POST" action="<?= e($selfUrl) ?>" style="display:inline-flex;margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_table">
              <input type="hidden" name="venue_id" value="<?= $venueId ?>">
              <input type="hidden" name="table_id" value="<?= (int)$t['id'] ?>">
              <button type="submit" class="btn-icon btn-icon--outline btn-icon--danger" data-tip="Remove" aria-label="Remove table <?= e($t['label']) ?>"
                      data-confirm-title="Remove table <?= e($t['label']) ?>?" data-confirm="It stops being offered for new bookings." data-confirm-label="Remove"><?= admin_icon('trash', 15) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.rs-hours{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px}
.rs-hours>div{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:12px 16px;min-width:0}
.rs-hours span{display:block;font-size:11.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.rs-hours b{display:block;font-size:15px;margin-top:3px}
@media (max-width:900px){.rs-hours{grid-template-columns:repeat(2,minmax(0,1fr))}}
.rs-panel{margin-bottom:16px}
.rs-form{display:flex;flex-direction:column;gap:14px}
.rs-form .field{margin:0}
.rs-form .field > label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.rs-form .inp{width:100%}
.rs-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start}
.rs-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.rs-chip{cursor:pointer}
.rs-uuid{font-size:11.5px}
.rs-venue{display:inline-flex}
.field-error{color:var(--red,#b42318);font-size:12px;margin-top:4px}
.inp.is-invalid{border-color:var(--red,#b42318)}
.data-table tr.is-selected td{background:var(--bg-soft,#faf6f2)}
@media (max-width:640px){#rsTables th:nth-child(4),#rsTables td:nth-child(4),#rsTables th:nth-child(5),#rsTables td:nth-child(5){display:none}}
</style>
<script>
  (function () {
    [['rsHoursBtn', 'hours', 'rsLunch'], ['rsAddBtn', 'tform', 'rsLabel']].forEach(function (p) {
      var b = document.getElementById(p[0]), c = document.getElementById(p[1]);
      if (!b || !c || b.dataset.ready) return; b.dataset.ready = '1';
      b.addEventListener('click', function () {
        var open = c.hidden;
        c.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { c.scrollIntoView({ block: 'nearest' }); var f = document.getElementById(p[2]); if (f) f.focus(); }
      });
    });
  })();
</script>
<script src="/js/timepicker.js?v=<?= @filemtime(__DIR__ . '/../js/timepicker.js') ?: '1' ?>"></script>

<?php include __DIR__ . '/_layout_end.php'; ?>
