<?php
declare(strict_types=1);
/**
 * Trade portal — live availability. The agent picks dates, party and (optionally)
 * one property; every published venue in scope is resolved with
 * ts_property_configurations() — the SAME capacity-aware brain and the ONE
 * pricing path the property pages and /search use — and
 * agent_price_configurations() adds the net figure beside each published one.
 * Every option links into request.php. Server-rendered GET. Design A "Clean
 * light": one rounded search bar, then every bookable option as a photo card,
 * cheapest first; the type/town chips filter the cards in the browser.
 */
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/listing-alternatives.php';   // TS_VENUE_TYPES, ts_venue_town()

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

$fmtDate = fn(string $d): string => date('D j M', strtotime($d));
$reqUrl  = fn(array $params): string => '/agent/request.php?' . http_build_query($params + [
    'check_in' => $stay[0] ?? '', 'check_out' => $stay[1] ?? '', 'adults' => $adults, 'children' => $children,
]);
$nightsTxt = $stay ? $stay[2] . ' night' . ($stay[2] === 1 ? '' : 's') : '';

/* Every bookable option across the properties → one card each, cheapest first.
   Prices are the configurations' own figures (ONE pricing path); the USD value
   is only a sort key, never shown. Unpriced options sort last as "On request". */
$cards = []; $noFit = []; $towns = [];
foreach ($results as $res) {
    $v = $res['venue']; $cfg = $res['cfg'];
    $where = trim((string)($v['location'] ?? '')) !== '' ? (string)$v['location'] : (string)$v['name'];
    $town  = ts_venue_town((string)($v['location'] ?? ''));
    $type  = TS_VENUE_TYPES[$v['slug']] ?? '';
    if (!$cfg['singles'] && !$cfg['combos'] && !$cfg['entire']) { $noFit[] = (string)$v['name']; continue; }
    if ($town !== '') $towns[$town] = ucwords($town);
    $add = function (string $kind, string $title, array $o, string $url, array $rooms = [], string $img = '') use (&$cards, $v, $where, $town, $type): void {
        $cur = (string)($o['currency'] ?? 'USD');
        $net = (float)($o['net_total'] ?? $o['total'] ?? 0);
        $usd = $net > 0 ? (float)convert_price($net, $cur, 'USD')['amount'] : PHP_FLOAT_MAX;
        $cards[] = ['kind' => $kind, 'title' => $title, 'venue' => (string)$v['name'], 'where' => $where,
            'town' => $town, 'type' => $type, 'cap' => (int)($o['capacity'] ?? 0), 'rooms' => $rooms,
            'img' => $img !== '' ? $img : (string)($o['hero'] ?? ''), 'pub' => (float)($o['total'] ?? 0),
            'net' => $net, 'cur' => $cur, 'url' => $url, 'sort' => $usd];
    };
    foreach ($cfg['singles'] as $o) $add('room', (string)$o['name'], $o, $reqUrl(['room' => $o['slug']]));
    foreach ($cfg['combos'] as $c) {
        $names = array_map(fn($r) => $r['name'] . ((int)$r['units_used'] > 1 ? ' ×' . (int)$r['units_used'] : ''), $c['rooms']);
        $hero  = '';
        foreach ($c['rooms'] as $r) { if (!empty($r['hero'])) { $hero = (string)$r['hero']; break; } }
        $units = array_sum(array_map(fn($r) => max(1, (int)$r['units_used']), $c['rooms']));
        $add('combo', $units . ' rooms together', $c,
             $reqUrl(['venue' => $v['slug'], 'rooms' => agent_rooms_param($c['rooms'])]), $names, $hero);
    }
    foreach ($cfg['entire'] as $o) $add('whole', (string)$o['name'], $o, $reqUrl(['room' => $o['slug']]));
}
usort($cards, fn($a, $b) => $a['sort'] <=> $b['sort']);
$types = array_values(array_unique(array_filter(array_column($cards, 'type'))));
ksort($towns);

$agentPageTitle = 'Check availability';
$agentActive    = 'availability';
include __DIR__ . '/_layout.php';
?>
<h1>Check availability</h1>
<p class="tp-sub">Live rooms and prices at your trade rate. Request an option and reservations check the dates, place the hold and confirm by email.</p>

