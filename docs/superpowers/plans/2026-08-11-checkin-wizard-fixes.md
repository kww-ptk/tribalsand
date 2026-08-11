# Check-in Wizard Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix five defects in the guest pre-arrival check-in flow — arrival assumes everyone flies, no airport picker, the lead's signature appears lost when adding a second adult, no consent gate, and no completion confirmation for a lead who finishes before their party.

**Architecture:** Four new pure helpers in `includes/checkin.php` carry all the new logic and are unit-tested in isolation. The wizard view (`includes/app/checkin.php`) splits its combined "party" step into "Your details" (lead identity + signature) and "Your party" (other adults), which structurally prevents the signature bug. `js/checkin-wizard.js` gains client-side validation and replaces its page-reloading add-adult with a DOM append. `api/checkin-save.php` persists three new columns and returns explicit consent errors instead of silently dropping them.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS, vanilla CSS. Tests are a plain PHP script with a `check()` assertion helper — no PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-11-checkin-wizard-fixes-design.md`

---

## Before you start

Read the spec linked above. Then read these three files end to end — every task touches at least one:

- `includes/checkin.php` (299 lines) — all check-in helpers
- `includes/app/checkin.php` (236 lines) — the guest wizard view
- `js/checkin-wizard.js` (146 lines) — the wizard's client behaviour

**Conventions you must follow** (from `CLAUDE.md`):

- Every DB read that touches a column added by a migration is wrapped in a `*_supported()` guard so the page still renders on an unmigrated database. Copy the pattern from `checkin_signature_supported()` at `includes/checkin.php:52`.
- All output is escaped with `e()`. All queries use `db_query()` with bound parameters — never string interpolation of user data.
- Never use `$_SERVER['REMOTE_ADDR']`; use `client_ip()`.
- No build step. Edit CSS and JS directly; `includes/head.php` cache-busts with `filemtime()`.

**Run the test suite** before you change anything, so you know the baseline is green:

```bash
php tests/checkin_logic.php
```

Expected: a list of `PASS` lines ending in `ALL PASS`. If it does not, stop and report.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `db/migrations/add_checkin_arrival.sql` | Three nullable columns on `booking_checkin` | Create |
| `includes/checkin.php` | Check-in domain logic. Gains 4 pure helpers + 1 support guard + 2 catalogs | Modify |
| `tests/checkin_logic.php` | Pure-helper assertions | Modify |
| `api/checkin-save.php` | Guest check-in write path | Modify |
| `includes/app/checkin.php` | Lead's wizard view | Modify |
| `js/checkin-wizard.js` | Wizard client behaviour | Modify |
| `css/portal-app.css` | Portal styles | Modify |
| `admin/_ws_checkin.php` | Admin booking workspace check-in tab | Modify |

All new logic that can be pure **is** pure, so it lands in `includes/checkin.php` and gets tested in `tests/checkin_logic.php`. The view, API and JS files only orchestrate.

---

### Task 1: Migration and support guard

**Files:**
- Create: `db/migrations/add_checkin_arrival.sql`
- Modify: `includes/checkin.php` (insert after `checkin_signature_supported()`, line 58)
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Create the migration**

Create `db/migrations/add_checkin_arrival.sql`:

```sql
-- Tribal Sand: arrival modes for pre-arrival check-in (flight / road / other).
-- Run via /admin/migrate.php. Idempotent — safe to re-run.
--
-- arrival_mode    'flight' | 'road' | 'other'; NULL = a row saved before this
--                 migration, which the app treats with the legacy flight rule.
-- arrival_vehicle road mode only: vehicle description / number plate.
-- arrival_note    other mode only: how the guest is arriving.
-- The "Other" airport free-text reuses the existing arrival_airport column.
ALTER TABLE booking_checkin
    ADD COLUMN IF NOT EXISTS arrival_mode    TEXT,
    ADD COLUMN IF NOT EXISTS arrival_vehicle TEXT,
    ADD COLUMN IF NOT EXISTS arrival_note    TEXT;
```

- [ ] **Step 2: Write the failing test**

In `tests/checkin_logic.php`, find the block that ends with these two lines (near the bottom, under the `C-2 support guards` heading):

```php
check('bill_item_guest_supported is bool',     is_bool(bill_item_guest_supported()));
check('message_sender_guest_supported is bool', is_bool(message_sender_guest_supported()));
```

Add a third line directly beneath them:

```php
check('checkin_arrival_mode_supported is bool', is_bool(checkin_arrival_mode_supported()));
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function checkin_arrival_mode_supported()`.

- [ ] **Step 4: Add the support guard**

In `includes/checkin.php`, insert this immediately after the closing brace of `checkin_signature_supported()` (line 58) and before `function checkin_required(`:

```php
/** True once add_checkin_arrival.sql is applied. Cached per-request. */
function checkin_arrival_mode_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT arrival_mode FROM booking_checkin LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: `PASS  checkin_arrival_mode_supported is bool`, and the run still ends `ALL PASS`.

- [ ] **Step 6: Apply the migration locally**

Run: `php bin/migrate.php db/migrations/add_checkin_arrival.sql`

(`bin/migrate.php` takes an optional path; with no argument it runs every file in `db/migrations/`.) Then verify:

```bash
php -r 'require "includes/db.php"; require "includes/checkin.php"; var_dump(checkin_arrival_mode_supported());'
```

Expected: `bool(true)`

- [ ] **Step 7: Commit**

```bash
git add db/migrations/add_checkin_arrival.sql includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): add arrival mode columns and support guard"
```

---

### Task 2: Arrival mode catalogs and `checkin_arrival_complete()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find this line under the `Per-step completeness (pure)` heading:

```php
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00', 'waiver_signature' => 'sig']) === true);
```

Insert this block immediately after it:

```php
// ── Arrival modes (pure) ────────────────────────────────────────────────────
check('arrival modes has three',          count(checkin_arrival_modes()) === 3);
check('arrival modes keyed by value',     array_keys(checkin_arrival_modes()) === ['flight', 'road', 'other']);
check('airports has three',               count(checkin_airports()) === 3);
check('arrival legacy needs flight+time', checkin_arrival_complete(['flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival legacy without flight',    checkin_arrival_complete(['arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight needs airport',     checkin_arrival_complete(['arrival_mode' => 'flight', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight needs flight no',   checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival flight complete',          checkin_arrival_complete(['arrival_mode' => 'flight', 'arrival_airport' => 'Malindi', 'flight_number' => 'KQ100', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival road needs only time',     checkin_arrival_complete(['arrival_mode' => 'road', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival road without time',        checkin_arrival_complete(['arrival_mode' => 'road']) === false);
check('arrival other needs only time',    checkin_arrival_complete(['arrival_mode' => 'other', 'arrival_at' => '2026-09-01 14:00']) === true);
check('arrival unknown mode = legacy',    checkin_arrival_complete(['arrival_mode' => 'teleport', 'arrival_at' => '2026-09-01 14:00']) === false);
check('arrival null data incomplete',     checkin_arrival_complete(null) === false);
check('step_complete delegates to mode',  checkin_step_complete('arrival', ['arrival_mode' => 'road', 'arrival_at' => '2026-09-01 14:00'], null) === true);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function checkin_arrival_modes()`.

- [ ] **Step 3: Add the catalogs and the helper**

In `includes/checkin.php`, insert this immediately after `checkin_step_catalog()` (which ends at line 18) and before `checkin_config()`:

```php
/** The three ways a guest can arrive. Array order = radio order in the wizard. */
function checkin_arrival_modes(): array {
    return [
        'flight' => 'By air',
        'road'   => 'By road',
        'other'  => 'Something else',
    ];
}

/**
 * Airports offered in the arrival dropdown. Keys are the stored value (so the
 * select round-trips), values are the guest-facing label. The wizard adds an
 * "Other" choice that writes free text into the same arrival_airport column.
 */
function checkin_airports(): array {
    return [
        'Vipingo' => 'Vipingo',
        'Malindi' => 'Malindi (MYD)',
        'Mombasa' => 'Mombasa — Moi Intl (MBA)',
    ];
}

/**
 * Is the arrival step's required data present? Mode-aware:
 *   flight      → airport + flight number + arrival time
 *   road/other  → arrival time only
 *   no mode set → the legacy rule (flight number + arrival time), so rows saved
 *                 before add_checkin_arrival.sql keep their old behaviour.
 * An unrecognised mode falls back to the legacy rule rather than passing. Pure.
 */
function checkin_arrival_complete(?array $data): bool {
    $d    = $data ?? [];
    $has  = fn($k) => trim((string)($d[$k] ?? '')) !== '';
    $mode = trim((string)($d['arrival_mode'] ?? ''));
    if ($mode === 'road' || $mode === 'other') return $has('arrival_at');
    if ($mode === 'flight') return $has('arrival_airport') && $has('flight_number') && $has('arrival_at');
    return $has('flight_number') && $has('arrival_at');
}
```

- [ ] **Step 4: Delegate `checkin_step_complete` to the helper**

In `includes/checkin.php`, inside `checkin_step_complete()`, replace this line:

```php
        case 'arrival':  return $has('flight_number') && !empty($data['arrival_at']);
```

with:

```php
        case 'arrival':  return checkin_arrival_complete($data);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/checkin_logic.php`
Expected: all the new `arrival …` lines PASS, the two pre-existing arrival assertions (`arrival incomplete when empty`, `arrival complete w/ flight+time`) still PASS, and the run ends `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): mode-aware arrival completion with airport catalog"
```

---

### Task 3: `checkin_consent_missing()`

This helper is the single source of truth for the consent wording, shared by the client-side gate (Task 10) and the server-side rejection (Task 11), so the two can never drift.

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find this line:

```php
check('lead cannot sign other',   checkin_can_sign_self(null, false) === false);
```

Insert this block immediately after it:

```php
// ── Consent completeness (pure) ─────────────────────────────────────────────
// A minimal valid PNG data-URL: the 8 magic bytes plus padding to clear the
// 8-byte floor in checkin_valid_signature().
$sigOk = 'data:image/png;base64,' . base64_encode(hex2bin('89504e470d0a1a0a') . str_repeat("\0", 16));
check('consent fixture is a valid sig', checkin_valid_signature($sigOk) === true);
check('consent complete',               checkin_consent_missing(true, 'Jess Achieng', $sigOk) === []);
check('consent needs agreement',        checkin_consent_missing(false, 'Jess', $sigOk) === ['agree to the terms']);
check('consent needs typed name',       checkin_consent_missing(true, '   ', $sigOk) === ['type your full name']);
check('consent needs signature',        checkin_consent_missing(true, 'Jess', '') === ['draw your signature']);
check('consent rejects junk signature', checkin_consent_missing(true, 'Jess', 'data:image/png;base64,bm90YXBuZw==') === ['draw your signature']);
check('consent alreadySigned skips sig', checkin_consent_missing(true, 'Jess', '', true) === []);
check('consent lists all three',        count(checkin_consent_missing(false, '', '')) === 3);
check('consent order is stable',        checkin_consent_missing(false, '', '') === ['agree to the terms', 'type your full name', 'draw your signature']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function checkin_consent_missing()`.

- [ ] **Step 3: Add the helper**

In `includes/checkin.php`, insert this immediately after `checkin_can_sign_self()` (which ends at line 197) and before `checkin_guest_complete()`:

```php
/**
 * Which pieces of consent a signing attempt is missing. [] = ready to sign.
 * The returned strings are guest-facing sentence fragments ("agree to the
 * terms"), used verbatim by both the wizard's inline error and the server's
 * rejection message so the two can never drift apart.
 *
 * $alreadySigned = the guest already has a stored signature, so an empty
 * $signature means "left the existing one alone", not "refused to sign". Pure.
 */
function checkin_consent_missing(bool $agreed, string $typedName, string $signature, bool $alreadySigned = false): array {
    $missing = [];
    if (!$agreed)                                                $missing[] = 'agree to the terms';
    if (trim($typedName) === '')                                 $missing[] = 'type your full name';
    if (!$alreadySigned && !checkin_valid_signature($signature)) $missing[] = 'draw your signature';
    return $missing;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/checkin_logic.php`
Expected: all nine new `consent …` lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): shared consent-completeness helper"
```

---

### Task 4: `checkin_outstanding_adults()` and `checkin_waiting_on_label()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find this line under the `Co-guest view state (pure)` heading:

