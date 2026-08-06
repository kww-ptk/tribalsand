# Pre-Check-in Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate the guest portal behind an admin-configurable, multi-step Pre-Check-in wizard when a booking requires it, capturing passport/flight/transfer/dietary/requests + a typed-name waiver before arrival.

**Architecture:** All logic lives in one new helper module (`includes/checkin.php`) tested by a standalone script (`tests/checkin_logic.php`, run via `php tests/checkin_logic.php` using the existing `check()` harness). Views (guest wizard, admin tab, admin config) and API endpoints stay thin and call those helpers. Data attaches to the existing `holds` row (two new columns) plus a `booking_checkin` row and a per-guest `checkin_guests` table. Passport files go to private Cloudflare R2 keys served only through an authenticated admin proxy. Everything is guarded by a `checkin_supported()` capability check and pre-migration `try/catch` so pages render before the migration is applied — matching the project's established conventions.

**Tech Stack:** Vanilla PHP 8.2+ (runs on 8.5 locally), PostgreSQL via PDO (`db_query()`), Cloudflare R2 (SigV4 already in `includes/storage.php`), vanilla JS/CSS (no build). Spec: `docs/superpowers/specs/2026-08-06-pre-checkin-design.md`.

---

## File Structure

**Create:**
- `db/migrations/add_checkin.sql` — holds columns + `booking_checkin` + `checkin_guests` (idempotent).
- `includes/checkin.php` — all helpers: config, state, badge, fetch, per-step completeness, submit validation, doc-view permission, waiver version.
- `tests/checkin_logic.php` — logic tests (pure helpers + DB-guarded fixtures).
- `admin/checkin-settings.php` — owner-only: step enabled/required toggles, global default, waiver text + welcome copy.
- `admin/_ws_checkin.php` — the "Check-in" tab body in the booking workspace (read-only submitted data + require toggle).
- `admin/checkin-file.php` — private passport/waiver file proxy (owner/manager, signed R2 GET, audit-logged).
- `includes/app/checkin.php` — guest wizard view (rendered by `booking.php?view=checkin`).
- `js/checkin-wizard.js` — client step navigation + async passport upload.
- `api/checkin-save.php` — guest: save one step / submit (validates, marks complete).
- `api/checkin-upload.php` — guest: passport file upload → private key.

**Modify:**
- `includes/storage.php` — generalize `storage_put()` (content-type + folder); add `storage_signed_get_url()` / `storage_read()`.
- `admin/hold-new.php` — "Require check-in" checkbox → follow-up UPDATE.
- `admin/booking.php` — add `checkin` tab (list + include + toggle POST handler).
- `booking.php` — gate logic + `checkin` view routing.
- `admin/frontdesk.php` — check-in badge on arriving cards.
- `admin/holds.php` — check-in badge column + "pending" filter.
- `admin/settings.php` — link to `checkin-settings.php`.
- `css/portal-app.css` — wizard + badge styles.

---

## Reference: helper contracts (implemented in Task 2)

These names are used across later tasks — keep them exact:

- `checkin_step_catalog(): array` — ordered `[key => ['label','default_required']]` for the 6 steps.
- `checkin_config(): array` — `[key => ['label','enabled','required']]` (catalog + `checkin_steps` setting overrides).
- `checkin_enabled_steps(): array` — enabled subset of `checkin_config()`, order preserved.
- `checkin_supported(): bool` — true once the migration is applied (cached).
- `checkin_required(array $hold): bool` — supported AND `$hold['require_checkin']` truthy.
- `checkin_is_complete(array $hold): bool` — `$hold['checkin_completed_at']` set.
- `checkin_state(array $hold): string` — `'none' | 'pending' | 'complete'`.
- `checkin_badge(array $hold): ?array` — `['label','class']` or null.
- `fetch_checkin(int $holdId): ?array` — `booking_checkin` row or null.
- `fetch_checkin_guests(int $holdId): array` — `checkin_guests` rows (lead first).
- `checkin_lead_guest(int $holdId): ?array` — the `is_lead` row or null.
- `waiver_version(string $text): string` — 12-char sha1 of trimmed terms.
- `can_view_guest_docs(int $holdId): bool` — `is_owner() || (is_manager() && staff_can_hold($holdId))`.
- `checkin_step_complete(string $key, ?array $data, ?array $lead): bool` — one step's required-completeness.
- `checkin_missing_steps(array $config, ?array $data, ?array $lead): array` — enabled+required steps still incomplete.

---

# Phase 1 — Foundation (schema, helpers, admin config + toggle, badges)

Delivers working software: admin can require check-in per booking, configure steps, and see "pending"/"done" badges. No guest UI yet.

### Task 1: Migration

**Files:**
- Create: `db/migrations/add_checkin.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Migration: guest Pre-Check-in. Run via /admin/migrate.php. Idempotent.
-- Adds a per-booking require flag + completion stamp, one booking-level
-- check-in row, and a per-guest identity table (lead-only UI in v1).

ALTER TABLE holds ADD COLUMN IF NOT EXISTS require_checkin      BOOLEAN     NOT NULL DEFAULT FALSE;
ALTER TABLE holds ADD COLUMN IF NOT EXISTS checkin_completed_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS booking_checkin (
    hold_id            INT PRIMARY KEY REFERENCES holds(id) ON DELETE CASCADE,
    arrival_airport    TEXT,
    flight_number      TEXT,
    arrival_at         TIMESTAMPTZ,
    needs_transfer     BOOLEAN,
    transfer_details   TEXT,
    dietary            TEXT,
    special_requests   TEXT,
    waiver_signed_name TEXT,
    waiver_signed_at   TIMESTAMPTZ,
    waiver_signed_ip   TEXT,
    waiver_version     TEXT,
    steps_saved        JSONB       NOT NULL DEFAULT '{}'::jsonb,
    submitted_at       TIMESTAMPTZ,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS checkin_guests (
    id                SERIAL PRIMARY KEY,
    hold_id           INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    is_lead           BOOLEAN NOT NULL DEFAULT TRUE,
    passport_name     TEXT,
    passport_number   TEXT,
    nationality       TEXT,
    passport_expiry   DATE,
    passport_file_key TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_checkin_guests_hold ON checkin_guests (hold_id);
```

- [ ] **Step 2: Apply it**

Local/dev: apply by running the file against the configured DB. From the repo root:
```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_checkin.sql")); echo "migrated\n";'
```
Expected: `migrated`. (Production applies the same file via `/admin/migrate.php` → select `add_checkin.sql` → Run.)

- [ ] **Step 3: Verify columns exist**

