<?php
/**
 * Messages — ONE conversation per booking (guest page, design 2, Oct 2026).
 *
 * Every message of the booking, across all its request threads, in one stream;
 * each request appears as a card (status, when, price) at the moment it was made.
 * Staff still work per request: replying from a card ("Reply") sends the message
 * into that request's thread (addon_id), a plain message goes to the general
 * thread. The "+" button opens the services (housekeeping, laundry, transfer…) —
 * the same forms the old Request screen used.
 *
 * ?thread=<id> (old links, the "Manage request" button) opens the conversation
 * already replying about that request; ?add=1 opens the "+" menu.
 * Expects $hold, $ref, $status, $actor, $checkin_gate.
 */
$__hid   = (int)$hold['id'];
$__u     = '/booking.php?ref=' . urlencode($ref);
$__msgs  = fetch_conversation_since($__hid, 0);
mark_conversation_read_by_guest($__hid);
$__adds  = [];
try { $__adds = array_values(array_filter(fetch_booking_addons($__hid), fn($a) => ($a['status'] ?? '') !== 'cancelled')); } catch (Throwable $e) {}
$__labels = [];
foreach ($__adds as $__a) $__labels[(int)$__a['id']] = addon_label($__a);

// Pre-selected "replying about" (old ?thread=<id> links).
$__replyTo = null;
$__tp = (string)($_GET['thread'] ?? '');
if ($__tp !== '' && $__tp !== 'general' && isset($__labels[(int)$__tp])) $__replyTo = (int)$__tp;

// One time-ordered stream of messages and request cards.
$__stream = [];
foreach ($__msgs as $__m)  $__stream[] = ['t' => strtotime((string)$__m['created_at']), 'k' => 'm', 'o' => (int)$__m['id'], 'r' => $__m];
foreach ($__adds as $__a)  $__stream[] = ['t' => strtotime((string)$__a['created_at']), 'k' => 'a', 'o' => -1, 'r' => $__a];
usort($__stream, fn($x, $y) => [$x['t'], $x['k'] === 'a' ? 0 : 1, $x['o']] <=> [$y['t'], $y['k'] === 'a' ? 0 : 1, $y['o']]);
$__dayLabel = function (int $ts): string {
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('D j M', $ts);
};
$__lastId = $__msgs ? (int)$__msgs[count($__msgs) - 1]['id'] : 0;
$__myGid  = (int)($actor['guest_id'] ?? 0);
$__vname  = trim((string)($hold['venue_name'] ?? '')) ?: 'Tribal Sand';
$__active = in_array($status ?? '', ['pending', 'confirmed'], true);
$__kindIco = [
    'tour'     => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
    'transfer' => '<path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/>',
    'laundry'  => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="13" r="4"/>',
    'other'    => '<path d="M4 5h16v11H8l-4 4z"/>',
];
?>
<?php if (!empty($checkin_gate)): /* set in booking.php — check-in still owed */ ?>
<div class="pa-note pa-note--pend">Your check-in isn’t finished. <a href="<?= e($__u) ?>&amp;view=checkin&amp;resume=1">Resume check-in →</a></div>
<?php endif; ?>

