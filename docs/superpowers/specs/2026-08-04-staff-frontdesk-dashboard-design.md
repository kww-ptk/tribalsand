# Staff Front Desk dashboard

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Sub-project:** B of two (the other, "editable service settings & pricing", is designed separately later).

## Problem

On-site staff log in scoped to their property (`admin_user_venues` / `admin_venue_ids()`) but currently land on the Concierge Desk, which lists requests — not reservations. There is no at-a-glance operational view of **who is at the property**: who's in house tonight, who arrives today/tomorrow, who's leaving. Staff need a front-desk roster scoped to their property.

## Goal

A **Front Desk** dashboard (`admin/frontdesk.php`) showing confirmed reservations grouped by day, scoped to the viewer's property(ies), as the staff landing page. Owner sees it too (all properties, filterable).

Non-goals: editing reservations here (cards link to the existing workspace); pending/unconfirmed holds; occupancy analytics; anything about service pricing (separate sub-project).

## Decisions (from brainstorming)

- **Tabs:** Today / Tomorrow / This week (segmented control).
- **Staff landing page:** staff land on Front Desk after login; owner keeps their dashboard. Both get a "Front desk" nav link.
- **Card badges:** show **open requests** count and **unread messages** count per reservation.
- **Confirmed reservations only** (pending excluded).
- **"Today" is computed in Africa/Nairobi**, not server/UTC time.

## UI

A page rendered inside the existing `admin/_layout.php` chrome.

- **Header:** "Front desk" + subtitle (property name for single-venue staff, else "All properties") + today's date. A **property filter** `<select>` (owner: All properties + each venue; multi-venue staff: All my properties + their venues; single-venue staff: no filter).
- **Segmented control:** Today · Tomorrow · This week → links `?when=today|tomorrow|week` (default `today`). Server-rendered; no JS required.
- **KPI strip** (Today/Tomorrow only): In house tonight · Arriving · Departing (counts for that day).
- **Grouped reservation cards:**
  - *Today / Tomorrow:* three sections — **Arriving**, **In house**, **Departing** — for the tab's day.
  - *This week:* **Arrivals** over the next 7 days, grouped by date heading.
- **Reservation card:** guest name; `venue · room/unit`; `check_in–check_out (N nights)`; booking code; phone (or "—"); an **Open →** link to `admin/booking.php?hold=<id>`; and two small badges when non-zero: **"{n} requests"** (→ workspace requests tab) and **"{n} unread"** (→ workspace messages tab).
- **Empty states:** each section shows a muted "Nobody arriving today", etc., when empty.

## Date bucketing (all statuses = `confirmed`)

Let `D` be the tab's day (`today` or `tomorrow`, Africa/Nairobi):

- **Arriving:** `check_in = D`
- **In house:** `check_in < D AND check_out > D` (staying that night, excludes arrivals)
- **Departing:** `check_out = D`
- **KPI "in house tonight":** `check_in <= D AND check_out > D` (arrivals + continuing; departures excluded)

**This week:** arrivals with `check_in BETWEEN today AND today+6 days`, ordered by `check_in, guest_name`, grouped by `check_in` date in the view.

## Data / helpers

New file `includes/frontdesk.php` (keeps the query logic isolated and unit-testable):

- `frontdesk_day(?array $venueIds, string $ymd): array` → `['arriving'=>[], 'inhouse'=>[], 'departing'=>[], 'kpi_inhouse'=>int]`.
- `frontdesk_week(?array $venueIds, string $fromYmd, int $days = 7): array` → arrivals rows for `[fromYmd, fromYmd+days)`, each row carrying its `check_in` for the view to group by.
- `frontdesk_today_ymd(): string` and `frontdesk_tomorrow_ymd(): string` → dates in `Africa/Nairobi` (`(new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d')`).

`$venueIds` semantics: `null` = no venue restriction (owner, "all"); non-empty array = restrict to those venue ids; the empty array is treated as `[-1]` (staff with no venues → nothing), so the SQL never emits an empty `IN ()`.