```bash
php -r 'require "includes/db.php"; var_dump(db_query("SELECT require_checkin, checkin_completed_at FROM holds LIMIT 1") instanceof PDOStatement);'
```
Expected: `bool(true)` (no exception).

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_checkin.sql
git commit -m "feat(checkin): schema — holds flags, booking_checkin, checkin_guests"
```

---

### Task 2: Helper module `includes/checkin.php`

**Files:**
- Create: `includes/checkin.php`
- Test: `tests/checkin_logic.php` (Task 3)

- [ ] **Step 1: Write the module**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';   // is_owner(), is_manager()
require_once __DIR__ . '/team.php';   // staff_can_hold()

/** Fixed step catalog. Array order = wizard order. */
function checkin_step_catalog(): array {
    return [
        'arrival'  => ['label' => 'Arrival & flight',     'default_required' => false],
        'transfer' => ['label' => 'Airport transfer',     'default_required' => false],
        'passport' => ['label' => 'Passport & identity',  'default_required' => true],
        'dietary'  => ['label' => 'Dietary requirements', 'default_required' => false],
        'requests' => ['label' => 'Special requests',     'default_required' => false],
        'waiver'   => ['label' => 'Waiver & indemnity',   'default_required' => true],
    ];
}

/** Merged config: catalog defaults overlaid with the `checkin_steps` setting (JSON). */
function checkin_config(): array {
    $overrides = [];
    $raw = setting('checkin_steps', '');
    if ($raw !== '') { $d = json_decode($raw, true); if (is_array($d)) $overrides = $d; }
    $out = [];
    foreach (checkin_step_catalog() as $key => $def) {
        $o = is_array($overrides[$key] ?? null) ? $overrides[$key] : [];
        $out[$key] = [
            'label'    => $def['label'],
            'enabled'  => array_key_exists('enabled', $o)  ? (bool)$o['enabled']  : true,
            'required' => array_key_exists('required', $o) ? (bool)$o['required'] : $def['default_required'],
        ];
    }
    return $out;
}

/** Enabled steps only, order preserved. */
function checkin_enabled_steps(): array {
    return array_filter(checkin_config(), fn($s) => $s['enabled']);
}

/** True once the migration is applied. Cached per-request. */
function checkin_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT require_checkin FROM holds LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

function checkin_required(array $hold): bool {
    return checkin_supported() && !empty($hold['require_checkin']);
}

function checkin_is_complete(array $hold): bool {
    return !empty($hold['checkin_completed_at']);
}

/** 'none' (not required) | 'pending' (required, unfinished) | 'complete'. */
function checkin_state(array $hold): string {
    if (!checkin_required($hold)) return 'none';
    return checkin_is_complete($hold) ? 'complete' : 'pending';
}

/** Badge descriptor for Frontdesk/Holds, or null when not required. */
function checkin_badge(array $hold): ?array {
    return match (checkin_state($hold)) {
        'complete' => ['label' => 'Checked in ✓',    'class' => 'ci-badge--done'],
        'pending'  => ['label' => 'Check-in pending', 'class' => 'ci-badge--pending'],
        default    => null,
    };
}

function fetch_checkin(int $holdId): ?array {
    if (!checkin_supported()) return null;
    try { $r = db_query('SELECT * FROM booking_checkin WHERE hold_id = :h', [':h' => $holdId])->fetch(); }
    catch (Throwable $e) { return null; }
    return $r ?: null;
}

function fetch_checkin_guests(int $holdId): array {
    if (!checkin_supported()) return [];
    try { return db_query('SELECT * FROM checkin_guests WHERE hold_id = :h ORDER BY is_lead DESC, id', [':h' => $holdId])->fetchAll(); }
    catch (Throwable $e) { return []; }
}

function checkin_lead_guest(int $holdId): ?array {
    foreach (fetch_checkin_guests($holdId) as $g) { if (!empty($g['is_lead'])) return $g; }
    return null;
}

/** 12-char stable label for the waiver terms the guest agreed to. */
function waiver_version(string $text): string {
    return substr(sha1(trim($text)), 0, 12);
}

/** Owner, or a manager who manages this booking's venue, may view passport docs. */
function can_view_guest_docs(int $holdId): bool {
    if (is_owner()) return true;
    return is_manager() && staff_can_hold($holdId);
}

/**
 * Is one step's *required* data present? $data = booking_checkin row (or null),
 * $lead = lead checkin_guests row (or null).
 */
function checkin_step_complete(string $key, ?array $data, ?array $lead): bool {
    $data = $data ?? [];
    $has = fn($k) => trim((string)($data[$k] ?? '')) !== '';
    switch ($key) {
        case 'arrival':  return $has('flight_number') && !empty($data['arrival_at']);
        case 'transfer':
            if (!array_key_exists('needs_transfer', $data) || $data['needs_transfer'] === null) return false;
            $wants = ($data['needs_transfer'] === true || $data['needs_transfer'] === 't' || $data['needs_transfer'] === '1' || $data['needs_transfer'] === 1);
            return $wants ? trim((string)($data['transfer_details'] ?? '')) !== '' : true;
        case 'passport':
            return $lead !== null
                && trim((string)($lead['passport_name'] ?? '')) !== ''
                && trim((string)($lead['passport_number'] ?? '')) !== ''
                && trim((string)($lead['passport_file_key'] ?? '')) !== '';
        case 'dietary':  return $has('dietary');
        case 'requests': return $has('special_requests');
        case 'waiver':   return !empty($data['waiver_signed_at']) && trim((string)($data['waiver_signed_name'] ?? '')) !== '';
        default:         return false;
    }
}

/** Enabled+required steps that are still incomplete. Empty array = ready to submit. */
function checkin_missing_steps(array $config, ?array $data, ?array $lead): array {
    $missing = [];
    foreach ($config as $key => $s) {
        if (empty($s['enabled']) || empty($s['required'])) continue;
        if (!checkin_step_complete($key, $data, $lead)) $missing[] = $key;
    }
    return $missing;
}
```

- [ ] **Step 2: Sanity check it parses**

```bash
php -l includes/checkin.php
```
Expected: `No syntax errors detected in includes/checkin.php`

- [ ] **Step 3: Commit**

```bash
git add includes/checkin.php
git commit -m "feat(checkin): helper module — config, state, validation, permissions"
```

---

### Task 3: Logic tests

**Files:**
- Create: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
// Pre-Check-in logic. Run: php tests/checkin_logic.php
// Pure helpers run always; DB/permission assertions SKIP until add_checkin.sql is applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/checkin.php';

session_init();

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Config defaults (no override set) ──────────────────────────────────────
$cfg = checkin_config();
check('config has 6 steps',            count($cfg) === 6);
check('passport enabled by default',   $cfg['passport']['enabled'] === true);
check('passport required by default',  $cfg['passport']['required'] === true);
check('waiver required by default',    $cfg['waiver']['required'] === true);
check('arrival optional by default',   $cfg['arrival']['required'] === false);
check('order: arrival before waiver',  array_search('arrival', array_keys($cfg), true) < array_search('waiver', array_keys($cfg), true));

// ── Config override merge (does not persist — restore after) ───────────────
$prev = setting('checkin_steps', '');
set_setting('checkin_steps', json_encode(['arrival' => ['required' => true], 'waiver' => ['enabled' => false]]));
$cfg2 = checkin_config();
check('override makes arrival required',  $cfg2['arrival']['required'] === true);
check('override disables waiver',         $cfg2['waiver']['enabled'] === false);
check('enabled steps exclude waiver',     !array_key_exists('waiver', checkin_enabled_steps()));
check('unspecified step keeps default',   $cfg2['passport']['required'] === true);
set_setting('checkin_steps', $prev); // restore

// ── waiver_version ─────────────────────────────────────────────────────────
check('waiver_version stable',   waiver_version('  terms  ') === waiver_version('terms'));
check('waiver_version differs',   waiver_version('a') !== waiver_version('b'));
check('waiver_version 12 chars',  strlen(waiver_version('x')) === 12);

// ── Badge / state (pure — synthetic holds) ─────────────────────────────────
// checkin_required() needs checkin_supported(); when unmigrated it returns
// 'none' for all, so assert badge shape only where supported.
if (checkin_supported()) {
    check('badge none when not required', checkin_badge(['require_checkin' => false]) === null);
    $p = checkin_badge(['require_checkin' => true, 'checkin_completed_at' => null]);
    check('badge pending label',  $p['class'] === 'ci-badge--pending');
    $d = checkin_badge(['require_checkin' => true, 'checkin_completed_at' => '2026-08-06 10:00:00']);
    check('badge done label',     $d['class'] === 'ci-badge--done');
} else {
    echo "SKIP  add_checkin.sql not applied — skipping state/badge/DB assertions\n";
}

