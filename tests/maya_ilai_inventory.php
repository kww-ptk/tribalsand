<?php
declare(strict_types=1);
// Maya Ilai composite inventory — component resolution, ring-fencing, allocation.
// Run: php tests/maya_ilai_inventory.php
// Any DB assertions run inside ONE transaction that is ROLLED BACK at the end.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-inventory.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/gantt-lanes.php';
require_once __DIR__ . '/../includes/staff-hold-guard.php';
require_once __DIR__ . '/../includes/gantt-block-guard.php'; // gantt_block_move() — the Gantt's drag
require_once __DIR__ . '/../includes/booking.php';   // fetch_hold_for_guest() — guest portal naming test
require_once __DIR__ . '/../includes/rates.php';     // rates_window_ymd() — the max-stay reference implementation

/**
 * ── Pre-migration probe (a SUBPROCESS of this same file) ─────────────────────
 *
 * `php tests/maya_ilai_inventory.php --premigration-probe` drops
 * availability_blocks.components and holds.room_id inside a transaction, books
 * an ordinary room end to end, prints one line of JSON and rolls back.
 *
 * It has to be a separate process, for two reasons, and both are load-bearing:
 *
 *  1. components_supported() / holds_room_id_supported() memoise per process.
 *     Dropping a column inside the main suite's transaction would not change
 *     what they already answered, and forcing them to re-probe would leave the
 *     rest of the suite believing the columns are gone.
 *  2. DROP COLUMN takes an ACCESS EXCLUSIVE lock. The main suite's transaction
 *     has written to both tables, so running the ALTER while it is open would
 *     block until it ends — i.e. hang. The parent runs this only AFTER its
 *     rollback.
 *
 * A fresh process with the columns absent is also exactly the situation this is
 * about: push-to-master deploys the container, /admin/migrate.php is run by hand
 * afterwards, and in between every PHP worker is a cold process talking to a
 * database that has not been migrated yet.
 */
