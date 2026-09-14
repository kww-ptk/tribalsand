#!/usr/bin/env php
<?php
/**
 * Phase A audit — reconcile nothing, just SHOW the truth of every published
 * room's capacity + unit inventory. READ-ONLY: no writes, safe to run anywhere,
 * any number of times.
 *
 * Why this exists
 * ---------------
 * The guest search and the AI assistant/concierge decide "does this party fit,
 * and in what configuration" purely from DATA:
 *   - rooms.capacity   = max occupancy of ONE unit of that room type
 *                        (NULL/0 = "unknown" → the room is never assumed to fit
 *                         a party and is skipped by the combo search)
 *   - COUNT(active units) = how many bookable units that room type has
 * ts_property_configurations()/ts_rank_combos() (includes/db.php) then compute
 * `free × capacity` per room and rank multi-room combos. So a *data* defect —
 * a one-room suite carrying 2 active units, or a wrong capacity — makes the AI
 * suggest nonsense (the "6 pax → 2× a one-room suite" symptom). It is NOT an
 * algorithm or prompt bug; both are already correct. The fix is the data.
 *
 * The catch: the dev DB (.env / Neon) is NOT production. This script is meant to
 * run against PROD RDS so we see the real state before changing anything.
 *
 * How to run against PROD
 * -----------------------
 * Prod RDS sits inside the VPC, unreachable from a laptop. Run it inside the app
 * container, which already has DATABASE_URL, via a one-off ECS run-task with a
 * command override (region eu-west-1, cluster `default`, service `tribalsand`,
 * container `Main`). The override command is simply:
 *     php bin/audit-capacity-units.php
 * (add `--json` for machine-readable output). Output lands in CloudWatch
 * (`/aws/ecs/default/tribalsand-3abb`, stream `ecs/Main/<taskId>`) — paste it back.
 *
 * Locally (against whatever .env points at):
 *     php bin/audit-capacity-units.php
 *     php bin/audit-capacity-units.php --json
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

$asJson = in_array('--json', $argv, true);

/*
 * Source-of-truth expectations (from db/seed_rooms_2026.sql + the property pages
 * + owner decisions). Keyed by room slug: [expected_capacity, expected_active_units].
 * A NULL expected value = "no defensible number yet — owner must decide" (Enkare
 * Bofa) or "confirm against reality" (My Amani). Rooms not listed here (e.g. a
 * slug prod has that the seed doesn't, like maya_ilai/superior-suite) simply show
 * no expectation and are surfaced by the anomaly checks instead.
 */
$EXPECT = [
    // Zuri — ocean suites (one physical suite = one unit) + whole-property buyout.
    'zuri-maji'   => [2, 1],
    'zuri-mwezi'  => [4, 1],
    'zuri-ua'     => [2, 1],
    'zuri-anga'   => [2, 1],
    'zuri-jua'    => [2, 1],
    'zuri-bahari' => [2, 1],   // may have been dropped on prod — 0 rows is fine
    'zuri-buyout' => [14, 1],  // entire place

    // Maya Kobe — 5 suites + whole-property buyout.
    'maya-kobe-prestige' => [4, 1],
    'maya-kobe-haze'     => [2, 1],
    'maya-kobe-glow'     => [2, 1],
    'maya-kobe-tide'     => [2, 1],
    'maya-kobe-drift'    => [2, 1],
    'maya-kobe-buyout'   => [16, 1],  // entire place (12 without Prestige; 16 with)

    // Maya Ilai — 2 multi-unit room types (8 each) + full-compound buyout.
    'maya-ilai-villa'   => [6, 8],
    'maya-ilai-studio'  => [2, 8],
    'maya-ilai-buyout'  => [48, 1],   // entire place
];

/*
 * Entire-place venues whose bookable inventory is a single whole-property room.
 * Keyed by VENUE slug because the room slug isn't guaranteed. Value = expected
 * whole-property capacity, or null when it's an open owner decision.
 */
