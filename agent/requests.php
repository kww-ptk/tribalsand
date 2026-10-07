<?php
declare(strict_types=1);
/**
 * Trade portal — the agent's requests and what became of each: sent (awaiting
 * reservations), then on hold (with the expiry countdown), confirmed, expired or
 * cancelled once reservations have converted it. Read via agent_requests().
 * Design A: four numbers on top, then one card table with status pills.
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

/* The four numbers. "Booked at your rate" is per currency (money is never
   summed across currencies) and counts confirmed requests only. */
$waiting = 0; $confirmed = 0; $booked = [];
foreach ($rows as $r) {
    $cls = agent_request_status($r)['class'];
    if ($cls === 'sent' || $cls === 'pending') $waiting++;
    if ($cls === 'confirmed') {
        $confirmed++;
        $pl = $r['payload'];
        if (isset($pl['quoted_total'])) {
            $cur = (string)($pl['quoted_currency'] ?? 'USD');
            $booked[$cur] = ($booked[$cur] ?? 0) + (float)$pl['quoted_total'];
        }
    }
}
$bookedTxt = $booked ? implode(' · ', array_map(fn($c, $a) => format_price($a, $c), array_keys($booked), $booked)) : '—';

$agentPageTitle = 'Your requests';
$agentActive    = 'requests';
include __DIR__ . '/_layout.php';
?>
<h1>Your requests</h1>
<p class="tp-sub">Every request you have sent and where it stands. Reservations place the hold and confirm each one by email to <?= e($agent['email']) ?>.</p>

<?php if ($sent): ?><div class="alert alert--success is-flash">Request sent — reservations will check the dates, place the hold and confirm by email. Nothing is held or charged until then.</div><?php endif; ?>
<?php if ($loadError): ?><div class="alert alert--error"><?= e($loadError) ?></div><?php endif; ?>

<?php if (!$rows && !$loadError): ?>
  <div class="tp-empty"><b>No requests yet</b>Find a room for your client and send a request in one step. <a href="/agent/availability.php" style="color:var(--tp-teal);font-weight:600">Check availability →</a></div>
<?php elseif ($rows): ?>
  <div class="tp-stats">
    <div class="tp-stat"><b><?= count($rows) ?></b><span>Requests sent</span></div>
    <div class="tp-stat"><b><?= $waiting ?></b><span>Waiting for a reply</span></div>
    <div class="tp-stat"><b><?= $confirmed ?></b><span>Confirmed</span></div>
    <div class="tp-stat"><b style="font-size:<?= count($booked) > 1 ? '17' : '24' ?>px"><?= e($bookedTxt) ?></b><span>Booked at your rate</span></div>
  </div>

  <section class="tp-block">
  <div class="tp-wrap">
  <table class="tp-table">
    <thead><tr><th>Traveller</th><th>Stay</th><th>Property · room</th><th>Status</th><th class="r">Your price</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pl = $r['payload']; $st = agent_request_status($r);
          $nights = (int) round((strtotime((string)$r['check_out']) - strtotime((string)$r['check_in'])) / 86400);
          $manage = (!empty($r['hold_id']) && in_array((string)$r['hold_status'], ['pending', 'confirmed'], true)) ? make_manage_url((int)$r['hold_id']) : ''; ?>
      <tr>
        <td><strong><?= e((string)$r['guest_name']) ?></strong><br><span class="tp-note">Sent <?= e(date('j M Y', strtotime((string)$r['created_at']))) ?></span></td>
        <td style="white-space:nowrap"><?= e(date('j M', strtotime((string)$r['check_in']))) ?> → <?= e(date('j M Y', strtotime((string)$r['check_out']))) ?><br><span class="tp-note"><?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span></td>
        <td><strong><?= e((string)($r['venue_name'] ?? ($pl['venue'] ?? ''))) ?></strong><br><span class="tp-note"><?= e((string)($r['room_name'] ?? ($pl['rooms'] ?? ''))) ?></span></td>
        <td><span class="tp-pill tp-pill--<?= e($st['class']) ?>"><?= e($st['label']) ?></span><?= $st['note'] !== '' ? '<br><span class="tp-note">' . e($st['note']) . '</span>' : '' ?></td>
        <td class="r tp-net" style="white-space:nowrap"><?= isset($pl['quoted_total']) ? e(format_price((float)$pl['quoted_total'], (string)($pl['quoted_currency'] ?? 'USD'))) : '—' ?></td>
        <td class="r tp-links" style="white-space:nowrap"><a href="/agent/request-view.php?id=<?= (int)$r['id'] ?>">View &amp; message</a><?php if ($manage !== ''): ?><br><a href="<?= e($manage) ?>">Manage booking</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </section>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