// ── Per-step completeness (pure) ───────────────────────────────────────────
check('arrival incomplete when empty',   checkin_step_complete('arrival', [], null) === false);
check('arrival complete w/ flight+time',  checkin_step_complete('arrival', ['flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00'], null) === true);
check('transfer needs answer',            checkin_step_complete('transfer', [], null) === false);
check('transfer no = complete',           checkin_step_complete('transfer', ['needs_transfer' => false], null) === true);
check('transfer yes needs details',       checkin_step_complete('transfer', ['needs_transfer' => true], null) === false);
check('transfer yes + details = complete',checkin_step_complete('transfer', ['needs_transfer' => true, 'transfer_details' => 'JKIA 2pm'], null) === true);
check('passport needs name+num+file',     checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B']) === false);
check('passport complete w/ file',        checkin_step_complete('passport', [], ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'checkin/1/x.jpg']) === true);
check('waiver needs signature',           checkin_step_complete('waiver', ['waiver_signed_name' => 'A'], null) === false);
check('waiver complete when signed',      checkin_step_complete('waiver', ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00'], null) === true);

// ── Missing-steps aggregation ──────────────────────────────────────────────
$cfgReq = ['passport' => ['enabled' => true, 'required' => true], 'waiver' => ['enabled' => true, 'required' => true], 'dietary' => ['enabled' => true, 'required' => false]];
check('missing lists passport+waiver',   checkin_missing_steps($cfgReq, [], null) === ['passport', 'waiver']);
$fullLead = ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'k'];
$fullData = ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06'];
check('missing empty when all done',     checkin_missing_steps($cfgReq, $fullData, $fullLead) === []);
check('optional step never missing',     !in_array('dietary', checkin_missing_steps($cfgReq, [], null), true));

// ── can_view_guest_docs (needs role fixtures + a hold; SKIP if unmigrated) ──
if (checkin_supported()) {
    $vrows = db()->query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchAll(PDO::FETCH_COLUMN);
    $unit  = $vrows ? db()->query('SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id WHERE r.venue_id='.(int)$vrows[0].' LIMIT 1')->fetchColumn() : null;
    if ($vrows && $unit) {
        $V1 = (int)$vrows[0];
        db_query("DELETE FROM admin_users WHERE name LIKE 'ZZ Chk %'");
        $mk = function(string $role, ?string $email = null) {
            db_query("INSERT INTO admin_users (name, role, email, access_code, is_active) VALUES (:n,:r,:e,:c,TRUE)",
                [':n' => 'ZZ Chk '.$role, ':r' => $role, ':e' => $email, ':c' => gen_staff_code()]);
            return (int)db()->lastInsertId();
        };
        $owner = $mk('owner');
        $mgrA  = $mk('manager', 'zz-chk-mgra@example.com');
        $mgrB  = $mk('manager', 'zz-chk-mgrb@example.com');
        $staff = $mk('staff');
        db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $mgrA, ':v' => $V1]);
        db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:a,:v)', [':a' => $staff, ':v' => $V1]);
        db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at) VALUES (:u,'2031-07-01','2031-07-03','ZZ Chk Guest','zz-chk@example.com','confirmed',NOW())", [':u' => (int)$unit]);
        $hold = (int)db()->lastInsertId();

        $_SESSION['admin_id'] = $owner;  check('owner can view docs',        can_view_guest_docs($hold) === true);
        $_SESSION['admin_id'] = $mgrA;   check('venue manager can view docs', can_view_guest_docs($hold) === true);
        $_SESSION['admin_id'] = $mgrB;   check('non-venue manager cannot',    can_view_guest_docs($hold) === false);
        $_SESSION['admin_id'] = $staff;  check('venue staff cannot view docs', can_view_guest_docs($hold) === false);

        // fetch_checkin round-trip
        db_query("INSERT INTO booking_checkin (hold_id, dietary) VALUES (:h,'none') ON CONFLICT (hold_id) DO UPDATE SET dietary='none'", [':h' => $hold]);
        check('fetch_checkin returns row', (fetch_checkin($hold)['dietary'] ?? '') === 'none');

        db_query('DELETE FROM holds WHERE id = :h', [':h' => $hold]);
        db_query("DELETE FROM admin_users WHERE id IN (:a,:b,:c,:d)", [':a' => $owner, ':b' => $mgrA, ':c' => $mgrB, ':d' => $staff]);
        unset($_SESSION['admin_id']);
    } else {
        echo "SKIP  no venue/unit seeded — skipping permission assertions\n";
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it — pure helpers must pass even before migration**

Run: `php tests/checkin_logic.php`
Expected: every pure assertion prints `PASS`; DB/permission blocks may print `SKIP` if the migration/seed isn't present; final line `ALL PASS`, exit 0. (If Task 1 was applied, the badge + permission blocks run and pass too.)

- [ ] **Step 3: Commit**

```bash
git add tests/checkin_logic.php
git commit -m "test(checkin): logic — config merge, step completeness, doc permissions"
```

---

### Task 4: "Require check-in" on New Booking

**Files:**
- Modify: `admin/hold-new.php`

- [ ] **Step 1: Load the helper + read the global default**

In `admin/hold-new.php`, after the existing `require_once` lines (top of file), add:
```php
require_once __DIR__ . '/../includes/checkin.php';
```
Then, **immediately after `require_login(); require_owner();`** (BEFORE the POST-handling block — the create branch below references `$want_checkin`, so it must be defined first), add:
```php
$checkin_default = checkin_supported() && setting('checkin_required_default', '0') === '1';
$want_checkin    = ($_SERVER['REQUEST_METHOD'] === 'POST') ? isset($_POST['require_checkin']) : $checkin_default;
```

- [ ] **Step 2: Persist the flag after the hold is created**

In the success branch, immediately after `$hold_id = create_hold_with_block(...)` succeeds (inside the `if (!$error)` that follows the try), add:
```php
if (checkin_supported() && $want_checkin) {
    db_query('UPDATE holds SET require_checkin = TRUE WHERE id = :id', [':id' => $hold_id]);
}
```

- [ ] **Step 3: Add the checkbox to the form**

After the guest-email `<div>` block in `admin/hold-new.php` (inside `.detail-grid`), add:
```php
        <?php if (checkin_supported()): ?>
        <div style="grid-column:1/-1">
          <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
            <input type="checkbox" name="require_checkin" value="1" <?= $want_checkin ? 'checked' : '' ?>>
            Require this guest to complete Pre-Check-in before using the portal
          </label>
        </div>
        <?php endif; ?>
```

- [ ] **Step 4: Verify manually**

Run the dev server (`php -S localhost:8765 router.php`), log in to `/admin/`, open `/admin/hold-new.php`, create a booking with the box ticked, then:
```bash
php -r 'require "includes/db.php"; echo db_query("SELECT require_checkin FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ? "REQUIRED\n" : "not\n";'
```
Expected: `REQUIRED`.

- [ ] **Step 5: Commit**

```bash
git add admin/hold-new.php
git commit -m "feat(checkin): require-check-in checkbox on New Booking"
```

---

### Task 5: Owner config page `admin/checkin-settings.php`

**Files:**
- Create: `admin/checkin-settings.php`
- Modify: `admin/settings.php` (add a link)

- [ ] **Step 1: Write the config page**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_login();
require_owner();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $steps = [];
    foreach (array_keys(checkin_step_catalog()) as $key) {
        $steps[$key] = [
            'enabled'  => isset($_POST['enabled'][$key]),
            'required' => isset($_POST['required'][$key]),
        ];
    }
    set_setting('checkin_steps', json_encode($steps));
    set_setting('checkin_required_default', isset($_POST['required_default']) ? '1' : '0');
    set_setting('checkin_waiver_text', trim((string)($_POST['waiver_text'] ?? '')));
    set_setting('checkin_welcome', trim((string)($_POST['welcome'] ?? '')));
    audit_log('checkin.config_change', 'settings', 0, '');
    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Check-in settings saved.'];
    header('Location: /admin/checkin-settings.php'); exit;
}
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']['msg']; unset($_SESSION['hold_flash']); }

$cfg      = checkin_config();
$default  = setting('checkin_required_default', '0') === '1';
$waiver   = setting('checkin_waiver_text', '');
$welcome  = setting('checkin_welcome', '');

$pageTitle  = 'Check-in Settings';
$activeMenu = 'settings';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Pre-Check-in Settings</h1></div>
<?php if (!checkin_supported()): ?>
<div class="alert alert--error">Run the <code>add_checkin.sql</code> migration (Settings → Migrate) to enable check-in.</div>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert--success is-flash"><?= e($flash) ?></div><?php endif; ?>

