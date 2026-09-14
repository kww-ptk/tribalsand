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

/** Encode a component list as a Postgres array literal. Names are [a-z_]+ only. */
function mi_pg_array_encode(array $items): string {
    return '{' . implode(',', $items) . '}';
}

/**
 * Decode a Postgres array literal into a component list.
 *
 * NOTE: a NULL column means "the whole unit is taken", which is NOT the same as
 * an empty set. This returns [] for both, so callers MUST test the raw value for
 * null before calling this.
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
 */
function mi_resolve(array $pattern, array $taken): ?array {
    $freeDoubles = array_values(array_diff(MAYA_ILAI_DOUBLES, $taken));
    $out = [];
    foreach ($pattern as $need) {
        if ($need === 'double') {
            if (!$freeDoubles) return null;
            $out[] = array_shift($freeDoubles);
            continue;
        }
        if (in_array($need, $taken, true)) return null;
        if (in_array($need, $out, true))   return null; // a pattern asking twice for one room
        $out[] = $need;
    }
    return $out;
}
