# Staff Clock In/Out Kiosk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fixed tablet where staff scan a printed QR card, tap Clock in or Clock out, and have a photo captured as evidence — feeding the existing `attendance` table.

**Architecture:** Punches are an append-only event log (`attendance_punches`); the existing `attendance` day-row remains the summary every report already reads. All slot logic is pure and tested before any page exists. The kiosk authenticates as a *device*, not a person — no staff session is involved.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS/CSS (no build step, no npm), two vendored single-file JS libraries.

**Spec:** `docs/superpowers/specs/2026-09-21-staff-clock-in-out-kiosk-design.md`

---

## CRITICAL PREREQUISITE — read before Task 1

`storage_put_private()` falls back to `sys_get_temp_dir()` when no private bucket is
configured (`includes/storage.php`, `checkin_private_dir()`). **On ECS that is the
container's ephemeral disk, wiped on every deploy.** Photo evidence would silently vanish.

One of these MUST be set in production before the photo feature is trusted:
`R2_CHECKIN_BUCKET`, `S3_CHECKIN_BUCKET`, or `CHECKIN_STORAGE_DIR` (a persistent volume).

Task 9 makes device registration **refuse** when none is set, so the feature cannot be
switched on into a state where it quietly loses evidence.

---

## File Structure

| File | Responsibility |
|---|---|
| Create `db/migrations/add_attendance_punches.sql` | `hr_staff.punch_token` + `attendance_devices` + `attendance_punches` |
| Create `includes/attendance-clock.php` | Guards, token mint/resolve, device auth, **pure** slot logic, punch write |
| Create `tests/attendance_clock_logic.php` | Pure assertions + DB round-trip in a rolled-back transaction |
| Create `js/vendor/jsqr.js` | Vendored QR **decoder** (MIT, one file) |
| Create `js/vendor/qrcode.js` | Vendored QR **generator** (MIT, one file) — printed cards only |
| Create `clock.php` | The kiosk page (web root, NOT under `/admin/`) |
| Create `js/clock-kiosk.js` | Camera, decode loop, confirm, capture, post |
| Create `api/clock-register.php` | Device registration (manager-authed) |
| Create `api/clock-punch.php` | The punch endpoint (device-authed) |
| Create `admin/attendance-devices.php` | Register / rename / revoke, last seen |
| Create `admin/attendance-cards.php` | Printable QR card sheet |
| Create `admin/attendance-photo.php` | Serve one punch photo, session-authed + scoped |
| Modify `admin/attendance.php` | Show punch photos on a day |
| Modify `admin/_layout.php` | Nav entries |

**House conventions for every task:**
- Prepared statements only via `db_query()`. Never interpolate a VALUE into SQL.
- Every new DB read behind a `*_supported()` guard probing `to_regclass` / `information_schema` — **never** a failing `SELECT` (a failed statement aborts the whole transaction in Postgres).
- `e()` on every user- or DB-supplied value.
- Nairobi-local dates via `frontdesk_today_ymd()`. Never assume UTC.
- Admin UI uses `.eselect` / `.inp` / `.btn-primary` / `.btn-outline` / `.btn-sm`. **`.btn-icon` is a 32×32 glyph square — never put text in it.**
- Tests are plain PHP scripts (`php tests/<name>.php`), no PHPUnit. Copy the harness shape from `tests/task_calendar_logic.php`.

---

### Task 1: Migration

**Files:** Create `db/migrations/add_attendance_punches.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Migration: self-service clock in/out (QR card kiosk).
-- Run via /admin/migrate.php. Idempotent. Depends on add_attendance.sql.
--
-- Three additions. The existing `attendance` table is NOT changed: punches
-- update it, and it stays the daily summary every report already reads.

-- 1. The secret a printed QR card encodes. NEVER hr_staff.id — a card encoding
--    an id is forged by printing a different number. 32 random hex chars,
--    minted on first card print, regenerated to kill a lost card.
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS punch_token TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_hr_staff_punch_token
    ON hr_staff (punch_token) WHERE punch_token IS NOT NULL;

-- 2. A registered kiosk tablet. Only the token HASH is stored, like a password:
--    a leaked row must not yield a working kiosk.
CREATE TABLE IF NOT EXISTS attendance_devices (
    id           SERIAL PRIMARY KEY,
    name         TEXT NOT NULL,
    venue_id     INT REFERENCES venues(id) ON DELETE SET NULL,
    token_hash   TEXT NOT NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at TIMESTAMPTZ,
    created_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 3. The event log — one row per tap. A day has up to four punches but one
--    `attendance` row, and each punch carries its own photo and device. This is
--    also the audit trail: `attendance` is editable by any manager, so without
--    this there is no record of what the person actually did.
CREATE TABLE IF NOT EXISTS attendance_punches (
    id          SERIAL PRIMARY KEY,
    hr_staff_id INT NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    device_id   INT REFERENCES attendance_devices(id) ON DELETE SET NULL,
    venue_id    INT REFERENCES venues(id) ON DELETE SET NULL,
    punched_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    kind        TEXT NOT NULL CHECK (kind IN ('in','out')),
    work_date   DATE NOT NULL,              -- the attendance row this filled
    slot        TEXT NOT NULL CHECK (slot IN ('in1','out1','in2','out2')),
    photo_key   TEXT,                       -- NULL = photo failed; time still stands
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_punches_staff_date ON attendance_punches (hr_staff_id, work_date);
CREATE INDEX IF NOT EXISTS idx_punches_date       ON attendance_punches (work_date);
```

- [ ] **Step 2: Apply it**

Run:
```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_attendance_punches.sql")); echo "applied\n";'
```
Expected: `applied`

- [ ] **Step 3: Verify all three landed**

Run:
```bash
php -r 'require "includes/db.php";
$p=db();
foreach (["attendance_devices","attendance_punches"] as $t) printf("%-20s %s\n", $t, $p->query("SELECT to_regclass(\$\$public.$t\$\$)")->fetchColumn() ? "EXISTS" : "MISSING");
$r=$p->prepare("SELECT 1 FROM information_schema.columns WHERE table_name=:t AND column_name=:c");
$r->execute([":t"=>"hr_staff",":c"=>"punch_token"]); printf("%-20s %s\n","hr_staff.punch_token",$r->fetchColumn()?"EXISTS":"MISSING");'
```
Expected: three `EXISTS`.

