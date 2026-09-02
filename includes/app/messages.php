<?php /** Messages view. Expects $hold, $ref. */ ?>
<?php
$__hid = (int)$hold['id'];
$__threadParam = $_GET['thread'] ?? null;               // 'general' | "<addon_id>" | null (list)
$__u = '/booking.php?ref=' . urlencode($ref) . '&amp;view=messages';

if ($__threadParam === null):
    // ── Thread list ──
    $__threads = fetch_message_threads($__hid);
?>
<h2 class="pa-h2">Messages</h2>
<p class="pa-sub">Chat with our team about your requests.</p>
<?php if (!empty($checkin_gate)): /* set in booking.php — check-in still owed */ ?>
<div class="pa-card" style="margin-bottom:14px">
  <div class="pa-card__body">
    <p class="pa-card__title">Your check-in isn’t finished</p>
    <p class="pa-card__meta" style="display:block;margin:3px 0 10px">Nothing you’ve filled in is lost — pick up exactly where you left off.</p>
    <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= urlencode($ref) ?>&amp;view=checkin&amp;resume=1">Resume check-in &rarr;</a>
  </div>
</div>
<?php endif; ?>
<?php foreach ($__threads as $__th):
    $__tid   = $__th['addon_id'] === null ? 'general' : (int)$__th['addon_id'];
    $__title = thread_title($__th);
    $__snip  = trim((string)($__th['last_body'] ?? ''));
?>
<a class="pa-card" style="display:block;text-decoration:none;color:inherit" href="<?= $__u ?>&amp;thread=<?= e((string)$__tid) ?>">
  <div class="pa-card__body" style="display:flex;align-items:center;gap:10px">
    <div style="flex:1;min-width:0">
      <p class="pa-card__title"><?= e($__title) ?></p>
      <p class="pa-card__meta" style="display:block;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $__snip !== '' ? e($__snip) : '<span style="color:var(--pa-muted)">No messages yet — say hello</span>' ?></p>
    </div>
    <?php if (($__th['addon_id'] ?? null) !== null && !empty($__th['status'])): ?><span class="pa-pill pa-pill--<?= e($__th['status']) ?>"><?= e(addon_status_label($__th['status'])) ?></span><?php endif; ?>
    <?php if (($__th['unread_guest'] ?? 0) > 0): ?><span class="pa-nav__badge" style="position:static"><?= (int)$__th['unread_guest'] ?></span><?php endif; ?>
  </div>
</a>
<?php endforeach; ?>

<?php else:
    // ── Conversation ──
    $__addonId = $__threadParam === 'general' ? null : (int)$__threadParam;
    if ($__addonId !== null) {
        $__own = db_query("SELECT 1 FROM booking_addons WHERE id=:a AND hold_id=:h", [':a'=>$__addonId, ':h'=>$__hid])->fetchColumn();
        if (!$__own) { echo '<p class="pa-sub">Conversation not found.</p>'; return; }
    }
    mark_thread_read_by_guest($__hid, $__addonId);
    $__msgs = fetch_thread_messages($__hid, $__addonId);
?>
<p style="margin:0 0 14px;display:flex;gap:14px;flex-wrap:wrap;align-items:center">
  <a href="<?= $__u ?>" class="pa-back">&larr; All messages</a>
  <?php if (!empty($checkin_gate)): /* keep the way back to an unfinished check-in */ ?>
  <a href="/booking.php?ref=<?= urlencode($ref) ?>&amp;view=checkin&amp;resume=1" class="pa-back">Resume check-in &rarr;</a>
  <?php endif; ?>
</p>
<?php if ($__addonId !== null && ($__addon = fetch_addon_for_thread($__hid, $__addonId))): ?>
<div class="pa-card" style="margin-bottom:14px">
  <div class="pa-card__body">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <p class="pa-card__title" style="margin:0"><?= e(addon_label($__addon)) ?></p>
      <?php if (!empty($__addon['status'])): ?><span class="pa-pill pa-pill--<?= e($__addon['status']) ?>"><?= e(addon_status_label($__addon['status'])) ?></span><?php endif; ?>
    </div>
    <?php
      $__meta = [];
      if (($__addon['kind'] ?? '') === 'tour' && (int)($__addon['pax'] ?? 0) > 0) $__meta[] = (int)$__addon['pax'] . ' pax';
      if (!empty($__addon['scheduled_for'])) $__meta[] = date('D j M, H:i', strtotime((string)$__addon['scheduled_for']));
      if (isset($__addon['price_amount']) && $__addon['price_amount'] !== null && (float)$__addon['price_amount'] > 0) $__meta[] = format_price((float)$__addon['price_amount']);
    ?>
    <?php if ($__meta): ?><p class="pa-card__meta" style="display:block;margin-top:4px;color:var(--pa-muted)"><?= e(implode(' · ', $__meta)) ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php $__lastId = $__msgs ? (int)$__msgs[count($__msgs)-1]['id'] : 0; ?>
<div id="bmThread" class="bm-thread pa-chat__thread"
     data-poll-url="/api/booking-message"
     data-ref="<?= e($ref) ?>"
     data-thread="<?= $__addonId === null ? 'general' : (int)$__addonId ?>"
     data-me="guest"
     data-me-guest="<?= (int)($actor['guest_id'] ?? 0) ?>"
     data-last="<?= $__lastId ?>">
  <p class="pa-sub bm-empty"<?= $__msgs ? ' style="display:none"' : '' ?>>No messages yet. Send the first one below.</p>
  <?php foreach ($__msgs as $__m): $__me = $__m['sender'] === 'guest';
        // On a shared booking every guest message is sender='guest'. Bubble
        // alignment still keys on that, but the LABEL distinguishes mine from
        // a co-guest's, which is the whole point of attribution.
        // When either id is unavailable (pre-migration, no sender_guest_id) fall
        // back to treating it as mine — 'You', today's behaviour. This mirrors the
        // same fallback in js/booking-manage.js so the server render and the live
        // poll never disagree.
        $__myGid = (int)($actor['guest_id'] ?? 0);
        $__sgid  = (int)($__m['sender_guest_id'] ?? 0);
        $__mine  = $__me && (!$__myGid || !$__sgid || $__sgid === $__myGid); ?>
  <div class="bm-msg" data-mid="<?= (int)$__m['id'] ?>" style="max-width:80%;<?= $__me ? 'align-self:flex-end;background:var(--pa-teal-d);color:#fff;border-radius:12px 12px 2px 12px' : 'align-self:flex-start;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px 12px 12px 2px' ?>;padding:9px 12px;font-size:14px;line-height:1.5">
    <?= e($__m['body']) ?>
    <div style="font-size:11px;margin-top:4px;<?= $__me ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)' ?>"><?= !$__me ? 'Concierge' : ($__mine ? 'You' : e(attributed_display_name((string)($__m['sender_name'] ?? ''), !empty($__m['sender_is_lead']), (string)($__m['hold_guest_name'] ?? '')))) ?> · <?= e(message_time_label($__m['created_at'])) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<form data-chat action="/api/booking-message.php" class="pa-chat__composer">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="addon_id" value="<?= $__addonId === null ? '' : (int)$__addonId ?>">
  <textarea name="body" rows="1" required placeholder="Type a message…" aria-label="Your message"></textarea>
  <button type="submit" class="pa-chat__send" aria-label="Send message">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
  </button>
  <p class="bm-status" aria-live="polite"></p>
</form>
<?php endif; ?>
