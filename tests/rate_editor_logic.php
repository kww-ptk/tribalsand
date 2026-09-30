<?php
declare(strict_types=1);
// Global rate editor — request parsing, the four price modes, rounding, refusals,
// run grouping, buyout sums; then (DB) apply / change log / undo and the endpoint
// dispatcher's guards.
// Run: php tests/rate_editor_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end (the
// rate_change_log table is created inside it when absent — Postgres DDL is
// transactional), so no real rates, rooms or log rows are ever left behind.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rate-editor.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
/** The refusal message a callable throws, or null when it does not refuse. */
function refusal(callable $fn): ?string {
    try { $fn(); return null; } catch (RateEditorRefusal $e) { return $e->getMessage(); }
}

// ── Fixtures for the pure block ─────────────────────────────────────────────
/** A nightly map in rates_nightly_maps() shape: $n nights from $from. */
function mk_map(string $from, int $n, float $price, ?string $label, bool $override = true): array {
    $out = [];
    $d = new DateTime($from);
    for ($i = 0; $i < $n; $i++) {
        $out[$d->format('Y-m-d')] = ['price' => $price, 'label' => $label,
            'rate_id' => $override ? 1 : null, 'is_override' => $override];
        $d->modify('+1 day');
    }
    return $out;
}
function mk_room(int $id, string $name, ?int $venue, float $price, string $cur, bool $entire = false, bool $pub = true): array {
    return ['id' => $id, 'name' => $name, 'venue_id' => $venue, 'venue_name' => $venue ? "Venue {$venue}" : null,
            'price_amount' => $price, 'price_currency' => $cur, 'is_entire_place' => $entire, 'is_published' => $pub];
}
/** Normalise + compute in one go (the pure core the DB layer wraps). */
function plan(array $data, array $rooms, array $maps): array {
    return re_compute(re_normalize_request($data), $rooms, $maps);
}

// ── Ranges: inclusive (UI) → exclusive (storage) ────────────────────────────
check('ranges: [first, last] becomes [first, last + 1)',
    re_parse_ranges([['2099-01-01', '2099-01-03']]) === [['2099-01-01', '2099-01-04']]);
check('ranges: one night (first == last)',
    re_parse_ranges([['2099-01-05', '2099-01-05']]) === [['2099-01-05', '2099-01-06']]);
check('ranges: {from, to} objects are accepted too',
    re_parse_ranges([['from' => '2099-02-01', 'to' => '2099-02-02']]) === [['2099-02-01', '2099-02-03']]);
check('ranges: abutting inclusive ranges merge (3rd–4th nights)',
    re_parse_ranges([['2099-01-01', '2099-01-03'], ['2099-01-04', '2099-01-05']]) === [['2099-01-01', '2099-01-06']]);
check('ranges: overlapping ranges merge, sorted',
    re_parse_ranges([['2099-03-05', '2099-03-10'], ['2099-03-01', '2099-03-06']]) === [['2099-03-01', '2099-03-11']]);
check('ranges: year end crosses correctly',
    re_parse_ranges([['2099-12-31', '2099-12-31']]) === [['2099-12-31', '2100-01-01']]);
check('ranges: last before first refused', refusal(fn() => re_parse_ranges([['2099-01-05', '2099-01-01']])) !== null);
check('ranges: non-padded date refused (strict write date)', refusal(fn() => re_parse_ranges([['2099-1-01', '2099-01-05']])) !== null);
check('ranges: impossible date refused', refusal(fn() => re_parse_ranges([['2099-02-30', '2099-03-01']])) !== null);
check('ranges: none refused', refusal(fn() => re_parse_ranges([])) !== null);
check('nights: listed one per night', re_nights([['2099-01-30', '2099-02-02']]) === ['2099-01-30', '2099-01-31', '2099-02-01']);

// ── Request validation ─────────────────────────────────────────────────────
$base = ['rooms' => [1], 'ranges' => [['2099-06-01', '2099-06-05']]];
check('request: fixed needs an amount > 0', refusal(fn() => re_normalize_request($base + ['mode' => 'fixed', 'amount' => 0])) !== null);
check('request: fixed negative refused',   refusal(fn() => re_normalize_request($base + ['mode' => 'fixed', 'amount' => -5])) !== null);
check('request: percent 0 refused',        refusal(fn() => re_normalize_request($base + ['mode' => 'percent', 'pct' => 0])) !== null);
check('request: percent -100 refused',     refusal(fn() => re_normalize_request($base + ['mode' => 'percent', 'pct' => -100])) !== null);
check('request: match needs a label',      refusal(fn() => re_normalize_request($base + ['mode' => 'match', 'match_label' => ' '])) !== null);
check('request: unknown mode refused',     refusal(fn() => re_normalize_request($base + ['mode' => 'double'])) !== null);
check('request: no rooms refused',         refusal(fn() => re_normalize_request(['rooms' => [], 'ranges' => $base['ranges'], 'mode' => 'base'])) !== null);
check('request: 51 rooms refused',         refusal(fn() => re_normalize_request(['rooms' => range(1, 51), 'ranges' => $base['ranges'], 'mode' => 'base'])) !== null);
check('request: 50 rooms accepted',        refusal(fn() => re_normalize_request(['rooms' => range(1, 50), 'ranges' => $base['ranges'], 'mode' => 'base'])) === null);
check('request: 1,101 nights refused',
    refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['2099-01-01', date('Y-m-d', strtotime('2099-01-01 +1100 day'))]], 'mode' => 'base'])) !== null);
check('request: 1,100 nights accepted',
    refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['2099-01-01', date('Y-m-d', strtotime('2099-01-01 +1099 day'))]], 'mode' => 'base'])) === null);
$n = re_normalize_request(['rooms' => ['3', 3, 7], 'ranges' => $base['ranges'], 'mode' => 'fixed', 'amount' => '51000']);
check('request: room ids de-duplicated + cast', $n['rooms'] === [3, 7]);
check('request: label absent = keep each night\'s label', $n['label'] === null && $n['keep_label'] === true);
check('request: update_buyouts defaults off', $n['update_buyouts'] === false);
$n = re_normalize_request($base + ['mode' => 'fixed', 'amount' => 10, 'label' => '  Mid season ']);
check('request: label trimmed and set', $n['label'] === 'Mid season' && $n['keep_label'] === false);
$n = re_normalize_request($base + ['mode' => 'fixed', 'amount' => 10, 'label' => '']);
check('request: empty label = set "no label"', $n['label'] === null && $n['keep_label'] === false);
$n = re_normalize_request($base + ['mode' => 'match', 'match_label' => 'Peak season']);
check('request: match label defaults to the matched season', $n['label'] === 'Peak season' && $n['keep_label'] === false);