$EXPECT_VENUE_ENTIRE = [
    'sandbox'     => 8,     // its page states "sleeps up to 8" (4 bedrooms)
    'my-amani'    => 10,    // CONFIRM against reality — provisional
    'enkare-bofa' => null,  // OWNER DECISION — no capacity in any seed
];

// Room types that legitimately have more than one active unit. Every OTHER
// non-entire room with >1 active unit is the "2× a one-room suite" defect.
$MULTI_UNIT_OK = ['maya-ilai-villa', 'maya-ilai-studio'];

// ── Gather ──────────────────────────────────────────────────────────────────
$rows = db_query(
    "SELECT v.slug AS venue_slug, v.name AS venue_name, v.is_published AS venue_pub,
            r.id, r.slug, r.name, r.capacity, r.is_entire_place, r.is_published,
            r.price_amount, r.price_currency, r.venue_id,
            (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active = TRUE) AS active_units,
            (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id) AS total_units
       FROM rooms r
       LEFT JOIN venues v ON v.id = r.venue_id
      ORDER BY v.sort_order NULLS LAST, v.slug NULLS LAST, r.is_entire_place ASC, r.sort_order ASC, r.slug ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$anom = [
    'cap_missing_individual' => [], // A: published non-entire room, capacity NULL/0 → invisible to guest-count search
    'extra_units'            => [], // B: published non-entire room with >1 active unit (not an allowed multi-unit type)
    'no_active_units'        => [], // C: published room with 0 active units → nothing bookable
    'cap_missing_entire'     => [], // D: published entire-place room, capacity NULL/0 → buyout never sized
    'orphan'                 => [], // E: room with no venue_id
    'cap_mismatch'           => [], // F: published room whose capacity differs from source-of-truth
    'units_mismatch'         => [], // G: published room whose active-unit count differs from source-of-truth
];

foreach ($rows as $r) {
    $pub      = !empty($r['is_published']);
    $entire   = !empty($r['is_entire_place']);
    $cap      = $r['capacity'] === null ? null : (int)$r['capacity'];
    $active   = (int)$r['active_units'];
    $slug     = $r['slug'];
    $label    = ($r['venue_slug'] ?? '(no venue)') . ' / ' . $slug;

    if ($r['venue_id'] === null)      $anom['orphan'][] = $label;
    if (!$pub) continue;              // only published rooms drive search/AI

    if ($active === 0)                $anom['no_active_units'][] = "$label  (published, nothing bookable)";

    if ($entire) {
        if ($cap === null || $cap === 0) $anom['cap_missing_entire'][] = $label;
    } else {
        if ($cap === null || $cap === 0) {
            $anom['cap_missing_individual'][] = $label;
        }
        if ($active > 1 && !in_array($slug, $MULTI_UNIT_OK, true)) {
            $anom['extra_units'][] = "$label  ($active active units — a single-room type should have 1)";
        }
    }

    // Expected-vs-actual (only for rooms we have a defensible expectation for).
    $exp = $EXPECT[$slug] ?? null;
    if ($exp === null && $entire && isset($EXPECT_VENUE_ENTIRE[$r['venue_slug']])) {
        $ev = $EXPECT_VENUE_ENTIRE[$r['venue_slug']];
        $exp = [$ev, 1];
    }
    if ($exp !== null) {
        [$ecap, $eunits] = $exp;
        if ($ecap !== null && $cap !== $ecap) {
            $anom['cap_mismatch'][] = "$label  capacity=" . ($cap ?? 'NULL') . "  expected=$ecap";
        }
        if ($eunits !== null && $active !== $eunits) {
            $anom['units_mismatch'][] = "$label  active_units=$active  expected=$eunits";
        }
    }
}

// Per-venue theoretical max party (mirrors ts_property_configurations max_capacity,
// but static — ignores date blocks — so it's "biggest party this property could host").
$venueMax = [];
foreach ($rows as $r) {
    if (empty($r['is_published'])) continue;
    $vs  = $r['venue_slug'] ?? '(no venue)';
    $cap = (int)($r['capacity'] ?? 0);
    $act = (int)$r['active_units'];
    $venueMax[$vs] ??= ['individual' => 0, 'entire' => 0, 'name' => $r['venue_name'] ?? $vs];
    if ($cap <= 0) continue;
    if (!empty($r['is_entire_place'])) {
        $venueMax[$vs]['entire'] = max($venueMax[$vs]['entire'], $cap);
    } else {
        $venueMax[$vs]['individual'] += $act * $cap;
    }
}

// ── Output ────────────────────────────────────────────────────────────────────
if ($asJson) {
    echo json_encode([
        'generated_at' => date('c'),
        'rooms'        => $rows,
        'anomalies'    => $anom,
        'venue_max'    => $venueMax,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$fmtMoney = fn($r) => ((float)$r['price_amount'] > 0)
    ? rtrim(rtrim(number_format((float)$r['price_amount'], 2), '0'), '.') . ' ' . ($r['price_currency'] ?: 'USD')
    : '—';

echo "Tribal Sand — capacity & unit audit  (" . date('Y-m-d H:i:s') . ", READ-ONLY)\n";
echo str_repeat('=', 96) . "\n\n";

echo "INVENTORY (every room, published + hidden)\n";
printf("  %-32s %-4s %-4s %5s %6s %6s  %s\n", 'venue / room', 'pub', 'whole', 'cap', 'units', 'total', 'price');
echo '  ' . str_repeat('-', 92) . "\n";
$lastVenue = null;
foreach ($rows as $r) {
    $vs = $r['venue_slug'] ?? '(no venue)';
    if ($vs !== $lastVenue) { echo "  \e[1m" . $vs . "\e[0m\n"; $lastVenue = $vs; }
    printf("  %-32s %-4s %-5s %5s %6d %6d  %s\n",
        '  ' . $r['slug'],
        !empty($r['is_published']) ? 'yes' : 'NO',
        !empty($r['is_entire_place']) ? 'yes' : '',
        $r['capacity'] === null ? 'NULL' : (string)(int)$r['capacity'],
        (int)$r['active_units'],
        (int)$r['total_units'],
        $fmtMoney($r)
    );
}
echo "\n";

echo "THEORETICAL MAX PARTY PER PUBLISHED VENUE  (biggest group the property can host)\n";
echo '  ' . str_repeat('-', 92) . "\n";
foreach ($venueMax as $vs => $m) {
    $max = max($m['individual'], $m['entire']);
    printf("  %-28s max %-4d  (by rooms: %d beds,  whole-property: %d)\n",
        $vs, $max, $m['individual'], $m['entire']);
}
echo "\n";

$sections = [
    'cap_missing_individual' => 'A. Published single rooms with NO capacity — invisible to guest-count search & combos',
    'extra_units'            => 'B. Single-room types carrying >1 active unit — the "2× a one-room suite" defect',
    'no_active_units'        => 'C. Published rooms with 0 active units — nothing is bookable',
    'cap_missing_entire'     => 'D. Whole-property rooms with NO capacity — the buyout is never sized',
    'orphan'                 => 'E. Rooms with no venue_id — orphaned, not scoped to any property',
    'cap_mismatch'           => 'F. Capacity differs from source-of-truth — confirm which is right',
    'units_mismatch'         => 'G. Active-unit count differs from source-of-truth — confirm which is right',
];

$anyIssue = false;
echo "ANOMALIES\n" . str_repeat('=', 96) . "\n";
foreach ($sections as $key => $title) {
    $items = $anom[$key];
    echo "\n[" . (count($items) ? '!' : ' ') . "] $title\n";
    if (!$items) { echo "      (none)\n"; continue; }
    $anyIssue = true;
    foreach ($items as $it) echo "      - $it\n";
}

echo "\n" . str_repeat('=', 96) . "\n";
echo $anyIssue
    ? "Anomalies found. Review above, then run db/backfill_room_capacity.sql (capacity)\n"
    . "and normalise units per the notes in that file. NOTHING was changed by this audit.\n"
    : "No anomalies detected against the source-of-truth. NOTHING was changed by this audit.\n";
echo "Open owner decisions: Enkare Bofa capacity (no seed value), My Amani capacity (confirm),\n";
echo "and any published room listed in section B/C above.\n";
