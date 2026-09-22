# Clock kiosk — greeting + idle screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After a card scan the kiosk greets the person by name and offers the one button that applies, and at rest it shows a breathing Tribal Sand logo with the camera switched off.

**Architecture:** A new pure resolver (`clock_card_state()`) reads the day's attendance row and answers "what should this person be offered?", built on the existing `clock_next_slot()` so the read path and the write path can never disagree. A new read-only endpoint (`api/clock-card.php`) exposes it to the tablet using the same guards as the punch endpoint. The kiosk JS becomes an explicit four-state machine that owns the camera's lifetime, acquiring it on a START press and releasing it with `track.stop()` on every return to idle.

**Tech Stack:** PHP 8.2, no framework. Vanilla JS, no build step. PostgreSQL via PDO. Tests are plain PHP scripts using a `check(label, bool)` harness.

**Spec:** `docs/superpowers/specs/2026-09-22-clock-kiosk-greeting-idle-design.md`

**No migration.** `attendance`, `attendance_punches` and `admin/attendance.php` are untouched.

---

## Background the engineer needs

**The data model.** One `attendance` row per person per day. Four time columns — `in1`, `out1`, `in2`, `out2` — each **minutes past midnight, stored as an integer** (`420` = 07:00). A time after midnight keeps counting: `1470` = 00:30 the next day. A `status` column carries a day code (`P` present, `LV` leave, `OFF`, `SK` sick, `ABS`, `PH`, `REC`, `REC_L`); an empty status or `P` means a normal working day, anything else means a manager marked the day deliberately and the kiosk must not touch it.

**The existing slot rule** lives in `clock_next_slot($row, $kind)` in `includes/attendance-clock.php`. It returns which column a punch would fill, or `null` when the punch makes no sense. Order is strictly `in1 → out1 → in2 → out2`. **Never reimplement this rule** — every task below builds on it.

**Pure means pure.** Most of `includes/attendance-clock.php` takes a row and returns an answer, with no database and no call to `date()`. That is why `tests/attendance_clock_logic.php` can run on a machine with no database. Everything you add in Tasks 1–4 must keep that property: the current time arrives as an `int` parameter, never from `time()`.

**Running the tests:** `php tests/attendance_clock_logic.php`. It prints `PASS`/`FAIL` per assertion and exits non-zero on any failure. The pure assertions run everywhere; a block at the end does database round-trips inside a transaction it rolls back, and **skips itself** when no database is reachable. Your new assertions are pure and go in the pure section, **before** the line `// ── DB round-trip, inside a transaction we roll back ─` (currently line 85).

**Known pre-existing state:** on a database without a `zuri-buyout` room, `tests/booking_import_logic.php` has one unrelated failure. That is not yours. `tests/attendance_clock_logic.php` is fully green before you start — confirm that first.

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `includes/attendance-clock.php` | The kiosk's read/write model, mostly pure | **Modify** — add 4 pure functions + 1 constant |
| `api/clock-card.php` | Read-only card lookup for the tablet | **Create** (~70 lines) |
| `api/clock-register.php` | One-time device registration | **Modify** — also return the venue name |
| `clock.php` | The kiosk page: markup + inline CSS | **Modify** — add the idle panel, breathing CSS, server time |
| `js/clock-kiosk.js` | Kiosk interaction | **Rewrite** — state machine + camera lifetime |
| `tests/attendance_clock_logic.php` | Pure assertions + a rolled-back DB block | **Modify** — add pure cases |
| `CLAUDE.md` | Project conventions | **Modify** — record the two new rules |

---

## Task 1: `clock_first_name()`

The name to greet someone by.

**Files:**
- Modify: `includes/attendance-clock.php`
- Test: `tests/attendance_clock_logic.php`

- [ ] **Step 1: Confirm the suite is green before you touch it**

Run: `php tests/attendance_clock_logic.php`
Expected: ends with `ALL PASS`. If it does not, stop and report — do not build on a red suite.

- [ ] **Step 2: Write the failing test**

In `tests/attendance_clock_logic.php`, immediately **before** the line
`// ── DB round-trip, inside a transaction we roll back ─`, add:

```php
// ── Greeting name (pure) ────────────────────────────────────────────────────
check('first name of two parts',  clock_first_name('Moses Kamau') === 'Moses');
check('first name of three',      clock_first_name('Moses Wanjiru Kamau') === 'Moses');
check('single name unchanged',    clock_first_name('Moses') === 'Moses');
check('leading space trimmed',    clock_first_name('   Moses Kamau ') === 'Moses');
check('double space handled',     clock_first_name('Moses  Kamau') === 'Moses');
check('empty name stays empty',   clock_first_name('') === '');
check('whitespace-only is empty', clock_first_name('   ') === '');
```

- [ ] **Step 3: Run it and watch it fail**

Run: `php tests/attendance_clock_logic.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function clock_first_name()`

- [ ] **Step 4: Implement**

In `includes/attendance-clock.php`, directly after `clock_minutes_from_hms()`:

```php
/**
 * The name to greet someone by: the first whitespace-separated part of a full
 * name. "Moses Kamau" → "Moses". PURE.
 *
 * Returns '' for an empty name rather than inventing a placeholder — the caller
 * decides what a nameless row should say on screen.
 */
function clock_first_name(string $full): string {
    $full = trim($full);
    if ($full === '') return '';
    $parts = preg_split('/\s+/', $full);
    return ($parts && $parts[0] !== '') ? $parts[0] : $full;
}
```

- [ ] **Step 5: Run it and watch it pass**

Run: `php tests/attendance_clock_logic.php`
Expected: the 7 new lines `PASS`, file ends `ALL PASS`

- [ ] **Step 6: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(clock): first name for the kiosk greeting"
```

---

## Task 2: `clock_last_punch()`

The most recently filled slot on a row — what the greeting's sub-line reports.

**Files:**
- Modify: `includes/attendance-clock.php`
- Test: `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

Append to the pure section you started in Task 1:

```php
// ── Most recent slot (pure) ─────────────────────────────────────────────────
// Reuses $empty/$in1/$closed/$in2/$full defined in the slot-resolution block.
check('no row -> no last punch',   clock_last_punch($empty) === null);
check('in1 only -> in at 420',     clock_last_punch($in1)   === ['kind' => 'in',  'min' => 420]);
check('closed -> out at 720',      clock_last_punch($closed)=== ['kind' => 'out', 'min' => 720]);
check('in2 -> in at 780',          clock_last_punch($in2)   === ['kind' => 'in',  'min' => 780]);
check('full -> out at 1020',       clock_last_punch($full)  === ['kind' => 'out', 'min' => 1020]);
// A manager's edit can leave a hole; the newest SET slot still wins.
check('gap: in1+in2 -> in at 780', clock_last_punch(['in1' => 420, 'in2' => 780]) === ['kind' => 'in', 'min' => 780]);
check('empty string is not set',   clock_last_punch(['in1' => 420, 'out1' => '']) === ['kind' => 'in', 'min' => 420]);
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php tests/attendance_clock_logic.php`
Expected: `Call to undefined function clock_last_punch()`

- [ ] **Step 3: Implement**

In `includes/attendance-clock.php`, directly after `clock_first_name()`:

```php
/**
 * The most recently filled slot on a row: ['kind'=>'in'|'out', 'min'=>int], or
 * null when nothing is recorded. PURE.
 *
 * Walks the four slots in chronological order and keeps the last one that is
 * set, so it answers "what happened most recently" without assuming the row is
 * contiguous — a manager's edit can leave a hole in the middle.
 *
 * This is what the greeting's sub-line reports, and it is deliberately NOT
 * "the open clock-in": the kiosk also needs a time in states where nothing is
 * open ("checked out at 13:00"), and the newest-slot rule gives the right
 * answer in every state, including mid-shift, where the newest slot IS the
 * open clock-in.
 */
function clock_last_punch(array $row): ?array {
    $found = null;
    foreach ([['in1', 'in'], ['out1', 'out'], ['in2', 'in'], ['out2', 'out']] as [$col, $kind]) {
        $v = $row[$col] ?? null;
        if ($v === null || $v === '') continue;
        $found = ['kind' => $kind, 'min' => (int)$v];
    }
    return $found;
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `php tests/attendance_clock_logic.php`
Expected: the 7 new lines `PASS`, file ends `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(clock): resolve the most recent slot on a day row"
```

---

## Task 3: `clock_display_time()`

A punch time a person can read.

**Files:**
- Modify: `includes/attendance-clock.php`
- Test: `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

```php
// ── Display time (pure) ─────────────────────────────────────────────────────
check('display 495 -> 08:15',   clock_display_time(495)  === '08:15');
check('display 1320 -> 22:00',  clock_display_time(1320) === '22:00');
check('display 0 -> 00:00',     clock_display_time(0)    === '00:00');
check('display null -> null',   clock_display_time(null) === null);
// Past midnight: 1470 is 00:30 the next day. A person reads "00:30" —
// attendance_min_to_hhmm() would say "00:30+1", which is for the manager's
// editor, not a greeting.
check('display 1470 -> 00:30',  clock_display_time(1470) === '00:30');
check('display 1440 -> 00:00',  clock_display_time(1440) === '00:00');
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php tests/attendance_clock_logic.php`
Expected: `Call to undefined function clock_display_time()`

- [ ] **Step 3: Implement**

In `includes/attendance-clock.php`, directly after `clock_last_punch()`:

```php
/**
 * A punch time for a person to read: "08:15". PURE.
 *
 * Deliberately NOT attendance_min_to_hhmm(), which renders a past-midnight time
 * as "00:30+1". That form is right on the manager's editor, where the +1 tells
 * you which day the minutes belong to, and wrong in a greeting, where it reads
 * as noise. Here the day is already established by the sentence around it
 * ("checked in at 22:10 yesterday").
 */
function clock_display_time(?int $min): ?string {
    if ($min === null) return null;
    $m = ((int)$min % 1440 + 1440) % 1440;   // also folds a negative safely
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `php tests/attendance_clock_logic.php`
Expected: the 6 new lines `PASS`, file ends `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(clock): human-readable punch time for the greeting"
```

---

## Task 4: `clock_card_state()` — the resolver

The heart of the feature. Given today's row, yesterday's row and the current minute, decide what the kiosk offers.

**Files:**
- Modify: `includes/attendance-clock.php`
- Test: `tests/attendance_clock_logic.php`

- [ ] **Step 1: Write the failing test**

```php
// ── Card state: what the kiosk offers (pure) ────────────────────────────────
// Rows reuse the fixtures above. $none is "no row yesterday".
$none = [];

$s = clock_card_state($empty, $none, 480);              // 08:00, nothing yet
check('fresh day offers in',       $s['action'] === 'in' && $s['slot'] === 'in1');
check('fresh day has no last',     $s['last_min'] === null && $s['last_kind'] === null);
check('fresh day not blocked',     $s['blocked'] === null && $s['stale'] === false);

$s = clock_card_state($in1, $none, 600);                // 10:00, in since 07:00
check('mid-shift offers out',      $s['action'] === 'out' && $s['slot'] === 'out1');
check('mid-shift reports the in',  $s['last_min'] === 420 && $s['last_kind'] === 'in');
check('mid-shift scope is today',  $s['scope'] === 'today');

$s = clock_card_state($closed, $none, 780);             // 13:00, back from break
check('after break offers in',     $s['action'] === 'in' && $s['slot'] === 'in2');
check('after break reports out',   $s['last_min'] === 720 && $s['last_kind'] === 'out');

$s = clock_card_state($in2, $none, 900);                // 15:00, second shift open
check('second shift offers out',   $s['action'] === 'out' && $s['slot'] === 'out2');
check('second shift reports in2',  $s['last_min'] === 780 && $s['last_kind'] === 'in');

$s = clock_card_state($full, $none, 1100);              // 18:20, day complete
check('full day offers nothing',   $s['action'] === null && $s['blocked'] === 'done');
check('full day reports last out', $s['last_min'] === 1020 && $s['last_kind'] === 'out');

$s = clock_card_state(['status' => 'LV'], $none, 480);
check('leave day is blocked',      $s['action'] === null && $s['blocked'] === 'status');
check('leave day keeps status',    $s['status'] === 'LV');