```php
check('coguest passport-only done', checkin_coguest_view_state($ciPass, $cfgPassOnly) === 'done');
```

Insert this block immediately after it:

```php
// ── Outstanding adults + waiting-on label (pure) ────────────────────────────
$cfgPW3 = ['passport' => ['enabled' => true, 'required' => true], 'waiver' => ['enabled' => true, 'required' => true]];
$gDone  = ['passport_name' => 'Jess Achieng', 'passport_number' => 'B', 'passport_file_key' => 'k',
           'waiver_signed_name' => 'Jess', 'waiver_signed_at' => '2026-08-06', 'waiver_signature' => 'sig'];
$gTodo  = ['passport_name' => 'Patrik Otieno'];
$gTodo2 = ['passport_name' => 'Sarah Kim'];
$gKid   = ['is_child' => true, 'passport_name' => 'Small One'];
check('outstanding excludes complete',  checkin_outstanding_adults([$gDone], $cfgPW3) === []);
check('outstanding lists incomplete',   count(checkin_outstanding_adults([$gDone, $gTodo], $cfgPW3)) === 1);
check('outstanding excludes children',  checkin_outstanding_adults([$gDone, $gKid], $cfgPW3) === []);
check('outstanding empty roster',       checkin_outstanding_adults([], $cfgPW3) === []);
check('outstanding keeps roster order', array_column(checkin_outstanding_adults([$gTodo, $gTodo2], $cfgPW3), 'passport_name') === ['Patrik Otieno', 'Sarah Kim']);

check('waiting label one',    checkin_waiting_on_label(['Patrik'], 0) === 'Patrik');
check('waiting label two',    checkin_waiting_on_label(['Patrik', 'Sarah'], 0) === 'Patrik and Sarah');
check('waiting label three',  checkin_waiting_on_label(['A', 'B', 'C'], 0) === 'A, B and C');
check('waiting label 1 slot', checkin_waiting_on_label([], 1) === '1 more guest');
check('waiting label n slots', checkin_waiting_on_label([], 2) === '2 more guests');
check('waiting label mixed',  checkin_waiting_on_label(['Patrik'], 2) === 'Patrik and 2 more guests');
check('waiting label empty',  checkin_waiting_on_label([], 0) === '');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/checkin_logic.php`
Expected: a PHP fatal error — `Call to undefined function checkin_outstanding_adults()`.

- [ ] **Step 3: Add the helpers**

In `includes/checkin.php`, insert this immediately after `checkin_party_complete_count()` (which ends at line 235) and before the `checkin_recompute_completion()` docblock:

