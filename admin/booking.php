<?php
/** Admin: single-booking workspace — Requests · Messages · Plan · Details. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();

$holdId = (int)($_GET['hold'] ?? $_POST['hold_id'] ?? 0);
$hold = $holdId ? db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, v.name AS venue_name
     FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id
     LEFT JOIN venues v ON v.id=r.venue_id WHERE h.id=:id", [':id'=>$holdId]
)->fetch() : null;

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }
if (!$hold) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Booking not found.']; header('Location: /admin/holds.php'); exit; }
if (is_staff() && !staff_can_hold($holdId)) { $_SESSION['hold_flash']=['type'=>'error','msg'=>'That booking is at a property you don’t manage.']; header('Location: ' . admin_home_url()); exit; }

$tab = $_GET['tab'] ?? 'requests';
if (!in_array($tab, ['requests','messages','plan','details'], true)) $tab = 'requests';
if (is_staff() && $tab === 'details') $tab = 'requests';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    // Staff can never confirm/cancel a booking — server-side gate (the Details tab is also hidden).
    if (is_staff() && in_array($act, ['confirm','cancel'], true)) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Staff accounts cannot confirm or cancel bookings.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=requests"); exit;
    }
    if ($act === 'confirm' && $hold['status'] === 'pending') {
        db_query("UPDATE holds SET status='confirmed', confirmed_at=NOW() WHERE id=:id", [':id'=>$holdId]);
        db_query("UPDATE availability_blocks SET block_type='booked' WHERE hold_id=:hid", [':hid'=>$holdId]);
        if ($hold['guest_email']) send_hold_confirmed($hold);
        audit_log('hold.confirm', 'hold', $holdId, "{$hold['guest_name']}");
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Confirmed — guest notified.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=details"); exit;
    }
    if ($act === 'cancel' && in_array($hold['status'], ['pending','confirmed'], true)) {
        db_query("UPDATE holds SET status='cancelled', cancelled_at=NOW() WHERE id=:id", [':id'=>$holdId]);
        db_query("DELETE FROM availability_blocks WHERE hold_id=:hid", [':hid'=>$holdId]);
        if ($hold['guest_email']) send_hold_cancelled($hold, 'cancelled');
        audit_log('hold.cancel', 'hold', $holdId, "{$hold['guest_name']}");
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Cancelled — dates freed, guest notified.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=details"); exit;
    }
    if ($act === 'reply') {
        $pa = ($_POST['addon_id'] ?? '') === '' ? null : (int)$_POST['addon_id'];
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
            $okAddon = $pa === null || db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$pa,':h'=>$holdId])->fetchColumn();
            if ($okAddon) {
                db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,:a,'admin',:b,FALSE,TRUE)", [':h'=>$holdId,':a'=>$pa,':b'=>$body]);
                audit_log('booking_message.admin_reply','hold',$holdId,'');
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Reply sent.'];
            }
        }
        $t = $pa === null ? 'general' : $pa;
        header("Location: /admin/booking.php?hold=$holdId&tab=messages&thread=$t"); exit;
    }
    if ($act === 'itin_add') {
        $CATS = ['flight','transfer','tour','dining','activity','note'];
        $day = (string)($_POST['day'] ?? ''); $cat = (string)($_POST['category'] ?? '');
        $title = trim((string)($_POST['title'] ?? '')); $detail = trim((string)($_POST['detail'] ?? ''));
        $atRaw = trim((string)($_POST['at_time'] ?? '')); $at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$atRaw) ? $atRaw : null;
        $inRange = $day !== '' && $day >= (string)$hold['check_in'] && $day <= (string)$hold['check_out'] && preg_match('/^\d{4}-\d{2}-\d{2}$/',$day);
        if ($inRange && in_array($cat,$CATS,true) && $title !== '') {
            db_query("INSERT INTO itinerary_items (hold_id,day,at_time,category,title,detail,created_by) VALUES (:h,:d,:t,:c,:ti,:de,'admin')",
                [':h'=>$holdId,':d'=>$day,':t'=>$at,':c'=>$cat,':ti'=>mb_substr($title,0,200),':de'=>($detail!==''?mb_substr($detail,0,2000):null)]);
            audit_log('itinerary.add','hold',$holdId,$title);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Plan item added.'];
        } else { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Check the day, category and title.']; }
        header("Location: /admin/booking.php?hold=$holdId&tab=plan"); exit;
    }
    if ($act === 'itin_del') {
        db_query("DELETE FROM itinerary_items WHERE id=:i AND hold_id=:h", [':i'=>(int)($_POST['item_id']??0),':h'=>$holdId]);
        audit_log('itinerary.delete','hold',$holdId,'');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Plan item removed.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=plan"); exit;
    }
}

$pageTitle  = 'Booking';
$activeMenu = 'holds';
$portalUrl  = make_manage_url($holdId);

$__addons  = fetch_booking_addons($holdId);
$__changes = fetch_booking_change_requests($holdId);
$openReq   = 0;
foreach ($__addons as $a) { if (in_array($a['status'],['requested','confirmed'],true)) $openReq++; }
foreach ($__changes as $c){ if ($c['status']==='requested') $openReq++; }
$unreadMsg = (int)db_query("SELECT COUNT(*) FROM booking_messages WHERE hold_id=:h AND sender='guest' AND read_by_admin=FALSE",[':h'=>$holdId])->fetchColumn();

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($hold['guest_name'] ?: 'Guest') ?> — <?= e($hold['room_name']) ?></h1>
  <a href="/admin/holds.php" class="btn-outline btn-sm">← Bookings</a>
</div>
<p class="text-muted" style="margin:-8px 0 14px;font-size:13px"><?= e(date('j M Y',strtotime($hold['check_in']))) ?> → <?= e(date('j M Y',strtotime($hold['check_out']))) ?> · <span class="badge badge--<?= ['pending'=>'orange','confirmed'=>'green','cancelled'=>'red','expired'=>'grey'][$hold['status']] ?? 'grey' ?>"><?= e($hold['status']) ?></span> · <code><?= e($hold['access_code']) ?></code></p>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px"><div class="card__body" style="display:flex;gap:8px;flex-wrap:wrap">
  <?php
  $__wtabs = ['requests'=>'Requests','messages'=>'Messages','plan'=>'Plan','details'=>'Details'];
  if (is_staff()) unset($__wtabs['details']);
  foreach ($__wtabs as $tk=>$tl):
    $b = $tk==='requests' && $openReq ? " ($openReq)" : ($tk==='messages' && $unreadMsg ? " ($unreadMsg)" : '');
  ?>
  <a href="?hold=<?= $holdId ?>&tab=<?= $tk ?>" class="btn-sm <?= $tab===$tk?'btn-primary':'btn-outline' ?>"><?= e($tl.$b) ?></a>
  <?php endforeach; ?>
</div></div>

<?php if ($tab === 'requests'): ?>
  <?php include __DIR__ . '/_ws_requests.php'; ?>
<?php elseif ($tab === 'messages'): ?>
  <?php include __DIR__ . '/_ws_messages.php'; ?>
<?php elseif ($tab === 'plan'): ?>
  <?php include __DIR__ . '/_ws_plan.php'; ?>
<?php else: ?>
  <div class="card"><div class="card__body">
    <table class="data-table" style="max-width:520px">
      <tr><td class="text-muted">Guest</td><td><?= e($hold['guest_name']) ?></td></tr>
      <tr><td class="text-muted">Email</td><td><?= e($hold['guest_email']) ?></td></tr>
      <tr><td class="text-muted">Property</td><td><?= e(trim(($hold['venue_name']??'').' · '.$hold['room_name'],' ·')) ?></td></tr>
      <tr><td class="text-muted">Dates</td><td><?= e(date('j M Y',strtotime($hold['check_in']))) ?> → <?= e(date('j M Y',strtotime($hold['check_out']))) ?></td></tr>
      <tr><td class="text-muted">Code</td><td><code><?= e($hold['access_code']) ?></code></td></tr>
      <tr><td class="text-muted">Portal link</td><td><input type="text" readonly value="<?= e($portalUrl) ?>" onclick="this.select()" style="width:100%;font-size:12px"></td></tr>
    </table>
    <div style="margin-top:14px">
      <?php if ($hold['status']==='pending'): ?>
      <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="action" value="confirm"><button class="btn-primary btn-sm" onclick="return confirm('Confirm and notify the guest?')">Confirm</button></form>
      <?php endif; ?>
      <?php if (in_array($hold['status'],['pending','confirmed'],true)): ?>
      <form method="POST" style="display:inline;margin-left:6px"><?= csrf_field() ?><input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="action" value="cancel"><button class="btn-danger btn-sm" onclick="return confirm('Cancel this booking? Dates freed, guest notified.')">Cancel</button></form>
      <?php endif; ?>
    </div>
  </div></div>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
