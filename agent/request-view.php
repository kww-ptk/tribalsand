<?php
declare(strict_types=1);
/**
 * Trade portal — one request in detail: the facts, the live status, and a
 * live-polling conversation with reservations (no page refresh — see
 * js/submission-thread.js + api/submission-thread.php). Ownership is re-checked
 * with the same signed filter the list uses (agent_fetch_request). The agent sees
 * ONLY staff replies and their own messages, never internal staff notes.
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/booking.php';          // make_manage_url()
require_once __DIR__ . '/../includes/submission-notes.php'; // submission_thread_payload()

agent_require_login();
$agent = agent_current();

$id  = (int)($_GET['id'] ?? 0);
$req = agent_fetch_request($agent, $id);

$flash = $_SESSION['agent_msg_flash'] ?? null;
unset($_SESSION['agent_msg_flash']);

$agentPageTitle = 'Request';
$agentActive    = 'requests';
include __DIR__ . '/_layout.php';

if ($req === null):
?>
<h1>Request not found</h1>
<div class="card"><div class="card__body card__body--pad">
  <p class="tp-note" style="margin:0">We couldn’t find that request on your account. <a href="/agent/requests.php">Back to your requests →</a></p>
</div></div>
<?php include __DIR__ . '/_layout_end.php'; return; endif;

$pl     = $req['payload'];
$st     = agent_request_status($req);
$nights = (int) round((strtotime((string)$req['check_out']) - strtotime((string)$req['check_in'])) / 86400);
$manage = (!empty($req['hold_id']) && in_array((string)$req['hold_status'], ['pending', 'confirmed'], true)) ? make_manage_url((int)$req['hold_id']) : '';
$thread = fetch_agent_visible_thread($id);
$notes  = array_map(fn($n) => submission_thread_payload($n, 'agent'), $thread);
$lastId = $notes ? (int) end($notes)['id'] : 0;
$room   = (string)($req['room_name'] ?? ($pl['rooms'] ?? ''));
$fmt    = fn(string $d): string => date('D j M Y', strtotime($d));
?>
<h1>Your request</h1>
<p class="tp-sub"><a href="/agent/requests.php">← All your requests</a></p>

<?php if ($flash): ?><div class="alert alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card"><div class="card__body card__body--pad">
  <h2><?= e((string)($req['venue_name'] ?? ($pl['venue'] ?? 'Property'))) ?></h2>
  <p class="tp-cardsub">Sent <?= e($fmt((string)$req['created_at'])) ?> · <span class="badge <?= e(agent_status_badge($st['class'])) ?>"><?= e($st['label']) ?></span><?= $st['note'] !== '' ? ' · ' . e($st['note']) : '' ?></p>

  <div class="tp-summary">
    <?php if ($room !== ''): ?><div><small>Room<?= (isset($pl['rooms']) && strpos((string)$pl['rooms'], ',') !== false) ? 's' : '' ?></small><span><?= e($room) ?></span></div><?php endif; ?>
    <div><small>Dates</small><span><?= e($fmt((string)$req['check_in'])) ?> → <?= e($fmt((string)$req['check_out'])) ?></span></div>
    <div><small>Nights</small><span><?= $nights ?></span></div>
    <div><small>Traveller</small><span><?= e((string)$req['guest_name']) ?></span></div>
    <div><small>Guests</small><span><?= (int)$req['guests_adults'] ?> adult<?= (int)$req['guests_adults'] === 1 ? '' : 's' ?><?= (int)$req['guests_children'] ? ', ' . (int)$req['guests_children'] . ' child' . ((int)$req['guests_children'] === 1 ? '' : 'ren') : '' ?></span></div>
    <?php if (isset($pl['quoted_total'])): ?>
    <div><small>Your rate</small><span class="tp-net"><?= e(format_price((float)$pl['quoted_total'], (string)($pl['quoted_currency'] ?? 'USD'))) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($manage !== ''): ?>
  <p class="tp-note" style="margin:0"><a href="<?= e($manage) ?>">Manage this booking →</a> (view or cancel the hold)</p>
  <?php endif; ?>
</div></div>

<div class="card"><div class="card__body card__body--pad">
  <h2>Messages</h2>
  <p class="tp-cardsub">Talk to reservations about this request. They also receive an email, and their replies appear here live.</p>

  <div id="stThread" class="st-thread" data-poll-url="/api/submission-thread.php" data-id="<?= (int)$id ?>" data-last="<?= (int)$lastId ?>" data-role="agent" style="margin-bottom:16px">
    <?php if (!$notes): ?>
      <p class="tp-note st-empty">No messages yet. Send reservations a note below — for example your traveller’s preferences or a question about the dates.</p>
    <?php else: foreach ($notes as $n): ?>
    <div class="stm <?= $n['mine'] ? 'stm--me' : 'stm--them' ?>" data-nid="<?= (int)$n['id'] ?>">
      <div class="stm__head"><strong><?= e($n['author']) ?></strong> · <?= e($n['time_label']) ?></div>
      <div class="stm__body"><?= e($n['body']) ?></div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <form id="stForm" method="POST" action="/api/agent-message.php"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b&&b.disabled)return false;">
    <?= csrf_field() ?>
    <input type="hidden" name="submission_id" value="<?= (int)$id ?>">
    <div class="field"><label for="agMsg">Message to reservations</label><textarea id="agMsg" name="body" class="inp inp--area" rows="3" required placeholder="Write a message…" style="width:100%"></textarea></div>
    <button type="submit" class="btn-primary">Send message</button>
    <span class="st-status tp-note" style="margin-left:10px"></span>
  </form>
</div></div>

<script defer src="/js/submission-thread.js?v=<?= @filemtime(__DIR__ . '/../js/submission-thread.js') ?: '1' ?>"></script>

<?php include __DIR__ . '/_layout_end.php'; ?>
