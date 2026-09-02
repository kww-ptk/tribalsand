# Reception role — design

**Date:** 2026-09-02
**Status:** approved, ready to implement

## Goal

Add a fourth account type, `reception`, that sees **everything the owner sees today
except the Catalog group, the Admin group, and site-content editing**. In practice
that means the full Operations group, the Bookings group, and restaurant
Reservations — scoped to the properties the account is assigned to.

## Role model

`admin_users.role` gains `'reception'` (currently `owner | manager | staff`).

Reception is *not* a staff job type: staff log in with a 12-character access code
via `login_staff()`, which hard-codes `role='staff'`. Reception needs an
email + password account, which `login()` already provides role-agnostically.

Reception is not a strict tier between manager and staff — it sees Bookings, which
managers do not. Managers are **not** changed by this work.

## Data model

One idempotent migration, `db/migrations/add_reception_role.sql`, applied after
`add_team_roles.sql` and run through `/admin/migrate.php`:

```sql
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check
    CHECK (role IN ('owner','manager','reception','staff'));
```

No new columns and no new tables. Reception reuses `admin_users.email` /
`password_hash` for login and the existing `admin_user_venues` join table for
property scoping.

Pre-migration safety: `reception_supported()` (memoized; inspects the constraint
definition in `pg_constraint`) hides the option in the account-create form until
the migration lands. This matches the house `*_supported()` convention. It fails
closed — the CHECK constraint would reject the INSERT anyway.

## Auth helpers — `includes/auth.php`

These already behave correctly and are **not** changed:

- `admin_role()` — still fails closed to `'staff'` for an unknown/missing role.
- `admin_job()` — returns `null` for reception (guarded by `!is_staff()`), so
  `job_is_ops()` is false and no ops-only surface opens up.
- `admin_venue_ids()` — only `is_owner()` returns `null` (= all venues), so
  reception falls into the scoped branch automatically.
- `staff_can_hold()` — same reasoning; reception is scoped per hold.
- `admin_home_url()` — reception lands on Front desk via the existing fallthrough.
- `require_owner()` — bounces reception, as intended.
- `login()` — role-agnostic, works for reception unchanged.
- `login_staff()` — hard-codes `role='staff'`, so reception cannot use an access
  code. This is deliberate and must not be relaxed.

Added:

- `is_reception(): bool`
- `reception_supported(): bool` — pre-migration guard (see above).
- `require_reception()` — owner | manager | reception. Guards surfaces managers
  already have and reception now joins: Tasks, Reservations.
- `require_bookings()` — owner | reception. Guards the Bookings surfaces, which
  stay closed to managers.
- `venue_scope_sql(string $col): string` — the shared scoping fragment. `''` for
  the owner, `1=0` for an account assigned no venues (empty scope means
  *nothing*, never everything), otherwise `<col> IN (…)`. `$col` is a literal
  written by us and interpolated; the ids are cast through `intval()`.
- `submission_in_scope(int $id): bool` — per-row submission authorization,
  mirroring the list predicate below exactly.

Changed: `require_gate()` admits reception (one condition).

`require_frontdesk()` needed **no** change: it bounces only ops and security
*staff*, and reception is not staff, so it already passes. `admin/messages-poll.php`
repeats that audience test inline and is `staff_can_hold()`-scoped, so it is
correct for reception unchanged too.

Also extended, so reception gets the scoped rights a manager already had:
`can_view_guest_docs()` (passport documents — front desk needs these at
check-in), `$canManage` in `task-action.php`, `$canAssign` in
`concierge-desk.php` and `_ws_requests.php`, and the assign branch of
`booking-request-action.php`.

## Surface map

| Group | Page | Gate today | Gate after |
|---|---|---|---|
| Operations | `frontdesk.php` | `require_login` + `is_owner` scoping | unchanged (works) |
| Operations | `mywork.php` | `require_login` | unchanged; nav link shown |
| Operations | `concierge-desk.php` | `require_login` + `is_owner` scoping | unchanged (works) |
| Operations | `messages.php`, `messages-poll.php` | `require_frontdesk` | unchanged (reception is not staff, so it passes) |
| Operations | `tasks.php`, `task-action.php` | `require_manager` | `require_reception` |
| Operations | `gate.php` | `require_gate` | `require_gate` admits reception |
| Restaurant | `reservations.php` | `require_manager` | `require_reception` |
| Restaurant | `menus.php`, `menu-edit.php` | `require_manager` | **unchanged** — content editing |
| Bookings | `holds.php`, `hold-action.php`, `hold-new.php` | `require_owner` | `require_bookings` |
| Bookings | `gantt.php` | `require_owner` | `require_bookings` |
| Bookings | `submissions.php`, `submission-view.php` | `require_owner` | `require_bookings` |
| Bookings | `conflicts.php` | `require_owner` | `require_bookings` |
| Bookings | `itinerary.php` | `require_owner` | `require_bookings` |
| Bookings | `booking.php`, `bill-print.php`, `booking-request-action.php` | `require_login` + `staff_can_hold` | unchanged (works) |

