# Staff Role + Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Property-scoped "staff" admin role (code login; Concierge Desk + Messages + booking workspace only), an owner Staff-management page, and a guest+admin polish pass.

**Architecture:** Add `role`/`name`/`access_code`/`is_active` to `admin_users` + an `admin_user_venues` junction. Auth gains role/venue helpers + `login_staff`. Owner-only pages call `require_owner()`; staff-visible queries filter by `admin_venue_ids()`. Reuses `login_attempts` for rate limiting.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via `db_query()`.

---

## Task S1: Migration + schema

**Files:** Create `db/migrations/add_staff_role.sql`; Modify `db/schema.sql`.

- [ ] **Step 1** — `db/migrations/add_staff_role.sql`:
```sql
-- Migration: staff role + per-venue scoping on admin_users
-- Run via /admin/migrate.php. Idempotent.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'owner';
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('owner','staff'));
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS name TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS access_code VARCHAR(16) UNIQUE;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE admin_users ALTER COLUMN email DROP NOT NULL;
ALTER TABLE admin_users ALTER COLUMN password_hash DROP NOT NULL;

CREATE TABLE IF NOT EXISTS admin_user_venues (
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    venue_id      INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (admin_user_id, venue_id)
);
```
- [ ] **Step 2** — Apply locally: `php -r "require 'includes/db.php'; db()->exec(file_get_contents('db/migrations/add_staff_role.sql')); echo 'ok';"` → `ok`; re-run → `ok`.
- [ ] **Step 3** — Verify: `php -r "require 'includes/db.php'; echo db_query(\"SELECT COUNT(*) FROM admin_users WHERE role='owner'\")->fetchColumn(),' owners; ', db_query('SELECT COUNT(*) FROM admin_user_venues')->fetchColumn(),' assignments\n';"` (existing admins are owners; 0 assignments).
- [ ] **Step 4** — Append the `CREATE TABLE admin_user_venues` + the column adds (as a comment block noting it mirrors the migration) to `db/schema.sql`.
- [ ] **Step 5** — Commit: `git add db/migrations/add_staff_role.sql db/schema.sql && git commit -m "feat(staff): migration — role/name/access_code on admin_users + admin_user_venues"`

---

## Task S2: Auth helpers + tests

**Files:** Modify `includes/auth.php`, `tests/portal_logic.php`.

- [ ] **Step 1** — In `includes/auth.php`, update `current_admin()` SELECT to include role/name:
```php
'SELECT id, email, name, role, created_at FROM admin_users WHERE id = :id',
```
- [ ] **Step 2** — Add helpers (after `current_admin()`):
```php
function admin_role(): string { $a = current_admin(); return $a['role'] ?? 'owner'; }
function is_staff(): bool { return admin_role() === 'staff'; }

/** Venue ids a staff user may see; null = all (owner). Cached per request. */
function admin_venue_ids(): ?array {
    static $cache = null; static $done = false;
    if ($done) return $cache;
    $done = true;
    if (!is_staff()) { return $cache = null; }
    $rows = db_query('SELECT venue_id FROM admin_user_venues WHERE admin_user_id = :id', [':id' => $_SESSION['admin_id'] ?? 0])->fetchAll(PDO::FETCH_COLUMN);
    return $cache = array_map('intval', $rows);
}

/** Owner-only gate. Staff are redirected to the concierge desk. */
function require_owner(): void {
    require_login();
    if (is_staff()) { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That area is not available for staff accounts.']; header('Location: /admin/concierge-desk.php'); exit; }
}

/** True if the current admin may act on this hold (owner: always; staff: hold's venue in scope). */
function staff_can_hold(int $holdId): bool {
    if (!is_staff()) return true;
    $vids = admin_venue_ids();
    if (!$vids) return false;
    $v = db_query('SELECT r.venue_id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE h.id=:id', [':id'=>$holdId])->fetchColumn();
    return $v !== false && in_array((int)$v, $vids, true);
}

/** Log in an onsite staff member by access code. */
function login_staff(string $code, string $ip): bool {
    session_init();
    $code = strtoupper(trim($code));
    if ($code === '' || is_rate_limited($code, $ip)) {
        db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,FALSE)', [':e'=>$code ?: 'staff', ':ip'=>$ip]);
        return false;
    }
    $user = db_query("SELECT * FROM admin_users WHERE access_code = :c AND role='staff' AND is_active=TRUE", [':c'=>$code])->fetch();
    db_query('INSERT INTO login_attempts (email, ip_address, success) VALUES (:e,:ip,:ok)', [':e'=>$code, ':ip'=>$ip, ':ok'=>$user?'TRUE':'FALSE']);
    if (!$user) return false;
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $user['id'];
    db_query('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id', [':id'=>$user['id']]);
    return true;
}

/** Generate a unique 12-char staff access code. */
function gen_staff_code(): string {
    do { $c = strtoupper(bin2hex(random_bytes(6))); } while (db_query('SELECT 1 FROM admin_users WHERE access_code=:c', [':c'=>$c])->fetchColumn());
    return $c;
}
```