Each reservation row is one query (with subqueries for the badges — no N+1):

```sql
SELECT h.id, h.guest_name, h.check_in, h.check_out, h.access_code,
       r.name AS room_name, u.name AS unit_name,
       v.id AS venue_id, v.name AS venue_name,
       s.guest_phone,
       (SELECT COUNT(*) FROM booking_addons ba
          WHERE ba.hold_id = h.id AND ba.status = 'requested')                      AS open_requests,
       (SELECT COUNT(*) FROM booking_messages bm
          WHERE bm.hold_id = h.id AND bm.sender = 'guest' AND bm.read_by_admin = FALSE) AS unread_msgs
FROM holds h
JOIN units u   ON u.id = h.unit_id
JOIN rooms r   ON r.id = u.room_id
LEFT JOIN venues v      ON v.id = r.venue_id
LEFT JOIN submissions s ON s.id = h.submission_id
WHERE h.status = 'confirmed'
  AND <date predicate>
  [AND r.venue_id IN (:v1,:v2,…)]     -- only when $venueIds is a non-null array
ORDER BY h.check_in ASC, h.guest_name ASC
```

Notes: `booking_addons.status = 'requested'` is the un-actioned state (CHECK allows `requested|confirmed|declined|cancelled`); "open" = `requested`. Badge queries are guarded so a missing `booking_messages`/`booking_addons` table (pre-migration) yields 0, not an error. Prepared statements only.

## Access control & navigation

- `admin/frontdesk.php`: `require_login()` (NOT `require_owner()`). Compute `$venueIds`: staff → `admin_venue_ids()`; owner → `null` unless a `?venue=<id>` filter is set (validated against the venue list, and for staff against their allowed set). A staff `?venue` outside their scope is ignored (falls back to their full scope).
- **Nav:** add a "Front desk" link in `admin/_layout.php` visible to **both** staff and owner (top of the list). `$activeMenu = 'frontdesk'`.
- **Landing:** add `admin_home_url(): string` to `includes/auth.php` → `is_staff() ? '/admin/frontdesk.php' : '/admin/dashboard.php'`. Use it for:
  - `admin/login.php` staff-login success (currently → concierge-desk) → `frontdesk.php`.
  - the "already logged in" redirects in `admin/login.php` and `admin/index.php` (currently hard-coded `dashboard.php`) → `admin_home_url()`.
  - `require_owner()`'s staff bounce target (currently `concierge-desk.php`) → `frontdesk.php`.

## Error handling / edge cases

- **No venue on a hold** (`venue_id` null): excluded from staff/venue-filtered views (the `IN` clause excludes null); included in owner "all" (venue name renders blank/"—").
- **Empty staff scope:** `admin_venue_ids()` returns `[]` → treated as `[-1]` → dashboard shows all-empty sections, no error.
- **Null phone** (admin-created holds, or no submission): render "—".
- **Pre-migration badge tables:** wrap badge subqueries / the whole fetch in try/catch to degrade to 0 counts.
- **Escaping:** all output via `e()`; `hold` id cast to int in the `Open →` link.

## Testing

New `tests/frontdesk_logic.php` (run `php tests/frontdesk_logic.php`, `check()` style), self-cleaning:
- Seed confirmed holds on a known unit/venue with dates around a fixed anchor (e.g. check_in = anchor, check_in < anchor < check_out, check_out = anchor) and assert `frontdesk_day($v, $anchor)` places each in arriving/inhouse/departing correctly and computes `kpi_inhouse`.
- Assert venue scoping: a hold in venue A is absent when `$venueIds = [B]`, present when `null` or `[A]`.
- Assert `frontdesk_week` returns an arrival inside the window and excludes one past `+days`.
- Assert badge counts: seed a `requested` addon and an unread guest message on a hold and confirm `open_requests`/`unread_msgs` = 1; clean up all seeded rows.

## Rollout

No migration — reads existing tables. Ship, and staff immediately land on Front Desk. (The separate service-pricing sub-project will follow.)
