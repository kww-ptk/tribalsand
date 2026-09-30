<?php
declare(strict_types=1);
/**
 * Global rate editor — set any rooms' nightly rates in one change, with a
 * preview, a change log and undo (owner only). Spec:
 * docs/superpowers/specs/2026-09-30-global-rate-editor-design.md
 *
 * Layers:
 *  - PURE (no I/O): request validation (re_normalize_request), inclusive →
 *    exclusive ranges (re_parse_ranges), the four price modes + buyout sums
 *    (re_compute), rounding, run grouping, the preview view and the summary.
 *  - I/O: rate_editor_preview() / rate_editor_apply() / rate_editor_undo() /
 *    rate_editor_log() / rate_editor_labels() / rate_editor_options(), and
 *    rate_editor_dispatch() — the whole JSON endpoint minus the session, so the
 *    guards are testable (api/rate-editor.php is a thin wrapper around it).
 *
 * Load-bearing rules:
 *  - ONE pricing path. Current prices come from ONE rates_nightly_maps() call
 *    over every involved room; the editor only WRITES `rates` rows, through
 *    rates_apply_ranges() / rates_clear_span() (includes/rates.php), so every
 *    written night is owned by exactly one row (no overlaps).
 *  - Apply never trusts a client preview: it recomputes from the database
 *    inside the transaction, under an advisory lock, and writes the rates and
 *    the log row in that ONE transaction. It REQUIRES the preview's
 *    re_fingerprint() and refuses (409) when the recomputed one differs — rates
 *    edited since the preview, or a double submit, never write.
 *  - Undo restores exactly or not at all: before rows are re-inserted oldest
 *    first (created_at ASC, id ASC, so legacy created_at ties keep resolving the
 *    same way), then every night of the span is compared with the logged before
 *    rows (re_row_claims); a mismatch throws and re_tx rolls it all back.
 *  - Prices are NUMERIC(10,2): anything above RE_MAX_AMOUNT is refused (422),
 *    never left to fail the INSERT.
 *  - `rates.date_to` and `rate_change_log.span_to` are EXCLUSIVE. The request
 *    carries [first night, last night] INCLUSIVE, like the forms.
 *  - No venue scoping here: the editor is owner-only (every venue). The
 *    dispatcher refuses anyone else before anything is read.
 *  - Pre-migration (no rate_change_log): preview + apply work, nothing is
 *    logged, undo/log report "needs the migration". rate_log_supported() is a
 *    to_regclass lookup — never a failing SELECT inside a transaction.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/rates-compare.php';

const RE_MAX_ROOMS     = 50;
const RE_MAX_NIGHTS    = 1100;
const RE_MAX_RANGES    = 50;
const RE_MAX_PCT       = 500.0;
const RE_MAX_AMOUNT    = 99999999.99;   // rates.price_amount is NUMERIC(10,2)
const RE_LABEL_MAX     = 100;          // rates.label is VARCHAR(100)
const RE_LOG_DEFAULT   = 20;
const RE_LOG_MAX       = 100;
const RE_MODES         = ['fixed', 'percent', 'match', 'base', 'sum'];
/** How far ahead the Buyout check looks (from today, Nairobi). */
const RE_BUYOUT_CHECK_NIGHTS = 730;

/** A request the owner can fix (bad input, currency mix, price ≤ 0, undo blocked) → 422. */
class RateEditorRefusal extends RuntimeException {}
/** A change id that does not exist → 404. */
class RateEditorNotFound extends RuntimeException {}
/** The feature needs the migration (log / undo) → 409. */
class RateEditorUnavailable extends RuntimeException {}
/** Apply's fingerprint no longer matches the rates (changed since the preview, or a double submit) → 409. */
class RateEditorConflict extends RuntimeException {}

// ───────────────────────────────────────────────────────────────────────────
// Pure helpers
// ───────────────────────────────────────────────────────────────────────────

function re_next_day(string $ymd): string {
    return (new DateTime($ymd))->modify('+1 day')->format('Y-m-d');
}
function re_prev_day(string $ymd): string {
    return (new DateTime($ymd))->modify('-1 day')->format('Y-m-d');
}

/**
 * [first night, last night] INCLUSIVE ranges (as sent by the form) → merged
 * [from, toExcl) ranges. Accepts [a, b], {from, to} or {first, last}. Dates are
 * validated strictly with rates_ymd() — this is a write path.
 * @throws RateEditorRefusal
 */
function re_parse_ranges($ranges): array {
    if (!is_array($ranges) || !$ranges) throw new RateEditorRefusal('Choose at least one range of nights.');
    if (count($ranges) > RE_MAX_RANGES) throw new RateEditorRefusal('At most ' . RE_MAX_RANGES . ' date ranges per change.');
    $excl = [];
    foreach ($ranges as $r) {
        if (!is_array($r)) throw new RateEditorRefusal('Each range needs a first and a last night.');
        $a = $r['from'] ?? $r['first'] ?? $r[0] ?? '';
        $b = $r['to']   ?? $r['last']  ?? $r[1] ?? '';
        $f = is_string($a) ? rates_ymd($a) : null;
        $l = is_string($b) ? rates_ymd($b) : null;
        if ($f === null || $l === null) throw new RateEditorRefusal('Dates must be real dates (YYYY-MM-DD).');
        if ($l < $f) throw new RateEditorRefusal("A range ends before it starts ({$f} → {$l}).");
        $to = re_next_day($l);
        // 9999-12-31's checkout is year 10000, which rates_merge_ranges() would
        // silently DROP (not a Y-m-d) — leaving an empty change. Refuse instead.
        if (rates_ymd($to) === null) throw new RateEditorRefusal('Dates must be real dates (YYYY-MM-DD).');
        $excl[] = [$f, $to];
    }
    $merged = rates_merge_ranges($excl);
    if (!$merged) throw new RateEditorRefusal('Choose at least one range of nights.');
    return $merged;
}

/** How many nights a list of [from, toExcl) ranges covers — counted, never listed. */
function re_count_nights(array $excl): int {
    $n = 0;
    foreach ($excl as [$from, $to]) $n += (int)(new DateTime($from))->diff(new DateTime($to))->days;
    return $n;
}

