<?php
/** Admin: guest ↔ staff messages. Thread list + conversation + reply (PRG). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'Messages';
$activeMenu = 'messages';

$holdId  = isset($_GET['hold']) ? (int)$_GET['hold'] : 0;
$threadP = $_GET['thread'] ?? null;
$addonId = ($threadP === null || $threadP === 'general') ? null : (int)$threadP;
$inThread = $holdId > 0 && $threadP !== null;

if ($inThread && !is_owner() && !staff_can_hold($holdId)) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
    header('Location: /admin/messages.php'); exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ph = (int)($_POST['hold_id'] ?? 0);
    $pa = ($_POST['addon_id'] ?? '') === '' ? null : (int)$_POST['addon_id'];
    $body = trim((string)($_POST['body'] ?? ''));
    // Staff and managers may only reply on bookings at their own properties.
    if (!is_owner() && !staff_can_hold($ph)) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
        header('Location: /admin/messages.php'); exit;
    }
    // Validate the target: hold must exist, and a targeted addon must belong to it.
    $holdOk  = $ph > 0 && db_query("SELECT 1 FROM holds WHERE id=:h", [':h'=>$ph])->fetchColumn();
    $addonOk = $pa === null || db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$pa, ':h'=>$ph])->fetchColumn();
    if ($holdOk && $addonOk && $body !== '') {
        if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);
        db_query("INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin) VALUES (:h,:a,'admin',:b,FALSE,TRUE)",
            [':h'=>$ph, ':a'=>$pa, ':b'=>$body]);
        audit_log('booking_message.admin_reply', 'hold', $ph, '');
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Reply sent.'];
    } elseif ($body !== '') {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That conversation no longer exists.'];
    }
    $q = '?hold=' . $ph . '&thread=' . ($pa === null ? 'general' : $pa);
    header('Location: /admin/messages.php' . $q); exit;
}

if ($inThread) { mark_thread_read_by_admin($holdId, $addonId); }

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Messages</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$inThread):
  $threads = fetch_admin_threads(admin_venue_ids());
?>
<div class="card"><div class="card__body" style="padding:0">
  <table class="data-table">
    <thead><tr><th>Guest</th><th>Thread</th><th>Latest</th><th>Unread</th></tr></thead>
    <tbody>
      <?php if (!$threads): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--muted)">No messages yet.</td></tr>
      <?php else: foreach ($threads as $t):
        $tid = $t['addon_id'] === null ? 'general' : (int)$t['addon_id'];
      ?>
      <tr>
        <td><strong><?= e($t['guest_name'] ?: 'Guest') ?></strong><br><a href="/admin/booking.php?hold=<?= (int)$t['hold_id'] ?>&tab=messages" class="text-muted" style="font-size:12px">Manage booking →</a></td>
        <td><a href="?hold=<?= (int)$t['hold_id'] ?>&thread=<?= e((string)$tid) ?>"><?= e(thread_title($t)) ?></a></td>
        <td class="text-muted" style="font-size:13px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)($t['last_body'] ?? '')) ?></td>
        <td><?php if ((int)$t['unread_admin'] > 0): ?><span class="badge badge--orange"><?= (int)$t['unread_admin'] ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>

<?php else:
  $msgs = fetch_thread_messages($holdId, $addonId);
  $ctx  = db_query("SELECT h.guest_name FROM holds h WHERE h.id=:h", [':h'=>$holdId])->fetch();
?>
<p style="margin:0 0 14px"><a href="/admin/messages.php" class="btn-outline btn-sm">← All threads</a></p>
<div class="card"><div class="card__body">
  <p style="margin:0 0 12px;font-weight:600"><?= e($ctx['guest_name'] ?? 'Guest') ?></p>
  <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
    <?php if (!$msgs): ?><p class="text-muted">No messages in this thread yet.</p><?php endif; ?>
    <?php foreach ($msgs as $m): $adminMsg = $m['sender'] === 'admin'; ?>
    <div style="max-width:80%;<?= $adminMsg ? 'align-self:flex-end;background:#102F3A;color:#fff' : 'align-self:flex-start;background:#f3f4f6;color:#111' ?>;border-radius:12px;padding:9px 12px;font-size:14px;line-height:1.5">
      <?= e($m['body']) ?>
      <div style="font-size:11px;margin-top:4px;opacity:.7"><?= $adminMsg ? 'Staff' : 'Guest' ?> · <?= e(date('j M, H:i', strtotime((string)$m['created_at']))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
    <input type="hidden" name="addon_id" value="<?= $addonId === null ? '' : (int)$addonId ?>">
    <textarea name="body" rows="3" required placeholder="Reply to the guest…" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:16px"></textarea>
    <button type="submit" class="btn-primary" style="margin-top:8px">Send reply</button>
  </form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
