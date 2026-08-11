# Check-in wizard fixes — design

**Date:** 2026-08-11
**Status:** approved, ready for planning
**Scope:** Group A of a four-group request (see "Out of scope" below)

## Problem

Five defects and gaps in the guest pre-arrival check-in flow that shipped in PRs #51–54.

1. **The arrival step assumes everyone flies.** `checkin_step_complete('arrival')` requires
   `flight_number` *and* `arrival_at` (`includes/checkin.php:134`). A guest driving in has no
   flight number, so when the arrival step is configured as required they cannot finish.
2. **No airport picker.** `arrival_airport` is a free-text box (`includes/app/checkin.php:107`),
   so arrival data arrives unnormalised and unusable for transfer planning.
3. **The lead's signature appears to vanish when a second adult is added.** "+ Add adult"
   navigates the page (`js/checkin-wizard.js:62`). On reload the canvas is blank and the
   `waiver_signature` hidden input renders empty, because neither is seeded from the stored
   value. The signature is still in the database, but the guest sees it gone and loses trust in
   the flow.
4. **The consent gate does not exist.** "Save & continue" calls `saveThen()` with no validation
   (`js/checkin-wizard.js:54`). Server-side, a save that lacks the agreement checkbox, the typed
   name or a valid signature is *silently skipped* (`api/checkin-save.php:54`) with no error
   returned. A guest can walk through the whole wizard without ever agreeing to the terms or
   signing.
5. **No completion confirmation for the lead.** The done card only renders when
   `checkin_is_complete($hold)` — i.e. when the *entire party* is finished
   (`includes/app/checkin.php:53`). A lead who completes their own part first sees no
   acknowledgement and no indication that anyone else is outstanding.

## Decisions taken

| Question | Decision |
|---|---|
| Arrival branching | Three modes: flight / road / other |
| Airport list | Vipingo, Malindi, Mombasa (Moi Intl), plus "Other" free text. Hardcoded, not admin-managed |
| Consent enforcement | Block the step in the client **and** hard-fail on the server |
| Portal gate | Unchanged — the lead unlocks the portal on their own check-in, not the party's |
| Production migration state | Assumed fully migrated; all new reads still use the `*_supported()` guard pattern |

## Architecture

### 1. Data model

New migration `db/migrations/add_checkin_arrival.sql`, idempotent, run via `/admin/migrate.php`:

```sql
ALTER TABLE booking_checkin
  ADD COLUMN IF NOT EXISTS arrival_mode    TEXT,  -- 'flight' | 'road' | 'other'
  ADD COLUMN IF NOT EXISTS arrival_vehicle TEXT,  -- road mode: vehicle description / plate
  ADD COLUMN IF NOT EXISTS arrival_note    TEXT;  -- other mode: how they are arriving
```

The "Other" airport value writes into the existing `arrival_airport` TEXT column — no separate
column. `arrival_mode` is nullable and unconstrained at the DB level; validation lives in PHP so
an unexpected value degrades to the legacy path rather than throwing.

A new guard in `includes/checkin.php`:

```php
function checkin_arrival_mode_supported(): bool   // static-cached, SELECT arrival_mode … LIMIT 1
```

Pre-migration, the wizard renders today's single flight form and `checkin_step_complete('arrival')`
keeps today's rule. No screen fatals on an unmigrated database.

### 2. Wizard flow

`includes/app/checkin.php` builds `$flow` by walking `checkin_enabled_steps()` and collapsing
`passport` + `waiver` into a single `party` slot. That collapse is what puts the lead's signature
on the same screen as the add-adult button.

Replace it with two slots:

| Slot | Label | Contents |
|---|---|---|
| `you` | Your details | Lead's passport fields, waiver text, agree checkbox, typed name, signature pad, **the lead's own children** |
| `party` | Your party | Other adult cards (fill-in / share-link / remove) and each of *their* children |

Resulting order: `arrival → transfer → you → party → dietary → requests`.

Mapping from the step config: `passport` contributes the identity fields to `you`, `waiver`
contributes the consent block to `you`.

Both slots are conditional, and the step numbering (`Step i of n`) is computed from the final
flow so it stays correct in every combination:

- `you` is omitted when *neither* `passport` nor `waiver` is enabled — there is nothing for the
  lead to fill in or sign.
- `party` is omitted when `guest_count` is 1 — a solo booking has no other adults to manage and
  should not see an empty step.

