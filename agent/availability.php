<?php
declare(strict_types=1);
/**
 * Trade portal — live availability. The agent picks dates, party and (optionally)
 * one property; every published venue in scope is resolved with
 * ts_property_configurations() — the SAME capacity-aware brain and the ONE
 * pricing path the property pages and /search use — and
 * agent_price_configurations() adds the net figure beside each published one.
 * Every option links into request.php. Server-rendered GET; the only JS is the
 * shared datepicker.
 */
require_once __DIR__ . '/../includes/agent.php';

agent_require_login();
$agent = agent_current();

$venuesAll = db_query('SELECT * FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll();

$ciRaw    = trim((string)($_GET['check_in']  ?? ''));
$coRaw    = trim((string)($_GET['check_out'] ?? ''));
$adults   = max(1, min(30, (int)($_GET['adults']   ?? 2)));
$children = max(0, min(20, (int)($_GET['children'] ?? 0)));
$venueSel = trim((string)($_GET['venue'] ?? ''));
$guests   = $adults + $children;

$searched = ($ciRaw !== '' || $coRaw !== '');
$error    = '';
$stay     = $searched ? agent_valid_stay($ciRaw, $coRaw) : null;
$results  = [];   // [['venue' => row, 'cfg' => priced configurations], …]

if ($searched && $stay === null) {
    $error = 'Please choose a check-in date from today and a later check-out (up to ' . AGENT_MAX_STAY_NIGHTS . ' nights).';
} elseif ($stay !== null) {
    [$ci, $co, $nights] = $stay;
    foreach ($venuesAll as $v) {
        if ($venueSel !== '' && $v['slug'] !== $venueSel) continue;
        try {
            $cfg = ts_property_configurations($v, $ci, $co, $guests, null, true);
        } catch (Throwable $e) {
            error_log('[agent-availability] ' . $e->getMessage());
            $error   = 'We could not check live availability right now. Please try again in a moment.';
            $results = [];
            break;
        }
        $results[] = ['venue' => $v, 'cfg' => agent_price_configurations($cfg, $agent, (int)$v['id'])];
    }
}

$fmtDate = fn(string $d): string => date('D j M Y', strtotime($d));
$reqUrl  = fn(array $params): string => '/agent/request.php?' . http_build_query($params + [
    'check_in' => $stay[0] ?? '', 'check_out' => $stay[1] ?? '', 'adults' => $adults, 'children' => $children,
]);
/** Published (struck through when discounted) + net, for one option. */
$priceCell = function (array $o, float $pct): string {
    if ((float)($o['total'] ?? 0) <= 0) return '<b>On request</b>';   // unpriced room — never "USD 0"
    $cur = (string)($o['currency'] ?? 'USD');
    $pub = format_price((float)$o['total'], $cur);
    $net = format_price((float)($o['net_total'] ?? $o['total']), $cur);
    return ($pct > 0 ? '<span class="ap-was">' . e($pub) . '</span> ' : '') . '<b>' . e($net) . '</b>';
};
$nightsTxt = $stay ? $stay[2] . ' night' . ($stay[2] === 1 ? '' : 's') : '';

$agentPageTitle = 'Check availability';
$agentActive    = 'availability';
include __DIR__ . '/_layout.php';
?>
<h1>Check availability</h1>
<p class="ap-sub">Live availability across every property, priced at your trade rate. Choose dates and party size, then request the option you want — dates are held for 24 hours while reservations confirm.</p>

<div class="ap-card">
  <form method="GET" action="/agent/availability.php" class="ap-form">
    <div class="field">
      <label for="apCi">Check-in</label>
      <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="apStay" data-dp-target="apCi" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="apCi" name="check_in" value="<?= e($stay[0] ?? $ciRaw) ?>">
      <noscript><input type="date" name="check_in" value="<?= e($stay[0] ?? $ciRaw) ?>" aria-label="Check-in"></noscript>
    </div>
    <div class="field">
      <label for="apCo">Check-out</label>
      <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="apStay" data-dp-target="apCo" data-dp-placeholder="Add date">Add date</button>
      <input type="hidden" id="apCo" name="check_out" value="<?= e($stay[1] ?? $coRaw) ?>">
      <noscript><input type="date" name="check_out" value="<?= e($stay[1] ?? $coRaw) ?>" aria-label="Check-out"></noscript>
    </div>
    <div class="field"><label for="apAd">Adults</label><input type="number" id="apAd" name="adults" min="1" max="30" value="<?= (int)$adults ?>"></div>
    <div class="field"><label for="apCh">Children</label><input type="number" id="apCh" name="children" min="0" max="20" value="<?= (int)$children ?>"></div>
    <div class="field">
      <label for="apVenue">Property</label>
      <select id="apVenue" name="venue">
        <option value="">All properties</option>
        <?php foreach ($venuesAll as $v): ?>
        <option value="<?= e($v['slug']) ?>" <?= $venueSel === $v['slug'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><button type="submit" class="btn">Check availability</button></div>
  </form>
  <?php if ($error): ?><div class="alert alert-error" style="margin:14px 0 0"><?= e($error) ?></div><?php endif; ?>
</div>

<?php if ($stay !== null && !$error): [$ci, $co, $nights] = $stay; ?>
<p class="ap-sub"><strong><?= e($fmtDate($ci)) ?> → <?= e($fmtDate($co)) ?></strong> · <?= e($nightsTxt) ?> ·
  <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> (<?= $adults ?> adult<?= $adults === 1 ? '' : 's' ?><?= $children ? ', ' . $children . ' child' . ($children === 1 ? '' : 'ren') : '' ?>)</p>

<?php foreach ($results as $res): $v = $res['venue']; $cfg = $res['cfg']; $pct = (float)$cfg['discount_pct'];
      $has = $cfg['singles'] || $cfg['combos'] || $cfg['entire']; ?>
<div class="ap-card">
  <h2><?= e($v['name']) ?></h2>
  <p class="ap-cardsub"><?php if ($pct > 0): ?>Your rate: <span class="ap-badge"><?= e(agent_pct_label($pct)) ?>% off published</span><?php else: ?>Published rates (no trade discount set for this property).<?php endif; ?></p>

  <?php if (!$has): ?>
    <p class="ap-note">Nothing fits <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> for these dates<?= !empty($cfg['max_capacity']) ? ' — the property sleeps up to ' . (int)$cfg['max_capacity'] . ' for this window' : '' ?>.</p>
  <?php else: ?>

    <?php if ($cfg['singles']): ?>
    <div class="ap-sec">Available rooms</div>
    <div class="ap-opts">
      <?php foreach ($cfg['singles'] as $o): ?>
      <div class="ap-opt">
        <div>
          <div class="ap-opt__name"><?= e($o['name']) ?></div>
          <div class="ap-opt__meta"><?= !empty($o['capacity']) ? 'Sleeps up to ' . (int)$o['capacity'] . ' · ' : '' ?><?= e($nightsTxt) ?></div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($o, $pct) ?><a class="ap-btn" href="<?= e($reqUrl(['room' => $o['slug']])) ?>">Request to book</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($cfg['combos']): ?>
    <div class="ap-sec">For <?= $guests ?> guests we suggest<?= count($cfg['combos']) > 1 ? ' — best fit first' : '' ?></div>
    <div class="ap-opts">
      <?php foreach ($cfg['combos'] as $c): ?>
      <div class="ap-opt">
        <div>
          <div class="ap-opt__name">Combination · sleeps <?= (int)$c['capacity'] ?></div>
          <div><?php foreach ($c['rooms'] as $cr): ?><span class="ap-chip"><?= e($cr['name']) ?><?= (int)$cr['units_used'] > 1 ? ' ×' . (int)$cr['units_used'] : '' ?> · <?= e(format_price((float)($cr['net_total'] ?? $cr['total']), (string)$cr['currency'])) ?></span><?php endforeach; ?></div>
          <div class="ap-opt__meta">Sent as an enquiry — reservations confirm the rooms and price by email.</div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($c, $pct) ?><a class="ap-btn ap-btn--ghost" href="<?= e($reqUrl(['venue' => $v['slug'], 'rooms' => agent_rooms_param($c['rooms'])])) ?>">Request these rooms</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($cfg['entire']): ?>
    <div class="ap-sec"><?= ($cfg['singles'] || $cfg['combos']) ? 'Or the whole property' : 'The whole property' ?></div>
    <div class="ap-opts">
      <?php foreach ($cfg['entire'] as $o): ?>
      <div class="ap-opt ap-opt--entire">
        <div>
          <div class="ap-opt__name"><?= e($o['name']) ?> <span class="ap-badge">Whole property</span></div>
          <div class="ap-opt__meta"><?= !empty($o['capacity']) ? 'Sleeps up to ' . (int)$o['capacity'] . ' · ' : '' ?><?= e($nightsTxt) ?></div>
        </div>
        <div class="ap-opt__price"><?= $priceCell($o, $pct) ?><a class="ap-btn" href="<?= e($reqUrl(['room' => $o['slug']])) ?>">Request to book</a></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$results): ?><div class="ap-card">No published properties to check.</div><?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
