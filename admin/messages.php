<?php
/**
 * Admin: guest ↔ staff messages — the "Desk" (design C, Oct 2026):
 *   list of conversations · the open conversation · the guest's stay.
 * Opening a conversation is a shell swap (no reload); replies post through
 * admin-chat.js (JSON, live poll). The POST below is the no-JS fallback (PRG).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/messages-view.php';
require_login();
require_frontdesk();   // ops & gate-security have a focused interface with no messaging

$pageTitle  = 'Customer messages';
$activeMenu = 'messages';

$holdId  = isset($_GET['hold']) ? (int)$_GET['hold'] : 0;
$threadP = $_GET['thread'] ?? null;
$addonId = ($threadP === null || $threadP === 'general') ? null : (int)$threadP;
$picked  = $holdId > 0 && $threadP !== null;   // a conversation the person opened

if ($picked && !is_owner() && !staff_can_hold($holdId)) {
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

// ── The list: filter (All · Unread · Requests), property, search ─────────────
$view   = in_array($_GET['view'] ?? '', ['unread', 'requests'], true) ? $_GET['view'] : '';
$fVenue = (int)($_GET['venue'] ?? 0);
$q      = trim((string)($_GET['q'] ?? ''));

$allThreads = fetch_admin_threads(admin_venue_ids());
$venueOpts = [];
foreach ($allThreads as $t) {
    $vid = (int)($t['venue_id'] ?? 0);
    if ($vid > 0 && !isset($venueOpts[$vid])) $venueOpts[$vid] = (string)($t['venue_name'] ?? '');
}
asort($venueOpts, SORT_NATURAL | SORT_FLAG_CASE);
if ($fVenue > 0 && !isset($venueOpts[$fVenue])) $fVenue = 0;   // stale/foreign id → All
$unreadTotal = count(array_filter($allThreads, fn($t) => (int)$t['unread_admin'] > 0));

$threads = array_values(array_filter($allThreads, function ($t) use ($view, $fVenue, $q) {
    if ($fVenue > 0 && (int)($t['venue_id'] ?? 0) !== $fVenue) return false;
    if ($view === 'unread' && (int)$t['unread_admin'] <= 0) return false;
    if ($view === 'requests' && $t['addon_id'] === null) return false;
    if ($q !== '') {
        $hay = mb_strtolower(($t['guest_name'] ?? '') . ' ' . thread_title($t) . ' ' . ($t['last_body'] ?? '') . ' ' . ($t['venue_name'] ?? ''));
        if (mb_strpos($hay, mb_strtolower($q)) === false) return false;
    }
    return true;
}));

// The open conversation: the one asked for, else (wide screens) the first in the list.
if (!$picked && $threads) {
    $holdId  = (int)$threads[0]['hold_id'];
    $addonId = $threads[0]['addon_id'] === null ? null : (int)$threads[0]['addon_id'];
}
$hasThread = $holdId > 0;
if ($picked) mark_thread_read_by_admin($holdId, $addonId);

// Keep the list's filters on every link the page draws.
$keep = array_filter(['view' => $view, 'venue' => $fVenue ?: null, 'q' => $q !== '' ? $q : null], fn($v) => $v !== null && $v !== '');
$url  = function (array $set = []) use ($keep): string {
    $p = array_merge($keep, $set);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return '/admin/messages.php' . ($p ? '?' . http_build_query($p) : '');
};
$threadUrl = fn(int $h, $a): string => $url(['hold' => $h, 'thread' => $a === null ? 'general' : (int)$a]);

// ── Context for the open conversation ───────────────────────────────────────
$hold = null; $msgs = []; $bookingThreads = []; $requests = []; $thisAddon = null;
if ($hasThread) {
    $hold = db_query(
        "SELECT h.*, r.name AS room_name, v.name AS venue_name
           FROM holds h
           JOIN units u ON u.id = h.unit_id
           JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
           LEFT JOIN venues v ON v.id = r.venue_id
          WHERE h.id = :h",
        [':h' => $holdId]
    )->fetch() ?: null;
    if ($hold) {
        $guestName = trim((string)$hold['guest_name']) ?: 'Guest';
        foreach (fetch_thread_messages($holdId, $addonId) as $m) {
            $msgs[] = ['id' => $m['id'], 'body' => $m['body'], 'created_at' => $m['created_at'],
                       'mine' => $m['sender'] === 'admin', 'who' => message_sender_label($m)];
        }
        $bookingThreads = fetch_message_threads($holdId);
        $requests = fetch_booking_addons($holdId);
        foreach ($requests as $r) if ($addonId !== null && (int)$r['id'] === $addonId) $thisAddon = $r;
    } else {
        $hasThread = false;
    }
}
$thisTitle = $hasThread ? thread_title($addonId === null ? ['addon_id' => null] : ($thisAddon ? ['addon_id' => $addonId] + $thisAddon : ['addon_id' => $addonId])) : '';

include __DIR__ . '/_layout.php';
?>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="mx<?= $picked ? ' is-thread' : '' ?>" data-mx>
  <!-- Conversations -->
  <section class="mx-pane" aria-label="Conversations">
    <div class="mx-pane__head">
      <form method="GET" action="/admin/messages.php" class="mx-search" role="search">
        <?= admin_icon('search', 15) ?>
        <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
        <?php if ($fVenue): ?><input type="hidden" name="venue" value="<?= (int)$fVenue ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search guest, property or message" aria-label="Search conversations">
      </form>
      <div class="mx-row">
        <nav class="mx-seg" aria-label="Show">
          <a href="<?= e($url(['view' => null])) ?>" class="<?= $view === '' ? 'is-on' : '' ?>">All</a>
          <a href="<?= e($url(['view' => 'unread'])) ?>" class="<?= $view === 'unread' ? 'is-on' : '' ?>">Unread<?= $unreadTotal ? ' ' . $unreadTotal : '' ?></a>
          <a href="<?= e($url(['view' => 'requests'])) ?>" class="<?= $view === 'requests' ? 'is-on' : '' ?>">Requests</a>
        </nav>
        <?php if (count($venueOpts) > 1): ?>
        <form method="GET" action="/admin/messages.php">
          <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
          <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
          <select name="venue" class="filter-select" aria-label="Property" onchange="this.form.submit()">
            <option value="0">All properties</option>
            <?php foreach ($venueOpts as $vid => $vname): ?>
            <option value="<?= (int)$vid ?>" <?= $fVenue === (int)$vid ? 'selected' : '' ?>><?= e($vname) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="mx-list" data-mx-list>
      <?php if (!$threads): ?>
        <div class="mx-empty"><?= admin_icon('message', 28) ?><span><?= ($q !== '' || $view !== '' || $fVenue) ? 'No conversations match.' : 'No messages yet.' ?></span></div>
      <?php else:
        $shownSec = '';
        foreach ($threads as $t):
          $u   = (int)$t['unread_admin'];
          $sec = $u > 0 ? 'Needs a reply' : 'Earlier';
          if ($sec !== $shownSec && $view !== 'unread'): $shownSec = $sec; ?>
          <div class="mx-sec"><?= e($sec) ?></div>
          <?php endif;
          $tid = $t['addon_id'] === null ? null : (int)$t['addon_id'];
          $on  = $hasThread && (int)$t['hold_id'] === $holdId && $tid === $addonId;
          $gn  = trim((string)$t['guest_name']) ?: 'Guest';
      ?>
        <a href="<?= e($threadUrl((int)$t['hold_id'], $tid)) ?>" class="mx-item<?= $on ? ' is-on' : '' ?><?= $u ? ' is-unread' : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
          <?= mx_avatar($gn, 'h' . (int)$t['hold_id']) ?>
          <span class="mx-item__body">
            <span class="mx-item__l1"><span class="mx-item__name"><?= e($gn) ?></span><?php if ($u): ?><span class="mx-dot"><?= $u ?></span><?php endif; ?><span class="mx-item__when"><?= e(mx_when($t['last_at'] ?? null)) ?></span></span>
            <span class="mx-item__sub"><?= e(trim((string)($t['venue_name'] ?? '')) !== '' ? $t['venue_name'] . ' · ' : '') ?><?= e(thread_title($t)) ?></span>
            <span class="mx-item__pv"><?= e((string)($t['last_body'] ?? '')) ?></span>
          </span>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <!-- Conversation -->
  <section class="mx-pane" aria-label="Conversation">
    <?php if (!$hasThread): ?>
      <div class="mx-empty"><?= admin_icon('message', 34) ?><strong>No conversation open</strong><span>Guests write from their booking page. Their messages show up here.</span></div>
    <?php else:
      $gn = trim((string)$hold['guest_name']) ?: 'Guest';
      $lastId = $msgs ? (int)$msgs[count($msgs) - 1]['id'] : 0; ?>
      <div class="mx-convhead">
        <a href="<?= e($url()) ?>" class="btn-icon btn-icon--outline mx-back" aria-label="All conversations"><?= admin_icon('arrow-left') ?></a>
        <?= mx_avatar($gn, 'h' . $holdId) ?>
        <div class="mx-convhead__t">
          <div class="mx-convhead__name"><?= e($gn) ?></div>
          <div class="mx-convhead__sub"><?= e($thisTitle) ?><?= $thisAddon ? ' · ' : '' ?><?= $thisAddon ? mx_status_chip((string)$thisAddon['status']) : '' ?></div>
        </div>
        <a href="/admin/booking.php?hold=<?= $holdId ?>" class="btn-outline btn-sm"><?= admin_icon('external-link', 14) ?> Open booking</a>
      </div>
      <?php if (count($bookingThreads) > 1): ?>
      <nav class="mx-threads" aria-label="This booking's conversations">
        <?php foreach ($bookingThreads as $bt): $btid = $bt['addon_id'] === null ? null : (int)$bt['addon_id']; ?>
        <a href="<?= e($threadUrl($holdId, $btid)) ?>" class="<?= $btid === $addonId ? 'is-on' : '' ?>"><?= e($btid === null ? 'General' : ucfirst((string)($bt['kind'] ?? 'Request'))) ?><?php if ((int)($bt['unread_admin'] ?? 0) > 0 && $btid !== $addonId): ?><span class="mx-dot"><?= (int)$bt['unread_admin'] ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
      <div id="amThread" class="am-thread"
           data-poll-url="/admin/messages-poll"
           data-hold="<?= $holdId ?>"
           data-thread="<?= $addonId === null ? 'general' : $addonId ?>"
           data-last="<?= $lastId ?>">
        <p class="am-empty"<?= $msgs ? ' style="display:none"' : '' ?>>No messages in this conversation yet.</p>
        <?= mx_bubbles_html($msgs) ?>
      </div>
      <?= mx_composer_html('', ['hold_id' => $holdId, 'addon_id' => $addonId === null ? '' : $addonId],
            'Reply to ' . explode(' ', $gn)[0] . '…',
            ['Thanks — we’re on it.', 'All confirmed ✓', 'Could you share your flight number?', 'Someone from our team will be with you shortly.']) ?>
    <?php endif; ?>
  </section>

  <!-- The guest's stay -->
  <?php if ($hasThread): $n = max(0, (int)((strtotime((string)$hold['check_out']) - strtotime((string)$hold['check_in'])) / 86400)); ?>
  <aside class="mx-pane mx-pane--ctx" aria-label="Stay">
    <div class="mx-ctx">
      <div class="mx-person"><?= mx_avatar(trim((string)$hold['guest_name']) ?: 'Guest', 'h' . $holdId) ?><span><strong><?= e(trim((string)$hold['guest_name']) ?: 'Guest') ?></strong><br><span class="text-muted" style="font-size:12px"><?= e((string)($hold['guest_email'] ?? '')) ?></span></span></div>
      <dl class="mx-kv">
        <dt>Property</dt><dd><?= e((string)($hold['venue_name'] ?? '—')) ?></dd>
        <dt>Room</dt><dd><?= e((string)($hold['room_name'] ?? '—')) ?></dd>
        <dt>Stay</dt><dd><?= e(date('j M', strtotime((string)$hold['check_in'])) . ' – ' . date('j M', strtotime((string)$hold['check_out']))) ?> · <?= $n ?> <?= $n === 1 ? 'night' : 'nights' ?></dd>
        <?php if (!empty($hold['guest_count'])): ?><dt>Guests</dt><dd><?= (int)$hold['guest_count'] ?></dd><?php endif; ?>
        <dt>Status</dt><dd><span class="badge badge--<?= ['pending'=>'orange','confirmed'=>'green','cancelled'=>'red','expired'=>'grey'][$hold['status']] ?? 'grey' ?>"><?= e((string)$hold['status']) ?></span></dd>
        <?php if (trim((string)($hold['access_code'] ?? '')) !== ''): ?><dt>Guest code</dt><dd><code><?= e((string)$hold['access_code']) ?></code></dd><?php endif; ?>
      </dl>

      <?php if ($thisAddon && in_array($thisAddon['status'], ['requested', 'confirmed'], true)): ?>
      <div>
        <h4 style="margin-bottom:8px">This request</h4>
        <div class="mx-actions">
          <form method="POST" action="/admin/booking-request-action.php" data-shell-form>
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$thisAddon['id'] ?>">
            <input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="return" value="messages">
            <?php if ($thisAddon['status'] === 'requested'): ?>
            <button type="submit" name="status" value="confirmed" class="btn-primary btn-sm"><?= admin_icon('check', 14) ?> Confirm</button>
            <?php endif; ?>
            <button type="submit" name="status" value="completed" class="btn-outline btn-sm"><?= admin_icon('check-check', 14) ?> Mark done</button>
          </form>
          <?php if ($thisAddon['status'] === 'requested'): ?>
          <form method="POST" action="/admin/booking-request-action.php" data-shell-form>
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$thisAddon['id'] ?>">
            <input type="hidden" name="hold_id" value="<?= $holdId ?>"><input type="hidden" name="return" value="messages">
            <button type="submit" name="status" value="declined" class="btn-outline btn-sm" data-confirm="Decline this request? The guest sees it as declined." data-confirm-title="Decline request" data-confirm-label="Decline"><?= admin_icon('x', 14) ?> Decline</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div>
        <h4 style="margin-bottom:4px">Requests<?= $requests ? ' · ' . count($requests) : '' ?></h4>
        <?php if (!$requests): ?>
        <p class="text-muted" style="font-size:12.5px;margin:6px 0 0">No requests on this booking.</p>
        <?php else: foreach ($requests as $r): ?>
        <div class="mx-req"><span title="<?= e(addon_label($r)) ?>"><?= e(ucfirst((string)$r['kind'])) ?><?= addon_label($r) !== '' ? ' · ' . e(addon_label($r)) : '' ?></span><?= mx_status_chip((string)$r['status']) ?></div>
        <?php endforeach; endif; ?>
      </div>

      <div class="mx-actions">
        <a href="/admin/booking.php?hold=<?= $holdId ?>" class="btn-primary btn-sm"><?= admin_icon('external-link', 14) ?> Open booking</a>
        <a href="/admin/booking.php?hold=<?= $holdId ?>&amp;tab=requests" class="btn-outline btn-sm">All requests</a>
      </div>
    </div>
  </aside>
  <?php endif; ?>
</div>
<script>
(function () {
  // Keep the conversation list where it was when a conversation is opened (the
  // shell swaps the whole page content, list included).
  var list = document.querySelector('[data-mx-list]');
  if (!list) return;
  try { var y = sessionStorage.getItem('mx_list_y'); if (y !== null) list.scrollTop = +y; } catch (e) {}
  list.addEventListener('click', function () { try { sessionStorage.setItem('mx_list_y', String(list.scrollTop)); } catch (e) {} });
  var on = list.querySelector('.mx-item.is-on');
  if (on && (on.offsetTop < list.scrollTop || on.offsetTop > list.scrollTop + list.clientHeight - 40)) list.scrollTop = on.offsetTop - 60;
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
