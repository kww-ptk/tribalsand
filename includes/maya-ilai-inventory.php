<?php
declare(strict_types=1);
/**
 * Maya Ilai composite inventory — pure logic, no I/O.
 *
 * Maya Ilai sells eight products that slice the same eight villas. Each villa has
 * four components; a product consumes a subset of ONE villa's components. Studios
 * are ordinary independent units and never appear here.
 *
 * Everything in this file is a pure function so it can be tested without a
 * database. The DB-touching allocator lives in includes/db.php.
 *
 * See docs/superpowers/specs/2026-09-13-maya-ilai-composite-inventory-design.md
 */

/** The room that owns the eight villa units. Component products borrow them. */
const MAYA_ILAI_VILLA_ROOM_SLUG = 'maya-ilai-villa';

/** The two interchangeable double bedrooms in a villa, lower letter allocated first. */
const MAYA_ILAI_DOUBLES = ['double_a', 'double_b'];

/** Every component of a villa, in canonical order. A NULL block takes all of them. */
const MAYA_ILAI_ALL_COMPONENTS = ['double_a', 'double_b', 'bunk', 'living'];

/** Encode a component list as a Postgres array literal. Names are [a-z_]+ only. */
function mi_pg_array_encode(array $items): string {
    return '{' . implode(',', $items) . '}';
}

/**
 * Decode a Postgres array literal into a component list.
 *
 * NOTE: a NULL column means "the whole unit is taken", which is NOT the same as
 * an empty set. This returns [] for both, so callers MUST test the raw value for
 * null before calling this. To get the NULL-means-whole-unit rule applied
 * automatically, call mi_block_taken_components() instead.
 */
function mi_pg_array_decode(?string $raw): array {
    if ($raw === null) return [];
    $raw = trim($raw);
    if ($raw === '' || $raw === '{}') return [];
    $inner = trim($raw, '{}');
    if ($inner === '') return [];
    $parts = array_map(static fn(string $s): string => trim($s, " \t\"'"), explode(',', $inner));
    return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
}

/**
 * The components a stored block consumes.
 *
 * A NULL `components` column means the block takes the WHOLE unit — that is what
 * every block at every other property means, and what a staff-entered or
 * OTA-imported Maya Ilai block means too. Resolving that to an empty set would
 * read as "nothing is taken" and oversell the villa, so the rule lives here
 * rather than in each caller.
 */
function mi_block_taken_components(?string $rawComponents): array {
    if ($rawComponents === null) return MAYA_ILAI_ALL_COMPONENTS;
    return mi_pg_array_decode($rawComponents);
}

/**
 * Product slug => the components it consumes from ONE villa.
 *
 * 'double' is a placeholder for "either free double bedroom" and is resolved to a
 * concrete double_a/double_b by mi_resolve(). The studio is deliberately absent:
 * it is an ordinary independent unit and uses the existing availability path.
 *
 * Prices (rooms.price_amount) for reference — High season, USD:
 *   bunk-room 150 · double 350 · family-room 500 · one-bed-suite 750
 *   family-suite 900 · two-bed-suite 1100 · villa 1170
 */
function mi_product_map(): array {
    return [
        'maya-ilai-bunk-room'     => ['bunk'],
        'maya-ilai-double'        => ['double'],
        'maya-ilai-family-room'   => ['double', 'bunk'],
        'maya-ilai-one-bed-suite' => ['double', 'living'],
        'maya-ilai-family-suite'  => ['double', 'bunk', 'living'],
        'maya-ilai-two-bed-suite' => ['double', 'double', 'living'],
        'maya-ilai-villa'         => ['double', 'double', 'bunk', 'living'],
    ];
}

/** True when this room is one of the seven products that slice a villa. */
function mi_is_composite_room(array $room): bool {
    return isset(mi_product_map()[$room['slug'] ?? '']);
}

/**
 * Resolve a product's pattern against the components already taken in one villa.
 *
 * Returns the concrete component list this booking would occupy, or NULL when the
 * villa cannot accommodate it. Doubles are allocated lower letter first so
 * allocation is deterministic and reproducible when staff are debugging.
 *
 * Fails closed, deliberately: an empty pattern returns NULL rather than `[]`
 * ("fits, consumes nothing" — which would report a fully-occupied villa as
 * available), and the pattern vocabulary is closed to 'double' | 'bunk' |
 * 'living' — anything else (an unrecognised name, or a literal 'double_a'
 * instead of the 'double' placeholder) also returns NULL rather than being
 * guessed at. "I don't know" must never resolve to "it's available".
 */