- [ ] **Step 4: Re-run to prove idempotence**

Run the Step 2 command again. Expected: `applied`, no error.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_attendance_punches.sql
git commit -m "feat(attendance): migration — punch tokens, kiosk devices, punch log

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Guards and card tokens

**Files:** Create `includes/attendance-clock.php`, Create `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/attendance_clock_logic.php`:

```php
<?php
declare(strict_types=1);
// Clock in/out — slot resolution, night shifts, token and device auth.
// Run: php tests/attendance_clock_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end.
// Requires add_hr_staff.sql + add_attendance.sql + add_attendance_punches.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Guards ──────────────────────────────────────────────────────────────────
check('punches guard returns a bool', is_bool(attendance_punches_supported()));
check('devices guard returns a bool', is_bool(attendance_devices_supported()));

// ── Token shape (pure) ──────────────────────────────────────────────────────
$t1 = clock_new_token();
$t2 = clock_new_token();
check('token is 32 hex chars',  (bool)preg_match('/^[0-9a-f]{32}$/', $t1));
check('tokens differ',          $t1 !== $t2);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: a fatal — `Failed opening required '.../includes/attendance-clock.php'`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/attendance-clock.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 4 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): clock guards and card-token minting

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Slot resolution (pure) — the core

**Files:** Modify `includes/attendance-clock.php`, Modify `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Insert into `tests/attendance_clock_logic.php` before the closing `echo $failures ?` line:

```php
// ── Slot resolution (pure). $row is an attendance row, or [] for no row. ─────
$empty  = [];
$in1    = ['in1' => 420];                                   // 07:00
$closed = ['in1' => 420, 'out1' => 720];                    // 07:00–12:00
$in2    = ['in1' => 420, 'out1' => 720, 'in2' => 780];      // back at 13:00
$full   = ['in1' => 420, 'out1' => 720, 'in2' => 780, 'out2' => 1020];

check('empty + in  -> in1',     clock_next_slot($empty,  'in')  === 'in1');
check('empty + out -> null',    clock_next_slot($empty,  'out') === null);
check('in1 + in    -> null',    clock_next_slot($in1,    'in')  === null);
check('in1 + out   -> out1',    clock_next_slot($in1,    'out') === 'out1');
check('closed + in -> in2',     clock_next_slot($closed, 'in')  === 'in2');
check('closed + out-> null',    clock_next_slot($closed, 'out') === null);
check('in2 + out   -> out2',    clock_next_slot($in2,    'out') === 'out2');
check('in2 + in    -> null',    clock_next_slot($in2,    'in')  === null);
check('full + in   -> null',    clock_next_slot($full,   'in')  === null);
check('full + out  -> null',    clock_next_slot($full,   'out') === null);

// A non-worked status (leave, off) must not silently accept a punch.
check('status row + in -> null', clock_next_slot(['status' => 'LV'], 'in') === null);

// An open row is one with an in and no matching out — used for the night-shift rule.
check('open: in1 only',      clock_row_is_open($in1)    === true);
check('open: closed pair',   clock_row_is_open($closed) === false);
check('open: in2 only',      clock_row_is_open($in2)    === true);
check('open: full',          clock_row_is_open($full)   === false);
check('open: empty',         clock_row_is_open($empty)  === false);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: fatal — `Call to undefined function clock_next_slot()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/attendance-clock.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 20 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): pure slot resolution for a punch

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: The merge rule and minutes (pure)

`attendance_upsert()` writes **all four** slots on every call. A punch must read the
current row, merge one value, and pass all four back — passing only `out1` erases `in1`.
This task builds and tests that merge in isolation, before anything writes to a database.

**Files:** Modify `includes/attendance-clock.php`, Modify `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Insert before the closing `echo $failures ?` line:

```php
// ── The merge rule (pure). THIS is the trap: attendance_upsert() is a whole-row
//    upsert, so a punch that passes only its own slot wipes the others. ───────
$merged = clock_merge_times(['in1' => 420], 'out1', 720);
check('merge keeps in1',        ($merged['in1']  ?? null) === '07:00');
check('merge sets out1',        ($merged['out1'] ?? null) === '12:00');
check('merge leaves in2 null',  ($merged['in2']  ?? null) === null);
check('merge leaves out2 null', ($merged['out2'] ?? null) === null);

$merged2 = clock_merge_times(['in1' => 420, 'out1' => 720, 'in2' => 780], 'out2', 1020);
check('merge keeps all three',  ($merged2['in1'] ?? null) === '07:00'
                             && ($merged2['out1'] ?? null) === '12:00'
                             && ($merged2['in2'] ?? null) === '13:00');
check('merge sets out2',        ($merged2['out2'] ?? null) === '17:00');

// Crossing midnight: 07:00 the next morning on yesterday's row is 1440 + 420.
check('past-midnight renders',  clock_merge_times(['in1' => 1140], 'out1', 1860)['out1'] === '31:00');

// Minutes helper
check('min from 07:02',   clock_minutes_from_hms('07:02:00') === 422);
check('min from 00:00',   clock_minutes_from_hms('00:00:00') === 0);
check('min from 23:59',   clock_minutes_from_hms('23:59:59') === 1439);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: fatal — `Call to undefined function clock_merge_times()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/attendance-clock.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 30 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): merge a punch into all four slots, never one

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Card tokens against the database

**Files:** Modify `includes/attendance-clock.php`, Modify `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Insert before the closing `echo $failures ?` line:

```php
// ── DB round-trip, inside a transaction we roll back ────────────────────────
$dbOk = true;
try { db(); } catch (Throwable $e) { $dbOk = false; }

if (!$dbOk || !attendance_punches_supported()) {
    echo "\nSKIP  DB assertions (no database, or add_attendance_punches.sql not applied)\n";
} else {
    db()->beginTransaction();
    try {
        $vid = (int) db_query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn();
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Clock Probe', :v, 'active')", [':v' => $vid]);
        $sid = (int) db()->lastInsertId('hr_staff_id_seq');

        $tok = clock_ensure_token($sid);
        check('token minted',            (bool)preg_match('/^[0-9a-f]{32}$/', $tok));
        check('token is stable',         clock_ensure_token($sid) === $tok);
        $found = clock_staff_by_token($tok);
        check('token resolves to person',(int)($found['id'] ?? 0) === $sid);
        check('unknown token resolves to nothing', clock_staff_by_token('deadbeef') === null);
        check('empty token resolves to nothing',   clock_staff_by_token('') === null);

        $new = clock_reissue_token($sid);
        check('reissue changes the token',  $new !== $tok);
        check('old card no longer works',   clock_staff_by_token($tok) === null);
        check('new card works',             (int)(clock_staff_by_token($new)['id'] ?? 0) === $sid);
    } finally {
        db()->rollBack();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: fatal — `Call to undefined function clock_ensure_token()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/attendance-clock.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 38 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Verify the test left nothing behind**

Run:
```bash
php -r 'require "includes/db.php"; echo db()->query("SELECT count(*) FROM hr_staff WHERE full_name = \$\$Clock Probe\$\$")->fetchColumn(), " probe rows (want 0)\n";'
```
Expected: `0 probe rows (want 0)`

- [ ] **Step 6: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): card tokens — mint, resolve, reissue

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Device registration and authentication

