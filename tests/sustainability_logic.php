<?php
declare(strict_types=1);
// Live sustainability metrics — accrual, clamping, formatting, re-baselining.
// Run: php tests/sustainability_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real metric rows are ever changed. Requires add_sustainability_metrics.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sustainability.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function approx(float $a, float $b, float $eps = 0.0001): bool { return abs($a - $b) < $eps; }

$now = strtotime('2026-09-03 12:00:00');
$day = 86400;

// ── Accrual (no DB) ─────────────────────────────────────────────────────────
$m = ['value'=>100.0, 'growth_per_day'=>2.5, 'baseline_at'=>$now, 'max_value'=>null, 'decimals'=>2, 'unit'=>'kg'];

check('0 days elapsed → stored value',       approx(sus_metric_current($m, $now), 100.0));
check('1 day accrues one rate',              approx(sus_metric_current($m, $now + $day), 102.5));
check('30 days accrue linearly',             approx(sus_metric_current($m, $now + 30 * $day), 175.0));
check('accrual is fractional within a day',  approx(sus_metric_current($m, $now + $day / 2), 101.25));

$future = ['value'=>100.0, 'growth_per_day'=>2.5, 'baseline_at'=>$now + 10 * $day, 'max_value'=>null];
check('future baseline never reads below',   approx(sus_metric_current($future, $now), 100.0));

$static = ['value'=>27.59, 'growth_per_day'=>0, 'baseline_at'=>$now, 'max_value'=>null];
check('rate 0 → value never moves',          approx(sus_metric_current($static, $now + 365 * $day), 27.59));

$capped = ['value'=>99.0, 'growth_per_day'=>1.0, 'baseline_at'=>$now, 'max_value'=>100.0];
check('accrual clamps at max_value',         approx(sus_metric_current($capped, $now + 50 * $day), 100.0));
check('below the cap is untouched',          approx(sus_metric_current($capped, $now + $day / 2), 99.5));

check('no baseline → no accrual',            approx(sus_metric_current(['value'=>5.0,'growth_per_day'=>9.0,'baseline_at'=>null], $now + 9 * $day), 5.0));
check('null metric → 0',                     approx(sus_metric_current(null), 0.0));

// ── Formatting ──────────────────────────────────────────────────────────────
check('display honours decimals=2',          sus_metric_display($m, $now) === '100.00');
check('display honours decimals=0',          sus_metric_display(['value'=>1043.7,'decimals'=>0,'growth_per_day'=>0], $now) === '1,044');
check('display thousands separator',         sus_metric_display(['value'=>12345.6,'decimals'=>1,'growth_per_day'=>0], $now) === '12,345.6');
check('number rounds to precision',          approx(sus_metric_number(['value'=>1.239,'decimals'=>2,'growth_per_day'=>0], $now), 1.24));
check('unit reads through',                  sus_metric_unit($m) === 'kg');
check('unit of null → empty',                sus_metric_unit(null) === '');

// ── Updated label ───────────────────────────────────────────────────────────
check('label: today',                        sus_metric_updated_label(['updated_at'=>$now - 3 * 3600], $now) === 'updated today');
check('label: yesterday',                    sus_metric_updated_label(['updated_at'=>$now - 1.5 * $day], $now) === 'updated yesterday');
check('label: n days',                       sus_metric_updated_label(['updated_at'=>$now - 5 * $day], $now) === 'updated 5 days ago');
check('label: months',                       sus_metric_updated_label(['updated_at'=>$now - 70 * $day], $now) === 'updated 2 months ago');
check('label: unknown → empty',              sus_metric_updated_label(['updated_at'=>null], $now) === '');

// ── Pre-migration fallback ──────────────────────────────────────────────────
$fb = sus_fallback_metrics();
check('fallback carries the five keys',      count(array_diff(
        ['solar_mwh','co2_tonnes','beach_kg_total','beach_kg_weekly','desal_pct'], array_keys($fb))) === 0);
check('fallback solar = shipped figure',     approx((float) $fb['solar_mwh']['value'], 27.59));
check('fallback co2 = shipped figure',       approx((float) $fb['co2_tonnes']['value'], 21.88));
check('fallback never accrues',              approx(sus_metric_current($fb['beach_kg_total'], $now + 999 * $day), 780.0));

// sus_metrics() must always resolve every template key, migration or not.
$live = sus_metrics();
foreach (['solar_mwh','co2_tonnes','beach_kg_total','beach_kg_weekly','desal_pct'] as $k) {
    check("sus_metrics() resolves {$k}",     isset($live[$k]));
}

if (!sustainability_supported()) {
    echo "\nSKIP  DB assertions (add_sustainability_metrics.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

// ── DB: re-baselining (rolled back) ─────────────────────────────────────────
db()->beginTransaction();

$id = (int) db_query(
    "INSERT INTO sustainability_metrics (metric_key, label, value, growth_per_day, baseline_at, unit, decimals)
     VALUES ('__test_metric', 'Test', 100, 5, NOW() - INTERVAL '10 days', 'kg', 0)
     RETURNING id"
)->fetchColumn();

$before = db_query('SELECT baseline_at FROM sustainability_metrics WHERE id = :i', [':i'=>$id])->fetch();
$row    = db_query('SELECT * FROM sustainability_metrics WHERE id = :i', [':i'=>$id])->fetch();
check('10 days at 5/day accrues 50',         approx(round(sus_metric_current($row)), 150.0));

// Editing only the label must NOT reset the accrual.
sus_metric_save($id, ['label'=>'Renamed','value'=>100,'growth_per_day'=>5,'max_value'=>'','unit'=>'kg','decimals'=>0,'note'=>'','sort_order'=>0,'is_published'=>1]);
$after = db_query('SELECT baseline_at, label FROM sustainability_metrics WHERE id = :i', [':i'=>$id])->fetch();
check('label-only edit keeps baseline',      $after['baseline_at'] === $before['baseline_at']);
check('label-only edit saves the label',     $after['label'] === 'Renamed');

// Entering a new reading re-bases the accrual.
sus_metric_save($id, ['label'=>'Renamed','value'=>200,'growth_per_day'=>5,'max_value'=>'','unit'=>'kg','decimals'=>0,'note'=>'','sort_order'=>0,'is_published'=>1]);
$rebased = db_query('SELECT * FROM sustainability_metrics WHERE id = :i', [':i'=>$id])->fetch();
check('new reading re-bases baseline',       $rebased['baseline_at'] !== $before['baseline_at']);
check('re-based value does not compound',    approx(round(sus_metric_current($rebased)), 200.0));

// A cap set through the admin path is honoured.
sus_metric_save($id, ['label'=>'Renamed','value'=>99,'growth_per_day'=>100,'max_value'=>'100','unit'=>'%','decimals'=>0,'note'=>'','sort_order'=>0,'is_published'=>1]);
$cappedRow = db_query('SELECT * FROM sustainability_metrics WHERE id = :i', [':i'=>$id])->fetch();
check('saved max_value clamps the render',   approx(sus_metric_current($cappedRow, time() + 5 * $day), 100.0));

db()->rollBack();
$gone = db_query("SELECT COUNT(*) FROM sustainability_metrics WHERE metric_key = '__test_metric'")->fetchColumn();
check('test row rolled back',                (int) $gone === 0);

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
