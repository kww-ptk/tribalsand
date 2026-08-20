# Restaurant — Menus & Reservations — Implementation Plan

> **For agentic workers:** implement task-by-task; steps use checkbox (`- [x]`) syntax for tracking. Every read must be pre-migration-safe (`*_supported()` guard) and every mutation CSRF+PRG. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

**Origin:** Patrik (WhatsApp, 20 Aug 2026) — per-property digital restaurant menus, backend-managed by the house manager (like `tribaltablekenya.com/admin/menu`), plus a "simple reservation system … later" for different properties (Zuri, Maya Kobe, …). Also: Events nav → dropdown (Weddings, Retreat) and an updated Retreats page.

**Status:**
- **Phase 1 (nav + Retreats)** — ✅ shipped in `c87ff20`.
- **Phase 2 (DB-driven menus)** — ✅ shipped in `c87ff20`. See appendix.
- **Phase 3 (reservations)** — ✅ **built & verified** (migration applied to Neon; `tests/reservations_logic.php` 32/32 pass; full guest-submit → admin-confirm → guest-email smoke test passed). Awaiting owner's commit/push.

**Phase 3 decisions (confirmed with owner):** enable for **all properties**; **staff-confirm (request) model** — guest submits → `pending` → staff confirm/cancel; **v1 has no table-capacity / double-booking logic** (staff eyeball availability, like tribaltablekenya's basic flow).

---

## Phase 3 — Restaurant reservations

**Goal:** A guest "Reserve a Table" request flow per property, and a manager-scoped admin surface to review/confirm/cancel, reusing the existing mail, Turnstile, scoping and data-table toolkits.

**Architecture:** New `reservations` table (venue-scoped, optional `menu_id` link). Public `reserve.php` form → `api/submit-reservation.php` inserts `pending`, emails the guest an acknowledgement + alerts staff. Admin `admin/reservations.php` lists/dashboards + confirm/cancel (PRG), scoped by `admin_venue_ids()`; confirming emails the guest. Helpers in `includes/reservations.php` (pre-migration-safe). "Reserve a Table" enters the site via the Restaurant nav dropdown + a CTA on `menu.php`; a "Reservations" link joins the admin Restaurant sidebar group.

**Tech Stack:** Vanilla PHP 8.2, PDO `db_query()` (pgsql — build any venue `IN (...)` clause in PHP, no reused placeholders). `client_ip()`, Africa/Nairobi tz, `e()`, `(int)`/`(float)` casts. Turnstile `verify_captcha()` (fail-closed). Mail via `includes/mail.php` branded template. Guest date/time use the **styled datepicker** (`js/datepicker.js` + `css/datepicker.css`) and styled selects — **never native** date/select inputs. Admin uses `.card`/`.field`/`data-table`, `admin-select.js`, the pagination/`dt_*` toolkit, and `admin_icon()`.

**Conventions:** Prepared statements only. Public mutation guarded by Turnstile + rate limiting + CSRF; admin mutation by `require_manager()` + `admin_venue_ids()` scope + per-row ownership re-check + CSRF + PRG. Reference id like the check-in pattern (`TSR-`-style). Branch `feature/restaurant-reservations` (owner may commit to `master` directly — their call).

---

### File map (Phase 3)

| File | Change |
|------|--------|
| `db/migrations/add_reservations.sql` | **Create** — `reservations` table + indexes |
| `includes/reservations.php` | **Create** — `reservations_supported()`, `create_reservation()`, `fetch_reservations()` (scoped + filtered), `fetch_reservation()`, `set_reservation_status()`, `reservation_slots()`, `reservation_status_badge()` |
| `api/submit-reservation.php` | **Create** — validate + Turnstile + rate-limit → insert `pending` → guest ack + staff alert mail |
| `reserve.php` | **Create** — public form (`?venue=<slug>`), styled datepicker + selects, Turnstile, success modal |
| `includes/mail.php` | **Modify** — `send_reservation_received()` (guest + staff) and `send_reservation_confirmed()` using the branded template |
| `menu.php` | **Modify** — "Reserve a Table" CTA linking to `reserve.php?venue=<slug>` |
| `includes/header.php` | **Modify** — add "Reserve a Table" to the Restaurant dropdown (desktop + mobile) |
| `admin/reservations.php` | **Create** — dashboard cards (Today/Upcoming/Pending) + filterable list + confirm/cancel (PRG), manager-scoped |
| `admin/_layout.php` | **Modify** — "Reservations" link in the Restaurant sidebar group |
| `tests/reservations_logic.php` | **Create** — helper tests inside a rolled-back transaction |

---

### Task 1: Migration

**Files:** Create `db/migrations/add_reservations.sql`

- [x] **Step 1: Write it** (idempotent; run via `/admin/migrate.php`)

```sql
-- Tribal Sand: restaurant table reservations (request model). Idempotent.
CREATE TABLE IF NOT EXISTS reservations (
    id               SERIAL PRIMARY KEY,
    venue_id         INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    menu_id          INT REFERENCES menus(id) ON DELETE SET NULL,
    reference        VARCHAR(32) UNIQUE,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    party_size       INT  NOT NULL CHECK (party_size > 0),
    guest_name       VARCHAR(160) NOT NULL,
    guest_phone      VARCHAR(40)  NOT NULL,
    guest_email      VARCHAR(200),
    notes            TEXT,
    status           VARCHAR(20) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','confirmed','cancelled')),
    source           VARCHAR(40) NOT NULL DEFAULT 'web',
    client_ip        VARCHAR(64),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_reservations_venue_date ON reservations (venue_id, reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservations_status     ON reservations (status, reservation_date);
```

- [x] **Step 2: Apply locally** — `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_reservations.sql")); echo "applied\n";'`
- [x] **Step 3: Verify** the table + a probe insert/rollback of a `pending` row is accepted.

### Task 2: Helpers — `includes/reservations.php`

- [x] `reservations_supported()` (memoised `to_regclass('public.reservations')`).
- [x] `create_reservation(array $data): array` — inserts `pending`, mints `reference` (`TSR-<venue>-<rand>`), returns the row. Uses `client_ip()`.
- [x] `fetch_reservations(?array $venueIds, array $filters)` — scoped (`null` = owner/all), filter by date/status/venue; ordered by date+time.
- [x] `fetch_reservation(int $id)`, `set_reservation_status(int $id, string $status)`.
- [x] `reservation_slots()` — service time options (e.g. 12:00–22:00, 30-min steps) for the styled select.
- [x] `reservation_status_badge(string): string` — badge class map.
- [x] **Verify:** `tests/reservations_logic.php` (rolled-back transaction) covers create + scope + status transitions.

### Task 3: Public form + API

- [x] `reserve.php?venue=<slug>` — property preselect (styled select of published venues), **styled datepicker** date, styled time + party-size selects, name/phone/email/notes, `.cf-turnstile`. No-JS still submits.
- [x] `api/submit-reservation.php` — validate (required fields, future date, party 1–N), `verify_captcha()` (fail-closed), rate-limit by `client_ip()`, insert via `create_reservation()`, then `send_reservation_received()`. PRG back to `reserve.php` with the success modal (reference shown).
- [x] **Verify:** submit a test reservation locally; confirm the row is `pending` and mail is dispatched (or logged).

### Task 4: Mail

- [x] `send_reservation_received($res)` — guest acknowledgement ("request received, pending confirmation") + staff alert, branded template.
- [x] `send_reservation_confirmed($res)` — guest confirmation on staff approval.
- [x] **Verify:** both render in the branded template with correct property, date/time, party, reference.

### Task 5: Admin surface

- [x] `admin/reservations.php` — `require_login()` + `require_manager()`; dashboard cards (Today / Upcoming / Pending counts) + filterable, paginated list (data-table toolkit); **Confirm** / **Cancel** buttons (PRG + CSRF), scoped by `admin_venue_ids()` with per-row ownership re-check. Confirming calls `send_reservation_confirmed()`.
- [x] `admin/_layout.php` — add "Reservations" to the existing Restaurant sidebar group (owner + manager), `$activeMenu==='reservations'`.
- [x] **Verify:** render as owner (all venues) and as a scoped manager (only their venue's reservations); confirm/cancel writes + emails; a scoped manager cannot act on another venue's row.

### Task 6: Entry points

- [x] `menu.php` — "Reserve a Table" CTA → `reserve.php?venue=<slug>` (only when the menu has a `venue_id`).
- [x] `includes/header.php` — "Reserve a Table" link in the Restaurant dropdown (desktop + mobile).
- [x] **Verify:** links resolve; nav stays pre-migration-safe (hidden when `reservations`/`menus` absent).

### Task 7: Ship

- [x] Full local smoke test (guest submit → admin confirm → guest email).
- [x] Run `add_reservations.sql` on the live DB (shared Neon → already applied if seeded locally; else via `/admin/migrate.php`).
- [x] Commit + (owner's call) push.

---

## Appendix — Phase 1 & 2 (shipped, `c87ff20`)

**Phase 1 — Nav + Retreats:** `retreats.php` redesigned (kept `db.php` opener, `asset_url()` images, header/footer assets; fixed doubled nav offset + duplicate FAQ `+`). Events nav → dropdown (Weddings → `events.php`, Retreats → `retreats.php`), desktop + mobile.

**Phase 2 — DB-driven menus:** `add_menus.sql` (`menus` → `menu_categories` → `menu_items`); `includes/menu.php` helpers (pre-migration-safe); public `menu.php?m=<slug>` reproducing the zuri-menu design; `zuri-menu.php` + `maya-kobe-breakfast.php` → conditional 301 redirects; `admin/menus.php` + `admin/menu-edit.php` (manager-scoped editor); `db/seeds/seed_menus.php` (Zuri food+drinks, Maya Kobe breakfast); Restaurant nav dropdown + admin sidebar group. See CLAUDE.md → "Restaurant menus — DB-driven, per property, manager-editable".

**Also shipped:** `includes/db.php` cold-start hardening (`connect_timeout` + retry; dev-server `set_time_limit(90)` for Neon serverless wake).
