# Check-in / Check-out Times Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the check-in and check-out windows in the pre-arrival flow, warn a guest whose expected arrival falls outside them, and tell them early check-in and late check-out can be requested for a fee.

**Architecture:** Two pure helpers (`checkin_times()`, `checkin_arrival_flag()`) hold all the logic and are unit-tested. The windows live in the existing key-value `settings` table, so only one small migration is needed — a `TIME` column for the guest's expected arrival **at the property**, which is a different time from the flight landing already captured.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS, vanilla CSS. Tests are plain PHP scripts with a `check()` helper. **One migration.**

**Spec:** `docs/superpowers/specs/2026-08-12-checkin-times-design.md`

---

## Before you start

Read the spec. The distinction that drives the whole design:

- `booking_checkin.arrival_at` — in **flight** mode this is the **flight landing time**. In road/other mode it already means arrival at the property.
- `property_arrival_time` (new) — when the guest expects to **reach the property**. Only asked in flight mode, because in the other modes `arrival_at` already is that.

The warning is evaluated against arrival at the property, never the landing time. A flight landing at 10:00 in Mombasa puts the guest at the villa around 12:00–13:00 — warning on the landing time would flag someone who is not early.

**Conventions** (`CLAUDE.md`):

- Escape output with `e()`. `db_query()` with bound parameters.
- Migration-added columns sit behind a cached `*_supported()` guard.
- **Never bind a PHP bool** — `PDO::ATTR_EMULATE_PREPARES` renders `false` as `''` and Postgres rejects it for a boolean. Use `'TRUE'`/`'FALSE'`. (This bit us in production; see `api/checkin-save.php:31`.)
- The app runs in `Africa/Nairobi` (`includes/db.php`), and the DB session is aligned to it. Times here are wall-clock strings, so no conversion is needed — but do not introduce any.

**Baseline:** `php tests/checkin_logic.php` ends `ALL PASS`. `php tests/team_logic.php` has one known pre-existing failure (`owner: home = dashboard`) — ignore it.

**Committing:** the tree has unrelated pre-existing changes (`.claude/launch.json`, two untracked `Archive*.zip`). **NEVER `git add -A` or `git add .`.**

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `db/migrations/add_property_arrival_time.sql` | One nullable TIME column | Create |
| `includes/checkin.php` | `checkin_times()`, `checkin_arrival_flag()`, support guard | Modify |
| `tests/checkin_logic.php` | Assertions for both helpers | Modify |
| `admin/checkin-settings.php` | Five new fields | Modify |
| `includes/app/checkin.php` | Relabelled fields, new time input, window + warning | Modify |
| `js/checkin-wizard.js` | Live warning on change | Modify |
| `api/checkin-save.php` | Persist `property_arrival_time` | Modify |
| `includes/app/_stay_essentials.php` | Check-in & check-out row | Modify |
| `admin/_ws_checkin.php` | Show expected property arrival | Modify |

---

### Task 1: The two pure helpers

**Files:** `includes/checkin.php`, `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing tests**

In `tests/checkin_logic.php`, find this line (near the top, under the config-override block):

```php
set_setting('checkin_steps', $prev); // restore
```

Insert this immediately after it:

```php
// ── Check-in / check-out windows (settings + defaults) ──────────────────────
$prevTimes = [];
foreach (['checkin_time_from','checkin_time_to','checkout_time_from','checkout_time_to','checkin_early_late_note'] as $__k) {
    $prevTimes[$__k] = setting($__k, '');
    set_setting($__k, '');
}
$t = checkin_times();
check('times has all five keys',  array_keys($t) === ['ci_from','ci_to','co_from','co_to','note']);
check('default check-in from',    $t['ci_from'] === '14:00');
check('default check-in to',      $t['ci_to']   === '20:00');
check('default check-out from',   $t['co_from'] === '10:00');
check('default check-out to',     $t['co_to']   === '11:00');
check('default note non-empty',   trim($t['note']) !== '');
set_setting('checkin_time_from', '15:30');
set_setting('checkin_early_late_note', 'Ask reception.');
$t2 = checkin_times();
check('override check-in from',   $t2['ci_from'] === '15:30');
check('override note',            $t2['note'] === 'Ask reception.');
check('unset key keeps default',  $t2['ci_to'] === '20:00');
foreach ($prevTimes as $__k => $__v) { set_setting($__k, $__v); }   // restore