if (PHP_SAPI === 'cli' && in_array('--premigration-probe', $argv ?? [], true)) {
    $pdo = db();
    $pdo->beginTransaction();
    $out = ['ok' => false, 'error' => 'probe did not run'];
    try {
        db_query('ALTER TABLE availability_blocks DROP COLUMN IF EXISTS components');
        db_query('ALTER TABLE holds DROP COLUMN IF EXISTS room_id');

        // An ORDINARY room at another property: this is about Zuri and Maya Kobe
        // being able to take a booking while Maya Ilai's migration is pending.
        $room = db_query("SELECT id, slug FROM rooms WHERE slug = 'zuri-jua'")->fetch();
        if (!$room) throw new RuntimeException('no zuri-jua room on this database');

        // Far future, so no live block can make this a false negative.
        $ci = '2103-03-01';
        $co = '2103-03-04';

        $unit = find_available_unit((int)$room['id'], $ci, $co);
        if ($unit === false) throw new RuntimeException('find_available_unit() found nothing for zuri-jua');

        $holdId = create_hold_with_block(
            (int)$unit['id'], null, $ci, $co,
            'Pre-migration Probe', 'premigration-probe@example.invalid'
        );
        $block = db_query(
            'SELECT unit_id, date_from::text AS df, date_to::text AS dt, block_type
               FROM availability_blocks WHERE hold_id = :h',
            [':h' => $holdId]
        )->fetch();

        // And the villa itself. `maya-ilai-villa` is NOT one of this branch's new
        // slugs — it is in db/seed_rooms_2026.sql and already on production — so
        // mi_is_composite_room() is true for it on an unmigrated database and the
        // composite allocators ARE reachable there. They must degrade to
        // whole-villa occupancy, not raise.
        $villaOk = null;
        $villa = db_query('SELECT id, slug FROM rooms WHERE slug = :s',
            [':s' => MAYA_ILAI_VILLA_ROOM_SLUG])->fetch();
        if ($villa) {
            find_available_unit((int)$villa['id'], $ci, $co);
            get_room_blocked_dates((int)$villa['id'], $ci, $co);
            $vUnit = db_query('SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order LIMIT 1',
                [':r' => $villa['id']])->fetchColumn();
            if ($vUnit) staff_hold_block_reason((int)$vUnit, $ci, $co);
            $villaOk = true;
        }

        $out = [
            'ok'       => $holdId > 0 && $block !== false,
            'room'     => $room['slug'],
            'unit'     => (int)$unit['id'],
            'hold'     => (int)$holdId,
            'block'    => $block === false ? null : $block,
            'villa_ok' => $villaOk,
            'error'    => null,
        ];
    } catch (Throwable $e) {
        $out = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    echo json_encode($out), "\n";
    exit(0);
}

/**
 * ── API probe (also a SUBPROCESS of this file) ───────────────────────────────
 *
 * `php tests/maya_ilai_inventory.php --api-probe <room-slug> <check_in>` runs
 * api/check-availability.php's check-in-only branch for real and prints what it
 * returned. A subprocess because the endpoint ends in exit(), and because $_GET
 * has to be in place before it is included.
 *
 * It opens a transaction first and never commits, so anything the endpoint
 * writes is discarded. The pre-expiry below is not belt-and-braces: the endpoint
 * reaches expire_stale_holds(), which e-mails every guest whose hold it cancels,
 * and RESEND_API_KEY is live in .env. Marking those rows expired inside this
 * doomed transaction first leaves the endpoint's own sweep with nothing to find,
 * so no guest can be mailed by a test run.
 */
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--api-probe') {
    db()->beginTransaction();
    db_query("UPDATE holds SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['room' => (string)($argv[2] ?? ''), 'check_in' => (string)($argv[3] ?? '')];
    include __DIR__ . '/../api/check-availability.php';   // exits; the transaction dies with it
    exit(0);
}

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Postgres array codec ────────────────────────────────────────────────────
check('encode: empty set',
    mi_pg_array_encode([]) === '{}');
check('encode: one component',
    mi_pg_array_encode(['bunk']) === '{bunk}');
check('encode: several keep order',
    mi_pg_array_encode(['double_a', 'living']) === '{double_a,living}');
check('decode: raw codec treats NULL as empty — NULL-means-whole-unit is mi_block_taken_components()\'s job',
    mi_pg_array_decode(null) === []);
check('decode: empty literal',
    mi_pg_array_decode('{}') === []);
check('decode: one component',
    mi_pg_array_decode('{bunk}') === ['bunk']);
check('decode: several',
    mi_pg_array_decode('{double_a,living}') === ['double_a', 'living']);
check('decode: tolerates quotes and spaces',
    mi_pg_array_decode('{"double_a", "living"}') === ['double_a', 'living']);
check('codec: round-trips',
    mi_pg_array_decode(mi_pg_array_encode(['double_b', 'bunk', 'living']))
        === ['double_b', 'bunk', 'living']);

// ── NULL-means-whole-unit, in one place ─────────────────────────────────────
check('block taken: a NULL components column means the whole villa is taken',
    mi_block_taken_components(null) === MAYA_ILAI_ALL_COMPONENTS);
check('block taken: a components list passes through the raw codec',
    mi_block_taken_components('{bunk}') === ['bunk']);

// ── Product map ─────────────────────────────────────────────────────────────
check('map: seven composite products',
    count(mi_product_map()) === 7);
check('map: the studio is NOT composite — it is its own unit',
    !isset(mi_product_map()['maya-ilai-studio']));
check('map: villa takes all four components',
    mi_product_map()['maya-ilai-villa'] === ['double', 'double', 'bunk', 'living']);
check('map: exact product map — pins every pattern, not just the ones resolve tests happen to use',
    mi_product_map() === [
        'maya-ilai-bunk-room'     => ['bunk'],
        'maya-ilai-double'        => ['double'],
        'maya-ilai-family-room'   => ['double', 'bunk'],
        'maya-ilai-one-bed-suite' => ['double', 'living'],
        'maya-ilai-family-suite'  => ['double', 'bunk', 'living'],
        'maya-ilai-two-bed-suite' => ['double', 'double', 'living'],
        'maya-ilai-villa'         => ['double', 'double', 'bunk', 'living'],
    ]);
check('is_composite: a component product',
    mi_is_composite_room(['slug' => 'maya-ilai-double']) === true);
check('is_composite: the studio is not',
    mi_is_composite_room(['slug' => 'maya-ilai-studio']) === false);
check('is_composite: another property is not',
    mi_is_composite_room(['slug' => 'zuri-jua']) === false);

// ── Component resolution ────────────────────────────────────────────────────
check('resolve: double into an empty villa takes double_a',
    mi_resolve(['double'], []) === ['double_a']);
check('resolve: double when double_a is taken falls to double_b',
    mi_resolve(['double'], ['double_a']) === ['double_b']);
check('resolve: no doubles left',
    mi_resolve(['double'], ['double_a', 'double_b']) === null);
check('resolve: bunk is free regardless of doubles',
    mi_resolve(['bunk'], ['double_a', 'double_b']) === ['bunk']);
check('resolve: bunk already taken',
    mi_resolve(['bunk'], ['bunk']) === null);
check('resolve: villa into an empty villa',
    mi_resolve(['double', 'double', 'bunk', 'living'], [])
        === ['double_a', 'double_b', 'bunk', 'living']);
check('resolve: villa blocked by a single taken double',
    mi_resolve(['double', 'double', 'bunk', 'living'], ['double_b']) === null);
check('resolve: two-bed suite needs both doubles',
    mi_resolve(['double', 'double', 'living'], ['double_a']) === null);

// Fail-closed: an empty or unrecognised pattern must never read as "fits, takes
// nothing" — that oversells a fully-occupied villa as available.
check('resolve: an empty pattern fails closed, not "fits and takes nothing"',
    mi_resolve([], ['double_a', 'double_b', 'bunk', 'living']) === null);
check('resolve: an empty pattern fails closed even against an empty villa',
    mi_resolve([], []) === null);
check('resolve: an unrecognised component name fails closed',
    mi_resolve(['jacuzzi'], []) === null);
check('resolve: a literal double_a is not legal vocabulary (only the "double" placeholder is)',
    mi_resolve(['double_a', 'double'], []) === null);

// The case from the design review: a One-Bedroom Suite is sold in this villa,
// taking double_a + living. double_b and bunk must stay sellable.
$suiteSold = ['double_a', 'living'];
check('One-Bed Suite sold: a Double Room still sells',
    mi_resolve(['double'], $suiteSold) === ['double_b']);
check('One-Bed Suite sold: the Bunk Room still sells',
    mi_resolve(['bunk'], $suiteSold) === ['bunk']);
check('One-Bed Suite sold: a Family Room still sells',
    mi_resolve(['double', 'bunk'], $suiteSold) === ['double_b', 'bunk']);
check('One-Bed Suite sold: a SECOND One-Bed Suite does not',
    mi_resolve(['double', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the Family Suite does not',
    mi_resolve(['double', 'bunk', 'living'], $suiteSold) === null);
check('One-Bed Suite sold: the full villa does not',
    mi_resolve(['double', 'double', 'bunk', 'living'], $suiteSold) === null);

// ── Ring-fencing ────────────────────────────────────────────────────────────
// Reserved villas are the LAST N by RANK (1-based position in the villa list
// ordered by sort_order) — NOT by the raw sort_order value, which is nullable-
// default-0 and admin-editable, so it can be sparse or 0-based. Rank stays
// dense and 1-based no matter how sort_order is laid out, so changing N never
// reshuffles which villas were already reserved. These four tests pass ranks
// 6..8 of 8, which happen to equal sort_order in a dense 1..8 layout.
check('reserved: rank 8 of 8 with N=2',
    mi_villa_is_reserved(8, 8, 2) === true);
check('reserved: rank 7 of 8 with N=2',
    mi_villa_is_reserved(7, 8, 2) === true);
check('reserved: rank 6 of 8 with N=2',
    mi_villa_is_reserved(6, 8, 2) === false);
check('reserved: nothing reserved with N=0',
    mi_villa_is_reserved(8, 8, 0) === false);

// ── Villa ordering ──────────────────────────────────────────────────────────
$villas = [
    ['unit_id' => 101, 'sort_order' => 1, 'taken' => []],
    ['unit_id' => 102, 'sort_order' => 2, 'taken' => ['double_a']],
    ['unit_id' => 103, 'sort_order' => 3, 'taken' => ['double_a', 'bunk']],
    ['unit_id' => 104, 'sort_order' => 4, 'taken' => []],
    ['unit_id' => 105, 'sort_order' => 5, 'taken' => []],
    ['unit_id' => 106, 'sort_order' => 6, 'taken' => []],
    ['unit_id' => 107, 'sort_order' => 7, 'taken' => []],
    ['unit_id' => 108, 'sort_order' => 8, 'taken' => []],
];

$ordered = mi_order_villas($villas, false, 2);
check('order: component products skip the 2 reserved villas',
    count($ordered) === 6);
check('order: reserved villas are absent',
    !in_array(107, array_column($ordered, 'unit_id'), true)
    && !in_array(108, array_column($ordered, 'unit_id'), true));
check('order: pack tight — most-occupied villa first',
    array_column($ordered, 'unit_id') === [103, 102, 101, 104, 105, 106]);

$orderedVilla = mi_order_villas($villas, true, 2);
check('order: the villa product sees all 8',
    count($orderedVilla) === 8);
check('order: the villa product takes a reserved villa first',
    array_column($orderedVilla, 'unit_id')[0] === 107
    && array_column($orderedVilla, 'unit_id')[1] === 108);

$noFence = mi_order_villas($villas, false, 0);
check('order: with N=0 every villa is offered',
    count($noFence) === 8);

check('order: the internal _reserved sort artifact is not leaked to callers',
    !array_key_exists('_reserved', $ordered[0] ?? ['_reserved' => true]));

// A villa with no 'taken' key at all must not fatal. With exactly one villa the
// comparator never runs (size-dependent bug, invisible above N=1) — the second
// case below has two villas, so the comparator actually executes.
$singleNoTaken = [['unit_id' => 201, 'sort_order' => 1]];
$singleResult = mi_order_villas($singleNoTaken, false, 0);
check('order: a missing taken key does not fatal with a single villa',
    count($singleResult) === 1 && $singleResult[0]['taken'] === []);

$twoNoTaken = [
    ['unit_id' => 202, 'sort_order' => 1],
    ['unit_id' => 203, 'sort_order' => 2],
];
$twoResult = mi_order_villas($twoNoTaken, false, 0);
check('order: a missing taken key does not fatal with two villas (the comparator runs here)',
    count($twoResult) === 2
    && $twoResult[0]['taken'] === [] && $twoResult[1]['taken'] === []);

// sort_order is NOT NULL DEFAULT 0 and admin-editable, so it can be sparse or
// 0-based. Ranking must come from POSITION after sorting by sort_order, not
// from the raw value — otherwise a 0-based layout under-reserves.
$sparseVillas = [
    ['unit_id' => 301, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 302, 'sort_order' => 1, 'taken' => []],
    ['unit_id' => 303, 'sort_order' => 2, 'taken' => []],
    ['unit_id' => 304, 'sort_order' => 3, 'taken' => []],
    ['unit_id' => 305, 'sort_order' => 4, 'taken' => []],
    ['unit_id' => 306, 'sort_order' => 5, 'taken' => []],
    ['unit_id' => 307, 'sort_order' => 6, 'taken' => []],
    ['unit_id' => 308, 'sort_order' => 7, 'taken' => []],
];
$sparseOrdered = mi_order_villas($sparseVillas, false, 2);
check('order: 0-based/sparse sort_order still reserves exactly 2 villas with N=2',
    count($sparseOrdered) === 6);
check('order: 0-based/sparse sort_order reserves the villas ranked last, not sort_order>=8',
    !in_array(307, array_column($sparseOrdered, 'unit_id'), true)
    && !in_array(308, array_column($sparseOrdered, 'unit_id'), true));

// $reserved larger than the villa count must clamp, not throw. $totalVillas is
// now derived from count($villas) — there is no separate parameter to disagree
// with the list, so a deactivated/filtered villa can no longer desync it.
check('order: reserved clamps to the villa count instead of erroring',
    mi_order_villas($villas, false, 10) === []);

// units.sort_order is NOT NULL DEFAULT 0, so two admin-added villas can share a
// value. Without a tiebreaker, rank falls back to arbitrary input/row order —
// which villa gets ring-fenced would then depend on how Postgres happened to
// return the rows on a given request. unit_id must break the tie so the same
// villas are reserved no matter what order the rows arrive in.
$tieVillasA = [
    ['unit_id' => 51, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 52, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 53, 'sort_order' => 0, 'taken' => []],
    ['unit_id' => 54, 'sort_order' => 0, 'taken' => []],
];
$tieVillasB = array_reverse($tieVillasA); // same villas, reverse input order
check('order: a sort_order tie reserves the same villas regardless of input order (forward)',
    array_column(mi_order_villas($tieVillasA, false, 2), 'unit_id') === [51, 52]);
check('order: a sort_order tie reserves the same villas regardless of input order (reversed)',
    array_column(mi_order_villas($tieVillasB, false, 2), 'unit_id') === [51, 52]);

// ── Gantt lane assignment (pure) ────────────────────────────────────────────
// One villa can hold several unrelated bookings at once. The calendar draws each
// block from its dates alone, so two concurrent blocks on one unit used to land
// on byte-identical geometry and the later one painted over the earlier. Lanes
// split the row so every block is visible.
//
// Spans are half-open day indexes [start, end) — the same convention as
// availability_blocks.date_to (the checkout morning, not the last night).
$lanesOf = static function (array $spans): array {
    $r = gantt_lane_assign($spans);
    return [$r['lanes'], $r['count']];
};

[$l, $n] = $lanesOf([['key' => 'a', 'start' => 0, 'end' => 3]]);
check('lanes: a lone block sits in lane 0 and the row keeps one lane',
    $l === ['a' => 0] && $n === 1);

[$l, $n] = $lanesOf([
    ['key' => 'a', 'start' => 0, 'end' => 3],
    ['key' => 'b', 'start' => 5, 'end' => 8],
]);
check('lanes: two blocks that do not overlap share lane 0 — other properties keep one lane',
    $l === ['a' => 0, 'b' => 0] && $n === 1);

[$l, $n] = $lanesOf([
    ['key' => 'a', 'start' => 0, 'end' => 3],
    ['key' => 'b', 'start' => 3, 'end' => 6],
]);
check('lanes: back-to-back stays one lane — checkout morning is not an overlap',
    $l === ['a' => 0, 'b' => 0] && $n === 1);

// The exact reproduction: a guest books Double A and a second guest books the
// bunk in the same villa over the SAME nights.
[$l, $n] = $lanesOf([
    ['key' => 'double', 'start' => 2, 'end' => 6],
    ['key' => 'bunk',   'start' => 2, 'end' => 6],
]);
check('lanes: two identical spans on one unit get separate lanes',
    $n === 2 && $l['double'] !== $l['bunk']
    && $l['double'] < 2 && $l['bunk'] < 2);
// A tie on both dates is broken by key, so the same two bookings always draw in
// the same order — not in whatever order Postgres happened to return them.
check('lanes: an exact tie is broken deterministically by key',
    $l === ['bunk' => 0, 'double' => 1]);

// A block fully inside another was invisible too.
[$l, $n] = $lanesOf([
    ['key' => 'outer', 'start' => 0, 'end' => 10],
    ['key' => 'inner', 'start' => 3, 'end' => 5],
]);
check('lanes: a block contained inside another gets its own lane',
    $l === ['outer' => 0, 'inner' => 1] && $n === 2);

[$l, $n] = $lanesOf([
    ['key' => 'a', 'start' => 0, 'end' => 9],
    ['key' => 'b', 'start' => 1, 'end' => 9],
    ['key' => 'c', 'start' => 2, 'end' => 9],
    ['key' => 'd', 'start' => 3, 'end' => 9],
]);
check('lanes: a fully split villa — all four components at once — gets four lanes',
    $l === ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3] && $n === 4);

// First fit: a lane is reused as soon as its last block has ended.
[$l, $n] = $lanesOf([
    ['key' => 'a', 'start' => 0, 'end' => 2],
    ['key' => 'b', 'start' => 1, 'end' => 3],
    ['key' => 'c', 'start' => 2, 'end' => 4],
]);
check('lanes: first fit reuses lane 0 once its block has ended',
    $l === ['a' => 0, 'b' => 1, 'c' => 0] && $n === 2);

// Row order out of the DB is date_from ASC, but nothing guarantees a tie order.
// Lane assignment must not depend on it, or the same calendar would redraw
// differently between page loads.
$shuffled = [
    ['key' => 'c', 'start' => 2, 'end' => 4],
    ['key' => 'a', 'start' => 0, 'end' => 2],
    ['key' => 'b', 'start' => 1, 'end' => 3],
];
[$l2, $n2] = $lanesOf($shuffled);
check('lanes: assignment is independent of input order',
    $l2 === ['a' => 0, 'b' => 1, 'c' => 0] && $n2 === 2);

check('lanes: an empty unit has no lanes at all',
    gantt_lane_assign([]) === ['lanes' => [], 'count' => 0]);

// Geometry. One lane MUST reproduce today's CSS exactly (.gantt-cells height 36,
// .gantt-block top:4 bottom:4 => a 28px bar), because every other property has
// one lane and must render unchanged.
$m1 = gantt_lane_metrics(1);
check('lane metrics: one lane reproduces today\'s geometry exactly (36px row, 4px inset, 28px bar)',
    $m1['row_h'] === 36 && $m1['lane_h'] === 28 && gantt_lane_top(0, $m1) === 4);

$m2 = gantt_lane_metrics(2);
check('lane metrics: two lanes still fit the 36px row',
    $m2['row_h'] === 36
    && gantt_lane_top(0, $m2) === 4
    && gantt_lane_top(1, $m2) + $m2['lane_h'] === 36 - 4);

$m4 = gantt_lane_metrics(4);
check('lane metrics: four lanes grow the row instead of drawing invisible slivers',
    $m4['lane_h'] >= 10 && $m4['row_h'] > 36
    && gantt_lane_top(3, $m4) + $m4['lane_h'] === $m4['row_h'] - 4);

check('lane metrics: lanes tile the row without overlapping, for every plausible count',
    (function (): bool {
        for ($n = 1; $n <= 8; $n++) {
            $m = gantt_lane_metrics($n);
            if (gantt_lane_top(0, $m) !== 4) return false;                      // same top inset as today
            if (gantt_lane_top($n - 1, $m) + $m['lane_h'] !== $m['row_h'] - 4) return false; // and bottom inset
            for ($i = 1; $i < $n; $i++) {
                // Each lane starts strictly below the previous one ends — two
                // bars on one unit can never paint over each other again.
                if (gantt_lane_top($i, $m) <= gantt_lane_top($i - 1, $m) + $m['lane_h'] - 1) return false;
            }
        }
        return true;
    })());

// ── DB-backed allocation (rolled back) ──────────────────────────────────────
$villaRoom = db_query(
    'SELECT id FROM rooms WHERE slug = :s', [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
)->fetch();
$doubleRoom = db_query(
    'SELECT id FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-double']
)->fetch();

if (!$villaRoom || !$doubleRoom) {
    echo "\nSKIP  Maya Ilai rooms not seeded — run db/migrations/maya_ilai_rooms_2026.sql\n";
    echo ($failures ? "{$failures} FAILED\n" : "All passed\n");
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $CI = '2099-05-01';
    $CO = '2099-05-05';

    // A clean window: nothing booked, so the villa product allocates.
    $u = find_available_unit((int)$villaRoom['id'], $CI, $CO);
    check('alloc: the villa product finds a unit in a clean window', $u !== false);
    check('alloc: it resolves all four components',
        ($u['_mi_components'] ?? []) === ['double_a', 'double_b', 'bunk', 'living']);

    // With N=2 the villa product must take a RESERVED villa first (villa 7).
    $seventh = db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 7',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    check('alloc: the villa product takes reserved villa 7 first',
        (int)$u['id'] === (int)$seventh);

    // Sell a One-Bedroom Suite in villa 1 and re-check what remains there.
    $first = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 1',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, :df, :dt, 'booked', :c)",
        [':u' => $first, ':df' => $CI, ':dt' => $CO,
         ':c' => mi_pg_array_encode(['double_a', 'living'])]
    );

    $d = find_available_unit((int)$doubleRoom['id'], $CI, $CO);
    check('alloc: a Double Room packs into the partly-sold villa 1',
        $d !== false && (int)$d['id'] === $first);
    check('alloc: and it takes double_b',
        ($d['_mi_components'] ?? []) === ['double_b']);

    // A whole-unit block (components NULL) still means the entire villa.
    $second = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 2',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, :df, :dt, 'booked', NULL)",
        [':u' => $second, ':df' => $CI, ':dt' => $CO]
    );
    $taken = mi_villa_states((int)$villaRoom['id'], $CI, $CO);
    check('alloc: a NULL-components block takes the whole villa',
        ($taken[$second]['taken'] ?? []) === MAYA_ILAI_ALL_COMPONENTS);

    // Dates outside the window are unaffected.
    $far = find_available_unit((int)$doubleRoom['id'], '2099-09-01', '2099-09-03');
    check('alloc: an unrelated window is unaffected', $far !== false);

    // ── The same-villa constraint ───────────────────────────────────────────
    // A Family Room needs a double AND a bunk in the SAME villa. Arrange a window
    // where a free double exists in one villa and free bunks exist in others, but
    // no single villa has both — the product must be unavailable.
    $SC = '2099-11-01';
    $SO = '2099-11-03';
    $villaUnits = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r ORDER BY sort_order',
        [':r' => $villaRoom['id']]
    )->fetchAll();
    foreach ($villaUnits as $v) {
        // Villa 1 keeps a free double but loses its bunk; every other villa keeps
        // a free bunk but loses both doubles.
        $take = ((int)$v['sort_order'] === 1)
            ? ['double_a', 'bunk', 'living']
            : ['double_a', 'double_b', 'living'];
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $v['id'], ':df' => $SC, ':dt' => $SO, ':c' => mi_pg_array_encode($take)]
        );
    }
    $famRoom = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-family-room'")->fetch();
    check('same-villa: a Family Room cannot borrow a bunk from another villa',
        find_available_unit((int)$famRoom['id'], $SC, $SO) === false);
    check('same-villa: a plain Double Room still sells from villa 1',
        find_available_unit((int)$doubleRoom['id'], $SC, $SO) !== false);
    $bunkOnly = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    check('same-villa: a plain Bunk Room still sells from another villa',
        find_available_unit((int)$bunkOnly['id'], $SC, $SO) !== false);

    // Maya Ilai must NOT use the venue-wide is_entire_place exclusion.
    $villaRoomRow = db_query(
        'SELECT id, slug, venue_id, is_entire_place FROM rooms WHERE slug = :s',
        [':s' => MAYA_ILAI_VILLA_ROOM_SLUG]
    )->fetch();
    check('conflict: Maya Ilai has no venue-wide conflict units',
        room_conflict_unit_ids($villaRoomRow) === []);

    // The seed data never sets is_entire_place=TRUE on a Maya Ilai room, so the
    // assertion above passes even without the guard (the "else" branch finds no
    // whole-villa sibling in the venue either way). Simulate the guard actually
    // being exercised — mi_is_composite_room() must short-circuit BEFORE the
    // is_entire_place branch even runs, or a future flip of that flag (or a
    // wrongly-config'd room) would block the whole property off one booking.
    $villaRoomIfEntire = $villaRoomRow;
    $villaRoomIfEntire['is_entire_place'] = true;
    check('conflict: still exempt even if is_entire_place were set on the villa room',
        room_conflict_unit_ids($villaRoomIfEntire) === []);

    // Zuri keeps the old behaviour.
    $zuriEntire = db_query(
        "SELECT id, slug, venue_id, is_entire_place FROM rooms
          WHERE is_entire_place = TRUE AND venue_id = (SELECT id FROM venues WHERE slug = 'zuri')"
    )->fetch();
    if ($zuriEntire) {
        check('conflict: Zuri still blocks its sibling units',
            count(room_conflict_unit_ids($zuriEntire)) > 0);
    }

    // ── Task 7: components are written onto the block ──────────────────────
    // A hold on a composite product must record its components on the block.
    $third = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 3',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $hid = create_hold_with_block(
        $third, null, '2099-07-01', '2099-07-04',
        'Component Test', 'test@example.com', 'pending', 24,
        ['double_a', 'living']
    );
    $written = db_query(
        'SELECT components::text AS c FROM availability_blocks WHERE hold_id = :h',
        [':h' => $hid]
    )->fetchColumn();
    check('hold: components are written onto the block',
        mi_pg_array_decode($written) === ['double_a', 'living']);

    // Omitting them keeps the old meaning: the whole unit.
    $fourth = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 4',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $hid2 = create_hold_with_block(
        $fourth, null, '2099-07-01', '2099-07-04',
        'Whole Unit Test', 'test@example.com', 'pending', 24
    );
    $written2 = db_query(
        'SELECT components FROM availability_blocks WHERE hold_id = :h',
        [':h' => $hid2]
    )->fetchColumn();
    check('hold: omitting components leaves NULL — the whole unit',
        $written2 === null);

    // ── Task 8: guest calendar blocked dates ────────────────────────────────
    // Fill every non-reserved villa's doubles over one night, so a Double Room
    // cannot be sold on that date and the calendar must say so.
    $BD = '2099-08-10';
    $BE = '2099-08-11';
    $allVillas = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r ORDER BY sort_order',
        [':r' => $villaRoom['id']]
    )->fetchAll();
    foreach ($allVillas as $v) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $v['id'], ':df' => $BD, ':dt' => $BE,
             ':c' => mi_pg_array_encode(['double_a', 'double_b'])]
        );
    }
    $blocked = get_room_blocked_dates((int)$doubleRoom['id'], '2099-08-01', '2099-08-20');
    check('calendar: a Double Room is blocked when every villa has both doubles sold',
        in_array($BD, $blocked, true));
    check('calendar: neighbouring dates stay open',
        !in_array('2099-08-12', $blocked, true));

    // The Bunk Room is untouched by doubles being sold.
    $bunkRoom = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    if ($bunkRoom) {
        $bunkBlocked = get_room_blocked_dates((int)$bunkRoom['id'], '2099-08-01', '2099-08-20');
        check('calendar: the Bunk Room is unaffected by sold doubles',
            !in_array($BD, $bunkBlocked, true));
    }

    // ── Task 9: atomic allocate + hold ──────────────────────────────────────
    // mi_allocate_and_hold() re-runs the allocation inside a transaction that
    // holds an advisory lock on the villa room, so two concurrent requests
    // cannot both claim the same component.
    $txDepthBefore = db()->inTransaction();

    $doubleRoomFull = db_query(
        'SELECT * FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-double']
    )->fetch();

    $AI = '2099-10-01';
    $AO = '2099-10-04';
    $atomicHold = mi_allocate_and_hold(
        $doubleRoomFull, null, $AI, $AO, 'Atomic Test', 'atomic@example.com'
    );
    check('atomic: a clean window returns a hold id',
        is_int($atomicHold) && $atomicHold > 0);
    check('atomic: the caller\'s transaction is untouched',
        db()->inTransaction() === $txDepthBefore);

    $atomicBlock = db_query(
        "SELECT ab.components::text AS c, u.room_id
           FROM availability_blocks ab JOIN units u ON u.id = ab.unit_id
          WHERE ab.hold_id = :h",
        [':h' => $atomicHold]
    )->fetch();
    check('atomic: the block carries the resolved components',
        $atomicBlock && mi_pg_array_decode($atomicBlock['c']) === ['double_a']);
    check('atomic: the block lands on a villa unit',
        $atomicBlock && (int)$atomicBlock['room_id'] === (int)$villaRoom['id']);
    check('atomic: the hold row is the one that was returned',
        (int) db_query('SELECT COUNT(*) FROM holds WHERE id = :h', [':h' => $atomicHold])
            ->fetchColumn() === 1);

    // Nothing can satisfy the pattern → FALSE, not an exception and not a
    // whole-unit block. Sell every villa's bunk, then ask for a Bunk Room.
    $NB  = '2099-10-20';
    $NBO = '2099-10-22';
    foreach ($allVillas as $v) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $v['id'], ':df' => $NB, ':dt' => $NBO,
             ':c' => mi_pg_array_encode(['bunk'])]
        );
    }
    $bunkRoomFull = db_query(
        'SELECT * FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-bunk-room']
    )->fetch();
    $noRoom = mi_allocate_and_hold(
        $bunkRoomFull, null, $NB, $NBO, 'Sold Out Test', 'soldout@example.com'
    );
    check('atomic: returns FALSE when no villa can satisfy the pattern',
        $noRoom === false);
    check('atomic: a FALSE result writes no hold',
        (int) db_query("SELECT COUNT(*) FROM holds WHERE guest_email = 'soldout@example.com'")
            ->fetchColumn() === 0);
    check('atomic: a FALSE result writes no block',
        (int) db_query(
            "SELECT COUNT(*) FROM availability_blocks ab JOIN units u ON u.id = ab.unit_id
              WHERE u.room_id = :r AND ab.date_from = :df AND ab.block_type = 'hold'",
            [':r' => $villaRoom['id'], ':df' => $NB]
        )->fetchColumn() === 0);
    check('atomic: the caller\'s transaction survives the FALSE path',
        db()->inTransaction() === $txDepthBefore);

    // A non-composite room here is a programming error, not a runtime case.
    $studioRoom = db_query(
        "SELECT * FROM rooms WHERE slug = 'maya-ilai-studio'"
    )->fetch();
    $threw = false;
    try {
        mi_allocate_and_hold($studioRoom, null, $AI, $AO, 'Studio Test', 'studio@example.com');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check('atomic: a non-composite room throws InvalidArgumentException', $threw);
    check('atomic: the caller\'s transaction survives the throw',
        db()->inTransaction() === $txDepthBefore);

    // ── The savepoint fix ───────────────────────────────────────────────────
    // The hazard: in Postgres a failed statement aborts the WHOLE transaction,
    // so create_hold_with_block()'s access-code retry loop would turn one
    // collision into a hard failure once it runs inside a transaction. Prove
    // the hazard is real, then prove holds still work inside one.
    db()->exec('SAVEPOINT mi_hazard_demo');
    $poisoned = false;
    try { db()->exec('SELECT 1/0'); } catch (Throwable $e) { /* expected */ }
    try { db_query('SELECT 1'); } catch (Throwable $e) { $poisoned = true; }
    db()->exec('ROLLBACK TO SAVEPOINT mi_hazard_demo');
    check('savepoint: a failed statement really does abort the whole transaction',
        $poisoned);
    check('savepoint: rolling back to a savepoint makes the transaction usable again',
        (int) db_query('SELECT 1')->fetchColumn() === 1);

    $fifth = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 5',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $sixth = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 6',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $b2b1 = create_hold_with_block($fifth, null, '2099-12-01', '2099-12-03',
        'Back To Back One', 'b2b1@example.com', 'pending', 24, ['bunk']);
    $b2b2 = create_hold_with_block($sixth, null, '2099-12-01', '2099-12-03',
        'Back To Back Two', 'b2b2@example.com', 'pending', 24, ['bunk']);
    check('savepoint: two holds created back-to-back inside one transaction both succeed',
        $b2b1 > 0 && $b2b2 > 0 && $b2b1 !== $b2b2);
    check('savepoint: both back-to-back holds wrote their blocks',
        (int) db_query(
            'SELECT COUNT(*) FROM availability_blocks WHERE hold_id IN (:a, :b)',
            [':a' => $b2b1, ':b' => $b2b2]
        )->fetchColumn() === 2);

    // ── Inventory room: "which room's units carry this room's inventory?" ──
    // Both form-mode guards (api/submit-enquiry.php, includes/booking-widget.php)
    // ask "does this room have units?" before allowing availability mode. The six
    // unitless composite products own no units of their own, so the guards must
    // ask through room_inventory_room_id() or they silently downgrade all six to
    // enquiry mode — a form that renders, submits, and never creates a hold.
    $villaRoomId = (int)$villaRoom['id'];
    foreach (['maya-ilai-bunk-room', 'maya-ilai-double', 'maya-ilai-family-room',
              'maya-ilai-one-bed-suite', 'maya-ilai-family-suite',
              'maya-ilai-two-bed-suite'] as $slug) {
        $r = db_query('SELECT * FROM rooms WHERE slug = :s', [':s' => $slug])->fetch();
        check("inventory room: {$slug} borrows the villa room's units",
            $r && room_inventory_room_id($r) === $villaRoomId);
    }
    $villaRow = db_query('SELECT * FROM rooms WHERE slug = :s',
        [':s' => MAYA_ILAI_VILLA_ROOM_SLUG])->fetch();
    check('inventory room: the villa product IS its own inventory room',
        $villaRow && room_inventory_room_id($villaRow) === $villaRoomId);

    $studio = db_query('SELECT * FROM rooms WHERE slug = :s',
        [':s' => 'maya-ilai-studio'])->fetch();
    check('inventory room: the studio is an ordinary room — its own units',
        $studio && room_inventory_room_id($studio) === (int)$studio['id']);

    $zuriRoom = db_query(
        "SELECT * FROM rooms WHERE venue_id = (SELECT id FROM venues WHERE slug = 'zuri')
          ORDER BY id LIMIT 1"
    )->fetch();
    if ($zuriRoom) {
        check('inventory room: another property is completely unaffected',
            room_inventory_room_id($zuriRoom) === (int)$zuriRoom['id']);
    }

    // mi_is_composite_room() reads $room['slug'] and returns false when the key is
    // absent, so a caller that hands over a slug-less array must fall back to the
    // room's own id — never fatal, never silently borrow the villa's units.
    check('inventory room: a room array with no slug falls back to its own id',
        room_inventory_room_id(['id' => 4242]) === 4242);

    // The condition both guards actually evaluate. If this is false for any of the
    // eight Maya Ilai products, that product renders an enquiry form and can never
    // be booked.
    $products = db_query(
        'SELECT * FROM rooms WHERE venue_id = :v ORDER BY slug', [':v' => (int)$villaRow['venue_id']]
    )->fetchAll();
    check('inventory room: Maya Ilai lists all eight products', count($products) === 8);
    foreach ($products as $p) {
        check("guard condition: {$p['slug']} has bookable inventory",
            count(fetch_units_by_room(room_inventory_room_id($p))) > 0);
    }
    // ── Which PRODUCT a hold is for, and what the ledger charges ────────────
    // Every consumer used to derive the product from holds -> units -> rooms.
    // That is wrong for Maya Ilai: six of the eight products own no units and
    // allocate against the VILLA's units, so the join reports "Three-Bedroom
    // Villa" for all of them. The money consequence is the one that matters —
    // bookings_sync_hold() took r.price_amount through that join, so a $150
    // Private Bunk Room was written into the revenue ledger at the villa's
    // $1,170 a night. holds.room_id records the product directly.
    if (!bookings_supported()) {
        echo "SKIP  ledger: add_bookings_finance not applied on this DB\n";
    } else {
        $LI = '2099-06-10';
        $LO = '2099-06-13';                 // 3 nights
        $bunkFull = db_query('SELECT * FROM rooms WHERE slug = :s',
            [':s' => 'maya-ilai-bunk-room'])->fetch();
        $bunkPrice  = (float)$bunkFull['price_amount'];
        $villaPrice = (float)$villaRow['price_amount'];

        $ledgerHold = mi_allocate_and_hold(
            $bunkFull, null, $LI, $LO, 'Ledger Bunk', 'ledger-bunk@example.com', 'confirmed', null
        );
        check('ledger: a Private Bunk Room books', is_int($ledgerHold) && $ledgerHold > 0);

        check('hold room_id: mi_allocate_and_hold() records the PRODUCT, not the villa',
            (int) db_query('SELECT room_id FROM holds WHERE id = :h', [':h' => $ledgerHold])
                ->fetchColumn() === (int)$bunkFull['id']);

        bookings_sync_hold($ledgerHold);
        $lrow = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $ledgerHold])->fetch();
        check('ledger: sync wrote a row', (bool)$lrow);
        check('ledger: gross is the bunk room\'s 150 × 3 nights, not the villa\'s 1170 × 3',
            $lrow && (float)$lrow['gross_amount'] === $bunkPrice * 3);
        check('ledger: the row is attributed to the bunk room, not the villa',
            $lrow && (int)$lrow['room_id'] === (int)$bunkFull['id']
                  && (int)$lrow['room_id'] !== (int)$villaRow['id']);
        check('ledger: nights survive unchanged', $lrow && (int)$lrow['nights'] === 3);
        check('ledger: the villa price is genuinely different, so the assertion above bites',
            $villaPrice > 0 && $villaPrice !== $bunkPrice);
        check('ledger: the unit is still a villa unit — allocation is unchanged',
            $lrow && (int) db_query('SELECT room_id FROM units WHERE id = :u',
                [':u' => (int)$lrow['unit_id']])->fetchColumn() === (int)$villaRow['id']);

        // Re-syncing must not duplicate or restate.
        bookings_sync_hold($ledgerHold);
        check('ledger: re-sync stays idempotent',
            (int) db_query('SELECT COUNT(*) FROM bookings WHERE hold_id = :h',
                [':h' => $ledgerHold])->fetchColumn() === 1);

        // The whole-villa product must still price at the villa's rate.
        $VI = '2099-06-20';
        $VO = '2099-06-22';                 // 2 nights
        $villaHold = mi_allocate_and_hold(
            $villaRow, null, $VI, $VO, 'Ledger Villa', 'ledger-villa@example.com', 'confirmed', null
        );
        check('ledger: the villa product books', is_int($villaHold) && $villaHold > 0);
        bookings_sync_hold($villaHold);
        $vrow = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $villaHold])->fetch();
        check('ledger: the villa product still prices at the villa rate',
            $vrow && (float)$vrow['gross_amount'] === $villaPrice * 2
                  && (int)$vrow['room_id'] === (int)$villaRow['id']);

        // ── No regression at any other property ────────────────────────────
        // Every non-Maya-Ilai hold has room_id = exactly what the unit->room
        // join already returned, so the figure must be identical to the old one.
        $otherUnit = db_query(
            "SELECT u.id AS unit_id, r.id AS room_id, r.price_amount, r.price_currency, r.slug
               FROM units u JOIN rooms r ON r.id = u.room_id
              WHERE u.is_active = TRUE AND r.price_amount > 0
                AND r.venue_id <> :v
              ORDER BY u.id LIMIT 1",
            [':v' => (int)$villaRow['venue_id']]
        )->fetch();
        if (!$otherUnit) {
            echo "SKIP  ledger: no priced unit at another property\n";
        } else {
            $OI = '2099-06-10';
            $OO = '2099-06-14';             // 4 nights
            $oHold = create_hold_with_block((int)$otherUnit['unit_id'], null, $OI, $OO,
                'Ledger Other', 'ledger-other@example.com', 'confirmed', null);
            bookings_sync_hold($oHold);
            $orow = db_query('SELECT * FROM bookings WHERE hold_id = :h', [':h' => $oHold])->fetch();
            // The pre-change expectation, computed the old way: the unit's room.
            $oExpected = room_stay_quote((int)$otherUnit['room_id'],
                (float)$otherUnit['price_amount'], $OI, $OO);
            check("no regression: {$otherUnit['slug']} gross is unchanged",
                $orow && (float)$orow['gross_amount'] === (float)$oExpected['total']);
            check("no regression: {$otherUnit['slug']} is attributed to its own room",
                $orow && (int)$orow['room_id'] === (int)$otherUnit['room_id']);
            check('no regression: a caller that passes no room id leaves room_id NULL',
                db_query('SELECT room_id FROM holds WHERE id = :h', [':h' => $oHold])
                    ->fetchColumn() === null);
            check('legacy path: a NULL holds.room_id still resolves to the unit\'s room',
                (int) db_query(
                    'SELECT ' . hold_room_id_sql() . ' FROM holds h JOIN units u ON u.id = h.unit_id
                      WHERE h.id = :h', [':h' => $oHold]
                )->fetchColumn() === (int)$otherUnit['room_id']);
        }

        // create_hold_with_block() writes the room id it is handed.
        $seventh = (int) db_query(
            'SELECT id FROM units WHERE room_id = :r AND sort_order = 7',
            [':r' => $villaRoom['id']]
        )->fetchColumn();
        if ($seventh) {
            $explicit = create_hold_with_block($seventh, null, '2099-06-25', '2099-06-27',
                'Explicit Room', 'explicit@example.com', 'pending', 24, ['bunk'],
                (int)$bunkFull['id']);
            check('hold room_id: create_hold_with_block() writes the room id it is passed',
                (int) db_query('SELECT room_id FROM holds WHERE id = :h', [':h' => $explicit])
                    ->fetchColumn() === (int)$bunkFull['id']);
        }
    }

    // ── Naming the PRODUCT a guest actually booked, on the surfaces a human
    // reads (staff hold-notification email, guest booking-management portal) ──
    // Both queries used to join holds -> units -> rooms directly, which for
    // Maya Ilai always resolves to the villa (units.room_id). A guest who books
    // a Private Bunk Room must be named "Private Bunk Room" everywhere, not
    // "Three-Bedroom Villa". Independent of the bookings-ledger guard above —
    // this only needs holds.room_id (Phase 1), not the finance migration.
    $bunkForName = db_query("SELECT * FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    $NI = '2099-07-10';
    $NO = '2099-07-12';
    $nameHold = mi_allocate_and_hold(
        $bunkForName, null, $NI, $NO, 'Name Test', 'name-test@example.com', 'pending', 24
    );
    check('naming: a Private Bunk Room books', is_int($nameHold) && $nameHold > 0);

    // The exact query api/submit-enquiry.php runs to build the staff
    // hold-notification email's room_name.
    $staffNotify = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name
         FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
         WHERE h.id = :id",
        [':id' => $nameHold]
    )->fetch();
    check('naming: the staff hold-notification query names the product, not the villa',
        $staffNotify && $staffNotify['room_name'] === 'Private Bunk Room');
    check('naming: …and specifically NOT the villa name',
        $staffNotify && $staffNotify['room_name'] !== $villaRow['name']);

    // includes/booking.php's fetch_hold_for_guest() — the guest's own
    // booking-management portal (record.php / booking.php / the check-in app
    // all resolve through this one helper).
    $guestPortal = fetch_hold_for_guest($nameHold);
    check('naming: the guest portal query (fetch_hold_for_guest) names the product, not the villa',
        $guestPortal && $guestPortal['room_name'] === 'Private Bunk Room');
    check('naming: the guest portal room_slug matches the product too',
        $guestPortal && $guestPortal['room_slug'] === 'maya-ilai-bunk-room');

    // A legacy hold — holds.room_id NULL, the pre-Phase-1 shape every existing
    // row was backfilled from — must still resolve to the UNIT's own room.
    $legacyVillaUnit = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 3', [':r' => $villaRoom['id']]
    )->fetchColumn();
    $legacyHold = create_hold_with_block($legacyVillaUnit, null, '2099-07-15', '2099-07-17',
        'Legacy Guest', 'legacy@example.com', 'pending', 24);
    check('naming: a legacy call with no room id leaves holds.room_id NULL',
        db_query('SELECT room_id FROM holds WHERE id = :h', [':h' => $legacyHold])->fetchColumn() === null);
    $legacyNotify = db_query(
        "SELECT h.*, r.name AS room_name
         FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
         WHERE h.id = :id",
        [':id' => $legacyHold]
    )->fetch();
    check('naming: a legacy hold (room_id NULL) still resolves to the unit\'s own room',
        $legacyNotify && $legacyNotify['room_name'] === $villaRow['name']);

    // A non-Maya-Ilai hold (Zuri or Maya Kobe) resolves exactly as before —
    // units.room_id and the product ARE the same room everywhere else, so
    // hold_room_id_sql() must be a pure no-op there.
    $otherRoomForName = db_query(
        "SELECT r.* FROM rooms r JOIN units u ON u.room_id = r.id
          WHERE u.is_active = TRUE AND r.venue_id <> :v
          ORDER BY r.id LIMIT 1",
        [':v' => (int)$villaRow['venue_id']]
    )->fetch();
    if (!$otherRoomForName) {
        echo "SKIP  naming: no active unit at another property\n";
    } else {
        $otherUnitForName = (int) db_query(
            'SELECT id FROM units WHERE room_id = :r AND is_active = TRUE LIMIT 1',
            [':r' => (int)$otherRoomForName['id']]
        )->fetchColumn();
        $otherNameHold = create_hold_with_block($otherUnitForName, null, '2099-07-20', '2099-07-22',
            'Other Property Guest', 'other-property@example.com', 'pending', 24);
        $otherNotify = db_query(
            "SELECT h.*, r.name AS room_name
             FROM holds h JOIN units u ON u.id = h.unit_id JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
             WHERE h.id = :id",
            [':id' => $otherNameHold]
        )->fetch();
        check("naming: a non-Maya-Ilai hold ({$otherRoomForName['slug']}) resolves exactly as before",
            $otherNotify && $otherNotify['room_name'] === $otherRoomForName['name']);
        $otherGuestPortal = fetch_hold_for_guest($otherNameHold);
        check("naming: …and the guest portal query agrees",
            $otherGuestPortal && $otherGuestPortal['room_name'] === $otherRoomForName['name']);
    }

    // ── The staff path must not oversell a component booking ───────────────
    // admin/hold-new.php and admin/submission-view.php write a block with
    // components NULL — the WHOLE villa. Over a live component booking that
    // silently sells the same bedroom twice: no constraint is violated and no
    // warning is shown. The guard below is what both forms call before writing.
    $SHI = '2100-03-01';
    $SHO = '2100-03-05';
    $shVilla = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 1', [':r' => $villaRoom['id']]
    )->fetchColumn();
    $shFree = (int) db_query(
        'SELECT id FROM units WHERE room_id = :r AND sort_order = 8', [':r' => $villaRoom['id']]
    )->fetchColumn();

    check('staff guard: an empty villa lets a staff booking through',
        staff_hold_block_reason($shVilla, $SHI, $SHO) === null);

    // A guest books a Double Room in villa 1 — components {double_a}.
    create_hold_with_block($shVilla, null, $SHI, $SHO, 'Guest Double',
        'guest-double@example.com', 'pending', 24, ['double_a'], (int)$doubleRoom['id']);

    $shReason = staff_hold_block_reason($shVilla, $SHI, $SHO);
    check('staff guard: a staff booking over a live component booking is REFUSED',
        $shReason !== null);
    check('staff guard: the refusal names the villa',
        is_string($shReason) && str_contains($shReason, 'Villa 1'));
    check('staff guard: the refusal names both dates',
        is_string($shReason) && str_contains($shReason, $SHI) && str_contains($shReason, $SHO));
    check('staff guard: the refusal names the component that is already sold',
        is_string($shReason) && str_contains($shReason, 'Double A'));

    // Partial overlap by a single night is still an overlap.
    check('staff guard: a one-night overlap is still refused',
        staff_hold_block_reason($shVilla, '2100-02-25', '2100-03-02') !== null);

    // A whole-villa (NULL components) block blocks the staff path too.
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, :df, :dt, 'booked', NULL)",
        [':u' => $shFree, ':df' => '2100-04-01', ':dt' => '2100-04-03']
    );
    check('staff guard: a NULL-components block refuses the staff path as well',
        staff_hold_block_reason($shFree, '2100-04-01', '2100-04-03') !== null);

    // Nothing booked in these dates → the staff booking still works, end to end.
    check('staff guard: a villa with nothing booked still passes the check',
        staff_hold_block_reason($shFree, $SHI, $SHO) === null);
    $shHold = create_hold_with_block($shFree, null, $SHI, $SHO, 'Staff Booking',
        'staff-booking@example.com', 'pending', null, null,
        (int) db_query('SELECT room_id FROM units WHERE id = :u', [':u' => $shFree])->fetchColumn());
    check('staff guard: and the staff hold is actually created',
        is_int($shHold) && $shHold > 0);
    check('staff guard: it still blocks the WHOLE villa (components NULL)',
        db_query('SELECT components FROM availability_blocks WHERE hold_id = :h',
            [':h' => $shHold])->fetchColumn() === null);
    check('staff guard: which is why the very same villa is now refused a second staff hold',
        staff_hold_block_reason($shFree, $SHI, $SHO) !== null);

    // Dates clear of every block are unaffected.
    check('staff guard: a window with nothing in it is never refused',
        staff_hold_block_reason($shVilla, '2100-06-01', '2100-06-03') === null);

    // The check is scoped to Maya Ilai VILLA units. Everything else — including
    // Maya Ilai's own studios — keeps the deliberate "you control overlaps"
    // behaviour the staff forms have always had.
    $shStudioUnit = (int) db_query(
        "SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE r.slug = 'maya-ilai-studio' ORDER BY u.sort_order LIMIT 1"
    )->fetchColumn();
    if ($shStudioUnit) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type)
             VALUES (:u, :df, :dt, 'booked')",
            [':u' => $shStudioUnit, ':df' => $SHI, ':dt' => $SHO]
        );
        check('staff guard: a Maya Ilai STUDIO is not a villa unit — unaffected',
            staff_hold_block_reason($shStudioUnit, $SHI, $SHO) === null);
    }

    $shOther = db_query(
        "SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE u.is_active = TRUE AND r.venue_id <> :v ORDER BY u.id LIMIT 1",
        [':v' => (int)$villaRow['venue_id']]
    )->fetchColumn();
    if ($shOther) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type)
             VALUES (:u, :df, :dt, 'booked')",
            [':u' => (int)$shOther, ':df' => $SHI, ':dt' => $SHO]
        );
        check('staff guard: another property double-booked on purpose is still allowed',
            staff_hold_block_reason((int)$shOther, $SHI, $SHO) === null);
    }

    check('staff guard: a unit id that does not exist is not a villa unit',
        staff_hold_block_reason(0, $SHI, $SHO) === null);

    check('hold_room_id_sql: COALESCEs once the column exists',
        holds_room_id_supported()
            ? hold_room_id_sql() === 'COALESCE(h.room_id, u.room_id)'
            : hold_room_id_sql() === 'u.room_id');
    check('hold_room_id_sql: honours custom aliases',
        holds_room_id_supported()
            ? hold_room_id_sql('hh', 'uu') === 'COALESCE(hh.room_id, uu.room_id)'
            : hold_room_id_sql('hh', 'uu') === 'uu.room_id');

    // ── Availability is a property of a STAY, not of a night ────────────────
    // The reported bug. mi_villa_states() unions each villa's taken components
    // across the WHOLE span on purpose — a guest keeps the same bedroom for the
    // whole booking, they do not move rooms mid-stay — so find_available_unit()
    // needs ONE villa to satisfy the pattern on every night. mi_blocked_dates()
    // resolves night by night, which is the right answer to a different
    // question, and the two disagree exactly here:
    //
    //   villas 1-5 have the bunk sold on night A, villas 2-6 on night B.
    //   Each night has a free bunk SOMEWHERE, so both nights are green — but no
    //   single villa spans both, so the two-night stay cannot be booked.
    //
    // room_max_stay_nights() is what lets the calendar express that.
    $A  = '2101-03-01';   // night A
    $B  = '2101-03-02';   // night B (and the check-out of a one-night stay from A)
    $A2 = '2101-03-03';   // the check-out of the two-night stay A..A2

    $villaRanks = db_query(
        'SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order, id',
        [':r' => $villaRoom['id']]
    )->fetchAll(PDO::FETCH_COLUMN);
    $rankUnit = [];
    foreach ($villaRanks as $i => $uid) $rankUnit[$i + 1] = (int)$uid;

    $sellBunk = static function (int $unitId, string $df, string $dt): void {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, 'booked', :c)",
            [':u' => $unitId, ':df' => $df, ':dt' => $dt, ':c' => mi_pg_array_encode(['bunk'])]
        );
    };
    // With the default N=2 the last two villas by rank are ring-fenced, so a
    // component product only ever sees ranks 1-6. Sell the bunk in 1-5 on night
    // A and in 2-6 on night B: rank 6 is the only bunk free on A, rank 1 the
    // only one free on B.
    foreach ([1, 2, 3, 4, 5] as $r) $sellBunk($rankUnit[$r], $A, $B);
    foreach ([2, 3, 4, 5, 6] as $r) $sellBunk($rankUnit[$r], $B, $A2);

    $bunkRoomRow = db_query("SELECT id, slug FROM rooms WHERE slug = 'maya-ilai-bunk-room'")->fetch();
    $bunkRoomId  = (int)$bunkRoomRow['id'];

    check('stay-vs-night: both nights are individually free — the per-night calendar is unchanged',
        mi_blocked_dates($bunkRoomRow, $A, $A2) === []);
    check('stay-vs-night: a one-night stay from A IS bookable',
        find_available_unit($bunkRoomId, $A, $B) !== false);
    check('stay-vs-night: but no single villa spans both nights — the 2-night stay is refused',
        find_available_unit($bunkRoomId, $A, $A2) === false);
    check('stay-vs-night: room_max_stay_nights() says 1, which is what the calendar could not say before',
        room_max_stay_nights($bunkRoomId, $A, 10) === 1);

    // A clean window has nothing to stop at, so the cap is the answer.
    $CLEAN = '2101-07-01';
    check('max stay: a clean window returns the cap',
        room_max_stay_nights($bunkRoomId, $CLEAN, 7) === 7);

    // A check-in with no bunk anywhere is not a stay at all.
    $Z  = '2101-09-01';
    $Z1 = '2101-09-02';
    foreach ($rankUnit as $uid) $sellBunk($uid, $Z, $Z1);
    check('max stay: a fully-blocked check-in returns 0',
        room_max_stay_nights($bunkRoomId, $Z, 7) === 0);
    check('max stay: and that night is blocked on the per-night calendar too',
        mi_blocked_dates($bunkRoomRow, $Z, $Z1) === [$Z]);

    // ── The same shape at an ORDINARY room ──────────────────────────────────
    // Nothing about this is Maya Ilai-specific: a room with several units has
    // it too (unit A free Monday, unit B free Tuesday, neither free both). It
    // has simply never been visible, because the calendar only ever asked the
    // per-night question.
    $studioRoom = db_query("SELECT id FROM rooms WHERE slug = 'maya-ilai-studio'")->fetch();
    if ($studioRoom) {
        $studioId    = (int)$studioRoom['id'];
        $studioUnits = db_query(
            'SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order, id',
            [':r' => $studioId]
        )->fetchAll(PDO::FETCH_COLUMN);
        $blockUnit = static function (int $uid, string $df, string $dt): void {
            db_query(
                "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type)
                 VALUES (:u, :df, :dt, 'booked')",
                [':u' => $uid, ':df' => $df, ':dt' => $dt]
            );
        };

        // A hard wall: every unit is gone from the 4th, so a stay starting on
        // the 1st runs exactly 3 nights (checking out on the 4th).
        foreach ($studioUnits as $uid) $blockUnit((int)$uid, '2101-05-04', '2101-05-20');
        check('max stay: an ordinary multi-unit room stops exactly at the wall',
            room_max_stay_nights($studioId, '2101-05-01', 14) === 3);
        check('max stay: one night short of the wall is still bookable',
            find_available_unit($studioId, '2101-05-01', '2101-05-04') !== false);
        check('max stay: one night past it is not',
            find_available_unit($studioId, '2101-05-01', '2101-05-05') === false);

        // And the interleaved version, with no wall anywhere: a different unit
        // is the free one on each night, so neither night is fully blocked and
        // yet no two-night stay exists.
        $G  = '2101-06-01';
        $G1 = '2101-06-02';
        $G2 = '2101-06-03';
        foreach ($studioUnits as $i => $uid) if ($i !== 0) $blockUnit((int)$uid, $G,  $G1);
        foreach ($studioUnits as $i => $uid) if ($i !== 1) $blockUnit((int)$uid, $G1, $G2);
        check('ordinary room: neither night is fully blocked',
            get_room_blocked_dates($studioId, $G, $G2) === []);
        check('ordinary room: each night is bookable on its own',
            find_available_unit($studioId, $G, $G1) !== false
            && find_available_unit($studioId, $G1, $G2) !== false);
        check('ordinary room: but the two-night stay is not',
            find_available_unit($studioId, $G, $G2) === false);
        check('ordinary room: room_max_stay_nights() reports 1 — the generic fix covers every property',
            room_max_stay_nights($studioId, $G, 7) === 1);
    }

    // ── Invalid input is "not a stay", never a runaway ──────────────────────
    check('max stay: an empty check-in is not a stay',
        room_max_stay_nights($bunkRoomId, '', 7) === 0);
    check('max stay: garbage is not a stay',
        room_max_stay_nights($bunkRoomId, 'tomorrow', 7) === 0);
    check('max stay: a date that is not on the calendar is not a stay',
        room_max_stay_nights($bunkRoomId, '2101-02-30', 7) === 0);
    check('max stay: a 13th month is not a stay',
        room_max_stay_nights($bunkRoomId, '2101-13-01', 7) === 0);
    check('max stay: a timestamp is not a date window',
        room_max_stay_nights($bunkRoomId, '2101-07-01T00:00:00', 7) === 0);
    check('max stay: a non-canonical but real date is repaired, exactly as the read-window validator does elsewhere',
        room_max_stay_nights($bunkRoomId, '2101-7-1', 7) === 7);
    check('max stay: a non-positive cap can only mean 0 nights',
        room_max_stay_nights($bunkRoomId, $CLEAN, 0) === 0
        && room_max_stay_nights($bunkRoomId, $CLEAN, -5) === 0);
    check('max stay: an absurd cap is clamped rather than probed to death',
        room_max_stay_nights($bunkRoomId, '2102-01-01', 1000000) === 365);
    check('max stay: a room that does not exist is not a stay',
        room_max_stay_nights(0, $CLEAN, 7) === 0);

    // ── Hoisting the lapsed-hold sweep out of the probes ────────────────────
    // room_max_stay_nights() used to binary-search with the PUBLIC
    // find_available_unit(), which opens with expire_stale_holds() — an
    // UPDATE … RETURNING that also deletes blocks and e-mails the affected
    // guests. ~5 probes meant ~5 write transactions per check-in click on a
    // public, unauthenticated GET. The sweep now happens once, in the caller.
    //
    // The closure below IS the pre-change implementation: the same binary
    // search over the same bounds, probing with the sweeping public function.
    // The change is a performance change and nothing else, so the two must
    // agree everywhere — including on the windows above that are 0, the cap,
    // and the awkward number in between.
    $refMaxStay = static function (int $roomId, string $checkIn, int $cap = 30): int {
        $ci = rates_window_ymd($checkIn);
        if ($ci === null) return 0;
        $cap = max(0, min($cap, 365));
        if ($cap === 0) return 0;
        $from = new DateTimeImmutable($ci);
        $lo = 0; $hi = $cap;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $co  = $from->modify("+{$mid} day")->format('Y-m-d');
            if (find_available_unit($roomId, $ci, $co) !== false) $lo = $mid;
            else                                                  $hi = $mid - 1;
        }
        return $lo;
    };
    $maxStayCases = [
        [$bunkRoomId, $A,      10], [$bunkRoomId, $CLEAN,  7], [$bunkRoomId, $Z, 7],
        [$bunkRoomId, '2101-7-1', 7], [$bunkRoomId, '2102-01-01', 40],
        [$bunkRoomId, $CLEAN,  0], [$bunkRoomId, 'tomorrow', 7], [0, $CLEAN, 7],
    ];
    foreach (array_keys(mi_product_map()) as $pSlug) {
        $pr = db_query('SELECT id FROM rooms WHERE slug = :s', [':s' => $pSlug])->fetchColumn();
        if ($pr) { $maxStayCases[] = [(int)$pr, $A, 10]; $maxStayCases[] = [(int)$pr, $CLEAN, 12]; }
    }
    if (isset($studioId)) { $maxStayCases[] = [$studioId, '2101-05-01', 14]; }
    $sameAsRefStay = true; $stayCoverage = [];
    foreach ($maxStayCases as [$rid, $cin, $cap]) {
        $now = room_max_stay_nights($rid, $cin, $cap);
        $was = $refMaxStay($rid, $cin, $cap);
        $stayCoverage[] = $now;
        if ($now !== $was) { $sameAsRefStay = false; break; }
    }
    check('sweep hoist: room_max_stay_nights() answers exactly what the sweeping-probe version answered',
        $sameAsRefStay);
    check('sweep hoist: ...over cases that are not all the same number (0, the cap and a real wall)',
        count(array_unique($stayCoverage)) >= 3
        && in_array(0, $stayCoverage, true) && in_array(1, $stayCoverage, true));

    // A stale hold is the observable trace of the sweep. Mailing is skipped for
    // a hold with no guest e-mail (expire_stale_holds() checks), so this scratch
    // row can never send anything — the rule for touching that function at all.
    $sweepUnit = (int)db_query(
        'SELECT id FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order LIMIT 1',
        [':r' => $villaRoom['id']]
    )->fetchColumn();
    $staleHold = static function () use ($sweepUnit): int {
        return (int)db_query(
            "INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email,
                                access_code, status, expires_at)
             VALUES (:u, '2104-01-01', '2104-01-02', 'Sweep Probe', '',
                     :code, 'pending', NOW() - INTERVAL '1 hour')
             RETURNING id",
            [':u' => $sweepUnit, ':code' => 'SWEEP' . random_int(100000, 999999)]
        )->fetchColumn();
    };
    $statusOf = static fn(int $h): string => (string)db_query(
        'SELECT status FROM holds WHERE id = :h', [':h' => $h])->fetchColumn();

    $h1 = $staleHold();
    find_available_unit_internal($bunkRoomId, $CLEAN, '2101-07-02', false);
    check('sweep hoist: a probe with $sweep = false does NOT expire lapsed holds',
        $statusOf($h1) === 'pending');
    find_available_unit($bunkRoomId, $CLEAN, '2101-07-02');
    check('sweep hoist: the public find_available_unit() still does',
        $statusOf($h1) === 'expired');

    $h2 = $staleHold();
    room_max_stay_nights($bunkRoomId, $CLEAN, 7);
    check('sweep hoist: room_max_stay_nights() still sweeps — once, at the top, not once per probe',
        $statusOf($h2) === 'expired');

    // ── Batching: one query per window, not two per night ───────────────────
    // The closure below IS the pre-batching implementation of mi_blocked_dates()
    // — one mi_villa_states() call per night. The batched version must answer
    // identically for every product over a window full of partial blocks, whole-
    // unit blocks and clean nights. (Same rationale as rates_nightly_map():
    // resolve the whole window once and slice it in PHP.)
    $refBlockedDates = static function (array $room, string $from, string $to) use ($villaRoom): array {
        $pattern = mi_product_map()[$room['slug']] ?? null;
        if ($pattern === null) return [];
        $reserved = max(0, (int) setting('maya_ilai_reserved_villas', '2'));
        $isVilla  = $room['slug'] === MAYA_ILAI_VILLA_ROOM_SLUG;
        $out = [];
        $d   = new DateTime($from);
        $end = new DateTime($to);
        while ($d < $end) {
            $night   = $d->format('Y-m-d');
            $next    = (clone $d)->modify('+1 day')->format('Y-m-d');
            $ordered = mi_order_villas(
                array_values(mi_villa_states((int)$villaRoom['id'], $night, $next)),
                $isVilla, $reserved
            );
            $fits = false;
            foreach ($ordered as $villa) {
                if (mi_resolve($pattern, $villa['taken']) !== null) { $fits = true; break; }
            }
            if (!$fits) $out[] = $night;
            $d->modify('+1 day');
        }
        return $out;
    };

    // Give the window a whole-villa block and a couple of partial ones on top of
    // the fixtures above, so it is not just clean nights being compared.
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, '2101-04-10', '2101-04-14', 'booked', NULL)",
        [':u' => $rankUnit[3]]
    );
    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
         VALUES (:u, '2101-04-12', '2101-04-20', 'booked', :c)",
        [':u' => $rankUnit[4], ':c' => mi_pg_array_encode(['double_a', 'living'])]
    );
    foreach ([1, 2, 5, 6, 7, 8] as $r) {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, '2101-04-11', '2101-04-13', 'booked', :c)",
            [':u' => $rankUnit[$r], ':c' => mi_pg_array_encode(['double_a', 'double_b', 'living'])]
        );
    }

    $BW_FROM = '2101-02-25';
    $BW_TO   = '2101-09-10';
    $sameAsRef = true;
    $refCoverage = 0;
    foreach (array_keys(mi_product_map()) as $productSlug) {
        $pr = db_query('SELECT id, slug FROM rooms WHERE slug = :s', [':s' => $productSlug])->fetch();
        if (!$pr) continue;
        $ref = $refBlockedDates($pr, $BW_FROM, $BW_TO);
        $refCoverage += count($ref);
        if (mi_blocked_dates($pr, $BW_FROM, $BW_TO) !== $ref) { $sameAsRef = false; break; }
    }
    check('batching: mi_blocked_dates() is byte-identical to the night-by-night implementation, for all seven products',
        $sameAsRef);
    check('batching: ...over a window that actually contains blocked nights (an all-clear window would prove nothing)',
        $refCoverage > 0);

    // ── The Gantt is the THIRD staff-entered booking surface (C1) ───────────
    // staff-hold-guard.php guards admin/hold-new.php and admin/submission-view.php
    // and says so. admin/gantt.php writes availability_blocks directly — "Add
    // block" and drag-to-move — and wrote them with no check at all, which is the
    // surface reception actually reaches for. A Gantt block has no component
    // picker, so it is components NULL = the WHOLE villa.
    $villaRoomId = (int)$villaRoom['id'];
    $dblRoom = db_query('SELECT * FROM rooms WHERE slug = :s', [':s' => 'maya-ilai-double'])->fetch();

    // 1. A guest books a Double Room. One bedroom, through the real allocator.
    $GCI = '2098-04-01';
    $GCO = '2098-04-05';
    $gHold = mi_allocate_and_hold($dblRoom, null, $GCI, $GCO,
        'Gantt Guest', 'gantt-guest@example.com', 'confirmed', null);
    $gUnit = (int) db_query('SELECT unit_id FROM holds WHERE id = :h', [':h' => $gHold])->fetchColumn();
    $gBlock = db_query(
        'SELECT id, components::text AS components FROM availability_blocks WHERE hold_id = :h',
        [':h' => $gHold]
    )->fetch();
    check('gantt bypass: the guest bought ONE bedroom of the villa, not the villa',
        mi_pg_array_decode($gBlock['components'] ?? null) === ['double_a']);

    // 2. The guard refuses that unit/range — this was always true.
    $gReason = staff_hold_block_reason($gUnit, $GCI, $GCO);
    check('gantt bypass: the guard refuses that villa for those dates, naming the sold bedroom',
        is_string($gReason) && str_contains($gReason, 'Double A') && str_contains($gReason, $GCI));

    // 3. …and the Gantt now asks it. create_block's INSERT is unreachable
    //    without the guard having answered first.
    $ganttSrc = (string) file_get_contents(__DIR__ . '/../admin/gantt.php');
    $handler = static function (string $src, string $from, string $to): string {
        $a = strpos($src, $from);
        if ($a === false) return '';
        $b = strpos($src, $to, $a);
        return $b === false ? '' : substr($src, $a, $b - $a);
    };
    $createSrc = $handler($ganttSrc, "\$action === 'create_block'", "\$action === 'convert_block'");
    check('gantt bypass: create_block consults staff_hold_block_reason() BEFORE it inserts',
        $createSrc !== ''
        && ($gp = strpos($createSrc, 'staff_hold_block_reason')) !== false
        && ($gi = strpos($createSrc, 'INSERT INTO availability_blocks')) !== false
        && $gp < $gi);
    $updateSrc = $handler($ganttSrc, "\$action === 'update_block'", "\$action === 'add_ical_feed'");
    check('gantt bypass: update_block no longer writes its own unguarded UPDATE',
        $updateSrc !== ''
        && strpos($updateSrc, 'gantt_block_move') !== false
        && strpos($updateSrc, 'UPDATE availability_blocks') === false);

    // ── Moving a block: the guard must not count the mover against itself ───
    $villaUnits = db_query(
        'SELECT id, sort_order FROM units WHERE room_id = :r AND is_active = TRUE ORDER BY sort_order',
        [':r' => $villaRoomId]
    )->fetchAll();
    $mvUnit = (int)$villaUnits[2]['id'];      // villa 3 — untouched by the tests above
    $mkBlock = static function (int $uid, string $df, string $dt, ?array $comp = null, string $type = 'blocked'): int {
        db_query(
            "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, components)
             VALUES (:u, :df, :dt, :t, :c)",
            [':u' => $uid, ':df' => $df, ':dt' => $dt, ':t' => $type,
             ':c' => $comp === null ? null : mi_pg_array_encode($comp)]
        );
        return (int) db()->lastInsertId();
    };
    $blockRow = static fn(int $id): array => db_query(
        'SELECT unit_id, date_from::text AS date_from, date_to::text AS date_to
           FROM availability_blocks WHERE id = :id', [':id' => $id]
    )->fetch() ?: [];

    $mvId = $mkBlock($mvUnit, '2098-05-10', '2098-05-15');

    // A nudge inside its own span: the only thing overlapping is the block being
    // dragged, so this must be allowed. Asked naively the guard refuses it,
    // because a NULL-components block reads as the whole villa.
    check('gantt move: asked naively, the guard refuses the block its own dates',
        staff_hold_block_reason($mvUnit, '2098-05-12', '2098-05-17') !== null);
    $mv1 = gantt_block_move($mvId, $mvUnit, '2098-05-12', '2098-05-17');
    check('gantt move: a block nudged within its own span is NOT refused by itself',
        $mv1['ok'] === true);
    check('gantt move: …and it actually moved',
        $blockRow($mvId)['date_from'] === '2098-05-12' && $blockRow($mvId)['date_to'] === '2098-05-17');

    // Somebody else's bedroom, on the same villa, overlapping the new position.
    // The move overlaps its own current span too, so this exercises the
    // park-and-ask-again path all the way to a refusal.
    $mkBlock($mvUnit, '2098-05-20', '2098-05-25', ['bunk'], 'booked');
    $mv2 = gantt_block_move($mvId, $mvUnit, '2098-05-16', '2098-05-22');
    check('gantt move: a drag onto a villa with a sold bunk is refused',
        $mv2['ok'] === false && str_contains($mv2['error'], 'Bunk'));
    check('gantt move: a refused move leaves the block exactly where it was — the parking is undone',
        $blockRow($mvId)['date_from'] === '2098-05-12'
        && $blockRow($mvId)['date_to'] === '2098-05-17'
        && (int)$blockRow($mvId)['unit_id'] === $mvUnit);

    // A drag onto a DIFFERENT villa that is partly sold: no self-overlap, so the
    // first (cheap) answer is already conclusive.
    $otherVilla = (int)$villaUnits[3]['id'];
    $mkBlock($otherVilla, '2098-08-01', '2098-08-06', ['double_a', 'living'], 'booked');
    $mv3 = gantt_block_move($mvId, $otherVilla, '2098-08-02', '2098-08-04');
    check('gantt move: a drag onto another villa\'s sold bedrooms is refused',
        $mv3['ok'] === false && str_contains($mv3['error'], 'Double A'));
    check('gantt move: that refusal changed nothing either',
        (int)$blockRow($mvId)['unit_id'] === $mvUnit
        && $blockRow($mvId)['date_from'] === '2098-05-12');

    // A free villa still takes the block.
    $mv4 = gantt_block_move($mvId, $otherVilla, '2098-09-02', '2098-09-04');
    check('gantt move: a clean destination still accepts the move',
        $mv4['ok'] === true && (int)$blockRow($mvId)['unit_id'] === $otherVilla);

    // Scope and block_type are respected BEFORE anything is parked: a row this
    // move would not touch must not be temporarily relocated.
    $scoped = gantt_block_move($mvId, $mvUnit, '2098-05-12', '2098-05-17', 'SELECT id FROM units WHERE id = -1');
    check('gantt move: an out-of-scope block is the same silent no-op as before',
        $scoped['ok'] === true && (int)$blockRow($mvId)['unit_id'] === $otherVilla);
    // A hold-type block is managed from holds.php and this action has always
    // refused to touch it. It must not be parked either — parking a row the
    // UPDATE will not move would leave a guest's booking on the parking dates.
    $holdBlockId = $mkBlock($mvUnit, '2098-12-01', '2098-12-05', null, 'hold');
    $held = gantt_block_move($holdBlockId, $otherVilla, '2098-08-02', '2098-08-04');
    check('gantt move: a hold-type block is never moved, and never parked',
        $held['ok'] === true
        && (int)$blockRow($holdBlockId)['unit_id'] === $mvUnit
        && $blockRow($holdBlockId)['date_from'] === '2098-12-01'
        && $blockRow($holdBlockId)['date_to']   === '2098-12-05');

    // ── Every other property is untouched ──────────────────────────────────
    // staff_hold_block_reason() returns null for them, so the Gantt must still
    // allow the overlaps staff deliberately create.
    $otherUnitRow = db_query(
        "SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE u.is_active = TRUE AND r.venue_id <> :v ORDER BY u.id LIMIT 1",
        [':v' => (int)$villaRow['venue_id']]
    )->fetchColumn();
    if (!$otherUnitRow) {
        echo "SKIP  gantt move: no active unit at another property\n";
    } else {
        $oUnit = (int)$otherUnitRow;
        $mkBlock($oUnit, '2098-07-01', '2098-07-05', null, 'booked');
        check('other property: the guard stays silent, whatever is already booked',
            staff_hold_block_reason($oUnit, '2098-07-01', '2098-07-05') === null);
        $oMoveId = $mkBlock($oUnit, '2098-07-20', '2098-07-22');
        $oMove   = gantt_block_move($oMoveId, $oUnit, '2098-07-01', '2098-07-05');
        check('other property: a deliberate double-book by drag still succeeds',
            $oMove['ok'] === true
            && $blockRow($oMoveId)['date_from'] === '2098-07-01'
            && $blockRow($oMoveId)['date_to']   === '2098-07-05');
    }

} finally {
    db()->rollBack();
}

