# Admin: Create Booking + Code-Only Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin create a **confirmed** booking from scratch (no enquiry) that yields a guest login code, and make guest login **code-only** (one-tap magic link + manual code entry, no email).

**Architecture:** Extend the existing `create_hold_with_block()` (nullable `submission_id` + a `$status` param) and lengthen access codes to 8 chars. Replace the guest login's `resolve_booking_by_code(code,email)` with a code-only resolver and drop the email field on `booking.php`. Add a new admin page `admin/hold-new.php` (create form + handler) and a "+ New Booking" button on `admin/holds.php`.

**Tech Stack:** Vanilla PHP 8.2, Postgres via PDO (`db_query()`), admin auth (`require_login`/`verify_csrf`/`csrf_field`), `audit_log()`, Turnstile (`verify_captcha`). No framework, no build. No PHP test framework — pure logic via `php`-CLI test scripts, DB paths via `php -r`, admin UI via auth-gate curl + login click-through. No DB migration.

**Key existing facts (verified):**
- `holds.submission_id` is **nullable**; `holds.expires_at` is **NOT NULL** (must always be set).
- `expire_stale_holds()` only expires `status='pending'` holds and only deletes `block_type='hold'` blocks — so a confirmed booking with `block_type='booked'` and a set `expires_at` is safe.
- `create_hold_with_block()` is called by `api/submit-enquiry.php` and `admin/submission-view.php` (convert). Both must keep creating **pending** holds → the `$status` default stays `'pending'`.
- `resolve_booking_by_code(code,email)` has exactly one caller: `booking.php:54`.
- `admin/holds.php` reads `$_SESSION['hold_flash']` for banner messages; that's the flash key to set on redirect-to-holds.
- Local dev: `php -S localhost:8765 router.php` (Neon via `.env`). NO `DATABASE_URL` — use `php -r`, never `psql`. Turnstile keys unset locally → captcha dev-bypass.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `includes/db.php` | `create_hold_with_block()` — nullable `submission_id` + `$status`; `generate_access_code()` default 6→8 |
| `tests/manage_logic.php` | Update the code-length assertions 6→8 |
| `includes/booking.php` | Replace `resolve_booking_by_code()` with `resolve_booking_by_code_only()` |
| `booking.php` | Code-only lookup (drop email field, arg, and copy) |
| `admin/hold-new.php` | New — create-booking form + `action=create` handler |
| `admin/holds.php` | "+ New Booking" button |

---

## Task 1: Helper changes (8-char codes, confirmed holds)

**Files:** Modify `includes/db.php`, `tests/manage_logic.php`

- [ ] **Step 1: Update the code-length assertions first (they must fail before the change)**

In `tests/manage_logic.php`, change the two 6-based assertions to 8:
```php
$code = generate_access_code();
check('code length is 8', strlen($code) === 8);
check('code is uppercase alnum, unambiguous alphabet',
      (bool)preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code));
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php tests/manage_logic.php`
Expected: FAIL on "code length is 8" (default is still 6).

- [ ] **Step 3: Bump `generate_access_code()` default to 8 in `includes/db.php`**

Change the signature default:
```php
function generate_access_code(int $len = 8): string {
```
(Body unchanged — same unambiguous alphabet + `random_int`.)

- [ ] **Step 4: Run the test, confirm PASS**

Run: `php tests/manage_logic.php`
Expected: `ALL PASS` (length now 8).

- [ ] **Step 5: Extend `create_hold_with_block()` in `includes/db.php`**

