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
require_once __DIR__ . '/frontdesk.php';

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

/** Minutes past midnight for an H:i or H:i:s clock string. PURE. */
function clock_minutes_from_hms(string $hms): int {
    $p = explode(':', trim($hms));
    return max(0, ((int)($p[0] ?? 0)) * 60 + (int)($p[1] ?? 0));
}

/**
 * Build the COMPLETE four-slot payload for attendance_upsert(), preserving what
 * is already on the row and setting one slot.
 *
 * attendance_upsert() is a whole-row upsert (see includes/attendance.php): it
 * writes status, in1, out1, in2 and out2 on every call. Passing only the slot
 * being punched silently erases the others — the fastest way to lose someone's
 * hours. Always go through this function.
 *
 * Values come back as H:i strings because attendance_upsert() parses them with
 * attendance_hhmm_to_min(). A time past midnight stays >= 24:00 (e.g. 31:00) so
 * the stored minute value keeps the >= 1440 night-shift convention.
 */
function clock_merge_times(array $row, string $slot, int $minutes): array {
    $out = [];
    foreach (['in1', 'out1', 'in2', 'out2'] as $k) {
        $v = $row[$k] ?? null;
        $out[$k] = ($v === null || $v === '') ? null : sprintf('%02d:%02d', intdiv((int)$v, 60), ((int)$v) % 60);
    }
    $out[$slot] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    return $out;
}

/** This person's card token, minting one on first use. Stable across calls. */
function clock_ensure_token(int $staffId): string {
    if ($staffId <= 0) return '';
    $cur = (string) (db_query("SELECT punch_token FROM hr_staff WHERE id = :i", [':i' => $staffId])->fetchColumn() ?: '');
    if ($cur !== '') return $cur;
    $tok = clock_new_token();
    db_query("UPDATE hr_staff SET punch_token = :t WHERE id = :i", [':t' => $tok, ':i' => $staffId]);
    return $tok;
}

/** Replace this person's token, instantly killing their old printed card. */
function clock_reissue_token(int $staffId): string {
    $tok = clock_new_token();
    db_query("UPDATE hr_staff SET punch_token = :t WHERE id = :i", [':t' => $tok, ':i' => $staffId]);
    return $tok;
}

/**
 * The active person a scanned card belongs to, or null.
 *
 * Refuses an empty token explicitly: `punch_token IS NULL` rows must never be
 * matched by a blank scan, which a bare equality test would do if the column
 * and the input were both empty strings.
 */