// ── Pre-migration deploy: every OTHER property must still take bookings ──────
//
// Push-to-master auto-deploys to ECS; migrations are applied separately through
// /admin/migrate.php (see CLAUDE.md). So the default order of operations puts
// this branch's code in front of a database that has neither
// availability_blocks.components nor holds.room_id. Writing either column
// unconditionally is not a Maya Ilai bug — it is a total booking outage at every
// property, on the web form, admin/hold-new.php and convert-to-hold alike, until
// somebody notices and runs the migration.
//
// Runs OUTSIDE the transaction above (see the probe at the top of this file for
// why it is a subprocess at all).
$probeCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --premigration-probe 2>&1';
$probeRaw = (string) shell_exec($probeCmd);
$probeLine = '';
foreach (array_reverse(array_filter(array_map('trim', explode("\n", $probeRaw)))) as $ln) {
    if ($ln !== '' && $ln[0] === '{') { $probeLine = $ln; break; }
}
$probe = $probeLine === '' ? null : json_decode($probeLine, true);

check('pre-migration: the probe subprocess ran and reported back',
    is_array($probe));
check('pre-migration: an ordinary room still books with components + holds.room_id dropped'
        . (is_array($probe) && !empty($probe['error']) ? ' — got ' . $probe['error'] : ''),
    is_array($probe) && ($probe['ok'] ?? false) === true);
