<?php
/**
 * Admin: staff room allocation (Team → Staff rooms).
 *
 * Owner, manager or HR. Rooms per property with their beds and who is in them;
 * put someone in (or move them — their old room is closed the same day), move
 * someone out, add / edit / remove rooms. A manager sees only their own
 * properties' rooms and the people they can see. History stays (a used room is
 * switched off, never deleted). Logic: includes/staff-rooms.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-rooms.php';
require_once __DIR__ . '/../includes/hr-establishment.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_hr();   // owner, manager or HR

$pageTitle  = 'Staff rooms';
$activeMenu = 'staff-rooms';
$self       = '/admin/staff-rooms.php';
$venueIds   = admin_venue_ids();
$companyIds = hr_admin_company_ids();
$ready      = hr_staff_supported() && staff_rooms_supported();

$venues = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
if ($venueIds !== null) $venues = array_values(array_filter($venues, fn($v) => in_array((int)$v['id'], $venueIds, true)));
$venueNames = [];
foreach ($venues as $v) $venueNames[(int)$v['id']] = $v['name'];

function sr_back(string $msg, string $type = 'success'): never {
    $_SESSION['sr_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: /admin/staff-rooms.php'); exit;
}
/** A room the acting account may manage, or a refusal. */
function sr_room(int $id, ?array $venueIds): array {
    $r = db_query('SELECT * FROM staff_rooms WHERE id = :id', [':id' => $id])->fetch();
    if (!$r || !staff_room_in_scope($r['venue_id'] !== null ? (int)$r['venue_id'] : null, $venueIds)) throw new StaffRoomRefusal('That room is not one of yours.');
    return $r;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$ready) sr_back('Run the add_staff_rooms migration first.', 'error');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'room_save') {
            $id  = (int)($_POST['room_id'] ?? 0) ?: null;
            if ($id) sr_room($id, $venueIds);
            $vid = (int)($_POST['venue_id'] ?? 0) ?: null;
            if ($vid !== null && !isset($venueNames[$vid])) throw new StaffRoomRefusal('Pick one of your properties.');
            if ($vid === null && $venueIds !== null) throw new StaffRoomRefusal('Pick the property the room is at.');
            $rid = staff_room_save($id, $vid, (string)($_POST['label'] ?? ''), (int)($_POST['beds'] ?? 1), (string)($_POST['notes'] ?? ''));
            audit_log($id ? 'staff_room.update' : 'staff_room.create', 'staff_room', $rid, (string)($_POST['label'] ?? ''));
            sr_back($id ? 'Room saved.' : 'Room added.');
        }
        if ($action === 'room_remove') {
            $r = sr_room((int)($_POST['room_id'] ?? 0), $venueIds);
            $how = staff_room_remove((int)$r['id']);
            audit_log('staff_room.' . $how, 'staff_room', (int)$r['id'], (string)$r['label']);
            sr_back($how === 'deleted' ? 'Room removed.' : 'Room switched off — its history stays.');
        }
        if ($action === 'assign') {
            $r   = sr_room((int)($_POST['room_id'] ?? 0), $venueIds);
            $sid = (int)($_POST['hr_staff_id'] ?? 0);
            $p   = fetch_hr_staff_row($sid);
            if (!$p || !hr_staff_in_venue_scope($sid, $p['venue_id'] !== null ? (int)$p['venue_id'] : null, $venueIds, $companyIds)) throw new StaffRoomRefusal('Pick one of your team.');
            $bed = (int)($_POST['bed'] ?? 0) ?: null;
            staff_room_assign((int)$r['id'], $sid, $bed, (string)($_POST['from'] ?? ''), (string)($_POST['note'] ?? ''), (int)($_SESSION['admin_id'] ?? 0));
            audit_log('staff_room.assign', 'hr_staff', $sid, (string)$r['label']);
            sr_back($p['full_name'] . ' is now in ' . $r['label'] . '.');
        }
        if ($action === 'vacate') {
            $aid = (int)($_POST['allocation_id'] ?? 0);
            $a = db_query('SELECT a.*, s.full_name FROM staff_room_allocations a JOIN hr_staff s ON s.id = a.hr_staff_id WHERE a.id = :id', [':id' => $aid])->fetch();
            if (!$a) throw new StaffRoomRefusal('They are no longer in that room.');
            sr_room((int)$a['room_id'], $venueIds);
            staff_room_vacate($aid, (string)($_POST['on'] ?? date('Y-m-d')));
            audit_log('staff_room.vacate', 'hr_staff', (int)$a['hr_staff_id'], '');
            sr_back($a['full_name'] . ' moved out.');
        }
        sr_back('Unknown action.', 'error');
    } catch (StaffRoomRefusal $e) {
        sr_back($e->getMessage(), 'error');
    }
}

