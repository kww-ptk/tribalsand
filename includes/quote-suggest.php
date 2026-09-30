<?php
declare(strict_types=1);
/**
 * Quote builder "Suggest options": from dates + party, what each property can
 * offer — the cheapest single rooms that fit, the best room combination, the
 * whole property — shaped as builder rows ({room id, qty, guests}) that
 * reception loads into the rooms table with one click and then edits.
 *
 * The options come from ts_property_configurations(), the SAME function
 * /search and the property pages use, so there is no second availability or
 * pricing path here: this file only picks, labels and orders what it returns.
 * Pure helpers first (tested without a DB), qb_suggestions() is the I/O.
 * Test: php tests/quote_suggest_logic.php
 */
require_once __DIR__ . '/listing-alternatives.php';   // ts_venue_town()

/**
 * Split a party across a combination's rows in order, filling each row up to
 * units × capacity; whatever is left (a party larger than the beds) stays on
 * the last row so no guest disappears from the quote.
 */
function qb_suggest_split_guests(array $rows, int $party): array {
    $out  = [];
    $left = max(0, $party);
    $rows = array_values($rows);
    $n    = count($rows);
    foreach ($rows as $i => $r) {
        $cap = max(0, (int)($r['units'] ?? 0)) * max(0, (int)($r['capacity'] ?? 0));
        $g   = $i === $n - 1 ? $left : min($cap, $left);
        $out[] = $g;
        $left -= $g;
    }
    return $out;
}

/**
 * One property's ts_property_configurations() output → up to $limit options, in
 * this order: the 2 cheapest single rooms (unpriced last), the best combination,
 * the whole property. Room slugs map to builder ids through $slugToId (the
 * account's catalogue); an option naming a room outside it is dropped whole.
 * A price of 0 or less is "no price set" (total null), never a free stay.
 */
function qb_suggest_venue_options(array $cfg, array $slugToId, int $party, int $limit = 3): array {
    $party  = max(1, $party);
    $priced = fn($t): ?float => is_numeric($t) && (float)$t > 0 ? round((float)$t, 2) : null;
    $idOf   = fn(array $x): ?int => isset($slugToId[(string)($x['slug'] ?? '')]) ? (int)$slugToId[(string)$x['slug']] : null;

    $singles = [];
    foreach ((array)($cfg['singles'] ?? []) as $s) {
        $id = $idOf($s);
        if (!$id) continue;
        $singles[] = [
            'kind' => 'single', 'label' => (string)($s['name'] ?? ''), 'sleeps' => (int)($s['capacity'] ?? 0),
            'total' => $priced($s['total'] ?? null), 'currency' => (string)($s['currency'] ?? 'USD'),
            'rooms' => [['id' => $id, 'qty' => 1, 'guests' => $party]],
        ];
    }
    usort($singles, fn(array $a, array $b): int =>
        [$a['total'] === null, $a['total'] ?? 0, $a['label']] <=> [$b['total'] === null, $b['total'] ?? 0, $b['label']]);

    $combo = null;
    foreach ((array)($cfg['combos'] ?? []) as $c) {
        $rows = []; $labels = []; $ok = true; $unpriced = false;
        foreach ((array)($c['rooms'] ?? []) as $cr) {
            $id = $idOf($cr);
            if (!$id) { $ok = false; break; }
            $u = max(1, (int)($cr['units_used'] ?? 1));
            $rows[]   = ['id' => $id, 'units' => $u, 'capacity' => (int)($cr['capacity'] ?? 0)];
            $labels[] = (string)($cr['name'] ?? '') . ($u > 1 ? ' ×' . $u : '');
            if ($priced($cr['total'] ?? null) === null) $unpriced = true;
        }
        if (!$ok || !$rows) continue;
        $guests = qb_suggest_split_guests($rows, $party);
        $combo = [
            'kind' => 'combo', 'label' => implode(' + ', $labels), 'sleeps' => (int)($c['capacity'] ?? 0),
            // One unpriced room makes the sum meaningless — say "no price set".
            'total' => $unpriced ? null : $priced($c['total'] ?? null), 'currency' => (string)($c['currency'] ?? 'USD'),
            'rooms' => array_map(fn(array $r, int $g) => ['id' => $r['id'], 'qty' => $r['units'], 'guests' => $g], $rows, $guests),
        ];
        break;
    }

    $entire = null;
    foreach ((array)($cfg['entire'] ?? []) as $e) {
        $id = $idOf($e);
        if (!$id) continue;
        $entire = [
            'kind' => 'entire', 'label' => (string)($e['name'] ?? 'Whole property'), 'sleeps' => (int)($e['capacity'] ?? 0),
            'total' => $priced($e['total'] ?? null), 'currency' => (string)($e['currency'] ?? 'USD'),
            'rooms' => [['id' => $id, 'qty' => 1, 'guests' => $party]],
        ];
        break;
    }

    $all = array_merge(array_slice($singles, 0, 2), $combo ? [$combo] : [], $entire ? [$entire] : []);
    return array_slice($all, 0, max(0, $limit));
}

