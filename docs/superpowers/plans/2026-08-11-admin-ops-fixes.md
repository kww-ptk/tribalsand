# Admin Ops Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-created bookings start pending without self-destructing, the guest board stops silently discarding event dates and prices, and the gate can sign in an arriving guest in one tap.

**Architecture:** `create_hold_with_block()` gains an optional expiry so a staff-typed booking can be pending with `expires_at = NULL`. That NULL is then the signal `frontdesk_rows()` uses to admit staff bookings to the arrivals board while still excluding speculative web enquiries. The guest board's event-only fields become conditional on the category, backed by a server-side validation error instead of a silent null. The gate's arrivals table renders data it already fetches and prefills the existing visitor form.

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO, vanilla JS, vanilla CSS. Tests are plain PHP scripts with a `check()` helper. **One migration** — `add_hold_no_expiry.sql`, already written and applied locally (see Task 1 Step 0).

**Spec:** `docs/superpowers/specs/2026-08-11-admin-ops-fixes-design.md`

---

## Before you start

Read the spec. Then read `includes/db.php:270-320` (`create_hold_with_block`), `includes/frontdesk.php:25-70` (`frontdesk_rows`), and `admin/guest-board.php`.

**Conventions** (from `CLAUDE.md`):

- Escape all output with `e()`. `db_query()` with bound parameters only.
- Migration-added columns sit behind `*_supported()` guards.
- No build step — edit CSS and JS directly.

**A timezone trap specific to this plan.** This local database runs at **UTC-04** while PHP runs at **UTC** (`SELECT now()` → `18:27-04` when `date()` → `22:27`). Never compute a timestamp in PHP and store it as if it were a DB time — a four-hour skew silently appears. All expiry arithmetic in Task 1 stays in SQL against `NOW()`.

**Baseline:**

```bash
php tests/frontdesk_logic.php && php tests/manage_logic.php
```

Both must end `ALL PASS`. `php tests/team_logic.php` has **two** known failures (`owner: home = dashboard`, `signed-out visitor stays in today log`) that also fail on `master` — ignore them, do not try to fix them.

**Committing:** the tree has unrelated pre-existing changes (`.claude/launch.json`, two untracked `Archive*.zip`). **NEVER `git add -A` or `git add .`.**

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `includes/db.php` | `create_hold_with_block()` optional expiry | Modify |
| `admin/hold-new.php` | Create pending, no expiry, corrected copy | Modify |
| `admin/holds.php` | "Awaiting confirmation" state | Modify |
| `includes/frontdesk.php` | Status predicate + select `h.status` | Modify |
| `admin/gate.php` | Arrivals columns + prefilled sign-in | Modify |
| `admin/frontdesk.php` | Pending badge | Modify |
| `admin/guest-board.php` | Conditional fields + validation | Modify |
| `tests/frontdesk_logic.php` | Assertions (the DB-backed harness) | Modify |

---

### Task 1: Optional expiry on hold creation

**Files:** `db/migrations/add_hold_no_expiry.sql`, `includes/db.php`, `tests/frontdesk_logic.php`

- [ ] **Step 0: The migration (already written and applied)**

`holds.expires_at` was `TIMESTAMP NOT NULL` (`db/migrations/add_availability.sql:32`) — every hold
used to be a 24h web enquiry — so an `expires_at = NULL` insert is rejected by the database. This
plan originally missed that and claimed no migration was needed.

`db/migrations/add_hold_no_expiry.sql` exists and has been applied to the local database. Confirm
before going further:

```bash
php -r 'require "includes/db.php"; echo db_query("SELECT is_nullable FROM information_schema.columns WHERE table_name=:t AND column_name=:c",[":t"=>"holds",":c"=>"expires_at"])->fetchColumn(), "\n";'
```

Expected: `YES`. If it prints `NO`, run `php bin/migrate.php db/migrations/add_hold_no_expiry.sql`
first. Include the migration file in the Task 1 commit.

- [ ] **Step 1: Write the failing test**

