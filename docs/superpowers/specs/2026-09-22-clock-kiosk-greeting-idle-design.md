# Clock kiosk — personal greeting + idle screen (design)

**Date:** 2026-09-22 · **Status:** approved for implementation
**Builds on:** `2026-09-21-staff-clock-in-out-kiosk-design.md` (shipped)

## 1. Problem

Two complaints about the kiosk as shipped, both about the same 10 seconds of a person's day.

**It doesn't know who you are.** The card token is never resolved to a person until the
punch is already being written, so after a scan the tablet can only say "Card read — choose
clock in or clock out". The person is asked to make a decision the system could make for
them, and gets no acknowledgement that the machine recognised them at all. Worse, they can
pick the wrong one: `clock_record_punch()` refuses it, but only *after* the tap, as an
error.

**The camera is always on.** `getUserMedia` fires on page load and the stream is held for
the life of the page. On a tablet mounted at reception that is a camera watching the room
all day: a privacy problem for staff and guests, and a constant battery and heat cost on
hardware that is plugged in and never reloaded.

## 2. Goal

Scan a card and be greeted by name, with the system deciding in-or-out and offering the one
button that applies — and a kiosk that is a calm branded screen at rest, with the camera off
until someone asks for it.

**Non-goals:** face matching (still rejected, for the reasons in the v1 spec); offline
queuing; changing the `attendance` data model; changing the manager's editor.

## 3. Decisions taken in brainstorming

| Question | Decision |
|---|---|
| Who decides in-or-out? | **The system.** This supersedes the v1 spec's "Explicit — scan, then tap Clock in or Clock out" |
| Auto-punch on scan? | **No.** The system picks the action; the person still confirms with one tap |
| Previous day still open | **Time-bounded.** Within 14h ⇒ night shift, offer *Clock out*. Older ⇒ forgotten punch, offer *Clock in* and leave the stale row for a manager |
| Idle screen | Breathing logo — soft glow swells behind the mark, logo drifts gently |
| Camera lifetime | **Off until START is pressed**, released again on every return to idle |
| Lookup rate limit | **None.** See §7 |
| Idle clock + property name | **Yes**, server-stamped (§6.4) |

### Why time-bounded

A real night shift and a forgotten clock-out are **identical in the data** — both are a row
with an `in` and no `out`. Only elapsed time separates them. Today `clock_record_punch()`
closes yesterday either way, storing the out as minutes + 1440, so a forgotten punch books a
24-hour shift into the hours totals. That is survivable while two buttons hide it behind a
choice; it stops being survivable once the kiosk shows only the button it picked. 14 hours
covers a generous night shift with overtime; beyond that it is almost certainly a forgotten
punch.

## 4. The resolver

New **pure** function in `includes/attendance-clock.php`, beside `clock_next_slot()`:

```php
clock_card_state(array $today, array $yest, int $nowMinutes): array
```

Returns:

| Key | Meaning |
|---|---|
| `action` | `'in'` \| `'out'` \| `null` — what the kiosk offers |
| `slot` | `in1`/`out1`/`in2`/`out2`, or `null` |
| `last_min` | raw minutes of the **most recently filled slot**, or `null` |
| `last_kind` | `'in'` \| `'out'` — which kind that slot was |
| `scope` | `'today'` \| `'yesterday'` — which day an `out` would close |
| `blocked` | `null` \| `'status'` \| `'done'` |
| `stale` | `true` when yesterday was left open and is past the 14h bound |
| `status` | the day's status code, for the message |

**It is built on `clock_next_slot()`, never a reimplementation of the slot rules.** Those
rules already exist, are tested, and are what the write path uses; a second copy is how the
read and the write start disagreeing.

Resolution order:

1. Today's status is a non-worked code ⇒ `action: null`, `blocked: 'status'`.
2. `clock_next_slot($today, 'out')` is non-null ⇒ `action: 'out'`, `scope: 'today'`.
   *(The headline case: "Hi Moses, checked in at 08:15".)*
3. `clock_next_slot($today, 'in')` is non-null:
   - yesterday open **and** elapsed ≤ `CLOCK_NIGHT_SHIFT_MAX_MIN` (840) ⇒ `action: 'out'`,
     `scope: 'yesterday'`, reading `last_min` from yesterday's row;
   - yesterday open and older ⇒ `action: 'in'`, `scope: 'today'`, `stale: true`;
   - otherwise ⇒ `action: 'in'`, `scope: 'today'`.
