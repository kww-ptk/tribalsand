<?php
declare(strict_types=1);
/**
 * Oversell guard for the two staff-entered booking forms.
 *
 * admin/hold-new.php and admin/submission-view.php create a hold with no
 * availability check at all. That is deliberate and long-standing: reception
 * needs to be able to record an overbooking, a walk-in over a soft block, or a
 * stay the OTA feed has not caught up with. It is safe everywhere a block means
 * "the whole unit is gone", because staff can SEE the clash on the calendar and
 * are choosing to accept it.
 *
 * Maya Ilai breaks that. Its eight products slice the same eight villas, and a
 * block records which components it consumes — with NULL meaning the whole unit.
 * A staff-entered block has no component picker, so it is written NULL, i.e. the
 * WHOLE villa. Dropped on a villa where a guest has already bought one bedroom:
 *
 *     guest books a Double Room -> block on unit 84, components '{double_a}'
 *     staff books unit 84, same dates, via hold-new
 *                               -> block on unit 84, components NULL (all four)
 *
 * double_a is now sold twice. No constraint is violated, nothing is logged, and
 * the calendar showed one bar. The asymmetry matters: guest-after-staff is safe,
 * because the NULL block already reads as the whole villa and the allocator
 * refuses; only staff-after-guest oversells.
 *
 * So this guard is scoped to Maya Ilai VILLA units only. Every other unit at
 * every other property — Maya Ilai's own studios included — keeps the "you
 * control overlaps" behaviour untouched.
 *
 * Test: php tests/maya_ilai_inventory.php
 */

require_once __DIR__ . '/db.php';                    // mi_villa_states()
require_once __DIR__ . '/maya-ilai-inventory.php';   // MAYA_ILAI_* , component names

/** Human labels for villa components, in canonical order. */
function staff_hold_component_label(string $component): string {
    return [
        'double_a' => 'Double A',
        'double_b' => 'Double B',
        'bunk'     => 'Bunk',
        'living'   => 'Living',
    ][$component] ?? $component;
}

/**
 * May a staff-entered booking take this unit for these dates?
 *
 * Returns NULL when it may (every unit that is not a Maya Ilai villa always
 * may), or a ready-to-show error string naming the villa, the dates and which
 * components are already sold. Reception acts on this message without opening
 * another screen, so it has to carry all three — a bare "not available" sends
 * someone hunting through the calendar.
 *
 * ANY taken component refuses, because a staff booking takes the whole villa:
 * there is no subset of a sold villa it could legally occupy.
 *
 * Deliberately does NOT call expire_stale_holds(): that function emails every
 * affected guest, which is not a side effect a validation check should have.
 * The scheduler runs it every 5 minutes (docker/scheduler.sh), so at worst a
 * just-lapsed hold refuses one staff booking for a few minutes — failing closed,
 * which is the right direction for an oversell guard.
 */
function staff_hold_block_reason(int $unitId, string $checkIn, string $checkOut): ?string {
    if ($unitId <= 0 || $checkIn === '' || $checkOut === '' || $checkIn >= $checkOut) return null;

    $unit = db_query(
        'SELECT u.id, u.name, r.id AS room_id, r.slug
           FROM units u JOIN rooms r ON r.id = u.room_id
          WHERE u.id = :id',
        [':id' => $unitId]
    )->fetch();
    if (!$unit || ($unit['slug'] ?? '') !== MAYA_ILAI_VILLA_ROOM_SLUG) return null;

    $states = mi_villa_states((int)$unit['room_id'], $checkIn, $checkOut);
    $taken  = $states[$unitId]['taken'] ?? [];
    if (!$taken) return null;

    // Canonical order, so the same clash always reads the same way.
    $ordered = array_values(array_intersect(MAYA_ILAI_ALL_COMPONENTS, $taken));
    $names   = implode(' + ', array_map('staff_hold_component_label', $ordered ?: $taken));
    $villa   = trim((string)($unit['name'] ?? '')) ?: ('Unit ' . $unitId);

    return "{$villa} is not free for {$checkIn} → {$checkOut}: {$names} "
         . ($ordered && count($ordered) === count(MAYA_ILAI_ALL_COMPONENTS)
             ? 'are already sold (the whole villa).'
             : (count($ordered ?: $taken) === 1 ? 'is already sold.' : 'are already sold.'))
         . ' A staff-entered booking takes the WHOLE villa, so this would sell the'
         . ' same bedroom twice. Move or cancel the existing booking first, or pick'
         . ' another villa.';
}
