# Zuri Restaurant Reservations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** External clients can request a table at Zuri from the public site; every request lands in the admin as `pending` and a manager confirms, declines, edits or cancels it.

**Architecture:** One new table (`restaurant_reservations`), one pure-logic helper module (`includes/restaurant.php`) shared by the public endpoint and the admin page, a JSON POST endpoint, a reusable form widget mounted on a minimal public page, and one admin page plus its action handler. Three transactional emails ride the existing Resend pipeline. All new logic that can be tested without a database is tested without a database.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO with prepared statements only, vanilla JS/CSS, Resend for email, Cloudflare Turnstile for spam.

**Spec:** `docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md`

---

## Conventions this plan assumes (read before Task 1)

These are project rules from `CLAUDE.md`. Violating them breaks production in ways that are hard to see locally:

- **Never** use `$_SERVER['REMOTE_ADDR']`. Always `client_ip()` (defined in `includes/db.php`) — the app sits behind a load balancer and `REMOTE_ADDR` is always the proxy.
- **Never** assume UTC. `includes/db.php` sets `Africa/Nairobi` in PHP and issues `SET TIME ZONE 'Africa/Nairobi'` on every connect. PHP `date()` and Postgres `CURRENT_DATE` already agree.
- New timestamp columns are **`TIMESTAMPTZ`**, never naive `TIMESTAMP`.
- All SQL goes through `db_query()` with bound parameters. Never interpolate user input into SQL.
- `verify_captcha()` is fail-closed. Never restore a `return true` bypass.
- Migrations are auto-discovered — `admin/migrate.php` globs `db/migrations/*.sql`. Dropping the file in is the whole registration step.

**Run the test suite with:** `php tests/restaurant_logic.php`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `db/migrations/add_restaurant_reservations.sql` | Table + indexes. Idempotent. |
| `includes/restaurant.php` | All reservation logic. Pure functions (slots, validation, transitions, reference) plus two thin DB helpers (`restaurant_supported()`, `restaurant_hours()`). Single source of truth for the endpoint and the admin page. |
| `tests/restaurant_logic.php` | Tests every pure function. No DB, no migration required. |
| `api/restaurant-book.php` | Public JSON POST endpoint. Spam guards, validation, insert, emails. |
| `includes/form-restaurant.php` | Reusable booking form widget (markup + its JS). |
| `zuri-restaurant.php` | Minimal public page mounting the widget. Served at `/zuri-restaurant`. |
| `admin/restaurant.php` | Counters, today's list, upcoming list, filters, edit form. |
| `admin/restaurant-action.php` | POST handler for confirm / decline / cancel / edit. |
| `includes/mail.php` (modify) | Three new send functions + their HTML builders. |
| `admin/_layout.php` (modify) | New `Restaurant` nav group. |

`includes/restaurant.php` stays pure wherever possible so the whole rules layer is testable without a database — the project already does this in `tests/currency_logic.php` and `tests/team_logic.php`.

---

## Task 1: Service-hours config and slot generation

**Files:**
- Create: `includes/restaurant.php`
- Create: `tests/restaurant_logic.php`

Slots run from `from` inclusive to `to` exclusive: `to` is closing time and is not itself bookable. With `18:00`–`22:00` at 30-minute steps the last seating is `21:30`.

- [ ] **Step 1: Write the failing test**

Create `tests/restaurant_logic.php`:

```php
<?php
declare(strict_types=1);
// Zuri restaurant reservation logic. Run: php tests/restaurant_logic.php
// Pure logic — no DB writes, no migration required.
require_once __DIR__ . '/../includes/restaurant.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── restaurant_normalise_hours ─────────────────────────────────────────────
$d = restaurant_normalise_hours(null);
check('null config falls back to 18:00-22:00/30', $d['from'] === '18:00' && $d['to'] === '22:00' && $d['step'] === 30);
check('null config opens every day',              count($d['days']) === 7);

$p = restaurant_normalise_hours(['from' => '12:00', 'to' => '15:00', 'step' => 60, 'days' => [1,2,3]]);
check('partial config is preserved',              $p['from'] === '12:00' && $p['step'] === 60 && $p['days'] === [1,2,3]);

$bad = restaurant_normalise_hours(['from' => 'nonsense', 'to' => '', 'step' => 0, 'days' => 'x']);
check('garbage config falls back to defaults',    $bad['from'] === '18:00' && $bad['step'] === 30 && count($bad['days']) === 7);

// ── restaurant_slots_for ───────────────────────────────────────────────────
// 2026-08-20 is a Thursday (day 4).
$cfg  = ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
$open = restaurant_slots_for('2026-08-20', $cfg);
check('first slot is the opening time',     $open[0] === '18:00');
check('closing time is NOT bookable',       !in_array('22:00', $open, true));
check('last seating is 21:30',              end($open) === '21:30');
check('30-min steps give 8 slots',          count($open) === 8);

$hourly = restaurant_slots_for('2026-08-20', ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '21:00', 'step' => 60]);
check('60-min steps give 3 slots',          $hourly === ['18:00', '19:00', '20:00']);

$closed = restaurant_slots_for('2026-08-20', ['days' => [0,1,2,3,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30]);
check('closed day yields no slots',         $closed === []);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "{$failures} FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/restaurant_logic.php`
Expected: FAIL — `require_once(includes/restaurant.php): Failed to open stream: No such file or directory`

- [ ] **Step 3: Write minimal implementation**

Create `includes/restaurant.php`:

```php
<?php
/**
 * Zuri restaurant reservations — shared logic.
 *
 * Everything here that can be pure IS pure, so api/restaurant-book.php and
 * admin/restaurant.php enforce identical rules and the whole rules layer is
 * testable without a database (tests/restaurant_logic.php).
 *
 * See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
 */
declare(strict_types=1);

const RESTAURANT_HORIZON_DAYS = 120;
const RESTAURANT_PARTY_MIN    = 1;
const RESTAURANT_PARTY_MAX    = 20;

/** Default service hours when a venue has none configured. */
function restaurant_default_hours(): array {
    return ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
}

/**
 * Coerce a stored hours config into a valid one, field by field. Any field that
 * is missing or malformed falls back to its default — a broken settings row
 * degrades to "open normal hours", never to "closed forever".
 */
function restaurant_normalise_hours(?array $cfg): array {
    $def = restaurant_default_hours();
    if (!$cfg) return $def;

    $isTime = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;

    $from = $isTime($cfg['from'] ?? null) ? $cfg['from'] : $def['from'];
    $to   = $isTime($cfg['to']   ?? null) ? $cfg['to']   : $def['to'];
    $step = (isset($cfg['step']) && is_numeric($cfg['step']) && (int)$cfg['step'] > 0)
          ? (int)$cfg['step'] : $def['step'];

    $days = $def['days'];
    if (isset($cfg['days']) && is_array($cfg['days'])) {
        $clean = [];
        foreach ($cfg['days'] as $d) {
            if (is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) $clean[] = (int)$d;
        }
        if ($clean) $days = array_values(array_unique($clean));
    }

    return ['days' => $days, 'from' => $from, 'to' => $to, 'step' => $step];
}

/**
 * Bookable slot times for a date, as 'HH:MM' strings.
 * `from` is inclusive, `to` is exclusive — `to` is closing time, not a seating.
 * Returns [] when the venue is closed that weekday.
 */
function restaurant_slots_for(string $ymd, array $cfg): array {
    $cfg = restaurant_normalise_hours($cfg);

    $ts = strtotime($ymd . ' 00:00:00');
    if ($ts === false) return [];
    if (!in_array((int)date('w', $ts), $cfg['days'], true)) return [];

    [$fh, $fm] = array_map('intval', explode(':', $cfg['from']));
    [$th, $tm] = array_map('intval', explode(':', $cfg['to']));
    $start = $fh * 60 + $fm;
    $end   = $th * 60 + $tm;

    $slots = [];
    for ($m = $start; $m < $end; $m += $cfg['step']) {
        $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $slots;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/restaurant_logic.php`
Expected: `ALL PASS`, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/restaurant.php tests/restaurant_logic.php
git commit -m "feat(restaurant): service-hours config + slot generation"
```

---

## Task 2: Request validation

**Files:**
- Modify: `includes/restaurant.php`
- Modify: `tests/restaurant_logic.php`

`restaurant_validate()` returns a `field => message` map. Empty map means valid. It takes "today" as an argument rather than calling `date()` so the tests are not time-bombs.

- [ ] **Step 1: Write the failing test**

Append to `tests/restaurant_logic.php`, immediately **before** the final `echo`/`exit` lines:

```php
// ── restaurant_validate ────────────────────────────────────────────────────
$cfg   = ['days' => [0,1,2,3,4,5,6], 'from' => '18:00', 'to' => '22:00', 'step' => 30];
$today = '2026-08-19';
$valid = [
    'name'       => 'Dan Oburu',
    'email'      => 'dan@example.com',
    'phone'      => '0703869559',
    'party_size' => 3,
    'date'       => '2026-08-20',
    'time'       => '18:30',
    'occasion'   => 'romantic',
];

check('a good request has no errors',      restaurant_validate($valid, $cfg, $today) === []);

check('name is required',                  isset(restaurant_validate(['name' => ''] + $valid, $cfg, $today)['name']));
check('email must be valid',               isset(restaurant_validate(['email' => 'nope'] + $valid, $cfg, $today)['email']));
check('phone stays optional',              restaurant_validate(['phone' => ''] + $valid, $cfg, $today) === []);

check('past date rejected',                isset(restaurant_validate(['date' => '2026-08-18'] + $valid, $cfg, $today)['date']));
check('today is bookable',                 !isset(restaurant_validate(['date' => '2026-08-19'] + $valid, $cfg, $today)['date']));
check('beyond 120-day horizon rejected',   isset(restaurant_validate(['date' => '2026-12-31'] + $valid, $cfg, $today)['date']));
check('malformed date rejected',           isset(restaurant_validate(['date' => '20/08/2026'] + $valid, $cfg, $today)['date']));

check('off-grid time rejected',            isset(restaurant_validate(['time' => '18:36'] + $valid, $cfg, $today)['time']));
check('closing time not bookable',         isset(restaurant_validate(['time' => '22:00'] + $valid, $cfg, $today)['time']));
check('on-grid time accepted',             !isset(restaurant_validate(['time' => '21:30'] + $valid, $cfg, $today)['time']));

