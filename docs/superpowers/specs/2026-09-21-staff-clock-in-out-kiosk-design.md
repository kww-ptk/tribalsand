# Staff clock in / out — QR card kiosk (design)

**Date:** 2026-09-21 · **Status:** approved for implementation

## 1. Problem

The HR module (`add_hr_staff.sql`) holds the workforce — 73+ people across 11 property
units — and `add_attendance.sql` already models a day's work: one row per person per day,
four time slots (`in1`/`out1`/`in2`/`out2`) stored as minutes from midnight, plus status
codes, overtime and leave. `admin/attendance.php` is a complete manager-facing editor.

But **every attendance row is typed in by a manager.** Nobody records their own hours, so
the times are a reconstruction after the fact, and a disputed shift has no evidence behind
it. Staff have no way in at all: most `hr_staff` rows have `admin_user_id = NULL`, so they
own no login.

## 2. Goal

A fixed tablet at a property where a team member scans a printed QR card, taps **Clock in**
or **Clock out**, and has their photo captured as evidence. The punch feeds the existing
`attendance` table, so every report, total and overtime calculation already built keeps
working unchanged.

**Non-goals (v1):** face recognition or any automatic identity matching; offline queuing;
geolocation; scheduling or rostering; replacing the manager's editor (it stays, and still
wins on conflict).

## 3. Decisions taken in brainstorming

| Question | Decision |
|---|---|
| How does a person identify themselves? | **A printed QR card**, one per person |
| Device | Any tablet — QR keeps it platform-agnostic (Web NFC is Chrome/Android only) |
| What does the photo do? | **Evidence only.** Captured and stored; no matching |
| In or out? | **Explicit** — scan, then tap Clock in or Clock out |
| Kiosk security | **Register the device once**, long-lived revocable token |
| QR decoding | **Vendor `jsQR`** — one self-contained MIT file, script tag, no npm/build |
| QR *generating* (printed cards) | A second vendored file, client-side — see §6 |
| Card from another property | **Allowed.** The punch records the device and its venue |

Face matching was rejected deliberately: it costs per call, needs a reference face per
person, and its false rejections lock someone out of their own shift at 6am with no
recourse.

## 4. Data model

**One migration, `add_attendance_punches.sql`, adds all three of the following** (the
`hr_staff` column plus the two new tables). It depends on `add_attendance.sql`.

### 4.1 `hr_staff.punch_token`

```sql
ALTER TABLE hr_staff ADD COLUMN IF NOT EXISTS punch_token TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_hr_staff_punch_token
    ON hr_staff (punch_token) WHERE punch_token IS NOT NULL;
```

**The QR encodes this token, never `hr_staff.id`.** A card encoding an id is forged by
typing a different number; the token is 32 random hex characters from
`random_bytes()`. It is minted lazily on first card print and can be **regenerated** per
person, which instantly invalidates a lost card.

### 4.2 `attendance_devices`

A registered kiosk: `id`, `name`, `venue_id`, `token_hash`, `is_active`, `last_seen_at`,
`created_by`, `created_at`.

Only the **hash** is stored (`password_hash()`), like a password — a leaked database row
must not yield a working kiosk token.

### 4.3 `attendance_punches`

The event log — one row per tap:

`id`, `hr_staff_id`, `device_id`, `venue_id`, `punched_at TIMESTAMPTZ`,
`kind` (`in`|`out`), `work_date DATE`, `slot` (`in1`|`out1`|`in2`|`out2`),
`photo_key TEXT`, `created_at`.

**Why a separate table rather than more columns on `attendance`.** A day has up to four
punches but one summary row, and each punch carries its own photo and its own device. The
punch log is also the audit trail: `attendance` is mutable by any manager, so without this
there is no record of what the person actually did versus what was later typed over it.

`attendance` itself is **unchanged**. Punches update it; it remains what the existing UI
reads.

## 5. The kiosk — `clock.php`

A single full-screen page at the web root, deliberately not under `/admin/`.

### 5.1 Device registration

Unregistered, the page shows only a sign-in prompt. An **owner or manager** signs in, names
the device and picks its property. The server issues a token; the page stores it in
`localStorage` and reloads into kiosk mode. The token is long-lived — the tablet is never
expected to be touched again.

`admin/attendance-devices.php` (owner/manager) lists devices with their last-seen time and
revokes one. Revoking sets `is_active = FALSE`; the next punch from that tablet fails with
"This device is no longer registered."

### 5.2 The punch flow

1. Camera preview runs continuously; `jsQR` decodes frames on a canvas.
2. A decoded token resolves to a person. The screen shows their name, their property, and
   today's state — "Not clocked in today", or "Clocked in at 07:02".