// ── Arrival flag against the window (pure) ──────────────────────────────────
check('flag before window = early', checkin_arrival_flag('10:00', '14:00', '20:00') === 'early');
check('flag after window = late',   checkin_arrival_flag('22:30', '14:00', '20:00') === 'late');
check('flag inside window = none',  checkin_arrival_flag('16:00', '14:00', '20:00') === '');
check('flag on opening boundary',   checkin_arrival_flag('14:00', '14:00', '20:00') === '');
check('flag on closing boundary',   checkin_arrival_flag('20:00', '14:00', '20:00') === '');
check('flag one minute early',      checkin_arrival_flag('13:59', '14:00', '20:00') === 'early');
check('flag one minute late',       checkin_arrival_flag('20:01', '14:00', '20:00') === 'late');
check('flag null = none',           checkin_arrival_flag(null, '14:00', '20:00') === '');
check('flag empty = none',          checkin_arrival_flag('', '14:00', '20:00') === '');
check('flag garbage = none',        checkin_arrival_flag('not a time', '14:00', '20:00') === '');
check('flag accepts seconds',       checkin_arrival_flag('10:00:00', '14:00', '20:00') === 'early');
```

The `garbage = none` case matters: a malformed value must not produce a false warning.

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_times()`. Confirm you see it.

- [ ] **Step 3: Add the helpers**

In `includes/checkin.php`, insert this immediately after `checkin_waiver_text()`:

```php
/**
 * The property's check-in and check-out windows, plus the early/late policy note.
 * Stored in the key-value settings table (no migration) and edited in
 * admin/checkin-settings.php. Defaults apply to any unset key so the guest never
 * sees a blank window.
 */
function checkin_times(): array {
    $get = function (string $key, string $default): string {
        $v = trim((string) setting($key, ''));
        return $v !== '' ? $v : $default;
    };
    return [
        'ci_from' => $get('checkin_time_from',  '14:00'),
        'ci_to'   => $get('checkin_time_to',    '20:00'),
        'co_from' => $get('checkout_time_from', '10:00'),
        'co_to'   => $get('checkout_time_to',   '11:00'),
        'note'    => $get('checkin_early_late_note',
            'Early check-in and late check-out are available for a fee, subject to availability — just ask us.'),
    ];
}

/**
 * Is an expected arrival outside the check-in window? Returns 'early', 'late' or
 * '' (inside, unknown, or unparseable). Boundaries count as inside.
 *
 * Compared as minutes-from-midnight rather than strings, so '9:05' and '09:05'
 * behave the same. A malformed value returns '' — a false warning is worse than
 * none. Pure.
 */
function checkin_arrival_flag(?string $hhmm, string $from, string $to): string {
    $mins = function (?string $t): ?int {
        if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) return null;
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h > 23 || $i > 59) return null;
        return $h * 60 + $i;
    };
    $at = $mins($hhmm); $a = $mins($from); $b = $mins($to);
    if ($at === null || $a === null || $b === null) return '';
    if ($at < $a) return 'early';
    if ($at > $b) return 'late';
    return '';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: all twenty new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): check-in window settings and an arrival-time flag"
```

---

### Task 2: Migration and support guard

**Files:** `db/migrations/add_property_arrival_time.sql`, `includes/checkin.php`, `tests/checkin_logic.php`

- [ ] **Step 1: Create the migration**

Create `db/migrations/add_property_arrival_time.sql`:

```sql
-- Tribal Sand: when the guest expects to reach the property. Run via
-- /admin/migrate.php. Idempotent.
--
-- Distinct from booking_checkin.arrival_at, which in flight mode is the FLIGHT
-- LANDING time — a flight landing at 10:00 in Mombasa puts the guest at the villa
-- around 12:00-13:00. The check-in window is checked against this column, not
-- against the landing time, so guests who are not actually early are not warned.
--
-- A TIME, not a timestamp: the date is already known (the check-in day), and a
-- bare time is what the warning compares and what reception reads off.
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS property_arrival_time TIME;
```

- [ ] **Step 2: Write the failing test**

In `tests/checkin_logic.php`, find:

```php
check('checkin_arrival_mode_supported is bool', is_bool(checkin_arrival_mode_supported()));
```

Insert immediately after it:

```php
check('checkin_property_arrival_supported is bool', is_bool(checkin_property_arrival_supported()));
```

