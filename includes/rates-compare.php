<?php
declare(strict_types=1);
/**
 * Rates comparison helpers — PURE (no I/O). Shared by admin/rates.php (Rate card,
 * Timeline) and the Quote Builder (season mixes, currency conversion).
 *
 * Every input is a resolved nightly map from rates_nightly_maps() /
 * room_stay_quotes(..., true) — never raw `rates` rows — so nothing here can
 * disagree with what a guest is charged.
 *
 * A "season" is the rate row's free-text label. The three the owner uses
 * (Standard / Mid / Peak season) sort first and carry colours; any other label
 * sorts after them alphabetically; an override with no label is "Other rate";
 * a night with no override is the room's Base price.
 *
 * Money: amounts stay in their OWN currency; rc_money_html() renders them for the
 * chosen currency and carries the original in data-* so admin-money.js can
 * re-render instantly on the KES | USD switch. rc_money_text() and the JS
 * formatter must stay byte-identical (tests/rates_compare_logic.php pins PHP).
 */

/** Colour key for a season label: std | mid | peak (also "High") | other | base. */
function rc_season_class(?string $label): string {
    if ($label === null || $label === '') return 'base';
    $l = strtolower($label);
    if (str_contains($l, 'peak') || str_contains($l, 'high')) return 'peak';   // "High season" = a property's top season
    if (str_contains($l, 'mid'))      return 'mid';
    if (str_contains($l, 'standard')) return 'std';
    return 'other';
}

/** Sort rank for a label: Standard 0, Mid 1, Peak/High 2, anything else 3. */
function rc_season_rank(string $label): int {
    return ['std' => 0, 'mid' => 1, 'peak' => 2][rc_season_class($label)] ?? 3;
}

/** Labels in display order: Standard, Mid, Peak, then the rest A–Z. */
function rc_sort_labels(array $labels): array {
    $labels = array_values(array_unique(array_map('strval', $labels)));
    usort($labels, fn($a, $b) => (rc_season_rank($a) <=> rc_season_rank($b)) ?: strcmp($a, $b));
    return $labels;
}

/** The key a night files under: its label, "Other rate" for an unlabelled override, null for base. */
function rc_night_key(array $night): ?string {
    if (empty($night['is_override'])) return null;
    $l = trim((string)($night['label'] ?? ''));
    return $l !== '' ? $l : 'Other rate';
}

/**
 * One Rate-card row from a room's nightly map.
 * @return array{seasons: array<string, array{min: float, max: float}>, base: float, base_nights: int}
 */
function rc_rate_card_row(array $nightly, float $base): array {
    $seasons = [];
    $baseNights = 0;
    foreach ($nightly as $night) {
        $key = rc_night_key($night);
        if ($key === null) { $baseNights++; continue; }
        $p = (float)$night['price'];
        if (!isset($seasons[$key])) { $seasons[$key] = ['min' => $p, 'max' => $p]; continue; }
        $seasons[$key]['min'] = min($seasons[$key]['min'], $p);
        $seasons[$key]['max'] = max($seasons[$key]['max'], $p);
    }
    return ['seasons' => $seasons, 'base' => $base, 'base_nights' => $baseNights];
}

/**
 * Season date runs across several rooms' nightly maps (the union of their
 * nights), merged into contiguous [firstNight, lastNight] runs. Keyed by label
 * in display order. Base nights are not a season and are left out.
 */
function rc_season_runs(array $maps): array {
    $days = [];
    foreach ($maps as $map) {
        foreach ($map as $ymd => $night) {
            $key = rc_night_key($night);
            if ($key !== null) $days[$key][$ymd] = true;
        }
    }
    $out = [];
    foreach (rc_sort_labels(array_keys($days)) as $key) {
        $dates = array_keys($days[$key]);
        sort($dates);
        $runs = [];
        $start = $prev = null;
        foreach ($dates as $ymd) {
            if ($prev !== null && $ymd === (new DateTime($prev))->modify('+1 day')->format('Y-m-d')) { $prev = $ymd; continue; }
            if ($start !== null) $runs[] = [$start, $prev];
            $start = $prev = $ymd;
        }
        if ($start !== null) $runs[] = [$start, $prev];
        $out[$key] = $runs;
    }
    return $out;
}

