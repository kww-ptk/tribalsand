# Admin ops fixes — design

**Date:** 2026-08-11
**Status:** approved, ready for planning
**Scope:** Group C of a four-group request (see "Out of scope" below)

## Problem

Three unrelated admin defects, plus one interaction between them that has to be designed for
rather than discovered in production.

### 1. Admin-created bookings start confirmed

`admin/hold-new.php:38` passes `'confirmed'` to `create_hold_with_block()` — the only call site
that does. The requester wants them to start **pending**, so a booking typed in by staff still
has to be explicitly confirmed.

### 2. The guest board silently discards event dates and prices

Reported as "not saving events, date, time and pricing and user cannot join". The save path is
**not** broken — posting `category=event` with a date and price stores both correctly, and the
join flow works end to end (both verified against the local database).

The actual cause is at `admin/guest-board.php:246`. For a new post `$edit` is null, so
`(($edit['category'] ?? '') === $ck)` is never true, no `<option>` carries `selected`, and the
browser selects the first entry of `$CATS` — **"Update"**. Line 74 then runs:

```php
if ($category !== 'event') { $eventDate = null; $priceAmt = null; }
```

An owner who fills in Title, event date, time and price but does not notice the Category
dropdown gets both values silently dropped, a flash saying "Post created", and a post no guest
can join — because the portal only renders a Join button for `category === 'event'`. Reproduced:

```
flash: "Post created."
  category:     update
  event_date:   *** NULL — silently discarded ***
  price_amount: *** NULL — silently discarded ***
```

One cause explains all four reported symptoms.

### 3. The gate cannot sign in a booked guest

`admin/gate.php` shows arrivals as a read-only three-column table (guest, property, room) and a
separate walk-up visitor form that demands name, property, who they are visiting, purpose and
vehicle — all typed from scratch, for a guest the system already knows.

The party size is already available: `frontdesk_rows()` selects `guest_count` and
`ci_complete_count` (`includes/frontdesk.php:38-42`). The gate simply does not render them.

### 4. The interaction — pending bookings disappear and self-destruct

Changing #1 in isolation would break #3 and lose data:

- **`frontdesk_rows()` filters `h.status = 'confirmed'`** (`includes/frontdesk.php:26`). A pending
  booking vanishes from the Front Desk board and from the Gate arrivals list — the very list #3
  exists to improve.
- **`create_hold_with_block()` sets `expires_at = NOW() + INTERVAL '24 hours'` unconditionally**
  (`includes/db.php:287`). `expire_stale_holds()` flips `status='pending' AND expires_at < NOW()`
  to `expired`, deletes the availability block and emails the guest, driven every five minutes by
  `bin/ical-expire-holds.php`. A booking staff deliberately created would silently expire
  overnight and release its dates.

## Decisions taken

| Question | Decision |
|---|---|
| Expiry on admin bookings | None — `expires_at = NULL`, pending until someone acts |
| Pending on Front Desk / Gate | Shown, with a Pending badge |
| Guest board | Date/price fields appear only for the Event category; a posted value on a non-event is a validation error, never a silent null |
| Gate sign-in | Writes a `visitors` row, prefilled from the booking |

## Architecture

### 1. Optional expiry on hold creation

`create_hold_with_block()` gains a nullable expiry:

```php
function create_hold_with_block(
    int $unit_id, ?int $submission_id,
    string $check_in, string $check_out,
    string $guest_name, string $guest_email,
    string $status = 'pending',
    ?int $expiresInHours = 24        // null = never expires
): int
```

`NULL` needs no special handling downstream: `expire_stale_holds()`'s predicate
`expires_at < NOW()` evaluates to NULL for a NULL column and therefore never matches. The hold is
exempt by construction rather than by a new branch.

`admin/hold-new.php` passes `'pending', null`. Its page copy currently says "confirmed" three
times (card title, intro paragraph, and the submit `confirm()` dialog) — all corrected.

**Holds list.** With `expires_at` NULL and status pending, every branch of the timing column in
`admin/holds.php:169-181` falls through and the cell renders blank. A branch is added so the new
state is legible: pending with no expiry reads **"Awaiting confirmation"**.

### 2. Which pending bookings are operational

"Pending" today means two different things, and conflating them would flood the arrivals board
with speculative web enquiries:

| Origin | `submission_id` | `expires_at` | Meaning |
|---|---|---|---|
| `api/submit-enquiry.php` | set | +24h | An unconfirmed web enquiry that may never convert |
| `admin/submission-view.php` | set | +24h | An enquiry a staff member converted |
| `admin/hold-new.php` (after this change) | null | **NULL** | A booking staff typed in deliberately |

The absence of an expiry is exactly the signal that separates them, so `frontdesk_rows()` becomes:

```sql
(h.status = 'confirmed' OR (h.status = 'pending' AND h.expires_at IS NULL))
```

