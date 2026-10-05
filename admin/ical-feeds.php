<?php
/**
 * Admin: OTA iCal feeds — Bookings › Calendar › iCal feeds.
 *
 * Two-way calendar sync with Airbnb, Booking.com, VRBO, Expedia… (logic in
 * includes/ical-sync.php). Scoped to the account's properties like the Calendar:
 *   • export — each unit's outbound feed URL (api/ical.php) to paste into an OTA;
 *   • import — OTA feeds we pull into the calendar, which the in-container
 *     scheduler runs every 15 minutes; "Sync now" runs it on demand under the
 *     staff member's own session (POST action=sync_now, JSON) — the sync secret
 *     never reaches a page.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ical-sync.php';
require_login();
require_bookings();

$scope   = admin_venue_ids();                 // null = owner (every venue)
$venueOk = venue_scope_sql('r.venue_id');     // '' for the owner
$unitIds = $venueOk === '' ? '' : "SELECT u2.id FROM units u2 JOIN rooms r ON r.id = u2.room_id WHERE {$venueOk}";
$track   = ical_tracking_supported();

$unitInScope = function (int $unitId) use ($scope): bool {
    if ($scope === null) return true;
    if (!$scope || $unitId <= 0) return false;
    $v = db_query("SELECT r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE u.id = :id", [':id' => $unitId])->fetchColumn();
    return $v !== false && $v !== null && in_array((int)$v, $scope, true);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_now') {
        header('Content-Type: application/json');
        $results = ical_sync_all($scope);
        $sum = static fn (string $k) => array_sum(array_map(static fn ($r) => (int) ($r[$k] ?? 0), $results));
        echo json_encode([
            'ok'       => true,
            'feeds'    => $results,
            'imported' => $sum('imported'),
            'removed'  => $sum('removed'),
            'moved'    => $sum('moved'),
            'errors'   => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'error')),
        ]);
        exit;
    }

    $flash = null;
    if ($action === 'add_ical_feed') {
        $unitId  = (int)($_POST['feed_unit_id'] ?? 0);
        $feedUrl = trim((string)($_POST['feed_url'] ?? ''));
        $label   = mb_substr(trim((string)($_POST['feed_label'] ?? '')), 0, 60);
        if ($label === '') $label = ical_label_from_url($feedUrl);
        if ($unitId && !$unitInScope($unitId)) {
            $flash = ['type' => 'error', 'msg' => 'That unit isn’t one of your properties.'];
        } elseif (!$unitId || !ical_feed_url_ok($feedUrl)) {
            $flash = ['type' => 'error', 'msg' => 'Pick a unit and paste the full iCal link (it starts with https://).'];
        } elseif (db_query("SELECT 1 FROM ical_feeds WHERE unit_id = :u AND feed_url = :url",
                    [':u' => $unitId, ':url' => $feedUrl])->fetchColumn()) {
            $flash = ['type' => 'error', 'msg' => 'That link is already imported for this unit.'];
        } else {
            $newId = (int) db_query("INSERT INTO ical_feeds (unit_id, label, feed_url) VALUES (:uid, :label, :url) RETURNING id",
                [':uid' => $unitId, ':label' => $label, ':url' => $feedUrl])->fetchColumn();
            audit_log('ical_feed.add', 'unit', $unitId, $label);
            // Check the link straight away so a wrong one shows up now, not in an hour.
            $feed = db_query("SELECT * FROM ical_feeds WHERE id = :id", [':id' => $newId])->fetch();
            $r    = $feed ? ical_sync_feed($feed) : ['status' => 'error', 'message' => ''];
            $flash = ($r['status'] ?? '') === 'ok'
                ? ['type' => 'success', 'msg' => 'Feed added and checked — ' . (int) ($r['total'] ?? 0) . ' upcoming '
                    . ((int) ($r['total'] ?? 0) === 1 ? 'booking' : 'bookings') . ' found, ' . (int) ($r['imported'] ?? 0) . ' added to the calendar.']
                : ['type' => 'error', 'msg' => 'Feed added, but it could not be read: ' . ($r['message'] ?? 'unknown error') . ' Check the link.'];
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
$feedLabelsByUnit = [];
foreach ($feeds as $f) {
    $feedLabelsByUnit[(int) $f['unit_id']][] = trim((string) ($f['label'] ?? '')) ?: (parse_url((string) $f['feed_url'], PHP_URL_HOST) ?: 'Feed');
}
$feedErrors = $track ? count(array_filter($feeds, static fn ($f) => ($f['last_status'] ?? '') === 'error')) : 0;

$syncOn = trim((string) (parse_env()['ICAL_SYNC_SECRET'] ?? '')) !== '';

$pageTitle  = 'iCal feeds';
$activeMenu = 'gantt';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>iCal feeds</h1>
  <div class="actions">
    <?php if ($feeds): ?>
    <button type="button" class="btn-primary btn-sm" id="icalSync" data-csrf="<?= e(csrf_token()) ?>"><?= admin_icon('rotate', 15) ?> Sync now</button>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$track): ?>
<div class="alert alert--info">Run <code>add_ical_sync_tracking.sql</code> (Admin → Migrations) so bookings cancelled or moved on Airbnb / Booking.com are cleared here too. Until then the import only adds dates.</div>
<?php endif; ?>
<?php if ($feedErrors): ?>
<div class="alert alert--error"><?= $feedErrors ?> <?= $feedErrors === 1 ? 'feed' : 'feeds' ?> could not be read on the last sync — see the red lines below. Those channels’ new bookings are not reaching the calendar.</div>
<?php endif; ?>

<p class="text-muted" style="margin:-6px 0 16px;font-size:13px;max-width:820px">
  Keeps Airbnb, Booking.com, VRBO and Expedia in step with the calendar. <strong>Import</strong> pulls their bookings in as blocked dates
  (and clears them again when a booking is cancelled there); <strong>Export</strong> gives each unit a link to paste into those sites so they
  close the dates we have sold. Dates only — prices and guest details are not shared.
  <?= $syncOn ? 'Imports run automatically every 15 minutes; the sites read our export every few hours.' : 'Automatic imports are off until <code>ICAL_SYNC_SECRET</code> is set on the server — use Sync now meanwhile.' ?>
</p>

<details class="card ical-help">
  <summary class="card__head"><span class="card__title"><?= admin_icon('info', 15) ?> How to connect a listing</span></summary>
  <div class="card__body ical-help__body">
    <div>
      <h3>Airbnb</h3>
      <ol>
        <li>Airbnb → <em>Listings</em> → the listing → <em>Availability</em> → <em>Connect calendars</em> (or <em>Sync calendars</em>).</li>
        <li><strong>Import here:</strong> copy Airbnb’s <em>Export calendar</em> link, then add it on the left with the matching unit.</li>
        <li><strong>Export to Airbnb:</strong> <em>Import calendar</em> → paste the unit’s link from the right → name it “Tribal Sand”.</li>
      </ol>
    </div>
    <div>
      <h3>Booking.com</h3>
      <ol>
        <li>Extranet → <em>Rates &amp; Availability</em> → <em>Sync calendars</em> → pick the room.</li>
        <li><strong>Import here:</strong> <em>Export calendar</em> → copy the link → add it on the left.</li>
        <li><strong>Export to Booking.com:</strong> <em>Import calendar</em> → paste the unit’s link → name it “Tribal Sand”.</li>
        <li>Only rooms that are ONE unit each can sync this way. A room type that sells several identical units needs a channel manager.</li>
      </ol>
    </div>
    <div>
      <h3>Good to know</h3>
      <ul>
        <li>One link per unit per site — each listing gets its own pair.</li>
        <li>A whole-property listing and its rooms close each other automatically.</li>
        <li>There is a delay of up to a few hours (the sites read our link on their own schedule). A same-day clash shows on <a href="/admin/conflicts.php">Conflicts</a>.</li>
        <li>Prices are still set on each site separately.</li>
      </ul>
    </div>
  </div>
</details>

<div class="ical-grid">
  <div class="card">
    <div class="card__head"><span class="card__title">Import from other sites <span class="text-muted" style="font-weight:400;font-size:12px">· <?= count($feeds) ?></span></span></div>
    <div class="card__body" style="padding:16px 18px">
      <?php if ($units): ?>
      <form method="POST" action="/admin/ical-feeds.php" class="ical-add" data-shell-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_ical_feed">
        <div class="field"><label for="icalUnit">Unit</label>
          <select id="icalUnit" name="feed_unit_id" class="eselect" required style="width:100%">
            <?php foreach ($unitsByVenue as $vname => $vunits): ?>
            <optgroup label="<?= e($vname) ?>">
              <?php foreach ($vunits as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['room_name'] . ' — ' . $u['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="icalLabel">Site</label>
          <input id="icalLabel" name="feed_label" class="inp" maxlength="60" placeholder="Filled in from the link" style="width:100%"></div>
        <div class="field ical-add__url"><label for="icalUrl">iCal link</label>
          <input id="icalUrl" type="url" name="feed_url" class="inp" required placeholder="Paste the link, e.g. https://www.airbnb.com/calendar/ical/…" style="width:100%"></div>
        <div class="ical-add__btn"><button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 14) ?> Add feed</button></div>
      </form>
      <?php endif; ?>

      <?php if ($feeds): ?>
      <ul class="ical-list">
        <?php foreach ($feeds as $f):
            $host  = parse_url((string)$f['feed_url'], PHP_URL_HOST) ?: (string)$f['feed_url'];
            $isErr = $track && ($f['last_status'] ?? '') === 'error';
            if ($isErr) {
                $syncTxt = (string) ($f['last_error'] ?? 'Could not be read');
            } elseif ($f['last_synced_at']) {
                $syncTxt = 'Synced ' . date('j M, H:i', strtotime((string)$f['last_synced_at']))
                    . ($track && $f['last_event_count'] !== null ? ' · ' . (int) $f['last_event_count'] . ' upcoming' : '');
            } else {
                $syncTxt = 'Not synced yet';
            } ?>
        <li>
          <span class="ical-list__main">
            <strong><?= e(trim((string)($f['label'] ?? '')) ?: $host) ?></strong>
            <span class="text-muted"><?= e(trim((string)($f['venue_name'] ?? '')) ? $f['venue_name'] . ' · ' : '') ?><?= e($f['room_name'] . ' — ' . $f['unit_name']) ?></span>
            <span class="text-muted ical-list__host" title="<?= e((string)$f['feed_url']) ?>"><?= e($host) ?></span>
          </span>
          <span class="ical-list__sync<?= $isErr ? ' is-error' : ($f['last_synced_at'] ? '' : ' is-never') ?>"<?= $isErr && $f['last_ok_at'] ? ' title="Last read OK ' . e(date('j M, H:i', strtotime((string) $f['last_ok_at']))) . '"' : '' ?>><?= e($syncTxt) ?></span>
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
      <p class="text-muted" style="font-size:13px;margin:14px 0 0">No feeds yet. Add the iCal link from each site’s calendar settings — see <em>How to connect a listing</em> above.</p>
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
          <?php foreach ($vunits as $u):
              $link  = site_url('api/ical?unit=' . (int)$u['id'] . '&token=' . $u['feed_token']);
              $chans = array_unique($feedLabelsByUnit[(int) $u['id']] ?? []); ?>
          <li>
            <span class="ical-list__main"><strong><?= e($u['room_name']) ?></strong>
              <span class="text-muted"><?= e($u['name']) ?><?= $chans ? ' · importing ' . e(implode(', ', $chans)) : ' · no site connected' ?></span></span>
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
.ical-help{margin-bottom:16px}
.ical-help > summary{cursor:pointer;list-style:none}
.ical-help > summary::-webkit-details-marker{display:none}
.ical-help__body{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding:4px 18px 16px;font-size:13px}
.ical-help__body h3{font-size:13px;margin:8px 0 6px}
.ical-help__body ol,.ical-help__body ul{margin:0;padding-left:18px;display:grid;gap:4px}
@media (max-width:1000px){.ical-help__body{grid-template-columns:minmax(0,1fr)}}
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
.ical-list__sync{font-size:12px;color:var(--green,#2e7d32);max-width:220px;text-align:right}
.ical-list__sync.is-never{color:var(--muted)}
.ical-list__sync.is-error{color:var(--red,#c62828)}
</style>
<script>
(function () {
  var btn = document.getElementById('icalSync');
  if (btn && !btn.dataset.bound) { btn.dataset.bound = '1'; btn.addEventListener('click', function () {
    var label = btn.innerHTML, fd = new FormData();
    fd.append('action', 'sync_now');
    fd.append('csrf_token', btn.dataset.csrf);
    btn.disabled = true; btn.textContent = 'Syncing…';
    fetch('/admin/ical-feeds.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        var parts = [d.imported + ' added'];
        if (d.removed) parts.push(d.removed + ' cleared (cancelled)');
        if (d.moved)   parts.push(d.moved + ' moved');
        var say = window.tsToast || function () {};
        say('Sync finished — ' + parts.join(', ') + (d.errors ? '. ' + d.errors + ' feed(s) could not be read.' : '.'), d.errors ? 'err' : 'ok');
        if (window.tsShellGo) window.tsShellGo('/admin/ical-feeds.php'); else location.reload();
      })
      .catch(function () { if (window.tsToast) window.tsToast('Sync failed — please try again.', 'err'); })
      .then(function () { btn.disabled = false; btn.innerHTML = label; });
  }); }
  var url = document.getElementById('icalUrl'), lab = document.getElementById('icalLabel');
  if (url && lab) url.addEventListener('change', function () {
    if (lab.value.trim()) return;
    var h = ''; try { h = new URL(url.value).hostname.toLowerCase(); } catch (e) { return; }
    var map = [['airbnb.', 'Airbnb'], ['booking.com', 'Booking.com'], ['vrbo.', 'VRBO'], ['homeaway.', 'VRBO'], ['expedia.', 'Expedia'], ['hopper.', 'Hopper']];
    for (var i = 0; i < map.length; i++) if (h.indexOf(map[i][0]) !== -1) { lab.value = map[i][1]; break; }
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
