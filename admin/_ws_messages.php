<?php /** Workspace Messages tab — the same list rows, bubbles and composer as Admin › Messages. Expects $hold, $holdId. */ ?>
<?php
require_once __DIR__ . '/../includes/messages-view.php';
$__thr = $_GET['thread'] ?? null;
$__aid = ($__thr === null || $__thr === 'general') ? null : (int)$__thr;
$__inThread = $__thr !== null;
if ($__inThread) mark_thread_read_by_admin($holdId, $__aid);
$__threads = fetch_message_threads($holdId);
$__gn = trim((string)($hold['guest_name'] ?? '')) ?: 'Guest';
$__tUrl = fn($aid) => '?hold=' . (int)$holdId . '&tab=messages&thread=' . ($aid === null ? 'general' : (int)$aid);
?>
<?php if (!$__inThread): ?>
<div class="mx-pane" style="max-width:860px">
  <?php
  $__any = false;
  foreach ($__threads as $t):
    $tid = $t['addon_id'] === null ? null : (int)$t['addon_id'];
    $__u = (int)($t['unread_admin'] ?? 0);
    if ($tid !== null && trim((string)($t['last_body'] ?? '')) === '' && $__u === 0) continue;   // a request nobody has written about yet
    $__any = true;
  ?>
  <a href="<?= e($__tUrl($tid)) ?>" data-ws-load data-skeleton="chat" class="mx-item<?= $__u > 0 ? ' is-unread' : '' ?>">
    <span class="mx-av mx-av--soft"><?= admin_icon('message', 15) ?></span>
    <span class="mx-item__body">
      <span class="mx-item__l1"><span class="mx-item__name"><?= e(thread_title($t)) ?></span><?php if ($__u > 0): ?><span class="mx-dot"><?= $__u ?></span><?php endif; ?><span class="mx-item__when"><?= e(mx_when($t['last_at'] ?? null)) ?></span></span>
      <span class="mx-item__pv"><?= e(trim((string)($t['last_body'] ?? '')) ?: 'No messages yet') ?></span>
    </span>
    <?php if (!empty($t['status'])): ?><?= mx_status_chip((string)$t['status']) ?><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php if (!$__any): ?>
  <div class="mx-empty"><?= admin_icon('message', 28) ?><span>No conversations on this booking yet.</span>
    <a href="<?= e($__tUrl(null)) ?>" data-ws-load data-skeleton="chat" class="btn-outline btn-sm">Write to <?= e(explode(' ', $__gn)[0]) ?></a></div>
  <?php endif; ?>
</div>
<?php else:
  $__msgs = [];
  foreach (fetch_thread_messages($holdId, $__aid) as $m) {
      $__msgs[] = ['id' => $m['id'], 'body' => $m['body'], 'created_at' => $m['created_at'], 'mine' => $m['sender'] === 'admin', 'who' => message_sender_label($m)];
  }
  $__lastId = $__msgs ? (int)$__msgs[count($__msgs) - 1]['id'] : 0;
  $__cur = null;
  foreach ($__threads as $t) if (($t['addon_id'] === null ? null : (int)$t['addon_id']) === $__aid) $__cur = $t;
?>
<div class="mx-pane" style="height:min(70vh,640px);min-height:440px">
  <div class="mx-convhead">
    <a href="?hold=<?= (int)$holdId ?>&tab=messages" data-ws-load data-skeleton="table" class="btn-icon btn-icon--outline" data-tip="All conversations" aria-label="All conversations"><?= admin_icon('arrow-left') ?></a>
    <?= mx_avatar($__gn, 'h' . (int)$holdId) ?>
    <div class="mx-convhead__t">
      <div class="mx-convhead__name"><?= e($__gn) ?></div>
      <div class="mx-convhead__sub"><?= e($__cur ? thread_title($__cur) : 'Message the team') ?><?= ($__cur && !empty($__cur['status'])) ? ' · ' . mx_status_chip((string)$__cur['status']) : '' ?></div>
    </div>
  </div>
  <?php if (count($__threads) > 1): ?>
  <nav class="mx-threads" aria-label="This booking's conversations">
    <?php foreach ($__threads as $bt): $btid = $bt['addon_id'] === null ? null : (int)$bt['addon_id']; ?>
    <a href="<?= e($__tUrl($btid)) ?>" data-ws-load data-skeleton="chat" class="<?= $btid === $__aid ? 'is-on' : '' ?>"><?= e($btid === null ? 'General' : ucfirst((string)($bt['kind'] ?? 'Request'))) ?><?php if ((int)($bt['unread_admin'] ?? 0) > 0 && $btid !== $__aid): ?><span class="mx-dot"><?= (int)$bt['unread_admin'] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div id="amThread" class="am-thread"
       data-poll-url="/admin/messages-poll"
       data-hold="<?= (int)$holdId ?>"
       data-thread="<?= $__aid === null ? 'general' : (int)$__aid ?>"
       data-last="<?= $__lastId ?>">
    <p class="am-empty"<?= $__msgs ? ' style="display:none"' : '' ?>>No messages in this conversation yet.</p>
    <?= mx_bubbles_html($__msgs) ?>
  </div>
  <?= mx_composer_html('/admin/booking.php', ['hold_id' => (int)$holdId, 'action' => 'reply', 'addon_id' => $__aid === null ? '' : (int)$__aid],
        'Reply to ' . explode(' ', $__gn)[0] . '…',
        ['Thanks — we’re on it.', 'All confirmed ✓', 'Someone from our team will be with you shortly.']) ?>
</div>
<?php endif; ?>