// ── Rounding + most common ──────────────────────────────────────────────────
check('round: KES to nearest 10 (down)', re_round_price(48363.0, 'KES') === 48360.0);
check('round: KES to nearest 10 (half up)', re_round_price(48365.0, 'KES') === 48370.0);
check('round: USD to nearest 1 (half up)', re_round_price(104.5, 'USD') === 105.0);
check('round: USD to nearest 1 (down)', re_round_price(104.4, 'usd') === 104.0);
check('most common: the majority price', re_most_common_price([300.0, 300.0, 250.0]) === 300.0);
check('most common: a tie takes the higher price', re_most_common_price([250.0, 300.0]) === 300.0);
check('most common: none → null', re_most_common_price([]) === null);
check('clip: a past run is dropped', re_clip_runs_from([['2026-01-01','2026-03-01']], '2026-09-29') === []);
check('clip: a straddling run starts today', re_clip_runs_from([['2026-01-01','2026-12-31']], '2026-09-29') === [['2026-09-29','2026-12-31']]);
check('clip: a run ending today is kept', re_clip_runs_from([['2026-09-01','2026-09-29']], '2026-09-29') === [['2026-09-29','2026-09-29']]);
check('clip: a future run is untouched', re_clip_runs_from([['2026-10-01','2026-10-05']], '2026-09-29') === [['2026-10-01','2026-10-05']]);
check('majority label: most rooms win', re_majority_label(['Mid', 'Mid', 'Peak']) === 'Mid');
check('majority label: base majority → no label', re_majority_label([null, null, 'Peak']) === null);
check('majority label: tie prefers a season over base', re_majority_label([null, 'Mid']) === 'Mid');
check('majority label: tie between seasons → the higher season', re_majority_label(['Mid', 'Peak']) === 'Peak');

// ── Limits are checked before any work (no fatal on a huge request) ─────────
$m = refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['0001-01-01', '9999-12-30']], 'mode' => 'base']));
check('cap: a 0001 → 9999 range is refused (before building ~3.65M nights)', $m !== null && str_contains($m, 'nights per change'));
check('cap: a last night of 9999-12-31 (checkout in year 10000) is refused, never silently dropped',
    refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['0001-01-01', '9999-12-31']], 'mode' => 'base'])) !== null);
$m = refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['2099-01-01', '2099-12-31'], ['2100-01-01', '2100-12-31'], ['2101-01-01', '2101-12-31'], ['2102-01-01', '2102-12-31']], 'mode' => 'base']));
check('cap: the night cap counts every merged range together', $m !== null && str_contains($m, 'nights per change'));
check('cap: a huge room list is refused', refusal(fn() => re_normalize_request(['rooms' => range(1, 200000), 'ranges' => [['2099-01-01', '2099-01-01']], 'mode' => 'base'])) !== null);

// ── Amount ceiling (rates.price_amount is NUMERIC(10,2)) ────────────────────
check('max: RE_MAX_AMOUNT is the NUMERIC(10,2) ceiling', RE_MAX_AMOUNT === 99999999.99);
$m = refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['2099-01-01', '2099-01-01']], 'mode' => 'fixed', 'amount' => 100000000]));
check('max: a fixed price above the ceiling is refused, readably', $m !== null && str_contains($m, 'maximum price'));
check('max: a fixed price AT the ceiling is accepted',
    refusal(fn() => re_normalize_request(['rooms' => [1], 'ranges' => [['2099-01-01', '2099-01-01']], 'mode' => 'fixed', 'amount' => 99999999.99])) === null);

// ── Run grouping ────────────────────────────────────────────────────────────
$t = fn(float $p, ?string $l, bool $b = false) => ['price' => $p, 'label' => $l, 'base' => $b];
$runs = re_group_runs([
    '2099-01-01' => $t(100, 'A'), '2099-01-02' => $t(100, 'A'), '2099-01-03' => $t(200, 'A'),
    '2099-01-04' => $t(100, 'A'), '2099-01-06' => $t(100, 'A'), '2099-01-07' => $t(100, 'B'),
    '2099-01-08' => $t(90, null, true), '2099-01-09' => $t(90, null, true),
]);
check('runs: contiguous equal nights join; gaps, price and label changes split',
    array_map(fn($r) => [$r['from'], $r['to'], $r['price'], $r['label'], $r['base']], $runs) === [
        ['2099-01-01', '2099-01-03', 100.0, 'A', false],
        ['2099-01-03', '2099-01-04', 200.0, 'A', false],
        ['2099-01-04', '2099-01-05', 100.0, 'A', false],
        ['2099-01-06', '2099-01-07', 100.0, 'A', false],
        ['2099-01-07', '2099-01-08', 100.0, 'B', false],
        ['2099-01-08', '2099-01-10', 90.0, null, true],
    ]);
check('runs: input order does not matter',
    re_group_runs(['2099-01-02' => $t(5, null), '2099-01-01' => $t(5, null)])[0]['from'] === '2099-01-01');

// ── Modes (pure) ────────────────────────────────────────────────────────────
$rooms = [
    1 => mk_room(1, 'Haze Suite', 10, 30000, 'KES'),
    2 => mk_room(2, 'Glow Suite', 10, 31000, 'KES'),
    3 => mk_room(3, 'Maji Suite', 20, 400, 'USD'),
];
$maps = [
    1 => mk_map('2099-06-01', 2, 30000, null, false) + mk_map('2099-06-03', 3, 48000, 'Mid season'),
    2 => mk_map('2099-06-01', 5, 51000, 'Mid season'),
    3 => mk_map('2099-06-01', 5, 400, null, false),
];
$rng = [['2099-06-01', '2099-06-05']];

// fixed
$p = plan(['rooms' => [1, 2], 'ranges' => $rng, 'mode' => 'fixed', 'amount' => 51000], $rooms, $maps);
check('fixed: every night of room 1 targets 51,000',
    count($p['rooms'][1]['targets']) === 5 && array_unique(array_column($p['rooms'][1]['targets'], 'price')) === [51000.0]);
check('fixed: keep label — base nights stay unlabelled, Mid nights keep Mid',
    $p['rooms'][1]['targets']['2099-06-01']['label'] === null && $p['rooms'][1]['targets']['2099-06-03']['label'] === 'Mid season');
check('fixed: nights already at price + label are unchanged (no target)',
    $p['rooms'][2]['targets'] === [] && $p['rooms'][2]['status'] === 'unchanged');
$p = plan(['rooms' => [1], 'ranges' => $rng, 'mode' => 'fixed', 'amount' => 51000, 'label' => 'Peak season'], $rooms, $maps);
check('fixed: a set label is written on every night',
    array_unique(array_column($p['rooms'][1]['targets'], 'label')) === ['Peak season']);
$msg = refusal(fn() => plan(['rooms' => [1, 3], 'ranges' => $rng, 'mode' => 'fixed', 'amount' => 100], $rooms, $maps));
check('fixed: mixed currencies refused, naming the rooms per currency',
    $msg !== null && str_contains($msg, 'KES: Haze Suite') && str_contains($msg, 'USD: Maji Suite'));
