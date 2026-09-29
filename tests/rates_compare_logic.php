<?php
declare(strict_types=1);
// Rates comparison + batch quote. Run: php tests/rates_compare_logic.php
// Pure helpers always run; the DB block runs in ONE rolled-back transaction and
// SKIPs when no database is reachable.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rates.php';
require_once __DIR__ . '/../includes/rates-compare.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure helpers ────────────────────────────────────────────────────────────
function n(float $p, ?string $label): array {
    return ['price' => $p, 'label' => $label, 'rate_id' => $label === null ? null : 1, 'is_override' => $label !== null];
}
check('class: standard/mid/peak/other/base',
    rc_season_class('Standard season') === 'std' && rc_season_class('Mid season') === 'mid'
    && rc_season_class('Peak season') === 'peak' && rc_season_class('Christmas') === 'other'
    && rc_season_class(null) === 'base');
check('labels sort Standard, Mid, Peak, then others A-Z',
    rc_sort_labels(['Peak season', 'Zeta', 'Mid season', 'Alpha', 'Standard season'])
        === ['Standard season', 'Mid season', 'Peak season', 'Alpha', 'Zeta']);

$map = ['2027-12-18' => n(97812, 'Mid season'), '2027-12-19' => n(97812, 'Mid season'),
        '2027-12-20' => n(119500, 'Peak season'), '2027-12-21' => n(143400, 'Peak season'),
        '2027-12-22' => n(80000, null), '2027-12-23' => n(5000, '')];
$row = rc_rate_card_row($map, 80000.0);
check('card row: one price per season', $row['seasons']['Mid season'] === ['min' => 97812.0, 'max' => 97812.0]);
check('card row: two peak prices become a range', $row['seasons']['Peak season'] === ['min' => 119500.0, 'max' => 143400.0]);
check('card row: base nights counted, base price kept', $row['base_nights'] === 1 && $row['base'] === 80000.0);
check('card row: unlabelled override is "Other rate"', isset($row['seasons']['Other rate']));

$runs = rc_season_runs([
    1 => ['2027-03-26' => n(1, 'Peak season'), '2027-03-27' => n(1, 'Peak season'), '2027-03-28' => n(1, 'Mid season')],
    2 => ['2027-03-27' => n(1, 'Peak season'), '2027-03-29' => n(1, 'Peak season'), '2027-03-30' => n(1, null)],
]);
check('runs: union across rooms, split on gaps',
    $runs['Peak season'] === [['2027-03-26', '2027-03-27'], ['2027-03-29', '2027-03-29']]);
check('runs: base nights are not a season', !isset($runs['']) && count($runs) === 2);
check('runs: keys in season order', array_keys($runs) === ['Mid season', 'Peak season']);
check('run label: same month', rc_run_label(['2027-12-20', '2027-12-31']) === '20 – 31 Dec');
check('run label: across months', rc_run_label(['2027-03-26', '2027-04-04']) === '26 Mar – 4 Apr');
check('run label: one night', rc_run_label(['2027-12-25', '2027-12-25']) === '25 Dec');

check('mix: counts per season in order',
    rc_season_mix(['a' => n(1, 'Peak season'), 'b' => n(1, 'Mid season'), 'c' => n(1, 'Mid season'), 'd' => n(1, null)])
        === '2 Mid + 1 Peak + 1 Base');
check('mix: empty map', rc_season_mix([]) === '');

$fx = ['USD' => 1.0, 'KES' => 129.0];
check('convert: same currency is exact', rc_convert(48360.0, 'KES', 'KES', $fx) === 48360.0);
check('convert: KES → USD', abs(rc_convert(129000.0, 'KES', 'USD', $fx) - 1000.0) < 0.0001);
check('convert: missing rate is null', rc_convert(10.0, 'KES', 'EUR', $fx) === null);

check('text: KES full', rc_money_text(48360.4, 'KES') === 'KES 48,360');
check('text: USD full', rc_money_text(374.6, 'USD') === '$375');
check('short: KES thousands', rc_money_text(48360, 'KES', true) === '48.4k');
check('short: KES round thousands', rc_money_text(100000, 'KES', true) === '100k');
check('short: KES millions', rc_money_text(1250000, 'KES', true) === '1.25m');
check('short: KES small', rc_money_text(950, 'KES', true) === '950');
check('short: USD', rc_money_text(375.2, 'USD', true) === '$375');
check('html: own currency, exact',
    rc_money_html(48360.0, 'KES', 'KES', $fx) === '<span class="mny" data-amt="48360" data-cur="KES">KES 48,360</span>');
check('html: converted is marked ≈',
    rc_money_html(129000.0, 'KES', 'USD', $fx) === '<span class="mny is-approx" data-amt="129000" data-cur="KES">≈ $1,000</span>');
check('html: short cells carry data-fmt and no ≈ prefix',
    rc_money_html(129000.0, 'KES', 'USD', $fx, true) === '<span class="mny is-approx" data-amt="129000" data-cur="KES" data-fmt="short">$1,000</span>');

// ── DB: room_stay_quotes() is the ONE summation ─────────────────────────────
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { echo "SKIP  DB block (no database)\n"; }
if ($pdo) {
    $pdo->beginTransaction();
    try {
        $rooms = db_query('SELECT id, price_amount FROM rooms ORDER BY id LIMIT 2')->fetchAll();
        if (count($rooms) < 2) {
            echo "SKIP  batch quote (need 2 rooms)\n";
        } else {
            [$a, $b] = $rooms;
            db_query("INSERT INTO rates (room_id, date_from, date_to, price_amount, label)
                      VALUES (:r, '2099-03-02', '2099-03-04', 777, 'Peak season')", [':r' => (int)$a['id']]);
            $defaults = [(int)$a['id'] => 100.0, (int)$b['id'] => 50.0];
            $batch = room_stay_quotes($defaults, '2099-03-01', '2099-03-05');
            check('batch: room A == single quote',
                $batch[(int)$a['id']] === room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05'));
            check('batch: room B == single quote',
                $batch[(int)$b['id']] === room_stay_quote((int)$b['id'], 50.0, '2099-03-01', '2099-03-05'));
            check('batch: A = 2 base + 2 override nights', $batch[(int)$a['id']] === ['nights' => 4, 'total' => 1754.0]);
            check('batch: bad window is not a quote',
                room_stay_quotes($defaults, '2099-03-05', '2099-03-01')[(int)$a['id']] === ['nights' => 0, 'total' => 0.0]);
            $withMap = room_stay_quotes($defaults, '2099-03-01', '2099-03-05', true);
            check('batch: withNightly returns the nightly map',
                count($withMap[(int)$a['id']]['nightly']) === 4
                && $withMap[(int)$a['id']]['nightly']['2099-03-02']['label'] === 'Peak season');
            check('single quote keeps its exact shape', array_keys(room_stay_quote((int)$a['id'], 100.0, '2099-03-01', '2099-03-05')) === ['nights', 'total']);
        }
    } finally { $pdo->rollBack(); }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