/** Every night (Y-m-d) in a list of [from, toExcl) ranges, in order. */
function re_nights(array $excl): array {
    $out = [];
    foreach ($excl as [$from, $to]) {
        for ($d = new DateTime($from), $e = new DateTime($to); $d < $e; $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
    }
    return $out;
}

/** A finite number from JSON (int, float or numeric string), else null. */
function re_number($v): ?float {
    if (is_int($v) || is_float($v)) $f = (float)$v;
    elseif (is_string($v) && is_numeric(trim($v))) $f = (float)trim($v);
    else return null;
    return is_finite($f) ? $f : null;
}

/** A label as given (trimmed, '' = none) or a refusal when too long. */
function re_clean_label($v, string $what = 'Season label'): string {
    if (!is_string($v) && !is_int($v) && !is_float($v)) throw new RateEditorRefusal("{$what} must be text.");
    $s = trim((string)$v);
    if (mb_strlen($s) > RE_LABEL_MAX) throw new RateEditorRefusal("{$what} is too long (max " . RE_LABEL_MAX . ' characters).');
    return $s;
}

/**
 * Validate a request. Returns:
 *   rooms (int[]), ranges ([from, toExcl)[]), nights (Y-m-d[]), mode,
 *   amount (fixed), pct (percent), match_label (match),
 *   label (?string) + keep_label (bool), update_buyouts (bool).
 *
 * Label: `label` absent or null = keep each night's own label (fixed, percent);
 * a string sets it on every night ('' = no label). In match mode it defaults to
 * the matched season. Ignored in base mode.
 * @throws RateEditorRefusal
 */
function re_normalize_request(array $data): array {
    $ids = $data['rooms'] ?? null;
    if (!is_array($ids)) throw new RateEditorRefusal('Choose at least one room.');
    // Cap the raw list BEFORE looping over it (a well-formed request never repeats an id).
    if (count($ids) > RE_MAX_ROOMS) throw new RateEditorRefusal('At most ' . RE_MAX_ROOMS . ' rooms per change — split it into smaller changes.');
    $rooms = [];
    foreach ($ids as $id) {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
            throw new RateEditorRefusal('A chosen room is not valid — reload the page.');
        }
        $rooms[(int)$id] = true;
    }
    $rooms = array_keys($rooms);
    if (!$rooms) throw new RateEditorRefusal('Choose at least one room.');
    if (count($rooms) > RE_MAX_ROOMS) throw new RateEditorRefusal('At most ' . RE_MAX_ROOMS . ' rooms per change — split it into smaller changes.');

    $ranges = re_parse_ranges($data['ranges'] ?? null);
    // Count BEFORE listing: 0001-01-01 → 9999-12-30 would otherwise build ~3.65M strings.
    if (re_count_nights($ranges) > RE_MAX_NIGHTS) {
        throw new RateEditorRefusal('At most ' . number_format(RE_MAX_NIGHTS) . ' nights per change — split it into smaller changes.');
    }
    $nights = re_nights($ranges);

    $mode = is_string($data['mode'] ?? null) ? strtolower(trim($data['mode'])) : '';
    if (!in_array($mode, RE_MODES, true)) throw new RateEditorRefusal('Choose how to set the price.');

    $req = ['rooms' => $rooms, 'ranges' => $ranges, 'nights' => $nights, 'mode' => $mode,
            'amount' => null, 'pct' => null, 'match_label' => null,
            'label' => null, 'keep_label' => true,
            'update_buyouts' => filter_var($data['update_buyouts'] ?? false, FILTER_VALIDATE_BOOLEAN)];

    if ($mode === 'fixed') {
        $a = re_number($data['amount'] ?? null);
        if ($a === null || round($a, 2) <= 0) throw new RateEditorRefusal('Enter a price above 0.');
        if (round($a, 2) > RE_MAX_AMOUNT) throw new RateEditorRefusal('A price of ' . number_format($a, 2) . ' would exceed the maximum price (' . number_format(RE_MAX_AMOUNT, 2) . ').');
        $req['amount'] = round($a, 2);
    } elseif ($mode === 'percent') {
        $p = re_number($data['pct'] ?? null);
        if ($p === null || $p == 0.0) throw new RateEditorRefusal('Enter a percentage to change by (for example 5 or -10).');
        if ($p <= -100 || $p > RE_MAX_PCT) throw new RateEditorRefusal('The change must be above -100% and at most +' . (int)RE_MAX_PCT . '%.');
        $req['pct'] = $p;
    } elseif ($mode === 'match') {
        $m = re_clean_label($data['match_label'] ?? '', 'The season to match');
        if ($m === '') throw new RateEditorRefusal('Choose the season to match.');
        $req['match_label'] = $m;
    }

    // 'sum' labels each night itself (the label most of the property's rooms carry).
    if ($mode !== 'base' && $mode !== 'sum') {
        if (array_key_exists('label', $data) && $data['label'] !== null) {
            $l = re_clean_label($data['label']);
            $req['label'] = $l !== '' ? $l : null;
            $req['keep_label'] = false;
        } elseif ($mode === 'match') {
            $req['label'] = $req['match_label'];
            $req['keep_label'] = false;
        }
    }
    return $req;
}

/** The [from, toExcl) window to resolve: the span, or whole calendar years for match. */
function re_load_window(array $req): array {
    $first = $req['nights'][0];
    $last  = $req['nights'][count($req['nights']) - 1];
    if ($req['mode'] === 'match') {
        return [substr($first, 0, 4) . '-01-01', sprintf('%04d-01-01', (int)substr($last, 0, 4) + 1)];
    }
    return [$first, re_next_day($last)];
}

/**
 * New price rounded to the nearest KES 10, or 1 unit of any other currency (half up).
 * The scaled value is first rounded to 6 places so binary noise (244.99999999999997
 * for 245) cannot turn an exact half into a round-down.
 */
function re_round_price(float $v, string $currency): float {
    return strtoupper($currency) === 'KES' ? rc_round_half_up(round($v / 10, 6)) * 10 : rc_round_half_up(round($v, 6));
}

/** A price after a % change, rounded. `× (100 + pct) / 100` keeps 350 −30% an exact 245 (× 0.7 is not). */
function re_percent_price(float $cur, float $pct, string $currency): float {
    return re_round_price($cur * (100 + $pct) / 100, $currency);
}

/** Refuse a computed price above the column's ceiling — never let it reach the INSERT as a 500. */
function re_check_max(float $price, string $roomName, string $ymd): void {
    if ($price > RE_MAX_AMOUNT) {
        throw new RateEditorRefusal("{$roomName} would exceed the maximum price (" . number_format(RE_MAX_AMOUNT, 2) . ') on '
            . date('j M Y', strtotime($ymd)) . ' — nothing was changed.');
    }
}

/**
 * Clip [firstNight, lastNight] runs (inclusive) to $today onwards: runs that end
 * before today are dropped, a straddling run starts today. Pre-filled editor dates
 * never reach into the past, so a Confirm can't rewrite nights that have gone.
 * Ymd strings compare chronologically (zero-padded).
 */
function re_clip_runs_from(array $runs, string $today): array {
    $out = [];
    foreach ($runs as $run) {
        [$a, $b] = $run;
        if ($b < $today) continue;
        $out[] = [$a < $today ? $today : $a, $b];
    }
    return $out;
}

/** Most common price; a tie takes the higher price. Null for none. */
function re_most_common_price(array $prices): ?float {
    $count = [];
    foreach ($prices as $p) {
        $k = sprintf('%.2F', (float)$p);
        $count[$k] = ($count[$k] ?? 0) + 1;
    }
    if (!$count) return null;
    uksort($count, fn($a, $b) => ($count[$b] <=> $count[$a]) ?: ((float)$b <=> (float)$a));
    return (float)array_key_first($count);
}

/**
 * The label most rooms carry (null = base / no label). Ties prefer a label over
 * none, then the higher season (Peak over Mid over Standard), then A–Z.
 */
function re_majority_label(array $labels): ?string {
    $count = []; $val = [];
    foreach ($labels as $l) {
        $l = re_label_norm($l === null ? null : (string)$l);
        $k = $l === null ? "\0" : 'L' . $l;
        $count[$k] = ($count[$k] ?? 0) + 1;
        $val[$k] = $l;
    }
    if (!$count) return null;
    $keys = array_keys($count);
    usort($keys, function ($a, $b) use ($count, $val) {
        if ($count[$a] !== $count[$b]) return $count[$b] <=> $count[$a];
        if (($val[$a] === null) !== ($val[$b] === null)) return $val[$a] === null ? 1 : -1;
        if ($val[$a] === null) return 0;
        return (rc_season_rank($val[$b]) <=> rc_season_rank($val[$a])) ?: strcmp($val[$a], $val[$b]);
    });
    return $val[$keys[0]];
}

/** A label as stored → trimmed, '' = null (whitespace is never a difference). */
function re_label_norm(?string $l): ?string {
    $l = trim((string)$l);
    return $l === '' ? null : $l;
}

/** Is a night's current label already the target label? Trimmed, case-SENSITIVE (a re-cased label is a change). */
function re_label_same(?string $a, ?string $b): bool {
    return re_label_norm($a) === re_label_norm($b);
}

/** Two labels are the same season: trimmed, case-insensitive. */
function re_label_eq(?string $a, ?string $b): bool {
    return strcasecmp(trim((string)$a), trim((string)$b)) === 0;
}

/**
 * Nightly targets [ymd => {price, label, base}] → contiguous runs of equal
 * (price, label, base): [{from, to (EXCLUSIVE), nights, price, label, base}].
 */
function re_group_runs(array $targets): array {
    ksort($targets);
    $runs = [];
    $prev = null;
    foreach ($targets as $ymd => $t) {
        $price = round((float)$t['price'], 2);
        $label = $t['label'] ?? null;
        $base  = !empty($t['base']);
        $i = count($runs) - 1;
        if ($prev !== null && $i >= 0 && $ymd === re_next_day($prev)
            && $runs[$i]['price'] === $price && $runs[$i]['label'] === $label && $runs[$i]['base'] === $base) {
            $runs[$i]['to'] = re_next_day($ymd);
            $runs[$i]['nights']++;
        } else {
            $runs[] = ['from' => $ymd, 'to' => re_next_day($ymd), 'nights' => 1, 'price' => $price, 'label' => $label, 'base' => $base];
        }
        $prev = $ymd;
    }
    return $runs;
}

/** A night as the resolver would return it when the map lacks it (never for a valid window). */
function re_base_night(float $default): array {
    return ['price' => $default, 'label' => null, 'rate_id' => null, 'is_override' => false];
}

