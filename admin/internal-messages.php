<?php
/**
 * Admin: internal team chat (team ↔ team), separate from Customer messages.
 * Channel list (all-team + one per accessible property) → conversation →
 * composer. Live via short polling (admin/internal-messages-poll.php); a plain
 * PRG form is the no-JS fallback.
 *
 * Broad audience: any signed-in account, including ops & gate staff — internal
 * comms are for the whole team. Venue channels are limited to their members.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/internal-messages.php';
require_once __DIR__ . '/../includes/booking.php';   // message_time_label()
require_once __DIR__ . '/../includes/icons.php';
require_login();

$pageTitle  = 'Team chat';
$activeMenu = 'internal_messages';

$admin = current_admin();
$meId  = (int)($admin['id'] ?? 0);

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// Who may create group chats (Item 4).
$canCreateGroups = internal_group_channels_supported() && (is_owner() || is_manager());

/** Access check for a resolved [venue_id, group_id] pair. */
$chAccess = function (?int $venueId, ?int $groupId) use ($meId): bool {
    if ($groupId) return internal_can_access_group($groupId, $meId);
    if ($venueId) return internal_can_access_channel($venueId);
    return true; // all-team
};

// Resolve the active channel from ?channel=all|<venue_id>|g<group_id>.
$channelRaw = (string)($_GET['channel'] ?? 'all');
[$activeVenue, $activeGroup] = internal_parse_channel($channelRaw);
if (($activeVenue !== null || $activeGroup !== null) && !$chAccess($activeVenue, $activeGroup)) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your channel.'];
    header('Location: /admin/internal-messages.php'); exit;
}

// ── POST: create a group chat (owner/manager) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
    verify_csrf();
    if (!$canCreateGroups) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Only an owner or manager can create a group.'];
        header('Location: /admin/internal-messages.php'); exit;
    }
    $name    = trim((string)($_POST['group_name'] ?? ''));
    $members = array_map('intval', (array)($_POST['members'] ?? []));
    if ($name === '') {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Give the group a name.'];
        header('Location: /admin/internal-messages.php'); exit;
    }
    $gid = create_internal_group($name, $meId, $members);
    if ($gid > 0) {
        audit_log('internal_group.create', 'internal_channel', $gid, $name);
        $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Group “' . $name . '” created.'];
        header('Location: /admin/internal-messages.php?channel=g' . $gid); exit;
    }
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Could not create the group.'];
    header('Location: /admin/internal-messages.php'); exit;
}

// ── POST: no-JS fallback to post a message (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!internal_messages_supported()) { header('Location: /admin/internal-messages.php'); exit; }
    [$pVenue, $pGroup] = internal_parse_channel((string)($_POST['channel'] ?? 'all'));
    $body   = trim((string)($_POST['body'] ?? ''));
    if ($chAccess($pVenue, $pGroup) && $body !== '') {
        $id = post_internal_message($pVenue, $meId, $body, $pGroup);
        internal_mark_channel_read($meId, $pVenue, $id, $pGroup);
        audit_log('internal_message.post', $pGroup ? 'internal_channel' : 'venue', ($pGroup ?: $pVenue) ?? 0, '');
    }
    header('Location: /admin/internal-messages.php?channel=' . internal_channel_addr($pVenue, $pGroup)); exit;
}

$channels = internal_messages_supported() ? internal_channels_for_user() : [];
$unread   = internal_messages_supported() ? internal_unread_by_channel($meId, $channels) : [];
$activeAddr = internal_channel_addr($activeVenue, $activeGroup);

// Active channel label + messages.
$activeLabel = 'All team';
foreach ($channels as $ch) {
    if (($ch['venue_id'] ?? null) === $activeVenue && ($ch['group_id'] ?? null) === $activeGroup) { $activeLabel = $ch['label']; break; }
}
$msgs = internal_messages_supported() ? fetch_internal_messages($activeVenue, 200, $activeGroup) : [];
if ($msgs) internal_mark_channel_read($meId, $activeVenue, (int)end($msgs)['id'], $activeGroup);
$lastId = $msgs ? (int)end($msgs)['id'] : 0;

// Members for the "new group" picker — active login accounts.
$__teamAccounts = $canCreateGroups
    ? db_query("SELECT id, name, email, role FROM admin_users WHERE is_active = TRUE ORDER BY name ASC")->fetchAll()
    : [];
// Members of the active group (shown in the header).
$__groupMembers = $activeGroup ? fetch_internal_group_members($activeGroup) : [];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Team chat</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!internal_messages_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p class="text-muted" style="margin:0">Team chat is unavailable. Run the <code>add_internal_messages.sql</code> migration to enable it.</p>
</div></div>
<?php else: ?>

