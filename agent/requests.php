<?php
declare(strict_types=1);
/**
 * Trade portal — the agent's requests and what became of each: sent (awaiting
 * reservations), then on hold (with the expiry countdown), confirmed, expired or
 * cancelled once reservations have converted it. Read via agent_requests().
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/booking.php';   // make_manage_url()

agent_require_login();
$agent = agent_current();

$sent      = (int)($_GET['sent'] ?? 0);
$rows      = [];
$loadError = '';
try {
    $rows = agent_requests($agent);
} catch (Throwable $e) {
    error_log('[agent-requests] ' . $e->getMessage());
    $loadError = 'We could not load your requests right now. Please try again shortly.';
}

$agentPageTitle = 'Your requests';
$agentActive    = 'requests';
include __DIR__ . '/_layout.php';
?>
<h1>Your requests</h1>
<p class="tp-sub">Every request you have sent, and its status. Reservations place the hold and confirm each one by email to <?= e($agent['email']) ?>.</p>

<?php if ($sent): ?><div class="alert alert--success">Request sent — reservations will check the dates, place the hold and confirm by email. Nothing is held or charged until then.</div><?php endif; ?>
<?php if ($loadError): ?><div class="alert alert--error"><?= e($loadError) ?></div><?php endif; ?>

<div class="card">
<?php if (!$rows && !$loadError): ?>
  <div class="card__body card__body--pad"><p class="tp-note" style="margin:0">No requests yet. <a href="/agent/availability.php">Check availability →</a></p></div>
<?php elseif ($rows): ?>
  <div class="card__body" style="padding:0"><div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Sent</th><th>Property · room</th><th>Dates</th><th>Traveller</th><th>Your rate</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pl = $r['payload']; $st = agent_request_status($r);
          $nights = (int) round((strtotime((string)$r['check_out']) - strtotime((string)$r['check_in'])) / 86400);
          $manage = (!empty($r['hold_id']) && in_array((string)$r['hold_status'], ['pending', 'confirmed'], true)) ? make_manage_url((int)$r['hold_id']) : ''; ?>
      <tr>
        <td style="white-space:nowrap"><?= e(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
        <td><strong><?= e((string)($r['venue_name'] ?? ($pl['venue'] ?? ''))) ?></strong><br><span class="tp-note"><?= e((string)($r['room_name'] ?? ($pl['rooms'] ?? ''))) ?></span></td>
        <td style="white-space:nowrap"><?= e(date('j M Y', strtotime((string)$r['check_in']))) ?> → <?= e(date('j M Y', strtotime((string)$r['check_out']))) ?><br><span class="tp-note"><?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span></td>
        <td><?= e((string)$r['guest_name']) ?></td>
        <td class="tp-net"><?= isset($pl['quoted_total']) ? e(format_price((float)$pl['quoted_total'], (string)($pl['quoted_currency'] ?? 'USD'))) : '—' ?></td>
        <td><span class="badge <?= e(agent_status_badge($st['class'])) ?>"><?= e($st['label']) ?></span><?= $st['note'] !== '' ? '<br><span class="tp-note">' . e($st['note']) . '</span>' : '' ?></td>
        <td style="white-space:nowrap"><a href="/agent/request-view.php?id=<?= (int)$r['id'] ?>">View / message</a><?php if ($manage !== ''): ?> · <a href="<?= e($manage) ?>">Manage</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div></div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