<form method="POST" action="/admin/checkin-settings.php">
  <?= csrf_field() ?>
  <div class="card" style="margin-bottom:16px"><div class="card__body">
    <label style="display:flex;align-items:center;gap:8px;font-weight:600">
      <input type="checkbox" name="required_default" value="1" <?= $default ? 'checked' : '' ?>>
      Require check-in by default on new bookings
    </label>
  </div></div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Steps</span></div>
    <div class="card__body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Step</th><th style="text-align:center">Shown</th><th style="text-align:center">Required</th></tr></thead>
        <tbody>
        <?php foreach ($cfg as $key => $s): ?>
          <tr>
            <td><?= e($s['label']) ?></td>
            <td style="text-align:center"><input type="checkbox" name="enabled[<?= e($key) ?>]" value="1" <?= $s['enabled'] ? 'checked' : '' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="required[<?= e($key) ?>]" value="1" <?= $s['required'] ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Waiver / indemnity terms</span></div>
    <div class="card__body">
      <textarea name="waiver_text" rows="8" style="width:100%;font-family:inherit;padding:10px" placeholder="Indemnity and insurance waiver the guest agrees to…"><?= e($waiver) ?></textarea>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Welcome copy (optional)</span></div>
    <div class="card__body">
      <textarea name="welcome" rows="3" style="width:100%;font-family:inherit;padding:10px" placeholder="Shown on the check-in landing screen."><?= e($welcome) ?></textarea>
    </div>
  </div>

  <button type="submit" class="btn-primary">Save settings</button>
</form>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Link it from Settings**

In `admin/settings.php`, add a link near the top of the page body (after the `page-header`), e.g.:
```php
<p style="margin:0 0 16px"><a href="/admin/checkin-settings.php" class="btn-outline btn-sm">Pre-Check-in settings →</a></p>
```

- [ ] **Step 3: Verify manually**

Load `/admin/checkin-settings.php` as owner, untick "Special requests" → Shown, tick "Arrival" → Required, save. Then:
```bash
php -r 'require "includes/db.php"; require "includes/checkin.php"; $c=checkin_config(); echo ($c["arrival"]["required"]?"arrival REQ":"arrival opt"), " / ", ($c["requests"]["enabled"]?"requests shown":"requests hidden"), "\n";'
```
Expected: `arrival REQ / requests hidden`.

- [ ] **Step 4: Commit**

```bash
git add admin/checkin-settings.php admin/settings.php
git commit -m "feat(checkin): owner config page — step toggles, default, waiver text"
```

---

### Task 6: Admin badges on Frontdesk + Holds

**Files:**
- Modify: `admin/assets/admin.css` (badge styles), `admin/frontdesk.php`, `admin/holds.php`
- Note: admin pages load `admin/assets/admin.css` (confirmed in `admin/_layout.php`). Put the `.ci-badge*` rules there so Frontdesk, Holds, and the Check-in tab all pick them up.

- [ ] **Step 1: Add badge CSS**

Append to `admin/assets/admin.css`:
```css
.ci-badge{font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;display:inline-block}
.ci-badge--done{background:#dcfce7;color:#166534}
.ci-badge--pending{background:#fef3c7;color:#92400e}
```

- [ ] **Step 2: Frontdesk badge**

In `admin/frontdesk.php`, ensure the helper is loaded (add `require_once __DIR__ . '/../includes/checkin.php';` near the other requires). Inside the arriving-card `.fd-card__badges` block (around line 89-91), after the existing request/unread badges, add:
```php
          <?php $__ci = checkin_badge($r); if ($__ci): ?><span class="ci-badge <?= e($__ci['class']) ?>"><?= e($__ci['label']) ?></span><?php endif; ?>
```
(The card row `$r` is a `holds` join and includes `require_checkin`/`checkin_completed_at` post-migration; `checkin_badge()` returns null pre-migration or when not required.)

- [ ] **Step 3: Holds list badge + filter**

In `admin/holds.php`: load the helper (`require_once __DIR__ . '/../includes/checkin.php';`). In the results table, add a header `<th>Check-in</th>` and, in the row loop, a cell:
```php
          <td><?php $__ci = checkin_badge($hold); if ($__ci): ?><span class="ci-badge <?= e($__ci['class']) ?>"><?= e($__ci['label']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
```
Add a filter option: in the status `<select>`, a new `<option value="checkin_pending">Check-in pending</option>`, and in the `switch ($status_filter)` add:
```php
    case 'checkin_pending': $conditions[] = "h.require_checkin = TRUE AND h.checkin_completed_at IS NULL AND h.status IN ('pending','confirmed')"; break;
```
Guard the whole filter option behind `checkin_supported()` so it only appears post-migration.

- [ ] **Step 4: Verify manually**

With a booking that requires check-in (Task 4), load `/admin/holds.php` → the row shows an amber "Check-in pending" badge; `/admin/frontdesk.php` (set the arrival to today) shows the same on the card. Selecting the "Check-in pending" filter lists only unfinished ones.

- [ ] **Step 5: Commit**

```bash
git add admin/assets/admin.css admin/frontdesk.php admin/holds.php
git commit -m "feat(checkin): pending/done badges on Frontdesk + Holds, pending filter"
```

---

# Phase 2 — Guest flow (gate + wizard, minus file upload)

Delivers the guest-facing check-in for every step except the passport *scan* (Phase 3). Passport text fields work now; the scan field is wired but its upload endpoint lands in Phase 3.

### Task 7: Gate the portal + route the check-in view

**Files:**
- Modify: `booking.php`

- [ ] **Step 1: Load the helper**

In `booking.php`, after the existing `require_once` block (top), add:
```php
require_once __DIR__ . '/includes/checkin.php';
```

- [ ] **Step 2: Compute gate state after `$hold` is resolved**

After the cancel-handling block (just before `$status = $hold['status'] ?? '';`), add:
```php
$checkin_gate = $hold && checkin_required($hold) && !checkin_is_complete($hold);
```

- [ ] **Step 3: Allow the `checkin` view and force it when gated**

Replace the `$view = ...` line with:
```php
$view = in_array($_GET['view'] ?? '', ['home','activities','messages','checkin'], true) ? $_GET['view'] : 'home';
// When check-in is outstanding, the portal is a hard gate: only the check-in
// flow and the message thread (escape hatch) are reachable.
if ($checkin_gate && !in_array($view, ['checkin','messages'], true)) $view = 'checkin';
```

- [ ] **Step 4: Render the check-in view**

In the view include block (where `home`/`activities`/`messages` are dispatched), add a branch:
```php
      <?php elseif ($view === 'checkin'): ?>
        <?php include __DIR__ . '/includes/app/checkin.php'; ?>
```
Also, so the completed state is greeted properly, extend the topbar titles map (`$__titles`) with `'checkin' => 'Check-in'`.

- [ ] **Step 5: Verify manually**

Open the portal link (`/booking.php?ref=…`) for a require-checkin booking. Expected: it lands on the check-in view (Task 8) regardless of `?view=home`. A booking that does *not* require check-in still opens Home normally. Pre-migration, nothing changes.

- [ ] **Step 6: Commit**

```bash
git add booking.php
git commit -m "feat(checkin): hard-gate the portal until check-in is complete"
```

---

### Task 8: The wizard view + step partial

**Files:**
- Create: `includes/app/checkin.php`
- Modify: `css/portal-app.css` (wizard styles)

Design note: one `<form>` containing all enabled steps as stacked `.ci-step` panels; `js/checkin-wizard.js` (Task 10) shows one at a time. Server-side, `api/checkin-save.php` (Task 9) accepts the whole form on "submit" and per-step saves on "Next". The form always renders the *current saved values* so it is resumable and editable-until-arrival.

- [ ] **Step 1: Write the view**