Read the current function first. Replace its whole body with this (adds nullable `submission_id`, a `$status` param, `confirmed_at`, and a status-dependent `block_type`; the pending default preserves current behavior exactly):
```php
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending'
): int {
    $confirmed = $status === 'confirmed';
    $hold_id = 0;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = generate_access_code();
        try {
            $stmt = db()->prepare(
                "INSERT INTO holds
                    (submission_id, unit_id, check_in, check_out, guest_name, guest_email,
                     access_code, status, confirmed_at, expires_at)
                 VALUES
                    (:sub, :unit, :ci, :co, :name, :email,
                     :code, :status, :confirmed_at, NOW() + INTERVAL '24 hours')
                 RETURNING id"
            );
            $stmt->execute([
                ':sub'          => $submission_id,
                ':unit'         => $unit_id,
                ':ci'           => $check_in,
                ':co'           => $check_out,
                ':name'         => $guest_name,
                ':email'        => $guest_email,
                ':code'         => $code,
                ':status'       => $status,
                ':confirmed_at' => $confirmed ? date('Y-m-d H:i:s') : null,
            ]);
            $hold_id = (int)$stmt->fetchColumn();
            break;
        } catch (PDOException $e) {
            if (($e->getCode() === '23505') && $attempt < 4) continue;
            throw $e;
        }
    }

    db_query(
        "INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, hold_id)
         VALUES (:unit, :df, :dt, :bt, :hold)",
        [':unit' => $unit_id, ':df' => $check_in, ':dt' => $check_out,
         ':bt' => $confirmed ? 'booked' : 'hold', ':hold' => $hold_id]
    );

    return $hold_id;
}
```

- [ ] **Step 6: Verify both paths against the live DB (confirmed = new; pending = regression), then clean up**

NO `DATABASE_URL` — use `php -r`:
```bash
php -r '
require "includes/db.php";
$uid = (int)db()->query("SELECT id FROM units WHERE is_active=TRUE LIMIT 1")->fetchColumn();
// confirmed, no submission
$c = create_hold_with_block($uid, null, "2027-08-01","2027-08-04","Conf Test","conf@example.com","confirmed");
$h = db_query("SELECT status, confirmed_at, submission_id, access_code FROM holds WHERE id=:i",[":i"=>$c])->fetch();
$bt = db_query("SELECT block_type FROM availability_blocks WHERE hold_id=:h",[":h"=>$c])->fetchColumn();
echo "CONFIRMED: status={$h["status"]} confirmed_at=".($h["confirmed_at"]?"set":"null")." sub=".($h["submission_id"]===null?"null":"NOTnull")." codelen=".strlen($h["access_code"])." block={$bt}\n";
// pending regression (no status arg)
$sid = (int)db()->query("SELECT id FROM submissions ORDER BY id LIMIT 1")->fetchColumn();
$p = create_hold_with_block($uid, $sid, "2027-09-01","2027-09-03","Pend Test","pend@example.com");
$hp = db_query("SELECT status FROM holds WHERE id=:i",[":i"=>$p])->fetch();
$btp = db_query("SELECT block_type FROM availability_blocks WHERE hold_id=:h",[":h"=>$p])->fetchColumn();
echo "PENDING:   status={$hp["status"]} block={$btp}\n";
foreach ([$c,$p] as $x){ db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$x]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$x]); }
echo "cleaned up\n";
'
```
Expected: `CONFIRMED: status=confirmed confirmed_at=set sub=null codelen=8 block=booked`; `PENDING: status=pending block=hold`; `cleaned up`.

- [ ] **Step 7: Commit**

```bash
git add includes/db.php tests/manage_logic.php
git commit -m "feat: 8-char codes + create_hold_with_block supports confirmed/manual holds"
```

---

## Task 2: Code-only login

**Files:** Modify `includes/booking.php`, `booking.php`

- [ ] **Step 1: Replace the resolver in `includes/booking.php`**

Find `resolve_booking_by_code(string $code, string $email)`. Replace it with a code-only version (keep the same SELECT/joins, drop the email predicate):
```php
/** Resolve a booking from a typed code alone (case-insensitive). */
function resolve_booking_by_code_only(string $code): array|false {
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    $row = db_query(
        "SELECT h.*, u.name AS unit_name, r.name AS room_name, r.slug AS room_slug,
                v.name AS venue_name
         FROM holds h
         JOIN units u  ON u.id = h.unit_id
         JOIN rooms r  ON r.id = u.room_id
         LEFT JOIN venues v ON v.id = r.venue_id
         WHERE h.access_code = :code",
        [':code' => $code]
    )->fetch();
    return $row ?: false;
}
```

- [ ] **Step 2: Update `booking.php` lookup handler (code-only)**