$s = clock_card_state(['status' => 'P'], $none, 480);
check('status P is a normal day',  $s['action'] === 'in' && $s['blocked'] === null);

// ── Night shift vs a punch someone forgot ───────────────────────────────────
// Yesterday: clocked in 22:00 (1320), never clocked out.
$yOpen = ['in1' => 1320];

$s = clock_card_state($empty, $yOpen, 360);             // 06:00, 8h later
check('night shift offers out',    $s['action'] === 'out' && $s['slot'] === 'out1');
check('night shift scope is yest', $s['scope'] === 'yesterday');
check('night shift reports yest',  $s['last_min'] === 1320 && $s['last_kind'] === 'in');
check('night shift not stale',     $s['stale'] === false);

// The boundary. elapsed = (now + 1440) - 1320, and the bound is 840 (14h).
check('13h59 later is a shift',    clock_card_state($empty, $yOpen, 719)['action'] === 'out');
$s = clock_card_state($empty, $yOpen, 721);             // 14h01 later
check('14h01 later offers in',     $s['action'] === 'in' && $s['slot'] === 'in1');
check('14h01 later is flagged',    $s['stale'] === true);
check('14h01 scope is today',      $s['scope'] === 'today');

// A closed yesterday is simply irrelevant.
check('closed yesterday ignored',  clock_card_state($empty, $full, 480)['action'] === 'in');

// Past midnight: in1 22:00, out1 23:30, in2 00:30 (1470) — still open.
$crossed = ['in1' => 1320, 'out1' => 1410, 'in2' => 1470];
$s = clock_card_state($crossed, $none, 120);
check('crossed midnight -> out2',  $s['action'] === 'out' && $s['slot'] === 'out2');
check('crossed reports 1470',      $s['last_min'] === 1470);
check('crossed displays 00:30',    clock_display_time($s['last_min']) === '00:30');