**The lead's children move to the `you` step.** Today the `.ci-kids` block for the lead lives
inside the combined party step (`includes/app/checkin.php:156`). If `party` were dropped for solo
bookings while the lead's children stayed in it, a single adult travelling with children would
lose the only way to declare them. Putting the lead's children alongside the lead's own details is
also the more natural grouping: each adult card owns its children, and the lead's card is now on
the `you` step.

### 3. Signature persistence

Two independent fixes, either of which alone would resolve the reported symptom. Both are
implemented, because they address different failure modes.

**(a) Render the stored signed state.** When `checkin_guest_waiver_signed($lead)` is true, the
`you` step renders a signed panel instead of a blank pad:

> ✓ Signed by *Jessica Mwangi* on 11 Aug 2026 — *[stored signature PNG]* — **Re-sign**

"Re-sign" swaps the panel for a blank pad and calls `window.ciSignInitAll()` (already exported by
`js/signature-pad.js:44`). The hidden `waiver_signature` input stays empty unless the guest
actually draws a new signature; `api/checkin-save.php:54` already requires
`checkin_valid_signature()` to pass before writing, so an untouched panel leaves the stored
signature intact. This makes a hard refresh — from any cause — safe.

**(b) Remove the page reload from "+ Add adult".** `api/checkin-guest.php:27` already returns
`{ok, guest_id, name, link}`. The wizard clones a hidden `<template id="ciGuestTpl">` adult card,
substitutes the returned id/name/link, appends it to the party step and updates the counter.
Nothing on the page is destroyed, so no in-progress input can be lost. The `?ci=<step>` resume
parameter and its handler (`js/checkin-wizard.js:141`) are removed along with the reload that
required them.

### 4. Consent gate

**Client** — `js/checkin-wizard.js` gains `validateStep(section)`, invoked by the `.ci-next`
handler and by the submit handler. For the `you` step it requires:

- the agree checkbox is checked;
- `waiver_signed_name` is non-empty;
- a signature exists — either the hidden input is populated (freshly drawn) or the step is
  rendering the already-signed panel;
- when the passport step is enabled and required, the passport name, number and an uploaded scan
  are all present.

On failure it renders an inline `.ci-err` naming every missing item, moves focus to the first
offender, and does not advance. The same check runs on submit so the last step cannot be bypassed.

**Server** — `api/checkin-save.php` currently drops an invalid consent payload with no feedback.
Add an explicit branch: when any of `waiver_agree`, `waiver_signed_name` or `waiver_signature` was
posted but the set is incomplete or `checkin_valid_signature()` fails, set `$_SESSION['ci_error']`
naming the reason and redirect back rather than writing a partial record. The existing
`do=submit` gate through `checkin_missing_steps()` is unchanged and remains the backstop for a
JS-disabled or crafted request.

A new pure helper backs both sides so the wording cannot drift:

```php
/** Missing consent fields for a signing attempt. [] = complete. Pure. */
function checkin_consent_missing(bool $agreed, string $typedName, string $signature, bool $alreadySigned): array
```

### 5. Arrival step

A three-way radio, "How will you arrive?", switching the fields below it:

| Mode | Fields | Required when the arrival step is required |
|---|---|---|
| `flight` | Airport select (Vipingo / Malindi / Mombasa (Moi Intl) / Other → text), flight number, arrival date & time | airport, flight number, arrival time |
| `road` | Arrival date & time, vehicle or plate (optional) | arrival time |
| `other` | Arrival date & time, "tell us how you're arriving" | arrival time |

Mode switching is CSS-class based on the radio, with no JS dependency for the *display* of the
selected mode's fields on first paint — the server renders the saved mode as `checked`.

`checkin_step_complete('arrival', …)` delegates to a new pure helper:

```php
/** Is the arrival step's required data present for the chosen mode? Pure. */
function checkin_arrival_complete(?array $data): bool
```

- `flight` → `arrival_airport` && `flight_number` && `arrival_at`
- `road` / `other` → `arrival_at`
- mode absent (pre-migration, or a legacy row) → today's rule: `flight_number` && `arrival_at`

Note this only gates submission when `arrival` is configured `required`; it is optional by
default (`includes/checkin.php:11`).

`api/checkin-save.php` persists `arrival_mode`, `arrival_vehicle` and `arrival_note` in the
existing `booking_checkin` upsert, adding the three columns only when
`checkin_arrival_mode_supported()` — mirroring how `insert_booking_addon()` composes its column
list.