check('fixed: a price of 0.004 (≤ 0 once stored) refused',
    refusal(fn() => plan(['rooms' => [3], 'ranges' => $rng, 'mode' => 'fixed', 'amount' => 0.004], $rooms, $maps)) !== null);

// percent
$p = plan(['rooms' => [1, 3], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 5], $rooms, $maps);
check('percent: KES 48,000 +5% = 50,400', $p['rooms'][1]['targets']['2099-06-03']['price'] === 50400.0);
check('percent: KES 30,000 +5% = 31,500', $p['rooms'][1]['targets']['2099-06-01']['price'] === 31500.0);
check('percent: keeps each night\'s own label',
    $p['rooms'][1]['targets']['2099-06-03']['label'] === 'Mid season' && $p['rooms'][1]['targets']['2099-06-01']['label'] === null);
check('percent: mixed currencies are fine', $p['rooms'][3]['targets']['2099-06-01']['price'] === 420.0);
$maps2 = $maps; $maps2[1]['2099-06-03']['price'] = 30123.0;
$p = plan(['rooms' => [1], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 5], $rooms, $maps2);
check('percent: KES rounds to the nearest 10 (31,629.15 → 31,630)', $p['rooms'][1]['targets']['2099-06-03']['price'] === 31630.0);
$p = plan(['rooms' => [3], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 2.5], [3 => mk_room(3, 'Maji Suite', 20, 199, 'USD')], [3 => mk_map('2099-06-01', 5, 199, null, false)]);
check('percent: USD rounds to the nearest 1 (203.975 → 204)', $p['rooms'][3]['targets']['2099-06-01']['price'] === 204.0);
$zeroRooms = [4 => mk_room(4, 'Zuri — Whole Villa', 20, 0, 'USD')];
$zeroMaps  = [4 => mk_map('2099-06-01', 3, 0, null, false) + mk_map('2099-06-04', 2, 800, 'Peak season')];
$p = plan(['rooms' => [4], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 10], $zeroRooms, $zeroMaps);
check('percent: unpriced nights are left alone (noted), priced ones change',
    count($p['rooms'][4]['targets']) === 2 && $p['rooms'][4]['targets']['2099-06-04']['price'] === 880.0
    && $p['rooms'][4]['notes'] !== []);
$p = plan(['rooms' => [4], 'ranges' => [['2099-06-01', '2099-06-03']], 'mode' => 'percent', 'pct' => 10], $zeroRooms, $zeroMaps);
check('percent: a room with no price on any night is skipped', $p['rooms'][4]['status'] === 'skipped' && $p['rooms'][4]['skipped'] !== null);
check('percent: a result that rounds to 0 is refused',
    refusal(fn() => plan(['rooms' => [1], 'ranges' => $rng, 'mode' => 'percent', 'pct' => -99.99], $rooms, $maps)) !== null);
// Exact half-way results must round UP, not fall to x.4999… in binary.
$pct = fn(float $price, string $cur, float $p) => plan(['rooms' => [7], 'ranges' => [['2099-06-01', '2099-06-01']], 'mode' => 'percent', 'pct' => $p],
    [7 => mk_room(7, 'Half Room', 10, $price, $cur)], [7 => mk_map('2099-06-01', 1, $price, null, false)])['rooms'][7]['targets']['2099-06-01']['price'] ?? null;
check('percent: KES 350 −30% = 245 → 250 (half up)', $pct(350, 'KES', -30) === 250.0);
check('percent: KES 1,450 −30% = 1,015 → 1,020 (half up)', $pct(1450, 'KES', -30) === 1020.0);
check('percent: USD 5 −30% = 3.5 → 4 (half up)', $pct(5, 'USD', -30) === 4.0);
$m = refusal(fn() => plan(['rooms' => [7], 'ranges' => [['2099-06-01', '2099-06-01']], 'mode' => 'percent', 'pct' => 500],
    [7 => mk_room(7, 'Big Room', 10, 20000000, 'KES')], [7 => mk_map('2099-06-01', 1, 20000000, null, false)]));
check('percent: a result above the maximum price is refused', $m !== null && str_contains($m, 'maximum price') && str_contains($m, 'Big Room'));

// Whitespace in a stored label is not a difference.
$wsRooms = [8 => mk_room(8, 'Space Room', 10, 100, 'USD')];
$wsMaps  = [8 => mk_map('2099-06-01', 2, 200, ' Mid season ')];
$p = plan(['rooms' => [8], 'ranges' => [['2099-06-01', '2099-06-02']], 'mode' => 'fixed', 'amount' => 200, 'label' => 'Mid season'], $wsRooms, $wsMaps);
check('label: " Mid season " already at 200 / "Mid season" is unchanged', $p['rooms'][8]['targets'] === [] && $p['rooms'][8]['status'] === 'unchanged');

// match a season
$mRooms = [
    5 => mk_room(5, 'Tide Suite', 10, 30000, 'KES'),
    6 => mk_room(6, 'Drift Suite', 10, 30000, 'KES'),
];
$mMaps = [
    // 2099: Peak at 72,000 for 5 nights and 70,000 for 2 → most common = 72,000.
    5 => mk_map('2099-06-01', 5, 30000, null, false) + mk_map('2099-12-20', 5, 72000, 'Peak season')
       + mk_map('2099-12-25', 2, 70000, 'Peak season') + mk_map('2100-06-01', 5, 30000, null, false),
    6 => mk_map('2099-06-01', 5, 48000, 'Mid season') + mk_map('2100-06-01', 5, 30000, null, false),
];
$p = plan(['rooms' => [5, 6], 'ranges' => $rng, 'mode' => 'match', 'match_label' => 'peak season'], $mRooms, $mMaps);
check('match: most common price for the label that year (72,000)',
    array_unique(array_column($p['rooms'][5]['targets'], 'price')) === [72000.0] && count($p['rooms'][5]['targets']) === 5);
check('match: label defaults to the matched season',
    array_unique(array_column($p['rooms'][5]['targets'], 'label')) === ['peak season']);
check('match: a room without that season is skipped with a reason',
    $p['rooms'][6]['status'] === 'skipped' && str_contains((string)$p['rooms'][6]['skipped'], 'peak season'));
$p = plan(['rooms' => [5], 'ranges' => [['2099-06-01', '2099-06-02'], ['2100-06-01', '2100-06-02']], 'mode' => 'match', 'match_label' => 'Peak season'], $mRooms, $mMaps);
check('match: the price comes from the SAME year — nights in a year without it are left alone',
    array_keys($p['rooms'][5]['targets']) === ['2099-06-01', '2099-06-02'] && $p['rooms'][5]['notes'] !== []);
check('match: window helper spans whole calendar years',
    re_load_window(re_normalize_request(['rooms' => [5], 'ranges' => [['2099-06-01', '2100-02-02']], 'mode' => 'match', 'match_label' => 'X']))
        === ['2099-01-01', '2101-01-01']);