function mi_resolve(array $pattern, array $taken): ?array {
    if (!$pattern) return null;
    $freeDoubles = array_values(array_diff(MAYA_ILAI_DOUBLES, $taken));
    $out = [];
    foreach ($pattern as $need) {
        if ($need === 'double') {
            if (!$freeDoubles) return null;
            $out[] = array_shift($freeDoubles);
            continue;
        }
        if ($need !== 'bunk' && $need !== 'living') return null; // closed vocabulary — fail closed on anything else
        if (in_array($need, $taken, true)) return null;
        if (in_array($need, $out, true))   return null; // a pattern asking twice for one room
        $out[] = $need;
    }
    return $out;
}

/**
 * Is villa RANK (1-based position within the villa list, ordered by
 * sort_order — see mi_order_villas()) one of the reserved ones?
 *
 * Reserved villas are the LAST $reserved by rank, so changing N never
 * reshuffles which villas were already reserved. Precondition: $rank must be
 * computed from POSITION after sorting by sort_order, never passed a raw
 * sort_order value directly — sort_order is NOT NULL DEFAULT 0 and
 * admin-editable, so it can be sparse or 0-based, and using it directly would
 * silently over- or under-reserve.
 */
function mi_villa_is_reserved(int $rank, int $totalVillas, int $reserved): bool {
    if ($reserved <= 0) return false;
    return $rank > ($totalVillas - $reserved);
}

/**
 * Order candidate villas for allocation.
 *
 * $villas: [['unit_id'=>int, 'sort_order'=>int, 'taken'=>string[]], …] — MUST be
 * the COMPLETE set of villas for the property. The villa total used for
 * ring-fencing is derived as count($villas), so a filtered/partial subset
 * cannot be ranked correctly: no value of that derived total would rank a
 * pre-filtered list the way the full property ranks it. A missing 'sort_order'
 * or 'taken' key is normalised (to 0 and [] respectively) in a pass BEFORE
 * ranking — normalising after would rank off raw input and, for 'taken', fatal
 * in the comparator once there are 2+ villas.
 *
 * Component products never see a reserved villa. The whole-villa product sees
 * every villa and prefers the reserved ones, so that consuming a villa leaves the
 * open villas available for component sales. $reserved is clamped to the villa
 * count so a misconfigured setting larger than the property fails closed (the
 * whole product line goes off sale) instead of throwing.
 *
 * Within those rules: most-occupied first (pack tight, keeping whole villas
 * intact), ties broken by unit_id ascending in BOTH the rank sort and this
 * final comparator. units.sort_order is NOT NULL DEFAULT 0, so two admin-added
 * villas can share a value — without a tiebreaker, rank (and therefore which
 * villas are ring-fenced) would fall back to arbitrary input/row order.
 */
function mi_order_villas(array $villas, bool $isVillaProduct, int $reserved): array {
    $totalVillas = count($villas);
    $reserved = min($reserved, $totalVillas);

    $sorted = array_map(static function (array $v): array {
        $v['sort_order'] = $v['sort_order'] ?? 0;
        $v['taken'] = $v['taken'] ?? [];
        return $v;
    }, $villas);
    usort($sorted, static function (array $a, array $b): int {
        $bySortOrder = (int)$a['sort_order'] <=> (int)$b['sort_order'];
        return $bySortOrder !== 0 ? $bySortOrder : (int)$a['unit_id'] <=> (int)$b['unit_id'];
    });

    $out = [];
    $rank = 0;
    foreach ($sorted as $v) {
        $rank++;
        $isRes = mi_villa_is_reserved($rank, $totalVillas, $reserved);
        if ($isRes && !$isVillaProduct) continue;
        $v['_reserved'] = $isRes;
        $out[] = $v;
    }
    usort($out, static function (array $a, array $b) use ($isVillaProduct): int {
        if ($isVillaProduct && $a['_reserved'] !== $b['_reserved']) {
            return $a['_reserved'] ? -1 : 1;
        }
        $ca = count($a['taken']);
        $cb = count($b['taken']);
        if ($ca !== $cb) return $cb <=> $ca;
        return (int)$a['unit_id'] <=> (int)$b['unit_id'];
    });

    return array_map(static function (array $v): array {
        unset($v['_reserved']);
        return $v;
    }, $out);
}