Admin `admin/_ws_checkin.php:69` gains an "Arriving" row showing the mode, and swaps the Airport
and Flight rows for Vehicle / Note when the mode is not `flight`.

### 6. Completion confirmation

`includes/app/checkin.php` renders one of three terminal cards.

**Whole party complete** (`checkin_is_complete($hold)`) — the existing "You're all checked in"
card, unchanged.

**Lead complete, others outstanding** — a new card, shown when all three hold:
`checkin_missing_steps($config, $data, $lead)` is empty (the booking-level required steps are
done), `checkin_guest_complete($lead, $config)` is true (the lead's own passport and signature are
done), and `checkin_party_complete_count($guests, $config)` is below `guest_count`. All three are
existing helpers; no new completion logic is introduced.

> ✓ **Thank you, Jessica. Your check-in is complete.**
> We're still waiting on **Patrik** and **Sarah**. Once everyone in your party has checked in,
> your reservation is fully confirmed.
>
> *[per outstanding adult: name (or "Guest 2" when unnamed) + Copy link button]*
>
> **Continue to your stay →**   **Download my signed waiver**

**Co-guest complete** — the existing card in `includes/app/checkin-guest.php:33`, unchanged; it
already offers "Continue to your stay" when sharing is on.

A new pure helper drives the outstanding list:

```php
/** Adult guest rows that are not yet complete, in roster order. Pure. */
function checkin_outstanding_adults(array $guests, array $config): array
```

**Deliberate wording deviation.** The original request asked for "once all of the people in your
party have completed the check in process, you will be able to manage your reservation". The
portal-gate decision keeps the lead's access unlocked as soon as *they* finish, so that sentence
would be false. The copy says "your reservation is fully confirmed" instead: it preserves the
nudge to chase co-guests without promising a lock that does not exist.

### 7. Testing

`tests/checkin_logic.php` is a pure-function harness with a `check()` assertion helper and no
fixtures. All three new helpers are pure by design so they are covered there:

- `checkin_arrival_complete()` — one case per mode, plus the legacy null-mode fallback, plus
  missing-field cases for `flight`.
- `checkin_consent_missing()` — complete set returns `[]`; each field missing individually is
  named; `alreadySigned` satisfies the signature requirement without a fresh payload.
- `checkin_outstanding_adults()` — excludes children, excludes complete adults, preserves roster
  order, returns `[]` for a fully complete party.

Run with `php tests/checkin_logic.php`. Existing assertions must continue to pass; the six-step
config assertions are unaffected because the `you`/`party` split happens in the view layer, not in
`checkin_config()`.

## Files touched

| File | Change |
|---|---|
| `db/migrations/add_checkin_arrival.sql` | New — three nullable columns on `booking_checkin` |
| `includes/checkin.php` | `checkin_arrival_mode_supported()`, `checkin_arrival_complete()`, `checkin_consent_missing()`, `checkin_outstanding_adults()`; `checkin_step_complete('arrival')` delegates |
| `includes/app/checkin.php` | Arrival mode branch; `you`/`party` step split; signed-state panel; lead-complete confirmation card; `<template>` for adult cards |
| `js/checkin-wizard.js` | `validateStep()`; DOM-append add-adult replacing the reload; re-sign toggle; drop `?ci=` resume |
| `api/checkin-save.php` | Persist arrival mode fields; explicit consent errors instead of a silent skip |
| `admin/_ws_checkin.php` | Arrival mode row; vehicle/note in place of flight fields when not flying |
| `tests/checkin_logic.php` | Assertions for the three new pure helpers |

`js/signature-pad.js` needs no change — `window.ciSignInitAll` (line 44) already covers re-init of
injected markup.

## Out of scope

Queued for their own spec → plan → implementation cycles:

- **Group B — identity & party visibility:** the "Karibu Jessica" bug for co-guests
  (`booking.php:240`), message sender clarity when a lead has no passport name
  (`includes/booking.php:165`), a party roster visible outside the check-in wizard, co-guest
  portal re-entry.
- **Group C — admin:** admin-created bookings defaulting to pending (`admin/hold-new.php:38`),
  the guest board event save/join failure, gate sign-in for booked guests with vehicle plate.
- **Group D — pricing:** extending the `service_options` catalog pattern beyond laundry and
  transfer.

Dropped by the requester: wellness services, and reordering the portal home tabs.