check('window: other modes load only the span',
    re_load_window(re_normalize_request(['rooms' => [5], 'ranges' => $rng, 'mode' => 'base'])) === ['2099-06-01', '2099-06-06']);

// back to base
$p = plan(['rooms' => [1], 'ranges' => $rng, 'mode' => 'base'], $rooms, $maps);
check('base: only override nights change, to the room\'s base price',
    array_keys($p['rooms'][1]['targets']) === ['2099-06-03', '2099-06-04', '2099-06-05']
    && $p['rooms'][1]['targets']['2099-06-03'] === ['price' => 30000.0, 'label' => null, 'base' => true]);
$p = plan(['rooms' => [4], 'ranges' => $rng, 'mode' => 'base'], $zeroRooms, $zeroMaps);
check('base: going back to a 0 base price is allowed (removes the override)', count($p['rooms'][4]['targets']) === 2);

// ── Buyouts (pure) ──────────────────────────────────────────────────────────
$bRooms = [
    10 => mk_room(10, 'Zuri — Whole Villa', 30, 0, 'USD', true),
    11 => mk_room(11, 'Maji Suite', 30, 100, 'USD'),
    12 => mk_room(12, 'Mwezi Suite', 30, 120, 'USD'),
    13 => mk_room(13, 'Ua Suite', 30, 150, 'USD'),
    14 => mk_room(14, 'Anga Suite', 30, 0, 'USD'),                 // unpriced → left out
    15 => mk_room(15, 'Jua Suite', 30, 90, 'KES'),                 // other currency → left out
    16 => mk_room(16, 'Old Suite', 30, 500, 'USD', false, false),  // unpublished → not counted
];
$bMaps = [
    10 => mk_map('2099-06-01', 3, 0, null, false),
    11 => mk_map('2099-06-01', 3, 100, null, false),
    12 => mk_map('2099-06-01', 3, 120, null, false),
    13 => mk_map('2099-06-01', 3, 150, 'Mid season'),
    14 => mk_map('2099-06-01', 3, 0, null, false),
    15 => mk_map('2099-06-01', 3, 90, null, false),
    16 => mk_map('2099-06-01', 3, 500, null, false),
];
$bRng = [['2099-06-01', '2099-06-03']];
$p = plan(['rooms' => [11, 12], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 200, 'label' => 'Mid season', 'update_buyouts' => true], $bRooms, $bMaps);
check('buyout: offered', $p['buyouts_available'] === true && count($p['buyouts']) === 1);
check('buyout: price = sum of the other published rooms AFTER the change (200+200+150)',
    ($p['rooms'][10]['targets']['2099-06-01']['price'] ?? null) === 550.0 && $p['rooms'][10]['is_buyout'] === true);
check('buyout: label = the label most rooms carry that night', $p['rooms'][10]['targets']['2099-06-01']['label'] === 'Mid season');
$lo = array_column($p['buyouts'][0]['left_out'], 'reason', 'room_id');
check('buyout: 0-priced and other-currency rooms left out and named',
    isset($lo[14], $lo[15]) && !isset($lo[16]) && count($p['rooms'][10]['targets']) === 3);
$p = plan(['rooms' => [11, 12], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 200], $bRooms, $bMaps);
check('buyout: not written unless asked (still offered)',
    $p['buyouts_available'] === true && !isset($p['rooms'][10]));
$p = plan(['rooms' => [10, 11], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 200, 'update_buyouts' => true], $bRooms, $bMaps);
check('buyout: not offered when the buyout room itself is selected', $p['buyouts_available'] === false);
$p = plan(['rooms' => [16], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 700, 'update_buyouts' => true], $bRooms, $bMaps);
check('buyout: a change to an unpublished room does not touch the buyout', !isset($p['rooms'][10]));
$two = $bRooms; $two[17] = mk_room(17, 'Second Villa', 30, 0, 'USD', true);
$p = plan(['rooms' => [11], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 200, 'update_buyouts' => true], $two, $bMaps + [17 => mk_map('2099-06-01', 3, 0, null, false)]);
check('buyout: not offered when a property has two whole-property rooms', $p['buyouts_available'] === false);
$m = refusal(fn() => plan(['rooms' => [11, 12], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 60000000, 'update_buyouts' => true], $bRooms, $bMaps));
check('buyout: a sum above the maximum price is refused', $m !== null && str_contains($m, 'maximum price') && str_contains($m, 'Zuri — Whole Villa'));
$wsB = $bMaps; $wsB[10] = mk_map('2099-06-01', 3, 550, ' Mid season ');
$p = plan(['rooms' => [11, 12], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 200, 'label' => 'Mid season', 'update_buyouts' => true], $bRooms, $wsB);
check('buyout: already at the sum with a whitespace-padded label → unchanged', ($p['rooms'][10]['targets'] ?? null) === []);

// ── "Sum of the rooms" mode — the Buyout check (pure) ───────────────────────
// Published, priced, same-currency rooms of Zuri add up to 100 + 120 + 150 = 370.
$sm = $bMaps; $sm[10] = mk_map('2099-06-01', 3, 370, 'Mid season');
$sreqS = ['rooms' => [10], 'ranges' => $bRng, 'mode' => 'sum'];
$p = plan($sreqS, $bRooms, $sm);
check('sum: buyout already at the sum → unchanged', $p['rooms'][10]['status'] === 'unchanged' && $p['rooms'][10]['targets'] === []
    && $p['rooms'][10]['is_buyout'] === true);
$sm[10]['2099-06-02'] = ['price' => 300.0, 'label' => 'Mid season', 'rate_id' => 1, 'is_override' => true];
$p = plan($sreqS, $bRooms, $sm);
check('sum: only the night that differs changes, to the sum',
    array_keys($p['rooms'][10]['targets']) === ['2099-06-02'] && $p['rooms'][10]['targets']['2099-06-02']['price'] === 370.0);
check('sum: label = the label most rooms carry that night',
    $p['rooms'][10]['targets']['2099-06-02']['label'] === re_majority_label([null, null, 'Mid season']));
$notes = implode(' ', $p['rooms'][10]['notes']);
check('sum: 0-priced and other-currency rooms left out and named, unpublished ignored',
    str_contains($notes, 'Anga Suite') && str_contains($notes, 'Jua Suite') && !str_contains($notes, 'Old Suite'));
$bs = $bMaps; $bs[10] = mk_map('2099-06-01', 3, 370, null, false);
check('sum: a base-price night already at the sum counts as matching', plan($sreqS, $bRooms, $bs)['rooms'][10]['targets'] === []);
$lblOnly = $bMaps; $lblOnly[10] = mk_map('2099-06-01', 3, 370, 'Peak');
check('sum: a different label alone is not a mismatch (price is what must add up)', plan($sreqS, $bRooms, $lblOnly)['rooms'][10]['targets'] === []);
$none = ['rooms' => $bRooms, 'maps' => $bMaps];
foreach ([11, 12, 13] as $mid) { $none['maps'][$mid] = mk_map('2099-06-01', 3, 0, null, false); }
$p = plan($sreqS, $bRooms, $none['maps']);
check('sum: a night with no priced rooms is left alone, with a note',
    $p['rooms'][10]['targets'] === [] && str_contains(implode(' ', $p['rooms'][10]['notes']), 'no priced rooms'));