4. Neither slot available ⇒ `action: null`, `blocked: 'done'`.

Elapsed for step 3 is `($nowMinutes + 1440) - $yest_in_min`.

**`last_min` is the most recently filled slot, not specifically the open clock-in.** The
greeting needs a time in states where nothing is open — "back from break" shows `out1`,
"day finished" shows `out2` — and the most-recent-slot rule yields the right value in every
case, including the mid-shift one, where the newest filled slot *is* the open clock-in.
`last_kind` then tells the caller whether to write "Checked in at" or "Checked out at",
which is the whole of the presentation decision.

`last_min` is returned **raw**, not formatted. `attendance_min_to_hhmm()` already renders
the `+1` past-midnight form, which is right for the manager's editor and wrong for a
greeting ("22:10+1" means nothing to a person). Presentation belongs to the endpoint;
the resolver stays pure and free of it.

Supporting pure helper: `clock_first_name(string $full): string` — first whitespace token,
falling back to the full string when there is no space, and to `''` when empty.

## 5. The lookup endpoint

`api/clock-card.php` — POST, JSON, **reads only**. Guards mirror `api/clock-punch.php`
exactly and in the same order:

1. POST only, else 405
2. `attendance_punches_supported()`
3. `clock_kiosk_enabled()` — the owner's kill switch, 403
4. `clock_device_by_token()` — 403
5. `clock_staff_by_token()` — 404

Then reads today's row and yesterday's, calls `clock_card_state()`, and answers:

```json
{ "ok": true, "name": "Moses", "full_name": "Moses Kamau",
  "action": "out", "last": "08:15", "last_kind": "in", "scope": "today",
  "blocked": null, "stale": false, "message": "Checked in at 08:15" }
```

It writes nothing, stores no photo, and touches no device timestamp.

A **separate** endpoint rather than a mode on `clock-punch.php`: a read and a write with
different failure semantics should not share a door, and the punch endpoint's job is
narrow enough to keep.

## 6. Kiosk changes

### 6.1 State machine

`js/clock-kiosk.js` gains an explicit state machine. Camera state in brackets:

```
IDLE  [camera OFF]  breathing logo · "Tap to clock in or out" · START
  └─ START ─────────────────────────────────────────────────────┐
                                                                ▼
SCANNING  [camera ON]  "Hold your card up to the camera" · Cancel
  ├─ 45s, no card ─────────────────────────────────────► IDLE [OFF]
  ├─ Cancel ───────────────────────────────────────────► IDLE [OFF]
  └─ card decoded → POST clock-card.php ────────────────┐
       └─ lookup fails → message, resume scanning        │
                                                         ▼
CONFIRM  [camera ON]  "Hi Moses · Checked in at 08:15" · Clock out · Cancel
  ├─ Cancel, or 20s idle ──────────────────────────────► IDLE [OFF]
  └─ tap → capture photo → camera OFF → POST punch ─────┐
                                                         ▼
RESULT  [camera OFF]  "Thanks Moses — clocked out at 17:42"  (4s) ─► IDLE
```

### 6.2 Camera lifetime

Released with `stream.getTracks().forEach(t => t.stop())`, never merely paused — pausing the
`<video>` leaves the hardware indicator lit and gives up the whole point of the change. The
stream is stopped **immediately after `capture()` returns**, before the punch request is
sent: the evidence photo is already in hand and nothing else needs the lens.

`getUserMedia` moves from page load to the START handler. This is strictly better: the
permission prompt now follows a deliberate tap, and on HTTPS the grant persists so later
presses go straight to the camera. A denied or blocked permission surfaces on the START
press with a plain message, not silently.

The two timeouts are not polish. Without them one person tapping START and walking away
leaves the camera live until the next reload — which on a mounted tablet is never.

### 6.3 Idle screen

Breathing treatment, in `clock.php`'s inline CSS (the page is deliberately standalone and
loads no `main.css`):

- a radial glow behind the mark, scaling `0.9 → 1.1` over ~7s;
- the logo fading up on first paint, then a `1 → 1.035` drift on the same cadence;
- `@media (prefers-reduced-motion: reduce)` disables both and renders it static.