/**
 * One selected room's plan: the nights that change and to what.
 * @return array{room_id:int, is_buyout:bool, status:string, targets:array, before:array, notes:array, skipped:?string}
 * @throws RateEditorRefusal on a price ≤ 0
 */
function re_room_plan(array $req, array $room, array $map): array {
    $cur     = strtoupper((string)$room['price_currency']);
    $default = (float)$room['price_amount'];
    $mode    = $req['mode'];
    $targets = []; $before = []; $notes = []; $skipped = null;

    $yearPrice = [];
    if ($mode === 'match') {
        $byYear = [];
        foreach ($map as $ymd => $night) {
            if (!empty($night['is_override']) && re_label_eq($night['label'] ?? null, $req['match_label'])) {
                $byYear[substr((string)$ymd, 0, 4)][] = (float)$night['price'];
            }
        }
        foreach ($byYear as $y => $prices) {
            $p = re_most_common_price($prices);
            if ($p !== null && $p > 0) $yearPrice[(string)$y] = $p;
        }
    }

    $unpriced = 0; $missingYears = []; $missed = 0;
    foreach ($req['nights'] as $ymd) {
        $night = $map[$ymd] ?? re_base_night($default);
        $curP  = (float)$night['price'];
        $curL  = ($night['label'] ?? null) !== null && trim((string)$night['label']) !== '' ? (string)$night['label'] : null;
        $isO   = !empty($night['is_override']);

        if ($mode === 'base') {
            if ($isO) {
                $targets[$ymd] = ['price' => $default, 'label' => null, 'base' => true];
                $before[$ymd]  = $night;
            }
            continue;
        }
        if ($mode === 'fixed') {
            $tp = (float)$req['amount'];
        } elseif ($mode === 'percent') {
            if ($curP <= 0) { $unpriced++; continue; }
            $tp = re_percent_price($curP, (float)$req['pct'], $cur);
        } else {                                                     // match
            $y = substr($ymd, 0, 4);
            if (!isset($yearPrice[$y])) { $missingYears[$y] = true; $missed++; continue; }
            $tp = $yearPrice[$y];
        }
        $tl = $req['keep_label'] ? $curL : $req['label'];
        $tp = round($tp, 2);
        if ($tp <= 0) {
            throw new RateEditorRefusal("{$room['name']} would be priced at 0 or less on " . date('j M Y', strtotime($ymd))
                . ' — nothing was changed. Use "Back to base price" to remove a rate.');
        }
        re_check_max($tp, (string)$room['name'], $ymd);
        if ($isO && abs($curP - $tp) < 0.005 && re_label_same($curL, $tl)) continue;   // already so
        $targets[$ymd] = ['price' => $tp, 'label' => $tl, 'base' => false];
        $before[$ymd]  = $night;
    }

    $total = count($req['nights']);
    if ($mode === 'percent' && $unpriced > 0) {
        if ($unpriced === $total) $skipped = 'No price is set on these nights — nothing to change by %.';
        else $notes[] = $unpriced . ' ' . ($unpriced === 1 ? 'night has' : 'nights have') . ' no price set and ' . ($unpriced === 1 ? 'was' : 'were') . ' left as ' . ($unpriced === 1 ? 'it is' : 'they are') . '.';
    }
    if ($mode === 'match' && $missed > 0) {
        $years = implode(', ', array_keys($missingYears));
        if ($missed === $total) $skipped = "No “{$req['match_label']}” price in {$years}.";
        else $notes[] = "No “{$req['match_label']}” price in {$years} — {$missed} " . ($missed === 1 ? 'night was' : 'nights were') . ' left as ' . ($missed === 1 ? 'it is' : 'they are') . '.';
    }

    return [
        'room_id'   => (int)$room['id'],
        'is_buyout' => false,
        'status'    => $skipped !== null ? 'skipped' : ($targets ? 'change' : 'unchanged'),
        'targets'   => $targets,
        'before'    => $before,
        'notes'     => $notes,
        'skipped'   => $skipped,
    ];
}

/**
 * The whole change, computed from current nightly maps. PURE.
 *
 * $rooms: [id => {id, name, venue_id, venue_name, price_amount, price_currency,
 *   is_entire_place, is_published}] — every selected room PLUS every room of the
 *   selected rooms' properties (for buyout sums).
 * $maps:  [id => nightly map] from rates_nightly_maps() over re_load_window().
 *
 * Buyouts: for each property touched where exactly ONE room is_entire_place, it
 * is not selected, and a selected published non-entire room changes: the
 * buyout's price for each of those nights = the sum of the property's published
 * non-entire rooms' prices AFTER the change (0-priced / other-currency rooms left
 * out and named), label = re_majority_label(). Written only with update_buyouts.
 *
 * @return array{rooms: array<int, array>, buyouts: array, buyouts_available: bool}
 * @throws RateEditorRefusal
 */
function re_compute(array $req, array $rooms, array $maps): array {
    $sel = $req['rooms'];
    foreach ($sel as $id) {
        if (!isset($rooms[$id])) throw new RateEditorRefusal('A chosen room no longer exists — reload the page.');
    }

    if ($req['mode'] === 'fixed') {
        $byCur = [];
        foreach ($sel as $id) $byCur[strtoupper((string)$rooms[$id]['price_currency'])][] = (string)$rooms[$id]['name'];
        if (count($byCur) > 1) {
            ksort($byCur);
            $parts = [];
            foreach ($byCur as $c => $names) $parts[] = $c . ': ' . implode(', ', $names);
            throw new RateEditorRefusal('A fixed price needs rooms in one currency — these differ (' . implode(' · ', $parts)
                . '). Split the change by currency, or use "Change by %".');
        }
    }

    if ($req['mode'] === 'sum') return re_compute_sum($req, $rooms, $maps);

    $out = ['rooms' => [], 'buyouts' => [], 'buyouts_available' => false];
    foreach ($sel as $id) $out['rooms'][$id] = re_room_plan($req, $rooms[$id], $maps[$id] ?? []);

    $venues = [];
    foreach ($sel as $id) if ($rooms[$id]['venue_id'] !== null) $venues[(int)$rooms[$id]['venue_id']] = true;

    foreach (array_keys($venues) as $vid) {
        $vRooms = array_filter($rooms, fn($r) => $r['venue_id'] !== null && (int)$r['venue_id'] === $vid);
        $entire = array_values(array_filter($vRooms, fn($r) => !empty($r['is_entire_place'])));
        if (count($entire) !== 1) continue;
        $b = $entire[0];
        $bid = (int)$b['id'];
        if (in_array($bid, $sel, true)) continue;
        $members = array_filter($vRooms, fn($r) => empty($r['is_entire_place']) && !empty($r['is_published']));
        if (!$members) continue;

        $affected = [];
        foreach ($sel as $id) {
            if (!isset($members[$id])) continue;
            foreach ($out['rooms'][$id]['targets'] as $ymd => $_) $affected[$ymd] = true;
        }
        if (!$affected) continue;
        ksort($affected);
        $out['buyouts_available'] = true;

        $info = ['venue_id' => $vid, 'venue_name' => $b['venue_name'] ?? null, 'room_id' => $bid, 'name' => (string)$b['name'],
                 'applied' => (bool)$req['update_buyouts'], 'nights' => 0, 'left_out' => [], 'nights_skipped' => 0];
        if (!$req['update_buyouts']) { $out['buyouts'][] = $info; continue; }

        $bCur = strtoupper((string)$b['price_currency']);
        $bDef = (float)$b['price_amount'];
        $bMap = $maps[$bid] ?? [];
        $targets = []; $before = []; $leftOut = [];
        $memberTargets = array_map(fn($e) => $e['targets'], $out['rooms']);
        foreach (array_keys($affected) as $ymd) {
            $sum = re_buyout_night_sum($bCur, $members, $ymd, $maps, $memberTargets, $leftOut);
            if ($sum === null) { $info['nights_skipped']++; continue; }
            $tp = $sum['price'];
            re_check_max($tp, (string)$b['name'], $ymd);
            $tl = $sum['label'];
            $night = $bMap[$ymd] ?? re_base_night($bDef);
            $curL  = ($night['label'] ?? null) !== null && trim((string)$night['label']) !== '' ? (string)$night['label'] : null;
            if (!empty($night['is_override']) && abs((float)$night['price'] - $tp) < 0.005 && re_label_same($curL, $tl)) continue;
            $targets[$ymd] = ['price' => $tp, 'label' => $tl, 'base' => false];
            $before[$ymd]  = $night;
        }
        $notes = [];
        if ($info['nights_skipped'] > 0) {
            $notes[] = $info['nights_skipped'] . ' ' . ($info['nights_skipped'] === 1 ? 'night' : 'nights')
                     . ' left as ' . ($info['nights_skipped'] === 1 ? 'it is' : 'they are') . ' — no priced rooms to add up.';
        }
        $info['left_out'] = array_values($leftOut);
        $info['nights']   = count($targets);
        $out['buyouts'][] = $info;
        $out['rooms'][$bid] = ['room_id' => $bid, 'is_buyout' => true,
            'status' => $targets ? 'change' : 'unchanged', 'targets' => $targets, 'before' => $before,
            'notes' => $notes, 'skipped' => null];
    }
    return $out;
}