```php
<?php
/** Guest check-in wizard. Expects $hold, $ref, $holdId (from booking.php). */
declare(strict_types=1);
$holdId  = (int)$hold['id'];
$cfg     = checkin_enabled_steps();
$data    = fetch_checkin($holdId) ?? [];
$lead    = checkin_lead_guest($holdId) ?? [];
$welcome = setting('checkin_welcome', '');
$waiver  = setting('checkin_waiver_text', '');
$done    = checkin_is_complete($hold);
$val     = fn($k, $src = null) => e((string)(($src ?? $data)[$k] ?? ''));
$arrDate = !empty($data['arrival_at']) ? date('Y-m-d\TH:i', strtotime((string)$data['arrival_at'])) : '';
?>
<link rel="stylesheet" href="/css/portal-app.css?v=<?= @filemtime(__DIR__ . '/../../css/portal-app.css') ?: time() ?>">

<?php if ($done): ?>
<div class="pa-card" style="padding:20px;text-align:center">
  <div style="font-size:40px">&#10003;</div>
  <h2 style="font-family:'Cormorant Garamond',serif;font-weight:400;margin:8px 0">You're checked in</h2>
  <p style="color:var(--pa-muted)">You can update your details until your arrival day.</p>
  <a class="pa-btn" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay →</a>
</div>
<?php endif; ?>

<form id="ciForm" class="ci-wizard<?= $done ? ' ci-done' : '' ?>" method="post" action="/api/checkin-save.php" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="do" value="save">

  <?php if ($welcome !== '' && !$done): ?><p class="ci-welcome"><?= e($welcome) ?></p><?php endif; ?>
  <div class="ci-progress"><div class="ci-progress__bar" id="ciBar"></div></div>

  <?php $i = 0; $n = count($cfg); foreach ($cfg as $key => $s): $i++; ?>
  <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>" hidden>
    <div class="ci-step__h"><span class="ci-step__num">Step <?= $i ?> of <?= $n ?></span><h3><?= e($s['label']) ?><?= $s['required'] ? ' <span class="ci-req">*</span>' : '' ?></h3></div>

    <?php if ($key === 'arrival'): ?>
      <label class="ci-l">Airport of arrival</label>
      <input class="ci-in" name="arrival_airport" value="<?= $val('arrival_airport') ?>" placeholder="e.g. Moi Intl (MBA)">
      <label class="ci-l">Flight number</label>
      <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
      <label class="ci-l">Arrival date &amp; time</label>
      <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">

    <?php elseif ($key === 'transfer'): ?>
      <label class="ci-l">Would you like us to arrange your airport transfer?</label>
      <?php $nt = $data['needs_transfer'] ?? null; $ntYes = ($nt === true || $nt === 't' || $nt === '1'); $ntNo = ($nt === false || $nt === 'f' || $nt === '0'); ?>
      <label class="ci-radio"><input type="radio" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
      <label class="ci-radio"><input type="radio" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I'll make my own way</label>
      <label class="ci-l">Transfer details (pickup point, pax, luggage)</label>
      <textarea class="ci-in" name="transfer_details" rows="3"><?= $val('transfer_details') ?></textarea>

    <?php elseif ($key === 'passport'): ?>
      <label class="ci-l">Full name (as on passport)</label>
      <input class="ci-in" name="passport_name" value="<?= $val('passport_name', $lead) ?>">
      <label class="ci-l">Passport number</label>
      <input class="ci-in" name="passport_number" value="<?= $val('passport_number', $lead) ?>">
      <label class="ci-l">Nationality</label>
      <input class="ci-in" name="nationality" value="<?= $val('nationality', $lead) ?>">
      <label class="ci-l">Passport expiry</label>
      <input class="ci-in" type="date" name="passport_expiry" value="<?= $val('passport_expiry', $lead) ?>">
      <label class="ci-l">Passport scan (photo or PDF)</label>
      <div class="ci-upload" data-has="<?= !empty($lead['passport_file_key']) ? '1' : '0' ?>">
        <input type="file" id="ciPassportFile" accept="image/jpeg,image/png,application/pdf">
        <span class="ci-upload__state"><?= !empty($lead['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
      </div>

    <?php elseif ($key === 'dietary'): ?>
      <label class="ci-l">Dietary requirements / allergies</label>
      <textarea class="ci-in" name="dietary" rows="4"><?= $val('dietary') ?></textarea>

    <?php elseif ($key === 'requests'): ?>
      <label class="ci-l">Anything to make your stay special?</label>
      <textarea class="ci-in" name="special_requests" rows="4" placeholder="Birthday surprise, a bottle of wine in the room…"><?= $val('special_requests') ?></textarea>

    <?php elseif ($key === 'waiver'): ?>
      <div class="ci-waiver"><?= nl2br(e($waiver !== '' ? $waiver : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.')) ?></div>
      <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= !empty($data['waiver_signed_at']) ? 'checked' : '' ?>> I have read and agree</label>
      <label class="ci-l">Type your full name to sign</label>
      <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name') ?>">
    <?php endif; ?>

    <div class="ci-nav">
      <button type="button" class="pa-btn pa-btn--ghost ci-back" <?= $i === 1 ? 'style="visibility:hidden"' : '' ?>>← Back</button>
      <?php if ($i < $n): ?>
        <button type="button" class="pa-btn ci-next">Save &amp; continue →</button>
      <?php else: ?>
        <button type="submit" class="pa-btn ci-submit" name="do" value="submit">Complete check-in</button>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <p class="ci-help"><a href="/booking.php?ref=<?= e($ref) ?>&view=messages">Message the team</a> if you need help.</p>
</form>
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
```

- [ ] **Step 2: Add wizard + upload styles to `css/portal-app.css`**

Append:
```css
.ci-wizard{max-width:520px;margin:0 auto}
.ci-welcome{color:var(--pa-muted);line-height:1.6;margin:0 0 16px}
.ci-progress{height:4px;background:#e7ded7;border-radius:999px;margin:0 0 20px;overflow:hidden}
.ci-progress__bar{height:100%;width:0;background:var(--teal,#1E5C6B);transition:width .25s}
.ci-step__num{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9CA3AF}
.ci-step__h h3{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:24px;margin:4px 0 18px}
.ci-req{color:#c0392b}
.ci-l{display:block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;margin:14px 0 6px}
.ci-in{width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:16px;font-family:inherit;box-sizing:border-box}
.ci-radio{display:flex;align-items:center;gap:8px;margin:8px 0;font-size:15px}
.ci-waiver{max-height:200px;overflow:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;font-size:14px;line-height:1.6;margin-bottom:12px}
.ci-upload{display:flex;align-items:center;gap:10px;margin-top:6px}
.ci-upload__state{font-size:13px;color:#6b7280}
.ci-nav{display:flex;justify-content:space-between;gap:12px;margin-top:24px}
.ci-help{text-align:center;margin-top:28px;font-size:13px;color:#9ca3af}
.pa-btn--ghost{background:transparent;color:var(--teal,#1E5C6B);border:1px solid #d1d5db}
```
(If `.pa-btn` variants already exist, reconcile rather than duplicate.)

- [ ] **Step 3: Commit**

```bash
git add includes/app/checkin.php css/portal-app.css
git commit -m "feat(checkin): guest wizard view + styles"
```

---

### Task 9: Save/submit endpoint `api/checkin-save.php`

