# Arrival & Transfer Step Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ask only *how* the guest arrives and *when they want their room* on step 1; move the airport and flight details to step 2 behind "yes, I want a transfer"; and add a check-out transfer.

**Architecture:** The desired check-in time becomes universal, so the early/late warning reads one field for every arrival mode instead of branching on mode. `arrival_at` reverts to meaning only "flight landing time". Three new columns carry the return leg, behind the usual `*_supported()` guard.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS (ES5 style), vanilla CSS. Tests are plain PHP scripts with a `check()` helper. **One migration.**

**Spec:** `docs/superpowers/specs/2026-08-13-transfer-step-restructure-design.md`

---

## Before you start

**Read the spec.** Then understand these three things, because every task depends on them.

**1. `arrival_at` is the flight LANDING time.** A flight landing at 10:00 in Mombasa puts the guest at the villa around 12:00–13:00. The check-in window is never checked against it. After this change it is collected only from flying guests who want a transfer.

**2. The desired check-in time becomes universal.** `property_arrival_time` is currently asked of fliers only; after this change every mode is asked. That deletes the `$isFlight ? $paSaved : $atTime` branch from the warning path.

**3. Legacy rows are healed by PREFILLING the field, not by a read-time branch.**

The spec says "read-time fallback". **Implement it as a prefill instead** — it is strictly better and the reason matters:

> A road booking saved before this change has its time in `arrival_at` and `property_arrival_time` empty. If PHP applied a fallback at flag time but JS read only the input field, the server would render a warning that the JS would immediately hide on first tick. **That is exactly the server/JS disagreement bug this project has hit before** (see the note in `CLAUDE.md`).
>
> Prefilling the input with the fallback value means PHP and JS read the same string, the guest sees a sensible pre-filled time, and the next save writes it into `property_arrival_time` — healing the row. No fallback logic in the JS at all.

**Conventions** (`CLAUDE.md`):