function clock_staff_by_token(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    try {
        $row = db_query(
            "SELECT s.id, s.full_name, s.position, s.venue_id, s.status, v.name AS venue_name
               FROM hr_staff s
               LEFT JOIN venues v ON v.id = s.venue_id
              WHERE s.punch_token = :t",
            [':t' => $token]
        )->fetch();
        if (!$row) return null;
        if (strtolower(trim((string)($row['status'] ?? ''))) === 'inactive') return null;
        return $row;
    } catch (Throwable $e) {
        error_log('[clock] token lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Register a kiosk. Returns [id, plaintext token] — the token is shown ONCE and
 * only its hash is stored, like a password. A leaked database row must not yield
 * a working kiosk.
 */
function clock_register_device(string $name, ?int $venueId, ?int $createdBy): array {
    $tok = clock_new_token();
    db_query(
        "INSERT INTO attendance_devices (name, venue_id, token_hash, created_by)
         VALUES (:n, :v, :h, :c)",
        [':n' => trim($name) ?: 'Kiosk', ':v' => $venueId ?: null,
         ':h' => password_hash($tok, PASSWORD_DEFAULT), ':c' => $createdBy]
    );
    return [(int) db()->lastInsertId('attendance_devices_id_seq'), $tok];
}

/**
 * The active device a kiosk token belongs to, or null.
 *
 * The hash is per-row, so this walks active devices and verifies. There are a
 * handful of tablets, not thousands — a lookup index is not worth storing a
 * reversible token for.
 */
function clock_device_by_token(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    if (!attendance_devices_supported()) return null;
    try {
        foreach (db_query("SELECT * FROM attendance_devices WHERE is_active = TRUE")->fetchAll() as $d) {
            if (password_verify($token, (string)$d['token_hash'])) return $d;
        }
    } catch (Throwable $e) {
        error_log('[clock] device lookup failed: ' . $e->getMessage());
    }
    return null;
}

/** Stamp a device as seen. Best effort — never fails a punch. */
function clock_touch_device(int $deviceId): void {
    try { db_query("UPDATE attendance_devices SET last_seen_at = now() WHERE id = :i", [':i' => $deviceId]); }
    catch (Throwable $e) { error_log('[clock] touch failed: ' . $e->getMessage()); }
}

/** Retire a tablet. The next punch from it is refused. */
function clock_revoke_device(int $deviceId): void {
    db_query("UPDATE attendance_devices SET is_active = FALSE WHERE id = :i", [':i' => $deviceId]);
}

/** How recently an identical punch counts as a double-tap rather than a new one. */
const CLOCK_DUPLICATE_WINDOW = 120;

/** One attendance row, or [] when the person has none that day. */
function clock_day_row(int $staffId, string $ymd): array {
    try {
        $r = db_query("SELECT * FROM attendance WHERE hr_staff_id = :s AND work_date = :d",
                      [':s' => $staffId, ':d' => $ymd])->fetch();
        return $r ?: [];
    } catch (Throwable $e) {
        error_log('[clock] day row failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Record a punch. Returns ['ok'=>bool, 'error'=>?string, 'slot'=>?string,
 * 'work_date'=>?string, 'punch_id'=>?int].
 *
 * $minutes is minutes past midnight of $todayYmd, injected rather than read from
 * the clock so this is testable at any instant.
 *
 * Night shifts: a clock-out with no open slot today, when YESTERDAY is still
 * open, closes yesterday and stores the time as minutes + 1440 — the convention
 * add_attendance.sql documents. Only yesterday is considered; a row left open
 * longer is a manager's problem, not something to guess at.
 */
function clock_record_punch(int $staffId, string $kind, int $minutes, string $todayYmd,
                            ?int $deviceId, ?int $venueId, ?string $photoKey): array {
    if (!attendance_punches_supported()) return ['ok' => false, 'error' => 'Clocking in isn’t enabled yet.'];
    if ($staffId <= 0 || !in_array($kind, ['in', 'out'], true)) return ['ok' => false, 'error' => 'Bad punch.'];

    $workDate = $todayYmd;
    $row      = clock_day_row($staffId, $workDate);
    $slot     = clock_next_slot($row, $kind);

    // Night shift: nothing open today, but yesterday is mid-shift.
    if ($slot === null && $kind === 'out') {
        $yest    = date('Y-m-d', strtotime('-1 day', strtotime($todayYmd)));
        $yestRow = clock_day_row($staffId, $yest);
        if (clock_row_is_open($yestRow)) {
            $workDate = $yest;
            $row      = $yestRow;
            $slot     = clock_next_slot($yestRow, 'out');
            $minutes += 1440;
        }
    }

    if ($slot === null) {
        $status = trim((string)($row['status'] ?? ''));
        if ($status !== '' && $status !== 'P') {
            return ['ok' => false, 'error' => 'Today is marked ' . attendance_status_label($status) . '. See a manager.'];
        }
        return ['ok' => false, 'error' => $kind === 'in'
            ? 'You are already clocked in.'
            : 'You haven’t clocked in yet.'];
    }

    if (clock_is_duplicate($staffId, $slot, $workDate)) {
        return ['ok' => false, 'error' => 'Already recorded a moment ago.'];
    }

    attendance_upsert($staffId, $workDate, clock_merge_times($row, $slot, $minutes), null);

    db_query(
        "INSERT INTO attendance_punches (hr_staff_id, device_id, venue_id, kind, work_date, slot, photo_key)
         VALUES (:s, :dev, :v, :k, :d, :sl, :p)",
        [':s' => $staffId, ':dev' => $deviceId, ':v' => $venueId, ':k' => $kind,
         ':d' => $workDate, ':sl' => $slot, ':p' => $photoKey]
    );

    return ['ok' => true, 'error' => null, 'slot' => $slot, 'work_date' => $workDate,
            'punch_id' => (int) db()->lastInsertId('attendance_punches_id_seq')];
}

/**
 * Too many punches from one card in a short window? Guards a scanned card being
 * replayed in a loop. Mirrors reservation_rate_limited() in
 * includes/reservations.php: count this feature's own rows, and fail OPEN on a
 * read error — a database hiccup must never stop someone starting their shift.
 */
function clock_rate_limited(int $staffId, int $max = 20): bool {
    if (!attendance_punches_supported()) return false;
    try {
        $n = (int) db_query(
            "SELECT COUNT(*) FROM attendance_punches
              WHERE hr_staff_id = :s AND punched_at > now() - interval '10 minutes'",
            [':s' => $staffId]
        )->fetchColumn();
        return $n >= $max;
    } catch (Throwable $e) {
        error_log('[clock] rate check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Was this exact slot just written? Guards a double-tap or a card left in front
 * of the lens.
 *
 * Scoped by SLOT, not by kind. clock_next_slot() already refuses a second `in`
 * while in1 is open, so a kind-scoped window adds nothing there — it only
 * creates a false rejection when someone corrects a mistaken punch, or returns
 * from a short break, inside the window. Fails OPEN: a read error must never
 * stop a real punch.
 */
function clock_is_duplicate(int $staffId, string $slot, string $workDate): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM attendance_punches
              WHERE hr_staff_id = :s AND slot = :sl AND work_date = :d
                AND punched_at > now() - (:w || ' seconds')::interval
              LIMIT 1",
            [':s' => $staffId, ':sl' => $slot, ':d' => $workDate, ':w' => (string) CLOCK_DUPLICATE_WINDOW]
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('[clock] duplicate check failed: ' . $e->getMessage());
        return false;
    }
}