/** "26 Mar – 4 Apr", "20 – 31 Dec", or "25 Dec" for a [first, last] night run. */
function rc_run_label(array $run): string {
    [$a, $b] = $run;
    $ta = strtotime($a); $tb = strtotime($b);
    if ($a === $b) return date('j M', $ta);
    if (date('Y-m', $ta) === date('Y-m', $tb)) return date('j', $ta) . ' – ' . date('j M', $tb);
    return date('j M', $ta) . ' – ' . date('j M', $tb);
}

/** "2 Mid + 2 Peak" for a stay's nightly map ("season" dropped; base nights = "Base"). */
function rc_season_mix(array $nightly): string {
    $count = [];
    foreach ($nightly as $night) {
        $key = rc_night_key($night) ?? '';
        $count[$key] = ($count[$key] ?? 0) + 1;
    }
    $keys = array_keys($count);
    usort($keys, function ($a, $b) {
        if ($a === '' || $b === '') return ($a === '') <=> ($b === '');   // base last
        return (rc_season_rank($a) <=> rc_season_rank($b)) ?: strcmp($a, $b);
    });
    $parts = [];
    foreach ($keys as $k) {
        $name = $k === '' ? 'Base' : trim(preg_replace('/\s*season$/i', '', $k));
        $parts[] = $count[$k] . ' ' . $name;
    }
    return implode(' + ', $parts);
}

/** Convert with a USD-based rate table; null when a rate is missing (never a guessed price). */
function rc_convert(float $amt, string $from, string $to, array $rates): ?float {
    $from = strtoupper($from); $to = strtoupper($to);
    if ($from === $to) return $amt;
    $rf = (float)($rates[$from] ?? 0); $rt = (float)($rates[$to] ?? 0);
    if ($rf <= 0 || $rt <= 0) return null;
    return $amt / $rf * $rt;
}

/** Trim trailing zeros from a decimal string: "100.0" → "100", "1.25" stays. */
function rc_trimz(string $s): string {
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** Round half UP to an integer: floor(x + 0.5). Mirrored by Math.floor(v + 0.5) in admin-money.js. */
function rc_round_half_up(float $x): float {
    return floor($x + 0.5);
}

/**
 * Display text for an amount already in $cur. Full: "KES 48,360" / "$375"
 * (whole units). Short (timeline cells): "48.4k" / "1.25m" for KES, "$375" /
 * "$120.5k" for other currencies.
 *
 * Rounding is explicit and identical to admin-money.js: scale the amount, round
 * the scaled value half UP with floor(x + 0.5), then place the decimal point
 * (number_format's own rounding pre-rounds, JS toFixed rounds the binary value —
 * they disagree on half-way values, so neither is used to round).
 */
function rc_money_text(float $amt, string $cur, bool $short = false): string {
    $cur = strtoupper($cur);
    $sym = TS_CURRENCIES[$cur]['symbol'] ?? ($cur . ' ');
    if ($short) {
        $a = abs($amt);
        if ($cur === 'KES') {
            if ($a >= 1000000) return rc_trimz(number_format(rc_round_half_up($amt / 10000) / 100, 2, '.', '')) . 'm';
            if ($a >= 1000)    return rc_trimz(number_format(rc_round_half_up($amt / 100) / 10, 1, '.', '')) . 'k';
            return number_format(rc_round_half_up($amt), 0, '.', '');
        }
        if ($a >= 100000) return $sym . rc_trimz(number_format(rc_round_half_up($amt / 100) / 10, 1, '.', '')) . 'k';
    }
    return $sym . number_format(rc_round_half_up($amt), 0);
}

/**
 * An amount for the page: rendered in $to (converted with $rates, marked ≈ and
 * .is-approx) and carrying its original amount + currency so the KES | USD switch
 * re-renders from source. A missing rate leaves it in its own currency.
 */
function rc_money_html(float $amt, string $from, string $to, array $rates, bool $short = false): string {
    $from  = strtoupper($from !== '' ? $from : 'USD');
    $v     = rc_convert($amt, $from, $to, $rates);
    $shown = $v === null ? $from : strtoupper($to);
    $approx = $shown !== $from;
    $txt = rc_money_text($v ?? $amt, $shown, $short);
    return '<span class="mny' . ($approx ? ' is-approx' : '') . '" data-amt="' . e(rc_trimz(sprintf('%.2F', $amt)))
         . '" data-cur="' . e($from) . '"' . ($short ? ' data-fmt="short"' : '') . '>'
         . e(($approx && !$short ? '≈ ' : '') . $txt) . '</span>';
}
