<?php
/**
 * Live sustainability metrics (DB-driven, owner-editable, time-accruing).
 *
 * Data model (migration: add_sustainability_metrics.sql):
 *   sustainability_metrics — one row per published figure. Each row holds the
 *   last KNOWN-TRUE reading (`value` taken at `baseline_at`) plus an optional
 *   `growth_per_day`. The number a visitor sees is derived at render time:
 *
 *       current = min(value + growth_per_day * days_since(baseline_at), max_value)
 *
 *   Nothing increments `value` on a schedule. That is load-bearing: the stored
 *   figure stays a reading the owner can defend, and re-entering a corrected
 *   reading in Admin → Sustainability re-bases the accrual (sus_metric_save()
 *   resets baseline_at whenever the value actually changes) instead of
 *   compounding on top of an already-accrued number.
 *
 * All reads are pre-migration-safe via sustainability_supported(): with no table
 * the helpers return SUS_FALLBACK — the exact figures the templates shipped with
 * — so an unapplied migration renders the current page rather than zeros.
 */

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** True if the metrics table exists (memoised). False pre-migration. */
function sustainability_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.sustainability_metrics')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * The figures the templates ship with — used before the migration runs, and as
 * the default for any key an owner has unpublished or deleted. Keep in step with
 * the seed block in add_sustainability_metrics.sql.
 */
function sus_fallback_metrics(): array {
    $rows = [
        ['metric_key'=>'solar_mwh',       'label'=>'Solar Energy Generated', 'value'=>27.59,  'unit'=>'MWh', 'decimals'=>2, 'note'=>'Tribal Dunes · updated weekly', 'sort_order'=>10],
        ['metric_key'=>'co2_tonnes',      'label'=>'CO₂ Emissions Avoided',  'value'=>21.88,  'unit'=>'T',   'decimals'=>2, 'note'=>'= 1,503 trees equivalent',     'sort_order'=>20],
        ['metric_key'=>'beach_kg_total',  'label'=>'Beach Waste Collected',  'value'=>780.0,  'unit'=>'kg',  'decimals'=>0, 'note'=>'Cumulative · Bofa & Watamu',   'sort_order'=>30],
        ['metric_key'=>'beach_kg_weekly', 'label'=>'Collected Every Week',   'value'=>30.0,   'unit'=>'kg',  'decimals'=>0, 'note'=>'Per week · every week',        'sort_order'=>40],
        ['metric_key'=>'desal_pct',       'label'=>'Desalinated Water',      'value'=>100.0,  'unit'=>'%',   'decimals'=>0, 'note'=>'No freshwater depletion',      'sort_order'=>50],
    ];
    $out = [];
    foreach ($rows as $r) {
        // A fallback never accrues: with no DB there is no reading to accrue from.
        $out[$r['metric_key']] = $r + [
            'baseline_at' => null, 'growth_per_day' => 0.0, 'max_value' => null,
            'is_published' => true, 'updated_at' => null, 'id' => 0,
        ];
    }
    return $out;
}

/** Published metrics keyed by metric_key, in sort order. Falls back pre-migration. */
function sus_metrics(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!sustainability_supported()) return $cache = sus_fallback_metrics();
    try {
        $rows = db_query(
            'SELECT * FROM sustainability_metrics WHERE is_published = TRUE ORDER BY sort_order, id'
        )->fetchAll();
    } catch (Throwable $e) {
        return $cache = sus_fallback_metrics();
    }
    if (!$rows) return $cache = sus_fallback_metrics();
    $out = [];
    foreach ($rows as $r) $out[$r['metric_key']] = $r;
    // Any key the templates ask for that the owner has removed still resolves.
    return $cache = $out + sus_fallback_metrics();
}

/** Every row including unpublished ones, for the admin screen. */
function sus_metrics_all(): array {
    if (!sustainability_supported()) return [];
    return db_query('SELECT * FROM sustainability_metrics ORDER BY sort_order, id')->fetchAll();
}

/** One metric row by key, or null when the key is unknown even to the fallback. */
function sus_metric(string $key): ?array {
    return sus_metrics()[$key] ?? null;
}

