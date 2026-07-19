# Guest Booking Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let guests with an availability booking view its status and manage their trip (request changes, add tours/transfers/itinerary), accessed by a magic link or a typed code + email — all request-based, no payment.

**Architecture:** A new guest page `booking.php` renders a hold (resolved via the existing `verify_guest_ref()` HMAC ref, or via a new random `access_code` + email lookup). Two JSON endpoints (`api/booking-addon.php`, `api/booking-change.php`) record guest requests into two new tables, mirroring `api/submit-enquiry.php` (Turnstile + rate-limit + `client_ip()`). Admin views/actions the requests on the existing holds screen. Nothing auto-mutates the hold.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via PDO (`db_query()`), Cloudflare Turnstile (`verify_captcha()`), Resend email (`includes/mail.php`), vanilla JS/CSS. No framework, no build step. No PHP test framework exists — pure-logic tasks ship a `php`-CLI assertion script under `tests/`; endpoint/page tasks verify with the local dev server + curl.

**Reconciliation with spec:** The spec described the link token as `HMAC(hold_id + guest_email)`. Implementation instead reuses the existing `make_guest_ref()`/`verify_guest_ref()` helpers in `includes/booking.php` (HMAC of `hold_id`, format `TS-<id>-<hash8>`) — server-only, unforgeable, and already written "for self-service for guests." The typed-code path verifies email separately. Same security properties, less new code (DRY).

**Local dev note:** Run the site with `php -S localhost:8765` (uses the Neon DB via `.env`) — the reference CLAUDE.md shows `D:\php84\php.exe`; on this macOS machine use `php` on PATH. DB-touching tests require a working `.env` (DATABASE_URL + BOOKING_TOKEN_SECRET).

---

## File Structure

| File | Responsibility |
|------|----------------|
| `db/migrations/add_booking_management.sql` | New — `holds.access_code` column + backfill, `booking_addons`, `booking_change_requests` |
| `db/run-migrations.sql` | Append the same idempotent statements (Neon console copy) |
| `includes/db.php` | Add `generate_access_code()`; set `access_code` inside `create_hold_with_block()` |
| `includes/booking.php` | Add `fetch_hold_for_guest()`, `resolve_booking_by_ref()`, `resolve_booking_by_code()`, `make_manage_url()`, `fetch_published_tours()`, `TRANSFER_OPTIONS` |
| `booking.php` | New — guest manage page: token render, else code+email lookup; status, summary, add-on catalog, change form |
| `api/booking-addon.php` | New — JSON POST: ref-gate + Turnstile + rate-limit → insert `booking_addons` → admin email |
| `api/booking-change.php` | New — JSON POST: ref-gate + Turnstile + rate-limit → insert `booking_change_requests` → admin email |
| `includes/mail.php` | Add manage-link + access-code to guest ack; add `send_addon_request_notification()`, `send_change_request_notification()` |
| `api/submit-enquiry.php` | Pass `hold_id` + `access_code` into the guest acknowledgement call |
| `admin/holds.php` | Render add-on & change requests per hold, with action buttons |
| `admin/booking-request-action.php` | New — admin endpoint to confirm/decline add-ons, mark/decline change requests |
| `js/booking-manage.js` | New — fetch-based submit for the add-on & change forms |
| `tests/manage_logic.php` | New — CLI assertions for access-code + ref helpers |

---

## Task 1: Database migration

**Files:**
- Create: `db/migrations/add_booking_management.sql`
- Modify: `db/run-migrations.sql` (append)

- [ ] **Step 1: Write the migration file**

Create `db/migrations/add_booking_management.sql`:

```sql
-- Migration: guest booking management (access code, add-ons, change requests)
-- Run: psql "$DATABASE_URL" -f db/migrations/add_booking_management.sql
-- Idempotent — safe to re-run.

-- 1. Guest access code on holds
ALTER TABLE holds ADD COLUMN IF NOT EXISTS access_code VARCHAR(12);

CREATE UNIQUE INDEX IF NOT EXISTS idx_holds_access_code
    ON holds(access_code) WHERE access_code IS NOT NULL;

-- Backfill existing holds that have no code yet (random 6-char, unambiguous alphabet).
-- translate() maps base64-ish chars into the allowed set; good enough for a one-off backfill.
UPDATE holds
SET access_code = upper(substr(translate(encode(gen_random_bytes(6), 'hex'),
                                         'abcdef0189', 'GHJKMN2345'), 1, 6))
WHERE access_code IS NULL;

-- 2. Add-on requests (tours / transfers / itinerary)
CREATE TABLE IF NOT EXISTS booking_addons (
    id         SERIAL PRIMARY KEY,
    hold_id    INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    kind       VARCHAR(20)  NOT NULL CHECK (kind IN ('tour','transfer','itinerary','other')),
    tour_id    INT          REFERENCES tours(id) ON DELETE SET NULL,
    details    TEXT         NOT NULL DEFAULT '',
    status     VARCHAR(20)  NOT NULL DEFAULT 'requested'
                            CHECK (status IN ('requested','confirmed','declined','cancelled')),
    created_at TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_addons_hold ON booking_addons(hold_id);

-- 3. Change requests (dates / guests)
CREATE TABLE IF NOT EXISTS booking_change_requests (
    id                  SERIAL PRIMARY KEY,
    hold_id             INT          NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    requested_check_in  DATE,
    requested_check_out DATE,
    requested_guests    INT,
    note                TEXT         NOT NULL DEFAULT '',
    status              VARCHAR(20)  NOT NULL DEFAULT 'requested'
                                     CHECK (status IN ('requested','handled','declined')),
    created_at          TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_booking_changes_hold ON booking_change_requests(hold_id);
```

> Note: `gen_random_bytes` needs `pgcrypto`. Neon has it enabled by default. If the backfill errors with "function gen_random_bytes does not exist", prepend `CREATE EXTENSION IF NOT EXISTS pgcrypto;` and re-run.

- [ ] **Step 2: Append the same statements to `db/run-migrations.sql`**

Open `db/run-migrations.sql` and append the entire block from Step 1 (minus the leading `-- Run:` comment line) to the end of the file, under a new header `-- ── Guest booking management ──`.

- [ ] **Step 3: Apply the migration to the dev DB**

