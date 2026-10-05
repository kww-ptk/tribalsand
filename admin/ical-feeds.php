<?php
/**
 * Admin: OTA iCal feeds — Bookings › Calendar › iCal feeds.
 *
 * Moved off the Calendar page (it sat under the Gantt and made the page a long
 * scroll). Two lists, scoped to the account's properties like the Calendar:
 *   • export — each unit's outbound feed URL (api/ical.php) to paste into an OTA;
 *   • import — OTA feeds we pull into the calendar (api/sync-ical.php), which the
 *     in-container scheduler runs hourly; "Sync now" runs it on demand.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_bookings();

$scope   = admin_venue_ids();                 // null = owner (every venue)
$venueOk = venue_scope_sql('r.venue_id');     // '' for the owner
$unitIds = $venueOk === '' ? '' : "SELECT u2.id FROM units u2 JOIN rooms r ON r.id = u2.room_id WHERE {$venueOk}";

$unitInScope = function (int $unitId) use ($scope): bool {
    if ($scope === null) return true;
    if (!$scope || $unitId <= 0) return false;
    $v = db_query("SELECT r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.id = :id", [':id' => $unitId])->fetchColumn();
    return $v !== false && $v !== null && in_array((int)$v, $scope, true);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = null;
    if ($action === 'add_ical_feed') {
        $unitId  = (int)($_POST['feed_unit_id'] ?? 0);
        $label   = mb_substr(trim((string)($_POST['feed_label'] ?? '')), 0, 60);
        $feedUrl = trim((string)($_POST['feed_url'] ?? ''));
        if ($unitId && !$unitInScope($unitId)) {
            $flash = ['type' => 'error', 'msg' => 'That unit isn’t one of your properties.'];
        } elseif ($unitId && $feedUrl !== '' && filter_var($feedUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $feedUrl)) {
            db_query("INSERT INTO ical_feeds (unit_id, label, feed_url) VALUES (:uid, :label, :url)",
                [':uid' => $unitId, ':label' => $label, ':url' => $feedUrl]);
            audit_log('ical_feed.add', 'unit', $unitId, $label);
            $flash = ['type' => 'success', 'msg' => 'Feed added. It imports on the next sync.'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Pick a unit and paste the full iCal link (it starts with https://).'];
        }
    } elseif ($action === 'delete_ical_feed') {
        db_query('DELETE FROM ical_feeds WHERE id = :id' . ($unitIds !== '' ? " AND unit_id IN ({$unitIds})" : ''),
            [':id' => (int)($_POST['feed_id'] ?? 0)]);
        $flash = ['type' => 'success', 'msg' => 'Feed removed.'];
    }
    if ($flash) $_SESSION['ical_flash'] = $flash;
    header('Location: /admin/ical-feeds.php'); exit;
}

$flash = $_SESSION['ical_flash'] ?? null;
unset($_SESSION['ical_flash']);

$units = db_query(
    "SELECT u.id, u.name, u.feed_token, r.name AS room_name, v.name AS venue_name
       FROM units u
       JOIN rooms r ON r.id = u.room_id
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE u.is_active = TRUE" . ($venueOk !== '' ? " AND {$venueOk}" : '') . "
      ORDER BY (v.id IS NULL), v.sort_order, v.name, v.id, r.sort_order, r.id, u.sort_order, u.id"
)->fetchAll();
$unitsByVenue = [];
foreach ($units as $u) $unitsByVenue[trim((string)($u['venue_name'] ?? '')) ?: 'Unassigned'][] = $u;

$feeds = db_query(
    "SELECT f.*, u.name AS unit_name, r.name AS room_name, v.name AS venue_name
       FROM ical_feeds f
       JOIN units u ON u.id = f.unit_id
       JOIN rooms r ON r.id = u.room_id
       LEFT JOIN venues v ON v.id = r.venue_id" . ($venueOk !== '' ? " WHERE {$venueOk}" : '') . "
      ORDER BY v.sort_order, r.sort_order, f.id"
)->fetchAll();

$env        = parse_env();
$siteUrl    = rtrim($env['SITE_URL'] ?? 'https://tribalsand.com', '/');
$syncSecret = $env['ICAL_SYNC_SECRET'] ?? '';

$pageTitle  = 'iCal feeds';
$activeMenu = 'gantt';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>iCal feeds</h1>
  <div class="actions">
    <?php if ($syncSecret !== ''): ?>
    <button type="button" class="btn-primary btn-sm" id="icalSync"><?= admin_icon('rotate', 15) ?> Sync now</button>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<p class="text-muted" style="margin:-6px 0 16px;font-size:13px;max-width:780px">
  Keep Airbnb, Booking.com and other sites in step with the calendar. <strong>Import</strong> pulls their bookings in as blocked dates;
  <strong>Export</strong> gives each unit a link to paste into those sites so they see our bookings.
  <?= $syncSecret !== '' ? 'Imports run automatically every hour.' : 'Imports are off until <code>ICAL_SYNC_SECRET</code> is set on the server.' ?>
</p>

<div class="ical-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">Import from other sites <span class="text-muted" style="font-weight:400;font-size:12px">· <?= count($feeds) ?></span></span></div>
    <div class="card__body" style="padding:16px 18px">
      <?php if ($units): ?>
      <form method="POST" action="/admin/ical-feeds.php" class="ical-add" data-shell-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_ical_feed">
        <div class="field"><label for="icalUnit">Unit</label>
          <select id="icalUnit" name="feed_unit_id" required style="width:100%">
            <?php foreach ($unitsByVenue as $vname => $vunits): ?>
            <optgroup label="<?= e($vname) ?>">
              <?php foreach ($vunits as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['room_name'] . ' — ' . $u['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="icalLabel">Site</label>
          <input id="icalLabel" name="feed_label" class="inp" maxlength="60" placeholder="Enter the site, e.g. Airbnb" style="width:100%"></div>
        <div class="field ical-add__url"><label for="icalUrl">iCal link</label>
          <input id="icalUrl" type="url" name="feed_url" class="inp" required placeholder="Paste the link, e.g. https://www.airbnb.com/calendar/ical/…" style="width:100%"></div>
        <div class="ical-add__btn"><button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 14) ?> Add feed</button></div>
      </form>
      <?php endif; ?>

      <?php if ($feeds): ?>
      <ul class="ical-list">
        <?php foreach ($feeds as $f): $host = parse_url((string)$f['feed_url'], PHP_URL_HOST) ?: (string)$f['feed_url']; ?>
        <li>
          <span class="ical-list__main">
            <strong><?= e(trim((string)($f['label'] ?? '')) ?: $host) ?></strong>
            <span class="text-muted"><?= e(trim((string)($f['venue_name'] ?? '')) ? $f['venue_name'] . ' · ' : '') ?><?= e($f['room_name'] . ' — ' . $f['unit_name']) ?></span>
            <span class="text-muted ical-list__host" title="<?= e((string)$f['feed_url']) ?>"><?= e($host) ?></span>
          </span>
          <span class="ical-list__sync<?= $f['last_synced_at'] ? '' : ' is-never' ?>"><?= $f['last_synced_at'] ? 'Synced ' . e(date('j M, H:i', strtotime((string)$f['last_synced_at']))) : 'Not synced yet' ?></span>
          <form method="POST" action="/admin/ical-feeds.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_ical_feed">
            <input type="hidden" name="feed_id" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn-icon btn-icon--danger" data-tip="Remove feed" aria-label="Remove feed"
                    data-confirm="Stop importing this feed? Blocks it already imported stay on the calendar." data-confirm-title="Remove feed" data-confirm-label="Remove"><?= admin_icon('trash') ?></button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="text-muted" style="font-size:13px;margin:14px 0 0">No feeds yet. Add the iCal link from each site’s calendar settings.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Export to other sites <span class="text-muted" style="font-weight:400;font-size:12px">· <?= count($units) ?> units</span></span></div>
    <div class="card__body" style="padding:0">
      <?php if (!$units): ?>
      <p class="text-muted" style="font-size:13px;margin:0;padding:16px 18px">No units yet.</p>
      <?php else: ?>
      <ul class="ical-list ical-list--export">
        <?php foreach ($unitsByVenue as $vname => $vunits): ?>
        <li class="ical-list__venue"><?= e($vname) ?></li>
          <?php foreach ($vunits as $u): $link = $siteUrl . '/api/ical.php?unit=' . (int)$u['id'] . '&token=' . $u['feed_token']; ?>
          <li>
            <span class="ical-list__main"><strong><?= e($u['room_name']) ?></strong><span class="text-muted"><?= e($u['name']) ?></span></span>
            <button type="button" class="btn-outline btn-sm copy-link" data-link="<?= e($link) ?>"><?= admin_icon('copy', 14) ?> Copy link</button>
          </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.ical-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);gap:16px;align-items:start}
@media (max-width:1000px){.ical-grid{grid-template-columns:minmax(0,1fr)}}
.ical-add{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,.8fr);gap:10px 12px;align-items:end;padding-bottom:16px;border-bottom:1px solid var(--border)}
.ical-add .field{margin:0}
.ical-add .field > label{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}
.ical-add__url{grid-column:1 / 2}
.ical-add__btn{display:flex;justify-content:flex-end}
@media (max-width:560px){.ical-add{grid-template-columns:minmax(0,1fr)}.ical-add__btn{justify-content:flex-start}}
.ical-list{list-style:none;margin:0;padding:0}
.ical-list li{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--border)}
.ical-list li:first-child{border-top:0}
.ical-list--export li{padding:9px 18px}
.ical-list__venue{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:#fbf8f3}
.ical-list__main{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:1px;font-size:13px}
.ical-list__main > *{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ical-list__host{font-size:11.5px}
.ical-list__sync{font-size:12px;color:var(--green,#2e7d32);white-space:nowrap}
.ical-list__sync.is-never{color:var(--muted)}
</style>
<?php if ($syncSecret !== ''): ?>
<script>
(function () {
  var btn = document.getElementById('icalSync');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var label = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Syncing…';
    fetch('/api/sync-ical.php', { method: 'POST', headers: { 'Authorization': 'Bearer <?= e($syncSecret) ?>' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var total = (data.feeds || []).reduce(function (s, f) { return s + (f.imported || 0); }, 0);
        if (window.tsToast) window.tsToast('Sync finished — ' + total + ' new ' + (total === 1 ? 'block' : 'blocks') + ' imported.', 'ok');
        if (window.tsShellGo) window.tsShellGo('/admin/ical-feeds.php');
      })
      .catch(function () { if (window.tsToast) window.tsToast('Sync failed. Check ICAL_SYNC_SECRET on the server.', 'err'); })
      .then(function () { btn.disabled = false; btn.innerHTML = label; });
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