**Files:**
- Create: `api/checkin-save.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/mail.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$ref    = trim((string)($_POST['ref'] ?? ''));
$holdId = verify_guest_ref($ref);
if (!$holdId) { http_response_code(403); exit('Invalid booking reference.'); }
verify_csrf();

$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { header('Location: /booking.php?ref=' . urlencode($ref)); exit; }
// Editable until the arrival day; locked afterward.
if (checkin_is_complete($hold) && strtotime((string)$hold['check_in']) < strtotime('today')) {
    header('Location: /booking.php?ref=' . urlencode($ref) . '&view=home'); exit;
}

// ── Upsert booking-level fields ────────────────────────────────────────────
$s = fn($k) => (($v = trim((string)($_POST[$k] ?? ''))) === '') ? null : $v;
$arrivalAt = ($_POST['arrival_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['arrival_at'])) : null;
$needsTransfer = array_key_exists('needs_transfer', $_POST) && $_POST['needs_transfer'] !== ''
    ? ($_POST['needs_transfer'] === '1') : null;

db_query(
    "INSERT INTO booking_checkin (hold_id, arrival_airport, flight_number, arrival_at, needs_transfer, transfer_details, dietary, special_requests, updated_at)
     VALUES (:h,:aa,:fn,:at,:nt,:td,:di,:sr, now())
     ON CONFLICT (hold_id) DO UPDATE SET
       arrival_airport=:aa, flight_number=:fn, arrival_at=:at, needs_transfer=:nt,
       transfer_details=:td, dietary=:di, special_requests=:sr, updated_at=now()",
    [':h'=>$holdId, ':aa'=>$s('arrival_airport'), ':fn'=>$s('flight_number'), ':at'=>$arrivalAt,
     ':nt'=>$needsTransfer, ':td'=>$s('transfer_details'), ':di'=>$s('dietary'), ':sr'=>$s('special_requests')]
);

// ── Upsert lead guest identity (file key handled by the upload endpoint) ───
$lead = checkin_lead_guest($holdId);
if ($lead) {
    db_query("UPDATE checkin_guests SET passport_name=:n, passport_number=:num, nationality=:nat, passport_expiry=:exp WHERE id=:id",
        [':n'=>$s('passport_name'), ':num'=>$s('passport_number'), ':nat'=>$s('nationality'),
         ':exp'=>$s('passport_expiry'), ':id'=>(int)$lead['id']]);
} else {
    db_query("INSERT INTO checkin_guests (hold_id, is_lead, passport_name, passport_number, nationality, passport_expiry) VALUES (:h,TRUE,:n,:num,:nat,:exp)",
        [':h'=>$holdId, ':n'=>$s('passport_name'), ':num'=>$s('passport_number'), ':nat'=>$s('nationality'), ':exp'=>$s('passport_expiry')]);
}

// ── Waiver signature (only when they tick + type a name) ───────────────────
if (!empty($_POST['waiver_agree']) && $s('waiver_signed_name')) {
    db_query("UPDATE booking_checkin SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip, waiver_version=:v WHERE hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version(setting('checkin_waiver_text','')), ':h'=>$holdId]);
}

$do = $_POST['do'] ?? 'save';

if ($do === 'submit') {
    $data = fetch_checkin($holdId);
    $lead = checkin_lead_guest($holdId);
    $missing = checkin_missing_steps(checkin_config(), $data, $lead);
    if ($missing) {
        $_SESSION['ci_error'] = 'Please complete: ' . implode(', ', array_map(fn($k) => checkin_step_catalog()[$k]['label'] ?? $k, $missing));
        header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
    }
    db_query("UPDATE holds SET checkin_completed_at = now() WHERE id = :h AND checkin_completed_at IS NULL", [':h'=>$holdId]);
    db_query("UPDATE booking_checkin SET submitted_at = now() WHERE hold_id = :h", [':h'=>$holdId]);
    audit_log('checkin.submit', 'hold', $holdId, (string)$hold['guest_name']);
    try { send_checkin_completed(fetch_hold_for_guest($holdId), fetch_checkin($holdId)); } catch (Throwable $e) { error_log('[checkin] mail: ' . $e->getMessage()); }
    header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
}

// AJAX per-step save → 204; full-form fallback → back to the wizard.
if (($_POST['ajax'] ?? '') === '1') { http_response_code(204); exit; }
header('Location: /booking.php?ref=' . urlencode($ref) . '&view=checkin'); exit;
```

- [ ] **Step 2: Add the guarded mail sender to `includes/mail.php`**

Append a wrapper that no-ops when mail is unconfigured (mirrors the existing `send_*` functions — read `send_hold_notification()` for the env-guard + `send_resend()` call shape and copy it):
```php
/** Best-effort front-desk notice on check-in completion. No-ops if mail is unconfigured. */
function send_checkin_completed(array $hold, ?array $data): void {
    $env = parse_env();
    $key = $env['RESEND_API_KEY'] ?? '';
    $from = $env['MAIL_FROM'] ?? '';
    $to  = $env['ADMIN_NOTIFY_EMAIL'] ?? $from;
    if ($key === '' || $from === '' || $to === '') return;     // not configured → silent
    $lines = [
        'Guest: ' . ($hold['guest_name'] ?? ''),
        'Room: '  . ($hold['room_name'] ?? ''),
        'Flight: ' . trim((string)($data['flight_number'] ?? '') . ' ' . (string)($data['arrival_airport'] ?? '')),
        'Arrival: ' . (string)($data['arrival_at'] ?? ''),
        'Transfer: ' . (($data['needs_transfer'] ?? null) ? ('yes — ' . (string)($data['transfer_details'] ?? '')) : 'no'),
        'Dietary: ' . (string)($data['dietary'] ?? ''),
        'Requests: ' . (string)($data['special_requests'] ?? ''),
    ];
    $body = "Guest completed pre-check-in.\n\n" . implode("\n", $lines)
          . "\n\n" . site_url('/admin/booking.php?hold=' . (int)$hold['id'] . '&tab=checkin');
    try { send_resend($to, 'Pre-check-in complete — ' . ($hold['guest_name'] ?? ''), $body, $from, $from, $key); }
    catch (Throwable $e) { error_log('[checkin] send_resend: ' . $e->getMessage()); }
}
```

- [ ] **Step 3: Show the submit error + success on the wizard**

In `includes/app/checkin.php`, near the top of the output (after the `$done` block), add:
```php
<?php if (!empty($_SESSION['ci_error'])): ?>
<div class="bk-lookup-error" style="max-width:520px"><?= e($_SESSION['ci_error']) ?></div>
<?php unset($_SESSION['ci_error']); endif; ?>
```

- [ ] **Step 4: Verify manually**

For a require-checkin booking, fill the wizard's text steps, tick the waiver + type a name, click "Complete check-in". Expected: redirect back showing "You're checked in", and the portal now opens Home. Confirm:
```bash
php -r 'require "includes/db.php"; echo db_query("SELECT checkin_completed_at FROM holds WHERE require_checkin AND checkin_completed_at IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn() ?: "none", "\n";'
```
Expected: a timestamp (not `none`). Try submitting with the waiver unticked → expect the "Please complete: Waiver & indemnity" error and no completion.

- [ ] **Step 5: Commit**

```bash
git add api/checkin-save.php includes/mail.php includes/app/checkin.php
git commit -m "feat(checkin): save/submit endpoint + validation + best-effort notice"
```

---

### Task 10: Wizard client JS

**Files:**
- Create: `js/checkin-wizard.js`

- [ ] **Step 1: Write the step navigation + async passport upload**