- [ ] **Step 3: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_property_arrival_supported()`.

- [ ] **Step 4: Add the guard**

In `includes/checkin.php`, insert immediately after `checkin_arrival_mode_supported()`:

```php
/** True once add_property_arrival_time.sql is applied. Cached per-request. */
function checkin_property_arrival_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT property_arrival_time FROM booking_checkin LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```

- [ ] **Step 5: Apply and verify**

```bash
php bin/migrate.php db/migrations/add_property_arrival_time.sql
php -r 'require "includes/db.php"; require "includes/checkin.php"; var_dump(checkin_property_arrival_supported());'
```

Expected: `bool(true)`. Then `php tests/checkin_logic.php` → `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/add_property_arrival_time.sql includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): add property_arrival_time and its support guard"
```

---

### Task 3: The times are editable in admin

**Files:** `admin/checkin-settings.php`

- [ ] **Step 1: Save the five settings**

Replace:

```php
    set_setting('checkin_waiver_text', trim((string)($_POST['waiver_text'] ?? '')));
```

with:

```php
    set_setting('checkin_waiver_text', trim((string)($_POST['waiver_text'] ?? '')));
    // Windows are free-form HH:MM strings; checkin_arrival_flag() ignores anything
    // it cannot parse, and checkin_times() falls back to the default for a blank.
    foreach (['checkin_time_from','checkin_time_to','checkout_time_from','checkout_time_to'] as $__tk) {
        set_setting($__tk, trim((string)($_POST[$__tk] ?? '')));
    }
    set_setting('checkin_early_late_note', trim((string)($_POST['early_late_note'] ?? '')));
```

- [ ] **Step 2: Load them for the form**

Replace:

```php
$waiver   = setting('checkin_waiver_text', '');
```

with:

```php
$waiver   = setting('checkin_waiver_text', '');
$times    = checkin_times();   // defaults applied, so the inputs are never blank
```

- [ ] **Step 3: Add the card**

Insert this immediately **before** the `Waiver / indemnity terms` card:

```php
  <div class="card" style="margin-bottom:16px">
    <div class="card__head"><span class="card__title">Check-in &amp; check-out times</span></div>
    <div class="card__body">
      <p class="text-muted" style="margin:0 0 14px;font-size:13px">Shown to the guest during pre-check-in. A guest who tells us they will arrive outside the check-in window gets a warning explaining the room may not be ready — they are not blocked.</p>
      <div class="form-row" style="max-width:520px">
        <div class="field">
          <label for="ciFrom">Check-in from</label>
          <input id="ciFrom" type="time" name="checkin_time_from" class="inp" value="<?= e($times['ci_from']) ?>" style="width:100%">
        </div>
        <div class="field">
          <label for="ciTo">Check-in until</label>
          <input id="ciTo" type="time" name="checkin_time_to" class="inp" value="<?= e($times['ci_to']) ?>" style="width:100%">
        </div>
      </div>
      <div class="form-row" style="max-width:520px">
        <div class="field">
          <label for="coFrom">Check-out from</label>
          <input id="coFrom" type="time" name="checkout_time_from" class="inp" value="<?= e($times['co_from']) ?>" style="width:100%">
        </div>
        <div class="field">
          <label for="coTo">Check-out by</label>
          <input id="coTo" type="time" name="checkout_time_to" class="inp" value="<?= e($times['co_to']) ?>" style="width:100%">
        </div>
      </div>
      <div class="field" style="max-width:520px">
        <label for="ciNote">Early check-in / late check-out note</label>
        <textarea id="ciNote" name="early_late_note" rows="2" class="inp" style="width:100%;font-family:inherit"><?= e($times['note']) ?></textarea>
      </div>
    </div>
  </div>
```

- [ ] **Step 4: Verify**

Run: `php -l admin/checkin-settings.php` → no syntax errors.

Then round-trip a change through the real POST path:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="POST";
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/checkin.php"; require_once "includes/icons.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
$_SESSION["csrf_token"]=bin2hex(random_bytes(16));
$before = checkin_times();
$_POST=["csrf_token"=>$_SESSION["csrf_token"],"waiver_text"=>setting("checkin_waiver_text",""),"welcome"=>setting("checkin_welcome",""),
        "checkin_time_from"=>"15:00","checkin_time_to"=>"21:00","checkout_time_from"=>"09:00","checkout_time_to"=>"10:30",
        "early_late_note"=>"Ask at reception."];
register_shutdown_function(function() use ($before) {
  $t = checkin_times();
  printf("  saved: in %s-%s  out %s-%s  note=%s\n", $t["ci_from"],$t["ci_to"],$t["co_from"],$t["co_to"],$t["note"]);
  foreach (["checkin_time_from"=>"","checkin_time_to"=>"","checkout_time_from"=>"","checkout_time_to"=>"","checkin_early_late_note"=>""] as $k=>$v) set_setting($k,$v);
  $d = checkin_times();
  printf("  cleared -> back to defaults: in %s-%s  out %s-%s\n", $d["ci_from"],$d["ci_to"],$d["co_from"],$d["co_to"]);
});
ob_start(); include "admin/checkin-settings.php";'
```