In `booking.php`, the `do=lookup` POST handler currently calls `resolve_booking_by_code($_POST['code'] ?? '', $_POST['email'] ?? '')` and sets the error "We couldn't find a booking with that code and email." Change to:
```php
        $found = resolve_booking_by_code_only($_POST['code'] ?? '');
```
and the error message to:
```php
            $lookupError = 'We couldn’t find a booking with that code.';
```
(Leave the rate-limit + `verify_captcha` logic exactly as-is.)

- [ ] **Step 3: Drop the email field from the lookup form in `booking.php`**

Find the lookup `<form … do=lookup>` (it currently has a code input AND an email input). Remove the **email** `<label>/<input>` block and update the intro copy so it asks only for the code (e.g. "Enter the booking code from your confirmation."). Leave the code input, the `.cf-turnstile` widget, and the submit button.

- [ ] **Step 4: Verify code-only resolution + login**

`php -l booking.php includes/booking.php` → clean.

Resolver, against a real hold:
```bash
php -r 'require "includes/booking.php"; $h=db_query("SELECT access_code FROM holds LIMIT 1")->fetch(); $r=resolve_booking_by_code_only($h["access_code"]); echo ($r?"code-only OK: ".$r["room_name"]:"MISS")."\n"; echo (resolve_booking_by_code_only("ZZZZZZZZ")?"LEAK":"bad-code correctly-miss")."\n"; echo (resolve_booking_by_code_only("")?"EMPTY-LEAK":"empty correctly-miss")."\n";'
```
Expected: `code-only OK: <room>`, `bad-code correctly-miss`, `empty correctly-miss`.