```php
/**
 * Adult guest rows that are not yet fully checked in, in roster order. Children
 * are never included. The counterpart to checkin_party_complete_count(), which
 * counts the same rows the other way round. Pure.
 */
function checkin_outstanding_adults(array $guests, array $config): array {
    $out = [];
    foreach ($guests as $g) {
        if (!empty($g['is_child'])) continue;
        if (!checkin_guest_complete($g, $config)) $out[] = $g;
    }
    return $out;
}

/**
 * Human list of who a party is still waiting on: named guests plus a count of
 * adult slots that have not been added to the roster at all ("2 more guests").
 * Returns '' when nothing is outstanding. Pure.
 */
function checkin_waiting_on_label(array $names, int $unnamedSlots): string {
    $parts = array_values($names);
    if ($unnamedSlots > 0) $parts[] = $unnamedSlots === 1 ? '1 more guest' : "{$unnamedSlots} more guests";
    if (!$parts) return '';
    if (count($parts) === 1) return $parts[0];
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/checkin_logic.php`
Expected: all twelve new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): outstanding-adults roster and waiting-on label"
```

---

### Task 5: Persist arrival mode fields on save

**Files:**
- Modify: `api/checkin-save.php:27-41`

There is no unit test here — this is a DB write path with no pure logic left in it (the pure parts are Tasks 2 and 3). It is verified by manual round-trip in Step 3.

- [ ] **Step 1: Replace the booking-level write block**

In `api/checkin-save.php`, replace this entire block (currently lines 27–41):

```php
// ── Booking-level fields (LEAD only — booking_checkin is hold-scoped) ───────
if ($isLead) {
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
}
```

with:

```php
// ── Booking-level fields (LEAD only — booking_checkin is hold-scoped) ───────
if ($isLead) {
    $arrivalAt = ($_POST['arrival_at'] ?? '') !== '' ? date('Y-m-d H:i:s', strtotime((string)$_POST['arrival_at'])) : null;
    $needsTransfer = array_key_exists('needs_transfer', $_POST) && $_POST['needs_transfer'] !== ''
        ? ($_POST['needs_transfer'] === '1') : null;

    // Arrival mode: validated against the catalog, '' when unknown or unmigrated.
    $mode = '';
    if (checkin_arrival_mode_supported()) {
        $posted = (string)($_POST['arrival_mode'] ?? '');
        if (array_key_exists($posted, checkin_arrival_modes())) $mode = $posted;
    }

    // The airport select posts '__other' to reveal a free-text box; store the text.
    $airport = $s('arrival_airport');
    if ($airport === '__other') $airport = $s('arrival_airport_other');
    $flight = $s('flight_number');
    // Switching away from flying clears stale flight data so the transfer desk
    // never sees a flight number for someone driving in.
    if ($mode !== '' && $mode !== 'flight') { $airport = null; $flight = null; }

    // Column list is composed so the write works pre- and post-migration —
    // same approach as insert_booking_addon() in includes/booking.php.
    $cols = ['arrival_airport', 'flight_number', 'arrival_at', 'needs_transfer',
             'transfer_details', 'dietary', 'special_requests'];
    $vals = [':aa', ':fn', ':at', ':nt', ':td', ':di', ':sr'];
    $p = [':h'=>$holdId, ':aa'=>$airport, ':fn'=>$flight, ':at'=>$arrivalAt,
          ':nt'=>$needsTransfer, ':td'=>$s('transfer_details'), ':di'=>$s('dietary'),
          ':sr'=>$s('special_requests')];

    if (checkin_arrival_mode_supported()) {
        $cols[] = 'arrival_mode';    $vals[] = ':am'; $p[':am'] = $mode === '' ? null : $mode;
        $cols[] = 'arrival_vehicle'; $vals[] = ':av'; $p[':av'] = $mode === 'road'  ? $s('arrival_vehicle') : null;
        $cols[] = 'arrival_note';    $vals[] = ':an'; $p[':an'] = $mode === 'other' ? $s('arrival_note')    : null;
    }

    $sets = [];
    foreach ($cols as $i => $c) { $sets[] = "{$c}={$vals[$i]}"; }

    db_query(
        'INSERT INTO booking_checkin (hold_id, ' . implode(', ', $cols) . ', updated_at)
         VALUES (:h, ' . implode(', ', $vals) . ', now())
         ON CONFLICT (hold_id) DO UPDATE SET ' . implode(', ', $sets) . ', updated_at=now()',
        $p
    );
}
```

- [ ] **Step 2: Check the file parses**

Run: `php -l api/checkin-save.php`
Expected: `No syntax errors detected in api/checkin-save.php`

- [ ] **Step 3: Verify the round-trip against the database**

The wizard cannot post these fields yet (that is Task 6), so exercise the write directly. Pick any hold id that exists locally:

```bash
php -r '
require "includes/db.php";
$h = (int)db_query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn();
db_query("INSERT INTO booking_checkin (hold_id, arrival_mode, arrival_vehicle) VALUES (:h, :m, :v)
          ON CONFLICT (hold_id) DO UPDATE SET arrival_mode=:m, arrival_vehicle=:v",
         [":h"=>$h, ":m"=>"road", ":v"=>"KDD 123A"]);
$r = db_query("SELECT arrival_mode, arrival_vehicle FROM booking_checkin WHERE hold_id=:h", [":h"=>$h])->fetch();
print_r($r);
db_query("UPDATE booking_checkin SET arrival_mode=NULL, arrival_vehicle=NULL WHERE hold_id=:h", [":h"=>$h]);
'
```

Expected: an array containing `[arrival_mode] => road` and `[arrival_vehicle] => KDD 123A`. The last line cleans up after itself.

- [ ] **Step 4: Commit**

```bash
git add api/checkin-save.php
git commit -m "feat(checkin): persist arrival mode, vehicle and note"
```

---

### Task 6: Arrival step UI — three modes and the airport picker

**Files:**
- Modify: `includes/app/checkin.php:105-111` (the `arrival` branch)
- Modify: `css/portal-app.css`

- [ ] **Step 1: Replace the arrival branch**

In `includes/app/checkin.php`, replace this block (currently lines 105–111):

```php
      <?php if ($key === 'arrival'): ?>
        <label class="ci-l">Airport of arrival</label>
        <input class="ci-in" name="arrival_airport" value="<?= $val('arrival_airport') ?>" placeholder="e.g. Moi Intl (MBA)">
        <label class="ci-l">Flight number</label>
        <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
        <label class="ci-l">Arrival date &amp; time</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
```

with:

```php
      <?php if ($key === 'arrival'): ?>
        <?php
          $amOn     = checkin_arrival_mode_supported();
          $modes    = checkin_arrival_modes();
          $airports = checkin_airports();
          $mode     = $amOn ? trim((string)($data['arrival_mode'] ?? '')) : '';
          if (!array_key_exists($mode, $modes)) $mode = $amOn ? 'flight' : '';
          $savedAir = trim((string)($data['arrival_airport'] ?? ''));
          // A saved airport that isn't in the catalog came from the "Other" box.
          $airOther = $savedAir !== '' && !array_key_exists($savedAir, $airports);
        ?>
        <?php if ($amOn): ?>
        <label class="ci-l">How will you arrive?</label>
        <div class="ci-modes">
          <?php foreach ($modes as $mk => $ml): ?>
          <label class="ci-radio"><input type="radio" class="ci-f-mode" name="arrival_mode" value="<?= e($mk) ?>" <?= $mode === $mk ? 'checked' : '' ?>> <?= e($ml) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="ci-mode-fields" data-mode="flight"<?= ($amOn && $mode !== 'flight') ? ' hidden' : '' ?>>
          <label class="ci-l">Airport of arrival</label>
          <select class="ci-in ci-f-airport" name="arrival_airport">
            <option value="">— select —</option>
            <?php foreach ($airports as $av => $al): ?>
            <option value="<?= e($av) ?>" <?= $savedAir === $av ? 'selected' : '' ?>><?= e($al) ?></option>
            <?php endforeach; ?>
            <option value="__other" <?= $airOther ? 'selected' : '' ?>>Other — I&rsquo;ll type it</option>
          </select>
          <div class="ci-airport-other"<?= $airOther ? '' : ' hidden' ?>>
            <label class="ci-l">Which airport?</label>
            <input class="ci-in" name="arrival_airport_other" value="<?= $airOther ? e($savedAir) : '' ?>" placeholder="e.g. Nairobi JKIA">
          </div>
          <label class="ci-l">Flight number</label>
          <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
        </div>

        <div class="ci-mode-fields" data-mode="road"<?= ($amOn && $mode === 'road') ? '' : ' hidden' ?>>
          <label class="ci-l">Vehicle / number plate <span class="ci-opt">(optional)</span></label>
          <input class="ci-in" name="arrival_vehicle" value="<?= $val('arrival_vehicle') ?>" placeholder="e.g. white Land Cruiser, KDD 123A">
        </div>

        <div class="ci-mode-fields" data-mode="other"<?= ($amOn && $mode === 'other') ? '' : ' hidden' ?>>
          <label class="ci-l">How are you arriving?</label>
          <input class="ci-in" name="arrival_note" value="<?= $val('arrival_note') ?>" placeholder="e.g. by boat, or dropped off by a tour operator">
        </div>

        <label class="ci-l">Arrival date &amp; time</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
```

Note the pre-migration path: when `$amOn` is false, no radios render, the flight group stays visible and the road/other groups stay hidden — exactly today's form.

- [ ] **Step 2: Add the styles**

In `css/portal-app.css`, find this line (in the signature-pad block, near line 249):

```css
/* ── Signature pad ─────────────────────────────────────────────────────────── */
```

Insert this immediately **before** it:

```css
/* ── Arrival modes ─────────────────────────────────────────────────────────── */
.ci-modes{margin:4px 0 6px}
.ci-opt{font-weight:400;text-transform:none;letter-spacing:0}
```

- [ ] **Step 3: Wire the radio and airport toggles**

In `js/checkin-wizard.js`, find the delegated `change` listener that begins:

```js
  // Delegated passport upload — any .ci-upload file input. guest_id from the enclosing card (absent → lead).
  form.addEventListener('change', function (e) {
    if (e.target.type !== 'file' || !e.target.closest('.ci-upload')) return;
```

Insert this **new, separate** listener immediately **before** that comment:

```js
  // Arrival: show only the chosen mode's fields, and reveal the free-text
  // airport box when "Other" is picked. First paint is server-rendered.
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
      return;
    }
    if (t.classList.contains('ci-f-airport')) {
      var box = t.closest('.ci-mode-fields').querySelector('.ci-airport-other');
      if (box) box.hidden = t.value !== '__other';
      return;
    }
  });