check('sum: refuses a room that is not a whole-property room',
    (refusal(fn() => plan(['rooms' => [11], 'ranges' => $bRng, 'mode' => 'sum'], $bRooms, $bMaps)) ?? '') !== ''
    && str_contains((string)refusal(fn() => plan(['rooms' => [11], 'ranges' => $bRng, 'mode' => 'sum'], $bRooms, $bMaps)), 'whole-property'));
check('sum: refuses a property with two whole-property rooms',
    refusal(fn() => plan($sreqS, $two, $bMaps + [17 => mk_map('2099-06-01', 3, 0, null, false)])) !== null);
$solo = [10 => $bRooms[10], 11 => $bRooms[11]];
check('sum: refuses a property with fewer than two published rooms', refusal(fn() => plan($sreqS, $solo, $bMaps)) !== null);
$sq = re_normalize_request($sreqS);
check('sum: summary says what it does', str_contains(re_summary($sq, re_compute($sq, $bRooms, $sm), $bRooms), 'sum of the rooms'));
check('sum: the buyout room is not also "buyout updated"', !str_contains(re_summary($sq, re_compute($sq, $bRooms, $sm), $bRooms), 'buyout updated'));

// ── Money display: cents only when there are cents ──────────────────────────
check('display: $99.50 keeps its cents', re_range_text(99.5, 99.5, 'USD') === '$99.50');
check('display: a range with cents on one end', re_range_text(99.5, 120.0, 'USD') === '$99.50 – 120');
check('display: whole amounts stay whole', re_range_text(48000.0, 51000.0, 'KES') === 'KES 48,000 – 51,000');
$sreq = re_normalize_request(['rooms' => [11], 'ranges' => $bRng, 'mode' => 'fixed', 'amount' => 99.5]);
check('display: summary shows $99.50', str_contains(re_summary($sreq, re_compute($sreq, $bRooms, $bMaps), $bRooms), '$99.50'));

// ── Fingerprint (pure) ──────────────────────────────────────────────────────
$freq = re_normalize_request(['rooms' => [1, 2], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 5]);
$fp1  = re_fingerprint($freq, re_compute($freq, $rooms, $maps));
check('fingerprint: 64 hex characters, stable', preg_match('/^[0-9a-f]{64}$/', $fp1) === 1 && $fp1 === re_fingerprint($freq, re_compute($freq, $rooms, $maps)));
$maps3 = $maps; $maps3[2]['2099-06-02']['price'] = 52000.0;
check('fingerprint: changes when a current price changes', re_fingerprint($freq, re_compute($freq, $rooms, $maps3)) !== $fp1);
$freq2 = re_normalize_request(['rooms' => [1, 2], 'ranges' => $rng, 'mode' => 'percent', 'pct' => 6]);
check('fingerprint: changes with the request', re_fingerprint($freq2, re_compute($freq2, $rooms, $maps)) !== $fp1);