3. They tap **Clock in** or **Clock out**. Both are always offered; the server decides
   whether the choice is sensible (§5.3).
4. A still is captured from the same video stream and posted with the punch.
5. Confirmation: "Joseph Mwangi — clocked IN at 07:02." The screen returns to scanning
   after a few seconds.

**Camera permission is granted once** per device, when registered.

### 5.3 `api/clock-punch.php`

Validates in this order, failing closed at each step: device token → device active → card
token resolves → the person is active → not a duplicate punch.

**Slot resolution** — `clock_next_slot($staffId, $kind, $todayYmd)`, pure and tested:

| State | `in` fills | `out` fills |
|---|---|---|
| Empty row | `in1` | see night-shift rule below; otherwise refuse ("You haven't clocked in") |
| `in1` set, `out1` empty | — (refuse: already in) | `out1` |
| `in1`,`out1` set | `in2` | — |
| `in2` set, `out2` empty | — | `out2` |
| All four set | refuse — "Your day is already complete. See a manager." | refuse |

**Night shifts crossing midnight — this is the one case that overrides the table above.**
If `out` is tapped and *today* has no open slot but **yesterday** does, the punch closes
**yesterday's** row, storing the time as
`minutes + 1440`. That is the convention `add_attendance.sql` already documents (a 19:00→07:00
security shift stores `out1 = 1860` on the start date). Only yesterday is considered — a
row left open longer is a manager's problem, not something to guess at.

**`attendance_upsert()` overwrites all four slots on every call.** It is a whole-row upsert,
not a partial update, so a punch MUST read the current row, merge its one new time in, and
pass all four back. Passing only `in1` would silently erase `out1`. This is the single
easiest way to lose someone's hours.

**Duplicate guard.** The same person, same `kind`, within `CLOCK_DUPLICATE_WINDOW` (120
seconds) is accepted and ignored with "Already recorded" rather than written twice — a
double-tap or a card held too long must not open and close a shift.

### 5.4 The photo

Captured as a JPEG from the video stream, posted as a file, stored with
`storage_put_private()` under `attendance/<staff>/<date>/<punch>.jpg` — the same private
path passport scans use. **Never a public URL.** Served only through
`admin/attendance-photo.php?punch=<id>`, which is session-authed and scoped by
`admin_venue_ids()`.

A punch whose photo fails to upload **still records the time**. Losing someone's shift
because a camera hiccuped is worse than a punch with no picture; the record shows "no photo".

## 6. Admin surfaces

- **`admin/attendance-devices.php`** — register, rename, revoke, last seen. Owner/manager.
- **`admin/attendance-cards.php`** — a printable sheet of QR cards for a property's staff:
  name, position, and the QR. Mints `punch_token` for anyone who lacks one, and offers
  "reissue" (which regenerates the token and kills the old card).

  **Generating a QR needs a second vendored file** — a small client-side generator
  (`qrcode-generator`, MIT, one file). Generating server-side would mean a PHP QR library,
  and this project has no composer; a hand-rolled encoder is not worth writing. So the
  feature vendors two third-party files in total: one to read a QR, one to draw one. Both
  are plain script tags, consistent with how every other JS file here ships.
- **`admin/attendance.php`** — gains a camera icon on any day with punches, opening the
  photos and their exact timestamps.

## 7. Scoping and permissions

- `clock.php` is gated by the **device token only** — no staff session exists.
- Registration requires `require_manager()`.
- The device's `venue_id` is recorded on every punch. A person from another property may
  still clock in; the punch shows where it happened.
- Admin reads are scoped by `admin_venue_ids()`, matching the rest of the module.
- Every read is pre-migration-safe: `attendance_punches_supported()` and
  `attendance_devices_supported()` probe `to_regclass`, never a failing `SELECT`.

## 8. Testing — `tests/attendance_clock_logic.php`

Pure assertions always; DB work in a rolled-back transaction.

- `clock_next_slot()` across all six states in the §5.3 table, both kinds
- The night-shift case: `out` with yesterday open writes yesterday at `+1440`
- The merge rule: punching `out1` preserves an existing `in1` (the `attendance_upsert()` trap)
- Duplicate guard inside and outside the window
- Token resolution: a valid token finds its person; a forged/unknown one resolves to nothing
- A revoked device is refused
- Regenerating a token invalidates the previous one

## 9. Decisions made without confirmation

1. **A failed photo upload does not fail the punch** (§5.4).
2. **Only yesterday is searched for an open night shift**, not an arbitrary window back.
3. **Both Clock in and Clock out are always shown**, with the server refusing nonsense,
   rather than the client hiding a button — the client cannot be trusted with that rule and
   a greyed-out button teaches nothing.
