<?php
declare(strict_types=1);
/**
 * The travel-agent rate view. Every figure is the published "from" nightly rate
 * (rates_from_price → the ONE pricing path, which already reflects any admin
 * rate overrides) with the agent's discount applied at render time. No parallel
 * net-rate is stored, so changing a base rate moves these prices automatically.
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/rates.php';     // rates_from_price()
require_once __DIR__ . '/../includes/services.php';  // format_price(), is_priced()

agent_require_login();
$agent = agent_current();

// Published venues, each with its published rooms. Read-only.
$venues = db_query('SELECT id, name FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();

$agentPageTitle = 'Your rates';
include __DIR__ . '/_layout.php';
?>
<h1>Your rates</h1>
<p class="ap-sub">Nightly rates from, across every property. Your agreed discount is already applied — availability is confirmed when you book.</p>

<?php foreach ($venues as $v):
    $vid  = (int)$v['id'];
    $pct  = agent_discount_pct($agent, $vid);
    $rooms = db_query(
        "SELECT id, name, capacity, price_amount, price_currency, is_entire_place
           FROM rooms WHERE venue_id = :vid AND is_published = TRUE
          ORDER BY is_entire_place ASC, sort_order ASC, name ASC",
        [':vid' => $vid]
    )->fetchAll();
    if (!$rooms) continue;
?>
<div class="ap-card">
  <h2><?= e($v['name']) ?></h2>
  <p class="ap-cardsub">
    <?php if ($pct > 0): ?>Your rate: <span class="ap-badge"><?= e(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% off published</span>
    <?php else: ?>Published rates (no trade discount set for this property).<?php endif; ?>
  </p>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Room</th><th>Sleeps</th><th>Published / night</th><th>Your rate / night</th></tr></thead>
    <tbody>
      <?php foreach ($rooms as $r):
        $rid  = (int)$r['id'];
        $cur  = $r['price_currency'] ?: 'USD';
        $base = (float)$r['price_amount'];
        if (!is_priced($base)) {
          echo '<tr><td>' . e($r['name']) . ($r['is_entire_place'] ? ' <span class="ap-badge">Whole property</span>' : '')
             . '</td><td>' . (int)$r['capacity'] . '</td><td colspan="2" style="text-align:left;color:#6b6050">On request</td></tr>';
          continue;
        }
        $from = rates_from_price($rid, $base, 365);          // honest "from", override-aware
        $net  = agent_net_price($from, $agent, $vid);
      ?>
      <tr>
        <td><?= e($r['name']) ?><?= $r['is_entire_place'] ? ' <span class="ap-badge">Whole property</span>' : '' ?></td>
        <td><?= $r['capacity'] ? (int)$r['capacity'] : '—' ?></td>
        <td><?php if ($pct > 0): ?><span class="ap-was"><?= e(format_price($from, $cur)) ?></span><?php else: ?><?= e(format_price($from, $cur)) ?><?php endif; ?></td>
        <td class="ap-net"><?= e(format_price($net, $cur)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>

<?php if (!$venues): ?><div class="ap-card">No published properties to show yet.</div><?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
