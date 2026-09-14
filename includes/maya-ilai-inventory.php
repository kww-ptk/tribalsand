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
