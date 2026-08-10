<?php /** Workspace Messages tab. Expects $hold, $holdId. */ ?>
<?php
$__thr = $_GET['thread'] ?? null;
$__aid = ($__thr === null || $__thr === 'general') ? null : (int)$__thr;
$__inThread = $__thr !== null;
if ($__inThread) mark_thread_read_by_admin($holdId, $__aid);
?>
<?php if (!$__inThread): $__threads = fetch_message_threads($holdId); ?>
<div class="card"><div class="card__body" style="padding:0">
  <?php if (!$__threads): ?>
  <?php dt_empty('No conversations on this booking yet.', 'message'); ?>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th>Thread</th><th>Latest</th><th>Unread</th></tr></thead>
    <tbody>
      <?php foreach ($__threads as $t):
        $tid  = $t['addon_id'] === null ? 'general' : (int)$t['addon_id'];
        $__u  = (int)($t['unread_admin'] ?? 0);
        $__href = '?hold=' . (int)$holdId . '&tab=messages&thread=' . $tid;
      ?>
      <tr class="dt-rowlink<?= $__u > 0 ? ' is-unread' : '' ?>">
        <td><a href="<?= e($__href) ?>" data-ws-load data-skeleton="chat" class="thread-guest"><?= e(thread_title($t)) ?></a></td>
        <td class="text-muted" style="font-size:13px;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)($t['last_body'] ?? '')) ?></td>
        <td><?php if ($__u > 0): ?><span class="badge badge--orange"><?= $__u ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div></div>
<?php else: $__msgs = fetch_thread_messages($holdId, $__aid); ?>
<p style="margin:0 0 12px"><a href="?hold=<?= (int)$holdId ?>&tab=messages" data-ws-load data-skeleton="table" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> All threads</a></p>
<div class="card"><div class="card__body" style="padding:16px">
  <?php $__lastId = $__msgs ? (int)$__msgs[count($__msgs)-1]['id'] : 0; ?>
  <div id="amThread" class="am-thread"
       data-poll-url="/admin/messages-poll"
       data-hold="<?= (int)$holdId ?>"
       data-thread="<?= $__aid === null ? 'general' : (int)$__aid ?>"
       data-last="<?= $__lastId ?>">
    <p class="text-muted am-empty"<?= $__msgs ? ' style="display:none"' : '' ?>>No messages in this thread yet.</p>
    <?php foreach ($__msgs as $m): $am = $m['sender'] === 'admin'; ?>
    <div class="am-msg <?= $am ? 'am-msg--staff' : 'am-msg--guest' ?>" data-mid="<?= (int)$m['id'] ?>">
      <?= e($m['body']) ?>
      <div class="am-msg__meta"><?= $am ? 'Staff' : 'Guest' ?> · <?= e(message_time_label($m['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <form id="amForm" method="POST" action="/admin/booking.php" class="am-composer">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
    <input type="hidden" name="action" value="reply">
    <input type="hidden" name="addon_id" value="<?= $__aid === null ? '' : (int)$__aid ?>">
    <textarea name="body" rows="3" required placeholder="Reply to the guest…"></textarea>
    <div class="am-composer__actions">
      <button type="submit" class="btn-primary"><?= admin_icon('send', 15) ?> Send reply</button>
      <span class="am-status text-muted" aria-live="polite" style="font-size:13px"></span>
    </div>
  </form>
</div></div>
<?php endif; ?>