check('party of 0 rejected',               isset(restaurant_validate(['party_size' => 0] + $valid, $cfg, $today)['party_size']));
check('party of 1 accepted',               !isset(restaurant_validate(['party_size' => 1] + $valid, $cfg, $today)['party_size']));
check('party of 20 accepted',              !isset(restaurant_validate(['party_size' => 20] + $valid, $cfg, $today)['party_size']));
$big = restaurant_validate(['party_size' => 21] + $valid, $cfg, $today);
check('party over 20 rejected',            isset($big['party_size']));
check('large party told to call',          str_contains(strtolower($big['party_size']), 'call'));

check('unknown occasion rejected',         isset(restaurant_validate(['occasion' => 'wedding'] + $valid, $cfg, $today)['occasion']));
check('empty occasion allowed',            restaurant_validate(['occasion' => ''] + $valid, $cfg, $today) === []);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/restaurant_logic.php`
Expected: FAIL with `Call to undefined function restaurant_validate()`

- [ ] **Step 3: Write minimal implementation**

Append to `includes/restaurant.php`:

```php
/** Occasions offered on the booking form. Empty/absent is allowed. */
function restaurant_occasions(): array {
    return ['romantic', 'birthday', 'anniversary', 'business', 'other'];
}

/**
 * Validate a booking request against a venue's hours.
 * Returns a field => message map; an empty array means valid.
 *
 * $todayYmd is injected rather than read from date() so this stays pure and
 * the tests do not rot. Callers pass date('Y-m-d') (Nairobi-local).
 */