Two transforms and an opacity, all compositor-only — no layout, no repaint. The logo is
`asset_url('images/whitelogo11.png')`, the same mark `includes/header.php` and
`admin/_layout.php` use. It is **decorative**: an `onerror` drops it for a text wordmark so a
slow or unreachable asset origin can never leave a wall tablet looking broken.

### 6.4 Idle clock and property name

The idle screen carries the current time, the date, and the property the tablet is
registered to ("Zuri reception").

**The time must be server-stamped.** The app is Africa/Nairobi throughout and punches are
timed by PHP; a JS-rendered clock would follow the *tablet's* clock and could disagree with
what a punch actually records. So `clock.php` renders the server's time into the page at
load and the script ticks forward from that value.

The property name comes back from `api/clock-register.php` at setup and is stored in
`localStorage` beside the device token — no extra request on load, and nothing sensitive
(it is a property name on a screen already showing it).

### 6.5 Punch request

Unchanged on the wire: same fields, same photo. The kind now comes from the lookup rather
than the person, but **the server re-decides**. `clock_record_punch()` recomputes the slot
from the row as it does today, so the client's kind is a request, never an instruction, and
a state that shifted between scan and tap is refused exactly as it is now.

On lookup failure the kiosk reports it and lets them retry. It does **not** fall back to two
buttons — that puts the guess back on the person, which is the thing being fixed.

## 7. On not rate-limiting the lookup

The lookup needs a valid device token **and** a valid 128-bit card token. Anyone holding
both can already write a punch, so the endpoint exposes no surface the punch endpoint does
not. The existing per-card limiter (`clock_rate_limited()`) stays on the write path
untouched. Revisit if the kiosk ever accepts a card from an untrusted source.

## 8. Copy

| State | Greeting | Sub-line | Button |
|---|---|---|---|
| Fresh day | Hello, Moses | Ready to start your day? | Clock in |
| Clocked in | Hi Moses | Checked in at 08:15 | Clock out |
| Back from break | Hello, Moses | Checked out at 13:00 | Clock in |
| Night shift | Hi Moses | Checked in at 22:10 yesterday | Clock out |
| Forgot yesterday | Hello, Moses | Yesterday was left open — a manager will fix it | Clock in |
| Day finished | Hi Moses | You're done for today — checked out at 17:30 | *(none)* |
| On leave | Hi Moses | Today is marked Leave. See a manager | *(none)* |
| Card unknown | — | Card not recognised. See a manager | *(none)* |

Confirmation after the tap: **"Thanks Moses — clocked out at 17:42."**

The two no-button states show only *Done*, returning to idle.

## 9. Testing

Extends `tests/attendance_clock_logic.php`, matching how that file already works — pure
assertions, no database. This matters: the attendance migrations are not applied on every
development database, and these cases must run anywhere.

- `clock_card_state()` for all seven rows in §8;
- both sides of the 14h boundary (13h59 ⇒ night shift; 14h01 ⇒ stale, offer `in`);
- `last_min` / `last_kind` pick the newest filled slot in every state — notably `out1` for
  "back from break" and `out2` for "day finished", where nothing is open;
- a `last_min` that crosses midnight (an `in2` at 00:30 stored as 1470) renders `00:30`,
  not `24:30` or `00:30+1`;
- `clock_first_name()`: two-part name, single name, empty, leading whitespace;
- the resolver never contradicts `clock_next_slot()` — for every state it reports an
  `action`, the matching `clock_next_slot($row, $action)` is non-null.

That last one is the important one. It is the property that keeps the read path and the
write path from drifting apart, and it is cheap to assert.

## 10. Files

| File | Change |
|---|---|
| `includes/attendance-clock.php` | + `clock_card_state()`, `clock_first_name()`, `CLOCK_NIGHT_SHIFT_MAX_MIN` |
| `api/clock-card.php` | **new** — read-only lookup |
| `api/clock-register.php` | also return device + venue name for the idle screen |
| `clock.php` | idle screen markup, breathing CSS, server time stamp |
| `js/clock-kiosk.js` | state machine, camera lifetime, greeting render |
| `tests/attendance_clock_logic.php` | + pure cases per §9 |

No migration. No change to `attendance`, `attendance_punches`, or the manager's editor.