check('pre-migration: ...and the availability block is actually written',
    is_array($probe) && is_array($probe['block'] ?? null)
    && ($probe['block']['df'] ?? '') === '2103-03-01'
    && ($probe['block']['dt'] ?? '') === '2103-03-04'
    && ($probe['block']['block_type'] ?? '') === 'hold');
// maya-ilai-villa predates this branch, so the composite allocators are reachable
// on an unmigrated database too — they must degrade, not raise.
check('pre-migration: the villa\'s own allocator, calendar and staff guard degrade instead of raising',
    is_array($probe) && ($probe['villa_ok'] ?? null) === true);

// ── A past check-in must not drive the availability search ──────────────────
// api/check-availability.php's check-in-only branch is a public, unauthenticated
// GET with no Turnstile and no rate limit, and every call runs a binary search
// over the availability tables. A date that is already gone cannot be booked, so
// it is answered as 0 nights without probing at all. Run for real, in a
// subprocess (the endpoint ends in exit()) — see the --api-probe block at the
// top of this file for why that subprocess can never e-mail anyone.
$apiProbe = static function (string $slug, string $checkIn): ?array {
    $raw = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api-probe '
        . escapeshellarg($slug) . ' ' . escapeshellarg($checkIn) . ' 2>&1'
    );
    foreach (array_reverse(array_filter(array_map('trim', explode("\n", $raw)))) as $ln) {
        if ($ln !== '' && $ln[0] === '{') return json_decode($ln, true);
    }
    return null;
};
$apiPast   = $apiProbe('zuri-jua', date('Y-m-d', strtotime('-30 days')));
$apiFuture = $apiProbe('zuri-jua', '2103-06-01');
check('past check-in: the API branch answers 0 nights',
    is_array($apiPast) && ($apiPast['max_nights'] ?? null) === 0);
check('past check-in: ...and still echoes the date and cap, so the widget is not left guessing',
    is_array($apiPast) && ($apiPast['check_in'] ?? '') !== '' && ($apiPast['cap'] ?? 0) > 0);
check('past check-in: a future check-in is unaffected — this is a guard, not a blanket 0',
    is_array($apiFuture) && ($apiFuture['max_nights'] ?? 0) > 0);

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