function restaurant_validate(array $in, array $cfg, string $todayYmd): array {
    $err = [];

    $name  = trim((string)($in['name']  ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    if ($name === '')                               $err['name']  = 'Your name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err['email'] = 'A valid email address is required.';

    $party = (int)($in['party_size'] ?? 0);
    if ($party < RESTAURANT_PARTY_MIN) {
        $err['party_size'] = 'Please tell us how many are dining.';
    } elseif ($party > RESTAURANT_PARTY_MAX) {
        $err['party_size'] = 'For parties over ' . RESTAURANT_PARTY_MAX
                           . ', please call us so we can look after you properly.';
    }

    $date = trim((string)($in['date'] ?? ''));
    $time = trim((string)($in['time'] ?? ''));

    $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    if (!$dateOk) {
        $err['date'] = 'Please choose a date.';
    } elseif ($date < $todayYmd) {
        $err['date'] = 'That date has already passed.';
    } elseif ($date > date('Y-m-d', strtotime($todayYmd . ' +' . RESTAURANT_HORIZON_DAYS . ' days'))) {
        $err['date'] = 'We take bookings up to ' . RESTAURANT_HORIZON_DAYS . ' days ahead.';
    }

    // Only check the time once the date is sound — slots depend on the weekday.
    if (!isset($err['date'])) {
        $slots = restaurant_slots_for($date, $cfg);
        if (!$slots) {
            $err['date'] = 'We are closed that day. Please choose another date.';
        } elseif (!in_array($time, $slots, true)) {
            $err['time'] = 'Please choose one of the available times.';
        }
    }

    $occasion = trim((string)($in['occasion'] ?? ''));
    if ($occasion !== '' && !in_array($occasion, restaurant_occasions(), true)) {
        $err['occasion'] = 'Please choose one of the listed occasions.';
    }

    return $err;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/restaurant_logic.php`
Expected: `ALL PASS`, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/restaurant.php tests/restaurant_logic.php
git commit -m "feat(restaurant): request validation (dates, slots, party size, occasion)"
```

---

## Task 3: Status transitions and reference codes

**Files:**
- Modify: `includes/restaurant.php`
- Modify: `tests/restaurant_logic.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/restaurant_logic.php`, before the final `echo`/`exit`:

```php
// ── restaurant_can_transition ──────────────────────────────────────────────
check('pending → confirmed',        restaurant_can_transition('pending', 'confirmed'));
check('pending → declined',         restaurant_can_transition('pending', 'declined'));
check('confirmed → cancelled',      restaurant_can_transition('confirmed', 'cancelled'));
check('pending → cancelled blocked', !restaurant_can_transition('pending', 'cancelled'));
check('confirmed → declined blocked',!restaurant_can_transition('confirmed', 'declined'));
check('declined is terminal',       !restaurant_can_transition('declined', 'confirmed'));
check('cancelled is terminal',      !restaurant_can_transition('cancelled', 'confirmed'));
check('no-op transition blocked',   !restaurant_can_transition('pending', 'pending'));
check('unknown status blocked',     !restaurant_can_transition('pending', 'seated'));

// ── restaurant_make_reference ──────────────────────────────────────────────
$ref = restaurant_make_reference();
check('reference matches ZR-XXXXX',     preg_match('/^ZR-[23456789A-HJ-NP-Z]{5}$/', $ref) === 1);
check('reference avoids 0/O/1/I',       preg_match('/[01OI]/', substr($ref, 3)) === 0);
$many = [];
for ($i = 0; $i < 200; $i++) { $many[restaurant_make_reference()] = true; }
check('references vary',                count($many) > 190);

// ── restaurant_status_badge_class ──────────────────────────────────────────
check('pending badge is orange',    restaurant_status_badge_class('pending')   === 'badge--orange');
check('confirmed badge is green',   restaurant_status_badge_class('confirmed') === 'badge--green');
check('declined badge is red',      restaurant_status_badge_class('declined')  === 'badge--red');
check('cancelled badge is grey',    restaurant_status_badge_class('cancelled') === 'badge--grey');
check('unknown badge is grey',      restaurant_status_badge_class('wat')       === 'badge--grey');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/restaurant_logic.php`
Expected: FAIL with `Call to undefined function restaurant_can_transition()`

- [ ] **Step 3: Write minimal implementation**

Append to `includes/restaurant.php`:

```php
/** Reference alphabet: no 0/O or 1/I, so a code survives being read over the phone. */
const RESTAURANT_REF_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

/** The only legal status moves. Anything else — including a no-op — is refused. */
function restaurant_can_transition(string $from, string $to): bool {
    $allowed = [
        'pending'   => ['confirmed', 'declined'],
        'confirmed' => ['cancelled'],
        'declined'  => [],
        'cancelled' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

/** Generate a guest-quotable reference, e.g. ZR-8F3K2. Uniqueness is the DB's job. */
function restaurant_make_reference(): string {
    $out = '';
    $max = strlen(RESTAURANT_REF_ALPHABET) - 1;
    for ($i = 0; $i < 5; $i++) {
        $out .= RESTAURANT_REF_ALPHABET[random_int(0, $max)];
    }
    return 'ZR-' . $out;
}

/** Admin badge colour for a status — reuses the existing .badge--* classes. */
function restaurant_status_badge_class(string $status): string {
    return match ($status) {
        'pending'   => 'badge--orange',
        'confirmed' => 'badge--green',
        'declined'  => 'badge--red',
        default     => 'badge--grey',
    };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/restaurant_logic.php`
Expected: `ALL PASS`, exit 0

- [ ] **Step 5: Commit**

```bash
git add includes/restaurant.php tests/restaurant_logic.php
git commit -m "feat(restaurant): status transitions, reference codes, badge classes"
```

---

## Task 4: Migration and DB-backed helpers

**Files:**
- Create: `db/migrations/add_restaurant_reservations.sql`
- Modify: `includes/restaurant.php`

No new test file — these two helpers touch the database, and the suite is deliberately DB-free. They are exercised end-to-end in Task 6.

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_restaurant_reservations.sql`:

```sql
-- Zuri restaurant reservations (request → manager confirms). Piece B of the
-- Restaurant feature. See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
--
-- Idempotent: safe to re-run from /admin/migrate.php.

CREATE TABLE IF NOT EXISTS restaurant_reservations (
    id             SERIAL PRIMARY KEY,
    reference      VARCHAR(20)  NOT NULL UNIQUE,
    venue_id       INT          NOT NULL REFERENCES venues(id) ON DELETE RESTRICT,
    status         VARCHAR(20)  NOT NULL DEFAULT 'pending',
    guest_name     VARCHAR(255) NOT NULL,
    guest_email    VARCHAR(255) NOT NULL,
    guest_phone    VARCHAR(50),
    party_size     INT          NOT NULL,
    reserved_on    DATE         NOT NULL,
    reserved_at    TIME         NOT NULL,
    occasion       VARCHAR(40),
    notes          TEXT,
    staff_notes    TEXT,
    confirmed_by   INT          REFERENCES admin_users(id) ON DELETE SET NULL,
    confirmed_at   TIMESTAMPTZ,
    decline_reason TEXT,
    source_page    TEXT,
    referrer       TEXT,
    utm_source     VARCHAR(255),
    utm_medium     VARCHAR(255),
    utm_campaign   VARCHAR(255),
    utm_term       VARCHAR(255),
    utm_content    VARCHAR(255),
    user_agent     TEXT,
    ip_address     VARCHAR(64),
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT restaurant_reservations_status_check
        CHECK (status IN ('pending', 'confirmed', 'declined', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS idx_resv_venue_date ON restaurant_reservations(venue_id, reserved_on);
CREATE INDEX IF NOT EXISTS idx_resv_status     ON restaurant_reservations(status);
CREATE INDEX IF NOT EXISTS idx_resv_date       ON restaurant_reservations(reserved_on);
```

- [ ] **Step 2: Apply it locally and verify the table exists**

Run:
```bash
php -r '$b=getcwd(); require $b."/includes/db.php"; db()->exec(file_get_contents($b."/db/migrations/add_restaurant_reservations.sql")); echo db_query("SELECT to_regclass(\047public.restaurant_reservations\047)")->fetchColumn(), "\n";'
```
Expected output: `restaurant_reservations`

- [ ] **Step 3: Add the DB-backed helpers**

Append to `includes/restaurant.php`:

```php
require_once __DIR__ . '/db.php';

/**
 * Is the reservations table present? The live DB migrates separately through
 * /admin/migrate.php, so there is a real window where this code is deployed and
 * the table is not. Callers use this to hide the nav item instead of 500ing.
 */
function restaurant_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.restaurant_reservations')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** Service hours for a venue slug, falling back to the defaults. */
function restaurant_hours(string $venueSlug): array {
    $raw = setting('restaurant_hours_' . $venueSlug, '');
    $cfg = $raw !== '' ? json_decode($raw, true) : null;
    return restaurant_normalise_hours(is_array($cfg) ? $cfg : null);
}

/** Where staff alerts for a venue go. Falls back to a global key, then MAIL_FROM. */
function restaurant_inbox(string $venueSlug): string {
    $to = setting('restaurant_inbox_' . $venueSlug, '') ?: setting('restaurant_inbox', '');
    if ($to !== '') return $to;
    $env = parse_env();
    return $env['MAIL_FROM'] ?? 'reservations@tribalsand.com';
}

/** Venue row by slug (id + name + location), or false. */
function restaurant_venue(string $slug): array|false {
    return db_query('SELECT id, slug, name, location FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
}
```

- [ ] **Step 4: Verify the helpers load and the guard returns true**

Run:
```bash
php -r 'require getcwd()."/includes/restaurant.php"; var_dump(restaurant_supported()); print_r(restaurant_hours("zuri")); print_r(restaurant_venue("zuri"));'
```
Expected: `bool(true)`, the default hours array, and the Zuri venue row (id 3).

- [ ] **Step 5: Run the test suite (must still pass — helpers must not break purity)**

Run: `php tests/restaurant_logic.php`
Expected: `ALL PASS`

- [ ] **Step 6: Commit**

```bash
git add db/migrations/add_restaurant_reservations.sql includes/restaurant.php
git commit -m "feat(restaurant): reservations table + supported/hours/inbox helpers"
```

---

## Task 5: The three emails

**Files:**
- Modify: `includes/mail.php` (append at end, before no other function — order does not matter)

Two functions. `send_restaurant_request()` sends the guest acknowledgement **and** the staff alert in one call (they always fire together). `send_restaurant_confirmed()` sends the confirmation on approval. There is no decline email by design.

Every function takes the same `$r` shape, which is what `api/restaurant-book.php` and `admin/restaurant-action.php` both build:

```
[
  'reference'   => 'ZR-8F3K2',
  'guest_name'  => 'Dan Oburu',
  'guest_email' => 'dan@example.com',
  'guest_phone' => '0703869559',
  'party_size'  => 3,
  'reserved_on' => '2026-08-20',   // Y-m-d
  'reserved_at' => '18:30',        // H:i
  'occasion'    => 'romantic',     // may be ''
  'notes'       => '',             // guest's own words; may be ''
  'venue_name'  => 'Zuri',
  'venue_slug'  => 'zuri',
]
```

`staff_notes` and `decline_reason` are **never** passed in and never rendered — they are internal.

- [ ] **Step 1: Add the shared formatting helpers**

Append to `includes/mail.php`:

```php
/* ── Restaurant reservations ─────────────────────────────────────────────── */

/** "Thursday 20 August 2026 at 18:30" — one phrasing used by every restaurant email. */
function _restaurant_when(array $r): string {
    $ts = strtotime($r['reserved_on'] . ' ' . $r['reserved_at']);
    return $ts ? date('l j F Y', $ts) . ' at ' . date('H:i', $ts)
               : $r['reserved_on'] . ' at ' . $r['reserved_at'];
}

/** Label => value pairs shown in the body of every restaurant email. */
function _restaurant_rows(array $r): array {
    $rows = [
        'Reference' => $r['reference'],
        'When'      => _restaurant_when($r),
        'Party'     => $r['party_size'] . ' ' . ((int)$r['party_size'] === 1 ? 'guest' : 'guests'),
    ];
    if (!empty($r['occasion'])) $rows['Occasion'] = ucfirst((string)$r['occasion']);
    if (!empty($r['notes']))    $rows['Notes']    = (string)$r['notes'];
    return $rows;
}

/** Branded HTML shell for restaurant mail, matching the rest of includes/mail.php. */
function _restaurant_html(string $heading, string $intro, array $rows, string $footnote): string {
    $esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $cells = '';
    foreach ($rows as $label => $value) {
        $cells .= '<tr>'
                . '<td style="padding:6px 14px 6px 0;color:#8C7A60;font-size:13px;white-space:nowrap">' . $esc((string)$label) . '</td>'
                . '<td style="padding:6px 0;color:#141412;font-size:15px">' . $esc((string)$value) . '</td>'
                . '</tr>';
    }

    return '<div style="font-family:Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;background:#FAF8F4">'
         . '<div style="font-size:12px;letter-spacing:.28em;text-transform:uppercase;color:#B8965A;margin-bottom:10px">Zuri &middot; Watamu</div>'
         . '<h1 style="font-family:Georgia,serif;font-weight:400;font-size:26px;color:#102F3A;margin:0 0 14px">' . $esc($heading) . '</h1>'
         . '<p style="font-size:15px;line-height:1.65;color:#5a4a38;margin:0 0 22px">' . $esc($intro) . '</p>'
         . '<table style="border-collapse:collapse;margin:0 0 22px">' . $cells . '</table>'
         . '<p style="font-size:13px;line-height:1.7;color:#8C7A60;margin:0">' . $esc($footnote) . '</p>'
         . '</div>';
}
```

- [ ] **Step 2: Add the request email (guest ack + staff alert)**

Append to `includes/mail.php`:

```php
/**
 * Fired when a guest submits a booking request: acknowledgement to the guest,
 * alert to the restaurant inbox. The guest copy is deliberately explicit that
 * this is NOT yet a confirmed table.
 */
function send_restaurant_request(array $r): void {
    require_once __DIR__ . '/restaurant.php';

    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $site = rtrim($env['APP_URL'] ?? $env['SITE_URL'] ?? '', '/');
    $when = _restaurant_when($r);
    $rows = _restaurant_rows($r);

    // ── Guest acknowledgement ──
    $gSubject = "We've received your table request — {$r['venue_name']} — {$r['reference']}";
    $gIntro   = 'Thank you — we have your request and will confirm within 24 hours. '
              . 'This is not yet a confirmed table; you will get a second email once it is.';
    $gText    = "Dear {$r['guest_name']},\n\n{$gIntro}\n\n"
              . "Reference: {$r['reference']}\nWhen:      {$when}\n"
              . "Party:     {$r['party_size']}\n\n"
              . "Warm regards,\n{$r['venue_name']} — Tribal Sand\nreservations@tribalsand.com";
    $gHtml    = _restaurant_html(
        'Your table request',
        $gIntro,
        $rows,
        'Need to change something? Reply to this email and quote your reference.'
    );
    _dispatch_mail($r['guest_email'], $gSubject, $gText, $from, $from, $env, $gHtml);

    // ── Staff alert ──
    $inbox    = restaurant_inbox($r['venue_slug']);
    $link     = $site . '/admin/restaurant.php';
    $sSubject = "New table request — {$when} — {$r['party_size']} guests — {$r['reference']}";
    $sRows    = $rows + [
        'Guest' => $r['guest_name'],
        'Email' => $r['guest_email'],
        'Phone' => $r['guest_phone'] !== '' ? $r['guest_phone'] : '—',
    ];
    $sText    = "New table request at {$r['venue_name']}.\n\n"
              . "Reference: {$r['reference']}\nWhen:      {$when}\n"
              . "Party:     {$r['party_size']}\nGuest:     {$r['guest_name']}\n"
              . "Email:     {$r['guest_email']}\nPhone:     " . ($r['guest_phone'] ?: '—') . "\n\n"
              . "Confirm or decline: {$link}";
    $sHtml    = _restaurant_html(
        'New table request',
        "A guest has requested a table at {$r['venue_name']}. Confirm or decline it in the admin.",
        $sRows,
        $link
    );
    _dispatch_mail($inbox, $sSubject, $sText, $from, $r['guest_email'], $env, $sHtml);
}

/** Fired when a manager confirms. The table is now real. */
function send_restaurant_confirmed(array $r): void {
    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'noreply@tribalsand.com';
    $when = _restaurant_when($r);

    $subject = "Your table is confirmed — {$r['venue_name']} — {$when}";
    $intro   = 'Your table is confirmed. We look forward to welcoming you.';
    $text    = "Dear {$r['guest_name']},\n\n{$intro}\n\n"
             . "Reference: {$r['reference']}\nWhen:      {$when}\n"
             . "Party:     {$r['party_size']}\n\n"
             . "To change or cancel, reply to this email or call us and quote your reference.\n\n"
             . "Warm regards,\n{$r['venue_name']} — Tribal Sand\nreservations@tribalsand.com";
    $html    = _restaurant_html(
        'Your table is confirmed',
        $intro,
        _restaurant_rows($r),
        'To change or cancel, reply to this email or call us and quote your reference.'
    );

    _dispatch_mail($r['guest_email'], $subject, $text, $from, $from, $env, $html);
}
```

- [ ] **Step 3: Verify both functions parse and are callable**

Run:
```bash
php -l includes/mail.php && php -r 'require getcwd()."/includes/mail.php"; var_dump(function_exists("send_restaurant_request"), function_exists("send_restaurant_confirmed"));'
```
Expected: `No syntax errors detected`, then `bool(true)` twice.

- [ ] **Step 4: Verify the shared formatter renders**

Run:
```bash
php -r 'require getcwd()."/includes/mail.php"; echo _restaurant_when(["reserved_on"=>"2026-08-20","reserved_at"=>"18:30"]), "\n";'
```
Expected: `Thursday 20 August 2026 at 18:30`

- [ ] **Step 5: Commit**

```bash
git add includes/mail.php
git commit -m "feat(restaurant): guest ack, staff alert and confirmation emails"
```

---

## Task 6: Public booking endpoint

**Files:**
- Create: `api/restaurant-book.php`

Modelled directly on `api/search-lead.php`. Differences: Turnstile is enforced (this creates an operational commitment, not just a lead), and the slot set is re-derived server-side.

- [ ] **Step 1: Write the endpoint**

Create `api/restaurant-book.php`:

```php
<?php
/**
 * Zuri restaurant — public table-request endpoint.
 *
 * Records the request as `pending` and notifies guest + staff. A human always
 * confirms; nothing here books a table. Capacity is deliberately NOT modelled.
 *
 * See docs/superpowers/specs/2026-08-19-restaurant-reservations-design.md
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}
if (!restaurant_supported()) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => 'Bookings are not available right now.']));
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot — accept silently so bots believe they succeeded.
if (!empty($data['website'])) {
    exit(json_encode(['ok' => true, 'reference' => 'ZR-00000']));
}

// Turnstile. verify_captcha() is fail-closed: site key set + secret missing = false.
if (!verify_captcha($data['cf-turnstile-response'] ?? '')) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'error' => 'Please complete the anti-spam check and try again.']));
}

$ip = client_ip();

// Rate limit: 5 requests per IP per hour.
$since = date('Y-m-d H:i:s', time() - 3600);
$count = (int) db_query(
    'SELECT COUNT(*) AS cnt FROM restaurant_reservations WHERE ip_address = :ip AND created_at > :since',
    [':ip' => $ip, ':since' => $since]
)->fetch()['cnt'];
if ($count >= 5) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a few minutes.']));
}

$venueSlug = 'zuri';
$venue     = restaurant_venue($venueSlug);
if (!$venue) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Restaurant unavailable.']));
}

// Validate against the venue's real hours — the client-side slot list is only a
// convenience, never the authority.
$cfg    = restaurant_hours($venueSlug);
$errors = restaurant_validate($data, $cfg, date('Y-m-d'));
if ($errors) {
    http_response_code(422);
    exit(json_encode(['ok' => false, 'errors' => $errors]));
}

$name     = trim((string)$data['name']);
$email    = trim((string)$data['email']);
$phone    = trim((string)($data['phone'] ?? ''));
$party    = (int)$data['party_size'];
$date     = trim((string)$data['date']);
$time     = trim((string)$data['time']);
$occasion = trim((string)($data['occasion'] ?? ''));
$notes    = trim((string)($data['notes'] ?? ''));

// Double-submit guard: the same guest asking for the same slot inside 5 minutes
// gets the existing reference back instead of a duplicate row.
$dupe = db_query(
    "SELECT reference FROM restaurant_reservations
      WHERE venue_id = :vid AND guest_email = :email
        AND reserved_on = :d AND reserved_at = :t
        AND created_at > NOW() - INTERVAL '5 minutes'
      LIMIT 1",
    [':vid' => (int)$venue['id'], ':email' => $email, ':d' => $date, ':t' => $time]
)->fetch();
if ($dupe) {
    exit(json_encode(['ok' => true, 'reference' => $dupe['reference'], 'duplicate' => true]));
}

if (session_status() === PHP_SESSION_NONE) session_start();
$tracking = $_SESSION['tracking'] ?? [];

// Insert, retrying on the (vanishingly rare) reference collision.
$reference = '';
$id        = 0;
for ($attempt = 0; $attempt < 5; $attempt++) {
    $reference = restaurant_make_reference();
    try {
        db_query(
            "INSERT INTO restaurant_reservations
                (reference, venue_id, status, guest_name, guest_email, guest_phone,
                 party_size, reserved_on, reserved_at, occasion, notes,
                 source_page, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                 user_agent, ip_address)
             VALUES
                (:ref, :vid, 'pending', :name, :email, :phone,
                 :party, :d, :t, :occasion, :notes,
                 :source_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                 :user_agent, :ip)",
            [
                ':ref'         => $reference,
                ':vid'         => (int)$venue['id'],
                ':name'        => $name,
                ':email'       => $email,
                ':phone'       => $phone,
                ':party'       => $party,
                ':d'           => $date,
                ':t'           => $time,
                ':occasion'    => $occasion !== '' ? $occasion : null,
                ':notes'       => $notes !== '' ? $notes : null,
                ':source_page' => $tracking['source_page'] ?? '',
                ':referrer'    => $tracking['referrer']    ?? '',
                ':utm_source'  => $tracking['utm_source']  ?? '',
                ':utm_medium'  => $tracking['utm_medium']  ?? '',
                ':utm_campaign'=> $tracking['utm_campaign']?? '',
                ':utm_term'    => $tracking['utm_term']    ?? '',
                ':utm_content' => $tracking['utm_content'] ?? '',
                ':user_agent'  => $tracking['user_agent']  ?? '',
                ':ip'          => $ip,
            ]
        );
        $id = (int) db()->lastInsertId();
        break;
    } catch (PDOException $e) {
        // 23505 = unique_violation. Only a reference clash is retryable.
        if ($e->getCode() !== '23505' || $attempt === 4) throw $e;
    }
}

audit_log('restaurant_request', 'restaurant_reservation', $id, $reference);

// Email is best-effort. The booking is already committed — a dead Resend key
// must cost a notification, never a reservation.
try {
    send_restaurant_request([
        'reference'   => $reference,
        'guest_name'  => $name,
        'guest_email' => $email,
        'guest_phone' => $phone,
        'party_size'  => $party,
        'reserved_on' => $date,
        'reserved_at' => $time,
        'occasion'    => $occasion,
        'notes'       => $notes,
        'venue_name'  => $venue['name'],
        'venue_slug'  => $venue['slug'],
    ]);
} catch (Throwable $e) {
    error_log('[restaurant-book] mail: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'id' => $id, 'reference' => $reference]);
```

- [ ] **Step 2: Verify it parses**

Run: `php -l api/restaurant-book.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Start the dev server and post a valid request**

Start the server through the preview tooling (never `php -S` in Bash), then run — replacing the date with a real near-future date:

```bash
curl -s -X POST http://localhost:8765/api/restaurant-book.php -H 'Content-Type: application/json' -d '{"name":"Plan Test","email":"plan.test@example.com","phone":"0700000000","party_size":2,"date":"2026-08-25","time":"19:00","occasion":"romantic","notes":"Window table"}'
```
Expected: `{"ok":true,"id":<n>,"reference":"ZR-XXXXX"}`

- [ ] **Step 4: Verify the guards actually bite**

```bash
# Off-grid time → 422 with a time error
curl -s -X POST http://localhost:8765/api/restaurant-book.php -H 'Content-Type: application/json' -d '{"name":"T","email":"t@example.com","party_size":2,"date":"2026-08-25","time":"18:36"}'
# Past date → 422 with a date error
curl -s -X POST http://localhost:8765/api/restaurant-book.php -H 'Content-Type: application/json' -d '{"name":"T","email":"t@example.com","party_size":2,"date":"2020-01-01","time":"19:00"}'
# Double submit → same reference, duplicate:true
curl -s -X POST http://localhost:8765/api/restaurant-book.php -H 'Content-Type: application/json' -d '{"name":"Plan Test","email":"plan.test@example.com","party_size":2,"date":"2026-08-25","time":"19:00"}'
```
Expected: two `422` payloads with `errors.time` and `errors.date`, then an `ok:true` carrying `"duplicate":true` and the **same** reference as Step 3.

- [ ] **Step 5: Clean up the test rows**

```bash
php -r 'require getcwd()."/includes/db.php"; echo db_query("DELETE FROM restaurant_reservations WHERE guest_email IN (\047plan.test@example.com\047,\047t@example.com\047)")->rowCount(), " deleted\n";'
```

- [ ] **Step 6: Commit**

```bash
git add api/restaurant-book.php
git commit -m "feat(restaurant): public table-request endpoint"
```

---

## Task 7: Booking form widget and public page

**Files:**
- Create: `includes/form-restaurant.php`
- Create: `zuri-restaurant.php`

**Deviation from the spec, deliberate:** the spec says success uses the existing `showSuccessModal()`. That function is defined in `includes/footer.php`, which this standalone page does not include, so the widget renders its own inline success panel instead. If piece C adopts the site-wide header/footer, switch the success branch to `showSuccessModal()` then — the endpoint and the rest of the widget are unaffected.

The widget follows `includes/form-enquiry.php`: a self-contained include that renders markup plus its own JS. Date selection uses the shared picker in **single-date mode** — its documented contract is a `.dp-btn` with `data-dp-target` pointing at a hidden input, and it fires a `change` event on that input when a date is chosen. It blocks past dates itself; it has **no max-date support**, so the 120-day horizon is enforced server-side only (Task 6 already does this, and the error surfaces on the form).

- [ ] **Step 1: Create the widget**

Create `includes/form-restaurant.php`:

```php
<?php
/**
 * Zuri restaurant — booking form widget.
 *
 * Self-contained: markup + its own JS, mirroring includes/form-enquiry.php.
 * Slot chips are generated client-side from the venue's hours config purely for
 * convenience — api/restaurant-book.php re-derives and re-validates them.
 *
 * Requires: css/datepicker.css, js/datepicker.js (single-date mode).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/restaurant.php';
require_once __DIR__ . '/turnstile.php';

$__rSlug  = 'zuri';
$__rHours = restaurant_hours($__rSlug);
?>
<form class="rbook" id="rbookForm" novalidate>
  <input type="text" name="website" class="rbook__hp" tabindex="-1" autocomplete="off" aria-hidden="true">

  <div class="rbook__row">
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookDateBtn">Date</label>
      <button type="button" class="dp-btn rbook__input" id="rbookDateBtn" data-dp-target="rbookDate">Choose a date</button>
      <input type="hidden" id="rbookDate" name="date">
    </div>
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookParty">Guests</label>
      <select class="rbook__input" id="rbookParty" name="party_size">
        <?php for ($g = RESTAURANT_PARTY_MIN; $g <= RESTAURANT_PARTY_MAX; $g++): ?>
        <option value="<?= $g ?>"<?= $g === 2 ? ' selected' : '' ?>><?= $g ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <div class="rbook__field">
    <span class="rbook__lbl">Time</span>
    <div class="rbook__slots" id="rbookSlots" role="radiogroup" aria-label="Available times">
      <p class="rbook__hint">Choose a date to see available times.</p>
    </div>
    <input type="hidden" id="rbookTime" name="time">
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookOccasion">Occasion <em>(optional)</em></label>
    <select class="rbook__input" id="rbookOccasion" name="occasion">
      <option value="">Just dinner</option>
      <?php foreach (restaurant_occasions() as $o): ?>
      <option value="<?= e($o) ?>"><?= e(ucfirst($o)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="rbook__row">
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookName">Name</label>
      <input class="rbook__input" type="text" id="rbookName" name="name" autocomplete="name" required>
    </div>
    <div class="rbook__field">
      <label class="rbook__lbl" for="rbookEmail">Email</label>
      <input class="rbook__input" type="email" id="rbookEmail" name="email" autocomplete="email" inputmode="email" required>
    </div>
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookPhone">Phone <em>(optional)</em></label>
    <input class="rbook__input" type="tel" id="rbookPhone" name="phone" autocomplete="tel" inputmode="tel">
  </div>

  <div class="rbook__field">
    <label class="rbook__lbl" for="rbookNotes">Anything we should know? <em>(optional)</em></label>
    <textarea class="rbook__input" id="rbookNotes" name="notes" rows="3" placeholder="Allergies, dietary needs, a quiet table…"></textarea>
  </div>

  <?php if (captcha_site_key()): ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
  <?php endif; ?>

  <p class="rbook__err" id="rbookErr" hidden></p>
  <button type="submit" class="rbook__submit">Request a Table</button>
  <p class="rbook__note">We confirm every table by email within 24 hours. No payment now.</p>
</form>

<style>
.rbook{max-width:560px;margin:0 auto;text-align:left}
.rbook__hp{position:absolute!important;left:-9999px;width:1px;height:1px;opacity:0}
.rbook__row{display:flex;gap:1rem}
.rbook__row .rbook__field{flex:1;min-width:0}
.rbook__field{margin-bottom:1rem}
.rbook__lbl{display:block;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--sand,#B8965A);font-weight:600;margin-bottom:.35rem}
.rbook__lbl em{font-style:normal;letter-spacing:0;text-transform:none;color:var(--light,#8C7A60);font-weight:400}
.rbook__input{width:100%;box-sizing:border-box;padding:.7rem .85rem;border:1px solid var(--border,rgba(184,150,90,.28));border-radius:4px;background:#fff;font-family:inherit;font-size:16px;color:var(--dark,#141412);text-align:left}
.rbook__input:focus{outline:none;border-color:var(--teal,#1E5C6B);box-shadow:0 0 0 3px rgba(30,92,107,.12)}
.rbook__slots{display:flex;flex-wrap:wrap;gap:.4rem}
.rbook__hint{font-size:.85rem;color:var(--light,#8C7A60);margin:0}
.rbook__slot{padding:.5rem .9rem;border:1px solid var(--border,rgba(184,150,90,.28));border-radius:4px;background:#fff;font-family:inherit;font-size:.9rem;color:var(--dark,#141412);cursor:pointer}
.rbook__slot.is-on{background:var(--teal-d,#102F3A);border-color:var(--teal-d,#102F3A);color:#fff}
.rbook__err{background:#fbe6e6;border:1px solid #f0c2c2;color:#a12;border-radius:4px;padding:.6rem .8rem;font-size:.85rem;margin:0 0 .8rem}
.rbook__err[hidden]{display:none}
.rbook__submit{width:100%;background:var(--teal-d,#102F3A);color:#fff;border:0;border-radius:4px;padding:.95rem 1.2rem;font-family:inherit;font-size:.82rem;letter-spacing:.08em;text-transform:uppercase;font-weight:600;cursor:pointer;transition:background .2s}
.rbook__submit:hover{background:var(--teal,#1E5C6B)}
.rbook__submit:disabled{opacity:.6;cursor:default}
.rbook__note{text-align:center;font-size:.75rem;color:var(--light,#8C7A60);margin:.8rem 0 0}
@media(max-width:560px){.rbook__row{flex-wrap:wrap;gap:0}.rbook__row .rbook__field{flex:1 1 100%}}
</style>

<script>
(function () {
  var HOURS = <?= json_encode($__rHours, JSON_UNESCAPED_SLASHES) ?>;
  var form  = document.getElementById('rbookForm');
  if (!form) return;
  var dateIn = document.getElementById('rbookDate');
  var timeIn = document.getElementById('rbookTime');
  var slots  = document.getElementById('rbookSlots');
  var err    = document.getElementById('rbookErr');

  // Mirrors restaurant_slots_for() in includes/restaurant.php: `from` inclusive,
  // `to` exclusive. Convenience only — the server re-derives this set.
  function slotsFor(ymd) {
    var d = new Date(ymd + 'T00:00:00');
    if (isNaN(d) || HOURS.days.indexOf(d.getDay()) === -1) return [];
    var f = HOURS.from.split(':'), t = HOURS.to.split(':');
    var start = (+f[0]) * 60 + (+f[1]), end = (+t[0]) * 60 + (+t[1]), out = [];
    for (var m = start; m < end; m += HOURS.step) {
      out.push(('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2));
    }
    return out;
  }

  function renderSlots() {
    timeIn.value = '';
    var list = dateIn.value ? slotsFor(dateIn.value) : [];
    if (!dateIn.value)  { slots.innerHTML = '<p class="rbook__hint">Choose a date to see available times.</p>'; return; }
    if (!list.length)   { slots.innerHTML = '<p class="rbook__hint">We are closed that day — please choose another date.</p>'; return; }
    slots.innerHTML = '';
    list.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'rbook__slot'; b.textContent = t;
      b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false');
      b.addEventListener('click', function () {
        slots.querySelectorAll('.rbook__slot').forEach(function (o) {
          o.classList.remove('is-on'); o.setAttribute('aria-checked', 'false');
        });
        b.classList.add('is-on'); b.setAttribute('aria-checked', 'true');
        timeIn.value = t;
      });
      slots.appendChild(b);
    });
  }

  // datepicker.js fires `change` on the hidden input when a date is picked.
  dateIn.addEventListener('change', renderSlots);

  function showErr(m) { err.textContent = m; err.hidden = false; }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true;
    var btn = form.querySelector('.rbook__submit');
    if (!dateIn.value) { showErr('Please choose a date.'); return; }
    if (!timeIn.value) { showErr('Please choose a time.'); return; }

    var tokenEl = form.querySelector('[name="cf-turnstile-response"]');
    var body = {
      name:       form.name.value.trim(),
      email:      form.email.value.trim(),
      phone:      form.phone.value.trim(),
      party_size: parseInt(form.party_size.value, 10),
      date:       dateIn.value,
      time:       timeIn.value,
      occasion:   form.occasion.value,
      notes:      form.notes.value.trim(),
      website:    form.website.value,
      'cf-turnstile-response': tokenEl ? tokenEl.value : ''
    };
    if (!body.name || !body.email) { showErr('Please enter your name and a valid email.'); return; }

    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('/api/restaurant-book.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin', body: JSON.stringify(body)
    })
    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
    .then(function (j) {
      if (j && j.ok) {
        form.innerHTML = '<div style="text-align:center;padding:1.5rem 0">'
          + '<p style="font-family:Cormorant Garamond,serif;font-size:1.6rem;color:#102F3A;margin:0 0 .5rem">Thank you</p>'
          + '<p style="font-size:.95rem;color:#5a4a38;line-height:1.7;margin:0">We have your request and will confirm by email within 24 hours.<br>Your reference is <strong>' + (j.reference || '') + '</strong>.</p>'
          + '</div>';
        return;
      }
      var msg = (j && j.errors) ? Object.keys(j.errors).map(function (k) { return j.errors[k]; })[0]
              : (j && j.error) || 'Something went wrong. Please try again.';
      showErr(msg);
      btn.disabled = false; btn.textContent = 'Request a Table';
      if (window.turnstile) window.turnstile.reset();
    })
    .catch(function () {
      showErr('Network error. Please try again.');
      btn.disabled = false; btn.textContent = 'Request a Table';
    });
  });
})();
</script>
```

- [ ] **Step 2: Create the minimal public page**

Create `zuri-restaurant.php`. Piece C rewrites this page's copy and imagery later; the widget include and the endpoint stay untouched.

```php
<?php
/**
 * Zuri Restaurant — booking page (piece B, minimal).
 *
 * Deliberately plain: the point of piece B is that Zuri is genuinely bookable.
 * Piece C replaces the copy, layout and imagery around the same widget.
 * Served at /zuri-restaurant by the strip-.php rule in .htaccess.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/turnstile.php';   // captcha_site_key() is used below
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Book a Table · Zuri Restaurant · Watamu · Tribal Sand</title>
<meta name="description" content="Book a table at Zuri Restaurant in Watamu — Mediterranean, Italian and Kenyan coastal cooking. Perfect for romantic dinners and special occasions.">
<link rel="canonical" href="https://tribalsand.com/zuri-restaurant">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/datepicker.css?v=<?= filemtime(__DIR__ . '/css/datepicker.css') ?>">
<script src="/js/datepicker.js?v=<?= filemtime(__DIR__ . '/js/datepicker.js') ?>" defer></script>
<style>
:root{--sand:#B8965A;--teal:#1E5C6B;--teal-d:#102F3A;--dark:#141412;--off:#FAF8F4;--cream:#F5EFE3;--light:#8C7A60;--border:rgba(184,150,90,.22)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Jost',sans-serif;background:var(--off);color:var(--dark);-webkit-font-smoothing:antialiased}
.zr-head{background:var(--cream);text-align:center;padding:3rem 1.5rem 2.4rem;border-bottom:1px solid var(--border)}
.zr-logo{font-family:'Cormorant Garamond',serif;font-size:2.6rem;font-weight:300;color:var(--teal-d);line-height:1}
.zr-sub{font-size:.68rem;letter-spacing:.35em;text-transform:uppercase;color:var(--sand);margin:.4rem 0 1rem}
.zr-tag{font-family:'Cormorant Garamond',serif;font-style:italic;font-size:1.1rem;color:var(--light);line-height:1.7;max-width:420px;margin:0 auto}
.zr-links{margin-top:1.2rem;font-size:.8rem}
.zr-links a{color:var(--teal);text-decoration:underline}
.zr-main{max-width:640px;margin:0 auto;padding:2.4rem 1.5rem 3.5rem}
.zr-h2{font-family:'Cormorant Garamond',serif;font-weight:300;font-size:1.9rem;color:var(--teal-d);text-align:center;margin-bottom:.4rem}
.zr-lead{text-align:center;font-size:.92rem;color:var(--light);line-height:1.7;margin-bottom:2rem}
.zr-foot{background:var(--teal-d);color:rgba(255,255,255,.55);text-align:center;padding:2rem 1.5rem;font-size:.78rem}
.zr-foot a{color:rgba(184,150,90,.75)}
</style>
</head>
<body>

<header class="zr-head">
  <div class="zr-logo">Zuri</div>
  <div class="zr-sub">Restaurant &middot; Watamu</div>
  <p class="zr-tag">Mediterranean simplicity, Italian craftsmanship, Indian traditions and the richness of the Kenyan coast.</p>
  <p class="zr-links"><a href="/zuri-menu">View the menu &rarr;</a></p>
</header>

<main class="zr-main">
  <h1 class="zr-h2">Book a table</h1>
  <p class="zr-lead">Open to house guests and outside visitors alike — and well suited to anniversaries, birthdays and quiet romantic dinners by the coast.</p>
  <?php include __DIR__ . '/includes/form-restaurant.php'; ?>
</main>

<footer class="zr-foot">
  Zuri is part of the Tribal Sand collection.<br>
  <a href="https://tribalsand.com">tribalsand.com</a> &middot; reservations@tribalsand.com
</footer>

<?php if (captcha_site_key()): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 3: Verify the page parses and renders**

Run: `php -l zuri-restaurant.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verify the page renders and the URL works**

Start the dev server via the preview tooling, then:
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8765/zuri-restaurant
curl -s http://localhost:8765/zuri-restaurant | grep -c "rbookForm"
```
Expected: `200`, then `1`

- [ ] **Step 5: Verify the live menu URL is untouched**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8765/zuri-menu
```
Expected: `200` — `zuri-menu.php` must remain byte-identical to what it was before this work.

- [ ] **Step 6: Commit**

```bash
git add includes/form-restaurant.php zuri-restaurant.php
git commit -m "feat(restaurant): booking form widget + minimal public booking page"
```

---

## Task 8: Admin reservations page

**Files:**
- Create: `admin/restaurant.php`

Uses the existing `.kpi-grid`/`.kpi-card` markup from `admin/dashboard.php` for the counters, and `.card`/`.data-table`/`.badge--*` for the lists. Venue scoping follows the codebase convention: `admin_venue_ids()` returns `null` for an owner (all venues) and an array otherwise — and an **empty** array means the account is scoped to nothing and must see nothing.

- [ ] **Step 1: Create the page**

Create `admin/restaurant.php`:

```php
<?php
/**
 * Admin: Restaurant reservations.
 *
 * Request-only bookings — every row arrives as `pending` and a human confirms.
 * Capacity is deliberately not modelled. See the design spec.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_login();
require_frontdesk();

$pageTitle  = 'Reservations';
$activeMenu = 'restaurant';

if (!restaurant_supported()) {
    require __DIR__ . '/_layout.php';
    echo '<div class="alert alert--info">The reservations table has not been created yet. '
       . 'Run <strong>add_restaurant_reservations.sql</strong> from <a href="/admin/migrate.php">Migrations</a>.</div>';
    require __DIR__ . '/_layout_end.php';
    exit;
}

$flash = $_SESSION['restaurant_flash'] ?? null;
unset($_SESSION['restaurant_flash']);

// ── Venue scope ──
// null = owner (all venues). [] = scoped to nothing → show nothing.
$venueIds = admin_venue_ids();
$scopeSql = '';
$scopeArgs = [];
if ($venueIds !== null) {
    if (!$venueIds) {
        $scopeSql = ' AND FALSE';
    } else {
        $in = [];
        foreach (array_values($venueIds) as $i => $vid) {
            $in[] = ':v' . $i;
            $scopeArgs[':v' . $i] = (int)$vid;
        }
        $scopeSql = ' AND r.venue_id IN (' . implode(',', $in) . ')';
    }
}

// ── Filters ──
$fStatus = trim((string)($_GET['status'] ?? ''));
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$filterSql  = '';
$filterArgs = [];
if (in_array($fStatus, ['pending', 'confirmed', 'declined', 'cancelled'], true)) {
    $filterSql .= ' AND r.status = :status';
    $filterArgs[':status'] = $fStatus;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $filterSql .= ' AND r.reserved_on >= :from'; $filterArgs[':from'] = $fFrom; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   { $filterSql .= ' AND r.reserved_on <= :to';   $filterArgs[':to']   = $fTo; }

$SELECT = 'SELECT r.*, v.name AS venue_name FROM restaurant_reservations r
           JOIN venues v ON v.id = r.venue_id WHERE TRUE';

// ── Counters ──
$countToday = (int) db_query(
    "SELECT COUNT(*) AS cnt FROM restaurant_reservations r
      WHERE r.reserved_on = CURRENT_DATE AND r.status = 'confirmed'" . $scopeSql,
    $scopeArgs
)->fetch()['cnt'];

$countPending = (int) db_query(
    "SELECT COUNT(*) AS cnt FROM restaurant_reservations r
      WHERE r.status = 'pending' AND r.reserved_on >= CURRENT_DATE" . $scopeSql,
    $scopeArgs
)->fetch()['cnt'];

// ── Lists ──
$today = db_query(
    $SELECT . $scopeSql . " AND r.reserved_on = CURRENT_DATE AND r.status IN ('pending','confirmed')
    ORDER BY r.reserved_at",
    $scopeArgs
)->fetchAll();

// Cap the filtered list. If it truncates, say so rather than silently hiding rows.
$LIMIT = 200;
$upcoming = db_query(
    $SELECT . $scopeSql . $filterSql . ' AND r.reserved_on >= CURRENT_DATE
    ORDER BY r.reserved_on, r.reserved_at LIMIT ' . ($LIMIT + 1),
    $scopeArgs + $filterArgs
)->fetchAll();
$truncated = count($upcoming) > $LIMIT;
if ($truncated) array_pop($upcoming);

$fmtTime = static fn(string $t) => substr($t, 0, 5);
$fmtDate = static fn(string $d) => date('D j M', strtotime($d));

require __DIR__ . '/_layout.php';
?>

<?php if ($flash): ?>
<div class="alert is-flash alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-card__label">Reservations Today</div>
    <div class="kpi-card__value"><?= e($countToday) ?></div>
    <div class="kpi-card__sub">confirmed for today</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-card__label">Pending Confirmation</div>
    <div class="kpi-card__value"><?= e($countPending) ?></div>
    <div class="kpi-card__sub">awaiting your decision</div>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Today's Reservations</span></div>
  <div class="card__body">
    <?php if (!$today): ?>
      <p>No reservations today.</p>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Time</th><th>Guest</th><th>Phone</th><th>Party</th><th>Occasion</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($today as $r): ?>
        <tr>
          <td><?= e($fmtTime($r['reserved_at'])) ?></td>
          <td><?= e($r['guest_name']) ?></td>
          <td><?= e($r['guest_phone'] ?: '—') ?></td>
          <td><?= e($r['party_size']) ?></td>
          <td><?= e($r['occasion'] ? ucfirst($r['occasion']) : '—') ?></td>
          <td><span class="badge <?= e(restaurant_status_badge_class($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Upcoming Reservations</span></div>
  <div class="card__body">

    <form method="get" class="filter-bar">
      <div class="filter-field">
        <label for="fStatus">Status</label>
        <select id="fStatus" name="status">
          <option value="">All</option>
          <?php foreach (['pending', 'confirmed', 'declined', 'cancelled'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field"><label for="fFrom">From</label><input type="date" id="fFrom" name="from" value="<?= e($fFrom) ?>"></div>
      <div class="filter-field"><label for="fTo">To</label><input type="date" id="fTo" name="to" value="<?= e($fTo) ?>"></div>
      <button type="submit" class="btn-sm btn-outline">Filter</button>
      <a href="/admin/restaurant.php" class="btn-sm btn-outline">Reset</a>
    </form>

    <?php if ($truncated): ?>
    <div class="alert alert--info">Showing the first <?= (int)$LIMIT ?> matching reservations. Narrow the date range to see more.</div>
    <?php endif; ?>

    <?php if (!$upcoming): ?>
      <p>No upcoming reservations match these filters.</p>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Date</th><th>Time</th><th>Guest</th><th>Phone</th><th>Party</th><th>Ref</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($upcoming as $r): ?>
        <tr>
          <td><?= e($fmtDate($r['reserved_on'])) ?></td>
          <td><?= e($fmtTime($r['reserved_at'])) ?></td>
          <td>
            <?= e($r['guest_name']) ?>
            <?php if ($r['occasion']): ?><br><small><?= e(ucfirst($r['occasion'])) ?></small><?php endif; ?>
            <?php if ($r['notes']): ?><br><small><?= e($r['notes']) ?></small><?php endif; ?>
          </td>
          <td><?= e($r['guest_phone'] ?: '—') ?></td>
          <td><?= e($r['party_size']) ?></td>
          <td><?= e($r['reference']) ?></td>
          <td><span class="badge <?= e(restaurant_status_badge_class($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td>
            <form method="post" action="/admin/restaurant-action.php" style="display:flex;gap:.35rem">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= e($r['id']) ?>">
              <?php if ($r['status'] === 'pending'): ?>
                <button type="submit" name="action" value="confirm" class="btn-sm btn-primary">Confirm</button>
                <button type="submit" name="action" value="decline" class="btn-sm btn-outline" data-confirm="Decline this table request?">Decline</button>
              <?php elseif ($r['status'] === 'confirmed'): ?>
                <button type="submit" name="action" value="cancel" class="btn-sm btn-outline" data-confirm="Cancel this confirmed table?">Cancel</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/restaurant.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify it loads behind auth**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8765/admin/restaurant.php
```
Expected: `302` (redirect to login) — proves `require_login()` is active. Log in through the browser to see the page itself.

- [ ] **Step 4: Commit**

```bash
git add admin/restaurant.php
git commit -m "feat(restaurant): admin reservations page with counters and filters"
```

---

## Task 9: Admin action handler

**Files:**
- Create: `admin/restaurant-action.php`

Every transition is guarded by its expected current status in the `WHERE` clause, so two managers clicking Confirm cannot both win — and only the winner sends the email.

- [ ] **Step 1: Create the handler**

Create `admin/restaurant-action.php`:

```php
<?php
/**
 * Admin: restaurant reservation state changes (confirm / decline / cancel).
 *
 * Each UPDATE is guarded by the expected current status, so a concurrent click
 * cannot double-apply a transition or double-send the confirmation email.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_once __DIR__ . '/../includes/mail.php';
require_login();
require_frontdesk();
verify_csrf();

$back = '/admin/restaurant.php';
$fail = function (string $msg) use ($back): never {
    $_SESSION['restaurant_flash'] = ['type' => 'error', 'msg' => $msg];
    header('Location: ' . $back);
    exit;
};

$id     = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$map    = ['confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled'];
if (!$id || !isset($map[$action])) $fail('Unknown action.');
$to = $map[$action];

$r = db_query(
    'SELECT r.*, v.name AS venue_name, v.slug AS venue_slug
       FROM restaurant_reservations r JOIN venues v ON v.id = r.venue_id
      WHERE r.id = :id',
    [':id' => $id]
)->fetch();
if (!$r) $fail('Reservation not found.');

// Venue scope — a scoped manager must not action another venue's covers.
$venueIds = admin_venue_ids();
if ($venueIds !== null && !in_array((int)$r['venue_id'], array_map('intval', $venueIds), true)) {
    $fail('That reservation belongs to another venue.');
}

if (!restaurant_can_transition($r['status'], $to)) {
    $fail('A ' . $r['status'] . ' reservation cannot be marked ' . $to . '.');
}

// Guarded update: only succeeds if the row is still in the status we read.
$sql = $to === 'confirmed'
    ? 'UPDATE restaurant_reservations
          SET status = :to, confirmed_by = :admin, confirmed_at = NOW(), updated_at = NOW()
        WHERE id = :id AND status = :from'
    : 'UPDATE restaurant_reservations
          SET status = :to, updated_at = NOW()
        WHERE id = :id AND status = :from';

$args = [':to' => $to, ':id' => $id, ':from' => $r['status']];
if ($to === 'confirmed') $args[':admin'] = (int)($_SESSION['admin_id'] ?? 0);

$changed = db_query($sql, $args)->rowCount();
if ($changed === 0) $fail('Someone else just updated that reservation. Refresh and try again.');

audit_log('restaurant_' . $action, 'restaurant_reservation', $id, $r['reference']);

// Only the winning update emails the guest, and only on confirmation.
if ($to === 'confirmed') {
    try {
        send_restaurant_confirmed([
            'reference'   => $r['reference'],
            'guest_name'  => $r['guest_name'],
            'guest_email' => $r['guest_email'],
            'guest_phone' => $r['guest_phone'] ?? '',
            'party_size'  => $r['party_size'],
            'reserved_on' => $r['reserved_on'],
            'reserved_at' => substr($r['reserved_at'], 0, 5),
            'occasion'    => $r['occasion'] ?? '',
            'notes'       => $r['notes'] ?? '',
            'venue_name'  => $r['venue_name'],
            'venue_slug'  => $r['venue_slug'],
        ]);
    } catch (Throwable $e) {
        error_log('[restaurant-action] mail: ' . $e->getMessage());
    }
}

$_SESSION['restaurant_flash'] = ['type' => 'success', 'msg' => 'Reservation ' . $r['reference'] . ' marked ' . $to . '.'];
header('Location: ' . $back);
exit;
```

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/restaurant-action.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify the transition guard rejects an illegal move**

```bash
php -r 'require getcwd()."/includes/restaurant.php";
var_dump(restaurant_can_transition("declined","confirmed"));   // must be false
var_dump(restaurant_can_transition("pending","confirmed"));    // must be true'
```
Expected: `bool(false)` then `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add admin/restaurant-action.php
git commit -m "feat(restaurant): guarded confirm/decline/cancel handler"
```

---

## Task 10: Edit a reservation

**Files:**
- Modify: `admin/restaurant.php`
- Modify: `admin/restaurant-action.php`

The spec requires the manager to edit date, time, party size, occasion, notes and staff notes. Editing is not a status transition, so it is a separate `action=edit` branch and is allowed on `pending` and `confirmed` rows only — a declined or cancelled reservation is history and stays untouched. Editing never emails the guest; the manager is already talking to them.

- [ ] **Step 1: Add the edit row to the upcoming table**

In `admin/restaurant.php`, inside the upcoming-list `<tbody>` loop, replace the closing `</tr>` of each row with this — an extra collapsible row carrying the edit form:

```php
        </tr>
        <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
        <tr class="resv-edit">
          <td colspan="8">
            <details>
              <summary class="btn-sm btn-outline" style="display:inline-block;cursor:pointer">Edit</summary>
              <form method="post" action="/admin/restaurant-action.php" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-top:.6rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                <input type="hidden" name="action" value="edit">
                <div class="filter-field">
                  <label for="ed-date-<?= e($r['id']) ?>">Date</label>
                  <input type="date" id="ed-date-<?= e($r['id']) ?>" name="date" value="<?= e($r['reserved_on']) ?>" required>
                </div>
                <div class="filter-field">
                  <label for="ed-time-<?= e($r['id']) ?>">Time</label>
                  <input type="time" id="ed-time-<?= e($r['id']) ?>" name="time" value="<?= e($fmtTime($r['reserved_at'])) ?>" step="60" required>
                </div>
                <div class="filter-field">
                  <label for="ed-party-<?= e($r['id']) ?>">Party</label>
                  <input type="number" id="ed-party-<?= e($r['id']) ?>" name="party_size" value="<?= e($r['party_size']) ?>"
                         min="<?= RESTAURANT_PARTY_MIN ?>" max="<?= RESTAURANT_PARTY_MAX ?>" required>
                </div>
                <div class="filter-field">
                  <label for="ed-occ-<?= e($r['id']) ?>">Occasion</label>
                  <select id="ed-occ-<?= e($r['id']) ?>" name="occasion">
                    <option value="">—</option>
                    <?php foreach (restaurant_occasions() as $o): ?>
                    <option value="<?= e($o) ?>"<?= $r['occasion'] === $o ? ' selected' : '' ?>><?= e(ucfirst($o)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="filter-field" style="flex:1 1 220px">
                  <label for="ed-notes-<?= e($r['id']) ?>">Guest notes</label>
                  <input type="text" id="ed-notes-<?= e($r['id']) ?>" name="notes" value="<?= e($r['notes'] ?? '') ?>">
                </div>
                <div class="filter-field" style="flex:1 1 220px">
                  <label for="ed-staff-<?= e($r['id']) ?>">Internal notes</label>
                  <input type="text" id="ed-staff-<?= e($r['id']) ?>" name="staff_notes" value="<?= e($r['staff_notes'] ?? '') ?>"
                         placeholder="Not shown to the guest">
                </div>
                <button type="submit" class="btn-sm btn-primary">Save</button>
              </form>
            </details>
          </td>
        </tr>
        <?php endif; ?>
```

Note the admin page reads `reserved_at` as `HH:MM:SS` from Postgres; `$fmtTime()` (already defined in Task 8) trims it to `HH:MM` for the `<input type="time">`.

- [ ] **Step 2: Add the edit branch to the handler**

In `admin/restaurant-action.php`, replace the two lines:

```php
$map    = ['confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled'];
if (!$id || !isset($map[$action])) $fail('Unknown action.');
$to = $map[$action];
```

with:

```php
$map = ['confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled'];
if (!$id || ($action !== 'edit' && !isset($map[$action]))) $fail('Unknown action.');
$to = $action === 'edit' ? null : $map[$action];
```

Then insert this block immediately **after** the venue-scope check and **before** the `restaurant_can_transition()` check:

```php
// ── Edit: not a status transition. Allowed while the booking is still live. ──
if ($action === 'edit') {
    if (!in_array($r['status'], ['pending', 'confirmed'], true)) {
        $fail('A ' . $r['status'] . ' reservation can no longer be edited.');
    }

    $in = [
        'name'       => $r['guest_name'],    // unchanged, but restaurant_validate() requires them
        'email'      => $r['guest_email'],
        'party_size' => (int)($_POST['party_size'] ?? 0),
        'date'       => trim((string)($_POST['date'] ?? '')),
        'time'       => trim((string)($_POST['time'] ?? '')),
        'occasion'   => trim((string)($_POST['occasion'] ?? '')),
    ];

    // Staff may move a booking to any time the venue is open — but the date and
    // party bounds are the same rules the public form obeys.
    $errors = restaurant_validate($in, restaurant_hours($r['venue_slug']), date('Y-m-d'));
    unset($errors['time']);   // an off-grid time is a deliberate staff override
    if ($errors) $fail(reset($errors));

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) $fail('Please enter a valid time.');

    db_query(
        'UPDATE restaurant_reservations
            SET reserved_on = :d, reserved_at = :t, party_size = :party,
                occasion = :occasion, notes = :notes, staff_notes = :staff, updated_at = NOW()
          WHERE id = :id',
        [
            ':d'        => $in['date'],
            ':t'        => $in['time'],
            ':party'    => $in['party_size'],
            ':occasion' => $in['occasion'] !== '' ? $in['occasion'] : null,
            ':notes'    => trim((string)($_POST['notes'] ?? '')) ?: null,
            ':staff'    => trim((string)($_POST['staff_notes'] ?? '')) ?: null,
            ':id'       => $id,
        ]
    );

    audit_log('restaurant_edit', 'restaurant_reservation', $id, $r['reference']);
    $_SESSION['restaurant_flash'] = ['type' => 'success', 'msg' => 'Reservation ' . $r['reference'] . ' updated.'];
    header('Location: ' . $back);
    exit;
}
```

- [ ] **Step 3: Verify both files parse**

Run: `php -l admin/restaurant.php && php -l admin/restaurant-action.php`
Expected: `No syntax errors detected` twice

- [ ] **Step 4: Verify an edit on a terminal row is refused**

Create a declined row, try to edit it, and confirm it is rejected:

```bash
php -r '$b=getcwd(); require $b."/includes/restaurant.php";
db_query("INSERT INTO restaurant_reservations (reference,venue_id,status,guest_name,guest_email,party_size,reserved_on,reserved_at) VALUES (:r,(SELECT id FROM venues WHERE slug=\047zuri\047),\047declined\047,\047Edit Guard\047,\047guard@example.com\047,2,CURRENT_DATE+1,\04719:00\047)", [":r"=>restaurant_make_reference()]);
echo "seeded\n";'
```
Then in the browser, confirm no Edit control renders on that declined row (Step 1 only emits it for `pending`/`confirmed`).

- [ ] **Step 5: Clean up**

```bash
php -r 'require getcwd()."/includes/db.php"; echo db_query("DELETE FROM restaurant_reservations WHERE guest_email=\047guard@example.com\047")->rowCount(), " deleted\n";'
```

- [ ] **Step 6: Commit**

```bash
git add admin/restaurant.php admin/restaurant-action.php
git commit -m "feat(restaurant): edit a live reservation from the admin"
```

---

## Task 11: Service-hours editor

**Files:**
- Create: `admin/restaurant-hours.php`

The spec puts this behind `require_manager()` — owner or manager, deliberately **not** `require_owner()` like the other settings screens, because changing dinner hours is daily ops rather than pricing config.

- [ ] **Step 1: Create the page**

Create `admin/restaurant-hours.php`:

```php
<?php
/**
 * Admin: restaurant service hours.
 *
 * Gated at require_manager() — owner or manager. This is a deliberate departure
 * from the other settings screens (which use require_owner()): changing dinner
 * hours is daily ops, not pricing configuration. See the design spec.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_login();
require_manager();

$pageTitle  = 'Service Hours';
$activeMenu = 'restaurant_hours';

$slug  = 'zuri';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $days = [];
    foreach (($_POST['days'] ?? []) as $d) {
        if (is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) $days[] = (int)$d;
    }

    $cfg = restaurant_normalise_hours([
        'days' => $days,
        'from' => (string)($_POST['from'] ?? ''),
        'to'   => (string)($_POST['to'] ?? ''),
        'step' => (int)($_POST['step'] ?? 30),
    ]);

    // Reject an inverted window outright — normalise_hours can't catch it, and it
    // would silently produce zero bookable slots.
    if ($cfg['from'] >= $cfg['to']) {
        $flash = ['type' => 'error', 'msg' => 'Closing time must be later than opening time.'];
    } else {
        db_query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value',
            [':k' => 'restaurant_hours_' . $slug, ':v' => json_encode($cfg)]
        );
        db_query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value',
            [':k' => 'restaurant_inbox_' . $slug, ':v' => trim((string)($_POST['inbox'] ?? ''))]
        );
        audit_log('restaurant_hours_update', 'venue', 0, $slug);
        $flash = ['type' => 'success', 'msg' => 'Service hours saved.'];
    }
}

$cfg   = restaurant_hours($slug);
$inbox = setting('restaurant_inbox_' . $slug, '');
$names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

require __DIR__ . '/_layout.php';
?>

<?php if ($flash): ?>
<div class="alert is-flash alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Zuri — Service Hours</span></div>
  <div class="card__body">
    <?php
      // Preview the last seating. end() takes a reference, so the slot list must
      // land in a variable first. Use a date we know the venue is open on.
      $__previewDay  = $cfg['days'][0] ?? 1;
      $__previewDate = date('Y-m-d', strtotime('sunday +' . $__previewDay . ' days'));
      $__previewSlots = restaurant_slots_for($__previewDate, $cfg);
      $__lastSeating  = $__previewSlots ? end($__previewSlots) : '—';
    ?>
    <p>Guests can book from the opening time up to (but not including) the closing time.
       At <?= e($cfg['from']) ?>–<?= e($cfg['to']) ?> in <?= e($cfg['step']) ?>-minute steps,
       the last seating is <?= e($__lastSeating) ?>.</p>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="filter-field">
        <span>Open on</span>
        <div style="display:flex;flex-wrap:wrap;gap:.8rem;margin-top:.4rem">
          <?php foreach ($names as $i => $n): ?>
          <label style="display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="days[]" value="<?= $i ?>"<?= in_array($i, $cfg['days'], true) ? ' checked' : '' ?>>
            <?= e($n) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-row">
        <div class="filter-field"><label for="from">Opens</label><input type="time" id="from" name="from" value="<?= e($cfg['from']) ?>" step="60" required></div>
        <div class="filter-field"><label for="to">Closes</label><input type="time" id="to" name="to" value="<?= e($cfg['to']) ?>" step="60" required></div>
        <div class="filter-field">
          <label for="step">Slot length</label>
          <select id="step" name="step">
            <?php foreach ([15, 30, 60] as $m): ?>
            <option value="<?= $m ?>"<?= $cfg['step'] === $m ? ' selected' : '' ?>><?= $m ?> minutes</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="filter-field" style="max-width:420px">
        <label for="inbox">Booking alerts go to</label>
        <input type="email" id="inbox" name="inbox" value="<?= e($inbox) ?>" placeholder="restaurant@tribalsand.com">
      </div>

      <button type="submit" class="btn-primary">Save hours</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/restaurant-hours.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify the settings round-trip**

```bash
php -r 'require getcwd()."/includes/restaurant.php";
db_query("INSERT INTO settings (setting_key,setting_value) VALUES (\047restaurant_hours_zuri\047,\047{\"days\":[4,5,6],\"from\":\"12:00\",\"to\":\"15:00\",\"step\":60}\047) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value");
print_r(restaurant_hours("zuri"));
print_r(restaurant_slots_for("2026-08-20", restaurant_hours("zuri")));'
```
Expected: the stored config, then `['12:00','13:00','14:00']` (2026-08-20 is a Thursday, day 4).

- [ ] **Step 4: Restore the default hours**

```bash
php -r 'require getcwd()."/includes/db.php";
db_query("DELETE FROM settings WHERE setting_key=\047restaurant_hours_zuri\047"); echo "reset\n";'
```

- [ ] **Step 5: Commit**

```bash
git add admin/restaurant-hours.php
git commit -m "feat(restaurant): service-hours editor (manager tier)"
```

---

## Task 12: Sidebar nav group and end-to-end verification

**Files:**
- Modify: `admin/_layout.php`

- [ ] **Step 1: Add the nav-visibility flag**

In `admin/_layout.php`, next to the other `$__nav*` variables (around lines 18–23), add:

```php
$__navRestaurant = ($__isOwner || $__isManager || $__isFrontdeskStaff) && (function (): bool {
    require_once __DIR__ . '/../includes/restaurant.php';
    return restaurant_supported();
})();
```

- [ ] **Step 2: Add the nav group**

In `admin/_layout.php`, immediately **after** the `$__navgroup('catalog', 'Catalog', ob_get_clean());` line, add:

```php
      <?php ob_start(); ?>
        <?php if ($__navRestaurant): ?>
        <a href="/admin/restaurant.php"   class="sidebar__link <?= ($activeMenu??'')==='restaurant'   ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7a3 3 0 0 0 6 0V2M6 2v20M17 2c-1.7 1.2-2.5 3.3-2.5 6s.8 4.3 2.5 5v9"/></svg>
          Reservations
        </a>
        <?php endif; ?>
        <?php if ($__navRestaurant && ($__isOwner || $__isManager)): ?>
        <a href="/admin/restaurant-hours.php" class="sidebar__link <?= ($activeMenu??'')==='restaurant_hours' ? 'is-active':'' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          Service hours
        </a>
        <?php endif; ?>
      <?php $__navgroup('restaurant', 'Restaurant', ob_get_clean()); ?>
```

`$__navgroup` returns early when its items string is empty, so the whole group disappears when the migration has not run or the account lacks access — no extra conditional needed around it.

- [ ] **Step 3: Verify the layout parses**

Run: `php -l admin/_layout.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Run the full test suite**

Run: `php tests/restaurant_logic.php`
Expected: `ALL PASS`, exit 0

- [ ] **Step 5: End-to-end check in the browser**

1. Open `/zuri-restaurant`, pick a date, pick a slot, fill the form, submit.
2. Confirm the success panel shows a `ZR-` reference.
3. Log into the admin, open **Restaurant → Reservations**.
4. Confirm the request appears under Pending with the right date, time, party and occasion.
5. Click **Confirm**; confirm the badge turns green and the flash appears.
6. Expand **Edit** on that row, change the party size, save, and confirm the new value sticks.
7. Open **Restaurant → Service hours**, change the closing time, save, then reload `/zuri-restaurant` and confirm the slot list changed.
8. Restore the original hours.
9. Check `/admin/audit.php` shows `restaurant_request`, `restaurant_confirm`, `restaurant_edit` and `restaurant_hours_update` entries.

- [ ] **Step 6: Verify the menu page is still byte-identical**

```bash
git diff --stat HEAD -- zuri-menu.php
```
Expected: no output — `zuri-menu.php` must not have been touched by any task in this plan.

- [ ] **Step 7: Clean up the end-to-end test row**

```bash
php -r 'require getcwd()."/includes/db.php"; echo db_query("DELETE FROM restaurant_reservations WHERE guest_email LIKE \047%@example.com\047")->rowCount(), " deleted\n";'
```

- [ ] **Step 8: Commit**

```bash
git add admin/_layout.php
git commit -m "feat(restaurant): Restaurant nav group in the admin sidebar"
```

---

## Post-implementation: live rollout

1. Deploy. `restaurant_supported()` keeps the nav group hidden while the table is absent.
2. Run `add_restaurant_reservations.sql` on the live DB via `/admin/migrate.php`.
3. Set the service hours and the alert inbox in **Admin → Restaurant → Service hours**. Until they
   are set, the defaults apply (open daily 18:00–22:00, 30-minute slots) and alerts fall back to
   `MAIL_FROM`.
4. Confirm `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are both set in the live environment. If the site key is set and the secret is not, `verify_captcha()` fail-closes and **every booking will be rejected**.
5. Link `/zuri-restaurant` from the Zuri property page and the main nav.

## Out of scope (tracked for later pieces)

- **Piece A — Menu CMS:** schema for menus/categories/items, admin editor, and converting `zuri-menu.php` to DB-driven rendering with identical markup.
- **Piece C — Restaurant marketing page:** replaces the copy, layout and imagery of `zuri-restaurant.php` around the same widget.
- Pagination on the upcoming list (currently capped at 200 with a visible notice).
- Guest self-service cancellation, decline emails, capacity limits, table assignment.