`tests/frontdesk_logic.php` is DB-backed and already creates holds. Find this line near the top:

```php
$hWeekOut = $mk($d($A,10),     $d($A, 12));  // arrival outside the 7-day window
```

Insert this immediately after it:

```php
// ── Staff-created bookings: pending, and never auto-expire ──────────────────
$hNoExp = create_hold_with_block($unit, null, $d($A, 20), $d($A, 22), 'ZZ FD NoExpiry', 'zz-noexp@example.com', 'pending', null);
$rowNE  = db_query("SELECT status, expires_at FROM holds WHERE id = :i", [':i' => $hNoExp])->fetch();
check('null expiry stores NULL',        $rowNE['expires_at'] === null);
check('null expiry keeps it pending',   $rowNE['status'] === 'pending');
check('its availability block exists',  (int)db_query("SELECT COUNT(*) FROM availability_blocks WHERE hold_id = :i", [':i' => $hNoExp])->fetchColumn() > 0);
// Assert the cron's PREDICATE rather than calling expire_stale_holds(): that
// function sweeps every stale hold in the database, deletes their blocks and
// EMAILS THEIR GUESTS. A test must never do that. NULL < NOW() is NULL, so the
// predicate cannot match — which is exactly the property we depend on.
$wouldExpire = (int)db_query(
    "SELECT COUNT(*) FROM holds WHERE id = :i AND status = 'pending' AND expires_at < NOW()",
    [':i' => $hNoExp]
)->fetchColumn();
check('expiry predicate cannot match it', $wouldExpire === 0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/frontdesk_logic.php`
Expected: `FAIL  null expiry stores NULL` — the 8th positional argument is ignored today, so a 24h expiry is written. Confirm you see the FAIL before proceeding.

- [ ] **Step 3: Add the parameter**

In `includes/db.php`, replace the signature and INSERT of `create_hold_with_block()`. Replace:

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
```

with:

```php
/**
 * $expiresInHours: NULL = the hold never auto-expires. Staff-typed bookings use
 * NULL so expire_stale_holds() cannot cancel them overnight and free the dates —
 * its predicate (expires_at < NOW()) is NULL for a NULL column and never matches.
 * The interval is built in SQL against NOW() on purpose: the app and database
 * clocks are not in the same timezone, so computing it in PHP would skew it.
 */
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending',
    ?int $expiresInHours = 24
): int {
    $confirmed = $status === 'confirmed';
    $expiresExpr = $expiresInHours === null ? 'NULL' : 'NOW() + make_interval(hours => :exph)';
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
                     :code, :status, :confirmed_at, {$expiresExpr})
                 RETURNING id"
            );
            $params = [
                ':sub'          => $submission_id,
                ':unit'         => $unit_id,
                ':ci'           => $check_in,
                ':co'           => $check_out,
                ':name'         => $guest_name,
                ':email'        => $guest_email,
                ':code'         => $code,
                ':status'       => $status,
                ':confirmed_at' => $confirmed ? date('Y-m-d H:i:s') : null,
            ];
            if ($expiresInHours !== null) $params[':exph'] = $expiresInHours;
            $stmt->execute($params);
```

`$expiresExpr` is built from a strict `=== null` test on an `?int` parameter, never from user input — there is no injection surface.

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/frontdesk_logic.php`
Expected: the four new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Confirm the default path is unchanged**

The two existing callers rely on the 24h default. Verify it still applies and uses the **database** clock:

```bash
php -r '
require "includes/db.php";
$u = (int)db_query("SELECT id FROM units WHERE is_active LIMIT 1")->fetchColumn();
$h = create_hold_with_block($u, null, "2031-07-01", "2031-07-03", "ZZ Default", "zz@example.com");
$r = db_query("SELECT status, expires_at, expires_at - NOW() AS ttl FROM holds WHERE id=:i", [":i"=>$h])->fetch();
printf("status=%s ttl=%s\n", $r["status"], $r["ttl"]);
db_query("DELETE FROM availability_blocks WHERE hold_id=:i",[":i"=>$h]);
db_query("DELETE FROM holds WHERE id=:i",[":i"=>$h]);'
```