/**
 * The number to display: the stored reading plus whatever has accrued since it
 * was taken, capped at max_value.
 *
 * Elapsed days are fractional (the figure creeps through the day instead of
 * jumping at midnight) and clamped at >= 0, so a baseline_at in the future can
 * never render BELOW the reading the owner entered.
 */
function sus_metric_current(?array $m, ?int $now = null): float {
    if (!$m) return 0.0;
    $value = (float) ($m['value'] ?? 0);
    $rate  = (float) ($m['growth_per_day'] ?? 0);
    $base  = $m['baseline_at'] ?? null;

    if ($rate > 0 && $base) {
        $ts = is_int($base) ? $base : strtotime((string) $base);
        if ($ts !== false) {
            $elapsed = max(0.0, (($now ?? time()) - $ts) / 86400);
            $value  += $rate * $elapsed;
        }
    }
    if (isset($m['max_value']) && $m['max_value'] !== null && $m['max_value'] !== '') {
        $value = min($value, (float) $m['max_value']);
    }
    return $value;
}

/** The current value formatted to the metric's precision, e.g. "27.59" / "1,043". */
function sus_metric_display(?array $m, ?int $now = null): string {
    if (!$m) return '0';
    $dec = (int) ($m['decimals'] ?? 2);
    return number_format(sus_metric_current($m, $now), $dec);
}

/** Raw current value rounded to the metric's precision — for JS count-up targets. */
function sus_metric_number(?array $m, ?int $now = null): float {
    if (!$m) return 0.0;
    return round(sus_metric_current($m, $now), (int) ($m['decimals'] ?? 2));
}

/** The metric's unit, e.g. "MWh". Empty string when it has none. */
function sus_metric_unit(?array $m): string {
    return (string) ($m['unit'] ?? '');
}

/** "updated 3 days ago" style stamp for the live badge. Empty when unknown. */
function sus_metric_updated_label(?array $m, ?int $now = null): string {
    $ts = $m['updated_at'] ?? $m['baseline_at'] ?? null;
    if (!$ts) return '';
    $t = is_numeric($ts) ? (int) $ts : strtotime((string) $ts);
    if ($t === false) return '';
    $diff = max(0, ($now ?? time()) - $t);
    if ($diff < 3600)   return 'updated just now';
    if ($diff < 86400)  return 'updated today';
    $days = (int) floor($diff / 86400);
    if ($days === 1)    return 'updated yesterday';
    if ($days < 30)     return "updated {$days} days ago";
    $months = (int) floor($days / 30);
    return $months === 1 ? 'updated last month' : "updated {$months} months ago";
}

/**
 * Write one metric.
 *
 * Re-baselines whenever `value` actually changes: the new number becomes the
 * reading, taken now. Editing only the label/note/rate leaves baseline_at alone
 * so an in-flight accrual is not silently reset.
 */
function sus_metric_save(int $id, array $f, ?int $adminId = null): void {
    if (!sustainability_supported()) return;

    $cur = db_query('SELECT value FROM sustainability_metrics WHERE id = :id', [':id'=>$id])->fetch();
    if (!$cur) return;

    $newValue  = round((float) ($f['value'] ?? 0), 2);
    $rebaseline = abs($newValue - (float) $cur['value']) > 0.000001;

    $max = ($f['max_value'] === '' || $f['max_value'] === null) ? null : round((float) $f['max_value'], 2);

    $sql = 'UPDATE sustainability_metrics SET
              label = :label, value = :value, growth_per_day = :rate, max_value = :max,
              unit = :unit, decimals = :dec, note = :note, sort_order = :sort,
              is_published = :pub, updated_at = NOW(), updated_by = :by'
         . ($rebaseline ? ', baseline_at = NOW()' : '')
         . ' WHERE id = :id';

    db_query($sql, [
        ':label' => trim((string) ($f['label'] ?? '')),
        ':value' => $newValue,
        ':rate'  => round((float) ($f['growth_per_day'] ?? 0), 4),
        ':max'   => $max,
        ':unit'  => trim((string) ($f['unit'] ?? '')),
        ':dec'   => max(0, min(4, (int) ($f['decimals'] ?? 2))),
        ':note'  => trim((string) ($f['note'] ?? '')),
        ':sort'  => (int) ($f['sort_order'] ?? 0),
        ':pub'   => !empty($f['is_published']),
        ':by'    => $adminId,
        ':id'    => $id,
    ]);
}