Login over HTTP (Turnstile dev-bypass locally): start `php -S localhost:8765 router.php`, then with `$CODE` = a real code:
```bash
CODE=$(php -r 'require "includes/db.php"; echo db()->query("SELECT access_code FROM holds LIMIT 1")->fetchColumn();')
curl -s -X POST "http://localhost:8765/booking.php" --data "do=lookup&code=$CODE" | grep -c "Booking code"   # expect >=1 (logged in, booking rendered)
curl -s "http://localhost:8765/booking.php" | grep -ci "email"                                               # lookup form: no email field prompt (spot-check; 0 ideal but verify the input[name=email] is gone)
curl -s -X POST "http://localhost:8765/booking.php" --data "do=lookup&code=ZZZZZZZZ" | grep -ci "couldn"      # expect >=1 generic error
```
Then stop the server. (The middle check is a spot-check; the definitive check is that `name="email"` no longer appears in the lookup form — `curl -s http://localhost:8765/booking.php | grep -c 'name="email"'` → expect 0.)

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php booking.php
git commit -m "feat: code-only guest login (drop email requirement)"
```

---

## Task 3: Admin "New Booking" page + button

**Files:** Create `admin/hold-new.php`; modify `admin/holds.php`

- [ ] **Step 1: Create `admin/hold-new.php`**

Mirror the conventions in `admin/submission-view.php` (auth, session, `_layout`, `csrf_field`, `e()`). On validation error it re-renders the form inline (preserving input); on success it sets `$_SESSION['hold_flash']` and redirects to `/admin/holds.php`.

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$error = '';
$old   = ['unit_id' => '', 'check_in' => '', 'check_out' => '', 'guest_name' => '', 'guest_email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $str      = fn($v) => is_scalar($v) ? trim((string)$v) : '';
    $unit_id  = (int)$str($_POST['unit_id'] ?? '');
    $check_in = $str($_POST['check_in']  ?? '');
    $check_out= $str($_POST['check_out'] ?? '');
    $g_name   = $str($_POST['guest_name']  ?? '');
    $g_email  = $str($_POST['guest_email'] ?? '');
    $old = ['unit_id' => $unit_id ?: '', 'check_in' => $check_in, 'check_out' => $check_out,
            'guest_name' => $g_name, 'guest_email' => $g_email];

    $is_date  = fn($d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    $unit_ok  = $unit_id > 0 && db_query("SELECT 1 FROM units WHERE id = :id AND is_active = TRUE", [':id' => $unit_id])->fetchColumn();

    if (!$unit_ok)                                        $error = 'Please choose a valid room / unit.';
    elseif (!$is_date($check_in) || !$is_date($check_out)) $error = 'Please enter valid check-in and check-out dates.';
    elseif ($check_in >= $check_out)                     $error = 'Check-out must be after check-in.';
    elseif ($g_name === '')                              $error = 'Guest name is required.';
    elseif (!filter_var($g_email, FILTER_VALIDATE_EMAIL)) $error = 'A valid guest email is required.';

    if (!$error) {
        try {
            $hold_id = create_hold_with_block($unit_id, null, $check_in, $check_out, $g_name, $g_email, 'confirmed');
        } catch (Throwable $e) {
            error_log('[hold-new] create failed: ' . $e->getMessage());
            $error = 'Could not create the booking. Please try again.';
        }
        if (!$error) {
            $code = (string)db_query("SELECT access_code FROM holds WHERE id = :id", [':id' => $hold_id])->fetchColumn();
            try {
                audit_log('hold.create_manual', 'hold', $hold_id, "manual booking — {$g_name} {$check_in}→{$check_out}");
            } catch (Throwable $e) { error_log('[hold-new] audit failed: ' . $e->getMessage()); }
            $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => "Booking #{$hold_id} created for {$g_name} — code {$code}."];
            header('Location: /admin/holds.php'); exit;
        }
    }
}

$ru_options = fetch_room_unit_options();

$pageTitle  = 'New Booking';
$activeMenu = 'holds';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>New Booking</h1>
  <div class="actions"><a href="/admin/holds.php" class="btn-outline btn-sm">← Holds</a></div>
</div>

<?php if ($error): ?>
<div class="card" style="border-left:4px solid var(--red,#dc2626);margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px"><?= e($error) ?></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Create a confirmed booking</span></div>
  <div class="card__body" style="padding:20px">
    <?php if (!$ru_options): ?>
      <p style="margin:0;color:var(--muted)">No availability units exist yet — add units to a room first (Rooms admin).</p>
    <?php else: ?>
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a confirmed booking, generates the guest's login code, and blocks the dates. Availability is not checked — you control overlaps.</p>
    <form method="POST" action="/admin/hold-new.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="detail-grid">
        <div>
          <div class="detail-item__label">Room / Unit</div>
          <select name="unit_id" required style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
            <option value="">— select —</option>
            <?php foreach ($ru_options as $o): ?>
            <option value="<?= (int)$o['unit_id'] ?>" <?= (int)$o['unit_id'] === (int)$old['unit_id'] ? 'selected' : '' ?>>
              <?= e($o['room_name']) ?> — <?= e($o['unit_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="detail-item__label">Check-in</div>
          <input type="date" name="check_in" required value="<?= e($old['check_in']) ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Check-out</div>
          <input type="date" name="check_out" required value="<?= e($old['check_out']) ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest name</div>
          <input type="text" name="guest_name" required value="<?= e($old['guest_name']) ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
        <div>
          <div class="detail-item__label">Guest email</div>
          <input type="email" name="guest_email" required value="<?= e($old['guest_email']) ?>"
                 style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px">
        </div>
      </div>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:16px"
              onclick="return confirm('Create this confirmed booking?')">Create Booking</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

> Read `admin/submission-view.php` / `admin/holds.php` first to confirm `_layout.php` expects `$pageTitle` + `$activeMenu`, and that `csrf_field()`/`verify_csrf()`/`audit_log()`/`fetch_room_unit_options()` are the correct names (all used elsewhere in admin). Adjust only if the real names differ.

- [ ] **Step 2: Add the "+ New Booking" button to `admin/holds.php`**

Read `admin/holds.php` and find the top page-header / actions area (near the KPIs or the `<h1>`). Add a button linking to the new page, matching the existing button classes (`btn-primary btn-sm` or `btn-outline btn-sm`):
```php
<a href="/admin/hold-new.php" class="btn-primary btn-sm">+ New Booking</a>
```
Place it in the header actions so it sits with the page title. Match surrounding markup; don't break the layout.

- [ ] **Step 3: Verify**

`php -l admin/hold-new.php admin/holds.php` → clean.

Auth-gate + button, with the dev server running:
```bash
php -S localhost:8765 router.php &   # background
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8765/admin/hold-new.php"                 # expect 302 (login)
curl -s -o /dev/null -w "%{http_code}\n" -X POST "http://localhost:8765/admin/hold-new.php" --data "action=create&unit_id=1"  # expect 302 (no booking created unauthenticated)
```
Confirm the button is present in the holds template source:
`grep -c "hold-new.php" admin/holds.php` → ≥1.
Stop the server.

- [ ] **Step 4: Commit**

```bash
git add admin/hold-new.php admin/holds.php
git commit -m "feat(admin): + New Booking page (create confirmed booking with code)"
```

---

## Task 4: End-to-end (admin login) + regression + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Test suites + lint**

```bash
php tests/manage_logic.php        # ALL PASS (code length 8)
php tests/convert_logic.php       # ALL PASS
```

- [ ] **Step 2: Full admin flow (requires admin login in the browser)**

Log into `/admin`. On the Holds page click **+ New Booking**. Fill Room/Unit + dates + guest name + email → **Create Booking** → confirm. Expect: redirect to Holds with a green flash **"Booking #N created for … — code XXXXXXXX."**, and the new booking shows in the list as **confirmed** with its **code + Copy portal link**.

- [ ] **Step 3: Code-only guest login (the point of the feature)**

Copy the new booking's code. In a private window open `/booking.php`, enter just the **code** (no email field should be present) → the booking loads. Also click the **Copy portal link** (magic link) → it logs straight in. A wrong code → generic "couldn't find" error.

- [ ] **Step 4: Regression — public + convert still create pending holds**

- Make a booking via the public widget (an availability room) → it's still a **pending** hold (unchanged).
- Convert an enquiry (submission view) → still a **pending** hold.
(Confirms the `$status` default preserved existing behavior.)

- [ ] **Step 5: Clean up any bookings created during E2E**

For each test booking id `<HID>`:
```bash
php -r '$h=(int)($argv[1]??0); if(!$h){echo "pass id\n";exit;} require "includes/db.php"; db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]); echo "removed $h\n";' <HID>
```

- [ ] **Step 6: Confirm no stray test data + final lint**

```bash
php -r 'require "includes/db.php"; var_dump(db_query("SELECT id,guest_email FROM holds WHERE guest_email IN (:a,:b,:c)", [":a"=>"conf@example.com",":b"=>"pend@example.com",":c"=>"newbooking@example.com"])->fetchAll(PDO::FETCH_ASSOC));'
```
Expected: empty array.

---

## Self-Review Notes (author checklist)

- **Spec coverage:** admin create-confirmed booking (Task 3 + Task 1 `$status`), nullable submission (Task 1), 8-char codes (Task 1), code-only resolver + login form (Task 2), "+ New Booking" button (Task 3), flash with code on Holds (Task 3 sets `hold_flash`), non-goals respected (no phone/guest-count, force-create, no auto-email). ✔
- **Regression guard:** Task 1 Step 6 + Task 4 Step 4 explicitly verify the pending path (public widget + convert) is unchanged — the riskiest part of touching the shared `create_hold_with_block`.
- **Gotcha handled:** `generate_access_code` default change breaks `tests/manage_logic.php`'s length-6 assertions → Task 1 Steps 1–4 update them to 8 first (TDD order).
- **Type/signature consistency:** `create_hold_with_block(int, ?int, string, string, string, string, string='pending'): int`; `generate_access_code(int=8): string`; `resolve_booking_by_code_only(string): array|false`; `fetch_room_unit_options(): array`; `audit_log(string,string,int,string)`; `create_hold_with_block(..., 'confirmed')` used in Task 3.
- **Flash keys:** `admin/holds.php` reads `$_SESSION['hold_flash']` → Task 3 sets exactly that on success; validation errors render inline on `hold-new.php` (no cross-page flash needed).
- **No placeholders; all code shown; CSRF + `e()` + `is_scalar` hardening throughout; force-create is intentional (no availability check).**