Run:
```bash
psql "$(grep -E '^DATABASE_URL=' .env | cut -d= -f2-)" -f db/migrations/add_booking_management.sql
```
Expected: `ALTER TABLE`, `CREATE INDEX`, `UPDATE <n>`, `CREATE TABLE`, `CREATE INDEX` messages, no errors.

- [ ] **Step 4: Verify schema**

Run:
```bash
psql "$(grep -E '^DATABASE_URL=' .env | cut -d= -f2-)" -c "\d holds" -c "\d booking_addons" -c "\d booking_change_requests"
```
Expected: `holds` shows an `access_code` column; both new tables exist with the columns above.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_booking_management.sql db/run-migrations.sql
git commit -m "feat(db): add access_code, booking_addons, booking_change_requests"
```

---

## Task 2: Access-code generation helper

**Files:**
- Modify: `includes/db.php` (add `generate_access_code()`; use it in `create_hold_with_block()`)
- Test: `tests/manage_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/manage_logic.php`:

```php
<?php
declare(strict_types=1);
// Pure-logic tests — no DB required. Run: php tests/manage_logic.php
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// --- generate_access_code ---
$code = generate_access_code();
check('code length is 6', strlen($code) === 6);
check('code is uppercase alnum, unambiguous alphabet',
      (bool)preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $code));
$codes = [];
for ($i = 0; $i < 200; $i++) $codes[generate_access_code()] = true;
check('codes vary (>150 unique of 200)', count($codes) > 150);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/manage_logic.php`
Expected: FAIL / fatal error — `Call to undefined function generate_access_code()`.

- [ ] **Step 3: Add `generate_access_code()` to `includes/db.php`**

Add near the other helpers (e.g. just after `client_ip()`):

```php
/**
 * Generate a short, human-friendly booking access code.
 * Uppercase, unambiguous alphabet (no 0/O/1/I/L). Uses random_int (CSPRNG).
 */
function generate_access_code(int $len = 6): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/manage_logic.php`
Expected: `PASS` for all three code checks, `ALL PASS`.

- [ ] **Step 5: Wire the code into `create_hold_with_block()`**

Replace the body of `create_hold_with_block()` in `includes/db.php` with a version that generates and stores a unique code (retry on the rare unique-index collision):

```php
function create_hold_with_block(
    int $unit_id, int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email
): int {
    $hold_id = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = generate_access_code();
        try {
            $stmt = db()->prepare(
                "INSERT INTO holds
                    (submission_id, unit_id, check_in, check_out, guest_name, guest_email, access_code, expires_at)
                 VALUES
                    (:sub, :unit, :ci, :co, :name, :email, :code, NOW() + INTERVAL '24 hours')
                 RETURNING id"
            );
            $stmt->execute([
                ':sub'   => $submission_id,
                ':unit'  => $unit_id,
                ':ci'    => $check_in,
                ':co'    => $check_out,
                ':name'  => $guest_name,
                ':email' => $guest_email,
                ':code'  => $code,
            ]);
            $hold_id = (int)$stmt->fetchColumn();
            break;
        } catch (PDOException $e) {
            // 23505 = unique_violation (access_code collision) — retry with a new code
            if (($e->getCode() === '23505') && $attempt < 4) continue;
            throw $e;
        }
    }

    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id)
         VALUES (:unit, :df, :dt, 'hold', :hold)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out, ':hold' => $hold_id]
    );

    return $hold_id;
}
```

- [ ] **Step 6: Integration check — create a hold, confirm the code persists**

Run (adjust unit id `1` to any real `units.id` from your DB):
```bash
php -r 'require "includes/db.php"; $id=create_hold_with_block(1,0,"2027-01-10","2027-01-12","Test Guest","codecheck@example.com"); $r=db_query("SELECT access_code FROM holds WHERE id=:id",[":id"=>$id])->fetch(); echo "hold {$id} code {$r[\"access_code\"]}\n"; db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$id]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$id]);'
```
Expected: prints a hold id and a 6-char code, then cleans itself up. No errors.

- [ ] **Step 7: Commit**

```bash
git add includes/db.php tests/manage_logic.php
git commit -m "feat: generate access_code on hold creation"
```

---

## Task 3: Booking access + catalog helpers

**Files:**
- Modify: `includes/booking.php` (add resolvers, manage URL, tours query, transfer options)
- Test: `tests/manage_logic.php` (extend)

- [ ] **Step 1: Extend the test with ref round-trip + manage URL**

Append to `tests/manage_logic.php` (before the final `echo $failures` line):

```php
// --- guest ref round-trip (requires BOOKING_TOKEN_SECRET in .env) ---
$secret = parse_env()['BOOKING_TOKEN_SECRET'] ?? '';
if ($secret) {
    require_once __DIR__ . '/../includes/booking.php';
    $ref = make_guest_ref(4242);
    check('ref has TS-<id>-<hash> shape', (bool)preg_match('/^TS-4242-[0-9a-f]{8}$/', $ref));
    check('verify_guest_ref round-trips', verify_guest_ref($ref) === 4242);
    check('tampered ref rejected', verify_guest_ref('TS-4242-deadbeef') === false);
    check('make_manage_url contains ref', str_contains(make_manage_url(4242), 'ref='));
} else {
    echo "SKIP  ref tests (BOOKING_TOKEN_SECRET not set)\n";
}
```

- [ ] **Step 2: Run the test to verify the new checks fail**

Run: `php tests/manage_logic.php`
Expected: FAIL / fatal — `Call to undefined function make_manage_url()` (ref/round-trip checks fail).

- [ ] **Step 3: Add the helpers to `includes/booking.php`**

Append to `includes/booking.php`:

```php
/** Transfer options offered on the manage page (no admin catalog — fixed list). */
const TRANSFER_OPTIONS = [
    'airport_to_property' => 'Airport → Property',
    'property_to_airport' => 'Property → Airport',
    'inter_property'      => 'Between properties',
    'custom'              => 'Custom transfer',
];

/** Absolute URL to the guest manage page for a hold (magic link). '' if secret unset. */
function make_manage_url(int $holdId): string {
    $ref = make_guest_ref($holdId);
    if (!$ref) return '';
    return site_url('/booking.php?ref=' . urlencode($ref));
}

/** Fetch a hold joined with unit/room/venue names for the guest view. */
function fetch_hold_for_guest(int $holdId): array|false {
    return db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.id = :id",
        [':id' => $holdId]
    )->fetch();
}