```

- [ ] **Step 4: Verify in the browser**

Start the dev server (launch.json config `tribalsand`) and open a booking whose `require_checkin` is true, at `/booking.php?ref=<ref>&view=checkin`. Start the wizard.

Expected on the Arrival step: three radios with **By air** preselected; airport dropdown showing Vipingo / Malindi / Mombasa / Other; picking **Other** reveals a text box; picking **By road** hides the airport and flight fields and shows Vehicle / number plate; the Arrival date & time field is visible in all three modes.

Then pick **By road**, enter a plate and a time, click **Save & continue**, and reload the page back to the arrival step. Expected: **By road** is still selected and the plate is still there.

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin.php css/portal-app.css js/checkin-wizard.js
git commit -m "feat(checkin): arrival mode picker with airport dropdown"
```

---

### Task 7: Split the party step into "Your details" and "Your party"

This is the structural fix: the lead's signature and the add-adult button end up on different steps, so adding a guest cannot disturb the signature.

**Files:**
- Modify: `includes/app/checkin.php:29-35` (flow build) and `:121-209` (the `party` branch)

- [ ] **Step 1: Replace the flow build**

In `includes/app/checkin.php`, replace this block (currently lines 29–35):

```php
// Wizard flow: passport's slot becomes "party"; waiver folds in (dropped as its own step).
$flow = [];
foreach ($cfg as $key => $s) {
    if ($key === 'passport') { $flow['party'] = ['label' => 'Your party', 'required' => true]; continue; }
    if ($key === 'waiver')   { if (!isset($flow['party'])) $flow['party'] = ['label' => 'Your party', 'required' => true]; continue; }
    $flow[$key] = $s;
}
```

with:

```php
// Wizard flow: passport + waiver collapse into "Your details" — the lead's own
// identity, consent and signature. Other adults get their own "Your party" step
// so adding a guest can never disturb the lead's signature (the old combined
// step reloaded the page and appeared to wipe it).
$flow = [];
foreach ($cfg as $key => $s) {
    if ($key === 'passport' || $key === 'waiver') {
        if (!isset($flow['you'])) $flow['you'] = ['label' => 'Your details', 'required' => true];
        continue;
    }
    $flow[$key] = $s;
}
// "Your party" only exists when there is something to manage: more than one
// adult, and at least one of passport/waiver enabled (so "you" was created).
if (isset($flow['you']) && $need > 1) {
    $rebuilt = [];
    foreach ($flow as $k => $v) {
        $rebuilt[$k] = $v;
        if ($k === 'you') $rebuilt['party'] = ['label' => 'Your party', 'required' => true];
    }
    $flow = $rebuilt;
}
```

- [ ] **Step 2: Tag the "you" section so the client gate knows the passport rules**

In `includes/app/checkin.php`, find the section opening tag (currently line 102):

```php
    <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>" hidden>
```

Replace it with:

```php
    <section class="ci-step" data-step="<?= $i ?>" data-key="<?= e($key) ?>"<?= ($key === 'you' && $showPassport && !empty($cfg['passport']['required'])) ? ' data-passport-required' : '' ?> hidden>
```

- [ ] **Step 3: Split the branch — replace the whole `party` block**

In `includes/app/checkin.php`, replace everything from this line (currently line 121):

```php
      <?php elseif ($key === 'party'): ?>
```

up to and including this line (currently line 209):

```php
        <button type="button" class="pa-btn pa-btn--ghost ci-addguest" data-need="<?= $need ?>" <?= count($adults) >= $need ? 'hidden' : '' ?>>+ Add adult (<?= count($adults) ?>/<?= $need ?>)</button>
```

