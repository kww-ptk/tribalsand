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

// Resolve the active channel from ?channel=all|<venue_id>.
$channelRaw = $_GET['channel'] ?? 'all';
$activeVenue = ($channelRaw === 'all' || $channelRaw === '') ? null : (int)$channelRaw;
if ($activeVenue !== null && !internal_can_access_channel($activeVenue)) {
    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your channel.'];
    header('Location: /admin/internal-messages.php'); exit;
}

// ── POST: no-JS fallback to post a message (PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!internal_messages_supported()) { header('Location: /admin/internal-messages.php'); exit; }
    $pRaw   = $_POST['channel'] ?? 'all';
    $pVenue = ($pRaw === 'all' || $pRaw === '') ? null : (int)$pRaw;
    $body   = trim((string)($_POST['body'] ?? ''));
    if (internal_can_access_channel($pVenue) && $body !== '') {
        $id = post_internal_message($pVenue, $meId, $body);
        internal_mark_channel_read($meId, $pVenue, $id);
        audit_log('internal_message.post', 'venue', $pVenue ?? 0, '');
    }
    header('Location: /admin/internal-messages.php?channel=' . ($pVenue === null ? 'all' : $pVenue)); exit;
}

$channels = internal_messages_supported() ? internal_channels_for_user() : [];
$unread   = internal_messages_supported() ? internal_unread_by_channel($meId, $channels) : [];

// Active channel label + messages.
$activeLabel = 'All team';
foreach ($channels as $ch) { if ($ch['venue_id'] === $activeVenue) { $activeLabel = $ch['label']; break; } }
$msgs = internal_messages_supported() ? fetch_internal_messages($activeVenue) : [];
if ($msgs) internal_mark_channel_read($meId, $activeVenue, (int)end($msgs)['id']);
$lastId = $msgs ? (int)end($msgs)['id'] : 0;

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
    <div class="card__head"><span class="card__title">Channels</span></div>
    <div class="card__body" style="padding:8px">
      <?php foreach ($channels as $ch):
        $isActive = $ch['venue_id'] === $activeVenue;
        $u = (int)($unread[$ch['key']] ?? 0);
        $href = '/admin/internal-messages.php?channel=' . ($ch['venue_id'] === null ? 'all' : (int)$ch['venue_id']);
      ?>
      <a href="<?= e($href) ?>" data-shell-link class="im-channel<?= $isActive ? ' is-active' : '' ?>"
         style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 12px;border-radius:8px;text-decoration:none;margin-bottom:2px;font-size:14px;<?= $isActive ? 'background:var(--teal,#1E5C6B);color:#fff;font-weight:600' : 'color:var(--text,#222)' ?>">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $ch['venue_id'] === null ? '★ ' : '' ?><?= e($ch['label']) ?></span>
        <?php if ($u > 0): ?><span class="badge <?= $isActive ? 'badge--grey' : 'badge--orange' ?>"><?= $u ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Conversation -->
  <div class="card">
    <div class="card__body" style="padding:20px">
      <p style="margin:0 0 12px;font-weight:600"><?= e($activeLabel) ?>
        <span class="text-muted" style="font-weight:400">· <?= $activeVenue === null ? 'everyone on the team' : 'this property\'s team' ?></span>
      </p>
      <div id="amThread" class="am-thread"
           data-poll-url="/admin/internal-messages-poll"
           data-channel="<?= $activeVenue === null ? 'all' : (int)$activeVenue ?>"
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
        <input type="hidden" name="channel" value="<?= $activeVenue === null ? 'all' : (int)$activeVenue ?>">
        <textarea name="body" rows="3" required placeholder="Message the team…"></textarea>
        <div class="am-composer__actions">
          <button type="submit" class="btn-primary"><?= admin_icon('send', 15) ?> Send</button>
          <span class="am-status text-muted" aria-live="polite" style="font-size:13px"></span>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
@media (max-width:720px){ .im-wrap{grid-template-columns:1fr!important} .im-channels .card__body{display:flex;flex-wrap:wrap;gap:4px} }
</style>
<script src="/admin/assets/admin-internal-chat.js?v=<?= @filemtime(__DIR__ . '/assets/admin-internal-chat.js') ?: time() ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