// ── The property that keeps read and write from drifting apart ──────────────
// Whenever the resolver names an action, the WRITE path must agree that a slot
// exists for it. If these two ever disagree the kiosk offers a button that the
// punch endpoint then refuses.
foreach ([
    ['today' => $empty,  'yest' => $none,  'now' => 480],
    ['today' => $in1,    'yest' => $none,  'now' => 600],
    ['today' => $closed, 'yest' => $none,  'now' => 780],
    ['today' => $in2,    'yest' => $none,  'now' => 900],
    ['today' => $empty,  'yest' => $yOpen, 'now' => 360],
    ['today' => $empty,  'yest' => $yOpen, 'now' => 721],
    ['today' => $crossed,'yest' => $none,  'now' => 120],
] as $i => $c) {
    $st = clock_card_state($c['today'], $c['yest'], $c['now']);
    if ($st['action'] === null) { check("case {$i}: no action, no slot", $st['slot'] === null); continue; }
    $row = ($st['scope'] === 'yesterday') ? $c['yest'] : $c['today'];
    check("case {$i}: write path agrees", clock_next_slot($row, $st['action']) === $st['slot']);
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php tests/attendance_clock_logic.php`
Expected: `Call to undefined function clock_card_state()`

- [ ] **Step 3: Implement**

In `includes/attendance-clock.php`, directly after `clock_display_time()`:

```php
/**
 * How long a night shift may run before we stop believing it is one. 14 hours.
 *
 * A real night shift and a clock-out someone forgot are IDENTICAL in the data —
 * both are a row with an in and no out. Only elapsed time separates them. A
 * generous night shift with overtime is ~12h; past 14h it is almost certainly
 * a forgotten punch, and closing it would book a 24-hour shift into the hours
 * totals.
 */
const CLOCK_NIGHT_SHIFT_MAX_MIN = 840;

/**
 * What the kiosk should offer the person who just scanned. PURE — no database,
 * no clock; $nowMinutes is minutes past midnight of today, injected so this is
 * testable at any instant.
 *
 * Returns:
 *   action     'in' | 'out' | null   what to offer
 *   slot       which column it fills, or null
 *   last_min   raw minutes of the most recent filled slot, or null
 *   last_kind  'in' | 'out' for that slot
 *   scope      'today' | 'yesterday' — which day an out would close
 *   blocked    null | 'status' | 'done'
 *   stale      true when yesterday was left open past the bound above
 *   status     the day's status code, for the message
 *
 * Built ON clock_next_slot(), never a second copy of the slot rules: that
 * function is what the WRITE path uses, and two implementations of one rule is
 * how a kiosk starts offering buttons the punch endpoint refuses.
 *
 * last_min is returned RAW. Formatting is the endpoint's job (see
 * clock_display_time) so this stays free of presentation.
 */
function clock_card_state(array $today, array $yest, int $nowMinutes): array {
    $base = [
        'action'    => null,
        'slot'      => null,
        'last_min'  => null,
        'last_kind' => null,
        'scope'     => 'today',
        'blocked'   => null,
        'stale'     => false,
        'status'    => trim((string)($today['status'] ?? '')),
    ];

    $last = clock_last_punch($today);
    if ($last !== null) {
        $base['last_min']  = $last['min'];
        $base['last_kind'] = $last['kind'];
    }

    // A manager marked this day deliberately. Checked BEFORE the slot lookups
    // so a leave day reads as 'status' and not as 'done' — they need different
    // messages on screen.
    if ($base['status'] !== '' && $base['status'] !== 'P') {
        return ['blocked' => 'status'] + $base;
    }

    // Mid-shift: an out is what's expected. This is the headline case.
    $outSlot = clock_next_slot($today, 'out');
    if ($outSlot !== null) {
        return ['action' => 'out', 'slot' => $outSlot] + $base;
    }

    $inSlot = clock_next_slot($today, 'in');
    if ($inSlot !== null) {
        // Yesterday still open: either a night shift ending now, or a punch
        // someone forgot before going home.
        if (clock_row_is_open($yest)) {
            $yLast   = clock_last_punch($yest);
            $elapsed = $yLast !== null ? ($nowMinutes + 1440) - $yLast['min'] : PHP_INT_MAX;

            if ($elapsed <= CLOCK_NIGHT_SHIFT_MAX_MIN) {
                return [
                    'action'    => 'out',
                    'slot'      => clock_next_slot($yest, 'out'),
                    'scope'     => 'yesterday',
                    'last_min'  => $yLast['min'],
                    'last_kind' => $yLast['kind'],
                ] + $base;
            }
            // Too old to be a shift. Start today and leave the stale row for a
            // manager — closing it here would record a day-long shift.
            return ['action' => 'in', 'slot' => $inSlot, 'stale' => true] + $base;
        }
        return ['action' => 'in', 'slot' => $inSlot] + $base;
    }

    return ['blocked' => 'done'] + $base;
}
```

**Note on `+ $base`:** PHP's array union keeps the **left** operand's keys, so each
return overrides only what it names and inherits the rest. Do not swap the operands.

- [ ] **Step 4: Run it and watch it pass**

Run: `php tests/attendance_clock_logic.php`
Expected: all new lines `PASS`, file ends `ALL PASS`

- [ ] **Step 5: Commit**

```bash
git add includes/attendance-clock.php tests/attendance_clock_logic.php
git commit -m "feat(clock): decide in-or-out from the day row

A night shift and a forgotten clock-out look identical in the data; split
them at 14h so a forgotten punch can't book a day-long shift."
```

---

## Task 5: `api/clock-card.php` — the lookup endpoint

**Files:**
- Create: `api/clock-card.php`

- [ ] **Step 1: Write the endpoint**

Create `api/clock-card.php`:

```php
<?php
/**
 * Who is this card, and what should the kiosk offer them?
 *
 * READ ONLY. Writes nothing, stores no photo, does not touch the device's
 * last-seen stamp. Authenticated by the DEVICE token exactly like
 * api/clock-punch.php — no one is signed in at a shared tablet — and for the
 * same reason it carries no CSRF token: there is no session to ride.
 *
 * Deliberately a separate endpoint from clock-punch.php rather than a mode on
 * it: a read and a write have different failure semantics and should not share
 * a door.
 *
 * No rate limit, deliberately: this needs a valid device token AND a valid
 * 128-bit card token, and anyone holding both can already write a punch — so it
 * exposes no surface clock-punch.php does not. The per-card limiter stays on
 * the write path.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

header('Content-Type: application/json');

function card_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $msg]));
}

// Guards, in the same order as api/clock-punch.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') card_fail('Method not allowed', 405);
if (!attendance_punches_supported())       card_fail('Clocking in isn’t enabled yet.');
if (!clock_kiosk_enabled())                card_fail('Clocking in is switched off. Ask a manager.', 403);

$device = clock_device_by_token((string)($_POST['device_token'] ?? ''));
if (!$device) card_fail('This tablet is no longer registered. Ask a manager to set it up again.', 403);

$staff = clock_staff_by_token((string)($_POST['card'] ?? ''));
if (!$staff) card_fail('Card not recognised. See a manager.', 404);

$today = frontdesk_today_ymd();
$yest  = date('Y-m-d', strtotime('-1 day', strtotime($today)));

$state = clock_card_state(
    clock_day_row((int)$staff['id'], $today),
    clock_day_row((int)$staff['id'], $yest),
    clock_minutes_from_hms(date('H:i:s'))
);

$last = clock_display_time($state['last_min']);

// The sub-line under the greeting. Assembled here rather than in the browser so
// the wording lives beside the rule that produced it.
if ($state['blocked'] === 'status') {
    $message = 'Today is marked ' . attendance_status_label($state['status']) . '. See a manager.';
} elseif ($state['blocked'] === 'done') {
    $message = $last !== null
        ? 'You’re done for today — checked out at ' . $last . '.'
        : 'You’re done for today.';
} elseif ($state['stale']) {
    $message = 'Yesterday was left open — a manager will fix it.';
} elseif ($last === null) {
    $message = 'Ready to start your day?';
} else {
    $verb    = $state['last_kind'] === 'in' ? 'Checked in at ' : 'Checked out at ';
    $when    = $state['scope'] === 'yesterday' ? $last . ' yesterday' : $last;
    $message = $verb . $when;
}

echo json_encode([
    'ok'        => true,
    'error'     => null,
    'name'      => clock_first_name((string)$staff['full_name']),
    'full_name' => $staff['full_name'],
    'action'    => $state['action'],
    'last'      => $last,
    'last_kind' => $state['last_kind'],
    'scope'     => $state['scope'],
    'blocked'   => $state['blocked'],
    'stale'     => $state['stale'],
    'message'   => $message,
]);
```

- [ ] **Step 2: Check it parses**

Run: `php -l api/clock-card.php`
Expected: `No syntax errors detected in api/clock-card.php`

- [ ] **Step 3: Verify the guards reject a bad request**

Start the dev server if it is not already up, then:

```bash
curl -s -X POST http://localhost:8765/api/clock-card.php -d 'device_token=nope&card=nope'
```

Expected: JSON with `"ok":false`. Which error depends on the database — with the
attendance migrations absent you get `Clocking in isn’t enabled yet.`; with them present
and the kiosk switched off, `Clocking in is switched off.`; with it on,
`This tablet is no longer registered.` **Any of these is a pass** — all three prove the
guards run before anything is read. A PHP error or an empty body is a fail.

- [ ] **Step 4: Commit**

```bash
git add api/clock-card.php
git commit -m "feat(clock): read-only card lookup for the kiosk greeting"
```

---

## Task 6: return the venue name at registration

The idle screen names the property. Registration is the only moment the tablet learns it, so stash it then rather than adding a request to every page load.

**Files:**
- Modify: `api/clock-register.php`

- [ ] **Step 1: Replace the final line**

The last line of `api/clock-register.php` is currently:

```php
echo json_encode(['ok' => true, 'error' => null, 'device_id' => $id, 'token' => $token]);
```

`$venueId` is already in scope and already validated against `admin_venue_ids()` further up.
Replace that single line with:

```php
// The idle screen names the property this tablet belongs to. Registration is
// the only moment it learns this, so it is returned once and cached in
// localStorage rather than costing a request on every page load.
$venueName = '';
if ($venueId) {
    $v = db_query("SELECT name FROM venues WHERE id = :id", [':id' => $venueId])->fetch();
    $venueName = $v ? (string)$v['name'] : '';
}

echo json_encode(['ok' => true, 'error' => null, 'device_id' => $id,
                  'token' => $token, 'venue_name' => $venueName]);
```

- [ ] **Step 2: Check it parses**

Run: `php -l api/clock-register.php`
Expected: `No syntax errors detected in api/clock-register.php`

- [ ] **Step 3: Commit**

```bash
git add api/clock-register.php
git commit -m "feat(clock): return the venue name when a tablet registers"
```

---

## Task 7: the idle screen — markup, breathing CSS, server time

Adds the idle panel to the page. It ships hidden; Task 8 wires it up. The kiosk keeps working exactly as it does now until then.

**Files:**
- Modify: `clock.php`

- [ ] **Step 1: Stamp the server's time into the page**

In `clock.php`, after the `$kioskOn = clock_kiosk_enabled();` line, add:

```php
// The idle clock ticks from the SERVER's time, not the tablet's. The whole app
// is Africa/Nairobi and punches are stamped by PHP; a clock reading the
// tablet's own time could disagree with what a punch actually records, which
// is exactly the confusion a visible clock is supposed to prevent.
$nowTs    = time();
$nowLabel = date('H:i', $nowTs);
$dayLabel = date('l j F', $nowTs);
```

- [ ] **Step 2: Add the breathing CSS**

In the `<style>` block, immediately before the `.hidden{display:none}` rule:

```css
/* ── Idle screen ─────────────────────────────────────────────────────────── */
.idle{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:78vh}
.idle__glow{position:absolute;width:min(560px,90vw);aspect-ratio:1;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle,rgba(97,164,132,.20) 0%,rgba(18,38,45,0) 68%);
  animation:idleBreath 7s ease-in-out infinite}