with the two branches below. The lead card (including the lead's children) moves to `you`; the other-adult cards and the add button move to `party`.

```php
      <?php elseif ($key === 'you'): ?>
        <!-- Lead card — part of #ciForm (no guest_id → the lead row) -->
        <div class="ci-guest ci-guest--lead">
          <div class="ci-guest__title"><span class="ci-guest__who">You (lead guest)</span>
            <span class="ci-chip <?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'ci-chip--ok' : '' ?>"><?= (checkin_guest_passport_complete($lead) && (!$showWaiver || checkin_guest_waiver_signed($lead))) ? 'Complete' : 'Your details' ?></span></div>
          <?php if ($showPassport): ?>
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
            <input type="file" accept="image/jpeg,image/png,application/pdf">
            <span class="ci-upload__state"><?= !empty($lead['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
          </div>
          <?php endif; ?>
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($lead) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <label class="ci-l">Sign below with your finger</label>
          <div class="ci-sign">
            <button type="button" class="ci-sign-clear">Clear</button>
            <canvas class="ci-sign-pad" data-target="#ciLeadSig"></canvas>
          </div>
          <input type="hidden" name="waiver_signature" id="ciLeadSig">
          <p class="ci-sign-hint">Reception can fill your details, but you sign yourself.</p>
          <?php endif; ?>
          <div class="ci-kids" data-parent="<?= (int)($lead['id'] ?? 0) ?>">
            <?php foreach (($kids[(int)($lead['id'] ?? 0)] ?? []) as $c): ?>
            <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
            <?php endforeach; ?>
            <button type="button" class="ci-addkid">+ Add child</button>
          </div>
        </div>

      <?php elseif ($key === 'party'): ?>
        <p class="ci-party__head">Every other adult needs <?= $showPassport ? 'their own passport' : '' ?><?= $showPassport && $showWaiver ? ' and ' : '' ?><?= $showWaiver ? 'to sign the waiver' : '' ?>. Add each guest, then fill their details or send them their own link.</p>

        <!-- Additional adult cards (data-field inputs → saved via per-guest AJAX, NOT the main submit) -->
        <?php foreach ($adults as $g): if (!empty($g['is_lead'])) continue; $gid = (int)$g['id'];
              $gc = checkin_guest_passport_complete($g) && (!$showWaiver || checkin_guest_waiver_signed($g)); ?>
        <div class="ci-guest" data-guest-id="<?= $gid ?>">
          <div class="ci-guest__title">
            <input class="ci-in ci-guest__name" data-field="passport_name" value="<?= e((string)$g['passport_name']) ?>" placeholder="Guest full name">
            <span class="ci-chip <?= $gc ? 'ci-chip--ok' : '' ?>"><?= $gc ? 'Complete' : 'Pending' ?></span>
            <button type="button" class="ci-guest__remove" aria-label="Remove guest">&times;</button>
          </div>
          <div class="ci-guest__modes">
            <button type="button" class="ci-mode ci-guest__fill">Fill in for them</button>
            <button type="button" class="ci-mode ci-guest__share">Send them a link</button>
          </div>
          <div class="ci-guest__inline" hidden>
            <?php if ($showPassport): ?>
            <label class="ci-l">Passport number</label>
            <input class="ci-in" data-field="passport_number" value="<?= e((string)$g['passport_number']) ?>">
            <label class="ci-l">Nationality</label>
            <input class="ci-in" data-field="nationality" value="<?= e((string)$g['nationality']) ?>">
            <label class="ci-l">Passport expiry</label>
            <input class="ci-in" type="date" data-field="passport_expiry" value="<?= e((string)$g['passport_expiry']) ?>">
            <label class="ci-l">Passport scan</label>
            <div class="ci-upload" data-has="<?= !empty($g['passport_file_key']) ? '1' : '0' ?>">
              <input type="file" accept="image/jpeg,image/png,application/pdf">
              <span class="ci-upload__state"><?= !empty($g['passport_file_key']) ? 'Uploaded &#10003;' : 'No file yet' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($showWaiver): ?>
            <p class="ci-hint">They sign the waiver themselves — use “Send them a link”, or “Sign on this device” from the admin check-in tab if they’re with you.</p>
            <?php endif; ?>
            <button type="button" class="pa-btn pa-btn--primary ci-guest__save">Save this guest</button>
          </div>
          <div class="ci-guest__link" hidden>
            <label class="ci-l">Their private check-in link</label>
            <div class="ci-linkrow"><input class="ci-in" readonly value="<?= e(make_guest_pass_url($holdId, $gid)) ?>" onclick="this.select()"><button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button></div>
          </div>
          <div class="ci-kids" data-parent="<?= $gid ?>">
            <?php foreach (($kids[$gid] ?? []) as $c): ?>
            <span class="ci-kid" data-guest-id="<?= (int)$c['id'] ?>"><?= e((string)$c['passport_name']) ?><button type="button" class="ci-kid__x" aria-label="Remove">&times;</button></span>
            <?php endforeach; ?>
            <button type="button" class="ci-addkid">+ Add child</button>
          </div>
        </div>
        <?php endforeach; ?>

        <button type="button" class="pa-btn pa-btn--ghost ci-addguest" data-need="<?= $need ?>" <?= count($adults) >= $need ? 'hidden' : '' ?>>+ Add adult (<?= count($adults) ?>/<?= $need ?>)</button>
```

- [ ] **Step 4: Check the file parses**

Run: `php -l includes/app/checkin.php`
Expected: `No syntax errors detected in includes/app/checkin.php`

- [ ] **Step 5: Verify both party sizes in the browser**

Open a booking with `guest_count = 1` and walk the wizard. Expected: steps read **Arrival → Airport transfer → Your details → Dietary → Special requests**, with no "Your party" step, and the **+ Add child** control present on Your details.

Then set that booking's `guest_count` to 3 from the admin check-in tab and reload. Expected: a **Your party** step appears directly after **Your details**, showing the add-adult button reading `+ Add adult (1/3)`. The lead's card and signature pad are on **Your details** only.

- [ ] **Step 6: Commit**

```bash
git add includes/app/checkin.php
git commit -m "feat(checkin): split lead details and party into separate steps"
```

---

### Task 8: Render the stored signature so it never looks lost

**Files:**
- Modify: `includes/app/checkin.php` (the `you` branch waiver block, added in Task 7)
- Modify: `js/checkin-wizard.js`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Replace the waiver block in the `you` branch**

In `includes/app/checkin.php`, inside the `<?php elseif ($key === 'you'): ?>` branch, replace this block:

```php
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($lead) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <label class="ci-l">Sign below with your finger</label>
          <div class="ci-sign">
            <button type="button" class="ci-sign-clear">Clear</button>
            <canvas class="ci-sign-pad" data-target="#ciLeadSig"></canvas>
          </div>
          <input type="hidden" name="waiver_signature" id="ciLeadSig">
          <p class="ci-sign-hint">Reception can fill your details, but you sign yourself.</p>
          <?php endif; ?>
```

with:

```php
          <?php if ($showWaiver): $leadSigned = checkin_guest_waiver_signed($lead); ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" class="ci-agree" name="waiver_agree" value="1" <?= $leadSigned ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <!-- data-signed drives the client gate: an existing signature satisfies it
               without the guest having to draw again. The hidden input stays empty
               unless they re-sign, and api/checkin-save.php only overwrites a stored
               signature when a valid new one is posted. -->
          <div class="ci-signwrap" data-signed="<?= $leadSigned ? '1' : '0' ?>">
            <?php if ($leadSigned): ?>
            <div class="ci-signed">
              <img class="ci-signed__img" src="<?= e((string)$lead['waiver_signature']) ?>" alt="Your signature">
              <span class="ci-signed__meta">Signed by <?= e((string)$lead['waiver_signed_name']) ?><br><?= e(date('j M Y', strtotime((string)$lead['waiver_signed_at']))) ?></span>
              <button type="button" class="ci-signed__redo" data-resign>Re-sign</button>
            </div>
            <?php endif; ?>
            <div class="ci-signpad"<?= $leadSigned ? ' hidden' : '' ?>>
              <label class="ci-l">Sign below with your finger</label>
              <div class="ci-sign">
                <button type="button" class="ci-sign-clear">Clear</button>
                <canvas class="ci-sign-pad" data-target="#ciLeadSig"></canvas>
              </div>
              <p class="ci-sign-hint">Reception can fill your details, but you sign yourself.</p>
            </div>
          </div>
          <input type="hidden" name="waiver_signature" id="ciLeadSig">
          <?php endif; ?>
```

- [ ] **Step 2: Add the Re-sign handler**

In `js/checkin-wizard.js`, inside the existing delegated `form.addEventListener('click', function (e) {` block, insert this as the **first** condition — immediately after `var t = e.target;` and before the `ci-next` line:

```js
    if (t.hasAttribute('data-resign')) {   // swap the stored-signature panel for a blank pad
      e.preventDefault();
      var wrap = t.closest('.ci-signwrap');
      wrap.setAttribute('data-signed', '0');
      var panel = wrap.querySelector('.ci-signed'); if (panel) panel.hidden = true;
      var pad   = wrap.querySelector('.ci-signpad'); if (pad) pad.hidden = false;
      // The canvas was already in the DOM (just hidden) so it is initialised;
      // this is idempotent and guards any future cloned markup.
      if (window.ciSignInitAll) window.ciSignInitAll();
      return;
    }
```

- [ ] **Step 3: Add the styles**

In `css/portal-app.css`, find this line:

```css
.ci-sign-hint { font-size: 11px; color: #9aa39a; margin-top: 4px; }
```

Insert this immediately after it:

```css
/* Stored signature, shown in place of a blank pad so a reload never looks like data loss */
.ci-signed{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--pa-line);border-radius:10px;padding:12px 14px;margin:6px 0 4px}
/* display:flex beats the [hidden] UA rule — restore it so Re-sign can hide this */
.ci-signed[hidden]{display:none}
.ci-signed__img{height:52px;max-width:170px;object-fit:contain}
.ci-signed__meta{flex:1;font-size:12.5px;color:var(--pa-muted);line-height:1.45}
.ci-signed__redo{flex:0 0 auto;font:inherit;font-size:13px;font-weight:600;color:var(--pa-teal);background:none;border:0;cursor:pointer;padding:0}
```

- [ ] **Step 4: Verify in the browser**

Open a booking, reach **Your details**, sign the pad, tick the terms box, type a name and click **Save & continue**. Then reload the page and return to **Your details**.

Expected: instead of a blank pad you see the signature image, "Signed by <name>" with the date, and a **Re-sign** button. Clicking **Re-sign** hides the panel and shows a blank, drawable pad.

Then reload again *without* re-signing and click through to the end. Expected: the signature is still on file — confirm with:

```bash
php -r 'require "includes/db.php"; $r = db_query("SELECT waiver_signed_name, length(waiver_signature) AS len FROM checkin_guests WHERE is_lead ORDER BY id DESC LIMIT 1")->fetch(); print_r($r);'
```

Expected: a non-zero `len`.

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin.php js/checkin-wizard.js css/portal-app.css
git commit -m "fix(checkin): render the stored signature instead of a blank pad"
```

---

### Task 9: Add an adult without reloading the page

**Files:**
- Modify: `includes/app/checkin.php` (the `party` branch)
- Modify: `js/checkin-wizard.js`

- [ ] **Step 1: Add the card template**

In `includes/app/checkin.php`, inside the `<?php elseif ($key === 'party'): ?>` branch, insert this immediately **before** the `<button type="button" class="pa-btn pa-btn--ghost ci-addguest" …>` line:

```php
        <!-- Cloned by js/checkin-wizard.js when a new adult is added. Template
             content is inert, so its inputs never post and never match a
             document querySelectorAll. -->
        <template id="ciGuestTpl">
          <div class="ci-guest" data-guest-id="">
            <div class="ci-guest__title">
              <input class="ci-in ci-guest__name" data-field="passport_name" value="" placeholder="Guest full name">
              <span class="ci-chip">Pending</span>
              <button type="button" class="ci-guest__remove" aria-label="Remove guest">&times;</button>
            </div>
            <div class="ci-guest__modes">
              <button type="button" class="ci-mode ci-guest__fill">Fill in for them</button>
              <button type="button" class="ci-mode ci-guest__share">Send them a link</button>
            </div>
            <div class="ci-guest__inline" hidden>
              <?php if ($showPassport): ?>
              <label class="ci-l">Passport number</label>
              <input class="ci-in" data-field="passport_number" value="">
              <label class="ci-l">Nationality</label>
              <input class="ci-in" data-field="nationality" value="">
              <label class="ci-l">Passport expiry</label>
              <input class="ci-in" type="date" data-field="passport_expiry" value="">
              <label class="ci-l">Passport scan</label>
              <div class="ci-upload" data-has="0">
                <input type="file" accept="image/jpeg,image/png,application/pdf">
                <span class="ci-upload__state">No file yet</span>
              </div>
              <?php endif; ?>
              <?php if ($showWaiver): ?>
              <p class="ci-hint">They sign the waiver themselves — use “Send them a link”, or “Sign on this device” from the admin check-in tab if they’re with you.</p>
              <?php endif; ?>
              <button type="button" class="pa-btn pa-btn--primary ci-guest__save">Save this guest</button>
            </div>
            <div class="ci-guest__link" hidden>
              <label class="ci-l">Their private check-in link</label>
              <div class="ci-linkrow"><input class="ci-in" readonly value="" onclick="this.select()"><button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button></div>
            </div>
            <div class="ci-kids" data-parent="">
              <button type="button" class="ci-addkid">+ Add child</button>
            </div>
          </div>
        </template>
```

- [ ] **Step 2: Replace the add-adult handler**

In `js/checkin-wizard.js`, replace this block:

```js
    if (t.classList.contains('ci-addguest')) {   // add adult → save lead, add slot, reload to the party step
      e.preventDefault(); t.disabled = true; t.textContent = 'Adding…';
      saveThen(function () {
        apiPost('/api/checkin-guest.php', { action: 'add_adult' })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
          .then(function () { location.href = '/booking.php?ref=' + encodeURIComponent(REF) + '&view=checkin&ci=party'; })
          .catch(function () { t.disabled = false; t.textContent = '+ Add adult'; });
      });
      return;
    }
```

with:

```js
    if (t.classList.contains('ci-addguest')) {   // add adult → append a card in place; never reload
      e.preventDefault();
      var addBtn = t; addBtn.disabled = true; addBtn.textContent = 'Adding…';
      apiPost('/api/checkin-guest.php', { action: 'add_adult' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (d) {
          var tpl = document.getElementById('ciGuestTpl');
          var card = tpl.content.firstElementChild.cloneNode(true);
          card.setAttribute('data-guest-id', d.guest_id);
          card.querySelector('.ci-kids').setAttribute('data-parent', d.guest_id);
          var link = card.querySelector('.ci-guest__link input');
          if (link) link.value = d.link || '';
          addBtn.parentNode.insertBefore(card, addBtn);
          addBtn.disabled = false;
          updateAddBtn();
          card.querySelector('.ci-guest__name').focus();
        })
        .catch(function () { addBtn.disabled = false; updateAddBtn(); });
      return;
    }
```

The old handler wrapped the call in `saveThen()` because the page was about to navigate away. Nothing navigates now, so the wrapper is gone — that is precisely what stops the lead's in-progress input being discarded.

- [ ] **Step 3: Remove the now-dead resume handler**

In `js/checkin-wizard.js`, replace this block at the bottom of the file:

```js
  // Initial view: resume to a step after a roster reload; else land on intro/done.
  var resume = new URLSearchParams(location.search).get('ci');
  if (resume) {
    var idx = -1; steps.forEach(function (s, i) { if (s.getAttribute('data-key') === resume) idx = i; });
    if (idx >= 0) { openSteps(idx); }
  } else if (!intro && !editBtn) { openSteps(0); }
```

with:

```js
  // Initial view: intro when there is one, else straight into the steps. The old
  // ?ci= resume parameter is gone with the reload that needed it.
  if (!intro && !editBtn) openSteps(0);
```

- [ ] **Step 4: Verify the bug is dead**

Open a booking with `guest_count = 3`. On **Your details**, sign the pad and fill the fields, then click **Save & continue** to reach **Your party**. Click **+ Add adult** twice.

Expected: two new guest cards appear instantly with no page reload; the counter reads `+ Add adult (3/3)` and then hides. Click **← Back** to **Your details**.

Expected: the signature panel is intact — this is the exact scenario that used to lose it. Also confirm the new cards persisted:

```bash
php -r 'require "includes/db.php"; print_r(db_query("SELECT id, is_lead, passport_name FROM checkin_guests WHERE hold_id = (SELECT MAX(hold_id) FROM checkin_guests) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));'
```

Expected: three adult rows, one with `is_lead` true.

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin.php js/checkin-wizard.js
git commit -m "fix(checkin): append adult cards in place instead of reloading"
```

---

### Task 10: Client-side consent gate

**Files:**
- Modify: `js/checkin-wizard.js`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Add the validation functions**

In `js/checkin-wizard.js`, insert this immediately after the `updateAddBtn()` function (which ends just before `form.addEventListener('click', …)`):

```js
  function fieldVal(sec, name) {
    var el = sec.querySelector('[name="' + name + '"]');
    return el ? String(el.value).trim() : '';
  }
  function clearErr(sec) { var box = sec.querySelector('.ci-err'); if (box) box.hidden = true; }
  function showErr(sec, items) {
    var box = sec.querySelector('.ci-err');
    if (!box) {
      box = document.createElement('div');
      box.className = 'ci-err';
      box.setAttribute('role', 'alert');
      sec.insertBefore(box, sec.querySelector('.ci-nav'));
    }
    box.textContent = 'Before you continue, please ' + items.join(', ') + '.';
    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // "Your details" is the consent gate: terms + typed name + a signature, plus
  // the passport fields when that step is configured as required. The wording
  // mirrors checkin_consent_missing() in includes/checkin.php.
  function validateStep(sec) {
    if (!sec) return true;
    clearErr(sec);
    if (sec.getAttribute('data-key') !== 'you') return true;
    var missing = [];
    var agree = sec.querySelector('.ci-agree');
    if (agree) {
      if (!agree.checked) missing.push('agree to the terms');
      if (fieldVal(sec, 'waiver_signed_name') === '') missing.push('type your full name');
      var wrap = sec.querySelector('.ci-signwrap');
      if (wrap && wrap.getAttribute('data-signed') !== '1') {
        var sig = document.getElementById('ciLeadSig');
        if (!sig || sig.value === '') missing.push('draw your signature');
      }
    }
    if (sec.hasAttribute('data-passport-required')) {
      if (fieldVal(sec, 'passport_name') === '')   missing.push('enter your passport name');
      if (fieldVal(sec, 'passport_number') === '') missing.push('enter your passport number');
      var up = sec.querySelector('.ci-upload');
      if (up && up.getAttribute('data-has') !== '1') missing.push('upload your passport scan');
    }
    if (!missing.length) return true;
    showErr(sec, missing);
    return false;
  }
```

- [ ] **Step 2: Gate the Next button**

In `js/checkin-wizard.js`, replace this line:

```js
    if (t.classList.contains('ci-next')) { e.preventDefault(); saveThen(function () { show(cur + 1); }); return; }
```

with:

```js
    if (t.classList.contains('ci-next')) {
      e.preventDefault();
      if (!validateStep(steps[cur])) return;
      saveThen(function () { show(cur + 1); });
      return;
    }
```

- [ ] **Step 3: Gate the final submit**

In `js/checkin-wizard.js`, insert this immediately before the closing `})();` at the very bottom of the file:

```js
  // Final submit re-checks the consent step, so it cannot be skipped by jumping
  // straight to the last step. The server enforces the same rule regardless.
  form.addEventListener('submit', function (e) {
    var you = form.querySelector('.ci-step[data-key="you"]');
    if (you && !validateStep(you)) {
      e.preventDefault();
      var idx = steps.indexOf(you);
      if (idx >= 0) openSteps(idx);
    }
  });
```

- [ ] **Step 4: Add the error style**

In `css/portal-app.css`, find this line:

```css
.ci-alert{max-width:520px;margin:0 auto 16px;background:#fbe6e6;border:1px solid #f0c2c2;color:#a12;border-radius:10px;padding:12px 16px;font-size:14px;line-height:1.5}
```

Insert this immediately after it:

```css
.ci-err{background:#fbe6e6;border:1px solid #f0c2c2;color:#a12;border-radius:9px;padding:11px 14px;font-size:13.5px;line-height:1.55;margin:16px 0 0}
```

- [ ] **Step 5: Verify the gate**

Open a booking, reach **Your details** with nothing filled in, and click **Save & continue**.

Expected: the step does **not** advance; a red box appears above the buttons reading *"Before you continue, please agree to the terms, type your full name, draw your signature, enter your passport name, enter your passport number, upload your passport scan."*

Now tick the box, type a name and draw a signature, but leave the passport scan out. Expected: the error narrows to the passport items only. Fill everything and click again — expected: it advances.

Finally, without signing, click through to the last step (by editing a completed booking via **Update my details**) and press **Complete check-in**. Expected: it jumps back to **Your details** and shows the error.

- [ ] **Step 6: Commit**

```bash
git add js/checkin-wizard.js css/portal-app.css
git commit -m "feat(checkin): block the consent step until terms and signature are given"
```

---

### Task 11: Server-side consent hard-fail

**Files:**
- Modify: `api/checkin-save.php:51-68`

- [ ] **Step 1: Replace the signature block**

In `api/checkin-save.php`, replace this entire block (currently lines 51–68):

```php
// ── Per-guest waiver signature: self-sign only, requires a drawn signature ──
$sig = (string)($_POST['waiver_signature'] ?? '');
$targetIsLead = (bool) db_query('SELECT is_lead FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetchColumn();
if (checkin_signature_supported() && !empty($_POST['waiver_agree']) && $s('waiver_signed_name')
    && checkin_can_sign_self($onlyGuestId, $targetIsLead)
    && checkin_valid_signature($sig)) {
    $terms  = checkin_waiver_text();
    $method = checkin_signing_method((string)($_POST['via'] ?? ''));
    db_query(
        "UPDATE checkin_guests
            SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip,
                waiver_version=:v, waiver_signature=:sig, waiver_terms_snapshot=:terms,
                waiver_signed_user_agent=:ua, waiver_signed_method=:m
          WHERE id=:g AND hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version($terms),
         ':sig'=>$sig, ':terms'=>$terms, ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
         ':m'=>$method, ':g'=>$guestId, ':h'=>$holdId]);
}
```

with:

```php
// ── Per-guest waiver signature: self-sign only, requires a drawn signature ──
$sig = (string)($_POST['waiver_signature'] ?? '');
$targetRow    = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch() ?: null;
$targetIsLead = (bool)($targetRow['is_lead'] ?? false);

// Did this request try to record consent at all? A save from another wizard step
// posts none of these, and must not be treated as a failed signing attempt.
$triedConsent = !empty($_POST['waiver_agree'])
    || trim((string)($_POST['waiver_signed_name'] ?? '')) !== ''
    || $sig !== '';

if ($triedConsent && checkin_signature_supported() && checkin_can_sign_self($onlyGuestId, $targetIsLead)) {
    $already = checkin_guest_waiver_signed($targetRow);
    $missing = checkin_consent_missing(
        !empty($_POST['waiver_agree']),
        (string)($_POST['waiver_signed_name'] ?? ''),
        $sig,
        $already
    );
    if ($missing) {
        // Previously this fell through silently, so a guest could finish the wizard
        // with no agreement and no signature and never be told.
        $msg = 'We could not record your signature — please ' . implode(', ', $missing) . '.';
        if (($_POST['ajax'] ?? '') === '1') {
            http_response_code(422);
            header('Content-Type: application/json');
            exit(json_encode(['ok'=>false, 'error'=>$msg]));
        }
        $_SESSION['ci_error'] = $msg;
        header('Location: ' . $back); exit;
    }
    // A fresh drawing replaces the stored one; an untouched signed panel posts an
    // empty value and leaves the existing signature alone.
    if (checkin_valid_signature($sig)) {
        $terms  = checkin_waiver_text();
        $method = checkin_signing_method((string)($_POST['via'] ?? ''));
        db_query(
            "UPDATE checkin_guests
                SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip,
                    waiver_version=:v, waiver_signature=:sig, waiver_terms_snapshot=:terms,
                    waiver_signed_user_agent=:ua, waiver_signed_method=:m
              WHERE id=:g AND hold_id=:h",
            [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version($terms),
             ':sig'=>$sig, ':terms'=>$terms, ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
             ':m'=>$method, ':g'=>$guestId, ':h'=>$holdId]);
    }
}
```

- [ ] **Step 2: Check the file parses**

Run: `php -l api/checkin-save.php`
Expected: `No syntax errors detected in api/checkin-save.php`

- [ ] **Step 3: Verify the server refuses without the client**

Simulate a JS-disabled or crafted post. In the browser devtools console, on the check-in page:

```js
var f = document.getElementById('ciForm');
var fd = new FormData(f);
fd.set('waiver_signed_name', 'Someone');
fd.set('waiver_agree', '1');
fd.set('waiver_signature', '');
fd.set('ajax', '1');
fetch('/api/checkin-save.php', {method:'POST', body: fd, credentials:'same-origin'})
  .then(function(r){ return r.status + ' ' + r.statusText; }).then(console.log);
```

Expected on a booking whose lead has **not** signed: `422 Unprocessable Entity`. On a booking whose lead **has** already signed: `204 No Content` (the empty signature is read as "leave the stored one alone", which is correct).

- [ ] **Step 4: Commit**

```bash
git add api/checkin-save.php
git commit -m "fix(checkin): reject incomplete consent instead of silently skipping it"
```

---

### Task 12: Completion confirmation for the lead

**Files:**
- Modify: `includes/app/checkin.php`

- [ ] **Step 1: Compute the confirmation state**

In `includes/app/checkin.php`, find this line (currently line 27, just after the `$kids` loop):

```php
$need     = max(1, (int)($hold['guest_count'] ?? 1));
```

Insert this immediately after it:

```php
// The lead has finished their own part but the party has not — acknowledge them
// and show who is still outstanding. The portal itself is already unlocked for
// them (booking.php lifts the gate on submitted_at), so this is information,
// not a lock.
// NB: $fullCfg is checkin_config() — every step with its enabled/required flags.
// It is NOT the same as $cfg (checkin_enabled_steps()) already defined above:
// checkin_guest_complete() reads $config['passport']['enabled'], so it needs the
// full map, including disabled steps.
$fullCfg      = checkin_config();
$outstanding  = checkin_outstanding_adults($guests, $fullCfg);
$unnamedSlots = max(0, $need - count($adults));   // adult slots never added to the roster
$leadDone     = checkin_guest_complete($lead ?: null, $fullCfg)
                && checkin_missing_steps($fullCfg, $data, $lead ?: null) === [];
$leadWaiting  = !$done && $leadDone && ($outstanding || $unnamedSlots > 0);

$waitingNames = [];
foreach ($outstanding as $__i => $__g) {
    $__n = trim((string)($__g['passport_name'] ?? ''));
    $waitingNames[] = $__n !== '' ? explode(' ', $__n)[0] : 'Guest ' . ($__i + 2);
}
$waitingLabel = checkin_waiting_on_label($waitingNames, $unnamedSlots);
```

- [ ] **Step 2: Hide the intro when the lead is waiting**

In `includes/app/checkin.php`, find this line:

```php
  <?php if (!$done): ?>
  <section class="ci-intro" id="ciIntro">
```

Replace the first line with:

```php
  <?php if (!$done && !$leadWaiting): ?>
```

- [ ] **Step 3: Add the confirmation card**

In `includes/app/checkin.php`, find the closing of the existing done-card block:

```php
  <button type="button" class="pa-btn pa-btn--ghost" id="ciEdit">Update my details</button>
</div>
<?php endif; ?>
```

Insert this immediately after that `<?php endif; ?>`:

```php
<?php if ($leadWaiting): ?>
<div class="pa-card ci-done-card">
  <div class="ci-done-card__check">&#10003;</div>
  <h2>Thank you, <?= e($first) ?>. Your check-in is complete.</h2>
  <p>
    <?php if ($waitingLabel !== ''): ?>We&rsquo;re still waiting on <strong><?= e($waitingLabel) ?></strong>. <?php endif; ?>
    Once everyone in your party has checked in, your reservation is fully confirmed.
  </p>
  <?php if ($outstanding): ?>
  <div class="ci-others" style="text-align:left">
    <p class="ci-need__title">Still to check in</p>
    <?php foreach ($outstanding as $og): $ogid = (int)($og['id'] ?? 0); if (!$ogid) continue; ?>
    <div class="ci-other__row">
      <span><?= e(trim((string)($og['passport_name'] ?? '')) !== '' ? (string)$og['passport_name'] : 'Unnamed guest') ?></span>
      <span class="ci-chip">Pending</span>
    </div>
    <div class="ci-linkrow" style="margin-bottom:12px">
      <input class="ci-in" readonly value="<?= e(make_guest_pass_url($holdId, $ogid)) ?>" onclick="this.select()">
      <button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <a class="pa-btn pa-btn--primary" href="/booking.php?ref=<?= e($ref) ?>&view=home">Continue to your stay &rarr;</a>
  <?php if (checkin_guest_waiver_signed($lead)): ?>
  <a class="pa-btn pa-btn--ghost" href="/admin/consent-print.php?hold=<?= $holdId ?>&guest=<?= (int)($lead['id'] ?? 0) ?>&ref=<?= e($ref) ?>" target="_blank">Download my signed waiver</a>
  <?php endif; ?>
  <button type="button" class="pa-btn pa-btn--ghost" id="ciEdit">Update my details</button>
</div>
<?php endif; ?>
```

The **Copy** buttons reuse the existing `.ci-copy` handler in `js/checkin-wizard.js` — it is delegated from `#ciForm`, and this card sits outside the form, so add the delegation for it in the next step.

- [ ] **Step 4: Make Copy work outside the form**

In `js/checkin-wizard.js`, the `.ci-copy` handler is inside the `form` click listener. Add a document-level fallback by inserting this immediately before the closing `})();` at the bottom of the file:

```js
  // The confirmation card sits outside #ciForm, so its Copy buttons need their
  // own delegation. Same behaviour as the in-form handler.
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains('ci-copy') || t.closest('#ciForm')) return;
    e.preventDefault();
    var inp = t.closest('.ci-linkrow').querySelector('input'); inp.select();
    try { navigator.clipboard.writeText(inp.value); } catch (_) { try { document.execCommand('copy'); } catch (__) {} }
    var o = t.textContent; t.textContent = 'Copied ✓'; setTimeout(function () { t.textContent = o; }, 1500);
  });
```

- [ ] **Step 5: Check the file parses**

Run: `php -l includes/app/checkin.php`
Expected: `No syntax errors detected in includes/app/checkin.php`

- [ ] **Step 6: Verify all three states**

**State 1 — lead waiting.** Take a booking with `guest_count = 2`. Complete the lead fully (passport, scan, terms, signature) and submit. Expected: a card reading *"Thank you, <name>. Your check-in is complete."* and *"We're still waiting on 1 more guest."* (or the second adult's first name if you added one), plus a **Continue to your stay →** button. Clicking that reaches the portal home — the lead is not locked out.

**State 2 — whole party done.** Complete the second adult via their `?g=` link. Reload the lead's check-in page. Expected: the original *"You're all checked in"* card, not the waiting card.

**State 3 — solo booking.** Set `guest_count = 1` and complete the lead. Expected: the *"You're all checked in"* card immediately; the waiting card never appears.

- [ ] **Step 7: Commit**

```bash
git add includes/app/checkin.php js/checkin-wizard.js
git commit -m "feat(checkin): confirm the lead's check-in and list who is outstanding"
```

---

### Task 13: Show the arrival mode in the admin workspace

**Files:**
- Modify: `admin/_ws_checkin.php:66-77`

- [ ] **Step 1: Replace the arrival rows**

In `admin/_ws_checkin.php`, replace this block (currently lines 66–77):

```php
<?php if ($__ci): ?>
<div class="card" style="margin-bottom:16px"><div class="card__body">
  <table class="data-table" style="max-width:600px">
    <tr><td class="text-muted">Airport</td><td><?= $__fmt($__ci['arrival_airport'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Flight</td><td><?= $__fmt($__ci['flight_number'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
    <tr><td class="text-muted">Dietary</td><td><?= $__fmt($__ci['dietary'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Requests</td><td><?= $__fmt($__ci['special_requests'] ?? '') ?></td></tr>
  </table>
</div></div>
<?php endif; ?>
```

with:

```php
<?php if ($__ci): ?>
<?php
  $__amOn  = checkin_arrival_mode_supported();
  $__mode  = $__amOn ? trim((string)($__ci['arrival_mode'] ?? '')) : '';
  $__modes = checkin_arrival_modes();
?>
<div class="card" style="margin-bottom:16px"><div class="card__body">
  <table class="data-table" style="max-width:600px">
    <?php if ($__amOn): ?>
    <tr><td class="text-muted">Arriving</td><td><?= $__mode !== '' ? e($__modes[$__mode] ?? $__mode) : '<span class="text-muted">—</span>' ?></td></tr>
    <?php endif; ?>
    <?php if ($__mode === '' || $__mode === 'flight'): ?>
    <tr><td class="text-muted">Airport</td><td><?= $__fmt($__ci['arrival_airport'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Flight</td><td><?= $__fmt($__ci['flight_number'] ?? '') ?></td></tr>
    <?php elseif ($__mode === 'road'): ?>
    <tr><td class="text-muted">Vehicle</td><td><?= $__fmt($__ci['arrival_vehicle'] ?? '') ?></td></tr>
    <?php else: ?>
    <tr><td class="text-muted">Arriving by</td><td><?= $__fmt($__ci['arrival_note'] ?? '') ?></td></tr>
    <?php endif; ?>
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
    <tr><td class="text-muted">Dietary</td><td><?= $__fmt($__ci['dietary'] ?? '') ?></td></tr>
    <tr><td class="text-muted">Requests</td><td><?= $__fmt($__ci['special_requests'] ?? '') ?></td></tr>
  </table>
</div></div>
<?php endif; ?>
```

- [ ] **Step 2: Check the file parses**

Run: `php -l admin/_ws_checkin.php`
Expected: `No syntax errors detected in admin/_ws_checkin.php`

- [ ] **Step 3: Verify in the browser**

Log into admin, open the booking you set to **By road** in Task 6, and go to the **Check-in** tab.

Expected: an **Arriving** row reading *By road*, a **Vehicle** row with the plate, and **no** Airport or Flight rows. Switch a different booking to **By air** and confirm the Airport and Flight rows return.

- [ ] **Step 4: Commit**

```bash
git add admin/_ws_checkin.php
git commit -m "feat(admin): show arrival mode and its fields on the check-in tab"
```

---

### Task 14: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Run every test suite**

```bash
for f in tests/*_logic.php; do echo "── $f"; php "$f" || echo "FAILED: $f"; done
```

Expected: every suite ends `ALL PASS`. `checkin_logic.php` should now carry roughly 40 more assertions than the baseline.

- [ ] **Step 2: Lint every file the plan touched**

```bash
for f in includes/checkin.php includes/app/checkin.php api/checkin-save.php admin/_ws_checkin.php tests/checkin_logic.php; do php -l "$f"; done
```

Expected: `No syntax errors detected` five times.

- [ ] **Step 3: Walk the whole flow once, end to end**

On a fresh booking with `guest_count = 2` and `require_checkin` on:

1. Open the guest link. Start check-in.
2. Arrival: pick **By road**, enter a plate and a time. Continue.
3. Transfer: answer **No**. Continue.
4. Your details: click Continue with nothing filled → the error lists every missing item.
5. Fill passport, upload a scan, tick terms, type a name, sign. Continue.
6. Your party: click **+ Add adult**. A card appears with no reload. Click **← Back**.
7. **The signature is still shown.** This is the headline fix — confirm it.
8. Continue to the end and submit.
9. Confirmation card thanks the lead by name and lists the outstanding guest with a copyable link.
10. Click **Continue to your stay →** — the portal home opens.
11. Open the outstanding guest's link, complete their check-in.
12. Reload the lead's check-in page → the *"You're all checked in"* card.

- [ ] **Step 4: Confirm nothing regressed pre-migration**

The `*_supported()` guards must keep the page alive on a database without `add_checkin_arrival.sql`. Simulate it:

```bash
php -r '
require "includes/db.php";
db_query("ALTER TABLE booking_checkin DROP COLUMN IF EXISTS arrival_mode, DROP COLUMN IF EXISTS arrival_vehicle, DROP COLUMN IF EXISTS arrival_note");
echo "dropped\n";'
```

Reload the guest check-in page. Expected: the arrival step renders the plain airport text-free flight form with **no** mode radios, and nothing fatals. Then restore:

```bash
php bin/migrate.php db/migrations/add_checkin_arrival.sql
```

- [ ] **Step 5: Report**

Summarise: which steps passed, anything that did not, and the final `git log --oneline` for the branch. Do **not** claim success for any step you did not actually run.

---

## Notes for the implementer

- **`$val()` in `includes/app/checkin.php`** is `fn($k, $src = null) => e((string)(($src ?? $data)[$k] ?? ''))` — it escapes for you. Do not wrap it in `e()` again.
- **`$data` may be an empty array** when the guest has never saved. Every new read uses `?? ''` for that reason.
- **The `checkin_guests` lead row may not exist yet.** `$lead` is `checkin_lead_guest($holdId) ?? []`, so `$lead['id']` can be absent — the existing code already guards with `(int)($lead['id'] ?? 0)`. Keep that.
- **Do not touch `includes/app/checkin-guest.php`** (the co-guest page). Its own done-card and signing flow are out of scope for this plan.
- **Do not change `checkin_recompute_completion()`.** Completion semantics are unchanged; only the presentation of an in-between state is new.