// ── DB block (rolled back) ─────────────────────────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "\nSKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $hadLog = rate_log_supported(true);
        /** Preview, then apply with that preview's fingerprint — what the UI does. */
        $apply = function (array $d, ?int $adminId = 1): array {
            return rate_editor_apply($d + ['fingerprint' => rate_editor_preview($d)['fingerprint']], $adminId);
        };
        /** The conflict (409) message a callable throws, or null. */
        $conflict = function (callable $fn): ?string {
            try { $fn(); return null; } catch (RateEditorConflict $e) { return $e->getMessage(); }
        };

        // A test property: one whole-property room + three suites (one unpriced).
        $vid = (int) db_query("INSERT INTO venues (slug, name, sort_order, is_published)
                               VALUES ('zz-rate-editor-test', 'ZZ Test Villa', 999, TRUE) RETURNING id")->fetchColumn();
        $mkRoom = function (string $slug, string $name, float $price, string $cur, bool $entire) use ($vid): int {
            return (int) db_query(
                "INSERT INTO rooms (slug, name, price_amount, price_currency, venue_id, is_entire_place, is_published, sort_order)
                 VALUES (:s, :n, :p, :c, :v, :e, TRUE, 0) RETURNING id",
                [':s' => $slug, ':n' => $name, ':p' => $price, ':c' => $cur, ':v' => $vid, ':e' => $entire ? 'true' : 'false']
            )->fetchColumn();
        };
        $B  = $mkRoom('zz-re-whole', 'ZZ Whole Villa', 0, 'USD', true);
        $S1 = $mkRoom('zz-re-s1', 'ZZ Suite One', 100, 'USD', false);
        $S2 = $mkRoom('zz-re-s2', 'ZZ Suite Two', 120, 'USD', false);
        $S3 = $mkRoom('zz-re-s3', 'ZZ Suite Three', 0, 'USD', false);
        $K  = $mkRoom('zz-re-k', 'ZZ Kes Room', 5000, 'KES', false);
        $ins = fn(int $r, string $f, string $t, float $p, ?string $l) => db_query(
            'INSERT INTO rates (room_id, date_from, date_to, price_amount, label) VALUES (:r, :f, :t, :p, :l)',
            [':r' => $r, ':f' => $f, ':t' => $t, ':p' => $p, ':l' => $l]);
        $ins($S1, '2099-06-01', '2099-07-01', 150, 'Mid season');
        $ins($S2, '2099-06-01', '2099-07-01', 170, 'Mid season');
        $ins($S1, '2099-12-20', '2100-01-05', 300, 'Peak season');
        $ins($B,  '2099-06-01', '2099-07-01', 320, 'Mid season');

        $q = fn(int $r, float $def, string $a, string $b) => room_stay_quote($r, $def, $a, $b)['total'];
        $snap = fn() => [$q($S1, 100, '2099-06-01', '2099-07-01'), $q($S2, 120, '2099-06-01', '2099-07-01'),
                         $q($B, 0, '2099-06-01', '2099-07-01'), $q($S1, 100, '2099-12-01', '2100-01-10')];
        $overlaps = fn() => (int) db_query(
            'SELECT COUNT(*) FROM rates a JOIN rates b ON a.room_id = b.room_id AND a.id < b.id
               AND a.date_from < b.date_to AND b.date_from < a.date_to
             WHERE a.room_id IN (' . implode(',', [$B, $S1, $S2, $S3, $K]) . ')')->fetchColumn();
        $q0 = $snap();

        // Pre-migration: apply still works, no log.
        if (!$hadLog) {
            $r = $apply(['rooms' => [$K], 'ranges' => [['2099-03-01', '2099-03-02']], 'mode' => 'fixed', 'amount' => 6000], 1);
            check('pre-migration: apply works without the log', $r['logged'] === false && $r['change_id'] === null && $r['log_note'] !== null);
            check('pre-migration: price written', $q($K, 5000, '2099-03-01', '2099-03-03') === 12000.0);
            $pdo->exec((string) file_get_contents(__DIR__ . '/../db/migrations/add_rate_change_log.sql'));
        }
        check('log: supported once the table exists', rate_log_supported(true));

        // Preview writes nothing.
        $before = (int) db_query('SELECT COUNT(*) FROM rates')->fetchColumn();
        $pv = rate_editor_preview(['rooms' => [$S1, $S2], 'ranges' => [['2099-06-10', '2099-06-19']], 'mode' => 'fixed', 'amount' => 200, 'update_buyouts' => true]);
        check('preview: writes nothing', (int) db_query('SELECT COUNT(*) FROM rates')->fetchColumn() === $before);
        $pvRows = array_column($pv['rooms'], null, 'room_id');
        check('preview: per-room before → after + nights',
            $pvRows[$S1]['nights'] === 10 && $pvRows[$S1]['before']['min'] === 150.0 && $pvRows[$S1]['after']['max'] === 200.0);
        check('preview: buyout row marked, S3 (unpriced) named as left out',
            $pvRows[$B]['is_buyout'] === true && $pvRows[$B]['after']['min'] === 400.0
            && in_array($S3, array_column($pv['buyouts'][0]['left_out'], 'room_id'), true));
        check('preview: totals', $pv['totals']['rooms'] === 3 && $pv['totals']['nights'] === 30);

        // A: fixed 200 on S1+S2, 10–19 Jun, buyout on.
        $A = $apply(['rooms' => [$S1, $S2], 'ranges' => [['2099-06-10', '2099-06-19']], 'mode' => 'fixed', 'amount' => 200, 'update_buyouts' => true], 1);
        check('apply A: logged', $A['logged'] === true && $A['change_id'] > 0);
        check('apply A: S1 quote reflects it', $q($S1, 100, '2099-06-10', '2099-06-20') === 2000.0);
        check('apply A: S2 quote reflects it', $q($S2, 120, '2099-06-10', '2099-06-20') === 2000.0);
        check('apply A: nights outside untouched', $q($S1, 100, '2099-06-09', '2099-06-10') === 150.0 && $q($S1, 100, '2099-06-20', '2099-06-21') === 150.0);
        check('apply A: buyout = 200 + 200 (S3 unpriced left out)', $q($B, 0, '2099-06-10', '2099-06-20') === 4000.0);
        check('apply A: label kept (Mid season)', rates_nightly_map($S1, 100, '2099-06-12', '2099-06-13')['2099-06-12']['label'] === 'Mid season');
        check('apply A: no overlapping rows', $overlaps() === 0);
        $log = db_query('SELECT * FROM rate_change_log WHERE id = :id', [':id' => $A['change_id']])->fetch();
        $logRooms = array_map('intval', explode(',', trim((string)$log['rooms'], '{}')));
        sort($logRooms);
        $want = [$B, $S1, $S2]; sort($want);
        check('log A: rooms, span, before/after', $logRooms === $want && $log['span_from'] === '2099-06-10' && $log['span_to'] === '2099-06-20'
            && count(json_decode($log['before_json'], true)) === 3 && count(json_decode($log['after_json'], true)) >= 3
            && str_contains((string)$log['summary'], 'ZZ Test Villa'));
        $qA = $snap();

        // B: +10% on S1 over 15–17 Jun (overlaps A).
        $Bc = $apply(['rooms' => [$S1], 'ranges' => [['2099-06-15', '2099-06-17']], 'mode' => 'percent', 'pct' => 10], 1);
        check('apply B (percent): 200 → 220', $q($S1, 100, '2099-06-15', '2099-06-18') === 660.0 && $overlaps() === 0);

        // Undo A refused while B (newer, overlapping) stands.
        $m = refusal(fn() => rate_editor_undo((int)$A['change_id'], 1));
        check('undo: refused while a newer overlapping change stands', $m !== null && str_contains($m, 'newer'));
        $lst = array_column(rate_editor_log(10)['changes'], null, 'id');
        check('log list: A cannot be undone, B can', $lst[$A['change_id']]['can_undo'] === false && $lst[$Bc['change_id']]['can_undo'] === true);

        rate_editor_undo((int)$Bc['change_id'], 1);
        check('undo B: back to after-A exactly', $snap() === $qA && $overlaps() === 0);
        check('undo B: marked undone', db_query('SELECT undone_at IS NOT NULL FROM rate_change_log WHERE id = :id', [':id' => $Bc['change_id']])->fetchColumn() === true);
        check('undo B: twice refused', refusal(fn() => rate_editor_undo((int)$Bc['change_id'], 1)) !== null);

        rate_editor_undo((int)$A['change_id'], 1);
        check('undo A: every quote back to the original exactly', $snap() === $q0 && $overlaps() === 0);
        check('undo A: buyout restored', $q($B, 0, '2099-06-10', '2099-06-20') === 3200.0);

        // Match: move Peak onto 15–19 Dec for S1 and S2 (S2 has no Peak → skipped).
        $M = $apply(['rooms' => [$S1, $S2], 'ranges' => [['2099-12-15', '2099-12-19']], 'mode' => 'match', 'match_label' => 'Peak season'], 1);
        $mRows = array_column($M['preview']['rooms'], null, 'room_id');
        check('match: S1 gets its Peak price', $q($S1, 100, '2099-12-15', '2099-12-20') === 1500.0
            && rates_nightly_map($S1, 100, '2099-12-15', '2099-12-16')['2099-12-15']['label'] === 'Peak season');
        check('match: S2 skipped and listed', $mRows[$S2]['status'] === 'skipped' && $q($S2, 120, '2099-12-15', '2099-12-20') === 600.0);

        // Base: remove S1's override on 25–26 Jun.
        $apply(['rooms' => [$S1], 'ranges' => [['2099-06-25', '2099-06-26']], 'mode' => 'base'], 1);
        check('base: override removed → base price', $q($S1, 100, '2099-06-25', '2099-06-27') === 200.0
            && $q($S1, 100, '2099-06-24', '2099-06-25') === 150.0 && $q($S1, 100, '2099-06-27', '2099-06-28') === 150.0 && $overlaps() === 0);

        // Nothing to change is refused (and writes no log row).
        $cnt = (int) db_query('SELECT COUNT(*) FROM rate_change_log')->fetchColumn();
        check('apply: nothing to change refused', refusal(fn() => $apply(['rooms' => [$S1], 'ranges' => [['2099-06-25', '2099-06-26']], 'mode' => 'base'], 1)) !== null
            && (int) db_query('SELECT COUNT(*) FROM rate_change_log')->fetchColumn() === $cnt);

        // Undo refused when the rates were edited elsewhere since.
        $C = $apply(['rooms' => [$S2], 'ranges' => [['2099-08-01', '2099-08-05']], 'mode' => 'fixed', 'amount' => 250, 'label' => 'Event'], 1);
        rates_apply_ranges($S2, [['2099-08-03', '2099-08-04']], 999.0, 'Manual');
        $m = refusal(fn() => rate_editor_undo((int)$C['change_id'], 1));
        check('undo: refused when rows changed since', $m !== null && str_contains($m, 'edited elsewhere'));
        $lst = array_column(rate_editor_log(10)['changes'], null, 'id');
        check('log list: edited-since change shows cannot undo', $lst[$C['change_id']]['can_undo'] === false);

        // Edits OUTSIDE the span do not block undo (clipped comparison).
        $D = $apply(['rooms' => [$K], 'ranges' => [['2099-09-10', '2099-09-12']], 'mode' => 'fixed', 'amount' => 7000], 1);
        rates_apply_ranges($K, [['2099-09-20', '2099-09-21']], 8000.0, null);
        rate_editor_undo((int)$D['change_id'], 1);
        check('undo: allowed when only nights outside the span changed', $q($K, 5000, '2099-09-10', '2099-09-13') === 15000.0
            && $q($K, 5000, '2099-09-20', '2099-09-21') === 8000.0);

        // ── Preview fingerprint: apply exactly what was previewed ───────────
        $fq = ['rooms' => [$K], 'ranges' => [['2099-10-01', '2099-10-03']], 'mode' => 'percent', 'pct' => 5];
        $fpv = rate_editor_preview($fq);
        check('fingerprint: preview returns one', preg_match('/^[0-9a-f]{64}$/', (string)($fpv['fingerprint'] ?? '')) === 1);
        check('fingerprint: missing → refused (422)', refusal(fn() => rate_editor_apply($fq, 1)) !== null);
        check('fingerprint: malformed → refused (422)', refusal(fn() => rate_editor_apply($fq + ['fingerprint' => 'abc'], 1)) !== null);
        $r = rate_editor_apply($fq + ['fingerprint' => $fpv['fingerprint']], 1);
        check('fingerprint: correct one applies (+5% once: 5,000 → 5,250)', $r['change_id'] > 0 && $q($K, 5000, '2099-10-01', '2099-10-04') === 15750.0);
        $m = $conflict(fn() => rate_editor_apply($fq + ['fingerprint' => $fpv['fingerprint']], 1));
        check('fingerprint: the same one again is refused — a double submit cannot compound +5%',
            $m !== null && str_contains($m, 'preview again') && $q($K, 5000, '2099-10-01', '2099-10-04') === 15750.0);
        $fq2 = ['rooms' => [$K], 'ranges' => [['2099-10-10', '2099-10-12']], 'mode' => 'fixed', 'amount' => 5500];
        $fpv2 = rate_editor_preview($fq2);
        rates_apply_ranges($K, [['2099-10-11', '2099-10-12']], 5100.0, null);            // edited after the preview
        check('fingerprint: stale after an intervening change is refused',
            $conflict(fn() => rate_editor_apply($fq2 + ['fingerprint' => $fpv2['fingerprint']], 1)) !== null
            && $q($K, 5000, '2099-10-10', '2099-10-13') === 15100.0);

        // ── Undo is exact with legacy same-created_at overlapping rows ──────
        $T = $mkRoom('zz-re-tie', 'ZZ Tie Room', 100, 'USD', false);
        $ts = '2020-01-01 00:00:00';
        db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at) VALUES (:r, '2099-06-05', '2099-06-10', 200, 'Inner', :c)", [':r' => $T, ':c' => $ts]);
        db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at) VALUES (:r, '2099-06-01', '2099-06-30', 150, 'Outer', :c)", [':r' => $T, ':c' => $ts]);
        $tq = fn() => $q($T, 100, '2099-06-01', '2099-06-30');
        $tBefore = $tq();
        $TA = $apply(['rooms' => [$T], 'ranges' => [['2099-06-07', '2099-06-07']], 'mode' => 'fixed', 'amount' => 500], 1);
        check('undo tie: apply changed one night', $tq() === $tBefore + 350.0);
        rate_editor_undo((int)$TA['change_id'], 1);
        check('undo tie: totals identical to before (the higher id still wins the tie)', $tq() === $tBefore);

        // ── Undo refuses (and changes nothing) when it cannot restore exactly
        $TB = $apply(['rooms' => [$T], 'ranges' => [['2099-06-20', '2099-06-21']], 'mode' => 'fixed', 'amount' => 400], 1);
        $tAfter = $tq();
        db_query('UPDATE rate_change_log SET before_json = CAST(:b AS jsonb) WHERE id = :id', [':id' => $TB['change_id'],
            ':b' => json_encode([['id' => 1, 'room_id' => $T, 'date_from' => '2099-06-20', 'date_to' => '2099-06-22',
                                  'price_amount' => 150.005, 'label' => 'Outer', 'created_at' => $ts]])]);
        $m = refusal(fn() => rate_editor_undo((int)$TB['change_id'], 1));
        check('undo: a restore that does not match the logged before rows is refused',
            $m !== null && str_contains($m, 'could not restore these rates exactly'));
        check('undo: …and rolled back to its savepoint — nothing changed, not marked undone',
            $tq() === $tAfter && db_query('SELECT undone_at IS NULL FROM rate_change_log WHERE id = :id', [':id' => $TB['change_id']])->fetchColumn() === true
            && $overlaps() === 0);

        // ── Endpoint dispatcher (guards + actions) ──────────────────────────
        $owner = ['id' => 1, 'role' => 'owner', 'is_active' => true];
        $tok = 'tok-abc';
        $pvReq = ['csrf_token' => $tok, 'action' => 'preview', 'rooms' => [$S1], 'ranges' => [['2099-06-02', '2099-06-03']], 'mode' => 'fixed', 'amount' => 180];
        $r = rate_editor_dispatch('POST', $pvReq, $owner, $tok);
        check('dispatch: owner preview 200', $r['status'] === 200 && $r['body']['ok'] === true && isset($r['body']['preview']['rooms']));
        check('dispatch: not signed in 401', rate_editor_dispatch('POST', $pvReq, false, $tok)['status'] === 401);
        check('dispatch: inactive 401', rate_editor_dispatch('POST', $pvReq, ['role' => 'owner', 'is_active' => false], $tok)['status'] === 401);
        check('dispatch: manager 403', rate_editor_dispatch('POST', $pvReq, ['role' => 'manager', 'is_active' => true], $tok)['status'] === 403);
        check('dispatch: reception 403', rate_editor_dispatch('POST', $pvReq, ['role' => 'reception', 'is_active' => true], $tok)['status'] === 403);
        check('dispatch: GET 405', rate_editor_dispatch('GET', $pvReq, $owner, $tok)['status'] === 405);
        check('dispatch: bad CSRF 403', rate_editor_dispatch('POST', ['csrf_token' => 'nope'] + $pvReq, $owner, $tok)['status'] === 403);
        check('dispatch: empty session token 403 (hash_equals(\'\',\'\') trap)',
            rate_editor_dispatch('POST', ['csrf_token' => ''] + $pvReq, $owner, '')['status'] === 403);
        check('dispatch: unknown room 422', rate_editor_dispatch('POST', ['rooms' => [2147480000]] + $pvReq, $owner, $tok)['status'] === 422);
        $r = rate_editor_dispatch('POST', ['rooms' => [$S1, $K], 'mode' => 'fixed'] + $pvReq, $owner, $tok);
        check('dispatch: currency mix 422 with a human message', $r['status'] === 422 && str_contains($r['body']['error'], 'ZZ Kes Room'));
        check('dispatch: unknown action 400', rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'nuke'], $owner, $tok)['status'] === 400);
        $r = rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'options'], $owner, $tok);
        $zz = array_values(array_filter($r['body']['venues'] ?? [], fn($v) => $v['id'] === $vid));
        check('dispatch: options lists rooms by property + the buyout room',
            $r['status'] === 200 && $zz && $zz[0]['buyout_room_id'] === $B && count($zz[0]['rooms']) === 6 /* 5 + ZZ Tie Room */ && in_array('Peak season', $r['body']['labels'], true));
        $r = rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'labels'], $owner, $tok);
        check('dispatch: labels', $r['status'] === 200 && in_array('Mid season', $r['body']['labels'], true));
        $r = rate_editor_dispatch('POST', ['action' => 'apply'] + $pvReq, $owner, $tok);
        check('dispatch: apply without a fingerprint 422', $r['status'] === 422 && str_contains($r['body']['error'], 'Preview'));
        $fp = rate_editor_dispatch('POST', $pvReq, $owner, $tok)['body']['preview']['fingerprint'];
        $r = rate_editor_dispatch('POST', ['action' => 'apply', 'fingerprint' => str_repeat('0', 64)] + $pvReq, $owner, $tok);
        check('dispatch: apply with a stale fingerprint 409', $r['status'] === 409 && str_contains($r['body']['error'], 'preview again'));
        $r = rate_editor_dispatch('POST', ['action' => 'apply', 'fingerprint' => $fp] + $pvReq, $owner, $tok);
        check('dispatch: apply 200 + change id', $r['status'] === 200 && ($r['body']['applied']['change_id'] ?? 0) > 0
            && $q($S1, 100, '2099-06-02', '2099-06-03') === 180.0);
        $cid = (int)$r['body']['applied']['change_id'];
        $r = rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'log', 'limit' => 5], $owner, $tok);
        check('dispatch: log lists it first', $r['status'] === 200 && $r['body']['changes'][0]['id'] === $cid && $r['body']['supported'] === true);
        $r = rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'undo', 'change_id' => $cid], $owner, $tok);
        check('dispatch: undo 200', $r['status'] === 200 && $q($S1, 100, '2099-06-02', '2099-06-03') === 150.0);
        check('dispatch: undo again 422', rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'undo', 'change_id' => $cid], $owner, $tok)['status'] === 422);
        check('dispatch: undo unknown 404', rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'undo', 'change_id' => 2147480000], $owner, $tok)['status'] === 404);

        // ── Guard: a split must not flip a legacy created_at tie OUTSIDE the span ──
        // Base 100. A (lower id) [06-01,06-30) 150 and B (higher id) [06-20,06-25) 200
        // share created_at, so B wins 06-20..24. Fixing 06-10 alone splits A and
        // re-inserts its remainder [06-11,06-30) with a NEW, higher id than B — which
        // would now win the tie on 06-20..24 (price 200 → 150) on nights never targeted.
        $G = $mkRoom('zz-re-guard', 'ZZ Guard Room', 100, 'USD', false);
        $gts = '2020-01-01 00:00:00';
        db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at) VALUES (:r, '2099-06-01', '2099-06-30', 150, 'Outer', :c)", [':r' => $G, ':c' => $gts]);
        db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at) VALUES (:r, '2099-06-20', '2099-06-25', 200, 'Inner', :c)", [':r' => $G, ':c' => $gts]);
        $gq = fn() => $q($G, 100, '2099-06-01', '2099-06-30');
        $gRows = fn() => db_query('SELECT id, date_from, date_to, price_amount FROM rates WHERE room_id = :r ORDER BY id', [':r' => $G])->fetchAll();
        $gBefore = $gq(); $gRowsBefore = $gRows();
        $gLogBefore = (int) db_query('SELECT COUNT(*) FROM rate_change_log')->fetchColumn();
        $m = refusal(fn() => $apply(['rooms' => [$G], 'ranges' => [['2099-06-10', '2099-06-10']], 'mode' => 'fixed', 'amount' => 500], 1));
        check('guard: a split that would flip a created_at tie outside the span is refused',
            $m !== null && str_contains($m, 'would also alter prices on other nights') && str_contains($m, 'nothing was changed'));
        check('guard: …and nothing changed (quotes, rows, log)',
            $gq() === $gBefore && $gRows() == $gRowsBefore
            && (int) db_query('SELECT COUNT(*) FROM rate_change_log')->fetchColumn() === $gLogBefore);
        // Through the dispatcher a refusal is a 422 with the message.
        $gd = ['rooms' => [$G], 'ranges' => [['2099-06-10', '2099-06-10']], 'mode' => 'fixed', 'amount' => 500];
        $r = rate_editor_dispatch('POST', ['csrf_token' => $tok, 'action' => 'apply', 'fingerprint' => rate_editor_preview($gd)['fingerprint']] + $gd, $owner, $tok);
        check('guard: dispatcher maps it to 422', $r['status'] === 422 && str_contains($r['body']['error'], 'Clean up the overlapping rates'));
        // A change whose split remainder cannot reach the tied row still applies: 06-26
        // splits A but its remainder [06-27,06-30) never overlaps B, and the tie keeps its order.
        $GA = $apply(['rooms' => [$G], 'ranges' => [['2099-06-26', '2099-06-26']], 'mode' => 'fixed', 'amount' => 250], 1);
        check('guard: a tie-safe change still applies (06-26 = 250, B still wins 06-20..24)',
            ($GA['change_id'] ?? 0) > 0 && $q($G, 100, '2099-06-26', '2099-06-27') === 250.0 && $q($G, 100, '2099-06-20', '2099-06-25') === 1000.0);
        rate_editor_undo((int)$GA['change_id'], 1);
        check('guard: …and its undo passes the guard and restores', $gq() === $gBefore);
    } finally {
        $pdo->rollBack();
        rate_log_supported(true);
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
