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

// Open (or start) a 1:1 direct message with a teammate (Item 6 — "Message" button).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['dm'])) {
    $other = (int)$_GET['dm'];
    if (!internal_group_channels_supported()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Direct messages need the add_internal_group_channels migration.'];
        header('Location: /admin/internal-messages.php'); exit;
    }
    $gid = internal_direct_channel($meId, $other);
    if ($gid > 0) { header('Location: /admin/internal-messages.php?channel=g' . $gid); exit; }
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Could not open a chat with that person.'];
    header('Location: /admin/internal-messages.php'); exit;
}

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
// Members of the active group (shown in the side panel).
$__groupMembers = $activeGroup ? fetch_internal_group_members($activeGroup) : [];

// Desk side panel: today's arrivals / in-house / departures for the channel's
// property (all-team: every property the account sees) — the Front desk numbers.
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/messages-view.php';
$__scope = admin_venue_ids();
$__dayVenues = $activeVenue !== null ? [(int)$activeVenue] : $__scope;
try { $__today = frontdesk_day($__dayVenues, frontdesk_today_ymd()); } catch (Throwable $e) { $__today = null; }
$__teamCount = $activeGroup ? count($__groupMembers)
    : (int)(db_query("SELECT COUNT(*) FROM admin_users WHERE is_active = TRUE")->fetchColumn() ?: 0);
$__previews = internal_channel_previews($channels);
$__chIcon = fn(array $ch): string => !empty($ch['group_id']) ? admin_icon('message', 15) : (($ch['venue_id'] ?? null) === null ? admin_icon('users', 15) : admin_icon('home', 15));

include __DIR__ . '/_layout.php';
?>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!internal_messages_supported()): ?>
<div class="card"><div class="card__body card__body--pad">
  <p class="text-muted" style="margin:0">Team chat is unavailable. Run the <code>add_internal_messages.sql</code> migration to enable it.</p>
</div></div>
<?php else: ?>