**Files:** Modify `includes/attendance-clock.php`, Modify `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Insert inside the `try {` block of the DB section, before the `} finally {`:

```php
        // ── Kiosk devices ───────────────────────────────────────────────────
        [$devId, $devTok] = clock_register_device('Probe tablet', $vid, null);
        check('device id returned',      $devId > 0);
        check('device token is 32 hex',  (bool)preg_match('/^[0-9a-f]{32}$/', $devTok));

        $dev = clock_device_by_token($devTok);
        check('device token resolves',   (int)($dev['id'] ?? 0) === $devId);
        check('wrong token refused',     clock_device_by_token(clock_new_token()) === null);
        check('empty token refused',     clock_device_by_token('') === null);

        // The plaintext token must NOT be recoverable from the row.
        $stored = (string) db_query("SELECT token_hash FROM attendance_devices WHERE id = :i", [':i' => $devId])->fetchColumn();
        check('token stored hashed',     $stored !== $devTok && $stored !== '');

        clock_revoke_device($devId);
        check('revoked device refused',  clock_device_by_token($devTok) === null);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: fatal — `Call to undefined function clock_register_device()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/attendance-clock.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 45 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): kiosk device registration, hashed tokens

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Recording a punch — including the night shift

**Files:** Modify `includes/attendance-clock.php`, Modify `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Insert inside the `try {` block, before `} finally {`:

```php
        // ── Recording punches ───────────────────────────────────────────────
        $today = frontdesk_today_ymd();
        $yest  = date('Y-m-d', strtotime('-1 day', strtotime($today)));

        $r1 = clock_record_punch($sid, 'in', 422, $today, $devId, $vid, null);   // 07:02
        check('in accepted',        ($r1['ok'] ?? false) === true);
        check('in filled in1',      ($r1['slot'] ?? '') === 'in1');

        $dup = clock_record_punch($sid, 'in', 423, $today, $devId, $vid, null);
        check('second in refused',  ($dup['ok'] ?? true) === false);

        $r2 = clock_record_punch($sid, 'out', 720, $today, $devId, $vid, null);  // 12:00
        check('out accepted',       ($r2['ok'] ?? false) === true);
        check('out filled out1',    ($r2['slot'] ?? '') === 'out1');

        // THE TRAP: out1 must not have erased in1.
        $row = db_query("SELECT in1, out1 FROM attendance WHERE hr_staff_id = :s AND work_date = :d",
                        [':s' => $sid, ':d' => $today])->fetch();
        check('in1 survived the out punch', (int)$row['in1'] === 422);
        check('out1 stored',                (int)$row['out1'] === 720);
        check('punch rows written',
              (int) db_query("SELECT count(*) FROM attendance_punches WHERE hr_staff_id = :s", [':s'=>$sid])->fetchColumn() === 2);

        // ── Night shift: yesterday open, clocking out this morning ──────────
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Night Probe', :v, 'active')", [':v'=>$vid]);
        $nid = (int) db()->lastInsertId('hr_staff_id_seq');
        db_query("INSERT INTO attendance (hr_staff_id, work_date, in1) VALUES (:s, :d, 1140)", [':s'=>$nid, ':d'=>$yest]); // 19:00

        $n = clock_record_punch($nid, 'out', 420, $today, $devId, $vid, null);   // 07:00 today
        check('night out accepted',      ($n['ok'] ?? false) === true);
        check('night out hit yesterday', ($n['work_date'] ?? '') === $yest);
        $nrow = db_query("SELECT in1, out1 FROM attendance WHERE hr_staff_id = :s AND work_date = :d",
                         [':s'=>$nid, ':d'=>$yest])->fetch();
        check('night out stored past midnight', (int)$nrow['out1'] === 1860);
        check('night in1 untouched',            (int)$nrow['in1']  === 1140);

        check('rate limiter is off at low volume', clock_rate_limited($sid) === false);
        check('rate limiter trips at the cap',      clock_rate_limited($sid, 1) === true);

        // A leave day refuses a punch outright.
        db_query("INSERT INTO hr_staff (full_name, venue_id, status) VALUES ('Leave Probe', :v, 'active')", [':v'=>$vid]);
        $lid = (int) db()->lastInsertId('hr_staff_id_seq');
        db_query("INSERT INTO attendance (hr_staff_id, work_date, status) VALUES (:s, :d, 'LV')", [':s'=>$lid, ':d'=>$today]);
        check('leave day refuses a punch', (clock_record_punch($lid, 'in', 420, $today, $devId, $vid, null)['ok'] ?? true) === false);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/attendance_clock_logic.php`
Expected: fatal — `Call to undefined function clock_record_punch()`.

- [ ] **Step 3: Write the minimal implementation**

Append to `includes/attendance-clock.php`:

```php
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

    if (clock_is_duplicate($staffId, $kind)) {
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

/** Was an identical punch just recorded? Guards a double-tap or a held card. */
function clock_is_duplicate(int $staffId, string $kind): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM attendance_punches
              WHERE hr_staff_id = :s AND kind = :k
                AND punched_at > now() - (:w || ' seconds')::interval
              LIMIT 1",
            [':s' => $staffId, ':k' => $kind, ':w' => (string) CLOCK_DUPLICATE_WINDOW]
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('[clock] duplicate check failed: ' . $e->getMessage());
        return false;   // fail OPEN — never block a real punch on a read error
    }
}
```

Add this require at the top of the file, beside the others, since `frontdesk_today_ymd()`
is used by callers and `attendance_status_label()` by this function:

```php
require_once __DIR__ . '/frontdesk.php';
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/attendance_clock_logic.php`
Expected: 62 `PASS` lines then `ALL PASS`.

- [ ] **Step 5: Confirm the database is clean**

Run:
```bash
php -r 'require "includes/db.php";
foreach (["hr_staff","attendance","attendance_punches","attendance_devices"] as $t)
  printf("%-22s %s\n", $t, db()->query("SELECT count(*) FROM $t")->fetchColumn());'
```
Expected: whatever the counts were before you started — the transaction rolled back.

- [ ] **Step 6: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(attendance): record a punch, including night shifts past midnight

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: Vendor the two QR libraries

**Files:** Create `js/vendor/jsqr.js`, Create `js/vendor/qrcode.js`, Create `js/vendor/README.md`

- [ ] **Step 1: Fetch the decoder**

`jsQR` (MIT) decodes a QR from canvas pixels. Download the single built file:

```bash
mkdir -p js/vendor
curl -fsSL https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js -o js/vendor/jsqr.js
ls -la js/vendor/jsqr.js
```
Expected: a file of roughly 300KB (unminified) — confirm it is non-empty and begins with a
comment or `(function`.

- [ ] **Step 2: Fetch the generator**

`qrcode-generator` (MIT) draws a QR for the printed cards:

```bash
curl -fsSL https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js -o js/vendor/qrcode.js
ls -la js/vendor/qrcode.js
```
Expected: a file of roughly 40KB.

- [ ] **Step 3: Verify both parse as JavaScript**

```bash
node --check js/vendor/jsqr.js && node --check js/vendor/qrcode.js && echo "both parse"
```
Expected: `both parse`

- [ ] **Step 4: Record what they are and why**

Create `js/vendor/README.md`:

```markdown
# Vendored third-party JavaScript

This project has no npm and no build step (see CLAUDE.md), so the few third-party
browser libraries it needs are committed here as single files and loaded with a
plain `<script src>` tag, exactly like every first-party file in `js/`.

| File | Library | Version | Licence | Used by |
|------|---------|---------|---------|---------|
| `jsqr.js` | [jsQR](https://github.com/cozmo/jsQR) | 1.4.0 | MIT | `js/clock-kiosk.js` — decodes a staff QR card from the camera |
| `qrcode.js` | [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) | 1.4.4 | MIT | `admin/attendance-cards.php` — draws the printed cards |

Both are unmodified upstream builds. To update one, replace the file and change
the version in this table — there is nothing to rebuild.

`jsqr.js` is only loaded by the kiosk page and `qrcode.js` only by the card-print
page, so no ordinary admin or guest page pays for them.
```

- [ ] **Step 5: Commit**

```bash
git add js/vendor/
git commit -m "chore(attendance): vendor jsQR and qrcode-generator

No npm and no build step, so both ship as single files loaded by a script
tag, like every other JS file here. Loaded only by the kiosk and the
card-print page.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: Device registration endpoint

**Files:** Create `api/clock-register.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
/**
 * Register this browser as a kiosk. Manager-authed, one-time: the response
 * carries a long-lived token the tablet stores and replays on every punch.
 *
 * Refuses when private storage is unconfigured — see below.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/attendance-clock.php';
require_login();
require_manager();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }
verify_csrf();

if (!attendance_devices_supported()) {
    http_response_code(400);
    exit(json_encode(['ok'=>false,'error'=>'Run the add_attendance_punches.sql migration first.']));
}

/**
 * Photo evidence is the point of this feature, and storage_put_private() falls
 * back to sys_get_temp_dir() when no private bucket or disk is configured — on
 * ECS that is the container's ephemeral filesystem, wiped every deploy. Refuse
 * to register rather than let the kiosk run while silently discarding evidence.
 */
$env = parse_env();
$hasPrivate = trim((string)($env['R2_CHECKIN_BUCKET'] ?? '')) !== ''
           || trim((string)($env['S3_CHECKIN_BUCKET'] ?? '')) !== ''
           || trim((string)($env['CHECKIN_STORAGE_DIR'] ?? '')) !== '';
if (!$hasPrivate) {
    http_response_code(400);
    exit(json_encode(['ok'=>false,'error'=>'No private storage is configured, so clock-in photos would be lost on the next deploy. Set R2_CHECKIN_BUCKET, S3_CHECKIN_BUCKET or CHECKIN_STORAGE_DIR first.']));
}

$name    = trim((string)($_POST['name'] ?? ''));
$venueId = (int)($_POST['venue_id'] ?? 0);

$scope = admin_venue_ids();                       // null = owner (all)
if ($scope !== null && !in_array($venueId, array_map('intval', $scope), true)) {
    http_response_code(403);
    exit(json_encode(['ok'=>false,'error'=>'That property isn’t yours to register a device for.']));
}
if ($name === '') { http_response_code(400); exit(json_encode(['ok'=>false,'error'=>'Give the tablet a name.'])); }

[$id, $token] = clock_register_device($name, $venueId ?: null, (int)($_SESSION['admin_id'] ?? 0) ?: null);
audit_log('attendance_device.register', 'attendance_device', $id, $name);

echo json_encode(['ok' => true, 'error' => null, 'device_id' => $id, 'token' => $token]);
```

- [ ] **Step 2: Verify it parses**

Run: `php -l api/clock-register.php`
Expected: `No syntax errors detected in api/clock-register.php`

- [ ] **Step 3: Verify the storage guard actually triggers**

Run:
```bash
php -r 'require "includes/db.php";
$env = parse_env();
$has = trim((string)($env["R2_CHECKIN_BUCKET"] ?? "")) !== ""
    || trim((string)($env["S3_CHECKIN_BUCKET"] ?? "")) !== ""
    || trim((string)($env["CHECKIN_STORAGE_DIR"] ?? "")) !== "";
echo $has ? "private storage IS configured — registration will be allowed\n"
          : "private storage NOT configured — registration will be refused (this is correct)\n";'
```
Report which you got. Either is fine; this confirms the guard reads the right keys.

- [ ] **Step 4: Commit**

```bash
git add api/clock-register.php
git commit -m "feat(attendance): kiosk registration, refused without private storage

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 10: The punch endpoint

**Files:** Create `api/clock-punch.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
/**
 * Record one clock in/out from a registered kiosk.
 *
 * Authenticated by the DEVICE token, not a staff session — no one is signed in
 * at a shared tablet. There is deliberately no CSRF token: there is no session
 * to ride, and the device token is the credential.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

header('Content-Type: application/json');

/** Answer and stop. */
function punch_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') punch_fail('Method not allowed', 405);
if (!attendance_punches_supported())       punch_fail('Clocking in isn’t enabled yet.');

$device = clock_device_by_token((string)($_POST['device_token'] ?? ''));
if (!$device) punch_fail('This tablet is no longer registered. Ask a manager to set it up again.', 403);

$staff = clock_staff_by_token((string)($_POST['card'] ?? ''));
if (!$staff) punch_fail('Card not recognised. See a manager.', 404);

$kind = (string)($_POST['kind'] ?? '');
if (!in_array($kind, ['in', 'out'], true)) punch_fail('Choose clock in or clock out.');

// Rate limit per card: a scanned card must not be replayable in a loop.
if (clock_rate_limited((int)$staff['id'])) {
    punch_fail('Too many attempts. Wait a few minutes.', 429);
}

$today   = frontdesk_today_ymd();
$minutes = clock_minutes_from_hms(date('H:i:s'));

// Store the photo FIRST so the punch row can carry its key — but never let a
// photo failure cost someone their shift. A punch with no picture beats none.
$photoKey = null;
if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $info = @getimagesize($_FILES['photo']['tmp_name']);
    if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        $key = 'attendance/' . (int)$staff['id'] . '/' . $today . '/' . bin2hex(random_bytes(8)) . '.jpg';
        if (storage_put_private($_FILES['photo']['tmp_name'], $key, 'image/jpeg')) $photoKey = $key;
        else error_log('[clock] photo store failed for staff ' . (int)$staff['id']);
    }
}

$res = clock_record_punch(
    (int)$staff['id'], $kind, $minutes, $today,
    (int)$device['id'], (int)($device['venue_id'] ?: 0) ?: null, $photoKey
);

if (!$res['ok']) punch_fail((string)$res['error']);

clock_touch_device((int)$device['id']);

echo json_encode([
    'ok'     => true,
    'error'  => null,
    'name'   => $staff['full_name'],
    'kind'   => $kind,
    'time'   => date('H:i'),
    'photo'  => $photoKey !== null,
    'date'   => $res['work_date'],
]);
```

- [ ] **Step 2: Verify it parses**

Run: `php -l api/clock-punch.php`
Expected: `No syntax errors detected in api/clock-punch.php`

- [ ] **Step 3: Commit**

```bash
git add api/clock-punch.php
git commit -m "feat(attendance): the punch endpoint, device-authed

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 11: The kiosk page and its JavaScript

**Files:** Create `clock.php`, Create `js/clock-kiosk.js`

- [ ] **Step 1: Write `clock.php`**

```php
<?php
/**
 * The staff clock in/out kiosk. Full screen, no chrome, meant for a tablet
 * mounted at a property. Deliberately at the web root, not under /admin/.
 *
 * Two modes, chosen by whether the browser holds a device token:
 *   unregistered → a manager signs in and names the tablet (once, ever)
 *   registered   → camera, scan, confirm
 *
 * There is no staff session here. The DEVICE is the credential.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance-clock.php';

$signedIn = !empty($_SESSION['admin_id']);
$canSetUp = $signedIn && (is_owner() || is_manager());

$venues = [];
if ($canSetUp) {
    $scope = admin_venue_ids();
    if ($scope === null) {
        $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
    } elseif ($scope) {
        $ph = []; $p = [];
        foreach ($scope as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
        $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Clock in — Tribal Sand</title>
<style>
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
     background:#12262d;color:#f4efe6;min-height:100vh;display:flex;align-items:center;justify-content:center}
.kiosk{width:100%;max-width:640px;padding:24px;text-align:center}
.kiosk h1{font-size:24px;margin:0 0 4px;font-weight:500}
.kiosk p.sub{color:#9fb3ba;margin:0 0 24px;font-size:15px}
#video{width:100%;max-width:420px;border-radius:16px;background:#000;aspect-ratio:4/3;object-fit:cover}
.person{font-size:28px;margin:12px 0 2px}
.meta{color:#9fb3ba;font-size:15px;margin:0 0 20px}
.acts{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.big{border:0;border-radius:14px;padding:20px 28px;font-size:19px;font-weight:500;cursor:pointer;min-width:190px;min-height:64px}
.big--in{background:#2f6f4f;color:#fff}
.big--out{background:#8a4b2a;color:#fff}
.big--ghost{background:transparent;color:#9fb3ba;border:1.5px solid #34505a}
.msg{margin-top:18px;font-size:17px;min-height:26px}
.msg--bad{color:#ffb4a8}
.msg--good{color:#8fe0b4}
.setup{text-align:left;background:#193440;border-radius:14px;padding:20px}
.setup label{display:block;margin:0 0 12px;font-size:14px;color:#9fb3ba}
.setup input,.setup select{width:100%;padding:12px;border-radius:8px;border:1px solid #34505a;background:#0e1e24;color:#f4efe6;font-size:16px;margin-top:6px}
.hidden{display:none}
</style>
</head>
<body>
<div class="kiosk">

  <div id="kioskMode" class="hidden">
    <h1>Scan your card</h1>
    <p class="sub">Hold it up to the camera</p>
    <video id="video" playsinline muted></video>
    <canvas id="frame" class="hidden"></canvas>

    <div id="person" class="hidden">
      <div class="person" id="personName"></div>
      <p class="meta" id="personMeta"></p>
      <div class="acts">
        <button class="big big--in"    data-kind="in">Clock in</button>
        <button class="big big--out"   data-kind="out">Clock out</button>
        <button class="big big--ghost" data-cancel>Cancel</button>
      </div>
    </div>

    <p class="msg" id="msg"></p>
  </div>

  <div id="setupMode">
    <h1>Set up this tablet</h1>
    <?php if (!$canSetUp): ?>
      <p class="sub">An owner or manager needs to sign in on this device once to set it up.</p>
      <a class="big big--in" style="display:inline-block;text-decoration:none;line-height:24px"
         href="/admin/login.php?next=<?= e(urlencode('/clock.php')) ?>">Sign in</a>
    <?php elseif (!$venues): ?>
      <p class="sub">No properties are assigned to your account, so there is nothing to register this tablet against.</p>
    <?php else: ?>
      <p class="sub">This is a one-time step. The tablet stays signed in afterwards.</p>
      <div class="setup">
        <?= csrf_field() ?>
        <label>Name this tablet
          <input type="text" id="devName" placeholder="Zuri reception tablet" autocomplete="off">
        </label>
        <label>Property
          <select id="devVenue">
            <?php foreach ($venues as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="big big--in" id="devSave" style="width:100%">Register this tablet</button>
        <p class="msg" id="setupMsg"></p>
      </div>
    <?php endif; ?>
  </div>

</div>
<script src="/js/vendor/jsqr.js?v=<?= @filemtime(__DIR__ . '/js/vendor/jsqr.js') ?: time() ?>"></script>
<script src="/js/clock-kiosk.js?v=<?= @filemtime(__DIR__ . '/js/clock-kiosk.js') ?: time() ?>"></script>
</body>
</html>
```

- [ ] **Step 2: Write `js/clock-kiosk.js`**

```js
/* Staff clock in/out kiosk.

   The device token in localStorage is what authenticates a punch — no one is
   signed in at a shared tablet. Registration happens once; after that the page
   goes straight to the camera on every load.

   The camera stream is opened once and kept. jsQR decodes frames off a canvas;
   a decoded card pauses scanning until the person acts or cancels, so a card
   left in front of the lens cannot fire repeatedly. */
(function () {
  'use strict';
  var KEY = 'ts_clock_device_token';

  var kiosk = document.getElementById('kioskMode');
  var setup = document.getElementById('setupMode');
  var video = document.getElementById('video');
  var frame = document.getElementById('frame');
  var person = document.getElementById('person');
  var personName = document.getElementById('personName');
  var personMeta = document.getElementById('personMeta');
  var msg = document.getElementById('msg');

  var token = null;
  try { token = localStorage.getItem(KEY); } catch (e) { token = null; }

  var scanning = false;
  var current = null;

  function say(text, good) {
    msg.textContent = text || '';
    msg.className = 'msg' + (text ? (good ? ' msg--good' : ' msg--bad') : '');
  }

  /* ── Registration ──────────────────────────────────────────────────────── */
  var devSave = document.getElementById('devSave');
  if (devSave) {
    devSave.addEventListener('click', function () {
      var name = (document.getElementById('devName').value || '').trim();
      var out = document.getElementById('setupMsg');
      if (!name) { out.textContent = 'Give the tablet a name first.'; out.className = 'msg msg--bad'; return; }

      var body = new FormData();
      var tokenField = document.querySelector('input[name="csrf_token"]');
      body.append('csrf_token', tokenField ? tokenField.value : '');
      body.append('name', name);
      body.append('venue_id', document.getElementById('devVenue').value);
      devSave.disabled = true;

      fetch('/api/clock-register.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Could not register. Reload and try again.' }; }); })
        .then(function (d) {
          if (d && d.ok) {
            try { localStorage.setItem(KEY, d.token); } catch (e) {}
            window.location.reload();
            return;
          }
          devSave.disabled = false;
          out.textContent = (d && d.error) || 'Could not register.';
          out.className = 'msg msg--bad';
        })
        .catch(function () {
          devSave.disabled = false;
          out.textContent = 'Network problem. Please try again.';
          out.className = 'msg msg--bad';
        });
    });
  }

  if (!token) return;   // stay in setup mode

  setup.classList.add('hidden');
  kiosk.classList.remove('hidden');

  /* ── Camera + decode loop ──────────────────────────────────────────────── */
  var ctx = frame.getContext('2d', { willReadFrequently: true });

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    .then(function (stream) {
      video.srcObject = stream;
      return video.play();
    })
    .then(function () { scanning = true; requestAnimationFrame(tick); })
    .catch(function () {
      say('No camera. Allow camera access for this page, then reload.', false);
    });

  function tick() {
    if (scanning && video.readyState === video.HAVE_ENOUGH_DATA) {
      frame.width = video.videoWidth;
      frame.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, frame.width, frame.height);
      try {
        var img = ctx.getImageData(0, 0, frame.width, frame.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height) : null;
        if (code && code.data) onCard(code.data.trim());
      } catch (e) { /* a frame we could not read; try the next one */ }
    }
    requestAnimationFrame(tick);
  }

  /* ── A card was seen ───────────────────────────────────────────────────── */
  function onCard(data) {
    if (!/^[0-9a-f]{32}$/.test(data)) return;   // not one of our cards
    if (current === data) return;
    scanning = false;
    current = data;
    personName.textContent = 'Card read';
    personMeta.textContent = 'Choose clock in or clock out';
    person.classList.remove('hidden');
    say('');
  }

  function reset() {
    current = null;
    person.classList.add('hidden');
    scanning = true;
  }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-cancel]')) { reset(); say(''); return; }

    var btn = ev.target.closest('[data-kind]');
    if (!btn || !current) return;

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);
    body.append('kind', btn.getAttribute('data-kind'));

    var shot = capture();
    if (shot) body.append('photo', shot, 'punch.jpg');

    Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = true; });
    say('Saving…', true);

    fetch('/api/clock-punch.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please try again.' }; }); })
      .then(function (d) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = false; });
        if (d && d.ok) {
          say(d.name + ' — clocked ' + d.kind.toUpperCase() + ' at ' + d.time, true);
        } else {
          say((d && d.error) || 'That didn’t save.', false);
        }
        setTimeout(function () { reset(); say(''); }, 4000);
      })
      .catch(function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = false; });
        say('No connection. The punch was NOT saved — try again.', false);
      });
  });

  /* A still from the live stream, as a JPEG blob. Returns null if unavailable —
     the punch still goes through without it. */
  function capture() {
    try {
      if (!frame.width) return null;
      var data = frame.toDataURL('image/jpeg', 0.7).split(',')[1];
      var bin = atob(data);
      var arr = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      return new Blob([arr], { type: 'image/jpeg' });
    } catch (e) { return null; }
  }
})();
```

- [ ] **Step 3: Verify both parse**

```bash
php -l clock.php && node --check js/clock-kiosk.js && echo "both clean"
```
Expected: `both clean`

- [ ] **Step 4: Commit**

```bash
git add clock.php js/clock-kiosk.js
git commit -m "feat(attendance): the kiosk page — scan, confirm, capture

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 12: Admin — devices, cards, photos

