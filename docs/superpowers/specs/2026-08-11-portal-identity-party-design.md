# Portal identity & party visibility — design

**Date:** 2026-08-11
**Status:** approved, ready for planning
**Scope:** Group B of a four-group request (see "Out of scope" below)

## Problem

Five defects, all rooted in one omission: **the guest portal resolves a booking but never
resolves an actor.** Nothing in `booking.php` or its views knows *which* guest is looking at the
page.

1. **A co-guest is greeted with the lead's name.** `booking.php:240` builds the greeting from
   `$hold['guest_name']`, which is always the lead. Jessica invites Patrik; Patrik opens his
   link and the portal says "Karibu, Jessica". The co-guest's own row is already loaded into
   `$me` at `booking.php:38` — it is used for gating and then discarded.
2. **Admin message attribution is inconsistent within a single screen.**
   `admin/_ws_messages.php:44` (the booking workspace tab) renders the sender's name.
   `admin/messages.php:168` (the global inbox, where front-desk staff actually work) hardcodes
   the string `'Guest'` — even though `fetch_thread_messages()` already selects `sender_name`.
   Worse, `admin/assets/admin-chat.js:43` *does* use `sender_name`, so on the inbox a name
   appears on messages that arrive while the page is open but not on the ones rendered with it.
3. **An unnamed lead is labelled "Guest".** `guest_display_name()` (`includes/booking.php:165`)
   returns `'Guest'` when `passport_name` is empty and never consults `holds.guest_name`. On a
   booking where the passport step is disabled, the lead's own requests are attributed to
   "Guest" on the bill and in the admin request list.
4. **No party roster outside the check-in wizard.** Once check-in completes, the wizard renders
   only a done card. No portal view — Home, Activities, Messages, Bill — lists who is on the
   booking or how far they have got.
5. **Co-guest re-entry is copy-paste only.** `checkin_guests` has no email column and no mail
   function sends the `?g=` link, so the lead must relay it by hand. If a co-guest loses it,
   there is no route back except the booking access code.

## Decisions taken

| Question | Decision |
|---|---|
| Party roster placement | A "Party" section in the Home in-page tabs |
| Co-guest re-entry | The lead re-shares from the roster — no email, no migration |
| Access-code path | Unchanged. It keeps granting lead access; only the greeting is corrected |

**On the access code.** Anyone holding the 8-character booking code can currently reach the
portal with full lead powers, including Cancel My Booking, because
`resolve_booking_by_code_only()` mints a lead ref (`booking.php:75-79`). That was raised and
deliberately kept: the code is the booking's shared secret and is printed on the confirmation.
This design does not change who gets in — only what the page calls them.

## Architecture

### 1. Resolve the acting guest once

`booking.php` gains a single resolved actor, available to every view:

```php
$actor = [
    'guest_id' => int|null,   // checkin_guests.id, null when no row exists yet
    'is_lead'  => bool,
    'name'     => string,     // full display name, '' when genuinely unknown
    'first'    => string,     // first word, for greetings
];
```

**This deliberately does NOT reuse `resolve_portal_actor()`.** That helper calls
`checkin_ensure_lead_guest_id()`, which performs an `INSERT ... ON CONFLICT DO NOTHING`. That is
correct in a write endpoint but wrong on a read path — it would mean a database write on every
portal page view. The display path reads the lead row if one exists and otherwise falls back to
`holds.guest_name`, writing nothing.

Resolution rules:

- **Co-guest** (`$isCoGuest` is already true, `$me` already fetched at `booking.php:38`) → their
  `passport_name`; `''` when they have not filled it in yet.
- **Lead** → the lead `checkin_guests` row's `passport_name` when set, else `holds.guest_name`.

The greeting at `booking.php:242` and the topbar title use `$actor['first']`, falling back to
the existing `'guest'` placeholder when the name is unknown.

### 2. One pure helper for attribution

```php
/**
 * Display name for an attributed row: the guest's own name, the booking name for
 * an unnamed lead, else "Guest". Pure.
 */
function attributed_display_name(string $guestName, bool $isLead, string $bookingName): string
```

Resolution order: a non-empty `$guestName` wins; otherwise an `$isLead` row falls back to
`$bookingName`; otherwise `'Guest'`. The returned value is the **first word only**, matching the
existing `guest_display_name()` convention so attribution reads consistently across the app.

**Plumbing.** Rather than thread `$hold` into every call site, the two message queries gain two
columns:

- `fetch_thread_messages()` and `fetch_thread_messages_since()` (`includes/booking.php:319-338`)
  add `cg.is_lead AS sender_is_lead` and join `holds h` for `h.guest_name AS hold_guest_name`.
- `message_payload()` (`includes/booking.php:346`) resolves the name from those columns.

Because `message_payload()` is the shared shaper, **all four message render paths inherit the
fix from one place**: the guest thread, the admin workspace tab, the admin inbox, and both live
polls. This is the same "keep initial render and appended bubbles identical" rule already
documented in CLAUDE.md.