Everything else stays exactly as it is: Catalog (Rooms, Properties, Site Menu,
Page Content, Media Library, For Sale Listings, Offers, Tours, Service pricing),
Admin (Dashboard, Staff, Settings, Audit Log, Guest board), Check-in Settings,
and `migrate.php`. No guest-facing changes.

## Navigation — `admin/_layout.php`

Add `$__isReception = is_reception()` and fold it into `$__navFrontdesk`,
`$__navConcierge`, `$__navMessages`, `$__navTasks`, `$__navGate`, `$__navMyWork`.
`$__roleBadge` reads "Reception".

Two structural edits:

1. The **Bookings** group currently sits inside a single `if ($__isOwner)` block
   that also wraps Catalog and Admin. Split that block so Bookings renders for
   owner + reception while Catalog and Admin stay owner-only.
2. The **Restaurant** group renders for reception with the Reservations link only
   — no Menus link.

The Conflicts pending-count badge (`_layout.php:169`) is an unscoped `COUNT(*)`.
It must be venue-scoped, otherwise reception sees a count covering rows it cannot
open.

## Venue scoping

Reception is property-scoped through the existing `admin_user_venues` machinery.
An empty venue set means **nothing**, never everything — no bare `IN ()` may be
emitted, and the owner's `null` (all venues) must stay distinct from `[]`.

| Page | Join available | Change |
|---|---|---|
| `holds.php` | already selects `r.venue_id` | add `venue_id IN (…)` to the list and count queries |
| `gantt.php` | already joins `venues v` | filter `v.id IN (…)` |
| `conflicts.php` | `units u → rooms r` | filter `r.venue_id IN (…)`; scope the pending badge |
| `submissions.php` | **none** | see below |

`submissions` has no venue column. `enquiry` rows carry a nullable `room_id`
(→ `rooms.venue_id`); `contact` and `agency` rows carry no property at all.
Scoping predicate:

```sql
s.room_id IS NULL
OR EXISTS (SELECT 1 FROM rooms r WHERE r.id = s.room_id AND r.venue_id IN (…))
```

That is: room enquiries scope to the account's venues, and property-less general
enquiries stay visible to every reception account — otherwise a general
"do you have availability?" message would reach nobody at the desk. The same
predicate applies to the list query, the count query and the status KPI counts.

Every mutating endpoint gets the new gate **plus** a per-row ownership re-check,
so a scoped account cannot act on a foreign row by posting its id. This is the
pattern `reservation_editable()` and `menu_editable()` already use. Applies to
`hold-action.php`, `hold-new.php` (both the posted unit and the dropdown),
`submission-view.php` (delete / status / reply / convert, all covered by one
gate above them), `itinerary.php`, conflict resolution, and all seven of
`gantt.php`'s mutating actions (block create/delete/move, rate set/delete,
iCal feed add/delete).

Read-side scoping also covers the KPI cards on `holds.php`, the room filter
dropdowns on `holds.php` / `gantt.php` / `submissions.php`, and the conflicts
pending badge in both `_layout.php` and `conflicts.php` — a scoped account must
never see a count covering rows it cannot open.

## Account management — `admin/staff.php`

- A third account-type radio, "Reception (email + password)", reusing the manager
  branch: email + password + venue checkboxes. Shown only when
  `reception_supported()`.
- Every guard `role IN ('manager','staff')` gains `'reception'` — activate /
  deactivate, delete, venue assignment, and the team list filter.
- Password reset moves from `role = 'manager'` to `role IN ('manager','reception')`.
- The `job_type` editor stays staff-only (already `role = 'staff'`).
- Heading becomes "Team"; the role column renders "Reception".

## Testing

A dedicated `tests/reception_role.php`, following the house fixture pattern
(ZZ-prefixed rows, cleaned up at the end), skipping cleanly when the migration
has not been applied:

- Gate matrix: which of `require_owner` / `require_manager` / `require_reception` /
  `require_bookings` / `require_frontdesk` / `require_gate` a reception account
  passes.
- `admin_venue_ids()` returns the assigned set (not `null`) for reception.
- `staff_can_hold()` allows an in-scope hold and rejects an out-of-scope one.
- `admin_job()` returns `null` and `job_is_ops()` is false for reception.
- `admin_home_url()` returns `/admin/frontdesk.php`.
- The submissions scoping predicate: an in-scope room enquiry is visible, an
  out-of-scope one is not, and a property-less contact enquiry is.
- `login_staff()` refuses a reception account even with a valid access code.

## Non-goals

- No change to the `manager` role's access.
- No new columns, tables, or guest-facing changes.
- No changes to Menus, Catalog, Admin, or Check-in Settings.
