<?php
declare(strict_types=1);
// Maya Ilai composite inventory — component resolution, ring-fencing, allocation.
// Run: php tests/maya_ilai_inventory.php
// Any DB assertions run inside ONE transaction that is ROLLED BACK at the end.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-inventory.php';

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
} finally {
    db()->rollBack();
}

echo "\n" . ($failures ? "{$failures} FAILED\n" : "All passed\n");
exit($failures ? 1 : 0);