/**
 * What a buyout costs on one night: the sum of its property's published rooms
 * (`$members`). PURE. The ONE place that adds a buyout up — used by the editor's
 * "Also update buyouts" and by the Buyout check (mode 'sum'), so the two can
 * never disagree. `$memberTargets` [room id => [ymd => target]] is a change in
 * flight: its new price counts instead of the current one. A room priced in
 * another currency than the buyout, or with no price that night, is left out and
 * recorded in `$leftOut` (never summed across currencies, never counted as 0).
 * @return array{price: float, label: ?string}|null  null = no priced rooms that night
 */
function re_buyout_night_sum(string $bCur, array $members, string $ymd, array $maps, array $memberTargets, array &$leftOut): ?array {
    $sum = 0.0; $labels = [];
    foreach ($members as $mid => $m) {
        $mc = strtoupper((string)$m['price_currency']);
        if ($mc !== $bCur) {
            $leftOut[$mid] = ['room_id' => (int)$mid, 'name' => (string)$m['name'],
                              'reason' => "priced in {$mc}, the whole-property room in {$bCur}"];
            continue;
        }
        $t = $memberTargets[$mid][$ymd] ?? null;
        if ($t !== null) {
            $p = (float)$t['price'];
            $l = $t['base'] ? null : $t['label'];
        } else {
            $night = $maps[$mid][$ymd] ?? re_base_night((float)$m['price_amount']);
            $p = (float)$night['price'];
            $l = !empty($night['is_override']) ? ($night['label'] ?? null) : null;
        }
        if ($p <= 0) {
            $leftOut[$mid] ??= ['room_id' => (int)$mid, 'name' => (string)$m['name'], 'reason' => 'no price set'];
            continue;
        }
        $sum += $p;
        $labels[] = $l;
    }
    if ($sum <= 0) return null;
    return ['price' => round($sum, 2), 'label' => re_majority_label($labels)];
}

/**
 * Mode 'sum' — set each selected buyout to the sum of its property's published
 * rooms, on the nights where it differs. PURE. Only the PRICE must add up: a
 * night already at the sum is left alone whatever its label or whether it is an
 * override; a changed night gets the label most of the rooms carry. Refuses a
 * room that is not the property's one whole-property room, or a property with
 * fewer than two published rooms to add up.
 * @throws RateEditorRefusal
 */
function re_compute_sum(array $req, array $rooms, array $maps): array {
    $out = ['rooms' => [], 'buyouts' => [], 'buyouts_available' => false];
    foreach ($req['rooms'] as $bid) {
        $b = $rooms[$bid];
        $bName = (string)$b['name'];
        if (empty($b['is_entire_place']) || $b['venue_id'] === null) {
            throw new RateEditorRefusal("{$bName} is not a whole-property room — only a buyout can be set to the sum of its property's rooms.");
        }
        $vid    = (int)$b['venue_id'];
        $vRooms = array_filter($rooms, fn($r) => $r['venue_id'] !== null && (int)$r['venue_id'] === $vid);
        if (count(array_filter($vRooms, fn($r) => !empty($r['is_entire_place']))) !== 1) {
            throw new RateEditorRefusal(($b['venue_name'] ?? 'This property') . ' has more than one whole-property room — set its buyout by hand.');
        }
        $members = array_filter($vRooms, fn($r) => empty($r['is_entire_place']) && !empty($r['is_published']));
        if (count($members) < 2) {
            throw new RateEditorRefusal(($b['venue_name'] ?? 'This property') . ' needs at least two published rooms to add up.');
        }
        $bCur = strtoupper((string)$b['price_currency']);
        $bMap = $maps[$bid] ?? [];
        $targets = []; $before = []; $leftOut = []; $noPrice = 0;
        foreach ($req['nights'] as $ymd) {
            $sum = re_buyout_night_sum($bCur, $members, $ymd, $maps, [], $leftOut);
            if ($sum === null) { $noPrice++; continue; }
            re_check_max($sum['price'], $bName, $ymd);
            $night = $bMap[$ymd] ?? re_base_night((float)$b['price_amount']);
            if (abs((float)$night['price'] - $sum['price']) < 0.005) continue;   // already adds up
            $targets[$ymd] = ['price' => $sum['price'], 'label' => $sum['label'], 'base' => false];
            $before[$ymd]  = $night;
        }
        $notes = [];
        if ($leftOut) {
            $notes[] = 'Not counted: ' . implode(', ', array_map(fn($l) => $l['name'] . ' (' . $l['reason'] . ')', array_values($leftOut))) . '.';
        }
        if ($noPrice > 0) {
            $notes[] = $noPrice . ' ' . ($noPrice === 1 ? 'night' : 'nights') . ' left as ' . ($noPrice === 1 ? 'it is' : 'they are')
                     . ' — no priced rooms to add up.';
        }
        $out['rooms'][$bid] = ['room_id' => (int)$bid, 'is_buyout' => true,
            'status' => $targets ? 'change' : 'unchanged', 'targets' => $targets, 'before' => $before,
            'notes' => $notes, 'skipped' => null];
    }
    return $out;
}

/**
 * "KES 48,000" / "$99.50": whole units unless the amount has cents — a stored
 * NUMERIC(10,2) price of 99.50 must not read back as "$100".
 */
function re_money_text(float $amt, string $cur): string {
    $amt = round($amt, 2);
    if (abs($amt - round($amt)) < 0.005) return rc_money_text($amt, $cur);
    $cur = strtoupper($cur);
    $sym = TS_CURRENCIES[$cur]['symbol'] ?? ($cur . ' ');
    return ($amt < 0 ? '-' : '') . $sym . number_format(abs($amt), 2);
}

/** "KES 48,000" or "KES 48,000 – 51,000" (same currency), "" for none. */
function re_range_text(?float $min, ?float $max, string $cur): string {
    if ($min === null || $max === null) return '';
    $a = re_money_text($min, $cur);
    if (abs($max - $min) < 0.005) return $a;
    $b = re_money_text($max, $cur);
    $sym = TS_CURRENCIES[strtoupper($cur)]['symbol'] ?? (strtoupper($cur) . ' ');
    return $a . ' – ' . (str_starts_with($b, $sym) ? substr($b, strlen($sym)) : $b);
}

/** "25 Dec 2099", "1 Jul – 31 Aug 2099", "20 Dec 2099 – 5 Jan 2100" for [first, last] nights. */
function re_nights_text(string $first, string $last): string {
    $ta = strtotime($first); $tb = strtotime($last);
    if ($first === $last) return date('j M Y', $ta);
    if (date('Y', $ta) !== date('Y', $tb)) return date('j M Y', $ta) . ' – ' . date('j M Y', $tb);
    if (date('Y-m', $ta) === date('Y-m', $tb)) return date('j', $ta) . ' – ' . date('j M Y', $tb);
    return date('j M', $ta) . ' – ' . date('j M Y', $tb);
}