/**
 * Order property groups: the requested property first (even when it has
 * nothing — reception should see that it's full), then properties with options
 * in the same town, then by their cheapest option ($rank converts to one
 * currency; properties price in KES or USD), properties with nothing last.
 * Adds 'preferred' to each group.
 */
function qb_suggest_order(array $groups, ?int $preferVenueId, ?callable $rank = null): array {
    $rank ??= fn(float $a, string $c): float => $a;
    $homeTown = '';
    foreach ($groups as $g) {
        if ($preferVenueId !== null && (int)$g['venue_id'] === $preferVenueId) $homeTown = ts_venue_town((string)($g['location'] ?? ''));
    }
    foreach ($groups as &$g) {
        $g['preferred'] = $preferVenueId !== null && (int)$g['venue_id'] === $preferVenueId;
        $best = null;
        foreach ((array)$g['options'] as $o) {
            if (($o['total'] ?? null) === null) continue;
            $v = (float)$rank((float)$o['total'], (string)($o['currency'] ?? 'USD'));
            $best = $best === null ? $v : min($best, $v);
        }
        $g['_best'] = $best;
        $g['_home'] = $homeTown !== '' && ts_venue_town((string)($g['location'] ?? '')) === $homeTown;
    }
    unset($g);
    usort($groups, fn(array $a, array $b): int =>
        [!$a['preferred'], !$a['options'], !$a['_home'], $a['_best'] === null, $a['_best'] ?? 0, (string)$a['name']]
        <=> [!$b['preferred'], !$b['options'], !$b['_home'], $b['_best'] === null, $b['_best'] ?? 0, (string)$b['name']]);
    return array_map(function (array $g) { unset($g['_best'], $g['_home']); return $g; }, $groups);
}

/**
 * Suggestions for every property in the account's quote catalogue ($scope:
 * null = owner/all, [] = nothing). Runs ts_property_configurations() per
 * property with $always_combos (a multi-room combo can undercut one big room,
 * as on the property pages) and maps its room slugs to catalogue ids.
 */
function qb_suggestions(string $ci, string $co, int $party, ?array $scope, ?int $preferVenueId): array {
    require_once __DIR__ . '/quote-builder.php';
    $cat = qb_catalog($scope);
    if (!$cat['rooms']) return [];
    $slugToId = []; $venueIds = [];
    foreach ($cat['rooms'] as $r) {
        $slugToId[(string)$r['slug']] = (int)$r['id'];
        $venueIds[(int)$r['venue_id']] = true;
    }
    $in = implode(',', array_map('intval', array_keys($venueIds)));   // ints — safe to inline
    $venues = db_query("SELECT * FROM venues WHERE id IN ($in) AND is_published = TRUE ORDER BY sort_order ASC, name ASC")->fetchAll();

    $groups = [];
    foreach ($venues as $v) {
        $cfg = ts_property_configurations($v, $ci, $co, max(1, $party), null, true);
        $groups[] = [
            'venue_id' => (int)$v['id'], 'name' => (string)$v['name'], 'location' => (string)($v['location'] ?? ''),
            'max_capacity' => (int)($cfg['max_capacity'] ?? 0),
            'options' => qb_suggest_venue_options($cfg, $slugToId, $party, 3),
        ];
    }
    return qb_suggest_order($groups, $preferVenueId,
        fn(float $a, string $c): float => (float) convert_price($a, $c, 'USD')['amount']);
}