Expected: `status=pending` and a ttl of approximately `24:00:00`. **If the ttl is near 20 or 28 hours you have introduced the timezone skew** — the interval must come from SQL, not PHP.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/add_hold_no_expiry.sql includes/db.php tests/frontdesk_logic.php
git commit -m "feat(holds): allow a hold to be created with no expiry"
```

---

### Task 2: Admin bookings start pending

**Files:** `admin/hold-new.php`, `admin/holds.php`

- [ ] **Step 1: Create as pending with no expiry**

In `admin/hold-new.php`, replace:

```php
            $hold_id = create_hold_with_block($unit_id, null, $check_in, $check_out, $g_name, $g_email, 'confirmed');
```

with:

```php
            // Pending, and no TTL: a booking staff typed in must not be expired by
            // the cron overnight the way an unattended web enquiry is.
            $hold_id = create_hold_with_block($unit_id, null, $check_in, $check_out, $g_name, $g_email, 'pending', null);
```

- [ ] **Step 2: Correct the page copy**

Three places in `admin/hold-new.php` still say "confirmed". Replace:

```php
  <div class="card__head"><span class="card__title">Create a confirmed booking</span></div>
```

with:

```php
  <div class="card__head"><span class="card__title">Create a booking</span></div>
```

Replace:

```php
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a confirmed booking, generates the guest's login code, and blocks the dates. Availability is not checked — you control overlaps.</p>
```

with:

```php
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Creates a <strong>pending</strong> booking, generates the guest's login code, and blocks the dates. It will not expire — confirm it from Holds when you're ready. Availability is not checked — you control overlaps.</p>
```

Replace:

```php
              onclick="return confirm('Create this confirmed booking?')">Create Booking</button>
```

with:

```php
              onclick="return confirm('Create this pending booking?')">Create Booking</button>