/** One line for the change log: "Zuri · 4 rooms · 1 Jul – 31 Aug 2027 · Mid season → KES 51,000". */
function re_summary(array $req, array $plan, array $rooms): string {
    $changed = array_filter($plan['rooms'], fn($e) => !$e['is_buyout'] && $e['targets']);
    $ids = $changed ? array_keys($changed) : $req['rooms'];
    $venueNames = [];
    foreach ($ids as $id) if (isset($rooms[$id])) $venueNames[(string)($rooms[$id]['venue_name'] ?? 'No property')] = true;
    $venueNames = array_keys($venueNames);
    $parts = [];
    $parts[] = count($venueNames) > 2 ? count($venueNames) . ' properties' : implode(' + ', $venueNames);
    $parts[] = count($ids) === 1 ? (string)($rooms[$ids[0]]['name'] ?? '1 room') : count($ids) . ' rooms';

    $first = $req['nights'][0];
    $last  = $req['nights'][count($req['nights']) - 1];
    $span  = re_nights_text($first, $last);
    if (count($req['ranges']) > 1) $span = count($req['ranges']) . ' ranges, ' . $span;
    $parts[] = $span;

    $lbl = $req['keep_label'] ? '' : ($req['label'] ?? 'no label');
    switch ($req['mode']) {
        case 'fixed':
            $cur = strtoupper((string)($rooms[$req['rooms'][0]]['price_currency'] ?? 'USD'));
            $parts[] = ($lbl !== '' ? $lbl . ' → ' : '') . re_money_text((float)$req['amount'], $cur);
            break;
        case 'percent':
            $pct = rc_trimz(sprintf('%.2F', abs($req['pct'])));
            $parts[] = ($req['pct'] > 0 ? '+' : '−') . $pct . '%' . ($lbl !== '' ? ' as ' . $lbl : '');
            break;
        case 'match':
            $parts[] = 'match ' . $req['match_label'] . ($lbl !== '' && !re_label_eq($lbl, $req['match_label']) ? ' as ' . $lbl : '');
            break;
        case 'sum':
            $parts[] = 'buyout = sum of the rooms';
            break;
        default:
            $parts[] = 'back to base price';
    }
    foreach ($plan['buyouts'] as $b) {
        if ($b['applied'] && $b['nights'] > 0) { $parts[] = 'buyout updated'; break; }
    }
    return implode(' · ', array_filter($parts, fn($p) => $p !== ''));
}

/** The JSON preview (see api/rate-editor.php for the contract). PURE. */
function re_preview_view(array $req, array $plan, array $rooms): array {
    $rows = [];
    $order = array_values(array_filter(array_keys($rooms), fn($id) => isset($plan['rooms'][$id])));
    foreach ($order as $id) {
        $e = $plan['rooms'][$id];
        $r = $rooms[$id];
        $cur = strtoupper((string)$r['price_currency']);
        $bp = array_map(fn($n) => (float)$n['price'], array_values($e['before']));
        $ap = array_map(fn($t) => (float)$t['price'], array_values($e['targets']));
        $lb = []; foreach ($e['before'] as $n) $lb[rc_night_key($n) ?? "\0"] = rc_night_key($n);
        $la = []; foreach ($e['targets'] as $t) {
            $k = $t['base'] ? null : ($t['label'] ?? 'Other rate');
            $la[$k ?? "\0"] = $k;
        }
        $bMin = $bp ? min($bp) : null; $bMax = $bp ? max($bp) : null;
        $aMin = $ap ? min($ap) : null; $aMax = $ap ? max($ap) : null;
        $rows[] = [
            'room_id'        => (int)$id,
            'name'           => (string)$r['name'],
            'venue_id'       => $r['venue_id'] !== null ? (int)$r['venue_id'] : null,
            'venue_name'     => $r['venue_name'] ?? null,
            'currency'       => $cur,
            'is_published'   => (bool)$r['is_published'],
            'is_buyout'      => (bool)$e['is_buyout'],
            'status'         => $e['status'],
            'skipped_reason' => $e['skipped'],
            'notes'          => $e['notes'],
            'nights'         => count($e['targets']),
            'before'         => $bp ? ['min' => $bMin, 'max' => $bMax] : null,
            'after'          => $ap ? ['min' => $aMin, 'max' => $aMax] : null,
            'before_text'    => re_range_text($bMin, $bMax, $cur),
            'after_text'     => re_range_text($aMin, $aMax, $cur),
            'labels_before'  => array_values($lb),
            'labels_after'   => array_values($la),
            'to_base'        => $req['mode'] === 'base' && !$e['is_buyout'],
            'runs'           => array_map(fn($run) => [
                'first' => $run['from'], 'last' => re_prev_day($run['to']), 'nights' => $run['nights'],
                'price' => $run['price'], 'label' => $run['base'] ? null : $run['label'], 'base' => $run['base'],
            ], re_group_runs($e['targets'])),
        ];
    }
    $totals = ['rooms' => 0, 'nights' => 0, 'skipped' => 0, 'unchanged' => 0];
    foreach ($rows as $row) {
        if ($row['status'] === 'change')    { $totals['rooms']++; $totals['nights'] += $row['nights']; }
        if ($row['status'] === 'skipped')   $totals['skipped']++;
        if ($row['status'] === 'unchanged') $totals['unchanged']++;
    }
    $first = $req['nights'][0];
    $last  = $req['nights'][count($req['nights']) - 1];
    return [
        'mode'              => $req['mode'],
        'summary'           => re_summary($req, $plan, $rooms),
        'span'              => ['first' => $first, 'last' => $last, 'nights' => count($req['nights'])],
        'ranges'            => array_map(fn($r) => ['first' => $r[0], 'last' => re_prev_day($r[1])], $req['ranges']),
        'label'             => $req['mode'] === 'base' ? null : ($req['keep_label'] ? null : $req['label']),
        'keep_label'        => $req['mode'] !== 'base' && $req['keep_label'],
        'rooms'             => $rows,
        'buyouts'           => $plan['buyouts'],
        'buyouts_available' => $plan['buyouts_available'],
        'totals'            => $totals,
    ];
}

/**
 * The preview's fingerprint: sha256 over a canonical JSON of (the normalised
 * request, and per room: its currency + base price and, for every night that
 * changes, the target price/label AND the current price/label). Apply recomputes
 * it under the advisory lock and refuses on any difference — so what is written
 * is exactly what the owner saw, and a double-submitted "+5%" cannot compound
 * (the second submit sees the first one's prices). Not a secret — a concurrency
 * check on an owner-only endpoint. PURE.
 */
