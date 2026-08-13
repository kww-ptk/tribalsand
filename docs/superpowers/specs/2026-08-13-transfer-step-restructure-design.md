# Restructuring the arrival and transfer steps — design

**Date:** 2026-08-13
**Status:** approved, ready for planning
**Builds on:** `2026-08-12-checkin-times-design.md` (merged as PRs #59–#61)

## Problem

The pre-check-in wizard asks every flying guest for their airport, flight number and landing
time on the **arrival** step, before it has established whether we are doing anything with that
information. The **transfer** step then asks, separately, whether they want us to arrange a car.

Two things are wrong with that order:

1. **We collect flight details we may have no use for.** A guest who flies in and drives
   themselves is asked to look up a flight number so it can sit unread in the database. The
   questions that matter to them — how they are arriving, when they want their room — are mixed
   in with questions that only matter to the transfer desk.
2. **There is no return leg.** The wizard never asks whether the guest needs a car when they
   check out, so every departure transfer is arranged ad hoc over WhatsApp.

## Decisions taken

| Question | Decision |
|---|---|
| What step 1 asks | How they will arrive, plus the desired check-in time — nothing flight-specific |
| Where flight details live | Step 2, and only when the guest wants a transfer |
| A flier who declines a transfer | Is **not** asked for a flight number or landing time |
| What the return leg asks | Destination and departure time — not flight-derived |
| Is a transfer airport-only | No. The destination is free-form, so a non-flying guest can request one too |

**Why the flier who declines is not asked.** They are arranging their own car; the flight number
serves the transfer desk and nobody else. The cost is that reception no longer sees "lands 09:00,
wants in at 10:00" for that guest — they see only the desired check-in time. That is accepted:
the early-arrival warning, which is what protects against the original surprise, still fires
because it reads the desired check-in time rather than the landing time.

## Architecture

### 1. Step 1 — "How you'll arrive"

```
How will you arrive?   ( ) Flying   ( ) By road   ( ) Something else
  by road         → Vehicle / number plate (optional)
  something else  → How are you arriving?

Desired check-in time   [ 10:00 ]

Check-in is from 14:00 to 20:00. Check-out is between 10:00 and 11:00.
⚠ You've asked to check in at 10:00, before check-in opens at 14:00. …
```

`property_arrival_time` is now asked of **every** mode, not just flight. `arrival_at` leaves this
step entirely.

### 2. Step 2 — "Transfers"

```
Would you like us to arrange a transfer on arrival?   ( ) Yes   ( ) No
  yes + flying      → Airport, Flight number, Landing date & time
  yes + not flying  → Where should we collect you?

Do you need a transfer when you check out?            ( ) Yes   ( ) No
  yes → Destination      [ Mombasa airport ]
        Departure time   [ 12:00 ]
```

The arrival-transfer block reuses `needs_transfer`, `arrival_airport`, `flight_number`,
`arrival_at` and `transfer_details`. Only the return leg is new.

`transfer_details` carries the pickup location for a non-flying guest, and is not shown at all
when flying — the airport, flight number and landing time say everything the desk needs, and an
extra free-text box next to them invites the guest to repeat themselves. Anything else they want
to add belongs in the existing Requests step.

**The cross-step dependency.** Whether the flight fields show depends on the transfer answer
(step 2) *and* the arrival mode (step 1). The wizard's existing handlers toggle within a step;
this is the first control that reads across one. The mode radios stay the source of truth — the
step-2 block re-reads `.ci-f-mode:checked` rather than duplicating the value into a hidden field,
so the two can never disagree.

### 3. The warning simplifies

Today the flag branches on mode:

```php
$flagTime = $isFlight ? $paSaved : $atTime;
```

Once the desired check-in time is universal, there is one field and one rule for every mode. The
`$isFlight` / `$atTime` branch disappears from the flag path, and `arrival_at` goes back to
meaning only what its label says: the flight landing time.

**Backward compatibility.** Bookings saved before this change have their arrival time in
`arrival_at` and `property_arrival_time` empty; reading only the new field would silently stop
warning on them. So the flag keeps a read-time fallback:

```
property_arrival_time, or — when it is empty and the mode is not flight — arrival_at's time part
```

A read-time fallback rather than a data migration, because it is reversible, it cannot corrupt a
row, and it matches how this codebase already absorbs schema drift. The fallback is legacy-only
and can be deleted once no pre-change rows are in play.

### 4. Migration

```sql
ALTER TABLE booking_checkin
  ADD COLUMN IF NOT EXISTS needs_departure_transfer BOOLEAN,
  ADD COLUMN IF NOT EXISTS departure_destination TEXT,
  ADD COLUMN IF NOT EXISTS departure_time TIME;
```

Behind a cached `checkin_departure_transfer_supported()` guard, like every other migration-added
column here.

`departure_time` is a `TIME` for the same reason `property_arrival_time` is: the date is already
known — it is the check-out day — and a bare time is what the driver reads off.

`departure_time` means **when the car leaves the property**, not when the flight departs. The
guest is asked for a pickup time and a destination; working back from a flight is the team's job.

> **`needs_departure_transfer` is a BOOLEAN.** `PDO::ATTR_EMULATE_PREPARES` renders a PHP `false`
> as `''`, which Postgres rejects for a boolean column. Bind `'TRUE'` / `'FALSE'` / `null`, never
> a PHP bool. This exact mistake took check-in down in production (PR #56); `api/checkin-save.php`
> already handles `needs_transfer` correctly and is the pattern to copy.

### 5. Step completeness

`checkin_arrival_complete()` no longer requires airport, flight number or `arrival_at` — the step
is complete once a mode is chosen. The desired check-in time stays optional, consistent with the
decision that the guest is warned but never blocked.

`checkin_step_complete('transfer', …)` extends to the return leg: both yes/no questions must be
answered; a "yes" on arrival requires the flight fields when flying, or the pickup location when
not; a "yes" on departure requires a destination and a time.

The legacy no-mode branch of `checkin_arrival_complete()` stays as it is, so rows written before
`add_checkin_arrival.sql` keep their old behaviour.

## Testing

`tests/checkin_logic.php` (the pure harness):

- `checkin_arrival_complete()` — a chosen mode is sufficient; no mode still uses the legacy rule.
- Transfer completeness across the matrix: both unanswered, arrival yes/no × flying or not,
  departure yes/no, and departure yes with a destination but no time.
- The flag's legacy fallback: `property_arrival_time` wins when set; `arrival_at`'s time is used
  when it is empty and the mode is not flight; flight mode never falls back to the landing time.
- `checkin_departure_transfer_supported()` returns a bool.

Then, as before: a browser pass at 375px, and a check that `needs_departure_transfer` round-trips
as a real boolean rather than `''`.

## Files touched

| File | Change |
|---|---|
| `db/migrations/add_departure_transfer.sql` | New — three columns |
| `includes/checkin.php` | Support guard; completeness rules; flag fallback |
| `includes/app/checkin.php` | Both steps rebuilt |
| `js/checkin-wizard.js` | Transfer/departure toggles; cross-step mode read; simplified warning |
| `api/checkin-save.php` | Persist the three new fields |
| `admin/_ws_checkin.php` | Show the return leg |
| `tests/checkin_logic.php` | Assertions |

## Out of scope

- **Pricing the transfers.** Same as early check-in: the guest states what they need and the team
  confirms. No quote, no payment.
- **Renaming `property_arrival_time`.** It now means "desired check-in time" for every mode, but
  it is an internal name on a shipped, migrated column; renaming costs a migration and every call
  site for no visible gain.
- **Backfilling `property_arrival_time` from `arrival_at`.** Handled by the read-time fallback.
- **Confirmation emails and the guest board.** Unchanged, as in the previous spec.
