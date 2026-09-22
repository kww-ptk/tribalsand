<?php
/**
 * Staff clock in/out — the kiosk's read/write model.
 *
 * Punches are an append-only log (attendance_punches); the existing `attendance`
 * day-row stays the summary every report reads. All slot logic here is PURE so
 * it is testable without a database or a clock.
 *
 * Every read is pre-migration-safe. The guards probe information_schema, never a
 * failing SELECT — a failed statement aborts the whole transaction in Postgres.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/attendance.php';

/** True once add_attendance_punches.sql has created the punch log (memoised). */
function attendance_punches_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.attendance_punches')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** True once the kiosk device table exists (memoised). */
function attendance_devices_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.attendance_devices')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/**
 * A fresh secret for a QR card or a kiosk device: 32 hex characters from a
 * cryptographic source. Never derived from a row id — a card encoding an id is
 * forged by printing a different number.
 */
function clock_new_token(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Which slot a punch fills, or null when the punch makes no sense. PURE.
 *
 * in1 → out1 → in2 → out2, in that order. Refusing is deliberate: a second
 * "clock in" without an intervening "out" is a mistake, and silently
 * overwriting in1 would erase the real start of someone's day.
 *
 * $row is an attendance row (or [] when the person has none yet). A row
 * carrying a non-worked status (leave, off, sick) accepts no punch at all —
 * a manager marked that day deliberately and the kiosk must not overwrite it.
 */
function clock_next_slot(array $row, string $kind): ?string {
    $status = trim((string)($row['status'] ?? ''));
    if ($status !== '' && $status !== 'P') return null;

    $has = fn(string $k): bool => isset($row[$k]) && $row[$k] !== null && $row[$k] !== '';

    if ($kind === 'in') {
        if (!$has('in1'))                    return 'in1';
        if ($has('out1') && !$has('in2'))    return 'in2';
        return null;
    }
    if ($kind === 'out') {
        if ($has('in1') && !$has('out1'))    return 'out1';
        if ($has('in2') && !$has('out2'))    return 'out2';
        return null;
    }
    return null;
}

/**
 * Is this row mid-shift — an in with no matching out? Used to decide whether a
 * clock-out belongs to YESTERDAY (a night shift crossing midnight).
 */
function clock_row_is_open(array $row): bool {
    return clock_next_slot($row, 'out') !== null;
}