function re_fingerprint(array $req, array $plan, array $rooms = []): string {
    $ids = $req['rooms'];
    sort($ids);
    $canon = [
        'v'              => 1,
        'rooms'          => $ids,
        'ranges'         => $req['ranges'],
        'mode'           => $req['mode'],
        'amount'         => $req['amount'] === null ? null : sprintf('%.2F', (float)$req['amount']),
        'pct'            => $req['pct'] === null ? null : rc_trimz(sprintf('%.6F', (float)$req['pct'])),
        'match_label'    => $req['match_label'],
        'label'          => $req['label'],
        'keep_label'     => (bool)$req['keep_label'],
        'update_buyouts' => (bool)$req['update_buyouts'],
        'plan'           => [],
    ];
    $planIds = array_keys($plan['rooms']);
    sort($planIds);
    foreach ($planIds as $id) {
        $e = $plan['rooms'][$id];
        $t = $e['targets'];
        ksort($t);
        $nights = [];
        foreach ($t as $ymd => $x) {
            $cur = $e['before'][$ymd] ?? null;
            $nights[] = [(string)$ymd,
                sprintf('%.2F', (float)$x['price']), empty($x['base']) ? re_label_norm($x['label'] ?? null) : null, !empty($x['base']),
                $cur === null ? null : sprintf('%.2F', (float)$cur['price']),
                $cur === null ? null : re_label_norm($cur['label'] ?? null),
                $cur !== null && !empty($cur['is_override'])];
        }
        $r = $rooms[$id] ?? null;
        $canon['plan'][] = [(int)$id, (bool)$e['is_buyout'], (string)$e['status'],
            $r === null ? null : strtoupper((string)$r['price_currency']),
            $r === null ? null : sprintf('%.2F', (float)$r['price_amount']),
            $nights];
    }
    return hash('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Which nights each room's rows claim inside [from, to), resolved exactly like
 * rates_nightly_maps() (created_at DESC, id DESC wins): [room][ymd] => "price|label".
 * Used to compare "current rows" with a logged after_json independent of how the
 * rows are fragmented. PURE.
 */
function re_row_claims(array $rows, string $from, string $to): array {
    usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']) ?: ((int)$b['id'] <=> (int)$a['id']));
    $claims = [];
    foreach ($rows as $r) {
        $rid = (int)$r['room_id'];
        $d = new DateTime(max((string)$r['date_from'], $from));
        $e = new DateTime(min((string)$r['date_to'], $to));
        $val = sprintf('%.2F', (float)$r['price_amount']) . '|' . trim((string)($r['label'] ?? ''));
        for (; $d < $e; $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            if (!isset($claims[$rid][$k])) $claims[$rid][$k] = $val;
        }
    }
    ksort($claims);
    foreach ($claims as &$c) ksort($c);
    return $claims;
}

// ───────────────────────────────────────────────────────────────────────────
// I/O
// ───────────────────────────────────────────────────────────────────────────

/** Is the change log migrated? A catalog lookup — safe inside a transaction. */
function rate_log_supported(bool $refresh = false): bool {
    static $ok = null;
    if ($ok !== null && !$refresh) return $ok;
    try {
        return $ok = (bool) db_query("SELECT to_regclass('rate_change_log') IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) { return $ok = false; }
}

/** The selected rooms plus every room of their properties, [id => row] in display order. */
function re_load_rooms(array $ids): array {
    $in = implode(',', array_map('intval', $ids));
    $rows = db_query(
        "SELECT r.id, r.name, r.venue_id, v.name AS venue_name, r.price_amount, r.price_currency,
                r.is_entire_place, r.is_published
           FROM rooms r LEFT JOIN venues v ON v.id = r.venue_id
          WHERE r.id IN ({$in})
             OR r.venue_id IN (SELECT venue_id FROM rooms WHERE id IN ({$in}) AND venue_id IS NOT NULL)
          ORDER BY v.sort_order ASC NULLS LAST, v.id ASC NULLS LAST, r.sort_order ASC, r.id ASC"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'],
            'venue_id' => $r['venue_id'] !== null ? (int)$r['venue_id'] : null,
            'venue_name' => $r['venue_name'] !== null ? (string)$r['venue_name'] : null,
            'price_amount' => (float)$r['price_amount'], 'price_currency' => strtoupper((string)$r['price_currency']),
            'is_entire_place' => (bool)$r['is_entire_place'], 'is_published' => (bool)$r['is_published'],
        ];
    }
    foreach ($ids as $id) {
        if (!isset($out[(int)$id])) throw new RateEditorRefusal('A chosen room no longer exists — reload the page.');
    }
    return $out;
}

/** Normalise, load and compute: [req, rooms, plan]. ONE rates_nightly_maps() call. */
function rate_editor_plan(array $data): array {
    $req   = re_normalize_request($data);
    $rooms = re_load_rooms($req['rooms']);
    [$from, $to] = re_load_window($req);
    $defaults = [];
    foreach ($rooms as $id => $r) $defaults[$id] = $r['price_amount'];
    $maps = rates_nightly_maps($defaults, $from, $to);
    return [$req, $rooms, re_compute($req, $rooms, $maps)];
}

/** Server-computed preview; writes nothing. Carries the fingerprint apply must send back. */
function rate_editor_preview(array $data): array {
    [$req, $rooms, $plan] = rate_editor_plan($data);
    return re_preview_view($req, $plan, $rooms)
        + ['fingerprint' => re_fingerprint($req, $plan, $rooms), 'log_supported' => rate_log_supported()];
}

/** Every `rates` row of these rooms overlapping [from, to), as stored. */
function re_rows_in_span(array $roomIds, string $from, string $to): array {
    if (!$roomIds) return [];
    $in = implode(',', array_map('intval', $roomIds));
    $rows = db_query(
        "SELECT id, room_id, date_from, date_to, price_amount, label, created_at
           FROM rates
          WHERE room_id IN ({$in}) AND date_from < :to AND date_to > :from
          ORDER BY room_id ASC, date_from ASC, id ASC",
        [':from' => $from, ':to' => $to]
    )->fetchAll();
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'room_id' => (int)$r['room_id'],
        'date_from' => (string)$r['date_from'], 'date_to' => (string)$r['date_to'],
        'price_amount' => (float)$r['price_amount'],
        'label' => $r['label'] !== null ? (string)$r['label'] : null,
        'created_at' => (string)$r['created_at'],
    ], $rows);
}

/**
 * Resolved claims for these rooms over the FULL extent of every row that touches
 * [from, to) — so nights outside the span that a split or trim could disturb are
 * covered too. One read. Returns {from, to, claims} where claims is
 * re_row_claims() over that extent: [room][ymd] => "price|label" (absent = base).
 */
function re_guard_snapshot(array $roomIds, string $from, string $to): array {
    $ef = $from; $et = $to;
    foreach (re_rows_in_span($roomIds, $from, $to) as $r) {
        if ($r['date_from'] < $ef) $ef = $r['date_from'];
        if ($r['date_to']   > $et) $et = $r['date_to'];
    }
    return ['from' => $ef, 'to' => $et, 'claims' => re_claims_over($roomIds, $ef, $et)];
}

/** re_row_claims() over the rows currently stored for these rooms in [from, to). */
function re_claims_over(array $roomIds, string $from, string $to): array {
    return re_row_claims(re_rows_in_span($roomIds, $from, $to), $from, $to);
}

/**
 * Safety net for legacy created_at ties: rates_clear_span() re-inserts the
 * right-hand remainder of a split row with a new, higher id, and the resolver
 * breaks a created_at tie on id DESC — so an overlapping older row that shares
 * that created_at can silently start (or stop) winning on nights the edit never
 * targeted. Recompute over the same extent and refuse if any night OUTSIDE
 * $targeted(room, ymd) resolves differently. Throwing rolls the caller's
 * transaction/savepoint back.
 * @throws RateEditorRefusal
 */
function re_guard_check(array $roomIds, array $snap, callable $targeted): void {
    $after = re_claims_over($roomIds, $snap['from'], $snap['to']);
    foreach ($roomIds as $rid) {
        $b = $snap['claims'][$rid] ?? [];
        $a = $after[$rid] ?? [];
        foreach ($b + $a as $ymd => $_) {
            if ($targeted($rid, (string)$ymd)) continue;
            if (($b[$ymd] ?? null) !== ($a[$ymd] ?? null)) {
                throw new RateEditorRefusal('This change would also alter prices on other nights because of overlapping older rates — nothing was changed. Clean up the overlapping rates on the room page first.');
            }
        }
    }
}

/** Write one room's targets: base runs cleared, priced runs via rates_apply_ranges() per (price, label). */
function re_write_room(int $roomId, array $targets): void {
    $groups = [];
    foreach (re_group_runs($targets) as $run) {
        if ($run['base']) { rates_clear_span($roomId, $run['from'], $run['to']); continue; }
        $k = sprintf('%.2F', $run['price']) . '|' . ($run['label'] === null ? "\0" : $run['label']);
        $groups[$k] ??= ['price' => $run['price'], 'label' => $run['label'], 'ranges' => []];
        $groups[$k]['ranges'][] = [$run['from'], $run['to']];
    }
    foreach ($groups as $g) {
        if (rates_apply_ranges($roomId, $g['ranges'], (float)$g['price'], $g['label']) < 1) {
            throw new RuntimeException("rate editor: nothing written for room {$roomId}");
        }
    }
}

/**
 * Run $fn atomically: in a new transaction, or — inside the caller's (PDO/pgsql
 * cannot nest) — in a SAVEPOINT that is rolled back on failure, so a refusal
 * thrown half-way (e.g. undo's post-condition) never leaves partial writes in the
 * caller's transaction. Same pattern as create_hold_with_block() in db.php.
 */
function re_tx(callable $fn) {
    static $n = 0;
    $pdo = db();
    $own = !$pdo->inTransaction();
    $sp  = $own ? null : 're_tx_' . (++$n);
    if ($own) $pdo->beginTransaction();
    else      $pdo->exec("SAVEPOINT {$sp}");
    try {
        // Serialise editor writes (apply / undo) so two owners cannot interleave
        // a recompute and a write, or undo across each other.
        db_query("SELECT pg_advisory_xact_lock(hashtext('ts_rate_editor'))");
        $out = $fn();
        if ($own) $pdo->commit();
        else      $pdo->exec("RELEASE SAVEPOINT {$sp}");
        return $out;
    } catch (Throwable $e) {
        if ($own) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } else {
            // Surface the real error, never the rollback's.
            try { $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}"); } catch (Throwable $_) {}
        }
        throw $e;
    }
}

/** Postgres int[] text ('{1,2}') → int list. */
function re_pg_int_array($v): array {
    if (is_array($v)) return array_map('intval', $v);
    $s = trim((string)$v, '{} ');
    return $s === '' ? [] : array_map('intval', explode(',', $s));
}

/**
 * Apply a change: recompute server-side, write every room's runs and the log row
 * in ONE transaction. Returns {change_id, logged, log_note, summary, totals, preview}.
 * $data['fingerprint'] (from the preview) is REQUIRED and re-checked under the
 * advisory lock: any difference — rates edited since, or the same change already
 * applied (double submit) — refuses the whole apply.
 * @throws RateEditorRefusal (missing/malformed fingerprint, "nothing to change", …)
 * @throws RateEditorConflict when the fingerprint no longer matches
 */