<div class="mx<?= isset($_GET['channel']) ? ' is-thread' : '' ?>" data-mx>
  <!-- Channels -->
  <section class="mx-pane" aria-label="Channels">
    <div class="mx-pane__head">
      <div class="mx-search">
        <?= admin_icon('search', 15) ?>
        <input type="search" placeholder="Find a channel" aria-label="Find a channel" data-mx-filter>
      </div>
      <?php if ($canCreateGroups): ?>
      <div class="mx-row"><span class="text-muted" style="font-size:12px"><?= count($channels) ?> channels</span>
        <button type="button" class="btn-outline btn-sm" id="imNewGroupBtn"><?= admin_icon('plus', 14) ?> New group</button></div>
      <?php endif; ?>
    </div>
    <div class="mx-list" data-mx-list>
      <?php $__lastKind = ''; foreach ($channels as $ch):
        $isGroup  = !empty($ch['group_id']);
        $kind     = $isGroup ? 'Group chats' : 'Channels';
        if ($kind !== $__lastKind): $__lastKind = $kind; ?>
        <div class="mx-sec"><?= e($kind) ?></div>
        <?php endif;
        $isActive = ($ch['venue_id'] ?? null) === $activeVenue && ($ch['group_id'] ?? null) === $activeGroup;
        $u    = (int)($unread[$ch['key']] ?? 0);
        $pv   = $__previews[(int)$ch['key']] ?? null;
        $who  = $pv ? (trim((string)($pv['sender_name'] ?? '')) ?: 'Team') : '';
      ?>
      <a href="/admin/internal-messages.php?channel=<?= e(internal_channel_addr($ch['venue_id'] ?? null, $ch['group_id'] ?? null)) ?>"
         class="mx-item<?= $isActive ? ' is-on' : '' ?><?= $u ? ' is-unread' : '' ?>"<?= $isActive ? ' aria-current="true"' : '' ?> data-mx-name="<?= e(mb_strtolower((string)$ch['label'])) ?>">
        <span class="mx-av<?= ($ch['venue_id'] ?? null) === null && !$isGroup ? '' : ' mx-av--soft' ?>"><?= $__chIcon($ch) ?></span>
        <span class="mx-item__body">
          <span class="mx-item__l1"><span class="mx-item__name"><?= e($ch['label']) ?></span><?php if ($u): ?><span class="mx-dot"><?= $u ?></span><?php endif; ?><span class="mx-item__when"><?= $pv ? e(mx_when($pv['created_at'])) : '' ?></span></span>
          <span class="mx-item__pv"><?= $pv ? e($who . ': ' . $pv['body']) : 'No messages yet' ?></span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Conversation -->
  <section class="mx-pane" aria-label="Conversation">
    <div class="mx-convhead">
      <a href="/admin/internal-messages.php" class="btn-icon btn-icon--outline mx-back" aria-label="All channels"><?= admin_icon('arrow-left') ?></a>
      <span class="mx-av<?= $activeVenue === null && !$activeGroup ? '' : ' mx-av--soft' ?>"><?= $activeGroup ? admin_icon('message', 15) : ($activeVenue === null ? admin_icon('users', 15) : admin_icon('home', 15)) ?></span>
      <div class="mx-convhead__t">
        <div class="mx-convhead__name"><?= e($activeLabel) ?></div>
        <div class="mx-convhead__sub"><?php
          if ($activeGroup) echo e($__teamCount . ' member' . ($__teamCount === 1 ? '' : 's'));
          else echo $activeVenue === null ? 'Everyone on the team' : 'This property’s team';
        ?></div>
      </div>
    </div>
    <div id="amThread" class="am-thread"
         data-poll-url="/admin/internal-messages-poll"
         data-channel="<?= e($activeAddr) ?>"
         data-last="<?= $lastId ?>">
      <p class="am-empty"<?= $msgs ? ' style="display:none"' : '' ?>>No messages in this channel yet. Say hello 👋</p>
      <?= mx_bubbles_html(array_map(fn($m) => [
            'id' => $m['id'], 'body' => $m['body'], 'created_at' => $m['created_at'],
            'mine' => (int)($m['sender_admin_id'] ?? 0) === $meId,
            'who' => trim((string)($m['sender_name'] ?? '')) ?: 'Team',
          ], $msgs)) ?>
    </div>
    <?= mx_composer_html('', ['channel' => $activeAddr], 'Message ' . $activeLabel . '…') ?>
  </section>

  <!-- About this channel -->
  <aside class="mx-pane mx-pane--ctx" aria-label="About this channel">
    <div class="mx-ctx">
      <div>
        <h4 style="margin-bottom:8px"><?= e($activeLabel) ?></h4>
        <p class="text-muted" style="font-size:12.5px;margin:0"><?php
          if ($activeGroup) echo 'A group chat. Only its members can read it.';
          elseif ($activeVenue === null) echo 'Everyone on the team can read this channel — ' . (int)$__teamCount . ' people.';
          else echo 'For the team at ' . e($activeLabel) . '.';
        ?></p>
      </div>

      <?php if ($activeGroup && $__groupMembers): ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <h4>Members · <?= count($__groupMembers) ?></h4>
        <?php foreach ($__groupMembers as $gm): $gmn = trim((string)($gm['name'] ?? '')) ?: (string)($gm['email'] ?? ''); ?>
        <div class="mx-person"><?= mx_avatar($gmn, 'u' . (int)$gm['id'], true) ?><span><?= e($gmn) ?><?= (int)$gm['id'] === $meId ? ' <span class="text-muted">(you)</span>' : '' ?></span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($__today !== null && !$activeGroup && $__navOn('frontdesk', 'frontdesk.php')): ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <h4>Today<?= $activeVenue !== null ? ' at ' . e($activeLabel) : '' ?></h4>
        <div class="mx-stat">
          <div><b><?= count($__today['arriving']) ?></b><span>Arriving</span></div>
          <div><b><?= count($__today['inhouse']) ?></b><span>In house</span></div>
          <div><b><?= count($__today['departing']) ?></b><span>Leaving</span></div>
        </div>
        <?php if ($__today['arriving']): ?>
        <div>
          <?php foreach (array_slice($__today['arriving'], 0, 5) as $ar): ?>
          <div class="mx-req"><span><?= e(trim((string)($ar['guest_name'] ?? '')) ?: 'Guest') ?></span><span class="text-muted" style="font-size:11.5px"><?= e((string)($ar['room_name'] ?? '')) ?></span></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <a href="/admin/frontdesk.php" class="btn-outline btn-sm" style="align-self:flex-start">Open Front desk</a>
      </div>
      <?php endif; ?>
    </div>
  </aside>
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