```

- [ ] **Step 3: Make the new state legible in the Holds list**

With `expires_at` NULL and status pending, every branch of the timing column falls through and the cell renders blank. In `admin/holds.php`, replace:

```php
          $expires_str = '';
          if ($status === 'pending' && $hold['expires_at']) {
```

with:

```php
          $expires_str = '';
          if ($status === 'pending' && !$hold['expires_at']) {
              $expires_str = 'Awaiting confirmation';   // staff-created: no TTL
          } elseif ($status === 'pending' && $hold['expires_at']) {
```

- [ ] **Step 4: Verify**

Run: `php -l admin/hold-new.php && php -l admin/holds.php`
Expected: no syntax errors.

Then exercise the real POST path and confirm what lands:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="POST";
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/checkin.php"; require_once "includes/icons.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
$_SESSION["csrf_token"]=bin2hex(random_bytes(16));
$u=(int)db_query("SELECT id FROM units WHERE is_active LIMIT 1")->fetchColumn();
$_POST=["csrf_token"=>$_SESSION["csrf_token"],"action"=>"create","unit_id"=>(string)$u,
        "check_in"=>"2031-08-01","check_out"=>"2031-08-04","guest_name"=>"ZZ Pending Test",
        "guest_email"=>"zz-pending@example.com","guest_count"=>"2"];
register_shutdown_function(function(){
  $row=db_query("SELECT id,status,expires_at FROM holds WHERE guest_email=:e ORDER BY id DESC LIMIT 1",[":e"=>"zz-pending@example.com"])->fetch();
  if(!$row){echo "no hold created\n";return;}
  printf("status=%s expires_at=%s\n", $row["status"], $row["expires_at"] === null ? "NULL (never expires)" : $row["expires_at"]);
  db_query("DELETE FROM availability_blocks WHERE hold_id=:i",[":i"=>$row["id"]]);
  db_query("DELETE FROM holds WHERE id=:i",[":i"=>$row["id"]]);
  echo "cleaned up\n";
});
ob_start(); include "admin/hold-new.php";'
```

Expected: `status=pending expires_at=NULL (never expires)`.

- [ ] **Step 5: Commit**

```bash
git add admin/hold-new.php admin/holds.php
git commit -m "feat(admin): create bookings as pending with no expiry"
```

---

### Task 3: Staff-created pending bookings reach the operational boards

**Files:** `includes/frontdesk.php`, `tests/frontdesk_logic.php`

- [ ] **Step 1: Write the failing tests**

The distinction that matters: a staff booking (no expiry) must appear, a web enquiry (with expiry) must not. In `tests/frontdesk_logic.php`, find the line you added in Task 1:

```php
check('its availability block survives', (int)db_query("SELECT COUNT(*) FROM availability_blocks WHERE hold_id = :i", [':i' => $hNoExp])->fetchColumn() > 0);
```

Insert this immediately after it:

```php
// ── Which pending holds are operational ─────────────────────────────────────
// Staff-typed bookings (no TTL) belong on the board; speculative web enquiries
// (24h TTL) do not, or the arrivals list fills with bookings that never convert.
$mkPending = function(string $ci, string $co, ?int $ttl) use ($unit): int {
    return create_hold_with_block($unit, null, $ci, $co, 'ZZ FD Pending', 'zz-p@example.com', 'pending', $ttl);
};
$hStaff   = $mkPending($A, $d($A, 2), null);   // staff-typed: no expiry
$hEnquiry = $mkPending($A, $d($A, 2), 24);     // web enquiry: 24h TTL
$dayP = frontdesk_day([$venue], $A);
check('staff-created pending arrives',   in_array($hStaff,   ids($dayP['arriving']), true));
check('web enquiry pending is excluded', !in_array($hEnquiry, ids($dayP['arriving']), true));
check('confirmed still arrives',         in_array($hArrive,   ids($dayP['arriving']), true));
check('rows carry status for badging',   array_key_exists('status', $dayP['arriving'][0]));
db_query("UPDATE holds SET status='cancelled' WHERE id = :i", [':i' => $hStaff]);
$dayC = frontdesk_day([$venue], $A);
check('cancelled is excluded',           !in_array($hStaff, ids($dayC['arriving']), true));
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/frontdesk_logic.php`
Expected: `FAIL  staff-created pending arrives` and `FAIL  rows carry status for badging`. The web-enquiry and confirmed lines should already pass.

- [ ] **Step 3: Widen the predicate and select the status**

In `includes/frontdesk.php`, replace:

```php
    $where = ["h.status = 'confirmed'", $datePredicate];
```

with:

```php
    // Confirmed bookings, plus staff-typed pending ones. The absence of a TTL is
    // what separates a booking someone typed in (admin/hold-new.php passes NULL)
    // from a speculative web enquiry, which always carries a 24h expiry.
    $where = ["(h.status = 'confirmed' OR (h.status = 'pending' AND h.expires_at IS NULL))", $datePredicate];
```

Then in the same function's SELECT, replace:

```php
            "SELECT h.id, h.guest_name, h.check_in, h.check_out, h.access_code,
```

with:

```php
            "SELECT h.id, h.guest_name, h.check_in, h.check_out, h.access_code, h.status,
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/frontdesk_logic.php`
Expected: all five new lines PASS, run ends `ALL PASS`.

- [ ] **Step 5: Report on the KPI change**

`frontdesk_day()`'s `kpi_inhouse` is `count(arriving) + count(inhouse)`, so staff-created pending bookings now count toward expected occupancy. The spec accepts this deliberately. **Confirm by reading that no other caller of `frontdesk_rows()`, `frontdesk_day()` or `frontdesk_week()` treats the result as "confirmed bookings" in a way this breaks** — grep for all callers and report what each does with the rows.

- [ ] **Step 6: Commit**

```bash
git add includes/frontdesk.php tests/frontdesk_logic.php
git commit -m "feat(frontdesk): show staff-created pending bookings on the boards"
```

---

### Task 4: Guest board stops discarding event dates and prices

**Files:** `admin/guest-board.php`

- [ ] **Step 1: Add the validation**

In `admin/guest-board.php`, replace:

```php
        // Date/price are events-only — keep them off other categories.
        if ($category !== 'event') { $eventDate = null; $priceAmt = null; }
```

with:

```php
        // Date/price are events-only. Previously a non-event silently nulled both,
        // so an owner who filled them in without noticing the Category dropdown
        // (which defaults to "Update" on a new post) lost the data and got a
        // "Post created" flash. Refuse instead of discarding.
        if ($category !== 'event' && ($evRaw !== '' || $priceRaw !== '')) {
            $errs[] = 'Pick the Event category to set a date or price.';
        }
        if ($category !== 'event') { $eventDate = null; $priceAmt = null; }
```

- [ ] **Step 2: Make the fields follow the category**

Replace the event-date field block:

```php
      <div class="field">
        <label>Event date &amp; time <span class="text-muted" style="font-weight:400">(events only)</span></label>
```

with:

```php
      <div class="field gb-eventonly"<?= (($edit['category'] ?? '') === 'event') ? '' : ' hidden' ?>>
        <label>Event date &amp; time</label>
```

Then replace the price field's opening tag and label:

```php
        <div class="field">
          <label for="gbPrice">Price <span class="text-muted" style="font-weight:400">(events only — blank = free)</span></label>
```

with:

```php
        <div class="field gb-eventonly"<?= (($edit['category'] ?? '') === 'event') ? '' : ' hidden' ?>>
          <label for="gbPrice">Price <span class="text-muted" style="font-weight:400">(blank = free)</span></label>
```

Server-rendered `hidden` means first paint is correct with no JavaScript. `.field` carries only `margin-bottom` in `admin/assets/admin.css:266` — no `display` rule — so the `[hidden]` attribute works without a CSS override.

- [ ] **Step 3: Toggle on category change**

In `admin/guest-board.php`'s inline `<script>`, find:

```php
      var form = document.getElementById('gbForm');
      if (!form) return;
```

Insert this immediately after it:

```php
      // The date and price fields belong to the Event category — show them only
      // then, so the values can never be typed into a post that would drop them.
      var gbCat = form.querySelector('select[name="category"]');
      function gbSyncEventFields() {
        var on = gbCat && gbCat.value === 'event';
        form.querySelectorAll('.gb-eventonly').forEach(function (el) { el.hidden = !on; });
      }
      if (gbCat) gbCat.addEventListener('change', gbSyncEventFields);
      gbSyncEventFields();
```

- [ ] **Step 4: Verify the fix and the regression**

Run: `php -l admin/guest-board.php`
Expected: no syntax errors.

Then run both cases through the real POST path:

```bash
php -r '
$_SERVER["REQUEST_METHOD"]="POST";
require_once "includes/auth.php"; require_once "includes/db.php"; require_once "includes/icons.php";
session_init();
$r=db_query("SELECT id FROM admin_users WHERE role=:r LIMIT 1",[":r"=>"owner"])->fetch();
$_SESSION["admin_id"]=(int)$r["id"]; $_SESSION["admin_role"]="owner";
$_SESSION["csrf_token"]=bin2hex(random_bytes(16));
$case = getenv("CASE");
$_POST=["csrf_token"=>$_SESSION["csrf_token"],"action"=>"save","id"=>"0","venue_id"=>"",
        "category"=>$case,"title"=>"ZZ Board Test","body"=>"x",
        "event_date"=>"2031-09-15T19:00","price_amount"=>"2500","sort_order"=>"0","is_published"=>"1"];
register_shutdown_function(function() use ($case) {
  $row=db_query("SELECT id,category,event_date,price_amount FROM guest_board_posts WHERE title=:t ORDER BY id DESC LIMIT 1",[":t"=>"ZZ Board Test"])->fetch();
  echo "category posted: $case\n";
  echo "  saved: " . ($row ? "id={$row["id"]} date=".var_export($row["event_date"],true)." price=".var_export($row["price_amount"],true) : "NOTHING (rejected)") . "\n";
  if ($row) db_query("DELETE FROM guest_board_posts WHERE id=:i",[":i"=>$row["id"]]);
});
ob_start(); include "admin/guest-board.php";'
```

Run it twice: `CASE=event php -r ...` and `CASE=update php -r ...` (export CASE before each run).

Expected:
- `CASE=event` → saved with the date and price intact.
- `CASE=update` → **NOTHING (rejected)**. Previously this saved a dateless, priceless post and reported success.

Report both outputs.

- [ ] **Step 5: Commit**

```bash
git add admin/guest-board.php
git commit -m "fix(admin): stop the guest board silently discarding event dates and prices"
```

---

### Task 5: Gate arrivals you can act on

**Files:** `admin/gate.php`, `admin/frontdesk.php`

- [ ] **Step 1: Load the check-in helpers**

`admin/gate.php` does not currently require `includes/checkin.php`, so `checkin_badge()` would be undefined. In `admin/gate.php`, replace:

```php
require_once __DIR__ . '/../includes/frontdesk.php';
```

with:

```php
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';   // checkin_badge() on arrival rows
```

- [ ] **Step 2: Rebuild the arrivals table**

In `admin/gate.php`, replace the whole arrivals card body:

```php
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Property</th><th>Room</th></tr></thead>
        <tbody>
          <?php if (!$day['arriving']): ?>
          <tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--muted)">No arrivals.</td></tr>
          <?php else: foreach ($day['arriving'] as $r): ?>
          <tr><td><strong><?= e($r['guest_name'] ?? 'Guest') ?></strong></td><td><?= e($r['venue_name'] ?? '') ?></td><td class="text-muted"><?= e($r['room_name'] ?? '') ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
```

with:

```php
      <table class="data-table">
        <thead><tr><th>Guest</th><th>Room</th><th>Party</th><th></th></tr></thead>
        <tbody>
          <?php if (!$day['arriving']): ?>
          <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--muted)">No arrivals.</td></tr>
          <?php else: foreach ($day['arriving'] as $r): ?>
          <?php
            $__n     = (int)($r['guest_count'] ?? 0);
            $__badge = checkin_badge($r);
            $__vid   = (int)($r['venue_id'] ?? 0);
            $__room  = trim(((string)($r['venue_name'] ?? '')) . ' · ' . ((string)($r['room_name'] ?? '')), ' ·');
          ?>
          <tr>
            <td><strong><?= e($r['guest_name'] ?? 'Guest') ?></strong>
              <?php if (($r['status'] ?? '') !== 'confirmed'): ?> <span class="badge badge--orange">Pending</span><?php endif; ?>
            </td>
            <td class="text-muted"><?= e($__room) ?></td>
            <td>
              <?= $__n > 0 ? e($__n . ' adult' . ($__n === 1 ? '' : 's')) : '<span class="text-muted">—</span>' ?>
              <?php if ($__badge): ?><br><span class="ci-badge <?= e($__badge['class']) ?>"><?= e($__badge['label']) ?></span><?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php if (visitors_supported() && in_array($__vid, $scopedVenueIds, true)): ?>
              <button type="button" class="btn-sm btn-outline gate-signin"
                      data-name="<?= e((string)($r['guest_name'] ?? '')) ?>"
                      data-venue="<?= $__vid ?>"
                      data-room="<?= e($__room) ?>">Sign in</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
```

Property and Room are merged into one column to keep four columns readable on the gate's likely-small screen.

- [ ] **Step 3: Prefill the sign-in form**

In `admin/gate.php`'s inline `<script>`, find:

```php
  // Gate-log date filter — auto-submit when a date is picked.
```

Insert this immediately **before** that comment:

```php
  // One-tap sign-in for an arriving booking: open the existing visitor form with
  // everything we already know filled in, leaving only the plate to type.
  document.querySelectorAll('.gate-signin').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!card) return;
      card.style.display = '';
      if (btn) btn.setAttribute('aria-expanded', 'true');
      var set = function (name, val) { var el = card.querySelector('[name="' + name + '"]'); if (el) el.value = val; };
      set('visitor_name', b.getAttribute('data-name') || '');
      set('venue_id',     b.getAttribute('data-venue') || '');
      set('visiting',     b.getAttribute('data-room') || '');
      set('purpose',      'Guest arrival');
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var veh = card.querySelector('[name="vehicle"]');
      if (veh) veh.focus();
    });
  });
```

`card` and `btn` are already declared at the top of that script for the existing toggle — reuse them, do not redeclare.

- [ ] **Step 4: Badge pending arrivals on the Front Desk too**

`admin/frontdesk.php` already requires `includes/checkin.php`, and renders every reservation
through a single `$card` closure — so one edit covers all its tabs.

Replace this line (in the `$card` closure, around line 76):

```php
      <div class="fd-card__name"><?= $name ?></div>
```

with:

```php
      <div class="fd-card__name"><?= $name ?><?php if (($r['status'] ?? '') !== 'confirmed'): ?> <span class="badge badge--orange" style="margin-left:6px;vertical-align:middle">Pending</span><?php endif; ?></div>
```

`$name` is already escaped at the top of the closure (`$name = e(...)`) — do not wrap it again.

- [ ] **Step 5: Verify**

Run: `php -l admin/gate.php && php -l admin/frontdesk.php`
Expected: no syntax errors.

**Do NOT attempt browser verification** — I will do the walkthrough myself in Task 6.

**Reason about and report on these:**
- **i.** `checkin_badge($r)` expects `require_checkin`, `checkin_completed_at`, `guest_count` and `ci_complete_count`. `frontdesk_rows()` only selects those when `checkin_supported()`. What does `checkin_badge()` return when they are all absent, and does the row still render?
- **ii.** The Sign in button only renders when the arrival's venue is in `$scopedVenueIds`. Is `venue_id` actually selected by `frontdesk_rows()`? Check the SELECT — if it is aliased differently the guard would hide every button.
- **iii.** The prefilled `venue_id` is written into a `<select>`. If the value is not one of its options nothing is selected. Can an arrival's venue ever be absent from that select? Trace how `$venues` is built.

- [ ] **Step 6: Commit**

```bash
git add admin/gate.php admin/frontdesk.php
git commit -m "feat(gate): show party size and sign in an arriving guest in one tap"
```

---

### Task 6: Full verification

**Files:** none — this task only runs and observes.

- [ ] **Step 1: Every suite**

```bash
for f in tests/*_logic.php; do printf "%-30s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done
```

Expected: all `ALL PASS` except `team_logic.php`, which has two known pre-existing failures.

- [ ] **Step 2: Lint**

```bash
for f in includes/db.php includes/frontdesk.php admin/hold-new.php admin/holds.php \
         admin/gate.php admin/frontdesk.php admin/guest-board.php; do php -l "$f"; done
```

Expected: `No syntax errors detected` seven times.

- [ ] **Step 3: Report**

Summarise what passed and what did not, plus `git log --oneline` for the tasks. Do **not** claim success for anything you did not run.

---

## Notes for the implementer

- **Never compute an expiry timestamp in PHP.** The app runs UTC and this database runs UTC-04; the interval must be built in SQL against `NOW()`.
- **Do not change what a web-enquiry hold does.** `api/submit-enquiry.php` and `admin/submission-view.php` keep the 24h default by relying on the parameter default — do not touch those call sites.
- **Do not try to fix the two `team_logic.php` failures.** They reproduce on `master` and are tracked separately.
- `admin/guest-board.php` renders `$errs` above the form and re-opens the form when `$errs` is non-empty (`$__openForm`), so the new validation error needs no extra plumbing.