Staff-created bookings appear; web enquiries still do not. `frontdesk_rows()` also starts
selecting `h.status` so views can badge them.

**KPI effect, accepted deliberately:** `frontdesk_day()`'s `kpi_inhouse` counts arrivals plus
in-house, so a staff-created pending booking now counts toward expected occupancy. That is
correct for an operational board — the guest is expected to sleep there — but it is a visible
number change and is called out here rather than discovered.

Arrival rows in `admin/gate.php` and `admin/frontdesk.php` render a **Pending** badge when
`status !== 'confirmed'`.

### 3. Guest board — fields follow the category, and nothing is discarded silently

**Client.** The Event date and Price fields are wrapped in a container that is shown only when
Category is `event`. The server renders it with `hidden` for any other category, so first paint
is correct without JavaScript; a `change` handler on the category select toggles it. The
"(events only)" label hints become unnecessary and are dropped — the field's presence says it.

**Server.** The silent null is replaced by a validation error:

```php
if ($category !== 'event' && ($evRaw !== '' || $priceRaw !== '')) {
    $errs[] = 'Pick the Event category to set a date or price.';
}
```

Only non-empty values trigger it — the form always posts empty strings for these fields, so a
genuine Update post is unaffected. `guest-board.php` already renders `$errs` above the form and
re-opens it on error, so no new plumbing is needed.

The category default stays "Update"; hiding the fields removes the trap without changing what a
new post is.

### 4. Gate — arrivals you can act on

The arrivals table in `admin/gate.php` gains three things per row:

- **Guests** — `guest_count` rendered as "3 adults", plus the existing `checkin_badge()` when
  check-in applies to the booking, so gate staff can see at a glance whether the party is
  checked in.
- **Pending badge** when the booking is not yet confirmed.
- **Sign in** — a button that reveals the existing sign-in card with `visitor_name`, `venue_id`
  and `visiting` (the room name) prefilled from the booking, `purpose` set to "Guest arrival",
  and focus placed in the vehicle/plate field. The only thing left to type is the plate.

Prefill is pure client-side: each button carries `data-` attributes and a small handler fills the
form that is already on the page. No new endpoint, no new table, and the visitor register stays
the single log of everyone on the property — so the existing "N on site" count keeps working.

The `visitors` table already has every column needed (`venue_id`, `visitor_name`, `visiting`,
`purpose`, `vehicle`), so there is **no migration**.

## Testing

All new assertions go in `tests/frontdesk_logic.php`, which is the project's **DB-backed** harness
— it already creates real holds and asserts on `frontdesk_day()` membership.

`tests/manage_logic.php` is explicitly "Pure-logic tests — no DB required", so hold-creation
assertions must **not** go there.

The rule being tested is a SQL predicate, and it is tested as one — against real rows, through
`frontdesk_rows()`. No parallel PHP helper: two definitions of one rule is exactly the drift this
group is otherwise fixing, and a PHP mirror would pass even if the SQL were wrong.

New assertions in `tests/frontdesk_logic.php`:

- A confirmed booking appears in `arriving`.
- A staff-created pending booking (`expires_at IS NULL`) appears.
- A web-enquiry pending booking (`expires_at` set) does **not** appear — this is the one that
  matters, since getting it wrong floods the arrivals board with speculative enquiries.
- A cancelled booking does not appear.
- `create_hold_with_block(..., 'pending', null)` stores `expires_at IS NULL`.
- `expire_stale_holds()` leaves that hold pending and keeps its availability block.

Every `tests/*_logic.php` must end `ALL PASS` — except `team_logic.php`, which has two failures
that also reproduce on `master` and are tracked separately.

## Files touched

| File | Change |
|---|---|
| `includes/db.php` | `create_hold_with_block()` nullable expiry |
| `admin/hold-new.php` | Create pending with no expiry; corrected copy |
| `admin/holds.php` | "Awaiting confirmation" for a pending hold with no expiry |
| `includes/frontdesk.php` | Status predicate widened to staff-created pending; select `h.status` |
| `admin/gate.php` | Guest count + check-in badge + Pending badge on arrivals; prefilled sign-in |
| `admin/frontdesk.php` | Pending badge on arrival rows |
| `admin/guest-board.php` | Category-conditional date/price fields; validation instead of silent null |
| `tests/frontdesk_logic.php` | Assertions (the DB-backed harness) |

No migration. No schema change.

## Out of scope

- **Group D — pricing:** extending the `service_options` catalog beyond laundry and transfer.
- The two pre-existing `team_logic.php` failures (owner home routing, and a Nairobi-vs-database
  timezone bug in the visitor day filter that also affects `admin/gate.php`'s log in production).
  Tracked separately.
- Changing what a *web enquiry* pending hold does — its 24h TTL is unchanged.

Dropped by the requester in the original decomposition: wellness services, and reordering the
portal home tabs.
