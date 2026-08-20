# Zuri Restaurant — Reservations (Design)

**Date:** 2026-08-19
**Status:** Approved, ready for implementation planning
**Scope:** Piece B of three (see *Programme context*)

---

## Programme context

The request — "a Restaurant section in the admin sidebar, editable menus per property, and a
simple booking system" — decomposes into three separable pieces:

| Piece | What it is | Status |
|-------|------------|--------|
| **A — Menu CMS** | Schema for menus/categories/items, admin editor, convert `/zuri-menu` from static to DB-driven with byte-identical output | Not yet specced |
| **B — Reservations** | Public booking flow, admin approve/edit, notification emails | **This spec** |
| **C — Restaurant page** | Turns B's minimal booking page into a full marketing page for Zuri Restaurant (romantic dinners, special occasions) | Not yet specced |

B is specced first because it is what "open for bookings" actually requires, and because it has no
dependency on A. C depends on B (its Book button posts to B's endpoint).

**`/zuri-menu` is untouched by this work.** `zuri-menu.php` (708 lines, zero PHP) stays exactly as
it is. The `.htaccess` strip-`.php` rule already serves `/zuri-menu` from it and 301s the `.php`
form, so the live URL is preserved for free.

---

## Goals

1. External clients can request a table at Zuri from the public site.
2. Every request lands in the admin as `pending` and is confirmed or declined by a human.
3. The guest is never led to believe an unconfirmed request is a booked table.
4. The manager can see today's covers and what is awaiting confirmation at a glance.
5. Nothing added here can 500 the admin before its migration has run on the live DB.

## Non-goals (deliberately excluded)

- Capacity limits, covers-per-slot, or table assignment. Every request reaches a human; the
  system never auto-rejects. Decided explicitly — a small restaurant doing special-occasion
  covers wants the manager's judgement, not a rules engine.
- Payments, deposits, or card holds.
- Guest self-service cancellation or rescheduling. Guests call; staff edit in admin.
- A decline email. Declines are handled personally; the wording is too delicate to automate.
- A separate restaurant dashboard, users screen, or reports screen. Tribal Sand already has
  Audit Log, Users (Staff) and reporting; this feature feeds them rather than duplicating them.

---

## Data model

### `restaurant_reservations`

| Column | Type | Notes |
|--------|------|-------|
| `id` | SERIAL PK | |
| `reference` | VARCHAR(20) NOT NULL UNIQUE | Guest-quotable code, format `ZR-` + 5 chars (e.g. `ZR-8F3K2`) |
| `venue_id` | INT NOT NULL REFERENCES venues(id) ON DELETE RESTRICT | Zuri = slug `zuri`. Present from day one so other venues cost nothing later |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'pending' | `pending` \| `confirmed` \| `declined` \| `cancelled` |
| `guest_name` | VARCHAR(255) NOT NULL | |
| `guest_email` | VARCHAR(255) NOT NULL | |
| `guest_phone` | VARCHAR(50) | Optional |
| `party_size` | INT NOT NULL | 1–20 enforced in code |
| `reserved_on` | DATE NOT NULL | Nairobi-local |
| `reserved_at` | TIME NOT NULL | Nairobi-local |
| `occasion` | VARCHAR(40) | `romantic` \| `birthday` \| `anniversary` \| `business` \| `other` \| NULL |
| `notes` | TEXT | Guest's dietary needs / special requests |
| `staff_notes` | TEXT | Internal only. Never rendered in any guest-facing output or email |
| `confirmed_by` | INT REFERENCES admin_users(id) | Who actioned it |
| `confirmed_at` | TIMESTAMPTZ | |
| `decline_reason` | TEXT | Internal record; not emailed |
| `source_page`, `referrer` | TEXT | Attribution, mirroring `submissions` |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` | VARCHAR | Same set `submissions` captures |
| `user_agent` | TEXT | |
| `ip_address` | VARCHAR(64) | From `client_ip()`, never `$_SERVER['REMOTE_ADDR']` |
| `created_at` | TIMESTAMPTZ NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ NOT NULL DEFAULT NOW() | |

Indexes: `(venue_id, reserved_on)`, `(status)`, `(reserved_on)`.

**Timestamps are `TIMESTAMPTZ`, not naive `TIMESTAMP`.** The app sets `Africa/Nairobi` in PHP and
on every PDO connect; naive columns written before that convention read ~3h off. `reserved_on` and
`reserved_at` are Nairobi-local wall-clock, which is what `frontdesk_today_ymd()` compares against,
so "today's reservations" agrees between PHP and Postgres.

**Why not reuse `submissions`:** it has no status column, and its shape is `check_in`/`check_out`
rather than a single date + time. Adding `status`, `confirmed_by`, `confirmed_at` and
`decline_reason` there would leave four columns permanently NULL on every enquiry, contact and
agency row, and would drop reservations into the same admin list as website enquiries.

### Status transitions

```
pending  → confirmed | declined
confirmed → cancelled
```

Terminal: `declined`, `cancelled`. No other transition is legal; the transition table lives in
`includes/restaurant.php` and is unit-tested.

### Reference format

`ZR-` followed by 5 characters from `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (digits and letters that
cannot be confused when read over the phone — no `0`/`O`, no `1`/`I`). Generated server-side;
the `UNIQUE` constraint is the authority, with up to 5 regeneration attempts on collision.

### Service hours (configuration, not schema)

Stored in the existing `settings` table via the `setting()` helper — no new configuration
infrastructure.

- Key: `restaurant_hours_<venue_slug>` (e.g. `restaurant_hours_zuri`)
- Value: JSON — `{"days":[0,1,2,3,4,5,6],"from":"18:00","to":"22:00","step":30}`
  (`days` uses 0 = Sunday, matching PHP `date('w')`)
- Default when unset: `18:00`–`22:00`, 30-minute steps, all days
- Restaurant inbox: `restaurant_inbox_<venue_slug>`, falling back to `restaurant_inbox`, then to
  the `MAIL_FROM` env value

---

## Public booking flow

1. Guest picks a date. The form renders slots generated from the venue's configured hours.
2. Guest supplies party size, occasion (optional), name, email, phone (optional), notes (optional).
3. `POST /api/restaurant-book.php` (JSON), following the shape of `api/submit-enquiry.php`:
   - honeypot field
   - `verify_captcha()` — Turnstile, **fail-closed**; never reverts to a bypass
   - rate limit: 5 requests per `client_ip()` per hour
4. **Server re-derives the valid slot set and rejects any time not in it.** The client-side list is
   a convenience; the server is the authority. Also rejected: dates in the past, dates beyond a
   120-day horizon, closed days, party size outside 1–20 (over 20 returns a "please call us"
   message rather than a validation error).
5. Insert as `pending` → `audit_log('restaurant_request', 'reservation', id)` → send guest
   acknowledgement and staff alert → return `{ok:true, reference:"ZR-…"}`.
6. Front-end shows the existing `showSuccessModal()` with the reference and the "we'll confirm
   within 24 hours" line.

All slot generation, validation and status-transition logic lives in **`includes/restaurant.php`**,
so the API endpoint and the admin page share one source of truth.

**Where the form lives.** The booking form is a reusable widget, `includes/form-restaurant.php`,
following the precedent of `includes/form-enquiry.php`. B mounts it on a minimal public page,
`zuri-restaurant.php` (served at `/zuri-restaurant` by the existing strip-`.php` rule), so the
restaurant is genuinely bookable when B ships rather than waiting on C. C then rewrites that page's
copy, layout and imagery around the same widget — no change to the widget or the endpoint.

---

## Admin surface

New `Restaurant` sidebar group (via the existing `$__navgroup` helper), with `Reservations` as its
first item. `Menus` joins it when piece A is built.

**`admin/restaurant.php`:**

- Counter cards: **Reservations Today**, **Pending Confirmation**
- **Today's Reservations** — time, guest, phone, party, status
- **Upcoming Reservations** — date, time, guest, phone, party, status
- Filters: status, date range, venue
- Row actions: Confirm, Decline, Edit, Cancel
- Edit covers date, time, party size, occasion, notes and staff notes

**`admin/restaurant-action.php`** handles Confirm / Decline / Cancel: `verify_csrf()`, PRG redirect,
`audit_log()` on every state change — which means the existing Audit Log page covers this feature
with no extra work.

### Roles

| Capability | Gate | Rationale |
|------------|------|-----------|
| View, confirm, decline, edit, cancel | `require_frontdesk()` | Owner, manager, front-desk staff. Front desk already handles guest communication; ops staff and gate security are excluded |
| Edit service hours | `require_manager()` | Owner or manager. A deliberate departure from the other settings screens, which use `require_owner()` — changing dinner hours is daily ops, not pricing config |

The reservations list is scoped with `admin_venue_ids()`, testing `!is_owner()` per the codebase
convention, so a venue-scoped manager sees only their venue's covers.

---

## Emails

Three, all rendered with the branded template introduced in `0d0483e`, named to match the existing
hold functions in `includes/mail.php`:

| Function | To | Content |
|----------|----|---------|
| `send_restaurant_request()` | Guest | Reference, date/time/party. States plainly this is a **request, not yet a confirmed table** |
| (staff alert, same dispatch) | Restaurant inbox | Full details + deep link into `admin/restaurant.php` |
| `send_restaurant_confirmed()` | Guest | Table confirmed, reference, date/time/party, occasion, who to call to change it |

No decline email (see Non-goals). `staff_notes` and `decline_reason` never appear in guest mail.

---

## Failure handling

- **Email failure must never lose a booking.** The insert commits first; sends are wrapped and
  logged. A dead Resend key costs a notification, not a reservation.
- **Double-submit.** A repeat POST with the same email + venue + slot within 5 minutes returns the
  existing reference instead of creating a second row.
- **Concurrent approval.** The confirm update is guarded by `WHERE status = 'pending'`; only one
  manager wins and only one confirmation email is sent. Same guard shape for decline and cancel.
- **Turnstile stays fail-closed.** Site key set with secret missing returns `false`.
- **Pre-migration safety.** `restaurant_supported()`, following the `*_supported()` pattern in
  `includes/team.php`, gates the nav item and the page. The live DB migrates separately through
  `/admin/migrate.php`, so there is a real window where the code is deployed and the table is not —
  this keeps that window a hidden nav item rather than a 500.

---

## Testing

`tests/restaurant_logic.php`, in the style of `tests/currency_logic.php` — pure functions, no DB,
runnable anywhere:

- Slot generation from an hours config (boundaries, step arithmetic, closed days)
- Slot validation rejects off-grid times
- Past dates and dates beyond the 120-day horizon are rejected
- Party size bounds (1, 20 accepted; 0, 21 rejected)
- Legal status transitions accepted, illegal ones rejected
- Reference format matches `ZR-[23456789A-HJ-NP-Z]{5}`

---

## Migration and rollout

1. `db/migrations/add_restaurant_reservations.sql` — idempotent (`CREATE TABLE IF NOT EXISTS`,
   `CREATE INDEX IF NOT EXISTS`), consistent with existing migrations.
2. Deploy code — `restaurant_supported()` keeps the nav hidden while the table is absent.
3. Run the migration on the live DB via `/admin/migrate.php`.
4. Set `restaurant_hours_zuri` and `restaurant_inbox_zuri` in settings.
5. Zuri is bookable at the end of B via the minimal `zuri-restaurant.php` page. Piece C replaces
   that page's content with full marketing copy and imagery; the form widget it mounts is unchanged.

---

## Follow-on

Piece C (public Zuri Restaurant page) posts to this endpoint. Piece A (Menu CMS) adds a second item
to the `Restaurant` nav group and converts `zuri-menu.php` to DB-driven rendering, preserving both
the URL and the exact markup.