function rate_editor_apply(array $data, ?int $adminId): array {
    $fp = $data['fingerprint'] ?? null;
    if (!is_string($fp) || preg_match('/^[0-9a-f]{64}$/', $fp) !== 1) {
        throw new RateEditorRefusal('Preview the change first, then apply it.');
    }
    return re_tx(function () use ($data, $adminId, $fp) {
        [$req, $rooms, $plan] = rate_editor_plan($data);
        if (!hash_equals(re_fingerprint($req, $plan, $rooms), $fp)) {
            throw new RateEditorConflict('Rates changed since the preview — preview again.');
        }
        $view  = re_preview_view($req, $plan, $rooms);
        $write = array_filter($plan['rooms'], fn($e) => $e['targets'] !== []);
        if (!$write) throw new RateEditorRefusal('Nothing to change — these rates already match.');

        $nights = [];
        foreach ($write as $e) foreach ($e['targets'] as $ymd => $_) $nights[$ymd] = true;
        ksort($nights);
        $spanFrom = array_key_first($nights);
        $spanTo   = re_next_day((string)array_key_last($nights));
        $roomIds  = array_map('intval', array_keys($write));
        sort($roomIds);

        $logOn  = rate_log_supported();
        $before = $logOn ? re_rows_in_span($roomIds, $spanFrom, $spanTo) : [];
        $snap   = re_guard_snapshot($roomIds, $spanFrom, $spanTo);
        foreach ($write as $id => $e) re_write_room((int)$id, $e['targets']);
        re_guard_check($roomIds, $snap, fn($rid, $ymd) => isset($write[$rid]['targets'][$ymd]));

        $changeId = null;
        if ($logOn) {
            $after = re_rows_in_span($roomIds, $spanFrom, $spanTo);
            $reqLog = $req; unset($reqLog['nights']);
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
            $changeId = (int) db_query(
                "INSERT INTO rate_change_log (admin_id, summary, rooms, span_from, span_to, request_json, before_json, after_json)
                 VALUES (:a, :s, CAST(:rooms AS integer[]), :f, :t, CAST(:req AS jsonb), CAST(:b AS jsonb), CAST(:af AS jsonb))
                 RETURNING id",
                [':a' => $adminId, ':s' => $view['summary'], ':rooms' => '{' . implode(',', $roomIds) . '}',
                 ':f' => $spanFrom, ':t' => $spanTo,
                 ':req' => json_encode($reqLog, $flags), ':b' => json_encode($before, $flags), ':af' => json_encode($after, $flags)]
            )->fetchColumn();
        }
        return [
            'change_id' => $changeId,
            'logged'    => $logOn,
            'log_note'  => $logOn ? null : 'Applied without a change-log entry — the change log and undo need the add_rate_change_log migration.',
            'summary'   => $view['summary'],
            'totals'    => $view['totals'],
            'span'      => ['first' => $spanFrom, 'last' => re_prev_day($spanTo)],
            'preview'   => $view,
        ];
    });
}

/**
 * Why a logged change cannot be undone right now, or null. $row needs id,
 * rooms, span_from, span_to, undone_at, after_json.
 * @return array{code:string, message:string}|null
 */
function re_undo_blocker(array $row): ?array {
    if (!empty($row['undone_at'])) return ['code' => 'undone', 'message' => 'This change was already undone.'];
    $newer = db_query(
        "SELECT id, summary FROM rate_change_log
          WHERE id > :id AND undone_at IS NULL AND rooms && CAST(:rooms AS integer[])
            AND span_from < :to AND span_to > :from
          ORDER BY id DESC LIMIT 1",
        [':id' => (int)$row['id'], ':rooms' => '{' . implode(',', re_pg_int_array($row['rooms'])) . '}',
         ':from' => (string)$row['span_from'], ':to' => (string)$row['span_to']]
    )->fetch();
    if ($newer) return ['code' => 'newer', 'message' => "Undo the newer change first: “{$newer['summary']}”."];

    $from = (string)$row['span_from']; $to = (string)$row['span_to'];
    $after = json_decode((string)$row['after_json'], true) ?: [];
    $now   = re_rows_in_span(re_pg_int_array($row['rooms']), $from, $to);
    if (re_row_claims($now, $from, $to) !== re_row_claims($after, $from, $to)) {
        return ['code' => 'edited', 'message' => 'These rates were edited elsewhere since — undo not possible.'];
    }
    return null;
}

/**
 * Undo a logged change: restore its before rows (clipped to its span) for its
 * rooms, in one transaction. LIFO-safe — refused while a later, not-undone
 * change overlaps the same rooms and nights, or when the rows no longer match
 * what the change wrote.
 */
