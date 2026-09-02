<?php
/** Admin: per-booking daily itinerary editor. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();
require_bookings();

$pageTitle  = 'Itinerary';
$activeMenu = 'holds';

$CATS = ['flight'=>'Flight','transfer'=>'Transfer','tour'=>'Tour','dining'=>'Dining','activity'=>'Activity','note'=>'Note'];

$holdId = (int)($_GET['hold'] ?? $_POST['hold_id'] ?? 0);
$hold = $holdId ? db_query(
    "SELECT h.*, r.name AS room_name FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE h.id=:id",
    [':id'=>$holdId]
)->fetch() : null;

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// Property scope — a scoped account (reception) only edits its own venues'
// itineraries. This sits above every POST handler below.
if (!$hold || !staff_can_hold($holdId)) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Booking not found.']; header('Location: /admin/holds.php'); exit; }

$__days = [];
for ($d = new DateTime((string)$hold['check_in']); $d <= new DateTime((string)$hold['check_out']); $d->modify('+1 day')) {
    $__days[$d->format('Y-m-d')] = $d->format('D j M Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $day   = (string)($_POST['day'] ?? '');
        $cat   = (string)($_POST['category'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $detail= trim((string)($_POST['detail'] ?? ''));
        $atRaw = trim((string)($_POST['at_time'] ?? ''));
        $at    = preg_match('/^\d{2}:\d{2}$/', $atRaw) ? $atRaw : null;
        if (!isset($__days[$day]))      $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a day within the stay.'];
        elseif (!isset($CATS[$cat]))    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a category.'];
        elseif ($title === '')          $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Title is required.'];
        else {
            db_query("INSERT INTO itinerary_items (hold_id, day, at_time, category, title, detail) VALUES (:h,:d,:t,:c,:ti,:de)",
                [':h'=>$holdId, ':d'=>$day, ':t'=>$at, ':c'=>$cat, ':ti'=>$title, ':de'=>($detail !== '' ? $detail : null)]);
            audit_log('itinerary.add', 'hold', $holdId, $title);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Item added.'];
        }
    } elseif ($action === 'delete') {
        $iid = (int)($_POST['item_id'] ?? 0);
        db_query("DELETE FROM itinerary_items WHERE id=:i AND hold_id=:h", [':i'=>$iid, ':h'=>$holdId]);
        audit_log('itinerary.delete', 'hold', $holdId, (string)$iid);
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Item removed.'];
    }
    header('Location: /admin/itinerary.php?hold=' . $holdId); exit;
}

$itin  = fetch_itinerary($hold);
$items = fetch_itinerary_items($holdId);

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Itinerary — <?= e($hold['guest_name'] ?: 'Guest') ?></h1>
  <a href="/admin/holds.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Bookings</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px">
  <div class="card__head"><span class="card__title">Add item</span></div>
  <div class="card__body">
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
      <label style="font-size:13px">Day<br>
        <select name="day" required>
          <?php foreach ($__days as $dv=>$dl): ?><option value="<?= e($dv) ?>"><?= e($dl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label style="font-size:13px">Time (optional)<br><input type="time" name="at_time"></label>
      <label style="font-size:13px">Category<br>
        <select name="category"><?php foreach ($CATS as $cv=>$cl): ?><option value="<?= e($cv) ?>"><?= e($cl) ?></option><?php endforeach; ?></select>
      </label>
      <label style="font-size:13px;flex:1;min-width:180px">Title<br><input type="text" name="title" required placeholder="Enter title" style="width:100%"></label>
      <label style="font-size:13px;flex:1;min-width:180px">Detail<br><input type="text" name="detail" placeholder="Enter detail" style="width:100%"></label>
      <button type="submit" class="btn-primary">Add</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Plan (<?= e($hold['check_in']) ?> → <?= e($hold['check_out']) ?>)</span></div>
  <div class="card__body">
    <?php foreach ($itin as $day): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:6px"><?= e($day['label']) ?><?= $day['is_today'] ? ' · today' : '' ?></div>
      <?php if (!$day['items']): ?>
        <div style="font-size:13px;color:var(--muted);font-style:italic">—</div>
      <?php else: foreach ($day['items'] as $it): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #f0f0f0;font-size:14px">
          <span style="min-width:44px;color:var(--muted)"><?= e($it['time'] ?? '—') ?></span>
          <span style="text-transform:capitalize;min-width:74px;color:var(--muted);font-size:12px"><?= e($it['category']) ?></span>
          <span style="flex:1"><strong><?= e($it['title']) ?></strong><?php if (($it['detail'] ?? '') !== '' && $it['detail'] !== 'from your request'): ?> <span style="color:var(--muted)">· <?= e($it['detail']) ?></span><?php endif; ?></span>
          <span style="font-size:11px;color:var(--muted)"><?= e($it['source']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($items): ?>
    <div style="margin-top:18px;border-top:1px solid #eee;padding-top:12px">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px">Your added items</div>
      <?php foreach ($items as $it): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-size:14px">
        <span style="flex:1"><?= e((string)$it['day']) ?><?php if (!empty($it['at_time'])): ?> <?= e(substr((string)$it['at_time'],0,5)) ?><?php endif; ?> · <strong><?= e($it['title']) ?></strong> <span style="color:var(--muted);text-transform:capitalize">(<?= e($it['category']) ?>)</span><?php if (($it['created_by'] ?? 'admin') === 'guest'): ?> <span class="badge badge--blue" style="font-size:10px">guest</span><?php endif; ?></span>
        <form method="POST" onsubmit="return confirm('Remove this item?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="hold_id" value="<?= (int)$holdId ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><button class="btn-danger btn-sm">Delete</button></form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