Both new columns sit behind the existing `message_sender_guest_supported()` guard. Pre-migration
there is no `sender_guest_id` to join on, so attribution is impossible either way;
`message_payload()` then returns `sender_name => ''` exactly as it does today, and the views fall
back to their existing generic labels. No screen changes shape on an unmigrated database.

`guest_display_name()` is left untouched — it is unit-tested and used in eight places, and
narrowing its behaviour would ripple further than this change warrants.

### 3. Party roster section on Home

New partial `includes/app/_party.php`, added to the Home in-page tabs as:

`Your stay · Party · Calendar · Requests`

Rendered only when the booking has more than one adult (`$need > 1`), matching the rule the
check-in wizard already uses for its party step — a solo booking gets no empty tab.

Each adult row shows:

- the guest's name, or a roster-numbered fallback ("Guest 2") when unnamed;
- a **You** badge when the row is the viewing actor (`$actor['guest_id']` match);
- a status chip: "Checked in ✓" when `checkin_guest_complete()`, else "Pending";
- their children as small chips beneath, matching the wizard's presentation.

**For the lead only**, each *pending* adult also gets a copy-link row carrying
`make_guest_pass_url()`. This is the re-share mechanism: the lead resends by WhatsApp or email
themselves. It reuses the `.ci-copy` markup and the document-level copy handler already added
for the check-in confirmation card, so no new JavaScript is needed.

Co-guests see the same roster **read-only** — names, badges and status, no links. Distributing
access to the booking stays the lead's decision.

When check-in is not required for the booking, the roster still renders but the status chips are
omitted (there is nothing to be pending on).

**Empty roster.** A booking can have `guest_count > 1` with no `checkin_guests` rows at all — the
lead has not started check-in. The section then shows a single line ("Your party will appear here
once check-in starts") rather than an empty tab. The **You** badge matches on
`$actor['guest_id']`, which is `null` in that state, so no row is wrongly badged.

### 4. Admin inbox sender names

`admin/messages.php:168` renders the actual sender instead of the literal `'Guest'`, matching
`admin/_ws_messages.php:44`. With §2 in place both simply read the payload, and the
load-vs-poll inconsistency disappears.

The thread header at `admin/messages.php:157` keeps showing the booking's lead name — it labels
the *booking*, not the message author, and that is correct.

### 5. Extract the roster-numbering helper

The lead confirmation card added in the previous group numbers unnamed guests inline in
`includes/app/checkin.php`, keyed on roster position. The party roster needs identical
numbering, and duplicating the rule invites the two to drift.

```php
/**
 * A guest's display label: their name, else "Guest N" by roster position.
 * $short = true returns the first word only (for sentences); default returns the
 * full name (for list rows). Pure.
 */
function checkin_guest_label(?array $guest, array $adults, bool $short = false): string
```

Two forms because the two surfaces genuinely differ: the confirmation card's sentence reads
"We're still waiting on **Patrik** and **Sarah**" (short), while its itemised list and the party
roster show "Patrik Otieno" (full). The `$short` flag keeps both from a single definition of the
numbering rule, which is the part that must not drift.

The confirmation card switches to it — its sentence passes `$short = true`, its list does not.

## Testing

Pure helpers, asserted in the existing plain-script harnesses (no fixtures, no PHPUnit):

`tests/checkin_logic.php`
- `attributed_display_name()` — own name wins; unnamed lead falls back to the booking name;
  unnamed co-guest returns "Guest"; returns the first word only.
- `checkin_guest_label()` — named guest returns their first name; unnamed returns "Guest N" by
  roster position, not filtered-list position; a guest absent from the roster degrades safely.

`tests/portal_logic.php`
- The actor-resolution shape: lead with a passport name, lead without one (falls back to
  `holds.guest_name`), co-guest with a name, co-guest without one.

Run: `php tests/checkin_logic.php && php tests/portal_logic.php`. Both must end `ALL PASS`.

## Files touched

| File | Change |
|---|---|
| `booking.php` | Resolve `$actor`; greeting and topbar use it |
| `includes/booking.php` | `attributed_display_name()`; two message queries gain `sender_is_lead` + `hold_guest_name`; `message_payload()` uses them |
| `includes/checkin.php` | `checkin_guest_label()` |
| `includes/app/_party.php` | New — the roster partial |
| `includes/app/home.php` | Register the `party` section tab |
| `includes/app/checkin.php` | Confirmation card switches to `checkin_guest_label()` |
| `includes/app/messages.php` | Sender label reads the resolved payload |
| `admin/messages.php` | Line 168 shows the real sender |
| `tests/checkin_logic.php`, `tests/portal_logic.php` | Assertions for the new helpers |

No migration. No schema change.

## Out of scope

- **Group C — admin:** admin-created bookings defaulting to pending (`admin/hold-new.php:38`),
  the guest board event save/join failure, gate sign-in for booked guests with vehicle plate.
- **Group D — pricing:** extending the `service_options` catalog beyond laundry and transfer.
- **The access-code privilege level**, deliberately retained as described above.
- Emailing co-guests their link — considered and rejected for this group; it needs an email
  column on `checkin_guests`, a mail template, and a delivery-failure path.

Dropped by the requester in the original decomposition: wellness services, and reordering the
portal home tabs.