Expected: `saved: in 15:00-21:00  out 09:00-10:30  note=Ask at reception.` then `cleared -> back to defaults: in 14:00-20:00  out 10:00-11:00`. Report the actual output.

- [ ] **Step 5: Commit**

```bash
git add admin/checkin-settings.php
git commit -m "feat(admin): edit the check-in and check-out windows"
```

---

### Task 4: The arrival step asks the right question and warns

**Files:** `includes/app/checkin.php`

- [ ] **Step 1: Load the times and the current flag**

In `includes/app/checkin.php`, inside the `<?php if ($key === 'arrival'): ?>` branch, find:

```php
          $airOther = $savedAir !== '' && !array_key_exists($savedAir, $airports);
        ?>
```

Replace with:

```php
          $airOther = $savedAir !== '' && !array_key_exists($savedAir, $airports);
          $T        = checkin_times();
          $paOn     = checkin_property_arrival_supported();
          // Flight mode captures the landing time in arrival_at, so the time the
          // guest reaches us is a separate field. In road/other, arrival_at IS
          // the time they reach us, so the flag reads its time part.
          $paSaved  = $paOn ? trim((string)($data['property_arrival_time'] ?? '')) : '';
          $paSaved  = $paSaved !== '' ? substr($paSaved, 0, 5) : '';
          $atTime   = !empty($data['arrival_at']) ? date('H:i', strtotime((string)$data['arrival_at'])) : '';
          $flagTime = ($mode === 'flight') ? $paSaved : $atTime;
          $flag     = checkin_arrival_flag($flagTime, $T['ci_from'], $T['ci_to']);
        ?>
```

- [ ] **Step 2: Relabel the shared arrival field per mode**

Replace:

```php
        <label class="ci-l">Arrival date &amp; time</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">
```

with:

```php
        <label class="ci-l" data-mode-label="flight"<?= $mode === 'flight' ? '' : ' hidden' ?>>Flight arrival <span class="ci-opt">(landing time)</span></label>
        <label class="ci-l" data-mode-label="other"<?= $mode === 'flight' ? ' hidden' : '' ?>>When do you expect to reach us?</label>
        <input class="ci-in" type="datetime-local" name="arrival_at" value="<?= e($arrDate) ?>">

        <?php if ($paOn): ?>
        <div class="ci-mode-fields" data-mode="flight"<?= ($amOn && $mode !== 'flight') ? ' hidden' : '' ?>>
          <label class="ci-l">What time do you expect to reach us?</label>
          <input class="ci-in ci-f-patime" type="time" name="property_arrival_time" value="<?= e($paSaved) ?>">
        </div>
        <?php endif; ?>

        <p class="ci-times" data-ci-from="<?= e($T['ci_from']) ?>" data-ci-to="<?= e($T['ci_to']) ?>">
          Check-in is from <strong><?= e($T['ci_from']) ?></strong> to <strong><?= e($T['ci_to']) ?></strong>.
          Check-out is between <strong><?= e($T['co_from']) ?></strong> and <strong><?= e($T['co_to']) ?></strong>.
        </p>
        <div class="ci-arrwarn"<?= $flag === '' ? ' hidden' : '' ?>>
          <p class="ci-arrwarn__t"></p>
          <p class="ci-arrwarn__n"><?= e($T['note']) ?></p>
          <a class="ci-arrwarn__a" href="/booking.php?ref=<?= e($ref) ?>&amp;view=messages">Message the team &rarr;</a>
        </div>
```

The warning body is filled by JS from one place (next task) so the server render and the live update cannot word it differently. The container's `hidden` state is server-rendered so the correct state shows before JS runs.

- [ ] **Step 3: Add the styles**

In `css/portal-app.css`, find:

```css
.ci-err{background:#fbe6e6;border:1px solid #f0c2c2;color:#a12;border-radius:9px;padding:11px 14px;font-size:13.5px;line-height:1.55;margin:16px 0 0}
```

Insert immediately after it:

```css
/* Check-in window notice + the early/late arrival warning */
.ci-times{font-size:13px;color:var(--pa-muted);line-height:1.6;margin:14px 0 0}
.ci-times strong{color:var(--pa-ink);font-weight:600}
.ci-arrwarn{background:#fff7e6;border:1px solid #f0dcb4;border-radius:9px;padding:12px 14px;margin:12px 0 0}
.ci-arrwarn__t{margin:0;font-size:13.5px;line-height:1.55;color:#8a5a00;font-weight:600}
.ci-arrwarn__n{margin:6px 0 0;font-size:13px;line-height:1.55;color:#8a5a00}
.ci-arrwarn__a{display:inline-block;margin-top:8px;font-size:13px;font-weight:600;color:var(--pa-teal)}
```

- [ ] **Step 4: Verify**

Run: `php -l includes/app/checkin.php` → no syntax errors, and `php tests/checkin_logic.php` → `ALL PASS`.

**Do NOT attempt browser verification** — I will do the walkthrough myself.

**Reason about and report:**
- **i.** `$mode` defaults to `'flight'` when the migration for arrival modes is applied but the guest has not chosen yet. With `$paSaved` empty, what does `$flag` evaluate to, and does the warning render on first load? It should not.
- **ii.** Pre-migration (`checkin_property_arrival_supported()` false) in flight mode, `$flagTime` is `''`. Confirm nothing warns and the new input is absent entirely.
- **iii.** The two `data-mode-label` labels are toggled by mode. Confirm the existing radio handler in `js/checkin-wizard.js` only toggles `.ci-mode-fields`, so these labels need their own handling in the next task — say so rather than fixing it here.

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin.php css/portal-app.css
git commit -m "feat(checkin): show the check-in window and ask when the guest reaches us"
```

---

### Task 5: The warning updates live

**Files:** `js/checkin-wizard.js`

- [ ] **Step 1: Add the flag logic and renderer**

In `js/checkin-wizard.js`, insert this immediately after the `updateAddBtn()` function:

```js
  // Mirrors checkin_arrival_flag() in includes/checkin.php. Kept in step with it
  // by the same boundary rules: inside the window and anything unparseable are
  // both "no warning" — a false alarm is worse than none.
  function arrMins(t) {
    var m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec((t || '').trim());
    if (!m) return null;
    var h = parseInt(m[1], 10), i = parseInt(m[2], 10);
    if (h > 23 || i > 59) return null;
    return h * 60 + i;
  }
  function arrFlag(at, from, to) {
    var a = arrMins(at), f = arrMins(from), t = arrMins(to);
    if (a === null || f === null || t === null) return '';
    if (a < f) return 'early';
    if (a > t) return 'late';
    return '';
  }
  // Which time counts as "reaching us": the dedicated field in flight mode,
  // otherwise the time part of the shared arrival datetime.
  function syncArrivalWarning(sec) {
    var times = sec.querySelector('.ci-times'); if (!times) return;
    var box = sec.querySelector('.ci-arrwarn'); if (!box) return;
    var from = times.getAttribute('data-ci-from'), to = times.getAttribute('data-ci-to');
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
    box.hidden = flag === '';
    if (flag === '') return;
    box.querySelector('.ci-arrwarn__t').textContent = flag === 'early'
      ? 'You’ve told us you’ll arrive at ' + t + ', before check-in opens at ' + from
        + '. Your room may still be occupied or being prepared, so it might not be ready when you get here.'
      : 'You’ll arrive at ' + t + ', after check-in closes at ' + to
        + '. Let us know so someone is there to meet you.';
  }
```

- [ ] **Step 2: Run it on load and on every relevant change**

In the delegated `change` listener that handles `.ci-f-mode` and `.ci-f-airport`, replace:

```js
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
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

Then, immediately before the final `if (!intro && !editBtn) openSteps(0);` at the bottom of the file, add:

```js
  // Server renders the correct initial state; this keeps it right if the browser
  // restored a value on back-navigation.
  var arrStep = form.querySelector('.ci-step[data-key="arrival"]');
  if (arrStep) syncArrivalWarning(arrStep);
```

- [ ] **Step 3: Verify**

Run: `node --check js/checkin-wizard.js` → no output.

