<?php
declare(strict_types=1);
/**
 * Trade portal — one request in detail: the facts, the live status, and a
 * lightweight conversation with reservations. Ownership is re-checked with the
 * same signed filter the list uses (agent_fetch_request) — the id in the URL is
 * never trusted. The agent sees ONLY staff replies and their own messages, never
 * internal staff notes (fetch_agent_visible_thread enforces that).
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/booking.php';   // make_manage_url()

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
<div class="ap-card">
  <p class="ap-note" style="margin:0">We couldn’t find that request on your account. <a href="/agent/requests.php">Back to your requests →</a></p>
</div>
<?php include __DIR__ . '/_layout_end.php'; return; endif;

$pl     = $req['payload'];
$st     = agent_request_status($req);
$nights = (int) round((strtotime((string)$req['check_out']) - strtotime((string)$req['check_in'])) / 86400);
$manage = (!empty($req['hold_id']) && in_array((string)$req['hold_status'], ['pending', 'confirmed'], true)) ? make_manage_url((int)$req['hold_id']) : '';
$thread = fetch_agent_visible_thread($id);
$room   = (string)($req['room_name'] ?? ($pl['rooms'] ?? ''));
$fmt    = fn(string $d): string => date('D j M Y', strtotime($d));
?>
<h1>Your request</h1>
<p class="ap-sub"><a href="/agent/requests.php">← All your requests</a></p>

<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="ap-card">
  <h2><?= e((string)($req['venue_name'] ?? ($pl['venue'] ?? 'Property'))) ?></h2>
  <p class="ap-cardsub">Sent <?= e($fmt((string)$req['created_at'])) ?> · <span class="ap-status ap-status--<?= e($st['class']) ?>"><?= e($st['label']) ?></span><?= $st['note'] !== '' ? ' · ' . e($st['note']) : '' ?></p>

  <div class="ap-summary">
    <?php if ($room !== ''): ?><div><small>Room<?= (isset($pl['rooms']) && strpos((string)$pl['rooms'], ',') !== false) ? 's' : '' ?></small><span><?= e($room) ?></span></div><?php endif; ?>
    <div><small>Dates</small><span><?= e($fmt((string)$req['check_in'])) ?> → <?= e($fmt((string)$req['check_out'])) ?></span></div>
    <div><small>Nights</small><span><?= $nights ?></span></div>
    <div><small>Traveller</small><span><?= e((string)$req['guest_name']) ?></span></div>
    <div><small>Guests</small><span><?= (int)$req['guests_adults'] ?> adult<?= (int)$req['guests_adults'] === 1 ? '' : 's' ?><?= (int)$req['guests_children'] ? ', ' . (int)$req['guests_children'] . ' child' . ((int)$req['guests_children'] === 1 ? '' : 'ren') : '' ?></span></div>
    <?php if (isset($pl['quoted_total'])): ?>
    <div><small>Your rate</small><span class="ap-net"><?= e(format_price((float)$pl['quoted_total'], (string)($pl['quoted_currency'] ?? 'USD'))) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($manage !== ''): ?>
  <p class="ap-note" style="margin:0"><a href="<?= e($manage) ?>">Manage this booking →</a> (view or cancel the hold)</p>
  <?php endif; ?>
</div>

<div class="ap-card">
  <h2>Messages</h2>
  <p class="ap-cardsub">Talk to reservations about this request. They also receive an email, and their replies appear here.</p>

  <?php if (!$thread): ?>
    <p class="ap-note">No messages yet. Send reservations a note below — for example your traveller’s preferences or a question about the dates.</p>
  <?php else: ?>
  <div class="ap-thread">
    <?php foreach ($thread as $n):
      $mine   = ($n['kind'] ?? '') === 'guest_reply';   // the agent's own / customer side
      $author = trim((string)($n['frozen_author'] ?? '')) ?: (trim((string)($n['author_name'] ?? '')) ?: ($mine ? 'You' : 'Reservations'));
      // A staff reply is stored with a "📧 Emailed to guest:" prefix — drop it for the agent's eyes.
      $body   = preg_replace('/^📧 Emailed to guest:\s*/u', '', (string)$n['body']);
    ?>
    <div class="ap-bubble <?= $mine ? 'ap-bubble--me' : 'ap-bubble--them' ?>">
      <div class="ap-bubble__head"><strong><?= e($mine ? 'You' : ($author ?: 'Reservations')) ?></strong> · <?= e(date('j M Y, H:i', strtotime((string)$n['created_at']))) ?></div>
      <div class="ap-bubble__body"><?= nl2br(e($body)) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="/api/agent-message.php" class="ap-msgform"
        onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled)return false;b.disabled=true;b.textContent='Sending…';return true;">
    <?= csrf_field() ?>
    <input type="hidden" name="submission_id" value="<?= (int)$id ?>">
    <div class="field"><label for="agMsg">Message to reservations</label><textarea id="agMsg" name="body" rows="3" required placeholder="Write a message…"></textarea></div>
    <button type="submit" class="btn btn--auto">Send message</button>
  </form>
</div>

<style>
  .ap-thread{display:flex;flex-direction:column;gap:10px;margin:0 0 16px}
  .ap-bubble{max-width:82%;border:1px solid var(--line);border-radius:12px;padding:10px 12px}
  .ap-bubble--them{background:#f0f9fa;border-color:#cfe0e6;align-self:flex-start;border-bottom-left-radius:3px}
  .ap-bubble--me{background:var(--sand);align-self:flex-end;border-bottom-right-radius:3px}
  .ap-bubble__head{font-size:.72rem;color:var(--mut);margin-bottom:4px}
  .ap-bubble__body{font-size:.9rem;line-height:1.5}
  .ap-msgform .field{margin-bottom:10px}
</style>

<?php include __DIR__ . '/_layout_end.php'; ?>
