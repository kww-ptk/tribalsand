<?php /** Workspace Messages tab. Expects $hold, $holdId. */ ?>
<?php
$__thr = $_GET['thread'] ?? null;
$__aid = ($__thr === null || $__thr === 'general') ? null : (int)$__thr;
$__inThread = $__thr !== null;
if ($__inThread) mark_thread_read_by_admin($holdId, $__aid);
?>
<?php if (!$__inThread): $__threads = fetch_message_threads($holdId); ?>
<div class="card"><div class="card__body" style="padding:0">
  <table class="data-table">
    <thead><tr><th>Thread</th><th>Latest</th><th>Unread</th></tr></thead>
    <tbody>
      <?php foreach ($__threads as $t): $tid = $t['addon_id'] === null ? 'general' : (int)$t['addon_id']; ?>
      <tr>
        <td><a href="?hold=<?= (int)$holdId ?>&tab=messages&thread=<?= e((string)$tid) ?>"><?= e(thread_title($t)) ?></a></td>
        <td class="text-muted" style="font-size:13px;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string)($t['last_body'] ?? '')) ?></td>
        <td><?php if ((int)($t['unread_admin'] ?? 0) > 0): ?><span class="badge badge--orange"><?= (int)$t['unread_admin'] ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php else: $__msgs = fetch_thread_messages($holdId, $__aid); ?>
<p style="margin:0 0 12px"><a href="?hold=<?= (int)$holdId ?>&tab=messages" class="btn-outline btn-sm">← All threads</a></p>
<div class="card"><div class="card__body">
  <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
    <?php if (!$__msgs): ?><p class="text-muted">No messages in this thread yet.</p><?php endif; ?>
    <?php foreach ($__msgs as $m): $am = $m['sender'] === 'admin'; ?>
    <div style="max-width:80%;<?= $am ? 'align-self:flex-end;background:#102F3A;color:#fff' : 'align-self:flex-start;background:#f3f4f6;color:#111' ?>;border-radius:12px;padding:9px 12px;font-size:14px;line-height:1.5">
      <?= e($m['body']) ?>
      <div style="font-size:11px;margin-top:4px;opacity:.7"><?= $am ? 'Staff' : 'Guest' ?> · <?= e(date('j M, H:i', strtotime((string)$m['created_at']))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="POST" action="/admin/booking.php">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= (int)$holdId ?>">
    <input type="hidden" name="action" value="reply">
    <input type="hidden" name="addon_id" value="<?= $__aid === null ? '' : (int)$__aid ?>">
    <textarea name="body" rows="3" required placeholder="Reply to the guest…" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:16px"></textarea>
    <button type="submit" class="btn-primary" style="margin-top:8px">Send reply</button>
  </form>
</div></div>
<?php endif; ?>