/** Resolve a booking from a magic-link ref (TS-<id>-<hash>). */
function resolve_booking_by_ref(string $ref): array|false {
    $holdId = verify_guest_ref(trim($ref));
    if ($holdId === false) return false;
    return fetch_hold_for_guest($holdId);
}

/** Resolve a booking from a typed code + email (case-insensitive). */
function resolve_booking_by_code(string $code, string $email): array|false {
    $code  = strtoupper(trim($code));
    $email = strtolower(trim($email));
    if ($code === '' || $email === '') return false;
    $row = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.access_code = :code AND lower(h.guest_email) = :email",
        [':code' => $code, ':email' => $email]
    )->fetch();
    return $row ?: false;
}

/** Published tours grouped for the add-on catalog. */
function fetch_published_tours(): array {
    return db_query(
        "SELECT id, slug, name, category, tag_label, duration, short_desc
         FROM tours WHERE is_published = TRUE
         ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
}

/** Add-ons + change requests already recorded against a hold (for display). */
function fetch_booking_addons(int $holdId): array {
    return db_query(
        "SELECT ba.*, t.name AS tour_name
         FROM booking_addons ba
         LEFT JOIN tours t ON t.id = ba.tour_id
         WHERE ba.hold_id = :id ORDER BY ba.created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}

function fetch_booking_change_requests(int $holdId): array {
    return db_query(
        "SELECT * FROM booking_change_requests WHERE hold_id = :id ORDER BY created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/manage_logic.php`
Expected: all ref checks `PASS` (or `SKIP` if no secret), `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php tests/manage_logic.php
git commit -m "feat: booking resolvers + add-on catalog helpers"
```

---

## Task 4: Guest manage page `booking.php`

**Files:**
- Create: `booking.php`
- Create: `js/booking-manage.js` (stub now; wired in Task 7's client behavior — created here so the page can reference it)

- [ ] **Step 1: Create `js/booking-manage.js`**

Create `js/booking-manage.js`:

```javascript
// Guest booking manage page — fetch-based submit for add-on & change forms.
(function () {
  function wire(form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const btn = form.querySelector('button[type=submit]');
      const status = form.querySelector('.bm-status');
      const url = form.getAttribute('action');
      const payload = Object.fromEntries(new FormData(form).entries());
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.ok) {
          if (status) { status.textContent = 'Request sent — we’ll be in touch by email.'; status.className = 'bm-status ok'; }
          setTimeout(function () { window.location.reload(); }, 1200);
        } else {
          if (status) { status.textContent = data.error || 'Something went wrong. Please try again.'; status.className = 'bm-status err'; }
          if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
          if (window.turnstile) window.turnstile.reset();
        }
      } catch (_) {
        if (status) { status.textContent = 'Network error. Please try again.'; status.className = 'bm-status err'; }
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
      }
    });
  }
  document.querySelectorAll('form[data-bm]').forEach(wire);

  // 24h hold countdown (pending bookings only)
  var banner = document.querySelector('.bm-banner[data-expires]');
  if (banner) {
    var el = document.getElementById('bmCountdown');
    var end = new Date(banner.getAttribute('data-expires').replace(' ', 'T')).getTime();
    var tick = function () {
      var d = end - Date.now();
      if (d <= 0) { if (el) el.textContent = 'expired'; return; }
      var h = Math.floor(d / 3.6e6), m = Math.floor((d % 3.6e6) / 6e4);
      if (el) el.textContent = h + 'h ' + m + 'm left';
    };
    tick(); setInterval(tick, 30000);
  }
})();
```

- [ ] **Step 2: Create `booking.php`**

Create `booking.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/turnstile.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$hold      = false;
$lookupErr = '';

// 1. Magic-link path: ?ref=TS-<id>-<hash>
if (!empty($_GET['ref'])) {
    try { $hold = resolve_booking_by_ref((string)$_GET['ref']); }
    catch (Throwable $e) { $hold = false; }
}

// 2. Code + email lookup (POST from the lookup form)
if (!$hold && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'lookup') {
    $ip = client_ip();
    // Rate limit: max 8 lookups per IP / 10 min (reuse login_attempts-style guard via holds is overkill; simple session counter)
    $_SESSION['bm_lookups'] = array_values(array_filter($_SESSION['bm_lookups'] ?? [], fn($t) => $t > time() - 600));
    if (count($_SESSION['bm_lookups']) >= 8) {
        $lookupErr = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif (!verify_captcha($_POST['cf-turnstile-response'] ?? '', $ip)) {
        $lookupErr = 'Security check failed. Please try again.';
    } else {
        $_SESSION['bm_lookups'][] = time();
        try { $hold = resolve_booking_by_code((string)($_POST['code'] ?? ''), (string)($_POST['email'] ?? '')); }
        catch (Throwable $e) { $hold = false; }
        if (!$hold) $lookupErr = 'We couldn’t find a booking with that code and email.';
    }
}

$page_title = 'Manage Your Booking · Tribal Sand';
$page_desc  = 'View and manage your Tribal Sand booking.';
$page_url   = 'https://tribalsand.com/booking.php';
$noindex    = true; // never index guest booking pages

include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/css/booking-manage.css?v=<?= @filemtime(__DIR__ . '/css/booking-manage.css') ?: time() ?>">
<div class="bm-page">
<?php if (!$hold): ?>
  <div class="bm-wrap bm-narrow">
    <h1>Manage your booking</h1>
    <p>Enter the booking code from your confirmation email, along with the email address you booked with.</p>
    <?php if ($lookupErr): ?><p class="bm-status err"><?= e($lookupErr) ?></p><?php endif; ?>
    <form method="post" class="bm-form">
      <input type="hidden" name="do" value="lookup">
      <label>Booking code
        <input type="text" name="code" required autocomplete="off" placeholder="e.g. K7QM2P"
               style="text-transform:uppercase" value="<?= e($_POST['code'] ?? '') ?>">
      </label>
      <label>Email address
        <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
      <button type="submit" class="btn">Find my booking</button>
    </form>
  </div>
<?php else:
    $hid    = (int)$hold['id'];
    $ref    = make_guest_ref($hid);
    $status = $hold['status'];
    $addons = fetch_booking_addons($hid);
    $changes= fetch_booking_change_requests($hid);
    $tours  = fetch_published_tours();
    $nights = (int)((strtotime($hold['check_out']) - strtotime($hold['check_in'])) / 86400);
    $canAct = in_array($status, ['pending', 'confirmed'], true);
    $badge  = match ($status) {
        'pending'   => ['pending',   '⏳ Reservation held — awaiting confirmation'],
        'confirmed' => ['confirmed', '✅ Booking confirmed'],
        'expired'   => ['expired',   'This hold has expired'],
        'cancelled' => ['cancelled', 'This booking was cancelled'],
        default     => ['pending',   ucfirst($status)],
    };
?>
  <div class="bm-wrap">
    <div class="bm-banner bm-<?= e($badge[0]) ?>"
         <?php if ($status === 'pending'): ?>data-expires="<?= e($hold['expires_at']) ?>"<?php endif; ?>>
      <span class="bm-banner-text"><?= e($badge[1]) ?></span>
      <?php if ($status === 'pending'): ?><span class="bm-countdown" id="bmCountdown"></span><?php endif; ?>
    </div>

    <section class="bm-summary">
      <h1><?= e($hold['room_name']) ?></h1>
      <?php if (!empty($hold['venue_name'])): ?><p class="bm-venue"><?= e($hold['venue_name']) ?></p><?php endif; ?>
      <dl>
        <div><dt>Check-in</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_in']))) ?></dd></div>
        <div><dt>Check-out</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_out']))) ?></dd></div>
        <div><dt>Nights</dt><dd><?= e((string)$nights) ?></dd></div>
        <div><dt>Guest</dt><dd><?= e($hold['guest_name']) ?></dd></div>
        <div><dt>Booking code</dt><dd><strong><?= e($hold['access_code'] ?? '') ?></strong></dd></div>
      </dl>
    </section>

    <?php if ($status === 'expired' || $status === 'cancelled'): ?>
      <p class="bm-note">This booking can no longer be changed. To make a new booking, please
        <a href="/enquire.php">start a new enquiry</a>.</p>
    <?php endif; ?>

    <?php if ($canAct): ?>
      <!-- Add-ons + change forms rendered in Task 5 / Task 6 include -->
      <?php include __DIR__ . '/includes/booking-manage-actions.php'; ?>
    <?php endif; ?>

    <?php if ($addons): ?>
    <section class="bm-list">
      <h2>Your extras</h2>
      <ul>
        <?php foreach ($addons as $a): ?>
          <li>
            <span class="bm-kind"><?= e(ucfirst($a['kind'])) ?></span>
            <?= e($a['tour_name'] ?? '') ?>
            <?php if ($a['details']): ?><span class="bm-detail"><?= e($a['details']) ?></span><?php endif; ?>
            <span class="bm-status-pill bm-pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($changes): ?>
    <section class="bm-list">
      <h2>Your change requests</h2>
      <ul>
        <?php foreach ($changes as $c): ?>
          <li>
            <?php if ($c['requested_check_in'] || $c['requested_check_out']): ?>
              New dates: <?= e((string)$c['requested_check_in']) ?> → <?= e((string)$c['requested_check_out']) ?>.
            <?php endif; ?>
            <?php if ($c['requested_guests']): ?>Guests: <?= e((string)$c['requested_guests']) ?>. <?php endif; ?>
            <?php if ($c['note']): ?><span class="bm-detail"><?= e($c['note']) ?></span><?php endif; ?>
            <span class="bm-status-pill bm-pill-<?= e($c['status']) ?>"><?= e($c['status']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<script src="/js/booking-manage.js?v=<?= @filemtime(__DIR__ . '/js/booking-manage.js') ?: time() ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

> The countdown is handled inside `js/booking-manage.js` (Step 1 already includes it) — no inline script needed.

- [ ] **Step 3: Create a minimal `includes/booking-manage-actions.php` placeholder** (real content in Tasks 5–6 verification, but the page must not fatal now)

Create `includes/booking-manage-actions.php`:

```php
<?php /** Rendered inside booking.php when $canAct. Expects $hold, $ref, $tours in scope. */ ?>
<section class="bm-actions">
  <h2>Add to your trip</h2>
  <p>Extras are added as requests — our team confirms availability and pricing by email.</p>
  <!-- forms added in Task 5 (change) and Task 6 (add-ons) -->
</section>
```

- [ ] **Step 4: Create `css/booking-manage.css`** (minimal, uses existing tokens where possible)

Create `css/booking-manage.css`:

```css
.bm-page{background:#FAF8F4;padding:120px 0 5rem;min-height:70vh}
@media(max-width:600px){.bm-page{padding-top:96px}}
.bm-wrap{max-width:760px;margin:0 auto;padding:0 20px}
.bm-narrow{max-width:440px}
.bm-form label{display:block;margin:0 0 14px;font-size:14px}
.bm-form input{display:block;width:100%;padding:10px;margin-top:4px;border:1px solid #cbd5cc;border-radius:8px}
.bm-banner{padding:16px 20px;border-radius:12px;display:flex;justify-content:space-between;align-items:center;margin:0 0 24px;font-weight:600}
.bm-pending{background:#fff7e6;color:#8a5a00}
.bm-confirmed{background:#e6f6ec;color:#146c37}
.bm-expired,.bm-cancelled{background:#f1f1f1;color:#666}
.bm-countdown{font-variant-numeric:tabular-nums;font-size:14px}
.bm-summary dl{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin:16px 0}
.bm-summary dt{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#888}
.bm-summary dd{margin:0;font-size:16px}
.bm-actions,.bm-list{margin-top:32px;padding-top:24px;border-top:1px solid #e5e0d6}
.bm-list li{padding:10px 0;border-bottom:1px solid #eee;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.bm-kind{font-weight:600}
.bm-detail{color:#666;font-size:14px;flex-basis:100%}
.bm-status-pill{font-size:12px;padding:2px 8px;border-radius:999px;text-transform:capitalize}
.bm-pill-requested{background:#fff7e6;color:#8a5a00}
.bm-pill-confirmed{background:#e6f6ec;color:#146c37}
.bm-pill-declined,.bm-pill-cancelled{background:#fbe6e6;color:#a12}
.bm-pill-handled{background:#e6eefb;color:#1a4a9c}
.bm-status.ok{color:#146c37}.bm-status.err{color:#a12}
.bm-tour-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.bm-tour{border:1px solid #e5e0d6;border-radius:10px;padding:12px}
```

- [ ] **Step 5: Verify the page renders both states**

Start the dev server (via the browser preview tool, config name `tribalsand` — create `.claude/launch.json` if missing with `php -S localhost:8765 router.php`).

Lookup state — open `http://localhost:8765/booking.php` → expect the "Manage your booking" code+email form.

Booking state — get a real ref:
```bash
php -r 'require "includes/booking.php"; $r=db_query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetch(); echo $r? make_manage_url((int)$r["id"])."\n" : "no holds\n";'
```
Open the printed URL → expect the status banner + booking summary. If "no holds", create one via Task 2 Step 6 first (without the cleanup delete).

- [ ] **Step 6: Commit**

```bash
git add booking.php js/booking-manage.js css/booking-manage.css includes/booking-manage-actions.php
git commit -m "feat: guest booking manage page (lookup + status + summary)"
```

---

## Task 5: Change-request endpoint + form

**Files:**
- Create: `api/booking-change.php`
- Modify: `includes/booking-manage-actions.php` (add the change form)

- [ ] **Step 1: Create `api/booking-change.php`**

Create `api/booking-change.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$ip   = client_ip();

// Ref gate — must resolve to a real, actionable hold
$hold = resolve_booking_by_ref((string)($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
if (!in_array($hold['status'], ['pending','confirmed'], true)) {
    http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'This booking can no longer be changed.']));
}

// Turnstile
if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Security check failed. Please try again.']));
}

// Rate limit — max 5 change requests per hold / 10 min
$window = date('Y-m-d H:i:s', time() - 600);
$cnt = db_query("SELECT COUNT(*) c FROM booking_change_requests WHERE hold_id=:h AND created_at>:w",
    [':h'=>$hold['id'], ':w'=>$window])->fetch()['c'];
if ((int)$cnt >= 5) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many requests. Please wait a few minutes.'])); }

// Validate: at least one of dates/guests/note present
$ci    = trim($data['check_in']  ?? '');
$co    = trim($data['check_out'] ?? '');
$guests= (int)($data['guests'] ?? 0);
$note  = trim($data['note'] ?? '');
$isDate = fn($d) => $d === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$isDate($ci) || !$isDate($co)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please use valid dates.'])); }
if ($ci === '' && $co === '' && $guests <= 0 && $note === '') {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please tell us what you’d like to change.']));
}

try {
    db_query(
        "INSERT INTO booking_change_requests (hold_id, requested_check_in, requested_check_out, requested_guests, note)
         VALUES (:h, :ci, :co, :g, :note)",
        [':h'=>$hold['id'], ':ci'=>$ci ?: null, ':co'=>$co ?: null, ':g'=>$guests > 0 ? $guests : null, ':note'=>$note]
    );
    send_change_request_notification($hold, ['check_in'=>$ci,'check_out'=>$co,'guests'=>$guests,'note'=>$note]);
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
```

> `send_change_request_notification()` is added in Task 7. If executing tasks strictly in order, temporarily comment that line, then uncomment in Task 7. (Noted so this task's endpoint can be smoke-tested standalone.)

- [ ] **Step 2: Add the change form to `includes/booking-manage-actions.php`**

Append inside `includes/booking-manage-actions.php` (after the intro `<p>`):

```php
<div class="bm-change">
  <h3>Request a change</h3>
  <form data-bm action="/api/booking-change.php" class="bm-form">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <label>New check-in (optional)<input type="date" name="check_in"></label>
    <label>New check-out (optional)<input type="date" name="check_out"></label>
    <label>Guests (optional)<input type="number" name="guests" min="1" max="30"></label>
    <label>Notes<textarea name="note" rows="3" placeholder="Tell us what you’d like to change"></textarea></label>
    <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
    <button type="submit" class="btn">Send change request</button>
    <p class="bm-status" aria-live="polite"></p>
  </form>
</div>
```

- [ ] **Step 3: Smoke-test the endpoint (ref-gate + validation)**

With the dev server running and a real ref in `$REF`:
```bash
REF=$(php -r 'require "includes/booking.php"; $r=db_query("SELECT id FROM holds WHERE status IN (\047pending\047,\047confirmed\047) ORDER BY id DESC LIMIT 1")->fetch(); echo $r? make_guest_ref((int)$r["id"]) : "";')
# Bad ref → 403
curl -s -X POST localhost:8765/api/booking-change.php -H 'Content-Type: application/json' -d '{"ref":"TS-1-bad","note":"x"}'
# Empty change → 422 (dev-mode Turnstile bypass applies only if keys unset locally)
curl -s -X POST localhost:8765/api/booking-change.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\"}"
```
Expected: first returns `{"ok":false,"error":"Booking not found."}`; second returns a 422 "tell us what you'd like to change" (or a Turnstile error if local keys are set — that's also acceptable proof the guard runs).

- [ ] **Step 4: Verify a real submission writes a row**

```bash
curl -s -X POST localhost:8765/api/booking-change.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"note\":\"Please move us to the sea-view room\"}"
psql "$(grep -E '^DATABASE_URL=' .env | cut -d= -f2-)" -c "SELECT hold_id, note, status FROM booking_change_requests ORDER BY id DESC LIMIT 1;"
```
Expected: `{"ok":true}` (local dev, keys unset) and a row with the note and `status=requested`. (If Turnstile keys are set locally, submit through the browser instead.)

- [ ] **Step 5: Commit**

```bash
git add api/booking-change.php includes/booking-manage-actions.php
git commit -m "feat: guest change-request endpoint + form"
```

---

## Task 6: Add-on endpoint + catalog form

**Files:**
- Create: `api/booking-addon.php`
- Modify: `includes/booking-manage-actions.php` (add the add-on catalog + form)

- [ ] **Step 1: Create `api/booking-addon.php`**

Create `api/booking-addon.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/turnstile.php';
require_once __DIR__ . '/../includes/mail.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$ip   = client_ip();

$hold = resolve_booking_by_ref((string)($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
if (!in_array($hold['status'], ['pending','confirmed'], true)) {
    http_response_code(409); exit(json_encode(['ok'=>false,'error'=>'This booking can no longer take additions.']));
}

if (!verify_captcha($data['cf-turnstile-response'] ?? '', $ip)) {
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Security check failed. Please try again.']));
}

// Rate limit — max 10 add-on requests per hold / 10 min
$window = date('Y-m-d H:i:s', time() - 600);
$cnt = db_query("SELECT COUNT(*) c FROM booking_addons WHERE hold_id=:h AND created_at>:w",
    [':h'=>$hold['id'], ':w'=>$window])->fetch()['c'];
if ((int)$cnt >= 10) { http_response_code(429); exit(json_encode(['ok'=>false,'error'=>'Too many requests. Please wait a few minutes.'])); }

// Validate
$kind = trim($data['kind'] ?? '');
if (!in_array($kind, ['tour','transfer','itinerary','other'], true)) {
    http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Unknown add-on type.']));
}
$details = trim($data['details'] ?? '');
$tour_id = null;

if ($kind === 'tour') {
    $slug = trim($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_by_slug($slug) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid tour.'])); }
    $tour_id = (int)$tour['id'];
    if ($details === '') $details = $tour['name'];
} elseif ($kind === 'transfer') {
    $opt = trim($data['transfer'] ?? '');
    if (!array_key_exists($opt, TRANSFER_OPTIONS)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a transfer option.'])); }
    $label = TRANSFER_OPTIONS[$opt];
    $details = $details === '' ? $label : "{$label} — {$details}";
} else { // itinerary / other
    if ($details === '') { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please add a few details.'])); }
}

try {
    db_query(
        "INSERT INTO booking_addons (hold_id, kind, tour_id, details) VALUES (:h, :k, :t, :d)",
        [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details]
    );
    send_addon_request_notification($hold, ['kind'=>$kind,'details'=>$details]);
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
```

> `send_addon_request_notification()` is added in Task 7 — same temporary-comment note as Task 5 if executing strictly in order.

- [ ] **Step 2: Add the add-on catalog to `includes/booking-manage-actions.php`**

Append inside `includes/booking-manage-actions.php` (after the change form `</div>`):

```php
<div class="bm-addons">
  <h3>Add a tour</h3>
  <form data-bm action="/api/booking-addon.php" class="bm-form">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="kind" value="tour">
    <label>Choose a tour
      <select name="tour_slug" required>
        <option value="">— select —</option>
        <?php foreach ($tours as $t): ?>
          <option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?><?= $t['tag_label'] ? ' · ' . e($t['tag_label']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Notes (optional)<textarea name="details" rows="2" placeholder="Preferred date, group size…"></textarea></label>
    <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
    <button type="submit" class="btn">Request this tour</button>
    <p class="bm-status" aria-live="polite"></p>
  </form>

  <h3>Add a transfer</h3>
  <form data-bm action="/api/booking-addon.php" class="bm-form">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="kind" value="transfer">
    <label>Transfer
      <select name="transfer" required>
        <option value="">— select —</option>
        <?php foreach (TRANSFER_OPTIONS as $k => $label): ?>
          <option value="<?= e($k) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Details (flight no., time, pickup…)<textarea name="details" rows="2"></textarea></label>
    <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
    <button type="submit" class="btn">Request transfer</button>
    <p class="bm-status" aria-live="polite"></p>
  </form>

  <h3>Itinerary request</h3>
  <form data-bm action="/api/booking-addon.php" class="bm-form">
    <input type="hidden" name="ref" value="<?= e($ref) ?>">
    <input type="hidden" name="kind" value="itinerary">
    <label>What would you like to add to your trip?<textarea name="details" rows="3" required></textarea></label>
    <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
    <button type="submit" class="btn">Send itinerary request</button>
    <p class="bm-status" aria-live="polite"></p>
  </form>
</div>
```

- [ ] **Step 3: Verify each add-on kind writes correctly**

With `$REF` set (as in Task 5), local Turnstile keys unset:
```bash
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"transfer\",\"transfer\":\"airport_to_property\",\"details\":\"KQ100 14:30\"}"
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"itinerary\",\"details\":\"Sunset dhow cruise\"}"
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"tour\",\"tour_slug\":\"tsavo-east\"}"
psql "$(grep -E '^DATABASE_URL=' .env | cut -d= -f2-)" -c "SELECT kind, tour_id, details, status FROM booking_addons ORDER BY id DESC LIMIT 3;"
```
Expected: three `{"ok":true}`; rows show transfer (details prefixed "Airport → Property — KQ100 14:30"), itinerary, and tour (tour_id set, details = "Tsavo East").

- [ ] **Step 4: Verify invalid input is rejected**

```bash
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"tour\",\"tour_slug\":\"nope\"}"
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"itinerary\"}"
```
Expected: `{"ok":false,"error":"Please choose a valid tour."}` and `{"ok":false,"error":"Please add a few details."}`.

- [ ] **Step 5: Commit**

```bash
git add api/booking-addon.php includes/booking-manage-actions.php
git commit -m "feat: guest add-on requests (tours/transfers/itinerary)"
```

---

## Task 7: Emails — guest link/code + admin notifications

**Files:**
- Modify: `includes/mail.php` (extend `send_guest_acknowledgement()`; add two notification functions)
- Modify: `api/submit-enquiry.php` (pass `hold_id` + `access_code` into the ack)

- [ ] **Step 1: Extend `send_guest_acknowledgement()` to include the manage link + code**

In `includes/mail.php`, inside `send_guest_acknowledgement()`, after `$rows` is built and before `$tl` is assembled, add:

```php
    // Manage-booking link + code (hold acks only)
    $manage_url = '';
    $access_code = trim((string)($a['access_code'] ?? ''));
    if (($a['kind'] ?? '') === 'hold' && !empty($a['hold_id'])) {
        require_once __DIR__ . '/booking.php';
        $manage_url = make_manage_url((int)$a['hold_id']);
    }
```

Then, in the text builder, after the `YOUR DETAILS` block (before the "If your enquiry is urgent" line), add:

```php
    if ($manage_url) {
        $tl[] = 'MANAGE YOUR BOOKING';
        $tl[] = "  View status & add tours/transfers: {$manage_url}";
        if ($access_code) $tl[] = "  Your booking code: {$access_code}";
        $tl[] = '';
    }
```

And pass both into the HTML builder call — change the `_guest_ack_html([...])` array to include:

```php
        'manage_url'  => $manage_url,
        'access_code' => $access_code,
```

- [ ] **Step 2: Render the link + code in `_guest_ack_html()`**

Find `_guest_ack_html()` in `includes/mail.php`. Add, immediately before the closing signature/footer block, a button + code panel (insert where the details table ends):

```php
    $manage = '';
    if (!empty($d['manage_url'])) {
        $code = !empty($d['access_code'])
            ? '<p style="margin:10px 0 0;font-size:14px;color:#555">Booking code: <strong style="letter-spacing:2px">'
              . htmlspecialchars($d['access_code']) . '</strong></p>' : '';
        $manage =
            '<div style="text-align:center;margin:24px 0">'
          . '<a href="' . htmlspecialchars($d['manage_url']) . '" '
          . 'style="display:inline-block;background:#0e6b7a;color:#fff;text-decoration:none;'
          . 'padding:12px 24px;border-radius:8px;font-weight:600">Manage your booking</a>'
          . $code . '</div>';
    }
```

Then insert `. $manage` into the HTML concatenation at the point where the body content is assembled (after the details rows, before the sign-off). Match the surrounding `_guest_ack_html` concatenation style.

- [ ] **Step 3: Add the two admin notification functions**

Append to `includes/mail.php`:

```php
/** Notify admin of a guest change request. */
function send_change_request_notification(array $hold, array $req): void {
    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'Tribal Sand <noreply@tribalsand.com>';
    $to   = setting('notify_email', 'reservations@tribalsand.com');
    $site = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $admin= $site . '/admin/holds.php';
    $subject = "Change request — hold #{$hold['id']} ({$hold['guest_name']})";
    $lines = [
        "Guest {$hold['guest_name']} ({$hold['guest_email']}) requested a change to hold #{$hold['id']} — {$hold['room_name']}.",
        '',
        'Requested:',
        '  New check-in:  ' . ($req['check_in']  ?: '—'),
        '  New check-out: ' . ($req['check_out'] ?: '—'),
        '  Guests:        ' . (($req['guests'] ?? 0) > 0 ? $req['guests'] : '—'),
        '  Note:          ' . ($req['note'] ?: '—'),
        '',
        "Review in admin: {$admin}",
    ];
    $text = implode("\n", $lines);
    $html = '<p>' . nl2br(htmlspecialchars($text)) . '</p>';
    _dispatch_mail($to, $subject, $text, $from, $to, $env, $html);
}

/** Notify admin of a guest add-on request. */
function send_addon_request_notification(array $hold, array $addon): void {
    $env  = parse_env();
    $from = $env['MAIL_FROM'] ?? 'Tribal Sand <noreply@tribalsand.com>';
    $to   = setting('notify_email', 'reservations@tribalsand.com');
    $site = rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/');
    $admin= $site . '/admin/holds.php';
    $subject = "Add-on request — hold #{$hold['id']} ({$hold['guest_name']})";
    $lines = [
        "Guest {$hold['guest_name']} ({$hold['guest_email']}) added a {$addon['kind']} to hold #{$hold['id']} — {$hold['room_name']}.",
        '',
        'Details: ' . ($addon['details'] ?: '—'),
        '',
        "Review in admin: {$admin}",
    ];
    $text = implode("\n", $lines);
    $html = '<p>' . nl2br(htmlspecialchars($text)) . '</p>';
    _dispatch_mail($to, $subject, $text, $from, $to, $env, $html);
}
```

> If Task 5/6 had the notification calls commented out, uncomment them now.

- [ ] **Step 4: Pass hold_id + access_code from `api/submit-enquiry.php`**

In `api/submit-enquiry.php`, in the availability branch where `send_guest_acknowledgement([...])` is called with `'kind' => 'hold'`, add these two keys to the array:

```php
            'hold_id'     => $hold_id,
            'access_code' => $hold_row['access_code'] ?? '',
```

- [ ] **Step 5: Verify the ack email contains the link + code**

If `RESEND_API_KEY` is unset locally, `_dispatch_mail` logs instead of sending — check the output. Otherwise inspect via a quick render:
```bash
php -r 'require "includes/mail.php"; require "includes/booking.php";
$r=db_query("SELECT id,access_code FROM holds ORDER BY id DESC LIMIT 1")->fetch();
$_SERVER["HTTP_HOST"]="tribalsand.com";
send_guest_acknowledgement(["kind"=>"hold","guest_name"=>"Test","guest_email"=>"you@example.com","room_name"=>"Maya Kobe","check_in"=>"2027-01-10","check_out"=>"2027-01-12","hold_id"=>(int)$r["id"],"access_code"=>$r["access_code"]]);
echo "sent/logged\n";'
```
Expected: no errors; if mail is logged locally, the log shows a `booking.php?ref=TS-` URL and the booking code.

- [ ] **Step 6: Commit**

```bash
git add includes/mail.php api/submit-enquiry.php
git commit -m "feat(mail): manage link + code in guest ack; admin request notifications"
```

---

## Task 8: Admin — view & action guest requests

**Files:**
- Create: `admin/booking-request-action.php`
- Modify: `admin/holds.php` (render requests per hold + action buttons)

- [ ] **Step 1: Create the admin action endpoint**

Create `admin/booking-request-action.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

session_init();
if (empty($_SESSION['admin_id'])) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: /admin/login.php'); exit;
}
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$type   = $_POST['type']   ?? '';   // 'addon' | 'change'
$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

$ok = false;
if ($type === 'addon' && in_array($status, ['confirmed','declined','cancelled'], true) && $id) {
    db_query("UPDATE booking_addons SET status=:s WHERE id=:id", [':s'=>$status, ':id'=>$id]);
    audit_log('booking_addon.' . $status, 'booking_addon', $id, 'admin action');
    $ok = true;
} elseif ($type === 'change' && in_array($status, ['handled','declined'], true) && $id) {
    db_query("UPDATE booking_change_requests SET status=:s WHERE id=:id", [':s'=>$status, ':id'=>$id]);
    audit_log('booking_change.' . $status, 'booking_change_request', $id, 'admin action');
    $ok = true;
}

$_SESSION['hold_flash'] = $ok
    ? ['type'=>'success', 'msg'=>ucfirst($type) . " request marked {$status}."]
    : ['type'=>'error',   'msg'=>'Invalid request action.'];
header('Location: /admin/holds.php');
exit;
```

- [ ] **Step 2: Render requests per hold in `admin/holds.php`**

First read how `admin/holds.php` lists holds (find the per-hold row/loop):
```bash
grep -n "foreach\|\$hold\|holds h\|status" admin/holds.php | head -40
```

Then, inside the per-hold render (where each hold's details are shown), add a block that fetches and lists its requests with action forms. Insert:

```php
<?php
  $h_addons  = db_query("SELECT ba.*, t.name AS tour_name FROM booking_addons ba
                         LEFT JOIN tours t ON t.id=ba.tour_id
                         WHERE ba.hold_id=:h ORDER BY ba.created_at DESC", [':h'=>$hold['id']])->fetchAll();
  $h_changes = db_query("SELECT * FROM booking_change_requests WHERE hold_id=:h ORDER BY created_at DESC",
                        [':h'=>$hold['id']])->fetchAll();
?>
<?php if ($h_addons || $h_changes): ?>
<div class="hold-requests" style="margin-top:10px;padding-top:10px;border-top:1px solid #eee">
  <?php foreach ($h_addons as $a): ?>
    <div style="display:flex;gap:8px;align-items:center;font-size:13px;margin:4px 0">
      <strong><?= e(ucfirst($a['kind'])) ?></strong>
      <span><?= e($a['tour_name'] ?? '') ?> <?= e($a['details']) ?></span>
      <em>(<?= e($a['status']) ?>)</em>
      <?php if ($a['status'] === 'requested'): ?>
        <form method="post" action="/admin/booking-request-action.php" style="display:inline">
          <input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button name="status" value="confirmed">Confirm</button>
          <button name="status" value="declined">Decline</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php foreach ($h_changes as $c): ?>
    <div style="display:flex;gap:8px;align-items:center;font-size:13px;margin:4px 0">
      <strong>Change</strong>
      <span>
        <?= e((string)($c['requested_check_in'] ?? '')) ?>→<?= e((string)($c['requested_check_out'] ?? '')) ?>
        <?= $c['requested_guests'] ? ' · ' . (int)$c['requested_guests'] . ' guests' : '' ?>
        <?= e($c['note']) ?>
      </span>
      <em>(<?= e($c['status']) ?>)</em>
      <?php if ($c['status'] === 'requested'): ?>
        <form method="post" action="/admin/booking-request-action.php" style="display:inline">
          <input type="hidden" name="type" value="change"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button name="status" value="handled">Mark handled</button>
          <button name="status" value="declined">Decline</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
```

Adjust the variable name (`$hold`) to match the actual loop variable used in `admin/holds.php`.

- [ ] **Step 3: Verify admin shows and actions a request**

Log into admin (`/admin/login.php`) in the browser preview, open `/admin/holds.php`, find the hold you added requests to (Tasks 5–6). Expect to see the add-on/change lines with Confirm/Decline buttons. Click Confirm on an add-on.

Then verify:
```bash
psql "$(grep -E '^DATABASE_URL=' .env | cut -d= -f2-)" -c "SELECT id,status FROM booking_addons ORDER BY id DESC LIMIT 1;"
```
Expected: the actioned add-on's `status` is now `confirmed`, and the admin page shows a success flash.

- [ ] **Step 4: Commit**

```bash
git add admin/booking-request-action.php admin/holds.php
git commit -m "feat(admin): view and action guest booking requests"
```

---

## Task 9: End-to-end walkthrough & noindex check

**Files:** none (verification only)

- [ ] **Step 1: Confirm `head.php` honors `$noindex`**

Check whether `includes/head.php` emits a `noindex` robots tag when `$noindex` is set:
```bash
grep -n "noindex\|robots" includes/head.php
```
If it does NOT, add near the other meta tags in `includes/head.php`:
```php
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
```
Then verify `booking.php` output contains the tag:
```bash
curl -s "localhost:8765/booking.php" | grep -i robots
```
Expected: `<meta name="robots" content="noindex,nofollow">`.

- [ ] **Step 2: Full guest flow in the browser**

1. Create a fresh hold via the real booking widget (or Task 2 Step 6 without cleanup).
2. Get its manage URL (`make_manage_url`) and open it → status banner + countdown + summary appear.
3. Submit a change request and a tour add-on through the on-page forms → each shows "Request sent" then reloads and lists the item as `requested`.
4. Open a new private window, go to `/booking.php`, enter the booking code + email → same booking loads.
5. Enter a wrong code → generic "couldn’t find" error.

- [ ] **Step 3: Admin confirms, guest sees updated status**

In admin, Confirm the tour add-on. Reload the guest page → the extra now shows a `confirmed` pill.

- [ ] **Step 4: Fail-closed check (no secret)**

Temporarily blank `BOOKING_TOKEN_SECRET` in `.env`, reload a `?ref=...` link → it should fall back to the lookup form (ref no longer verifies), and no "Manage your booking" link is generated in new acks. Restore the secret afterward.

- [ ] **Step 5: Run the logic test suite once more**

Run: `php tests/manage_logic.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Final commit (if any tweaks were made)**

```bash
git add -A
git commit -m "chore: booking management e2e fixes + noindex"
```

---

## Self-Review Notes (author checklist — verify during execution)

- **Spec coverage:** magic link (Task 3/4), code+email lookup (Task 4), `access_code` column + generation (Tasks 1–2), add-ons tours/transfers/itinerary (Task 6), change requests (Task 5), admin actioning (Task 8), emails with link+code (Task 7), status banner + countdown (Task 4), non-goals respected (no payment, no self-cancel, holds-only, fixed transfers). ✔ each maps to a task.
- **No self-cancel / no guest add-on confirmation email:** intentionally absent (spec non-goals).
- **Type consistency:** `resolve_booking_by_ref`, `resolve_booking_by_code`, `make_manage_url`, `generate_access_code`, `fetch_published_tours`, `TRANSFER_OPTIONS`, `send_change_request_notification`, `send_addon_request_notification` are defined once (Tasks 2/3/7) and referenced with the same names/signatures in Tasks 4/5/6/7. Statuses match the CHECK constraints: addons `requested|confirmed|declined|cancelled`; changes `requested|handled|declined`.
- **Include-scope check:** `includes/booking-manage-actions.php` uses `$hold`, `$ref`, and `$tours` — all defined in `booking.php` before the include, so the variables are in scope. `e()` and `captcha_site_key()` are loaded (db.php / turnstile.php).