.idle__logo{height:56px;width:auto;filter:brightness(0) invert(1);opacity:.92;position:relative;z-index:2;
  animation:idleRise 1.1s ease-out both, idlePulse 7s ease-in-out 1.1s infinite}
.idle__word{font-size:26px;letter-spacing:.18em;text-transform:uppercase;position:relative;z-index:2;
  animation:idleRise 1.1s ease-out both, idlePulse 7s ease-in-out 1.1s infinite}
.idle__sub{color:#9fb3ba;font-size:15px;margin:16px 0 26px;position:relative;z-index:2}
.idle__start{position:relative;z-index:2;min-width:260px;min-height:76px;font-size:21px;letter-spacing:.05em}
.idle__foot{position:absolute;bottom:18px;left:0;right:0;color:#6d868f;font-size:13px;z-index:2}
.idle__time{font-size:17px;color:#9fb3ba;display:block;margin-bottom:2px}

@keyframes idleBreath{0%,100%{transform:scale(.9);opacity:.55}50%{transform:scale(1.1);opacity:1}}
@keyframes idleRise{from{opacity:0;transform:translateY(14px)}to{opacity:.92;transform:none}}
@keyframes idlePulse{0%,100%{transform:scale(1)}50%{transform:scale(1.035)}}

/* A wall tablet runs this animation every waking hour. Honour the setting. */
@media (prefers-reduced-motion: reduce){
  .idle__glow,.idle__logo,.idle__word{animation:none}
}
```

- [ ] **Step 3: Add the idle panel markup**

Inside `<div id="kioskMode" class="hidden">`, **replace** these two lines:

```html
    <h1>Scan your card</h1>
    <p class="sub">Hold it up to the camera</p>
```

with:

```html
    <div id="idleMode" class="idle">
      <div class="idle__glow"></div>
      <img class="idle__logo" src="<?= e(asset_url('images/whitelogo11.png')) ?>" alt="Tribal Sand"
           onerror="this.outerHTML='<div class=\'idle__word\'>Tribal Sand</div>'">
      <p class="idle__sub">Tap to clock in or out</p>
      <button class="big big--in idle__start" id="startBtn">START</button>
      <p class="msg" id="idleMsg"></p>
      <div class="idle__foot">
        <span class="idle__time" id="idleClock" data-now="<?= e($nowLabel) ?>"><?= e($nowLabel) ?></span>
        <span id="idleWhere"><?= e($dayLabel) ?></span>
      </div>
    </div>

    <div id="scanMode" class="hidden">
      <h1>Scan your card</h1>
      <p class="sub">Hold it up to the camera</p>
    </div>
```

Then move the existing `<video>` and `<canvas>` lines **inside** `scanMode`, and add a
cancel button after them, so `scanMode` reads:

```html
    <div id="scanMode" class="hidden">
      <h1>Scan your card</h1>
      <p class="sub">Hold it up to the camera</p>
      <video id="video" playsinline muted></video>
      <canvas id="frame" class="hidden"></canvas>
      <div class="acts" style="margin-top:18px">
        <button class="big big--ghost" data-cancel>Cancel</button>
      </div>
    </div>
```

- [ ] **Step 4: Give the confirm panel its own greeting line**

Replace the `<div id="person" class="hidden">` block with:

```html
    <div id="person" class="hidden">
      <div class="person" id="personName"></div>
      <p class="meta" id="personMeta"></p>
      <div class="acts" id="personActs"></div>
    </div>
```

The two fixed buttons are gone on purpose — Task 9 renders the one that applies.

- [ ] **Step 5: Verify it renders**

Load `http://localhost:8765/clock.php` in a browser.

Expected: with the kiosk switched **off** you still get "Clocking in is switched off" — the
idle panel is inside the `$kioskOn` branch. With it **on** and a device token already in
`localStorage`, you now see the idle screen: logo breathing gently, a START button, and the
time and date at the bottom. The camera does **not** open (the old JS looks for elements
that moved, so it fails silently — Task 8 fixes that). If the logo does not load you should
see "TRIBAL SAND" as text, never a broken image.

- [ ] **Step 6: Commit**

```bash
git add clock.php
git commit -m "feat(clock): breathing idle screen for the kiosk"
```

---

## Task 8: the state machine and the camera's lifetime

The rewrite. `js/clock-kiosk.js` becomes four explicit states and owns when the camera is on.

**Files:**
- Modify: `js/clock-kiosk.js` (full rewrite below the registration block)

- [ ] **Step 1: Replace the file**

Write `js/clock-kiosk.js`:

```js
/* Staff clock in/out kiosk.

   The device token in localStorage is what authenticates a punch — no one is
   signed in at a shared tablet. Registration happens once.

   FOUR STATES, and the camera belongs to them:

     idle    [camera OFF]  breathing logo, START
     scan    [camera ON]   decoding frames, 45s to show a card
     confirm [camera ON]   greeting + the one button that applies, 20s to act
     result  [camera OFF]  what was recorded, 4s, then idle

   The camera is RELEASED on every return to idle — track.stop(), not a paused
   <video>, which would leave the hardware indicator lit and give up the whole
   point. It is stopped the moment the evidence photo is captured, before the
   punch is even sent: nothing after that needs the lens.

   getUserMedia runs on the START press, not on load. The permission prompt then
   follows a deliberate tap, and on HTTPS the grant persists so later presses go
   straight to the camera. */
(function () {
  'use strict';
  var KEY       = 'ts_clock_device_token';
  var VENUE_KEY = 'ts_clock_venue_name';

  var SCAN_TIMEOUT_MS    = 45000;   // tapped START and walked away
  var CONFIRM_TIMEOUT_MS = 20000;   // scanned and walked away
  var RESULT_MS          = 4000;

  var setup   = document.getElementById('setupMode');
  var kiosk   = document.getElementById('kioskMode');
  var idle    = document.getElementById('idleMode');
  var scan    = document.getElementById('scanMode');
  var person  = document.getElementById('person');
  var video   = document.getElementById('video');
  var frame   = document.getElementById('frame');

  var personName = document.getElementById('personName');
  var personMeta = document.getElementById('personMeta');
  var personActs = document.getElementById('personActs');
  var idleMsg    = document.getElementById('idleMsg');
  var msg        = document.getElementById('msg');

  var token = null;
  try { token = localStorage.getItem(KEY); } catch (e) { token = null; }

  function say(el, text, good) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'msg' + (text ? (good ? ' msg--good' : ' msg--bad') : '');
  }

  /* ── Registration ──────────────────────────────────────────────────────── */
  var devSave = document.getElementById('devSave');
  if (devSave) {
    devSave.addEventListener('click', function () {
      var name = (document.getElementById('devName').value || '').trim();
      var out  = document.getElementById('setupMsg');
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
            try {
              localStorage.setItem(KEY, d.token);
              if (d.venue_name) localStorage.setItem(VENUE_KEY, d.venue_name);
            } catch (e) {}
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

  /* ── Idle furniture: the property name, and a clock started from the
        SERVER's time so it can never disagree with what a punch records. ──── */
  (function () {
    var where = document.getElementById('idleWhere');
    var venue = null;
    try { venue = localStorage.getItem(VENUE_KEY); } catch (e) {}
    if (where && venue) where.textContent = venue + ' · ' + where.textContent;

    var el = document.getElementById('idleClock');
    if (!el) return;
    var parts = (el.getAttribute('data-now') || '').split(':');
    if (parts.length !== 2) return;
    var mins = (parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10));
    var base = Date.now();
    setInterval(function () {
      var m = (mins + Math.floor((Date.now() - base) / 60000)) % 1440;
      el.textContent = ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2);
    }, 10000);
  })();

  /* ── State ─────────────────────────────────────────────────────────────── */
  var stream = null, scanning = false, current = null, pending = null;
  var scanTimer = null, confirmTimer = null, resultTimer = null;
  var ctx = frame.getContext('2d', { willReadFrequently: true });

  function show(which) {
    idle.classList.toggle('hidden',   which !== 'idle');
    scan.classList.toggle('hidden',   which !== 'scan');
    person.classList.toggle('hidden', which !== 'confirm');
  }

  function stopCamera() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    try { video.srcObject = null; } catch (e) {}
  }

  function clearTimers() {
    clearTimeout(scanTimer); clearTimeout(confirmTimer); clearTimeout(resultTimer);
    scanTimer = confirmTimer = resultTimer = null;
  }

  function toIdle() {
    clearTimers();
    stopCamera();
    current = null; pending = null;
    say(msg, ''); say(idleMsg, '');
    show('idle');
  }

  function toScanning() {
    say(idleMsg, '');
    show('scan');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
      .then(function (s) { stream = s; video.srcObject = s; return video.play(); })
      .then(function () {
        scanning = true;
        scanTimer = setTimeout(toIdle, SCAN_TIMEOUT_MS);
        requestAnimationFrame(tick);
      })
      .catch(function () {
        stopCamera();
        show('idle');
        say(idleMsg, 'No camera. Allow camera access for this page, then reload.', false);
      });
  }

  document.getElementById('startBtn').addEventListener('click', toScanning);

  /* ── Decode loop ───────────────────────────────────────────────────────── */
  function tick() {
    if (scanning && video.readyState === video.HAVE_ENOUGH_DATA) {
      frame.width = video.videoWidth;
      frame.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, frame.width, frame.height);
      try {
        var img  = ctx.getImageData(0, 0, frame.width, frame.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height) : null;
        if (code && code.data) onCard(code.data.trim());
      } catch (e) { /* a frame we could not read; try the next one */ }
    }
    if (stream) requestAnimationFrame(tick);
  }

  /* ── A card was seen: ask the server who it is ─────────────────────────── */
  function onCard(data) {
    if (!/^[0-9a-f]{32}$/.test(data)) return;   // not one of our cards
    if (current === data) return;
    scanning = false;
    current  = data;
    clearTimeout(scanTimer);

    personName.textContent = 'Reading card…';
    personMeta.textContent = '';
    personActs.innerHTML   = '';
    show('confirm');

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);

    fetch('/api/clock-card.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Could not read that card.' }; }); })
      .then(function (d) { d && d.ok ? renderPerson(d) : renderRefusal(d && d.error); })
      .catch(function () { renderRefusal('No connection. Try again in a moment.'); });
  }

  /* The greeting, and the ONE button that applies. Never a fallback to two
     buttons: that hands the decision back to the person, which is the thing
     this replaced. */
  function renderPerson(d) {
    pending = d.action;
    personName.textContent = (d.action === 'out' ? 'Hi ' : 'Hello, ') + (d.name || 'there');
    personMeta.textContent = d.message || '';
    personActs.innerHTML   = '';

    if (d.action === 'in' || d.action === 'out') {
      var b = document.createElement('button');
      b.className = 'big ' + (d.action === 'in' ? 'big--in' : 'big--out');
      b.setAttribute('data-kind', d.action);
      b.textContent = d.action === 'in' ? 'Clock in' : 'Clock out';
      personActs.appendChild(b);
    }

    var c = document.createElement('button');
    c.className = 'big big--ghost';
    c.setAttribute('data-cancel', '');
    c.textContent = (d.action === 'in' || d.action === 'out') ? 'Cancel' : 'Done';
    personActs.appendChild(c);

    confirmTimer = setTimeout(toIdle, CONFIRM_TIMEOUT_MS);
  }

  function renderRefusal(text) {
    pending = null;
    personName.textContent = '';
    personActs.innerHTML   = '';
    say(msg, text || 'Could not read that card.', false);
    resultTimer = setTimeout(toIdle, RESULT_MS);
  }

  /* ── Confirm and record ────────────────────────────────────────────────── */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-cancel]')) { toIdle(); return; }

    var btn = ev.target.closest('[data-kind]');
    if (!btn || !current || !pending) return;

    clearTimeout(confirmTimer);

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);
    body.append('kind', btn.getAttribute('data-kind'));

    // Capture BEFORE releasing the camera, then release it immediately — the
    // evidence photo is in hand and nothing downstream needs the lens.
    var shot = capture();
    if (shot) body.append('photo', shot, 'punch.jpg');
    stopCamera();

    btn.disabled = true;
    say(msg, 'Saving…', true);

    fetch('/api/clock-punch.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please try again.' }; }); })
      .then(function (d) {
        if (d && d.ok) {
          var who = (d.name || '').split(/\s+/)[0];
          say(msg, 'Thanks ' + who + ' — clocked ' + d.kind + ' at ' + d.time + '.', true);
        } else {
          say(msg, (d && d.error) || 'That didn’t save.', false);
        }
        personActs.innerHTML = '';
        resultTimer = setTimeout(toIdle, RESULT_MS);
      })
      .catch(function () {
        say(msg, 'No connection. The punch was NOT saved — try again.', false);
        personActs.innerHTML = '';
        resultTimer = setTimeout(toIdle, RESULT_MS);
      });
  });

  /* A still from the live stream, as a JPEG blob. Returns null if unavailable —
     the punch still goes through without it.

     Downscaled to at most 640px on the long edge before encoding. The decode
     canvas runs at the camera's native size (often 1280x720) because jsQR needs
     the detail to read a card, but the stored evidence does not: 640px is ample
     to recognise a face, and at roughly a quarter of the pixels it cuts each
     file from ~150KB to ~40KB. Across 73 staff punching twice a day that is the
     difference between ~1GB and ~250MB a month. */
  var SHOT_MAX_EDGE = 640;
  var shotCanvas = document.createElement('canvas');

  function capture() {
    try {
      if (!frame.width || !frame.height) return null;

      var scale = Math.min(1, SHOT_MAX_EDGE / Math.max(frame.width, frame.height));
      shotCanvas.width  = Math.max(1, Math.round(frame.width * scale));
      shotCanvas.height = Math.max(1, Math.round(frame.height * scale));
      shotCanvas.getContext('2d').drawImage(frame, 0, 0, shotCanvas.width, shotCanvas.height);

      var data = shotCanvas.toDataURL('image/jpeg', 0.7).split(',')[1];
      var bin  = atob(data);
      var arr  = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      return new Blob([arr], { type: 'image/jpeg' });
    } catch (e) { return null; }
  }

  toIdle();
})();
```

- [ ] **Step 2: Verify the camera stays off at rest**

Load `http://localhost:8765/clock.php` with the kiosk switched on and a device registered.

Expected: the idle screen shows and **no camera indicator lights up**. Press START — the
permission prompt appears (first time only), then the video feed. Press Cancel — the feed
stops and the indicator **goes out**. This is the change; if the indicator stays lit after
Cancel, `stopCamera()` is not being reached.

- [ ] **Step 3: Verify the scan timeout**

Press START and leave it alone for 45 seconds.
Expected: it returns to the idle screen on its own and the camera indicator goes out.

- [ ] **Step 4: Commit**

```bash
git add js/clock-kiosk.js
git commit -m "feat(clock): kiosk state machine, camera off until START

The camera ran from page load for the life of the page — on a tablet that is
mounted and never reloaded, that is a camera watching reception all day. It is
now acquired on a START press and released with track.stop() on every return
to idle, and the moment the evidence photo is captured."
```

---

## Task 9: end-to-end verification

No new code. This is the task that proves the feature works against a real database, and it is not optional.

**Prerequisites:** the attendance migrations (`add_hr_staff.sql`, `add_attendance.sql`,
`add_attendance_punches.sql`) applied to whichever database `.env` points at, the kiosk
switched on in Admin → Clock kiosks, a registered tablet (or browser), and one `hr_staff`
row with a printed or on-screen QR card from `admin/attendance-cards.php`.

- [ ] **Step 1: Fresh day**

Scan a card for someone with no attendance row today.
Expected: **"Hello, <first name>"**, sub-line "Ready to start your day?", a single
**Clock in** button, plus Cancel.

- [ ] **Step 2: Clock in, then rescan**

Tap Clock in, wait for "Thanks … clocked in at HH:MM", let it return to idle, press START
and scan the same card.
Expected: **"Hi <first name>"**, sub-line **"Checked in at HH:MM"** matching what you just
recorded, and a single **Clock out** button. This is the headline case from the spec.

- [ ] **Step 3: Complete the day**

Clock out, rescan (expect "Checked out at …" + **Clock in**), clock in, rescan (expect
"Checked in at …" + **Clock out**), clock out, rescan.
Expected: the fourth scan shows **"You're done for today — checked out at HH:MM."** with
**no action button**, only Done.

- [ ] **Step 4: A marked day refuses**

In `admin/attendance.php`, set that person's status for today to **Leave**. Rescan.
Expected: **"Today is marked Leave. See a manager."**, no action button. The kiosk must not
offer to overwrite a day a manager marked.

- [ ] **Step 5: Night shift**

In `admin/attendance.php`, give the person a **yesterday** row with `in1` set a few hours
before now and no `out1`, and clear today's row. Rescan.
Expected: **Clock out**, sub-line reading **"Checked in at HH:MM yesterday"**.

- [ ] **Step 6: The forgotten punch**

Change that yesterday `in1` to a time more than 14 hours before now. Rescan.
Expected: the offer flips to **Clock in**, sub-line **"Yesterday was left open — a manager
will fix it."** Tap it and confirm in `admin/attendance.php` that **today** gained an `in1`
and **yesterday's row was not touched**. This is the case that protects the hours totals —
if yesterday gained an `out1`, the bound is not being applied.

- [ ] **Step 7: Photo evidence still lands**

Open `admin/attendance.php` for today and confirm the punches you made carry photos
(`admin/attendance-photo.php` serves them). Stopping the camera early must not have cost the
capture.

- [ ] **Step 8: Run the full suite**

Run: `php tests/attendance_clock_logic.php`
Expected: `ALL PASS`

- [ ] **Step 9: Commit any fixes**

If any step above failed, fix it, re-run steps 1–8, and commit with a message naming what
was wrong.

---

## Task 10: record the conventions

`CLAUDE.md` is this project's memory of decisions that are expensive to rediscover. Two here qualify.

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the section**

In `CLAUDE.md`, after the `### Team roles & job types` section, add:

```markdown
### Clock kiosk — the system picks the action, the camera sleeps
`clock.php` + `js/clock-kiosk.js`, model in `includes/attendance-clock.php`. Migrations:
`add_hr_staff` → `add_attendance` → `add_attendance_punches`. Owner kill switch:
`clock_kiosk_enabled()`. Test: `php tests/attendance_clock_logic.php` (pure; runs with no DB).
- **The kiosk decides in-or-out, the person confirms.** `clock_card_state($today, $yest,
  $nowMin)` resolves it and the tablet renders the ONE button that applies. It is **built on
  `clock_next_slot()`** — never a second copy of the slot rules, or the kiosk starts offering
  buttons `clock_record_punch()` then refuses. The server re-decides on the punch regardless:
  the client's `kind` is a request, never an instruction. The JS must **never** fall back to
  showing both buttons, which hands the decision back to the person.
- **A night shift and a forgotten clock-out are identical in the data** — an `in` with no
  `out`. They split on elapsed time at `CLOCK_NIGHT_SHIFT_MAX_MIN` (840 = 14h): inside it the
  punch closes yesterday, outside it starts today and leaves the stale row for a manager.
  Without the bound, someone who went home without scanning books a **24-hour shift** into
  the hours totals (`clock_record_punch()` stores a yesterday-out as minutes + 1440).
- **`clock_last_punch()` is the most recent filled slot, NOT the open clock-in.** The
  greeting needs a time in states where nothing is open ("checked out at 13:00"). Mid-shift
  the newest slot *is* the open in, so one rule serves every state.
- **Display with `clock_display_time()`, not `attendance_min_to_hhmm()`** — the latter renders
  "00:30+1", which is right on the manager's editor and noise in a greeting.
- **The camera is off until START is pressed** and is released with `track.stop()` on every
  return to idle, including the instant the evidence photo is captured. Pausing the `<video>`
  is not enough — the hardware indicator stays lit. The 45s scan / 20s confirm timeouts are
  load-bearing: a tablet is mounted and never reloaded, so one person walking away would
  otherwise leave the camera live indefinitely. `getUserMedia` runs on the tap, not on load.
- **The idle clock is server-stamped.** `clock.php` renders PHP's time into `data-now` and the
  script ticks from it. A JS clock would follow the *tablet's* time and could disagree with
  what a punch records (the app is Africa/Nairobi throughout).
- `api/clock-card.php` is **read-only** — same guards as `api/clock-punch.php`, in the same
  order, no CSRF (no session to ride), and **no rate limit**: it needs a valid device token
  AND a valid 128-bit card token, and anyone with both can already punch.
```

- [ ] **Step 2: Add the new files to the File Map**

In the `## File Map` table, after the `includes/team.php` row (or the nearest attendance
entry), add:

```markdown
| `includes/attendance-clock.php` | Clock kiosk model — pure slot/state resolution, card + device auth, photo purge |
| `api/clock-card.php` | Read-only card lookup — who scanned, and what to offer them |
| `clock.php` · `js/clock-kiosk.js` | The kiosk: breathing idle screen, scan, greeting, confirm (camera off at rest) |
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: record the clock kiosk conventions"
```

---

## Self-review notes

Checked against the spec:

| Spec section | Task |
|---|---|
| §4 resolver, `last_min`/`last_kind`, 14h bound | 2, 3, 4 |
| §4 `clock_first_name()` | 1 |
| §5 lookup endpoint + guard order | 5 |
| §6.1 state machine | 8 |
| §6.2 camera lifetime, `track.stop()`, timeouts | 8 (steps 2–3 verify) |
| §6.3 breathing idle screen, reduced motion, logo fallback | 7 |
| §6.4 server-stamped clock, venue name | 6, 7, 8 |
| §6.5 punch unchanged, server re-decides, no two-button fallback | 8 |
| §7 no rate limit, with reasoning | 5 |
| §8 copy — all 8 rows | 5 (message assembly), 8 (greeting prefix) |
| §9 testing, incl. the read/write agreement property | 1–4, 9 |
| §10 files | all |

Naming is consistent across tasks: `clock_card_state`, `clock_last_punch`,
`clock_first_name`, `clock_display_time`, `CLOCK_NIGHT_SHIFT_MAX_MIN`, and the JSON keys
`action` / `last` / `last_kind` / `scope` / `blocked` / `stale` / `message`.

**One thing deliberately left out:** the spec's §8 "Card unknown" row is handled by the
existing 404 from `clock_staff_by_token()`, surfaced through `renderRefusal()` in Task 8 —
no separate task.