<form method="GET" action="/agent/availability.php" class="tp-search" role="search">
  <div class="field">
    <label for="apCi">Check-in</label>
    <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="apStay" data-dp-target="apCi" data-dp-placeholder="Add date">Add date</button>
    <input type="hidden" id="apCi" name="check_in" value="<?= e($stay[0] ?? $ciRaw) ?>">
  </div>
  <div class="field">
    <label for="apCo">Check-out</label>
    <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="apStay" data-dp-target="apCo" data-dp-placeholder="Add date">Add date</button>
    <input type="hidden" id="apCo" name="check_out" value="<?= e($stay[1] ?? $coRaw) ?>">
  </div>
  <div class="field"><label for="apAd">Adults</label><input type="number" id="apAd" class="inp" name="adults" min="1" max="30" inputmode="numeric" value="<?= (int)$adults ?>"></div>
  <div class="field"><label for="apCh">Children</label><input type="number" id="apCh" class="inp" name="children" min="0" max="20" inputmode="numeric" value="<?= (int)$children ?>"></div>
  <div class="field">
    <label for="apVenue">Property</label>
    <select id="apVenue" name="venue" class="filter-select eselect--block" aria-label="Property">
      <option value="">All properties</option>
      <?php foreach ($venuesAll as $v): ?>
      <option value="<?= e($v['slug']) ?>" <?= $venueSel === $v['slug'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="tp-search__go"><svg class="tp-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>Search</button>
</form>
<?php if ($error): ?><div class="alert alert--error" style="margin:16px 0 0"><?= e($error) ?></div><?php endif; ?>

<?php if ($stay === null && !$error): ?>
  <div class="tp-empty" style="margin-top:24px"><b>Choose your dates to see what's free</b>Every property is checked live and priced at your trade rate. <a href="/agent/rates.php" style="color:var(--tp-teal);font-weight:600">See your rates →</a></div>
<?php elseif ($stay !== null && !$error): [$ci, $co, $nights] = $stay; ?>

  <?php if ($cards && (count($types) > 1 || count($towns) > 1)): ?>
  <div class="tp-chips" role="group" aria-label="Filter results">
    <button type="button" class="tp-fchip is-on" data-f="" aria-pressed="true">All</button>
    <?php if (in_array('hotel', $types, true)): ?><button type="button" class="tp-fchip" data-f="type:hotel" aria-pressed="false">Boutique hotels</button><?php endif; ?>
    <?php if (in_array('villa', $types, true)): ?><button type="button" class="tp-fchip" data-f="type:villa" aria-pressed="false">Private villas</button><?php endif; ?>
    <?php if (count($towns) > 1): foreach ($towns as $tk => $tl): ?><button type="button" class="tp-fchip" data-f="town:<?= e($tk) ?>" aria-pressed="false"><?= e($tl) ?></button><?php endforeach; endif; ?>
  </div>
  <?php endif; ?>

  <div class="tp-meta">
    <span><b><span data-count><?= count($cards) ?></span> option<?= count($cards) === 1 ? '' : 's' ?></b> for <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> · <?= e($fmtDate($ci)) ?> → <?= e($fmtDate($co)) ?> · <?= e($nightsTxt) ?></span>
    <span>Lowest price first · prices are for the whole stay</span>
  </div>

  <?php if (!$cards): ?>
    <div class="tp-empty"><b>Nothing fits <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?> for these dates</b>Try other dates or a smaller party, or ask reservations — they can often find a way.</div>
  <?php else: ?>
  <div class="tp-grid">
    <?php foreach ($cards as $c):
      $tag = $c['kind'] === 'whole' ? ['Whole property', 'tp-tag--whole']
           : ($c['kind'] === 'combo' ? ['Rooms together', 'tp-tag--combo'] : ['Fits ' . $guests . ' guest' . ($guests === 1 ? '' : 's'), '']); ?>
    <article class="tp-card" data-type="<?= e($c['type']) ?>" data-town="<?= e($c['town']) ?>">
      <div class="tp-card__img">
        <?php if ($c['img'] !== ''): ?><img src="<?= e($c['img']) ?>" alt="<?= e($c['title']) ?>" loading="lazy"><?php endif; ?>
        <span class="tp-card__where"><?= e($c['venue']) ?></span>
      </div>
      <div class="tp-card__b">
        <span class="tp-tag <?= $tag[1] ?>"><?= e($tag[0]) ?></span>
        <div class="tp-card__t"><?= e($c['title']) ?></div>
        <div class="tp-card__l"><?= e($c['where']) ?><?= $c['cap'] ? ' · sleeps ' . $c['cap'] : '' ?></div>
        <?php if ($c['rooms']): ?><div class="tp-card__rooms"><?php foreach ($c['rooms'] as $rn): ?><span><?= e($rn) ?></span><?php endforeach; ?></div><?php endif; ?>
        <div class="tp-card__row">
          <div>
            <?php if ($c['net'] > 0): ?>
              <?php if ($c['pub'] > $c['net']): ?><span class="tp-was"><?= e(format_price($c['pub'], $c['cur'])) ?></span><?php endif; ?>
              <div class="tp-price"><?= e(format_price($c['net'], $c['cur'])) ?> <small>· <?= e($nightsTxt) ?></small></div>
            <?php else: ?>
              <div class="tp-price" style="font-size:15px">On request</div>
            <?php endif; ?>
          </div>
          <a class="tp-btn" href="<?= e($c['url']) ?>">Request</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="tp-empty" data-none hidden><b>No options match this filter</b>Choose "All" to see every option.</div>
  <?php endif; ?>

  <?php if ($noFit && $cards): ?><p class="tp-nofit">No space for this party at: <?= e(implode(', ', $noFit)) ?>.</p><?php endif; ?>
<?php endif; ?>

<script>
// Filter chips: show only the cards of one type or town (no reload).
(function () {
  var chips = document.querySelectorAll('.tp-fchip');
  if (!chips.length) return;
  var cards = document.querySelectorAll('.tp-card');
  var count = document.querySelector('[data-count]');
  var none  = document.querySelector('[data-none]');
  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chips.forEach(function (c) { c.classList.toggle('is-on', c === chip); c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      var f = chip.getAttribute('data-f'), shown = 0;
      cards.forEach(function (card) {
        var ok = !f || (f.indexOf('type:') === 0 ? card.dataset.type === f.slice(5) : card.dataset.town === f.slice(5));
        card.hidden = !ok; if (ok) shown++;
      });
      if (count) count.textContent = shown;
      if (none) none.hidden = shown > 0;
    });
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
