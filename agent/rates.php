<?php
declare(strict_types=1);
/**
 * The travel-agent rate view. Every figure is the published "from" nightly rate
 * (rates_from_price → the ONE pricing path, which already reflects any admin
 * rate overrides) with the agent's discount applied at render time. No parallel
 * net-rate is stored, so changing a base rate moves these prices automatically.
 * Design A: one card per property — photo, place, discount — over its rooms.
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/rates.php';     // rates_from_price()
require_once __DIR__ . '/../includes/services.php';  // format_price(), is_priced()

agent_require_login();
$agent = agent_current();

// Published venues, each with its published rooms. Read-only.
$venues = db_query('SELECT id, name, location FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();

/** The property's main photo, or '' (the header shows a sand tile instead). */
$venuePhoto = function (int $vid): string {
    try {
        $imgs = function_exists('fetch_venue_images') ? fetch_venue_images($vid) : [];
        return $imgs ? (string)storage_url($imgs[0]['filename']) : '';
    } catch (Throwable $e) { return ''; }
};

$agentPageTitle = 'Your rates';
$agentActive    = 'rates';
include __DIR__ . '/_layout.php';
?>
<h1>Your rates</h1>
<p class="tp-sub">Nightly rates from, across every property, with your agreed discount already applied. <a href="/agent/availability.php">Check live availability for your dates →</a></p>

<?php $shown = 0; foreach ($venues as $v):
    $vid  = (int)$v['id'];
    $pct  = agent_discount_pct($agent, $vid);
    $rooms = db_query(
        "SELECT id, name, capacity, price_amount, price_currency, is_entire_place
           FROM rooms WHERE venue_id = :vid AND is_published = TRUE
          ORDER BY is_entire_place ASC, sort_order ASC, name ASC",
        [':vid' => $vid]
    )->fetchAll();
    if (!$rooms) continue;
    $shown++;
    $photo = $venuePhoto($vid);
?>
<section class="tp-block">
  <div class="tp-block__h">
    <?php if ($photo !== ''): ?><img class="tp-block__img" src="<?= e($photo) ?>" alt="" loading="lazy"><?php else: ?><div class="tp-block__img" aria-hidden="true"></div><?php endif; ?>
    <div>
      <div class="tp-block__t"><?= e($v['name']) ?></div>
      <?php if (trim((string)($v['location'] ?? '')) !== ''): ?><div class="tp-block__l"><?= e($v['location']) ?></div><?php endif; ?>
    </div>
    <?php if ($pct > 0): ?><span class="tp-disc"><?= e(rtrim(rtrim(number_format($pct, 2), '0'), '.')) ?>% off published</span>
    <?php else: ?><span class="tp-disc tp-disc--none">Published rates</span><?php endif; ?>
  </div>
  <div class="tp-wrap">
  <table class="tp-table">
    <thead><tr><th>Room</th><th>Sleeps</th><th class="r">Published / night</th><th class="r">Your rate / night</th></tr></thead>
    <tbody>
      <?php foreach ($rooms as $r):
        $rid  = (int)$r['id'];
        $cur  = $r['price_currency'] ?: 'USD';
        $base = (float)$r['price_amount'];
        $name = e($r['name']) . ($r['is_entire_place'] ? '<span class="tp-wholetag">Whole property</span>' : '');
        if (!is_priced($base)) {
          echo '<tr><td>' . $name . '</td><td>' . ($r['capacity'] ? (int)$r['capacity'] : '—') . '</td><td colspan="2" class="r tp-note">On request</td></tr>';
          continue;
        }
        $from = rates_from_price($rid, $base, 365);          // honest "from", override-aware
        $net  = agent_net_price($from, $agent, $vid);
      ?>
      <tr>
        <td><?= $name ?></td>
        <td><?= $r['capacity'] ? (int)$r['capacity'] : '—' ?></td>
        <td class="r"><?php if ($pct > 0): ?><span class="tp-was"><?= e(format_price($from, $cur)) ?></span><?php else: ?><?= e(format_price($from, $cur)) ?><?php endif; ?></td>
        <td class="r tp-net"><?= e(format_price($net, $cur)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>

<?php if (!$shown): ?><div class="tp-empty"><b>No rates to show yet</b>Published properties will appear here.</div><?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