Then prove the JS rule matches the PHP one across the same inputs:

```bash
php -r '
require "includes/db.php"; require "includes/checkin.php";
$cases = ["10:00","13:59","14:00","16:00","20:00","20:01","22:30","","not a time","10:00:00"];
foreach ($cases as $c) printf("%s|%s\n", $c, checkin_arrival_flag($c, "14:00", "20:00"));' > /tmp/php_flags.txt
node -e '
function arrMins(t){var m=/^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec((t||"").trim());if(!m)return null;var h=parseInt(m[1],10),i=parseInt(m[2],10);if(h>23||i>59)return null;return h*60+i;}
function arrFlag(at,from,to){var a=arrMins(at),f=arrMins(from),t=arrMins(to);if(a===null||f===null||t===null)return "";if(a<f)return "early";if(a>t)return "late";return "";}
["10:00","13:59","14:00","16:00","20:00","20:01","22:30","","not a time","10:00:00"].forEach(function(c){console.log(c+"|"+arrFlag(c,"14:00","20:00"));});' > /tmp/js_flags.txt
diff /tmp/php_flags.txt /tmp/js_flags.txt && echo "  PHP and JS agree on every case" || echo "  *** THEY DISAGREE ***"
rm -f /tmp/php_flags.txt /tmp/js_flags.txt
```

Expected: `PHP and JS agree on every case`. Report the actual result — a disagreement here means the server render and the live update would contradict each other, which is the exact class of bug this project has hit before.

- [ ] **Step 4: Commit**

```bash
git add js/checkin-wizard.js
git commit -m "feat(checkin): live early/late arrival warning"
```

---

### Task 6: Persist the new time, and show it to staff

**Files:** `api/checkin-save.php`, `admin/_ws_checkin.php`, `includes/app/_stay_essentials.php`

- [ ] **Step 1: Save `property_arrival_time`**

In `api/checkin-save.php`, find the block that adds the arrival-mode columns:

```php
    if (checkin_arrival_mode_supported()) {
        $cols[] = 'arrival_mode';    $vals[] = ':am'; $p[':am'] = $mode === '' ? null : $mode;
        $cols[] = 'arrival_vehicle'; $vals[] = ':av'; $p[':av'] = $mode === 'road'  ? $s('arrival_vehicle') : null;
        $cols[] = 'arrival_note';    $vals[] = ':an'; $p[':an'] = $mode === 'other' ? $s('arrival_note')    : null;
    }
```

Insert immediately after it:

```php
    if (checkin_property_arrival_supported()) {
        // Only meaningful in flight mode — in road/other, arrival_at already is
        // the time the guest reaches us, so we do not store a duplicate.
        $pa = $s('property_arrival_time');
        if ($pa !== null && !preg_match('/^\d{1,2}:\d{2}$/', $pa)) $pa = null;
        $cols[] = 'property_arrival_time'; $vals[] = ':pat';
        $p[':pat'] = ($mode === 'flight') ? $pa : null;
    }
```

- [ ] **Step 2: Show it on the admin check-in tab**

In `admin/_ws_checkin.php`, find:

```php
    <tr><td class="text-muted">Arrival</td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
```

Replace with:

```php
    <tr><td class="text-muted"><?= ($__mode === 'flight') ? 'Flight lands' : 'Arrival' ?></td><td><?= $__fmt(($__ci['arrival_at'] ?? '') ? date('j M Y H:i', strtotime((string)$__ci['arrival_at'])) : '') ?></td></tr>
    <?php if (checkin_property_arrival_supported() && $__mode === 'flight'): ?>
    <tr><td class="text-muted">Reaching us</td><td><?php
      $__pat = trim((string)($__ci['property_arrival_time'] ?? ''));
      echo $__pat !== '' ? e(substr($__pat, 0, 5)) : '<span class="text-muted">—</span>';
    ?></td></tr>
    <?php endif; ?>
```

- [ ] **Step 3: Put the times in Stay essentials**

In `includes/app/_stay_essentials.php`, replace:

```php
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } }
?>
```

with:

```php
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } }
// The windows come from settings, so this block always has real content — every
// venues.stay_* field is empty today, which is why it read "details will appear
// here soon" for every property.
$__T = checkin_times();
?>
```

Then replace:

```php
    <?php if (!$__any): ?>
      <p style="color:#6b7280;font-size:14px">Stay details will appear here soon.</p>
    <?php else: foreach ($__info as $__k=>$__label): $__v = $__vals[$__k]; if ($__v==='') continue; ?>
```