- [ ] **Step 3** — Tests in `tests/portal_logic.php` (before the summary). Seed an owner + a staff + venue assignment, assert helpers, clean up:
```php
// ── staff role ───────────────────────────────────────────────
$vA = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
$vB = (int)(db()->query("SELECT id FROM venues WHERE id <> $vA ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($vA) {
    $code = 'ZZTESTCODE01';
    db_query("INSERT INTO admin_users (email, role, name, access_code, is_active) VALUES (NULL,'staff','ZZ Staff',:c,TRUE)", [':c'=>$code]);
    $sid = (int)db()->lastInsertId();
    db_query("INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:s,:v)", [':s'=>$sid, ':v'=>$vA]);
    // login_staff by simulating session
    check('login_staff succeeds with right code', login_staff($code, '127.0.0.1') === true);
    check('is_staff after staff login', is_staff() === true);
    check('admin_venue_ids returns [vA] for staff', admin_venue_ids() === [$vA]);
    // a hold at vA vs vB
    $hAid = (int)(db()->query("SELECT h.id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE r.venue_id=$vA LIMIT 1")->fetchColumn() ?: 0);
    if ($hAid) check('staff_can_hold true at assigned venue', staff_can_hold($hAid) === true);
    if ($vB) { $hBid = (int)(db()->query("SELECT h.id FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id WHERE r.venue_id=$vB LIMIT 1")->fetchColumn() ?: 0);
        if ($hBid) check('staff_can_hold false at other venue', staff_can_hold($hBid) === false); }
    // reset session so later tests / runs aren't "logged in"
    unset($_SESSION['admin_id']);
    check('login_staff fails with wrong code', login_staff('NOPE', '127.0.0.1') === false);
    unset($_SESSION['admin_id']);
    db_query("DELETE FROM admin_users WHERE access_code=:c", [':c'=>$code]);
    db_query("DELETE FROM login_attempts WHERE email IN (:c,'NOPE')", [':c'=>$code]);
}
```
(Note: `admin_venue_ids()` caches per request via `static`; because the test logs in mid-run, call order matters — the check runs right after login before any owner context, so the cache resolves to staff. If the static cache causes a stale value, the implementer may reset it by running the staff checks in a subprocess or accept that this assertion is covered by the browser E2E instead — keep the login/`staff_can_hold`/wrong-code assertions which don't depend on the cache.)

- [ ] **Step 4** — Run `php tests/portal_logic.php` → ALL PASS; `php -l includes/auth.php`.
- [ ] **Step 5** — Commit: `git add includes/auth.php tests/portal_logic.php && git commit -m "feat(staff): auth role/venue helpers + login_staff + tests"`

---

## Task S3: Staff login panel

**Files:** Modify `admin/login.php`.

- [ ] **Step 1** — Read `admin/login.php`. In the POST handler, add a staff branch BEFORE the owner branch:
```php
if (($_POST['do'] ?? '') === 'staff') {
    if (login_staff($_POST['staff_code'] ?? '', client_ip())) { header('Location: /admin/concierge-desk.php'); exit; }
    $error = 'Invalid or inactive staff code.';
}
```
Ensure `client_ip()`/`login_staff` are available (auth.php + db.php already required).
- [ ] **Step 2** — Add a second panel under the owner form:
```php
<hr style="margin:24px 0;border:none;border-top:1px solid #eee">
<form method="POST" class="bk-lookup-form" style="max-width:380px;margin:0 auto">
  <input type="hidden" name="do" value="staff">
  <label class="bk-lookup-label" for="staffCode">Onsite staff — access code</label>
  <input id="staffCode" type="text" name="staff_code" required autocomplete="off" placeholder="Staff code" style="text-transform:uppercase" class="bk-lookup-input">
  <button type="submit" class="bk-lookup-btn">Staff sign in</button>
</form>
```
(Match the page's existing form classes; if the login page uses different classes, adapt.)
- [ ] **Step 3** — `php -l admin/login.php`; commit: `git add admin/login.php && git commit -m "feat(staff): access-code sign-in panel on admin login"`

---

## Task S4: Owner-only gating + nav

**Files:** Modify all owner-only admin pages + `admin/_layout.php` + `admin/dashboard.php` landing.

- [ ] **Step 1** — Add `require_owner();` immediately AFTER the existing `require_login();` in EACH of: `admin/dashboard.php`, `tours.php`, `rooms.php`, `venues.php`, `properties.php`, `gantt.php`, `holds.php`, `hold-new.php`, `hold-action.php`, `submissions.php`, `submission-view.php`, `conflicts.php`, `audit.php`, `settings.php`, `stay-info.php`, `guest-board.php`, `migrate.php`, `itinerary.php`. (Do NOT add it to `concierge-desk.php`, `messages.php`, `booking.php`, `booking-request-action.php`, `logout.php`, `login.php`, `staff.php` [staff.php adds its own in S6].) Verify each file has `require_login()` first; if a page checks the session differently, place `require_owner()` right after that check.
- [ ] **Step 2** — `admin/_layout.php`: wrap the owner-only sidebar links so they render only when `!is_staff()`. Keep **Concierge desk** + **Messages** always visible. For staff, also render their name + a `<span class="badge badge--blue">Staff</span>`. Add an owner-only **Staff** link → `/admin/staff.php` near the bottom. The Messages unread badge (if present in the layout) should pass `admin_venue_ids()` to `count_unread_admin()`.
- [ ] **Step 3** — Lint all edited files (`for f in admin/*.php; do php -l "$f"; done`), commit: `git add admin/ && git commit -m "feat(staff): require_owner gating on owner-only pages; staff-aware admin nav"`

---

## Task S5: Venue scoping (desk, messages, workspace)

**Files:** Modify `admin/concierge-desk.php`, `includes/booking.php`, `admin/messages.php`, `admin/booking.php`, `admin/booking-request-action.php`.

- [ ] **Step 1** — `admin/concierge-desk.php`: after the existing `$where`/`$params` are built and before `$whereSql`, add staff scoping:
```php
if (is_staff()) {
    $vids = admin_venue_ids() ?: [-1];
    $ph = []; foreach ($vids as $i=>$v) { $n=":sv$i"; $ph[]=$n; $params[$n]=(int)$v; }
    $where[] = 'r.venue_id IN (' . implode(',', $ph) . ')';
}
```
(The desk query already joins `rooms r`; confirm the alias is `r`.)
- [ ] **Step 2** — `includes/booking.php`: give `fetch_admin_threads(?array $venueIds = null)` and `count_unread_admin(?array $venueIds = null)` an optional venue filter. When `$venueIds` is a non-null array, add `AND r.venue_id IN (...)` (bound placeholders) to the query (both already join or can join `rooms r` via the hold). For `count_unread_admin`, join holds→units→rooms when filtering. A null arg keeps current behavior.
- [ ] **Step 3** — `admin/messages.php`: pass `admin_venue_ids()` to `fetch_admin_threads()`; and when rendering a single thread, block staff from a hold they can't access (`if (is_staff() && !staff_can_hold($holdId)) { redirect to messages.php }`).
- [ ] **Step 4** — `admin/booking.php` (workspace): right after `$hold` is loaded and validated, add:
```php
if (is_staff() && !staff_can_hold($holdId)) { $_SESSION['hold_flash']=['type'=>'error','msg'=>'That booking is at a property you don’t manage.']; header('Location: /admin/concierge-desk.php'); exit; }
```
And hide the **Details** tab for staff: in the `$__wtabs` array drop `details` when `is_staff()`, and if a staff user requests `tab=details` force it to `requests`. (Staff never see confirm/cancel.)
- [ ] **Step 5** — `admin/booking-request-action.php`: after loading the target request's hold id, if `is_staff() && !staff_can_hold($thatHoldId)` → flash + redirect back without applying. (Look up the addon/change's hold_id first.)
- [ ] **Step 6** — Lint; commit: `git add admin/ includes/booking.php && git commit -m "feat(staff): venue-scope concierge desk, messages, workspace + action guard"`

---

## Task S6: Owner Staff-management page

**Files:** Create `admin/staff.php`.

- [ ] **Step 1** — Create `admin/staff.php`: `require_login(); require_owner();`. POST actions (CSRF + PRG + audit_log):
  - `create`: name required; insert `admin_users (name, role, access_code, is_active) VALUES (:n,'staff', gen_staff_code(), TRUE)`; then insert selected `venue_id[]` into `admin_user_venues`.
  - `venues`: replace a staff's venue set (delete then insert the posted `venue_id[]`).
  - `regen`: set a new `gen_staff_code()`.
  - `toggle`: flip `is_active`.
  - `delete`: delete the staff row (cascade removes assignments).
  Render: a create form (name + venue checkboxes from `SELECT id,name FROM venues ORDER BY sort_order`), and a list of staff with their code, assigned venue names, active state, and edit controls (venue checkboxes to save, regenerate code, activate/deactivate, delete). Show the access code prominently (owner shares it with staff). Use admin CSS. Add `$activeMenu='staff'`.
- [ ] **Step 2** — Lint + smoke (`php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/staff.php';"` → no fatal). Commit: `git add admin/staff.php && git commit -m "feat(staff): owner staff-management page (create/assign venues/code)"`

---

## Task S7: Staff E2E + regression

- [ ] **Step 1** — `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php` → ALL PASS; `php -l` on all changed/new files.
- [ ] **Step 2** — CLI/browser: seed a staff (code + venue A). Simulate staff session (`$_SESSION['admin_id']=<sid>`), render `admin/concierge-desk.php` → only venue-A requests; render an owner-only page (`admin/settings.php`) → redirects (Location to concierge-desk); render `admin/booking.php?hold=<venue-B hold>` → redirect; workspace for a venue-A hold → no Details tab. Owner session → everything visible, Staff page lists the account + code.
- [ ] **Step 3** — Clean up seeded staff/holds. Confirm baseline.

---

## Task P1: Guest portal polish
- [ ] Review Home/Activities/Messages on mobile (375) + desktop. Fix: consistent section spacing, `.pa-h2`/`.pa-sub` usage, empty states present and friendly, tap targets ≥44px, the collapsible + add-to-plan spacing, no nav overlap. CSS/markup only. Commit `style(portal): guest visual consistency pass`.

## Task P2: Admin polish
- [ ] Review concierge-desk / messages / workspace / staff. Fix: consistent `.btn-*`/`.badge--*`, page-header spacing, empty states, workspace tab chips. CSS/markup only. Commit `style(admin): admin visual consistency pass`.

---

## Self-Review Notes
- Coverage: migration (S1), auth (S2), staff login (S3), gating+nav (S4), scoping (S5), staff admin (S6), verify (S7), polish (P1,P2).
- Security: server-side `require_owner` on all sensitive pages; venue scoping in queries + `staff_can_hold` on workspace + action endpoint; rate-limited code login; escaped output.
- Reuse: `login_attempts` for rate limit; existing `_layout`, `csrf_field`, `audit_log`, `.badge`/`.btn` classes.