**Files:** Create `admin/attendance-devices.php`, Create `admin/attendance-cards.php`, Create `admin/attendance-photo.php`

- [ ] **Step 1: Write the device manager**

`admin/attendance-devices.php` — `require_login()` + `require_manager()`, scoped by
`admin_venue_ids()`. A table of devices (name, property, last seen, status) with a Revoke
button per row posting `action=revoke` + `id` + `csrf_field()`, PRG back to itself with a
flash.

**The revoke branch must re-check ownership** — a manager must not retire another
property's tablet by posting its id. Write it exactly like this:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'revoke') {
        $id  = (int)($_POST['id'] ?? 0);
        $dev = $id ? db_query("SELECT id, venue_id, name FROM attendance_devices WHERE id = :i", [':i'=>$id])->fetch() : false;
        $scope = admin_venue_ids();                       // null = owner (all)
        $ok = $dev && ($scope === null || in_array((int)$dev['venue_id'], array_map('intval', $scope), true));
        if ($ok) {
            clock_revoke_device($id);
            audit_log('attendance_device.revoke', 'attendance_device', $id, (string)$dev['name']);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Tablet revoked. It can no longer record time.'];
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That tablet isn’t yours to revoke.'];
        }
    }
    header('Location: /admin/attendance-devices.php'); exit;
}
```
Show a banner with the `/clock.php` URL and the instruction to open it on the tablet and
sign in there. Follow the page shape of `admin/reservations.php` (page-header, card,
`.btn-outline`/`.btn-sm`). Use `.btn-outline btn-sm` for text buttons — **never `.btn-icon`,
which is a 32×32 glyph square**.

- [ ] **Step 2: Write the card sheet**

`admin/attendance-cards.php` — `require_login()` + `require_manager()`, scoped. A property
picker (validated against `admin_venue_ids()`, ignoring a foreign id) and a printable grid
of cards, one per active `hr_staff` row at that property: full name, position, and a QR
canvas. Each person's token comes from `clock_ensure_token($id)`. Draw each QR client-side:

```html
<script src="/js/vendor/qrcode.js?v=<?= @filemtime(__DIR__ . '/../js/vendor/qrcode.js') ?: time() ?>"></script>
<script>
Array.prototype.forEach.call(document.querySelectorAll('[data-qr]'), function (el) {
  var qr = qrcode(0, 'M');
  qr.addData(el.getAttribute('data-qr'));
  qr.make();
  el.innerHTML = qr.createSvgTag({ scalable: true });
});
</script>
```

Add a print stylesheet (`@media print`) hiding the admin chrome so the sheet prints clean,
and a "Reissue card" button per person posting to the same page, which calls
`clock_reissue_token()` and warns that the old card stops working immediately.

- [ ] **Step 3: Write the photo endpoint**

`admin/attendance-photo.php` — `require_login()`, takes `?punch=<id>`. The scope check and
the resolve-before-headers order are both load-bearing, so write them exactly:

```php
require_login();