<div class="pa-convo">
  <div class="pa-convo__head">
    <span class="pa-convo__av" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($__vname, 0, 2))) ?></span>
    <span><b>Your <?= e($__vname) ?> team</b><span>Ask anything, or tap + to request a service.</span></span>
  </div>

  <div id="bmThread" class="bm-thread pa-chat__thread pa-convo__stream"
       data-poll-url="/api/booking-message"
       data-ref="<?= e($ref) ?>"
       data-thread="all"
       data-me="guest"
       data-me-guest="<?= $__myGid ?>"
       data-labels="<?= e(json_encode((object)$__labels, JSON_UNESCAPED_UNICODE)) ?>"
       data-last="<?= $__lastId ?>">
    <p class="pa-sub bm-empty"<?= $__stream ? ' style="display:none"' : '' ?>>No messages yet. Say hello, or tap <b>+</b> to ask for housekeeping, laundry, a transfer or anything else.</p>
    <?php $__prevDay = ''; foreach ($__stream as $__it):
        $__day = $__dayLabel($__it['t']);
        if ($__day !== $__prevDay): $__prevDay = $__day; ?>
    <span class="pa-daysep" data-day="<?= e(date('Y-m-d', $__it['t'])) ?>"><?= e($__day) ?></span>
    <?php endif;
        if ($__it['k'] === 'a'):
            $__a = $__it['r'];
            [$__sl, $__st] = guest_extra_status_view((string)$__a['status']);
            $__ik = in_array($__a['kind'], ['tour', 'transfer', 'laundry'], true) ? $__a['kind'] : 'other';
            $__meta = [];
            if (!empty($__a['scheduled_for'])) { $__ts = strtotime((string)$__a['scheduled_for']); $__meta[] = date('D j M', $__ts) . (date('H:i', $__ts) !== '00:00' ? ' · ' . date('H:i', $__ts) : ''); }
            if ((int)($__a['pax'] ?? 0) > 0) $__meta[] = (int)$__a['pax'] . ' ' . ((int)$__a['pax'] === 1 ? 'person' : 'people');
            if (is_priced($__a['price_amount'] ?? null)) $__meta[] = format_price((float)$__a['price_amount']);
    ?>
    <div class="pa-reqcard" data-addon="<?= (int)$__a['id'] ?>">
      <span class="pa-reqcard__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $__kindIco[$__ik] ?></svg></span>
      <span class="pa-reqcard__t"><b><?= e(addon_label($__a)) ?></b><?php if ($__meta): ?><span><?= e(implode(' · ', $__meta)) ?></span><?php endif; ?></span>
      <span class="pa-reqcard__side">
        <?php if ($__sl !== ''): ?><span class="pa-pill pa-pill--<?= e($__st) ?>"><?= e($__sl) ?></span><?php endif; ?>
        <button type="button" class="pa-reqcard__reply" data-reply="<?= (int)$__a['id'] ?>">Reply</button>
      </span>
    </div>
    <?php else:
        $__m = $__it['r']; $__me = $__m['sender'] === 'guest';
        // Same label rule as js/booking-manage.js appendMsg(), so polled bubbles match.
        $__sgid = (int)($__m['sender_guest_id'] ?? 0);
        $__mine = $__me && (!$__myGid || !$__sgid || $__sgid === $__myGid);
        $__about = ($__m['addon_id'] ?? null) !== null && isset($__labels[(int)$__m['addon_id']]) ? $__labels[(int)$__m['addon_id']] : '';
    ?>
    <div class="bm-msg" data-mid="<?= (int)$__m['id'] ?>" style="max-width:80%;<?= $__me ? 'align-self:flex-end;background:var(--pa-teal-d);color:#fff;border-radius:12px 12px 2px 12px' : 'align-self:flex-start;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px 12px 12px 2px' ?>;padding:9px 12px;font-size:14px;line-height:1.5">
      <?php if ($__about !== ''): ?><div class="bm-about">About: <?= e($__about) ?></div><?php endif; ?>
      <?= e($__m['body']) ?>
      <div style="font-size:11px;margin-top:4px;<?= $__me ? 'color:rgba(255,255,255,.7)' : 'color:var(--pa-muted)' ?>"><?= !$__me ? 'Concierge' : ($__mine ? 'You' : e(attributed_display_name((string)($__m['sender_name'] ?? ''), !empty($__m['sender_is_lead']), (string)($__m['hold_guest_name'] ?? '')))) ?> · <?= e(date('H:i', strtotime((string)$__m['created_at']))) ?></div>
    </div>
    <?php endif; endforeach; ?>
  </div>
</div>

<form data-chat action="/api/booking-message.php" class="pa-chat__composer pa-convo__composer">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="addon_id" value="<?= $__replyTo ?? '' ?>" data-reply-input>
  <div class="pa-replychip" data-reply-chip<?= $__replyTo ? '' : ' hidden' ?>>
    <span>Replying about <b data-reply-label><?= $__replyTo ? e($__labels[$__replyTo]) : '' ?></b></span>
    <button type="button" data-reply-clear aria-label="Stop replying about this request">×</button>
  </div>
  <div class="pa-convo__row">
    <?php if ($__active): ?><button type="button" class="pa-convo__plus" data-plus-open aria-label="Request a service" aria-haspopup="dialog">+</button><?php endif; ?>
    <textarea name="body" rows="1" required placeholder="Message the team…" aria-label="Your message"></textarea>
    <button type="submit" class="pa-chat__send" aria-label="Send message">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
    </button>
  </div>
  <p class="bm-status" aria-live="polite"></p>
</form>

<?php if ($__active): ?>
<div class="pa-sheet" id="paPlus" hidden<?= !empty($_GET['add']) ? ' data-open-on-load' : '' ?>>
  <div class="pa-sheet__back" data-plus-close></div>
  <div class="pa-sheet__panel" role="dialog" aria-modal="true" aria-label="Request a service">
    <div class="pa-sheet__grab" aria-hidden="true"></div>
    <button type="button" class="pa-sheet__x" data-plus-close aria-label="Close">×</button>
    <?php include __DIR__ . '/_services.php'; ?>
    <a class="pa-btn" style="margin-top:10px" href="<?= e($__u) ?>&amp;view=extras">Browse extras — massage, activities, transfers</a>
  </div>
</div>
<?php endif; ?>