<div class="im-wrap" style="display:grid;grid-template-columns:240px minmax(0,1fr);gap:18px;align-items:start">
  <!-- Channel list -->
  <div class="card im-channels" style="align-self:stretch">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <span class="card__title">Channels</span>
      <?php if ($canCreateGroups): ?>
      <button type="button" class="btn-outline btn-sm" id="imNewGroupBtn"><?= admin_icon('plus', 14) ?> New group</button>
      <?php endif; ?>
    </div>
    <div class="card__body" style="padding:8px">
      <?php $__lastKind = 'core'; foreach ($channels as $ch):
        $isActive = ($ch['venue_id'] ?? null) === $activeVenue && ($ch['group_id'] ?? null) === $activeGroup;
        $u = (int)($unread[$ch['key']] ?? 0);
        $addr = internal_channel_addr($ch['venue_id'] ?? null, $ch['group_id'] ?? null);
        $isGroup = !empty($ch['group_id']);
        if ($isGroup && $__lastKind !== 'group') { echo '<div style="border-top:1px solid var(--border,#e7ded7);margin:6px 4px 8px"></div><div class="text-muted" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding:0 8px 4px">Group chats</div>'; $__lastKind = 'group'; }
      ?>
      <a href="/admin/internal-messages.php?channel=<?= e($addr) ?>" data-shell-link class="im-channel<?= $isActive ? ' is-active' : '' ?>"
         style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 12px;border-radius:8px;text-decoration:none;margin-bottom:2px;font-size:14px;<?= $isActive ? 'background:var(--teal,#1E5C6B);color:#fff;font-weight:600' : 'color:var(--text,#222)' ?>">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= ($ch['venue_id'] ?? null) === null && !$isGroup ? '★ ' : ($isGroup ? '# ' : '') ?><?= e($ch['label']) ?></span>
        <?php if ($u > 0): ?><span class="badge <?= $isActive ? 'badge--grey' : 'badge--orange' ?>"><?= $u ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Conversation -->
  <div class="card">
    <div class="card__body" style="padding:20px">
      <p style="margin:0 0 12px;font-weight:600"><?= $activeGroup ? '# ' : '' ?><?= e($activeLabel) ?>
        <span class="text-muted" style="font-weight:400">· <?php
          if ($activeGroup) {
              $__names = array_map(fn($m) => trim((string)($m['name'] ?? '')) ?: (string)($m['email'] ?? ''), $__groupMembers);
              echo e(count($__names) . ' member' . (count($__names) === 1 ? '' : 's') . ($__names ? ': ' . implode(', ', array_slice($__names, 0, 6)) . (count($__names) > 6 ? '…' : '') : ''));
          } else {
              echo $activeVenue === null ? 'everyone on the team' : 'this property\'s team';
          }
        ?></span>
      </p>
      <div id="amThread" class="am-thread"
           data-poll-url="/admin/internal-messages-poll"
           data-channel="<?= e($activeAddr) ?>"
           data-last="<?= $lastId ?>">
        <p class="text-muted am-empty"<?= $msgs ? ' style="display:none"' : '' ?>>No messages in this channel yet. Say hello 👋</p>
        <?php foreach ($msgs as $m): $mine = (int)($m['sender_admin_id'] ?? 0) === $meId; ?>
        <div class="am-msg <?= $mine ? 'am-msg--staff' : 'am-msg--guest' ?>" data-mid="<?= (int)$m['id'] ?>">
          <?= e($m['body']) ?>
          <div class="am-msg__meta"><?= e(trim((string)($m['sender_name'] ?? '')) ?: 'Team') ?> · <?= e(message_time_label($m['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <form id="amForm" method="POST" class="am-composer">
        <?= csrf_field() ?>
        <input type="hidden" name="channel" value="<?= e($activeAddr) ?>">
        <textarea name="body" rows="3" required placeholder="Message the team…"></textarea>
        <div class="am-composer__actions">
          <button type="submit" class="btn-primary"><?= admin_icon('send', 15) ?> Send</button>
          <span class="am-status text-muted" aria-live="polite" style="font-size:13px"></span>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($canCreateGroups): ?>
<!-- New group chat (Item 4) -->
<div class="im-modal is-hidden" id="imNewGroup" role="dialog" aria-modal="true" aria-label="New group chat">
  <div class="im-modal__box">
    <h2 style="font-size:16px;margin:0 0 14px">New group chat</h2>
    <form method="POST" action="/admin/internal-messages.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_group">
      <label class="detail-item__label" style="display:block;margin-bottom:6px">Group name</label>
      <input type="text" name="group_name" class="inp" required maxlength="120" placeholder="e.g. Watamu managers" style="width:100%;box-sizing:border-box;margin-bottom:16px">
      <label class="detail-item__label" style="display:block;margin-bottom:6px">Members</label>
      <div class="im-members">
        <?php foreach ($__teamAccounts as $a): if ((int)$a['id'] === $meId) continue; ?>
        <label class="im-member">
          <input type="checkbox" name="members[]" value="<?= (int)$a['id'] ?>">
          <span><?= e($a['name'] ?: $a['email']) ?> <span class="text-muted" style="font-size:12px">(<?= e($a['role']) ?>)</span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <p class="text-muted" style="font-size:12px;margin:10px 0 0">You’re added automatically.</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn-outline btn-sm" data-im-close>Cancel</button>
        <button type="submit" class="btn-primary btn-sm">Create group</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
@media (max-width:720px){ .im-wrap{grid-template-columns:1fr!important} .im-channels .card__body{display:flex;flex-wrap:wrap;gap:4px} }
.im-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px}
.im-modal.is-hidden{display:none}
.im-modal__box{background:#fff;border-radius:10px;padding:22px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,.2);max-height:88vh;overflow:auto}
.im-members{max-height:240px;overflow:auto;border:1px solid var(--border,#e7ded7);border-radius:8px;padding:6px}
.im-member{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;font-size:14px;cursor:pointer}
.im-member:hover{background:var(--bg,#f6f3ee)}
</style>
<script src="/admin/assets/admin-internal-chat.js?v=<?= @filemtime(__DIR__ . '/assets/admin-internal-chat.js') ?: time() ?>"></script>
<?php if ($canCreateGroups): ?>
<script>
(function(){
  var btn=document.getElementById('imNewGroupBtn'), modal=document.getElementById('imNewGroup');
  if(!btn||!modal) return;
  function open(){ modal.classList.remove('is-hidden'); }
  function close(){ modal.classList.add('is-hidden'); }
  btn.addEventListener('click', open);
  modal.addEventListener('click', function(e){ if(e.target===modal||e.target.hasAttribute('data-im-close')) close(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