$id = (int)($_GET['punch'] ?? 0);
$row = $id ? db_query(
    "SELECT p.photo_key, s.venue_id
       FROM attendance_punches p
       JOIN hr_staff s ON s.id = p.hr_staff_id
      WHERE p.id = :i", [':i' => $id])->fetch() : false;

$scope = admin_venue_ids();                       // null = owner (all)
if (!$row || ($scope !== null && !in_array((int)$row['venue_id'], array_map('intval', $scope), true))) {
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Forbidden');
}
$key = (string)($row['photo_key'] ?? '');
if ($key === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('No photo was captured for this punch.'); }

// Resolve the bytes BEFORE sending any content headers — otherwise a missing
// file returns a text error body under image/jpeg and the browser renders a
// broken image instead of the real problem. Same order as admin/checkin-file.php.
$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); exit('Photo is stored remotely but could not be fetched.'); }
} else {
    $path = storage_local_path($key);
    if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Photo is missing from storage — most likely no persistent private bucket is configured, so it was lost on a deploy.'); }
    $data = file_get_contents($path);
    if ($data === false) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); exit('Photo could not be read.'); }
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, no-store');
echo $data;
```

Confirm `storage_signed_get_url()` and `storage_local_path()` exist with those names before
relying on them (`grep -n "function storage_signed_get_url\|function storage_local_path" includes/storage.php`);
if either differs, use the real name and say so in your report.

- [ ] **Step 4: Verify all three parse**

```bash
for f in admin/attendance-devices.php admin/attendance-cards.php admin/attendance-photo.php; do php -l $f; done
```
Expected: `No syntax errors detected` three times.

- [ ] **Step 5: Commit**

```bash
git add admin/attendance-devices.php admin/attendance-cards.php admin/attendance-photo.php
git commit -m "feat(attendance): device manager, printable cards, photo endpoint

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 13: Show punches on the attendance page, and nav

