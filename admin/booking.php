<?php
/** Admin: single-booking workspace — Requests · Messages · Plan · Details. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/icons.php';            // admin_icon() — used while buffering the AJAX panel (before _layout.php loads it)
require_once __DIR__ . '/../includes/admin-pagination.php'; // dt_empty() for the workspace thread list
require_once __DIR__ . '/../includes/copy-link.php';        // copy_link_control() for the Details tab
require_login();

$holdId = (int)($_GET['hold'] ?? $_POST['hold_id'] ?? 0);
$hold = $holdId ? db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.venue_id AS venue_id, v.name AS venue_name
     FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id
     LEFT JOIN venues v ON v.id=r.venue_id WHERE h.id=:id", [':id'=>$holdId]
)->fetch() : null;

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }
if (!$hold) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Booking not found.']; header('Location: /admin/holds.php'); exit; }
if (!is_owner() && !staff_can_hold($holdId)) { $_SESSION['hold_flash']=['type'=>'error','msg'=>'That booking is at a property you don’t manage.']; header('Location: ' . admin_home_url()); exit; }

// Ops staff & gate-security get no guest messaging (mirrors require_frontdesk / the nav).
$__noMessaging = is_staff() && (job_is_ops(admin_job()) || admin_job() === 'security');

$tab = $_GET['tab'] ?? 'requests';
if (!in_array($tab, ['requests','messages','plan','bill','checkin','details'], true)) $tab = 'requests';
// Only the owner sees the Details tab (booking confirm/cancel/edit is owner-only config).
if (!is_owner() && $tab === 'details') $tab = 'requests';
if ($__noMessaging && $tab === 'messages') $tab = 'requests';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';
    // Only the owner can confirm/cancel a booking — server-side gate (the Details tab is also hidden).
    if (!is_owner() && in_array($act, ['confirm','cancel'], true)) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Only the owner account can confirm or cancel bookings.'];
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
    if ($act === 'reply' && $__noMessaging) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Messaging isn’t available for your account.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=requests"); exit;
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
    if ($act === 'bill_set_price') {
        $aid   = (int)($_POST['addon_id'] ?? 0);
        $price = ($_POST['price_amount'] ?? '') === '' ? null : (float)$_POST['price_amount'];
        if ($aid && ($price === null || ($price >= 0 && $price < 100000000))) { // NUMERIC(10,2) ceiling
            db_query("UPDATE booking_addons SET price_amount=:p WHERE id=:a AND hold_id=:h", [':p'=>$price, ':a'=>$aid, ':h'=>$holdId]);
            audit_log('bill.set_price', 'booking_addon', $aid, '');
        }
        header("Location: /admin/booking.php?hold=$holdId&tab=bill"); exit;
    }
    if ($act === 'bill_add') {
        $label  = trim((string)($_POST['label'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        if ($label !== '' && $amount >= 0 && $amount < 100000000) { // NUMERIC(10,2) ceiling
            $gid = (int)($_POST['guest_id'] ?? 0);
            $gid = ($gid > 0 && bill_item_guest_supported()
                    && db_query('SELECT 1 FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetchColumn())
                   ? $gid : null;
            if (bill_item_guest_supported()) {
                db_query("INSERT INTO bill_items (hold_id, label, amount, guest_id) VALUES (:h,:l,:a,:g)", [':h'=>$holdId, ':l'=>mb_substr($label,0,200), ':a'=>$amount, ':g'=>$gid]);
            } else {
                db_query("INSERT INTO bill_items (hold_id, label, amount) VALUES (:h,:l,:a)", [':h'=>$holdId, ':l'=>mb_substr($label,0,200), ':a'=>$amount]);
            }
            audit_log('bill.add', 'hold', $holdId, $label);
        }
        header("Location: /admin/booking.php?hold=$holdId&tab=bill"); exit;
    }
    if ($act === 'bill_del') {
        db_query("DELETE FROM bill_items WHERE id=:i AND hold_id=:h", [':i'=>(int)($_POST['item_id'] ?? 0), ':h'=>$holdId]);
        audit_log('bill.del', 'hold', $holdId, '');
        header("Location: /admin/booking.php?hold=$holdId&tab=bill"); exit;
    }
    if ($act === 'checkin_toggle' && is_owner() && checkin_supported()) {
        $on = ($_POST['require_checkin'] ?? '') === '1';
        db_query('UPDATE holds SET require_checkin = :r WHERE id = :id', [':r' => $on, ':id' => $holdId]);
        audit_log('checkin.require_toggle', 'hold', $holdId, $on ? 'on' : 'off');
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Check-in requirement updated.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }
    if ($act === 'guest_count_set' && is_owner() && checkin_supported()) {
        $n = (int)($_POST['guest_count'] ?? 0);
        if ($n >= 1 && $n <= 12) {
            db_query('UPDATE holds SET guest_count = :n WHERE id = :id', [':n' => $n, ':id' => $holdId]);
            audit_log('checkin.guest_count', 'hold', $holdId, (string)$n);
            $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Party size updated.'];
        } else {
            $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Number of adults must be between 1 and 12.'];
        }
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }

    if ($act === 'share_toggle' && checkin_supported() && can_view_guest_docs($holdId) && share_reservation_supported()) {
        $on = ($_POST['share_reservation'] ?? '') === '1';
        db_query('UPDATE holds SET share_reservation = :s WHERE id = :id', [':s' => $on, ':id' => $holdId]);
        audit_log('portal.share_toggle', 'hold', $holdId, $on ? 'on' : 'off');
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => $on ? 'Sharing turned on — co-guests can use the portal.' : 'Sharing turned off.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }

    // ── Guest management (owner / venue-manager) — sub-project B ─────────────
    if (in_array($act, ['guest_fill','guest_upload','guest_add_adult','guest_add_child','guest_remove'], true)
        && checkin_supported() && can_view_guest_docs($holdId)) {

        $gs  = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
        $gid = (int)($_POST['guest_id'] ?? 0);

        // Guest-targeting actions must reference a guest that belongs to this hold.
        if (in_array($act, ['guest_fill','guest_upload','guest_remove'], true)) {
            $belongs = $gid > 0 && db_query('SELECT 1 FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetchColumn();
            if (!$belongs) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Guest not found on this booking.'];
                header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
            }
        }

        if ($act === 'guest_fill' && $gid > 0) {
            // Editing a signed guest's material identity voids their signature (they
            // must re-consent) and must not silently change the signed record. Runs
            // BEFORE the UPDATE so it can compare the stored values against the edit.
            $voided = checkin_void_signature_if_identity_changed($holdId, $gid, [
                'passport_name'   => $gs('passport_name'),
                'passport_number' => $gs('passport_number'),
                'nationality'     => $gs('nationality'),
                'passport_expiry' => $gs('passport_expiry'),
            ], 'admin');
            db_query("UPDATE checkin_guests SET passport_name=:n, passport_number=:num, nationality=:nat, passport_expiry=:exp WHERE id=:g AND hold_id=:h",
                [':n'=>$gs('passport_name'), ':num'=>$gs('passport_number'), ':nat'=>$gs('nationality'), ':exp'=>$gs('passport_expiry'), ':g'=>$gid, ':h'=>$holdId]);
            audit_log('checkin.guest_fill', 'hold', $holdId, 'guest ' . $gid);
            $_SESSION['hold_flash'] = $voided
                ? ['type'=>'success','msg'=>'Guest details saved. Their previous signature was voided — a new signature is required.']
                : ['type'=>'success','msg'=>'Guest details saved.'];

        } elseif ($act === 'guest_upload' && $gid > 0) {
            require_once __DIR__ . '/../includes/storage.php';
            $f = $_FILES['passport'] ?? null;
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'No file received.'];
            } elseif (($f['size'] ?? 0) > 8 * 1024 * 1024) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'File too large (max 8 MB).'];
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
                if (!isset($allowed[$mime])) {
                    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'File must be JPG, PNG or PDF.'];
                } else {
                    $key = 'checkin/' . $holdId . '/' . $gid . '/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
                    if (storage_put_private($f['tmp_name'], $key, $mime)) {
                        $prev = db_query('SELECT passport_file_key FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetch();
                        db_query('UPDATE checkin_guests SET passport_file_key=:k WHERE id=:g AND hold_id=:h', [':k'=>$key, ':g'=>$gid, ':h'=>$holdId]);
                        if ($prev && !empty($prev['passport_file_key']) && $prev['passport_file_key'] !== $key) { try { storage_delete_private($prev['passport_file_key']); } catch (Throwable $e) {} }
                        audit_log('checkin.guest_upload', 'hold', $holdId, 'guest ' . $gid);
                        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Passport scan uploaded.'];
                    } else {
                        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Upload failed — try again.'];
                    }
                }
            }

        } elseif ($act === 'guest_add_adult') {
            // First adult on an empty roster becomes the lead, so the guest's ref link
            // reuses it instead of seeding a duplicate lead row later.
            $hasLead = (bool) db_query('SELECT 1 FROM checkin_guests WHERE hold_id=:h AND is_lead', [':h'=>$holdId])->fetchColumn();
            if ($hasLead) {
                db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, passport_name) VALUES (:h, FALSE, FALSE, :n)', [':h'=>$holdId, ':n'=>$gs('passport_name')]);
            } else {
                db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, passport_name) VALUES (:h, TRUE, FALSE, :n)', [':h'=>$holdId, ':n'=>$gs('passport_name')]);
            }
            $newGid = (int) db()->lastInsertId();
            $adults = (int) db_query('SELECT COUNT(*) FROM checkin_guests WHERE hold_id=:h AND is_child=FALSE', [':h'=>$holdId])->fetchColumn();
            if ($adults > (int)($hold['guest_count'] ?? 1)) {
                db_query('UPDATE holds SET guest_count=:n WHERE id=:h', [':n'=>$adults, ':h'=>$holdId]);
            }
            // A newly-added adult is unverified — un-latch completion so the badge reflects reality.
            db_query('UPDATE holds SET checkin_completed_at = NULL WHERE id=:h', [':h'=>$holdId]);
            audit_log('checkin.guest_add', 'hold', $holdId, 'adult ' . $newGid);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Adult added.'];

        } elseif ($act === 'guest_add_child') {
            $parent = (int)($_POST['parent_guest_id'] ?? 0);
            $okParent = $parent > 0 && db_query('SELECT 1 FROM checkin_guests WHERE id=:p AND hold_id=:h AND is_child=FALSE', [':p'=>$parent, ':h'=>$holdId])->fetchColumn();
            if ($okParent) {
                $dob = ($_POST['date_of_birth'] ?? '') !== '' ? (string)$_POST['date_of_birth'] : null;
                db_query('INSERT INTO checkin_guests (hold_id, is_lead, is_child, parent_guest_id, passport_name, date_of_birth) VALUES (:h, FALSE, TRUE, :p, :n, :dob)',
                    [':h'=>$holdId, ':p'=>$parent, ':n'=>$gs('passport_name'), ':dob'=>$dob]);
                audit_log('checkin.guest_add', 'hold', $holdId, 'child of ' . $parent);
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Child added.'];
            } else {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Pick a valid adult to add the child under.'];
            }

        } elseif ($act === 'guest_remove' && $gid > 0) {
            require_once __DIR__ . '/../includes/storage.php';
            $row = db_query('SELECT is_lead, is_child FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetch();
            if (!$row || !empty($row['is_lead'])) {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'The lead guest can’t be removed.'];
            } else {
                $files = db_query('SELECT passport_file_key FROM checkin_guests WHERE (id=:g OR parent_guest_id=:g) AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId])->fetchAll(PDO::FETCH_COLUMN);
                db_query('DELETE FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$gid, ':h'=>$holdId]);
                foreach ($files as $fk) { if (!empty($fk)) { try { storage_delete_private($fk); } catch (Throwable $e) {} } }
                if (empty($row['is_child'])) {   // removed an adult — shrink the required party and un-latch completion
                    $adults = (int) db_query('SELECT COUNT(*) FROM checkin_guests WHERE hold_id=:h AND is_child=FALSE', [':h'=>$holdId])->fetchColumn();
                    db_query('UPDATE holds SET guest_count = GREATEST(1, LEAST(guest_count, :a)), checkin_completed_at = NULL WHERE id=:h', [':a'=>$adults, ':h'=>$holdId]);
                }
                audit_log('checkin.guest_remove', 'hold', $holdId, 'guest ' . $gid);
                $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Guest removed.'];
            }
        }

        checkin_recompute_completion($holdId);
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
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

// Tab menu (owner-only Details; ops/security get no Messages).
$__wtabs = ['requests'=>'Requests','messages'=>'Messages','plan'=>'Plan','bill'=>'Bill','checkin'=>'Check-in','details'=>'Details'];
if (!is_owner())     unset($__wtabs['details']);
if ($__noMessaging)  unset($__wtabs['messages']);

// Skeleton the shell shows while a tab loads: the tab NAV always lands on the
// list/overview shape; the panel itself may be a chat when a thread is open.
$__tabSkel = ['requests'=>'table','messages'=>'table','plan'=>'detail','bill'=>'table','checkin'=>'detail','details'=>'detail'];
$__skel    = ($tab === 'messages' && ($_GET['thread'] ?? null) !== null) ? 'chat' : ($__tabSkel[$tab] ?? 'table');

// Build the active tab's panel into a buffer so it can be served on its own as an
// AJAX fragment — the no-flicker shell fetches ?ajax=1 and swaps just this panel.
ob_start();
if     ($tab === 'requests') include __DIR__ . '/_ws_requests.php';
elseif ($tab === 'messages') include __DIR__ . '/_ws_messages.php';
elseif ($tab === 'plan')     include __DIR__ . '/_ws_plan.php';
elseif ($tab === 'bill')     include __DIR__ . '/_ws_bill.php';
elseif ($tab === 'checkin')  include __DIR__ . '/_ws_checkin.php';
else                         include __DIR__ . '/_ws_details.php';
$wsPanel = ob_get_clean();

// AJAX fragment: emit only the panel inner and stop (GET only — POSTs stay PRG).
if (($_GET['ajax'] ?? '') !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') { echo $wsPanel; exit; }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e($hold['guest_name'] ?: 'Guest') ?> — <?= e($hold['room_name']) ?></h1>
  <a href="/admin/holds.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Bookings</a>
</div>
<p class="text-muted" style="margin:-8px 0 14px;font-size:13px"><?= e(date('j M Y',strtotime($hold['check_in']))) ?> → <?= e(date('j M Y',strtotime($hold['check_out']))) ?> · <span class="badge badge--<?= ['pending'=>'orange','confirmed'=>'green','cancelled'=>'red','expired'=>'grey'][$hold['status']] ?? 'grey' ?>"><?= e($hold['status']) ?></span> · <code><?= e($hold['access_code']) ?></code></p>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="ws" data-ws>
  <nav class="tabs ws-tabs" data-ws-tabs aria-label="Booking sections">
    <?php foreach ($__wtabs as $tk=>$tl):
      $cnt = $tk === 'requests' ? $openReq : ($tk === 'messages' ? $unreadMsg : 0);
    ?>
    <a href="?hold=<?= $holdId ?>&tab=<?= $tk ?>" class="tab-btn<?= $tab===$tk?' is-active':'' ?>"
       data-ws-tab data-tab="<?= e($tk) ?>" data-skeleton="<?= e($__tabSkel[$tk] ?? 'table') ?>">
      <?= e($tl) ?><?php if ($cnt > 0): ?> <span class="tab-btn__count"><?= (int)$cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="ws-panel" data-ws-panel data-skeleton="<?= e($__skel) ?>"><?= $wsPanel ?></div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