with:

```php
      <div class="pa-card">
        <div class="pa-card__body">
          <div style="font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px">Check-in &amp; check-out</div>
          <div style="font-size:14px;line-height:1.6">
            Check-in <strong><?= e($__T['ci_from']) ?>&ndash;<?= e($__T['ci_to']) ?></strong><br>
            Check-out <strong><?= e($__T['co_from']) ?>&ndash;<?= e($__T['co_to']) ?></strong>
          </div>
          <div style="font-size:13px;line-height:1.55;color:#6b7280;margin-top:8px"><?= e($__T['note']) ?></div>
        </div>
      </div>
    <?php if ($__any): foreach ($__info as $__k=>$__label): $__v = $__vals[$__k]; if ($__v==='') continue; ?>
```

**The closing line needs no change.** The block previously read `if (!$__any) … else: foreach …` and now reads `if ($__any): foreach …`, so the existing

```php
    <?php endforeach; endif; ?>
```

still pairs correctly. Leave it exactly as it is, and run `php -l includes/app/_stay_essentials.php` immediately — if it errors, the `if`/`foreach` nesting is wrong and you should stop and report rather than patching around it.

- [ ] **Step 4: Verify**

Run: `php -l api/checkin-save.php && php -l admin/_ws_checkin.php && php -l includes/app/_stay_essentials.php` → no syntax errors.

Then round-trip the new column:

```bash
php -r '
require "includes/db.php"; require "includes/booking.php"; require "includes/checkin.php";
$u=(int)db_query("SELECT id FROM units WHERE is_active LIMIT 1")->fetchColumn();
$h=create_hold_with_block($u,null,date("Y-m-d",strtotime("+110 days")),date("Y-m-d",strtotime("+112 days")),"ZZ Times","zzt@example.com","confirmed");
db_query("INSERT INTO booking_checkin (hold_id, arrival_mode, property_arrival_time) VALUES (:h,:m,:t)",[":h"=>$h,":m"=>"flight",":t"=>"10:00"]);
$r=db_query("SELECT arrival_mode, property_arrival_time FROM booking_checkin WHERE hold_id=:h",[":h"=>$h])->fetch();
$T=checkin_times();
printf("  stored: mode=%s time=%s -> flag=%s\n", $r["arrival_mode"], $r["property_arrival_time"], checkin_arrival_flag(substr($r["property_arrival_time"],0,5), $T["ci_from"], $T["ci_to"]));
db_query("DELETE FROM booking_checkin WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]);
db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]);
echo "  cleaned up\n";'
```

Expected: `stored: mode=flight time=10:00:00 -> flag=early`.

- [ ] **Step 5: Commit**

```bash
git add api/checkin-save.php admin/_ws_checkin.php includes/app/_stay_essentials.php
git commit -m "feat(checkin): persist the expected property arrival and surface the windows"
```

---

### Task 7: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Every suite**

```bash
for f in tests/*_logic.php; do printf "%-28s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`'s one known pre-existing failure.

- [ ] **Step 2: Lint**

```bash
for f in includes/checkin.php admin/checkin-settings.php includes/app/checkin.php \
         api/checkin-save.php admin/_ws_checkin.php includes/app/_stay_essentials.php; do php -l "$f"; done
node --check js/checkin-wizard.js
```

- [ ] **Step 3: Pre-migration safety**

Drop the column, reload the guest check-in page, confirm nothing fatals and the new field is simply absent — then restore:

```bash
php -r 'require "includes/db.php"; db_query("ALTER TABLE booking_checkin DROP COLUMN IF EXISTS property_arrival_time"); echo "dropped\n";'
# load /booking.php?ref=<a real ref>&view=checkin and confirm it renders
php bin/migrate.php db/migrations/add_property_arrival_time.sql
```

- [ ] **Step 4: Report**

Summarise what passed and what did not, plus `git log --oneline`. Do **not** claim success for anything you did not run.

---

## Notes for the implementer

- **The warning is never a block.** A guest must always be able to save an early time and continue.
- **Do not warn on the flight landing time.** Only on the time the guest reaches the property.
- **Never bind a PHP bool** — see the CLAUDE.md note and `api/checkin-save.php:31`.
- The PHP and JS flag rules must agree exactly; Task 5 Step 3 diffs them for that reason.
