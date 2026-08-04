# Staff Role (property-scoped concierge admin) + Polish — Design Spec

**Date:** 2026-08-03
**Status:** Approved

## Goal
1. **Staff role:** onsite staff log in with a short **access code**, see only the **Concierge Desk + Messages + the per-booking workspace** (Requests/Messages/Plan — no confirm/cancel, no catalog/settings), scoped to the **properties (venues)** they're assigned to. One staff account can cover multiple properties. Owners keep full access and manage staff.
2. **Polish pass:** guest-portal + admin visual consistency (spacing, headings, badges, buttons, empty states).

---

## Part 1 — Staff role

### Data model — `db/migrations/add_staff_role.sql` (+ append to `db/schema.sql`)
```sql
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'owner';
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('owner','staff'));
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS name TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS access_code VARCHAR(16) UNIQUE;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE admin_users ALTER COLUMN email DROP NOT NULL;          -- staff need no email
ALTER TABLE admin_users ALTER COLUMN password_hash DROP NOT NULL;  -- staff use a code, no password

CREATE TABLE IF NOT EXISTS admin_user_venues (
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    venue_id      INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (admin_user_id, venue_id)
);
```
Existing accounts default to `role='owner'`. Idempotent. Run via `/admin/migrate.php`.

### Auth (`includes/auth.php`)
- `current_admin()` — ensure it returns `role`, `name`, `email` (SELECT includes them).
- `admin_role(): string` → `'owner'|'staff'` (default `'owner'` if unknown).
- `is_staff(): bool`.
- `admin_venue_ids(): ?array` — for staff, the assigned venue ids from `admin_user_venues`; for owner, `null` (= all venues). Cache per request.
- `require_owner(): void` — if `is_staff()`, set a flash and redirect to `/admin/concierge-desk.php` (server-side gate; not just nav hiding).
- `staff_can_hold(int $holdId): bool` — `true` for owner; for staff, true iff the hold's room venue ∈ assigned ids.
- `login_staff(string $code, string $ip): bool` — normalize code; `is_rate_limited($code,$ip)` guard (reuse `login_attempts`); find `admin_users WHERE access_code=:c AND role='staff' AND is_active=TRUE`; on match set session (`admin_id`, and a cached role), update `last_login_at`, return true; else `record_failed_login($code,$ip)` and false.

### Staff login (`admin/login.php`)
Add a second panel "Onsite staff" with a single **Access code** field posting `do=staff`; the handler calls `login_staff()` and on success redirects to `/admin/concierge-desk.php`. Keep the existing owner email+password form. Rate-limited; no Turnstile (consistent with the current admin login). **Security note:** a code-only login is weaker than password — mitigated by a 10–12-char random code, per-IP rate-limit lockout, the strictly limited staff role, and owner deactivation. Documented, accepted.

### Gating (server-side)
Add `require_owner();` immediately after `require_login();` on every owner-only page: `dashboard.php`, `tours.php`, `rooms.php`, `venues.php`, `properties.php`, `gantt.php`, `holds.php`, `hold-new.php`, `hold-action.php`, `submissions.php`, `submission-view.php`, `conflicts.php`, `audit.php`, `settings.php`, `stay-info.php`, `guest-board.php`, `migrate.php`, `itinerary.php`, `staff.php`. Staff-allowed pages (no `require_owner`): `concierge-desk.php`, `messages.php`, `booking.php`, `logout.php`, `booking-request-action.php` (staff action requests). On login, staff land on `concierge-desk.php` (owners on `dashboard.php`).

### Venue scoping
- `admin/concierge-desk.php`: when `is_staff()`, add `ba`'s hold venue filter `r.venue_id IN (:v0,:v1,…)` (the desk already joins rooms/venues) using `admin_venue_ids()`. Owner unaffected.
- `includes/booking.php` `fetch_admin_threads(?array $venueIds = null)` + `count_unread_admin(?array $venueIds = null)` — when a venue list is passed, filter threads/counts to holds whose room venue ∈ list. `admin/messages.php` passes `admin_venue_ids()`; the `_layout` nav badge passes it too.
- `admin/booking.php` (workspace): after loading `$hold`, if `is_staff()` and `!staff_can_hold($holdId)` → flash + redirect to `concierge-desk.php`. For staff, **omit the Details tab** (no confirm/cancel) — staff tabs are Requests · Messages · Plan.
- `admin/booking-request-action.php`: for staff, verify `staff_can_hold()` for the target request's hold before applying (defense in depth).

### Admin nav (`admin/_layout.php`)
- If `is_staff()`: show only **Concierge Desk** + **Messages** links; hide all owner links; show the staff `name` + a "Staff" badge; the Messages unread badge uses `admin_venue_ids()`.
- If owner: show all links plus a new **Staff** link → `admin/staff.php`.

### Owner Staff management (`admin/staff.php`, new)
- `require_login()` + `require_owner()`. List staff accounts (name, code, assigned venues, active). Create: name + venue multi-select (checkboxes from `venues`) + auto-generated `access_code`. Edit: reassign venues, regenerate code, activate/deactivate, delete. CSRF + PRG + `audit_log`. Code generation: 12-char unguessable (e.g. `strtoupper(bin2hex(random_bytes(6)))`), uniqueness-checked.

---

## Part 2 — Polish (guest + admin visual consistency)

Targeted tidy, not a redesign:
- **Guest portal:** consistent section spacing on Home; ensure every list/section has a friendly empty state (Activities empty, Messages empty, plan empty already done); consistent `.pa-h2`/`.pa-sub` usage; check mobile tap targets + that the fixed nav never overlaps content (safe-area already added); verify the collapsible "Your stay" and the add-to-plan form spacing.
- **Admin:** consistent `.btn-*`/`.badge--*` usage and spacing across concierge-desk / messages / workspace / staff; ensure each list has an empty state; the workspace tab chips and badges consistent; page-headers consistent.
- Keep changes CSS/markup-level; no behavior changes. Capture concrete fixes during the task rather than pre-listing every pixel.

---

## Security
- Code login: rate-limited, long random code, limited role, deactivatable; server-side `require_owner()` on all sensitive pages (not just nav hiding); venue scoping enforced in queries + `staff_can_hold` on the workspace and the action endpoint. All output `e()`-escaped; SQL parameterized (the `venue_id IN (...)` list uses bound placeholders). `client_ip()` for rate limits.

## Testing
- `tests/portal_logic.php` (or a new `tests/staff_logic.php`): seed an owner + a staff assigned to venue A; assert `admin_venue_ids()` returns [A] for staff / null for owner; `staff_can_hold()` true for a hold at A, false for a hold at B; `login_staff()` succeeds with the right code and fails with a wrong one; `fetch_admin_threads([A])` excludes a thread at venue B.
- Browser/CLI: staff code login → lands on concierge-desk showing only venue-A requests; owner-only page (e.g. settings) redirects staff away; workspace for a venue-B hold redirects staff; Details tab hidden for staff. Owner Staff page creates a staff + assigns venues + shows the code.
- Regression: owner login + all existing admin pages unchanged; existing tests pass; `php -l` clean.

## Deploy
Run `db/migrations/add_staff_role.sql` via `/admin/migrate.php` after deploy (adds columns + junction; existing admins become owners automatically). No guest-facing DB dependency. Rollback: Render previous deploy; the added columns/table are harmless.
