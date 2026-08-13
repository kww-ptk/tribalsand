# Check-in / check-out times in the pre-arrival flow — design

**Date:** 2026-08-12
**Status:** approved, ready for planning
**Requested by:** Valentina (via WhatsApp, in Italian) — translated in "Problem" below

## Problem

> *"Check-in is 14:00 to 20:00. Check-out is 10:00–11:00. If that isn't in the pre-check-in and I
> write that I arrive at 10:00 and the system doesn't stop me, the guest assumes they get in at
> 10:00 — and they don't, because the rooms may still be occupied, or need cleaning, or the villa
> isn't ready. And it should also say that early check-in and late check-out are available for a
> fee, subject to availability."*

Three concrete gaps:

1. **The times are nowhere.** No check-in or check-out window is configured anywhere in the
   system. `venues.stay_checkout` exists but is empty for all seven properties, and there is no
   check-in equivalent at all.
2. **The arrival field gives no guidance.** `includes/app/checkin.php:211` renders a bare
   "Arrival date & time" `datetime-local` with no window shown and no feedback on any value. A
   guest types 10:00, the wizard accepts it silently, and they arrive expecting a room.
3. **The paid upgrade is never mentioned**, so a guest who *would* pay for early check-in never
   learns it exists — and instead turns up early and is disappointed.

### The ambiguity that shapes the fix

"Arrival date & time" sits directly beneath Flight number, so today it reads as **when the flight
lands**. Valentina's example ("I write that I arrive at 10") is about **reaching the property**.
These are different times: a flight landing at 10:00 in Mombasa puts the guest at Zuri around
12:00–13:00. Warning on the landing time would flag guests who are not actually early, and
staying silent misses the ones who are.

## Decisions taken

| Question | Decision |
|---|---|
| Which time to check | Ask both. Keep flight landing (the transfer desk needs it); add expected arrival **at the property**, and check the window against that |
| Early arrival | Warn clearly, let them continue, point them at the paid option. **Not** a hard block |
| Scope of the times | One global setting, not per property |

**Why not a hard block.** Guests genuinely arrive before 14:00 — flights land when they land. A
block gives them no honest answer, so they would enter 14:00 and the property loses the real
arrival time, which is worse than the current state. The goal is that nobody is *surprised*, not
that nobody arrives early.

## Architecture

### 1. The times are settings — no migration

Five keys in the existing key-value `settings` table, edited in `admin/checkin-settings.php`
beside the waiver text:

| Key | Default |
|---|---|
| `checkin_time_from` | `14:00` |
| `checkin_time_to` | `20:00` |
| `checkout_time_from` | `10:00` |
| `checkout_time_to` | `11:00` |
| `checkin_early_late_note` | *Early check-in and late check-out are available for a fee, subject to availability — just ask us.* |

The note is a setting rather than hardcoded copy because it is commercial policy wording, and
because it needs translating for Italian-speaking guests eventually.

A helper returns them with defaults applied, so an unset key never renders an empty window:

```php
/** ['ci_from','ci_to','co_from','co_to','note'] with defaults. */
function checkin_times(): array
```

### 2. One migration: expected arrival at the property

```sql
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS property_arrival_time TIME;
```

A `TIME`, not a timestamp — the date is already known (it is the check-in day), and a bare time is
what the warning compares and what reception reads off.

**Which field means what, per arrival mode.** This avoids asking the same thing twice:

| Mode | `arrival_at` | `property_arrival_time` |
|---|---|---|
| Flying | Flight landing, relabelled **"Flight arrival (landing time)"** | Asked: *"What time do you expect to reach us?"* |
| By road | Relabelled **"When do you expect to reach us?"** — this already *is* property arrival | Not asked; the time part of `arrival_at` is used |
| Something else | Same as by road | Same as by road |

So the guest is never asked twice, and the warning always evaluates the time they actually reach
the property.

### 3. The warning

A pure helper, so the rule is testable and identical on the server and the client:

```php
/** '' | 'early' | 'late' for an expected arrival against the check-in window. Pure. */
function checkin_arrival_flag(?string $hhmm, string $from, string $to): string
```

`''` when unknown or inside the window. Boundary values (exactly 14:00, exactly 20:00) are inside.

Rendered on the arrival step:

- **Always**, under the arrival fields: *"Check-in is from 14:00 to 20:00. Check-out is between
  10:00 and 11:00."*
- **When `early`**: *"You've told us you'll arrive at 10:00, before check-in opens at 14:00. Your
  room may still be occupied or being prepared, so it might not be ready when you get here."*
  followed by the early/late note and a link to **Message the team** to request it.
- **When `late`**: *"You'll arrive after 20:00 — let us know so someone is there to meet you,"*
  with the same message link.

The server renders the correct state on load from the saved value; a `change` handler on the time
input updates it live. Both call the same wording, built from `checkin_arrival_flag()` plus
`checkin_times()`, so they cannot drift.

**Why Messages and not the Requests tile:** during pre-check-in the portal is gated to `checkin`
and `messages` only (`booking.php:163`). A link to Requests would bounce the guest back to the
wizard.

### 4. Where else the times appear

- **Stay essentials** (`includes/app/_stay_essentials.php`) gains a **Check-in & check-out** row
  built from the settings. That block currently renders "Stay details will appear here soon" for
  every property, because all four `venues.stay_*` fields are empty — this gives it real content
  on day one.
- **Admin check-in tab** (`admin/_ws_checkin.php`) shows `property_arrival_time` next to the
  existing Arrival row, so reception sees when the guest actually expects to turn up.

Not touched: the guest board, emails, and the public property pages. The times belong in the
booking flow first; putting them in confirmation emails is a sensible follow-up.

## Testing

`tests/checkin_logic.php`, which is the pure harness:

- `checkin_times()` returns all five keys, with defaults when unset and overrides when set
  (following the existing `checkin_waiver_text()` save/restore pattern so the suite leaves no
  state behind).
- `checkin_arrival_flag()` — before the window is `early`; after is `late`; inside is `''`; the
  exact boundaries `14:00` and `20:00` are `''`; `null` and `''` are `''`; a malformed value is
  `''` rather than a false positive.

Then a browser pass at 375px: the window renders, an early time produces the warning with a
working Messages link, a time inside the window produces none, and the value survives a save and
reload.

## Files touched

| File | Change |
|---|---|
| `db/migrations/add_property_arrival_time.sql` | New — one nullable TIME column |
| `includes/checkin.php` | `checkin_times()`, `checkin_arrival_flag()`, support guard |
| `admin/checkin-settings.php` | Five new fields |
| `includes/app/checkin.php` | Relabelled arrival fields, the new time field, the window and warning |
| `js/checkin-wizard.js` | Live warning on change |
| `api/checkin-save.php` | Persist `property_arrival_time` |
| `includes/app/_stay_essentials.php` | Check-in & check-out row |
| `admin/_ws_checkin.php` | Show the expected property arrival |
| `tests/checkin_logic.php` | Assertions |

## Out of scope

- **Blocking early arrivals.** Deliberately rejected above.
- **Charging for early check-in automatically.** The guest is told the policy and pointed at the
  team; pricing and confirmation stay a human decision, like every other concierge request.
- **Per-property times.** One global setting, per the decision above; an override is easy to add
  later if a villa ever differs from the hotel rooms.
- **Italian translation.** The note is a setting so the wording can change, but the portal has no
  i18n layer and adding one is its own project.