// ── GET ──────────────────────────────────────────────────────────────────────
$flash = $_SESSION['sr_flash'] ?? null; unset($_SESSION['sr_flash']);
$rooms = $ready ? staff_rooms_list($venueIds) : [];
$people = $ready ? fetch_hr_staff($venueIds, ['status' => 'active'], $companyIds) : [];
$housed = [];
foreach ($rooms as $r) foreach ($r['people'] as $p) $housed[(int)$p['hr_staff_id']] = true;
$groups = [];
foreach ($rooms as $r) $groups[$r['venue_id'] !== null ? ($venueNames[(int)$r['venue_id']] ?? 'Property') : 'No property'][] = $r;
$bedsTotal = array_sum(array_map(fn($r) => (int)$r['beds'], $rooms));
$bedsUsed  = array_sum(array_map(fn($r) => count($r['people']), $rooms));

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Staff rooms</h1>
  <div class="actions">
    <?php if ($ready): ?><button type="button" class="btn-primary btn-sm" id="addRoomBtn" aria-controls="roomCard" aria-expanded="false" data-help="staff-rooms-add"><?= admin_icon('plus', 15) ?> Add a room</button><?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">Run <code>add_staff_rooms.sql</code> (Settings → Your account → Migrations) to keep track of staff rooms.</p></div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif; ?>

<div class="card" id="roomCard" data-inline-form hidden style="margin-bottom:16px">
  <div class="card__head"><span class="card__title" id="roomCardTitle">Add a room</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="<?= $self ?>" class="sr-form" id="roomForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="room_save"><input type="hidden" name="room_id" value="">
      <div class="field"><label>Property</label>
        <select name="venue_id" class="filter-select eselect--block">
          <?php if ($venueIds === null): ?><option value="0">No property (e.g. the office house)</option><?php endif; ?>
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Room</label><input name="label" class="inp" required maxlength="120" placeholder="e.g. Staff block A · Room 3" style="width:100%"></div>
      <div class="field"><label>Beds</label><input name="beds" type="number" class="inp inp--num no-spin" min="1" max="20" value="2" required style="width:100%"></div>
      <div class="field" style="grid-column:1/-1"><label>Notes <span class="text-muted">(optional)</span></label><input name="notes" class="inp" maxlength="300" placeholder="e.g. women only, has its own bathroom" style="width:100%"></div>
      <div><button class="btn-primary btn-sm">Save room</button></div>
    </form>
  </div>
</div>

<div class="sr-chips">
  <span class="badge badge--grey"><?= count($rooms) ?> rooms</span>
  <span class="badge badge--grey"><?= $bedsTotal ?> beds</span>
  <span class="badge badge--green"><?= $bedsUsed ?> in use</span>
  <span class="badge badge--blue"><?= max(0, $bedsTotal - $bedsUsed) ?> free</span>
  <span class="text-muted" style="font-size:12.5px"><?= max(0, count($people) - count($housed)) ?> of your <?= count($people) ?> active staff have no room — not everyone lives on site.</span>
</div>

