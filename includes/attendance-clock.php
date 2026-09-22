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