function rate_editor_undo(int $changeId, ?int $adminId): array {
    if (!rate_log_supported()) throw new RateEditorUnavailable('Undo needs the add_rate_change_log migration.');
    return re_tx(function () use ($changeId, $adminId) {
        $row = db_query('SELECT * FROM rate_change_log WHERE id = :id FOR UPDATE', [':id' => $changeId])->fetch();
        if (!$row) throw new RateEditorNotFound('That change was not found.');
        $block = re_undo_blocker($row);
        if ($block !== null) throw new RateEditorRefusal($block['message']);

        $rooms = re_pg_int_array($row['rooms']);
        $from  = (string)$row['span_from'];
        $to    = (string)$row['span_to'];
        $beforeRows = array_values(array_filter(
            json_decode((string)$row['before_json'], true) ?: [],
            fn($b) => is_array($b) && in_array((int)($b['room_id'] ?? 0), $rooms, true)
        ));
        // Re-insert OLDEST first (created_at ASC, id ASC): the resolver breaks a
        // created_at tie by id DESC, and the restored rows get new ids in insert
        // order — so this order keeps every legacy tie resolving as it did.
        usort($beforeRows, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']) ?: ((int)$a['id'] <=> (int)$b['id']));

        $snap = re_guard_snapshot($rooms, $from, $to);
        foreach ($rooms as $rid) rates_clear_span($rid, $from, $to);
        foreach ($beforeRows as $b) {
            $f = max((string)$b['date_from'], $from);
            $t = min((string)$b['date_to'], $to);
            if ($f >= $t) continue;
            db_query(
                'INSERT INTO rates (room_id, date_from, date_to, price_amount, label, created_at)
                 VALUES (:r, :f, :t, :p, :l, :c)',
                [':r' => (int)$b['room_id'], ':f' => $f, ':t' => $t, ':p' => $b['price_amount'],
                 ':l' => $b['label'], ':c' => (string)$b['created_at']]
            );
        }
        re_guard_check($rooms, $snap, fn($rid, $ymd) => $ymd >= $from && $ymd < $to);
        // Post-condition: every night of the span resolves exactly as it did
        // before the change. Otherwise throw — re_tx rolls the whole undo back.
        if (re_row_claims(re_rows_in_span($rooms, $from, $to), $from, $to) !== re_row_claims($beforeRows, $from, $to)) {
            throw new RateEditorRefusal('Undo could not restore these rates exactly — nothing was changed.');
        }
        db_query('UPDATE rate_change_log SET undone_at = NOW(), undone_by = :a WHERE id = :id',
            [':a' => $adminId, ':id' => $changeId]);
        return ['change_id' => $changeId, 'summary' => (string)$row['summary'], 'rooms' => $rooms,
                'span' => ['first' => $from, 'last' => re_prev_day($to)]];
    });
}

/** Latest changes, newest first, with who / when / undo state. */
function rate_editor_log(int $limit = RE_LOG_DEFAULT): array {
    if (!rate_log_supported()) return ['supported' => false, 'changes' => []];
    $limit = max(1, min(RE_LOG_MAX, $limit));
    $rows = db_query(
        "SELECT l.id, l.created_at, l.summary, l.rooms, l.span_from, l.span_to, l.undone_at, l.after_json,
                a.name AS by_name, a.email AS by_email, u.name AS undone_name, u.email AS undone_email,
                EXISTS (SELECT 1 FROM rate_change_log n
                         WHERE n.id > l.id AND n.undone_at IS NULL AND n.rooms && l.rooms
                           AND n.span_from < l.span_to AND n.span_to > l.span_from) AS has_newer
           FROM rate_change_log l
           LEFT JOIN admin_users a ON a.id = l.admin_id
           LEFT JOIN admin_users u ON u.id = l.undone_by
          ORDER BY l.id DESC LIMIT {$limit}"
    )->fetchAll();

    $allRooms = [];
    foreach ($rows as $r) foreach (re_pg_int_array($r['rooms']) as $rid) $allRooms[$rid] = true;
    $names = [];
    if ($allRooms) {
        foreach (db_query('SELECT id, name FROM rooms WHERE id IN (' . implode(',', array_keys($allRooms)) . ')')->fetchAll() as $n) {
            $names[(int)$n['id']] = (string)$n['name'];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        if (!empty($r['undone_at']))  $block = ['code' => 'undone', 'message' => 'Undone.'];
        elseif ($r['has_newer'])      $block = ['code' => 'newer', 'message' => 'Undo the newer change first.'];
        else                          $block = re_undo_blocker($r);
        $rooms = re_pg_int_array($r['rooms']);
        $out[] = [
            'id'            => (int)$r['id'],
            'summary'       => (string)$r['summary'],
            'created_at'    => date('c', strtotime((string)$r['created_at'])),
            'created_text'  => date('j M Y, H:i', strtotime((string)$r['created_at'])),
            'by'            => trim((string)($r['by_name'] ?? '')) ?: ((string)($r['by_email'] ?? '') ?: 'Unknown'),
            'rooms'         => $rooms,
            'room_names'    => array_map(fn($id) => $names[$id] ?? "Room {$id}", $rooms),
            'span'          => ['first' => (string)$r['span_from'], 'last' => re_prev_day((string)$r['span_to'])],
            'undone'        => !empty($r['undone_at']),
            'undone_at'     => !empty($r['undone_at']) ? date('c', strtotime((string)$r['undone_at'])) : null,
            'undone_by'     => !empty($r['undone_at']) ? (trim((string)($r['undone_name'] ?? '')) ?: ((string)($r['undone_email'] ?? '') ?: 'Unknown')) : null,
            'can_undo'      => $block === null,
            'undo_blocked'  => $block,
        ];
    }
    return ['supported' => true, 'changes' => $out];
}

/** Season labels already in use (for suggestions), Standard / Mid / Peak first. */
function rate_editor_labels(): array {
    $rows = db_query("SELECT DISTINCT TRIM(label) AS l FROM rates WHERE label IS NOT NULL AND TRIM(label) <> ''")->fetchAll(PDO::FETCH_COLUMN);
    return rc_sort_labels(array_map('strval', $rows));
}

/**
 * Everything the form needs: rooms grouped by property (owner = every venue,
 * published or not), the buyout room per property (exactly one whole-property
 * room + at least one published other room), labels in use, limits.
 */
function rate_editor_options(): array {
    $rows = db_query(
        "SELECT r.id, r.name, r.venue_id, v.name AS venue_name, v.is_published AS venue_published,
                r.price_amount, r.price_currency, r.is_entire_place, r.is_published
           FROM rooms r LEFT JOIN venues v ON v.id = r.venue_id
          ORDER BY v.sort_order ASC NULLS LAST, v.id ASC NULLS LAST, r.sort_order ASC, r.id ASC"
    )->fetchAll();
    $venues = [];
    foreach ($rows as $r) {
        $key = $r['venue_id'] === null ? 'none' : (string)(int)$r['venue_id'];
        $venues[$key] ??= [
            'id' => $r['venue_id'] === null ? null : (int)$r['venue_id'],
            'name' => $r['venue_id'] === null ? 'No property' : (string)$r['venue_name'],
            'is_published' => $r['venue_id'] === null ? false : (bool)$r['venue_published'],
            'buyout_room_id' => null, 'rooms' => [],
        ];
        $venues[$key]['rooms'][] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'],
            'currency' => strtoupper((string)$r['price_currency']), 'base_price' => (float)$r['price_amount'],
            'is_entire_place' => (bool)$r['is_entire_place'], 'is_published' => (bool)$r['is_published'],
        ];
    }
    foreach ($venues as &$v) {
        if ($v['id'] === null) continue;
        $entire = array_values(array_filter($v['rooms'], fn($r) => $r['is_entire_place']));
        $others = array_filter($v['rooms'], fn($r) => !$r['is_entire_place'] && $r['is_published']);
        if (count($entire) === 1 && $others) $v['buyout_room_id'] = $entire[0]['id'];
    }
    unset($v);
    return [
        'venues'        => array_values($venues),
        'labels'        => rate_editor_labels(),
        'log_supported' => rate_log_supported(),
        'limits'        => ['max_rooms' => RE_MAX_ROOMS, 'max_nights' => RE_MAX_NIGHTS, 'max_ranges' => RE_MAX_RANGES],
    ];
}

/** Best-effort audit trail; inside a caller's transaction it runs in a SAVEPOINT so a failure cannot abort it. */
function re_audit(string $action, int $changeId, string $notes): void {
    if (!function_exists('audit_log')) return;
    $pdo = db();
    $sp  = $pdo->inTransaction();
    try {
        if ($sp) $pdo->exec('SAVEPOINT re_audit');
        audit_log($action, 'rate_change_log', $changeId, $notes);
        if ($sp) $pdo->exec('RELEASE SAVEPOINT re_audit');
    } catch (Throwable $e) {
        if ($sp) { try { $pdo->exec('ROLLBACK TO SAVEPOINT re_audit'); } catch (Throwable $_) {} }
        error_log('[rate-editor] audit: ' . $e->getMessage());
    }
}

/**
 * The JSON endpoint without the session: guards + action routing.
 * $admin is current_admin() (false when signed out); $sessionToken is the
 * session's csrf_token. Returns ['status' => int, 'body' => array].
 * Contract: see api/rate-editor.php.
 */
function rate_editor_dispatch(string $method, array $data, array|false $admin, string $sessionToken): array {
    $res = fn(int $status, array $body) => ['status' => $status, 'body' => $body];
    if (!$admin || (array_key_exists('is_active', $admin) && !$admin['is_active'])) {
        return $res(401, ['ok' => false, 'error' => 'Your session expired. Sign in again.']);
    }
    if (($admin['role'] ?? '') !== 'owner') return $res(403, ['ok' => false, 'error' => 'Only the owner can change rates.']);
    if (strtoupper($method) !== 'POST') return $res(405, ['ok' => false, 'error' => 'Method not allowed.']);
    $token = is_string($data['csrf_token'] ?? null) ? $data['csrf_token'] : '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
        return $res(403, ['ok' => false, 'error' => 'Your session token expired. Reload the page.']);
    }

    $adminId = (int)($admin['id'] ?? 0) ?: null;
    $action  = is_string($data['action'] ?? null) ? $data['action'] : '';
    try {
        switch ($action) {
            case 'options': return $res(200, ['ok' => true] + rate_editor_options());
            case 'labels':  return $res(200, ['ok' => true, 'labels' => rate_editor_labels()]);
            case 'preview': return $res(200, ['ok' => true, 'preview' => rate_editor_preview($data)]);
            case 'apply':
                $a = rate_editor_apply($data, $adminId);
                re_audit('rate_editor_apply', (int)($a['change_id'] ?? 0), $a['summary']);
                return $res(200, ['ok' => true, 'applied' => $a]);
            case 'undo':
                $cid = $data['change_id'] ?? null;
                if (!(is_int($cid) || (is_string($cid) && ctype_digit($cid))) || (int)$cid <= 0) {
                    return $res(422, ['ok' => false, 'error' => 'Choose a change to undo.']);
                }
                $u = rate_editor_undo((int)$cid, $adminId);
                re_audit('rate_editor_undo', $u['change_id'], $u['summary']);
                return $res(200, ['ok' => true, 'undone' => $u]);
            case 'log':
                $lim = re_number($data['limit'] ?? null);
                return $res(200, ['ok' => true] + rate_editor_log($lim === null ? RE_LOG_DEFAULT : (int)$lim));
            default:
                return $res(400, ['ok' => false, 'error' => 'Unknown action.']);
        }
    } catch (RateEditorRefusal $e) {
        return $res(422, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (RateEditorNotFound $e) {
        return $res(404, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (RateEditorUnavailable $e) {
        return $res(409, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (RateEditorConflict $e) {
        return $res(409, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[rate-editor] ' . $action . ': ' . $e->getMessage());
        return $res(500, ['ok' => false, 'error' => 'Could not complete that. Try again.']);
    }
}