<?php if (!$rooms): ?>
<div class="card"><div class="card__body card__body--pad"><p class="text-muted" style="margin:0">No staff rooms yet. Add the rooms of each property's staff accommodation, then put people in them.</p></div></div>
<?php else: foreach ($groups as $gName => $list): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title"><?= e($gName) ?></span><span class="text-muted" style="font-size:12px"><?= array_sum(array_map(fn($r) => count($r['people']), $list)) ?> / <?= array_sum(array_map(fn($r) => (int)$r['beds'], $list)) ?> beds in use</span></div>
  <div class="table-wrap"><table class="data-table sr-table">
    <thead><tr><th>Room</th><th class="n">Beds</th><th>Who is there</th><th>Put someone in</th><th style="text-align:right"></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): $full = count($r['people']) >= (int)$r['beds']; ?>
      <tr>
        <td><strong><?= e($r['label']) ?></strong><?php if ($r['notes']): ?><div class="text-muted" style="font-size:12px"><?= e($r['notes']) ?></div><?php endif; ?></td>
        <td class="n"><span class="badge badge--<?= $full ? 'orange' : 'green' ?>"><?= count($r['people']) ?> / <?= (int)$r['beds'] ?></span></td>
        <td class="sr-people">
          <?php if (!$r['people']): ?><span class="text-muted">Empty</span><?php endif; ?>
          <?php foreach ($r['people'] as $p): ?>
          <div class="sr-person">
            <a href="/admin/employee.php?id=<?= (int)$p['hr_staff_id'] ?>"><?= e($p['full_name']) ?></a>
            <span class="text-muted"><?= $p['bed'] ? 'bed ' . (int)$p['bed'] . ' · ' : '' ?>since <?= e(date('j M Y', strtotime((string)$p['date_from']))) ?></span>
            <form method="POST" action="<?= $self ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="vacate"><input type="hidden" name="allocation_id" value="<?= (int)$p['allocation_id'] ?>"><input type="hidden" name="on" value="<?= e(date('Y-m-d')) ?>">
              <button class="btn-icon btn-icon--outline" data-confirm="<?= e($p['full_name']) ?> moved out of <?= e($r['label']) ?> today?" data-tip="Moved out" aria-label="<?= e($p['full_name']) ?> moved out"><?= admin_icon('x', 14) ?></button></form>
          </div>
          <?php endforeach; ?>
        </td>
        <td>
          <?php if ($full): ?><span class="text-muted" style="font-size:12.5px">Full</span>
          <?php else: ?>
          <form method="POST" action="<?= $self ?>" class="sr-assign"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
            <select name="hr_staff_id" class="filter-select" required aria-label="Who moves into <?= e($r['label']) ?>">
              <option value="">Choose a person…</option>
              <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['full_name']) ?><?= isset($housed[(int)$p['id']]) ? ' (moves from their room)' : '' ?></option><?php endforeach; ?>
            </select>
            <select name="bed" class="filter-select" aria-label="Bed">
              <option value="0">Any bed</option>
              <?php $taken = array_map(fn($p) => (int)$p['bed'], $r['people']); for ($b = 1; $b <= (int)$r['beds']; $b++): if (in_array($b, $taken, true)) continue; ?><option value="<?= $b ?>">Bed <?= $b ?></option><?php endfor; ?>
            </select>
            <input type="hidden" name="from" value="<?= e(date('Y-m-d')) ?>">
            <button class="btn-outline btn-sm"><?= admin_icon('plus', 14) ?> Move in today</button>
          </form>
          <?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <button type="button" class="btn-icon btn-icon--outline sr-edit" data-tip="Edit room" aria-label="Edit <?= e($r['label']) ?>"
                  data-id="<?= (int)$r['id'] ?>" data-venue="<?= (int)($r['venue_id'] ?? 0) ?>" data-label="<?= e($r['label']) ?>" data-beds="<?= (int)$r['beds'] ?>" data-notes="<?= e((string)($r['notes'] ?? '')) ?>"><?= admin_icon('edit') ?></button>
          <form method="POST" action="<?= $self ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="room_remove"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
            <button class="btn-icon btn-icon--danger" data-confirm="Remove <?= e($r['label']) ?>? A room people have lived in is switched off and keeps its history." data-tip="Remove room" aria-label="Remove <?= e($r['label']) ?>"><?= admin_icon('trash') ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; endif; ?>

<style>
.sr-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end}
.sr-form .field label{display:block;font-size:12px;color:var(--muted,#6b7280);margin-bottom:4px}
.sr-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.sr-table td{vertical-align:top}
.sr-table .n{text-align:right}
.sr-person{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.sr-person a{font-weight:600}
.sr-person .text-muted{font-size:12px}
.sr-assign{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.sr-assign select{max-width:220px}
</style>
<script>
(function () {
  var card = document.getElementById('roomCard'), form = document.getElementById('roomForm'), title = document.getElementById('roomCardTitle');
  var add = document.getElementById('addRoomBtn');
  if (!card || !form) return;
  function open() { card.removeAttribute('hidden'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  function set(n, v) { var el = form.querySelector('[name="' + n + '"]'); if (el) { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); } }
  if (add) add.addEventListener('click', function () { title.textContent = 'Add a room'; form.reset(); set('room_id', ''); open(); });
  document.querySelectorAll('.sr-edit').forEach(function (b) {
    b.addEventListener('click', function () {
      title.textContent = 'Edit ' + b.dataset.label;
      set('room_id', b.dataset.id); set('venue_id', b.dataset.venue); set('label', b.dataset.label); set('beds', b.dataset.beds); set('notes', b.dataset.notes);
      open();
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
