<?php
declare(strict_types=1);
/**
 * Listing page alternatives: when a property has no space for the guest's
 * dates + party, the sidebar lists the other properties that do.
 *
 * Pure helpers only — ranking takes the rows ts_search_availability() already
 * returned (the /search function), so the list can never disagree with /search
 * and there is no second availability or pricing path. Used by
 * api/alternative-properties.php and search.php. Test:
 * php tests/listing_alternatives_logic.php
 */
require_once __DIR__ . '/rates.php';   // rates_window_ymd()

/** Property type per venue slug — mirrors the homepage "Our Properties" filters. */
const TS_VENUE_TYPES = [
    'maya-kobe' => 'hotel', 'zuri' => 'hotel', 'maya_ilai' => 'hotel',
    'my-amani'  => 'villa', 'enkare-bofa' => 'villa', 'sandbox' => 'villa',
];
/** Wording matches the /search filter chips ("Private Villas" ↔ "Private Villa"). */
const TS_VENUE_TYPE_LABELS = ['hotel' => 'Boutique Hotel', 'villa' => 'Private Villa'];

/** Longest stay the alternatives lookup will check (it checks every property). */
const TS_ALT_MAX_NIGHTS = 60;

function ts_venue_type_label(string $slug): string {
    return TS_VENUE_TYPE_LABELS[TS_VENUE_TYPES[$slug] ?? ''] ?? '';
}

/** Town key of a venue location ("Watamu · Kenya" → "watamu") — the /search rule. */
function ts_venue_town(string $location): string {
    return strtolower(trim(preg_split('/[·,]/u', $location)[0] ?? ''));
}

/**
 * Validate a stay window: [check_in, check_out, error|null]. Dates are repaired
 * by rates_window_ymd() (zero-padded — every comparison here is a string
 * comparison); check-in not before $today (Nairobi, passed in); at most
 * TS_ALT_MAX_NIGHTS nights.
 */
function ts_alternatives_window(string $ci, string $co, string $today): array {
    $ci = rates_window_ymd($ci) ?? '';
    $co = rates_window_ymd($co) ?? '';
    if ($ci === '' || $co === '') return [$ci, $co, 'Dates must be valid and formatted YYYY-MM-DD'];
    if ($ci >= $co)               return [$ci, $co, 'Check-out must be after check-in'];
    if ($ci < $today)             return [$ci, $co, 'Check-in is in the past'];
    $nights = (int) round((strtotime($co . ' 12:00') - strtotime($ci . ' 12:00')) / 86400);
    if ($nights > TS_ALT_MAX_NIGHTS) return [$ci, $co, 'Stays longer than ' . TS_ALT_MAX_NIGHTS . ' nights: please contact us'];
    return [$ci, $co, null];
}

/**
 * Rank ts_search_availability() rows into the alternatives list: drop the
 * current venue and every venue with nothing for the party (count 0); same
 * town as the current venue first, then the cheapest "from" (unpriced last),
 * then name. Returns ['options' => first $limit, 'more' => how many were cut].
 */
function ts_alternative_properties(array $results, string $excludeSlug, int $limit = 3): array {
    $homeTown = '';
    foreach ($results as $r) {
        if (($r['venue']['slug'] ?? '') === $excludeSlug) { $homeTown = ts_venue_town((string)($r['venue']['location'] ?? '')); break; }
    }
    $opts = [];
    foreach ($results as $r) {
        $v = $r['venue'] ?? [];
        $slug = (string)($v['slug'] ?? '');
        if ($slug === '' || $slug === $excludeSlug || (int)($r['count'] ?? 0) <= 0) continue;
        $from = isset($r['from']) && (float)$r['from'] > 0 ? (float)$r['from'] : null;
        $opts[] = [
            'slug'     => $slug,
            'name'     => (string)($v['name'] ?? $slug),
            'location' => (string)($v['location'] ?? ''),
            'type'     => ts_venue_type_label($slug),
            'hero'     => $r['hero'] ?? null,
            'from'     => $from,
            'currency' => (string)($r['currency'] ?? 'USD'),
            '_home'    => $homeTown !== '' && ts_venue_town((string)($v['location'] ?? '')) === $homeTown,
        ];
    }
    usort($opts, function (array $a, array $b): int {
        if ($a['_home'] !== $b['_home']) return $a['_home'] ? -1 : 1;
        if (($a['from'] === null) !== ($b['from'] === null)) return $a['from'] === null ? 1 : -1;
        return [$a['from'], $a['name']] <=> [$b['from'], $b['name']];
    });
    $opts = array_map(function (array $o) { unset($o['_home']); return $o; }, $opts);
    return ['options' => array_slice($opts, 0, max(0, $limit)), 'more' => max(0, count($opts) - max(0, $limit))];
}