- Escape output with `e()`. `db_query()` with bound parameters.
- Migration-added columns sit behind a cached `*_supported()` guard.
- **NEVER bind a PHP bool.** `PDO::ATTR_EMULATE_PREPARES` renders `false` as `''` and Postgres rejects it for a boolean column. Bind `'TRUE'`/`'FALSE'`/`null`. This took check-in down in production (PR #56). `needs_departure_transfer` is a boolean — `api/checkin-save.php:36-37` is the pattern to copy.
- **Hidden is not absent.** A hidden `<input>` still submits. Every field stays in the DOM and is hidden by mode, so values round-trip and nothing is silently nulled. Only the existing deliberate clear (airport/flight on mode switch) removes data.
- The app runs in `Africa/Nairobi`. These are wall-clock strings — introduce no conversion.

**Baseline:** `php tests/checkin_logic.php` ends `ALL PASS`. `php tests/team_logic.php` has one known pre-existing failure (`owner: home = dashboard`) — ignore it.

**Committing:** the tree has unrelated pre-existing changes (`.claude/launch.json`, two untracked `Archive*.zip`). **NEVER `git add -A` or `git add .`.**

**Verification:** do not open a browser — the requester does the walkthrough. Render partials server-side. Admin pages cannot be logged into here; fake `$_SESSION['admin_id']` / `$_SESSION['admin_role']`. Never enter a password. Clean up every fixture row you create. Note `hold` #469 ("ZZ Chk Guest") is a pre-existing leaked fixture — leave it alone.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `db/migrations/add_departure_transfer.sql` | Three nullable columns | Create |
| `includes/checkin.php` | Support guard, `checkin_desired_time()`, completeness rules | Modify |
| `tests/checkin_logic.php` | Assertions for all three | Modify |
| `includes/app/checkin.php` | Step 1 and step 2 rebuilt | Modify |
| `js/checkin-wizard.js` | Transfer toggles, cross-step mode read, simplified warning | Modify |
| `api/checkin-save.php` | Persist the three new fields | Modify |
| `admin/_ws_checkin.php` | Show the return leg | Modify |

---

### Task 1: Migration and support guard

**Files:** `db/migrations/add_departure_transfer.sql`, `includes/checkin.php`, `tests/checkin_logic.php`

- [ ] **Step 1: Create the migration**

Create `db/migrations/add_departure_transfer.sql`:

```sql
-- Tribal Sand: the check-out transfer. Run via /admin/migrate.php. Idempotent.
--
-- departure_time is when the CAR LEAVES THE PROPERTY, not when a flight departs.
-- The guest is asked for a pickup time and a destination; working back from a
-- flight is the team's job.
--
-- A TIME, not a timestamp, for the same reason as property_arrival_time: the date
-- is already known (the check-out day) and a bare time is what the driver reads off.
--
-- needs_departure_transfer is a BOOLEAN. Bind 'TRUE'/'FALSE'/null, never a PHP
-- bool -- PDO::ATTR_EMULATE_PREPARES renders false as '' and Postgres rejects it.
ALTER TABLE booking_checkin
  ADD COLUMN IF NOT EXISTS needs_departure_transfer BOOLEAN,
  ADD COLUMN IF NOT EXISTS departure_destination    TEXT,
  ADD COLUMN IF NOT EXISTS departure_time           TIME;
```

- [ ] **Step 2: Write the failing test**

In `tests/checkin_logic.php`, find:

```php
check('checkin_property_arrival_supported is bool', is_bool(checkin_property_arrival_supported()));
```

Insert immediately after it:

```php
check('checkin_departure_transfer_supported is bool', is_bool(checkin_departure_transfer_supported()));
```

- [ ] **Step 3: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_departure_transfer_supported()`. Confirm you see it.

- [ ] **Step 4: Add the guard**

In `includes/checkin.php`, insert immediately after `checkin_property_arrival_supported()` (it ends at line 115):

```php
/** True once add_departure_transfer.sql is applied. Cached per-request. */
function checkin_departure_transfer_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT departure_time FROM booking_checkin LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```

- [ ] **Step 5: Apply and verify**

```bash
php -r 'require "includes/db.php"; require "includes/checkin.php"; var_dump(checkin_departure_transfer_supported());'
php bin/migrate.php db/migrations/add_departure_transfer.sql
php bin/migrate.php db/migrations/add_departure_transfer.sql
php -r 'require "includes/db.php"; require "includes/checkin.php"; var_dump(checkin_departure_transfer_supported());'
```

Expected: `bool(false)`, then two successful `OK` runs proving idempotency, then `bool(true)`. Then `php tests/checkin_logic.php` → `ALL PASS`.

Also confirm the column types:

```bash
php -r 'require "includes/db.php";
foreach (db_query("SELECT column_name,data_type,is_nullable FROM information_schema.columns WHERE table_name=:t AND column_name IN (:a,:b,:c)",
  [":t"=>"booking_checkin",":a"=>"needs_departure_transfer",":b"=>"departure_destination",":c"=>"departure_time"])->fetchAll() as $r)
  printf("  %-26s %-26s null=%s\n", $r["column_name"], $r["data_type"], $r["is_nullable"]);'
```

Expected: `boolean`, `text`, `time without time zone`, all `null=YES`.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/add_departure_transfer.sql includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): add the departure transfer columns and their support guard"
```

---

### Task 2: `checkin_desired_time()` — one field, with legacy healing

**Files:** `includes/checkin.php`, `tests/checkin_logic.php`

This is the helper that makes the warning mode-agnostic. It resolves the value that goes **into the input field**, which is also the value the flag is computed from — so PHP and JS cannot disagree.

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find the line you added in Task 1:

```php
check('checkin_departure_transfer_supported is bool', is_bool(checkin_departure_transfer_supported()));
```

Insert immediately after it:

```php
// ── Desired check-in time, with legacy prefill ──────────────────────────────
check('desired: uses property_arrival_time',
    checkin_desired_time(['arrival_mode'=>'flight','property_arrival_time'=>'10:00:00']) === '10:00');
check('desired: trims seconds',
    checkin_desired_time(['property_arrival_time'=>'09:05:00']) === '09:05');
check('desired: road falls back to arrival_at',
    checkin_desired_time(['arrival_mode'=>'road','arrival_at'=>'2026-09-10 09:00:00+03']) === '09:00');
check('desired: other falls back to arrival_at',
    checkin_desired_time(['arrival_mode'=>'other','arrival_at'=>'2026-09-10 21:30:00+03']) === '21:30');
check('desired: flight NEVER falls back to the landing time',
    checkin_desired_time(['arrival_mode'=>'flight','arrival_at'=>'2026-09-10 10:00:00+03']) === '');
check('desired: no mode NEVER falls back (legacy flight-only form)',
    checkin_desired_time(['arrival_at'=>'2026-09-10 10:00:00+03']) === '');
check('desired: set value beats the fallback',
    checkin_desired_time(['arrival_mode'=>'road','arrival_at'=>'2026-09-10 09:00:00+03','property_arrival_time'=>'15:00:00']) === '15:00');
check('desired: empty data',            checkin_desired_time([]) === '');
check('desired: null data',             checkin_desired_time(null) === '');
check('desired: road with no arrival_at', checkin_desired_time(['arrival_mode'=>'road']) === '');
```

The `flight NEVER falls back` case is the important one: `arrival_at` is a landing time in flight mode, and warning on it is the exact bug this whole feature exists to avoid.

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_desired_time()`. Confirm you see it.

- [ ] **Step 3: Add the helper**

In `includes/checkin.php`, insert immediately after `checkin_arrival_flag()` (it ends with the closing brace of the `return '';` block):

```php
/**
 * The guest's desired check-in time as HH:MM, or '' if unknown.
 *
 * This is both what the input field is rendered with and what the early/late
 * warning is computed from, so the server render and the live JS read the same
 * string and cannot contradict each other.
 *
 * Legacy healing: before the desired check-in time existed, a road/other guest's
 * arrival_at WAS the time they wanted their room, so it is used to prefill the
 * field. The next save then writes it into property_arrival_time and the row is
 * healed. Never for flight, and never when no mode is set (the legacy form was
 * flight-only) — arrival_at is a LANDING time there, hours before the guest
 * reaches the property.
 */
function checkin_desired_time(?array $data): string {
    $d  = $data ?? [];
    $pa = trim((string)($d['property_arrival_time'] ?? ''));
    if ($pa !== '') return substr($pa, 0, 5);

    $mode = trim((string)($d['arrival_mode'] ?? ''));
    if ($mode !== 'road' && $mode !== 'other') return '';

    $at = trim((string)($d['arrival_at'] ?? ''));
    return $at !== '' ? date('H:i', strtotime($at)) : '';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: all ten new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): resolve the desired check-in time, healing legacy rows"
```

---

### Task 3: Step completeness rules

**Files:** `includes/checkin.php`, `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find the block you added in Task 2 and insert immediately after its last line (`check('desired: road with no arrival_at', …)`):

```php
// ── Arrival step is complete once a mode is chosen ──────────────────────────
check('arrival: flight alone is enough now',  checkin_arrival_complete(['arrival_mode'=>'flight']) === true);
check('arrival: road alone is enough',        checkin_arrival_complete(['arrival_mode'=>'road'])   === true);
check('arrival: other alone is enough',       checkin_arrival_complete(['arrival_mode'=>'other'])  === true);
check('arrival: nothing chosen is incomplete',checkin_arrival_complete([])                          === false);
check('arrival: unknown mode is incomplete',  checkin_arrival_complete(['arrival_mode'=>'zzz'])     === false);
check('arrival: legacy no-mode row still passes on its old rule',
    checkin_arrival_complete(['flight_number'=>'KQ610','arrival_at'=>'2026-09-10 10:00:00+03']) === true);

// ── Transfer step covers both legs ──────────────────────────────────────────
$tc = fn(array $d) => checkin_step_complete('transfer', $d, null);
check('transfer: nothing answered',        $tc([]) === false);
check('transfer: arrival answered, departure not',
    $tc(['needs_transfer'=>'f']) === false);
check('transfer: both no',
    $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'f']) === true);
check('transfer: arrival yes + flying needs the flight fields',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'flight','needs_departure_transfer'=>'f']) === false);
check('transfer: arrival yes + flying, fields given',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'flight','arrival_airport'=>'MBA',
         'flight_number'=>'KQ610','arrival_at'=>'2026-09-10 10:00:00+03',
         'needs_departure_transfer'=>'f']) === true);
check('transfer: arrival yes + road needs a pickup point',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'road','needs_departure_transfer'=>'f']) === false);
check('transfer: arrival yes + road, pickup given',
    $tc(['needs_transfer'=>'t','arrival_mode'=>'road','transfer_details'=>'Likoni ferry',
         'needs_departure_transfer'=>'f']) === true);
check('transfer: departure yes needs a destination and a time',
    $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t']) === false);
check('transfer: departure yes, destination only',
    $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t','departure_destination'=>'MBA']) === false);
check('transfer: departure yes, both given',
    $tc(['needs_transfer'=>'f','needs_departure_transfer'=>'t','departure_destination'=>'MBA',
         'departure_time'=>'12:00:00']) === true);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: failures — `arrival: flight alone is enough now` FAILs (the old rule still demands airport, flight number and arrival time), and the departure-leg cases FAIL because nothing reads those columns yet. Confirm which lines fail before continuing.

- [ ] **Step 3: Simplify `checkin_arrival_complete()`**

In `includes/checkin.php`, replace the whole docblock and body:

```php
/**
 * Is the arrival step complete? Rules by mode:
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

with:

```php
/**
 * Is the arrival step complete? The step now asks only HOW the guest arrives —
 * the airport and flight details moved to the transfer step, and the desired
 * check-in time is deliberately optional (the guest is warned, never blocked).
 * So choosing a mode is the whole requirement.
 *
 * A row with no mode is pre-add_checkin_arrival.sql and keeps its old rule, so
 * an unmigrated deployment does not suddenly mark every arrival step incomplete.
 * Pure.
 */
function checkin_arrival_complete(?array $data): bool {
    $d    = $data ?? [];
    $mode = trim((string)($d['arrival_mode'] ?? ''));
    if ($mode !== '') return array_key_exists($mode, checkin_arrival_modes());
    $has = fn($k) => trim((string)($d[$k] ?? '')) !== '';
    return $has('flight_number') && $has('arrival_at');
}
```

- [ ] **Step 4: Extend the transfer rule**

In `includes/checkin.php`, inside `checkin_step_complete()`, replace:

```php
        case 'transfer':
            if (!array_key_exists('needs_transfer', $data) || $data['needs_transfer'] === null) return false;
            $wants = ($data['needs_transfer'] === true || $data['needs_transfer'] === 't' || $data['needs_transfer'] === '1' || $data['needs_transfer'] === 1);
            return $wants ? trim((string)($data['transfer_details'] ?? '')) !== '' : true;
```

with:

```php
        case 'transfer':
            // Postgres hands booleans back as 't'/'f' under PDO; the form posts
            // '1'/'0'. Both legs read through the same coercion.
            $yes = function ($v): ?bool {
                if ($v === null) return null;
                if ($v === true  || $v === 't' || $v === '1' || $v === 1) return true;
                if ($v === false || $v === 'f' || $v === '0' || $v === 0) return false;
                return null;
            };
            $inb  = $yes($data['needs_transfer'] ?? null);
            $outb = checkin_departure_transfer_supported()
                ? $yes($data['needs_departure_transfer'] ?? null) : false;
            if ($inb === null || $outb === null) return false;
            if ($inb) {
                // Flying: the airport, flight and landing time ARE the detail.
                // Otherwise we need somewhere to collect them from.
                $mode = trim((string)($data['arrival_mode'] ?? ''));
                $ok = $mode === 'flight'
                    ? ($has('arrival_airport') && $has('flight_number') && $has('arrival_at'))
                    : $has('transfer_details');
                if (!$ok) return false;
            }
            if ($outb && !($has('departure_destination') && $has('departure_time'))) return false;
            return true;
```

Note `$has` is already defined at the top of `checkin_step_complete()` — do not redeclare it.

Note also `$outb` is forced to `false` (rather than `null`) pre-migration, so the step does not become permanently incomplete on a deployment that has not run the migration yet.

**One consequence for the tests:** because of that forced `false`, the `transfer: arrival answered, departure not` assertion only holds once Task 1's migration is applied — on an unmigrated database the step would legitimately be complete. Task 1 applies it, so the suite passes in order. This environment-dependence is the same reason the `*_supported()` assertions elsewhere in the suite only check `is_bool` rather than `=== true`.

- [ ] **Step 5: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: all new lines PASS, run ends `ALL PASS`.

Then confirm nothing else regressed:

```bash
for f in tests/*_logic.php; do printf "%-26s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`'s one known failure.

- [ ] **Step 6: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): arrival needs only a mode; transfer covers both legs"
```

---

### Task 4: Step 1 asks how they arrive and when they want their room

**Files:** `includes/app/checkin.php`

- [ ] **Step 1: Replace the arrival branch's PHP preamble**

In `includes/app/checkin.php`, inside the `<?php if ($key === 'arrival'): ?>` branch, replace the **entire** preamble — from `<?php` through the closing `?>` — which currently reads:

```php
        <?php
          $amOn     = checkin_arrival_mode_supported();
          $modes    = checkin_arrival_modes();
          $airports = checkin_airports();
          $mode     = $amOn ? trim((string)($data['arrival_mode'] ?? '')) : '';
          if (!array_key_exists($mode, $modes)) $mode = $amOn ? 'flight' : '';
          $savedAir = trim((string)($data['arrival_airport'] ?? ''));
          // A saved airport that isn't in the catalog came from the "Other" box.
          $airOther = $savedAir !== '' && !array_key_exists($savedAir, $airports);
          $T        = checkin_times();
          $paOn     = checkin_property_arrival_supported();
          // What the guest wants their room for, which is what the window is
          // checked against. Flight mode captures the LANDING time in arrival_at,
          // so the desired check-in time is a separate field. In road/other,
          // arrival_at is when they drive up — which is when they want in — so
          // the flag reads its time part.
          $paSaved  = $paOn ? trim((string)($data['property_arrival_time'] ?? '')) : '';
          $paSaved  = $paSaved !== '' ? substr($paSaved, 0, 5) : '';
          $atTime   = !empty($data['arrival_at']) ? date('H:i', strtotime((string)$data['arrival_at'])) : '';
          // No arrival_mode column yet → the legacy form was flight-only, so arrival_at
          // holds a LANDING time. Treat that as flight (same fallback as line 170 and
          // checkin_arrival_complete()) or we would warn on the landing time itself.
          $isFlight = $amOn ? ($mode === 'flight') : true;
          $flagTime = $isFlight ? $paSaved : $atTime;
          $flag     = checkin_arrival_flag($flagTime, $T['ci_from'], $T['ci_to']);
        ?>
```

with:

```php
        <?php
          $amOn     = checkin_arrival_mode_supported();
          $modes    = checkin_arrival_modes();
          $mode     = $amOn ? trim((string)($data['arrival_mode'] ?? '')) : '';
          if (!array_key_exists($mode, $modes)) $mode = $amOn ? 'flight' : '';
          $T        = checkin_times();
          $paOn     = checkin_property_arrival_supported();
          // One field for every mode now, so the server render and the live JS
          // read the same string. checkin_desired_time() also prefills from a
          // legacy road/other arrival_at, which the next save then heals.
          $paSaved  = $paOn ? checkin_desired_time($data) : '';
          $flag     = checkin_arrival_flag($paSaved, $T['ci_from'], $T['ci_to']);
        ?>
```

`$airports`, `$savedAir` and `$airOther` are deleted here because Step 2 removes the only markup that used them. **Do not rely on them surviving into the transfer step** — each step is a separate branch of the same `foreach`, so those variables would only exist if the arrival step happened to render first *and* is enabled in `checkin_steps`. Task 5 deliberately recomputes its own `$airports2` / `$savedAir2` / `$airOther2` for that reason.

`$isFlight` and `$atTime` are deleted because the warning no longer branches on mode. If `php -l` passes but you see an "undefined variable" notice at render time, you have left a reference behind — grep the arrival branch for all five names before moving on.

- [ ] **Step 2: Remove the flight and landing-time fields from step 1**

Still in the arrival branch, delete these three blocks entirely:

```php
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
```

```php
        <?php // $isFlight, not ($mode === 'flight'): identical whenever $amOn, but pre-migration
              // ($amOn false, $mode '') arrival_at IS treated as a landing time and the flight
              // field groups above ARE shown, so the label must say so too. ?>
        <label class="ci-l" data-mode-label="flight"<?= $isFlight ? '' : ' hidden' ?>>Flight arrival <span class="ci-opt">(landing time)</span></label>
        <label class="ci-l" data-mode-label="other"<?= $isFlight ? ' hidden' : '' ?>>When do you expect to reach us?</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
```

```php
        <?php if ($paOn): ?>
        <div class="ci-mode-fields" data-mode="flight"<?= ($amOn && $mode !== 'flight') ? ' hidden' : '' ?>>
          <label class="ci-l">Desired check-in time</label>
          <input class="ci-in ci-f-patime" type="time" name="property_arrival_time" value="<?= e($paSaved) ?>">
        </div>
        <?php endif; ?>
```

The road and other `.ci-mode-fields` blocks (vehicle, note) stay exactly as they are.

- [ ] **Step 3: Add the universal desired check-in time**

Immediately after the `data-mode="other"` block (the one containing `name="arrival_note"`) and immediately **before** the `<p class="ci-times" …>` paragraph, insert:

```php
        <?php if ($paOn): ?>
        <label class="ci-l">Desired check-in time <span class="ci-opt">(optional)</span></label>
        <input class="ci-in ci-f-patime" type="time" name="property_arrival_time" value="<?= e($paSaved) ?>">
        <?php endif; ?>
```

No `.ci-mode-fields` wrapper and no `data-mode` — this is asked of everyone, which is the whole point of the change.

- [ ] **Step 4: Relabel the step**

In `includes/checkin.php`, in the step catalog, replace:

```php
        'arrival'  => ['label' => 'Arrival & flight',     'default_required' => false],
        'transfer' => ['label' => 'Airport transfer',     'default_required' => false],
```

with:

```php
        'arrival'  => ['label' => 'How you’ll arrive',    'default_required' => false],
        'transfer' => ['label' => 'Transfers',            'default_required' => false],
```

- [ ] **Step 5: Verify**

Run: `php -l includes/app/checkin.php && php -l includes/checkin.php && php tests/checkin_logic.php`
Expected: no syntax errors, `ALL PASS`.

Then confirm step 1's shape by rendering it. Build a fixture hold, render `includes/app/checkin.php` for each mode, and report for each: which field names appear, and whether the warning is shown or hidden.

```bash
php -r '
require_once "includes/db.php"; require_once "includes/booking.php"; require_once "includes/checkin.php";
$u=(int)db_query("SELECT id FROM units WHERE is_active LIMIT 1")->fetchColumn();
$h=create_hold_with_block($u,null,date("Y-m-d",strtotime("+320 days")),date("Y-m-d",strtotime("+322 days")),"ZZS1 Fixture","zzs1@example.com","confirmed");
db_query("INSERT INTO booking_checkin (hold_id) VALUES (:h)",[":h"=>$h]);
foreach ([["flight","10:00"],["road","10:00"],["other","16:00"],["road",null]] as [$m,$p]) {
  db_query("UPDATE booking_checkin SET arrival_mode=:m, property_arrival_time=:p WHERE hold_id=:h",[":m"=>$m,":p"=>$p,":h"=>$h]);
  $d=fetch_checkin($h);
  printf("  %-7s pat=%-6s desired=%-6s flag=%s\n", $m, $p ?? "-", checkin_desired_time($d) ?: "-",
    checkin_arrival_flag(checkin_desired_time($d), "14:00", "20:00") ?: "none");
}
db_query("DELETE FROM booking_checkin WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]);
echo "  cleaned up\n";'
```

Expected: `flight`/`road` at `10:00` → `early`; `other` at `16:00` → `none`; `road` with no value → `-`/`none`.

**Report:** confirm that `arrival_at`, `arrival_airport` and `flight_number` no longer appear anywhere in the rendered step 1, and that `property_arrival_time` appears exactly once with no `hidden` wrapper.

- [ ] **Step 6: Commit**

```bash
git add includes/app/checkin.php includes/checkin.php
git commit -m "feat(checkin): step 1 asks how you arrive and when you want your room"
```

---

### Task 5: Step 2 carries both transfer legs

**Files:** `includes/app/checkin.php`

- [ ] **Step 1: Replace the transfer branch**

In `includes/app/checkin.php`, replace this entire block:

```php
      <?php elseif ($key === 'transfer'): ?>
        <label class="ci-l">Would you like us to arrange your airport transfer?</label>
        <?php $nt = $data['needs_transfer'] ?? null; $ntYes = ($nt === true || $nt === 't' || $nt === '1'); $ntNo = ($nt === false || $nt === 'f' || $nt === '0'); ?>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I'll make my own way</label>
        <label class="ci-l">Transfer details (pickup point, pax, luggage)</label>
        <textarea class="ci-in" name="transfer_details" rows="3"><?= $val('transfer_details') ?></textarea>
```

with:

```php
      <?php elseif ($key === 'transfer'): ?>
        <?php
          $amOn2    = checkin_arrival_mode_supported();
          $mode2    = $amOn2 ? trim((string)($data['arrival_mode'] ?? '')) : '';
          // Same legacy rule as everywhere else: no mode column means the old
          // flight-only form, so treat the guest as flying.
          $isFlight2 = $amOn2 ? ($mode2 === 'flight') : true;
          $airports2 = checkin_airports();
          $savedAir2 = trim((string)($data['arrival_airport'] ?? ''));
          $airOther2 = $savedAir2 !== '' && !array_key_exists($savedAir2, $airports2);
          $dtOn      = checkin_departure_transfer_supported();
          $yn = function ($v): array {
              return [($v === true  || $v === 't' || $v === '1'),
                      ($v === false || $v === 'f' || $v === '0')];
          };
          [$ntYes, $ntNo] = $yn($data['needs_transfer'] ?? null);
          [$dtYes, $dtNo] = $yn($data['needs_departure_transfer'] ?? null);
          $depTime = $dtOn ? trim((string)($data['departure_time'] ?? '')) : '';
          $depTime = $depTime !== '' ? substr($depTime, 0, 5) : '';
        ?>
        <label class="ci-l">Would you like us to arrange a transfer when you arrive?</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tin" name="needs_transfer" value="1" <?= $ntYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tin" name="needs_transfer" value="0" <?= $ntNo ? 'checked' : '' ?>> No, I&rsquo;ll make my own way</label>

        <?php // Both blocks stay in the DOM and are only hidden — a hidden input
              // still submits, so nothing the guest already entered is silently
              // dropped when they toggle. ?>
        <div class="ci-tin-fields" data-tmode="flight"<?= ($ntYes && $isFlight2) ? '' : ' hidden' ?>>
          <label class="ci-l">Airport of arrival</label>
          <select class="ci-in ci-f-airport" name="arrival_airport">
            <option value="">— select —</option>
            <?php foreach ($airports2 as $av => $al): ?>
            <option value="<?= e($av) ?>" <?= $savedAir2 === $av ? 'selected' : '' ?>><?= e($al) ?></option>
            <?php endforeach; ?>
            <option value="__other" <?= $airOther2 ? 'selected' : '' ?>>Other — I&rsquo;ll type it</option>
          </select>
          <div class="ci-airport-other"<?= $airOther2 ? '' : ' hidden' ?>>
            <label class="ci-l">Which airport?</label>
            <input class="ci-in" name="arrival_airport_other" value="<?= $airOther2 ? e($savedAir2) : '' ?>" placeholder="e.g. Nairobi JKIA">
          </div>
          <label class="ci-l">Flight number</label>
          <input class="ci-in" name="flight_number" value="<?= $val('flight_number') ?>" placeholder="e.g. KQ610">
          <label class="ci-l">Flight arrival <span class="ci-opt">(landing time)</span></label>
          <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
        </div>

        <div class="ci-tin-fields" data-tmode="other"<?= ($ntYes && !$isFlight2) ? '' : ' hidden' ?>>
          <label class="ci-l">Where should we collect you?</label>
          <textarea class="ci-in" name="transfer_details" rows="3" placeholder="e.g. Likoni ferry, or the Serena in Mombasa"><?= $val('transfer_details') ?></textarea>
        </div>

        <?php if ($dtOn): ?>
        <label class="ci-l" style="margin-top:18px">Do you need a transfer when you check out?</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tout" name="needs_departure_transfer" value="1" <?= $dtYes ? 'checked' : '' ?>> Yes, please arrange it</label>
        <label class="ci-radio"><input type="radio" class="ci-f-tout" name="needs_departure_transfer" value="0" <?= $dtNo ? 'checked' : '' ?>> No, thank you</label>
        <div class="ci-tout-fields"<?= $dtYes ? '' : ' hidden' ?>>
          <label class="ci-l">Where are we taking you?</label>
          <input class="ci-in" name="departure_destination" value="<?= $val('departure_destination') ?>" placeholder="e.g. Moi International Airport">
          <label class="ci-l">What time should we collect you?</label>
          <input class="ci-in" type="time" name="departure_time" value="<?= e($depTime) ?>">
        </div>
        <?php endif; ?>
```

- [ ] **Step 2: Verify**

Run: `php -l includes/app/checkin.php` → no syntax errors. Then `php tests/checkin_logic.php` → `ALL PASS`.

Render step 2 across the matrix and report which blocks are visible:

```bash
php -r '
require_once "includes/db.php"; require_once "includes/booking.php"; require_once "includes/checkin.php";
$u=(int)db_query("SELECT id FROM units WHERE is_active LIMIT 1")->fetchColumn();
$h=create_hold_with_block($u,null,date("Y-m-d",strtotime("+321 days")),date("Y-m-d",strtotime("+323 days")),"ZZS2 Fixture","zzs2@example.com","confirmed");
db_query("INSERT INTO booking_checkin (hold_id) VALUES (:h)",[":h"=>$h]);
foreach ([["flight","t","f"],["flight","f","f"],["road","t","f"],["road","f","t"],[null,null,null]] as [$m,$in,$out]) {
  db_query("UPDATE booking_checkin SET arrival_mode=:m, needs_transfer=:i, needs_departure_transfer=:o WHERE hold_id=:h",
    [":m"=>$m,":i"=>$in,":o"=>$out,":h"=>$h]);
  $d=fetch_checkin($h);
  printf("  mode=%-7s in=%-4s out=%-4s -> complete=%s\n", $m ?? "null", $in ?? "-", $out ?? "-",
    checkin_step_complete("transfer", $d, null) ? "yes" : "no");
}
db_query("DELETE FROM booking_checkin WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]);
echo "  cleaned up\n";'
```

Expected: `flight/t/f` → `no` (flight fields missing); `flight/f/f` → `yes`; `road/t/f` → `no` (no pickup point); `road/f/t` → `no` (no destination/time); `null/-/-` → `no`.

**Report:** the rendered HTML for a `road` + `needs_transfer=t` row — confirm the `data-tmode="other"` block is visible, the `data-tmode="flight"` block is present but `hidden`, and that `arrival_at` still appears in the DOM (so it round-trips) even though it is hidden.

- [ ] **Step 3: Commit**

```bash
git add includes/app/checkin.php
git commit -m "feat(checkin): the transfer step carries the flight details and the return leg"
```

---

### Task 6: The wizard's JS drives the new toggles

**Files:** `js/checkin-wizard.js`

- [ ] **Step 1: Simplify the warning to one field**

In `js/checkin-wizard.js`, inside `syncArrivalWarning()`, replace:

```js
    var modeEl = sec.querySelector('.ci-f-mode:checked');
    var mode = modeEl ? modeEl.value : 'flight';
    var t = '';
    if (mode === 'flight') {
      var pa = sec.querySelector('.ci-f-patime');
      t = pa ? pa.value : '';
    } else {
      var at = sec.querySelector('[name="arrival_at"]');
      t = at && at.value ? at.value.split('T')[1] || '' : '';
    }
    var flag = arrFlag(t, from, to);
```

with:

```js
    // One field for every mode. The server prefills it via checkin_desired_time(),
    // including the legacy road/other fallback, so this reads exactly the string
    // the server flagged on — no mode branch, and no way for the two to disagree.
    var pa = sec.querySelector('.ci-f-patime');
    var t = pa ? pa.value : '';
    var flag = arrFlag(t, from, to);
```

- [ ] **Step 2: Drop the dead mode-label toggle**

Still in `js/checkin-wizard.js`, in the delegated `change` listener, replace:

```js
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
      // The shared arrival field means different things per mode, so its label
      // swaps too — .ci-mode-fields above does not cover a bare <label>.
      step.querySelectorAll('[data-mode-label]').forEach(function (l) {
        l.hidden = (l.getAttribute('data-mode-label') === 'flight') !== (t.value === 'flight');
      });
      syncArrivalWarning(step);
      return;
    }
    if (t.classList.contains('ci-f-patime') || t.name === 'arrival_at') {
      syncArrivalWarning(t.closest('.ci-step'));
      return;
    }
```

with:

```js
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
      // The transfer step asks different things of a flier, and the mode lives
      // on THIS step — so changing it has to re-run that step's toggle too.
      syncTransferFields(form);
      return;
    }
    if (t.classList.contains('ci-f-patime')) {
      syncArrivalWarning(t.closest('.ci-step'));
      return;
    }
    if (t.classList.contains('ci-f-tin') || t.classList.contains('ci-f-tout')) {
      syncTransferFields(form);
      return;
    }
```

The `[data-mode-label]` toggle goes because Task 4 deleted both labels. `arrival_at` no longer triggers the warning because it is a landing time now and lives on another step.

- [ ] **Step 3: Add the transfer toggle**

Immediately after `syncArrivalWarning()`'s closing brace, insert:

```js
  // The arrival-transfer block depends on TWO answers that live on different
  // steps: "do you want a transfer" (transfer step) and "how will you arrive"
  // (arrival step). The mode radios stay the single source of truth — this
  // re-reads them rather than copying the value into a hidden field, so the two
  // steps cannot drift apart.
  function syncTransferFields(root) {
    var sec = root.querySelector('.ci-step[data-key="transfer"]');
    if (!sec) return;
    var inEl = sec.querySelector('.ci-f-tin:checked');
    var wantsIn = !!inEl && inEl.value === '1';
    var modeEl = root.querySelector('.ci-f-mode:checked');
    // No mode radios at all = pre-migration, where the legacy form was
    // flight-only. Matches $isFlight2 in includes/app/checkin.php.
    var isFlight = modeEl ? modeEl.value === 'flight' : true;

    sec.querySelectorAll('.ci-tin-fields').forEach(function (g) {
      var wantFlight = g.getAttribute('data-tmode') === 'flight';
      g.hidden = !wantsIn || (wantFlight !== isFlight);
    });

    var outEl = sec.querySelector('.ci-f-tout:checked');
    var outBox = sec.querySelector('.ci-tout-fields');
    if (outBox) outBox.hidden = !outEl || outEl.value !== '1';
  }
```

- [ ] **Step 4: Run it on load**

Find the initial-load call added previously:

```js
  var arrStep = form.querySelector('.ci-step[data-key="arrival"]');
  if (arrStep) syncArrivalWarning(arrStep);
```

Replace with:

```js
  var arrStep = form.querySelector('.ci-step[data-key="arrival"]');
  if (arrStep) syncArrivalWarning(arrStep);
  syncTransferFields(form);
```

- [ ] **Step 5: Verify**

Run: `node --check js/checkin-wizard.js` → no output.

Then prove the JS toggle agrees with the PHP render across the matrix. Extract `syncTransferFields` from the shipped file (do **not** retype it) and drive it against a minimal fake DOM; compare each block's `hidden` against what `includes/app/checkin.php` renders for the same state. Cover: `flight`+yes, `flight`+no, `road`+yes, `road`+no, no-mode+yes, and departure yes/no.

Report the comparison table. A disagreement means the server render and the first JS tick would contradict each other — the exact bug class this project has hit before.

Also confirm the PHP/JS flag parity still holds after Step 1's simplification, using the existing extraction approach:

```bash
php -r '
require "includes/db.php"; require "includes/checkin.php";
foreach (["10:00","13:59","14:00","16:00","20:00","20:01","22:30","","not a time","10:00:00","9:05","24:00"] as $c)
  printf("%s|%s\n", $c, checkin_arrival_flag($c, "14:00", "20:00"));' > /tmp/php_flags.txt
```

then extract `arrMins`/`arrFlag` from `js/checkin-wizard.js` by source offset, run the same cases, and `diff`. Expected: no differences. Remove the temp files afterwards.

- [ ] **Step 6: Commit**

```bash
git add js/checkin-wizard.js
git commit -m "feat(checkin): drive the transfer toggles and simplify the warning"
```

---

### Task 7: Persist the return leg

**Files:** `api/checkin-save.php`

- [ ] **Step 1: Coerce the new boolean**

In `api/checkin-save.php`, find:

```php
    $needsTransfer = array_key_exists('needs_transfer', $_POST) && $_POST['needs_transfer'] !== ''
        ? ($_POST['needs_transfer'] === '1' ? 'TRUE' : 'FALSE') : null;
```

Insert immediately after it:

```php
    // Same 'TRUE'/'FALSE'-string rule as needs_transfer above — a bound PHP bool
    // would render as '' and Postgres would reject it for a boolean column.
    $needsDepTransfer = array_key_exists('needs_departure_transfer', $_POST) && $_POST['needs_departure_transfer'] !== ''
        ? ($_POST['needs_departure_transfer'] === '1' ? 'TRUE' : 'FALSE') : null;
```

- [ ] **Step 2: Simplify the desired-time write**

Find the whole `if (checkin_property_arrival_supported()) { … }` block and replace its body's mode logic. Replace:

```php
        $isFlight = $mode === 'flight' || !checkin_arrival_mode_supported();
```

and:

```php
        $p[':pat'] = $isFlight ? $pa : null;
```

with, respectively — delete the `$isFlight` line entirely, and change the bind to:

```php
        // Asked of every mode now, so there is no mode branch to get wrong.
        $p[':pat'] = $pa;
```

Also update the block's leading comment. Replace:

```php
        // The guest's desired check-in time. Only meaningful in flight mode — in
        // road/other, arrival_at is when they drive up, which is when they want
        // in, so we do not store a duplicate.
        //
        // Pre-migration ($mode is forced to '' above) the legacy form was
        // flight-only, so arrival_at holds a LANDING time and the wizard both
        // SHOWS this field and warns on it (includes/app/checkin.php:185,233 —
        // the same `$amOn ? … : true` fallback). Dropping the value here would
        // ask the guest a question, warn them about the answer, and then throw
        // it away.
```

with:

```php
        // The guest's desired check-in time, asked of every arrival mode. The
        // wizard prefills it from a legacy road/other arrival_at via
        // checkin_desired_time(), so saving here is also what heals those rows.
```

- [ ] **Step 3: Write the three new columns**

Immediately after the closing brace of the `if (checkin_property_arrival_supported()) { … }` block, insert:

```php
    if (checkin_departure_transfer_supported()) {
        // Destination and time are only meaningful once the guest says yes;
        // clearing them on "no" stops the driver seeing a stale pickup.
        $wantsOut = $needsDepTransfer === 'TRUE';
        // A REAL clock time — Postgres rejects '25:00'/'99:99' for a TIME column
        // and the exception would abort this whole write, taking the guest's
        // other answers with it (the wizard posts the entire form every save).
        $dt = $s('departure_time');
        if ($dt !== null && !preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $dt)) $dt = null;
        $cols[] = 'needs_departure_transfer'; $vals[] = ':ndt'; $p[':ndt'] = $needsDepTransfer;
        $cols[] = 'departure_destination';    $vals[] = ':dd';  $p[':dd']  = $wantsOut ? $s('departure_destination') : null;
        $cols[] = 'departure_time';           $vals[] = ':dt';  $p[':dt']  = $wantsOut ? $dt : null;
    }
```

- [ ] **Step 4: Verify**

Run: `php -l api/checkin-save.php && php tests/checkin_logic.php` → clean, `ALL PASS`.

Then drive the real endpoint end-to-end. Post a valid `ref` and CSRF token, `require` the actual endpoint, and read each row back from Postgres. Cover at minimum:

| Case | Post | Expect stored |
|---|---|---|
| A | departure yes, `MBA`, `12:00` | `TRUE`, `MBA`, `12:00:00` |
| B | departure no, destination and time still posted | `FALSE`, `NULL`, `NULL` |
| C | departure unanswered | `NULL`, `NULL`, `NULL` |
| D | departure yes, time `99:99` | `TRUE`, dest kept, time `NULL`, **no exception** |
| E | second save changing the time to `14:30` | `14:30:00` — proves the UPDATE path |
| F | road mode, desired check-in `10:00` | `property_arrival_time = 10:00:00` (no longer nulled for road) |

Case F is the regression that matters most: before this task, a road guest's desired time was discarded.

Confirm no PHP bool is bound anywhere — `php tests/checkin_logic.php` includes a `no bind is ever a PHP bool` assertion; report that it passes. Clean up every fixture row.

- [ ] **Step 5: Commit**

```bash
git add api/checkin-save.php
git commit -m "feat(checkin): persist the departure transfer and the universal desired time"
```

---

### Task 8: Staff see the return leg

**Files:** `admin/_ws_checkin.php`

- [ ] **Step 1: Add the rows**

In `admin/_ws_checkin.php`, find:

```php
    <tr><td class="text-muted">Transfer</td><td><?php $nt=$__ci['needs_transfer']??null; echo ($nt===null)?'—':(($nt===true||$nt==='t')?'Yes — '.e((string)($__ci['transfer_details']??'')):'No'); ?></td></tr>
```

Insert immediately after it:

```php
    <?php if (checkin_departure_transfer_supported()): ?>
    <tr><td class="text-muted">Check-out transfer</td><td><?php
      $__dt = $__ci['needs_departure_transfer'] ?? null;
      if ($__dt === null) { echo '<span class="text-muted">—</span>'; }
      elseif ($__dt === true || $__dt === 't') {
        $__dd = trim((string)($__ci['departure_destination'] ?? ''));
        $__dp = trim((string)($__ci['departure_time'] ?? ''));
        echo 'Yes — ' . ($__dd !== '' ? e($__dd) : '<span class="text-muted">no destination</span>')
           . ($__dp !== '' ? ' at <strong>' . e(substr($__dp, 0, 5)) . '</strong>' : '');
      } else { echo 'No'; }
    ?></td></tr>
    <?php endif; ?>
```

- [ ] **Step 2: Verify**

Run: `php -l admin/_ws_checkin.php && php tests/checkin_logic.php` → clean, `ALL PASS`.

Render the real partial with a faked owner session (`$_SESSION['admin_id']`, `$_SESSION['admin_role']` — never a password) across: departure yes with both fields, yes with a destination but no time, yes with neither, no, and unanswered. Report the rendered row for each. Clean up fixtures.

- [ ] **Step 3: Commit**

```bash
git add admin/_ws_checkin.php
git commit -m "feat(admin): show the check-out transfer on the check-in tab"
```

---

### Task 9: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Every suite**

```bash
for f in tests/*_logic.php; do printf "%-28s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`'s one known pre-existing failure.

- [ ] **Step 2: Lint**

```bash
for f in includes/checkin.php includes/app/checkin.php api/checkin-save.php admin/_ws_checkin.php; do php -l "$f"; done
node --check js/checkin-wizard.js
```

- [ ] **Step 3: Pre-migration safety**

`checkin_departure_transfer_supported()` caches in a `static`, so you **cannot** drop the columns and re-check in the same PHP process — drop in one process and render in a **new** one, or the guard returns a stale `true` and the test proves nothing. State how you handled it.

Before dropping, report how many rows have a non-NULL `departure_time`; if any do, capture and restore them.

```bash
php -r 'require "includes/db.php"; db_query("ALTER TABLE booking_checkin DROP COLUMN IF EXISTS needs_departure_transfer, DROP COLUMN IF EXISTS departure_destination, DROP COLUMN IF EXISTS departure_time"); echo "dropped\n";'
```

Then, in fresh processes: render the guest transfer step and the admin check-in tab. Confirm no fatal, no warning or notice, that the departure block is **absent from the HTML** (not merely hidden), that the arrival-transfer half still works, and that the transfer step does not become permanently incomplete. Then restore:

```bash
php bin/migrate.php db/migrations/add_departure_transfer.sql
```

Confirm the three columns are back with the right types and the guard returns `bool(true)` in a fresh process.

- [ ] **Step 4: End-to-end coherence**

Follow one guest all the way through and report what each hop holds:

1. Flying, wants a transfer, lands 10:00, desired check-in 12:30, check-out transfer to Moi airport at 12:00.
2. By road, no arrival transfer, desired check-in 09:00, no check-out transfer.
3. A **legacy** road row — `arrival_at` set, `property_arrival_time` NULL — confirm the field prefills from `arrival_at`, the warning renders, and a save heals the row so `property_arrival_time` is then set.

Case 3 is the backward-compatibility guarantee; it is the one most likely to be quietly broken.

- [ ] **Step 5: Working tree and fixtures**

`git status --short` must show ONLY the pre-existing `.claude/launch.json` modification and the two untracked `Archive*.zip`. Report any `holds` matching `ZZ%` and any orphaned `booking_checkin` / `availability_blocks` rows — **do not delete** `hold` #469 ("ZZ Chk Guest"), which is a known pre-existing leak.

- [ ] **Step 6: Report**

Summarise what passed and what did not, plus `git log --oneline`. Do **not** claim success for anything you did not run.

---

## Notes for the implementer

- **The desired check-in time is optional.** A guest can leave it blank, complete the step, and get no warning. That is the accepted design, not a bug to fix.
- **Never warn on `arrival_at`.** It is a flight landing time. `checkin_desired_time()` falls back to it only for road/other, never for flight and never when no mode is set.
- **Never bind a PHP bool.** `needs_departure_transfer` is a boolean column; bind `'TRUE'`/`'FALSE'`/`null`.
- **Hidden is not absent.** Every field stays in the DOM so values round-trip; only the deliberate airport/flight clear on mode switch removes data.
- The PHP and JS rules must agree exactly — Task 6 Step 5 diffs both the flag and the new toggle for that reason.