**Files:** Modify `admin/attendance.php`, Modify `admin/_layout.php`

- [ ] **Step 1: Read `admin/attendance.php` end to end**

Run: `cat admin/attendance.php`

Find where a day's row is rendered. You are adding a marker on any day that has punches.

- [ ] **Step 2: Add a punch fetcher**

Append to `includes/attendance-clock.php`:

```php
/**
 * Punches for one person on one day, oldest first. [] pre-migration.
 * Used by the attendance editor to show what the kiosk actually recorded,
 * alongside whatever a manager may since have typed over it.
 */
function clock_punches_for(int $staffId, string $ymd): array {
    if (!attendance_punches_supported()) return [];
    try {
        return db_query(
            "SELECT p.id, p.kind, p.slot, p.punched_at, p.photo_key, d.name AS device_name
               FROM attendance_punches p
               LEFT JOIN attendance_devices d ON d.id = p.device_id
              WHERE p.hr_staff_id = :s AND p.work_date = :d
              ORDER BY p.punched_at ASC",
            [':s' => $staffId, ':d' => $ymd]
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[clock] punches fetch failed: ' . $e->getMessage());
        return [];
    }
}
```

- [ ] **Step 3: Render them**

In `admin/attendance.php`, require `includes/attendance-clock.php` and, on each day row that
has punches, show a small "self-recorded" marker listing each punch as
`HH:MM in/out` with a thumbnail link to `admin/attendance-photo.php?punch=<id>` when
`photo_key` is set, and the plain text "no photo" when it is not.