```javascript
(function () {
  var form = document.getElementById('ciForm');
  if (!form || form.classList.contains('ci-done')) {
    // Completed view: still allow re-editing if steps are present.
  }
  var steps = Array.prototype.slice.call(document.querySelectorAll('.ci-step'));
  if (!steps.length) return;
  var bar = document.getElementById('ciBar');
  var cur = 0;

  function show(i) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, idx) { s.hidden = idx !== cur; });
    if (bar) bar.style.width = Math.round(((cur + 1) / steps.length) * 100) + '%';
    window.scrollTo(0, 0);
  }

  // Save the current step's fields via AJAX, then advance.
  function saveThen(next) {
    var fd = new FormData(form);
    fd.set('do', 'save'); fd.set('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function () { next(); })
      .catch(function () { next(); }); // save is best-effort; never trap the guest
  }

  form.addEventListener('click', function (e) {
    if (e.target.classList.contains('ci-next')) { e.preventDefault(); saveThen(function () { show(cur + 1); }); }
    if (e.target.classList.contains('ci-back')) { e.preventDefault(); show(cur - 1); }
  });

  // Async passport upload (Phase 3 endpoint). Shows uploaded state; no public URL.
  var fileInput = document.getElementById('ciPassportFile');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) return;
      var wrap = fileInput.closest('.ci-upload');
      var state = wrap.querySelector('.ci-upload__state');
      state.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('ref', form.querySelector('input[name=ref]').value);
      fd.append('csrf_token', form.querySelector('input[name=csrf_token]').value);
      fd.append('passport', f);
      fetch('/api/checkin-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function () { state.innerHTML = 'Uploaded ✓'; wrap.setAttribute('data-has', '1'); })
        .catch(function () { state.textContent = 'Upload failed — try again'; });
    });
  }

  show(0);
})();
```
(Confirm the CSRF hidden field name emitted by `csrf_field()` — if it isn't `csrf_token`, match it here and in the upload endpoint.)

- [ ] **Step 2: Verify manually**

Reload the wizard: only step 1 shows, the progress bar advances, Back/Next move between steps, and the final step shows "Complete check-in". (Upload will error until Task 12 — that's expected here.)

- [ ] **Step 3: Commit**

```bash
git add js/checkin-wizard.js
git commit -m "feat(checkin): wizard step navigation + async upload client"
```

---

# Phase 3 — Files, privacy, admin view

Delivers the passport scan pipeline (private storage + proxy) and the admin Check-in tab.

### Task 11: Generalize storage + signed GET

**Files:**
- Modify: `includes/storage.php`

- [ ] **Step 1: Read the current signing code**

Read `includes/storage.php` fully — `_r2_put()` already builds SigV4 for PUT. You will (a) let callers pass a content-type + folder, and (b) add a signed **GET** URL builder reusing the same scope/signing shape.

- [ ] **Step 2: Generalize `storage_put()`**

Change the signature to `storage_put(string $local_path, string $filename, string $content_type = 'image/jpeg', string $folder = 'rooms'): string|false` and thread `$content_type` into `_r2_put()` (replace the hardcoded `$ct = 'image/jpeg'`), and use `$folder` for the local fallback path. Existing callers keep working via defaults.

- [ ] **Step 3: Add a signed GET URL builder**

```php
/** Presigned GET URL (default 5 min) for a private R2 object key. '' if R2 unconfigured. */
function storage_signed_get_url(string $key, int $ttl = 300): string {
    $env = parse_env();
    if (!_r2_configured($env)) return '';   // local fallback: served by admin proxy reading disk
    $account_id = $env['R2_ACCOUNT_ID'];
    $bucket     = $env['R2_BUCKET'] ?? 'tribalsand-images';
    $access_key = $env['R2_ACCESS_KEY'];
    $secret_key = $env['R2_SECRET_KEY'];
    $host   = "{$account_id}.r2.cloudflarestorage.com";
    $dt     = gmdate('Ymd\THis\Z');
    $d      = gmdate('Ymd');
    $region = 'auto'; $service = 's3';
    $scope  = "{$d}/{$region}/{$service}/aws4_request";
    $q = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$access_key}/{$scope}",
        'X-Amz-Date'          => $dt,
        'X-Amz-Expires'       => (string)$ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($q);
    $canon_query = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $canonical_request = "GET\n/{$bucket}/{$key}\n{$canon_query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash('sha256', $canonical_request);
    $k_date=hash_hmac('sha256',$d,"AWS4{$secret_key}",true);
    $k_region=hash_hmac('sha256',$region,$k_date,true);
    $k_service=hash_hmac('sha256',$service,$k_region,true);
    $k_signing=hash_hmac('sha256','aws4_request',$k_service,true);
    $sig=hash_hmac('sha256',$string_to_sign,$k_signing);
    return "https://{$host}/{$bucket}/{$key}?{$canon_query}&X-Amz-Signature={$sig}";
}

/** Absolute local path for a key stored via the filesystem fallback. */
function storage_local_path(string $key): string {
    return __DIR__ . '/../assets/img/' . $key;
}
```

- [ ] **Step 4: Sanity check**

```bash
php -l includes/storage.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/storage.php
git commit -m "feat(storage): content-type/folder params + presigned GET for private files"
```

---

### Task 12: Passport upload endpoint

**Files:**
- Create: `api/checkin-upload.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$ref    = trim((string)($_POST['ref'] ?? ''));
$holdId = verify_guest_ref($ref);
if (!$holdId) { http_response_code(403); echo json_encode(['error' => 'bad ref']); exit; }
verify_csrf();

$hold = fetch_hold_for_guest($holdId);
if (!$hold || !checkin_required($hold)) { http_response_code(403); echo json_encode(['error' => 'not applicable']); exit; }

$f = $_FILES['passport'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'no file']); exit; }
if (($f['size'] ?? 0) > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'too large']); exit; }

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['error' => 'type not allowed']); exit; }

$key = 'checkin/' . $holdId . '/' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
$stored = storage_put($f['tmp_name'], $key, $mime, 'checkin');   // R2 returns URL/key; local returns relative key
if ($stored === false) { http_response_code(500); echo json_encode(['error' => 'store failed']); exit; }

// Store the KEY (not a public URL) so viewing always goes through the admin proxy.
$lead = checkin_lead_guest($holdId);
if ($lead) {
    // Remove the previous file if one existed.
    if (!empty($lead['passport_file_key'])) { try { storage_delete($lead['passport_file_key']); } catch (Throwable $e) {} }
    db_query('UPDATE checkin_guests SET passport_file_key = :k WHERE id = :id', [':k' => $key, ':id' => (int)$lead['id']]);
} else {
    db_query('INSERT INTO checkin_guests (hold_id, is_lead, passport_file_key) VALUES (:h, TRUE, :k)', [':h' => $holdId, ':k' => $key]);
}
echo json_encode(['ok' => true]);
```

- [ ] **Step 2: Verify manually**

In the wizard's passport step, choose a JPG/PNG/PDF ≤8 MB. Expected: state flips to "Uploaded ✓". Confirm the key (not a URL) is stored:
```bash
php -r 'require "includes/db.php"; echo db_query("SELECT passport_file_key FROM checkin_guests ORDER BY id DESC LIMIT 1")->fetchColumn(), "\n";'
```
Expected: `checkin/<holdId>/<hex>.jpg` (starts with `checkin/`, not `http`). Try a `.txt` → expect a 400 JSON error and no DB change.

- [ ] **Step 3: Commit**

```bash
git add api/checkin-upload.php
git commit -m "feat(checkin): private passport upload endpoint (typed + size-limited)"
```

---

### Task 13: Admin private file proxy

**Files:**
- Create: `admin/checkin-file.php`

- [ ] **Step 1: Write the proxy**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$holdId  = (int)($_GET['hold'] ?? 0);
$guestId = (int)($_GET['guest'] ?? 0);
if (!$holdId || !can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }

$row = db_query('SELECT passport_file_key FROM checkin_guests WHERE id = :g AND hold_id = :h', [':g' => $guestId, ':h' => $holdId])->fetch();
$key = $row['passport_file_key'] ?? '';
if ($key === '') { http_response_code(404); exit('No file'); }

audit_log('checkin.file_view', 'hold', $holdId, 'guest ' . $guestId);

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$ct  = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $ct);
header('Content-Disposition: inline; filename="passport.' . $ext . '"');
header('Cache-Control: private, no-store');

$signed = storage_signed_get_url($key);
if ($signed !== '') {
    $data = @file_get_contents($signed);
    if ($data === false) { http_response_code(502); exit('Fetch failed'); }
    echo $data;
} else {
    $path = storage_local_path($key);   // filesystem fallback
    if (!is_file($path)) { http_response_code(404); exit('No file'); }
    readfile($path);
}
```

- [ ] **Step 2: Verify manually**

As owner, hit `/admin/checkin-file.php?hold=<id>&guest=<leadGuestId>` for a booking with an uploaded scan → the image/PDF renders inline. Log in as a non-manager staff (or unrelated manager) → expect `403 Forbidden`. Confirm an audit row:
```bash
php -r 'require "includes/db.php"; echo db_query("SELECT action FROM admin_audit_log WHERE action=:a ORDER BY id DESC LIMIT 1", [":a"=>"checkin.file_view"])->fetchColumn() ?: "none", "\n";'
```
Expected: `checkin.file_view`.

- [ ] **Step 3: Commit**

```bash
git add admin/checkin-file.php
git commit -m "feat(checkin): owner/manager-only signed passport proxy (audit-logged)"
```

---

### Task 14: Admin Check-in tab

**Files:**
- Create: `admin/_ws_checkin.php`
- Modify: `admin/booking.php`

- [ ] **Step 1: Wire the tab into `admin/booking.php`**

- Add `require_once __DIR__ . '/../includes/checkin.php';` to the requires.
- Add `'checkin'` to the allowed `$tab` list: `if (!in_array($tab, ['requests','messages','plan','bill','checkin','details'], true)) $tab = 'requests';`
- Add `'checkin' => 'Check-in'` to the `$__wtabs` map (before `details`). Show it to owner + assigned staff (leave in the map; do NOT unset for non-owner).
- Add a POST handler for the require toggle (owner-only), alongside the other actions:
```php
    if ($act === 'checkin_toggle' && is_owner() && checkin_supported()) {
        $on = ($_POST['require_checkin'] ?? '') === '1';
        db_query('UPDATE holds SET require_checkin = :r WHERE id = :id', [':r' => $on, ':id' => $holdId]);
        audit_log('checkin.require_toggle', 'hold', $holdId, $on ? 'on' : 'off');
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Check-in requirement updated.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }
```
- Add the include branch next to the other tabs:
```php
<?php elseif ($tab === 'checkin'): ?>
  <?php include __DIR__ . '/_ws_checkin.php'; ?>
```

- [ ] **Step 2: Write the tab body**

```php
<?php /** Workspace Check-in tab. Expects $hold, $holdId. */ ?>
<?php
$__ci   = fetch_checkin($holdId);
$__lead = checkin_lead_guest($holdId);
$__canDocs = can_view_guest_docs($holdId);
$__state = checkin_state($hold);
$__fmt = fn($v) => ($v === null || $v === '') ? '—' : e((string)$v);
?>
<?php if (!checkin_supported()): ?>
<div class="card"><div class="card__body">Run the <code>add_checkin.sql</code> migration to enable check-in.</div></div>
<?php else: ?>

<div class="card" style="margin-bottom:16px"><div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <div>
    <strong>Status:</strong>
    <?php if ($__state === 'complete'): ?><span class="ci-badge ci-badge--done">Checked in ✓</span> <span class="text-muted"><?= e(date('j M Y H:i', strtotime((string)$hold['checkin_completed_at']))) ?></span>
    <?php elseif ($__state === 'pending'): ?><span class="ci-badge ci-badge--pending">Pending</span>
    <?php else: ?><span class="text-muted">Not required</span><?php endif; ?>
  </div>
  <?php if (is_owner()): ?>
  <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="hold_id" value="<?= $holdId ?>">
    <input type="hidden" name="action" value="checkin_toggle">
    <input type="hidden" name="require_checkin" value="<?= !empty($hold['require_checkin']) ? '0' : '1' ?>">
    <button class="btn-sm btn-outline"><?= !empty($hold['require_checkin']) ? 'Turn off requirement' : 'Require check-in' ?></button>
  </form>
  <?php endif; ?>
</div></div>

<?php if ($__ci || $__lead): ?>
<div class="card" style="margin-bottom:16px"><div class="card__body">
  <table class="data-table" style="max-width:600px">
    <tr><td class="text-muted">Airport</td><td><?= $__fmt($__ci['arrival_airport'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Flight</td><td><?= $__fmt($__ci['flight_number'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
    <tr><td class="text-muted">Dietary</td><td><?= $__fmt($__ci['dietary'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Requests</td><td><?= $__fmt($__ci['special_requests'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Waiver</td><td><?php echo !empty($__ci['waiver_signed_at']) ? 'Signed by '.e((string)$__ci['waiver_signed_name']).' · '.e(date('j M Y', strtotime((string)$__ci['waiver_signed_at']))) : '—'; ?></td></tr>
  </table>
</div></div>

<div class="card"><div class="card__head"><span class="card__title">Lead guest — identity</span></div><div class="card__body">
  <?php if ($__lead): ?>
  <table class="data-table" style="max-width:600px">
    <tr><td class="text-muted">Name</td><td><?= $__fmt($__lead['passport_name'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Nationality</td><td><?= $__fmt($__lead['nationality'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Passport #</td><td><?= $__canDocs ? $__fmt($__lead['passport_number'] ?? '') : '<span class="text-muted">•••• (restricted)</span>' ?></td></tr>
    <tr><td class="text-muted">Expiry</td><td><?= $__fmt($__lead['passport_expiry'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Scan</td><td>
      <?php if (empty($__lead['passport_file_key'])): ?>—
      <?php elseif ($__canDocs): ?><a href="/admin/checkin-file.php?hold=<?= $holdId ?>&guest=<?= (int)$__lead['id'] ?>" target="_blank" class="btn-sm btn-outline">View scan →</a>
      <?php else: ?>On file ✓ <span class="text-muted">(restricted)</span><?php endif; ?>
    </td></tr>
  </table>
  <?php else: ?><p class="text-muted" style="margin:0">No identity captured yet.</p><?php endif; ?>
</div></div>
<?php else: ?>
<div class="card"><div class="card__body text-muted">Nothing submitted yet.</div></div>
<?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 3: Verify manually**

Open a checked-in booking → `/admin/booking.php?hold=<id>&tab=checkin` as owner: all fields show, "View scan" opens the proxy, passport # is visible. As a venue *manager*: same. As non-manager venue *staff*: passport # shows "•••• (restricted)" and the scan shows "On file ✓ (restricted)" with no link. Toggling the requirement (owner) flips it and audit-logs.

- [ ] **Step 4: Commit**

```bash
git add admin/_ws_checkin.php admin/booking.php
git commit -m "feat(checkin): admin Check-in tab (view data, doc-guarded, owner toggle)"
```

---

### Task 15: Completed-state home card + final regression

**Files:**
- Modify: `includes/app/_stay_essentials.php` (small "checked in" affordance — optional) or leave the wizard's own done-state.

- [ ] **Step 1: Run the full logic suite (migrated)**

Run: `php tests/checkin_logic.php`
Expected: `ALL PASS`, exit 0, with the permission + badge blocks executing (no SKIP) since the migration is applied.

- [ ] **Step 2: Regression — existing suites still pass**

Run: `php tests/portal_logic.php && php tests/frontdesk_logic.php && php tests/team_logic.php`
Expected: each ends `ALL PASS`.

- [ ] **Step 3: Pre-migration safety check (manual reasoning + spot test)**

Confirm `checkin_supported()` gating means a fresh DB without `add_checkin.sql` still renders: `booking.php`, `admin/holds.php`, `admin/frontdesk.php`, `admin/hold-new.php` (the checkbox + badges simply don't appear). Spot-check by grepping that every new `holds` query for check-in columns is inside a `checkin_supported()` or `try/catch` guard.

- [ ] **Step 4: Commit any final tweak + open the PR**

```bash
git add -A
git commit -m "chore(checkin): final regression pass"
```
Then push the branch and open a PR into `master` (per the repo's PR workflow). Note: pushing requires the `kww-ptk` gh account (see project memory).

---

## Manual verification checklist (end-to-end)

1. Migration applied; `php tests/checkin_logic.php` → ALL PASS.
2. Owner sets defaults + waiver text in `/admin/checkin-settings.php`.
3. New Booking with "Require check-in" ticked → Holds shows amber "Check-in pending".
4. Guest opens portal → hard-gated into the wizard; "Message the team" still reachable.
5. Guest completes all required steps incl. passport scan + typed waiver → "Checked in ✓"; portal unlocks.
6. Frontdesk + Holds now show green "Checked in ✓".
7. Admin Check-in tab shows all data; owner/manager can view the scan; staff see "restricted".
8. Guest re-opens before arrival day → can edit; after arrival day → locked.

---

## Self-Review

**Spec coverage:** hard gate (Task 7) ✓ · 6-step catalog + toggles (Tasks 2,5) ✓ · typed-name waiver (Tasks 8,9) ✓ · private files owner+manager (Tasks 11,12,13,14) ✓ · per-booking toggle any booking (Tasks 4,14) ✓ · lead-guest table extensible (Task 1) ✓ · editable-until-arrival (Task 9) ✓ · in-admin completion surfacing (Task 6) + best-effort email (Task 9) ✓ · passport scan owner+manager, staff masked (Task 14) ✓ · pre-migration guards (all tasks via `checkin_supported()`) ✓.

**Placeholders:** none — every code step carries complete code; the one "confirm the CSRF field name" note (Task 10) is a verification instruction, not a code gap, with a concrete fallback.

**Type consistency:** helper names in the Reference section match their definitions (Task 2) and all call-sites (Tasks 4–14). `passport_file_key` stores a KEY everywhere (upload writes it, proxy reads it, tab links to it). `checkin_missing_steps`/`checkin_step_complete` signatures are identical across the test (Task 3) and the endpoint (Task 9).