Guard the whole block with `attendance_punches_supported()` so a pre-migration deploy
renders the page exactly as it does today.

- [ ] **Step 4: Add the nav entries**

In `admin/_layout.php`, next to the existing HR/attendance links, add two entries gated on
owner-or-manager (match how the neighbouring attendance link is gated — read the file and
use the variable it already defines rather than inventing one):

- "Clock devices" → `/admin/attendance-devices.php`, active on `$activeMenu === 'attendance_devices'`
- "Staff cards" → `/admin/attendance-cards.php`, active on `$activeMenu === 'attendance_cards'`

Copy the `<svg>` shape and sizing from a neighbouring link exactly.

- [ ] **Step 5: Verify**

```bash
php -l admin/attendance.php && php -l admin/_layout.php && php tests/attendance_clock_logic.php | tail -2
```
Expected: two clean lints and `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add admin/attendance.php admin/_layout.php includes/attendance-clock.php
git commit -m "feat(attendance): show kiosk punches on the day, add nav

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 14: Full verification

**Files:** none — this task only runs things.

- [ ] **Step 1: Run the new suite and its neighbours**

```bash
for t in attendance_clock_logic hr_logic task_calendar_logic recurring_tasks_logic; do
  printf "%-26s " "$t"; php tests/$t.php 2>&1 | tail -2 | tr '\n' ' '; echo
done
```
Expected: `attendance_clock_logic`, `task_calendar_logic` and `recurring_tasks_logic` end
`ALL PASS`. **`hr_logic` has one KNOWN pre-existing failure** ("scope: bare column when no
alias") that also fails on master — do not chase it, and do not "fix" it by weakening
`hr_scope_sql()`.

- [ ] **Step 2: Lint everything touched**

```bash
for f in $(git diff --name-only master..HEAD | grep '\.php$'); do php -l "$f" | grep -v "^No syntax errors" || true; done
node --check js/clock-kiosk.js && node --check js/vendor/jsqr.js && node --check js/vendor/qrcode.js
echo "lint done"
```
Expected: no PHP output before `lint done`.

- [ ] **Step 3: Prove the pre-migration path**

Postgres DDL is transactional, so this is safe and reverts itself:

```bash
php -r 'require "includes/db.php";
$p = db(); $p->beginTransaction();
try {
  $p->exec("DROP TABLE attendance_punches");
  $p->exec("DROP TABLE attendance_devices");
  require "includes/attendance-clock.php";
  echo "punches_supported = " . var_export(attendance_punches_supported(), true) . " (want false)\n";
  echo "devices_supported = " . var_export(attendance_devices_supported(), true) . " (want false)\n";
  $r = clock_record_punch(1, "in", 420, date("Y-m-d"), null, null, null);
  echo "punch refused cleanly: " . var_export($r["ok"] === false, true) . "\n";
  echo "punches_for -> " . count(clock_punches_for(1, date("Y-m-d"))) . " rows, no fatal\n";
} catch (Throwable $e) { echo "FAILED: " . $e->getMessage() . "\n"; }
$p->rollBack();
echo "rolled back; tables back: " . var_export((bool)$p->query("SELECT to_regclass(\$\$public.attendance_punches\$\$)")->fetchColumn(), true) . "\n";'
```
Expected: both guards `false`, the punch refused, no fatal, tables restored.

- [ ] **Step 4: Walk it in a browser**

Start a server and check each surface by hand. **`clock.php` needs `https` or `localhost`**
— `getUserMedia` is refused on plain http from any other host.

```bash
php -S localhost:8765 -t . router.php
```

- `/clock.php` — shows "Set up this tablet"; after signing in as a manager it offers the
  name + property form. Registering stores the token and reloads into camera mode.
- `/admin/attendance-cards.php` — QRs render; print preview is clean.
- Scan a printed (or on-screen) card at `/clock.php` — the buttons appear; Clock in saves
  and confirms; a second Clock in is refused with "You are already clocked in."
- `/admin/attendance.php` — the day now shows the punch and its photo.
- Revoke the device in `/admin/attendance-devices.php`, then punch again — expect "This
  tablet is no longer registered."

**Clean up every row you create** (staff, attendance, punches, devices) and prove the table
counts return to what they were before you started.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "test(attendance): verify the clock kiosk end to end

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Deployment

1. Apply `db/migrations/add_attendance_punches.sql` to production RDS via `/admin/migrate.php`. The local `.env` is a separate database.
2. **Set a private storage target before registering any tablet** — `R2_CHECKIN_BUCKET`, `S3_CHECKIN_BUCKET` or `CHECKIN_STORAGE_DIR`. Without one, `storage_put_private()` writes to the container's ephemeral disk and every clock-in photo is lost on the next deploy. Task 9 refuses registration until this is set, which is the safety net, not the solution.
3. Print the cards from Admin → Staff cards and hand them out.
4. Open `/clock.php` on the tablet, sign in once as a manager, name it, and leave it open. **It must be reached over https** or the camera will not start.
