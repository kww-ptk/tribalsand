# Channel management — Booking.com, Airbnb (VRBO, Expedia, Hopper later)

Goal: Tribal Sand's admin is the ONE place that decides what is free and what it
costs; Booking.com, Airbnb and the rest follow it, and their bookings land in our
calendar automatically. Two stages.

## Stage 1 — two-way iCal (live in code now, no third party)

What it does: dates only. Each OTA listing reads our per-unit export feed and closes
the dates we sold; we read each listing's feed every 15 minutes and close its
bookings here. Prices are still set on each site. Code: `includes/ical-sync.php`,
`api/ical.php` (export), `api/sync-ical.php` (scheduler entry),
`admin/ical-feeds.php` (Bookings → Calendar → iCal feeds). Test:
`php tests/ical_sync_logic.php`.

### Go-live checklist (production)
1. Run `add_ical_sync_tracking.sql` via `/admin/migrate.php`.
2. Confirm `ICAL_SYNC_SECRET` is set in the ECS task env (the page says
   "Automatic imports are off…" when it is not).
3. For every unit that is listed on an OTA, connect BOTH directions (the page has
   a "How to connect a listing" panel with the click paths):
   - OTA export link → Admin → iCal feeds → *Import* (pick the unit).
   - Our unit's *Copy link* → paste into the OTA's *Import calendar*.
4. Watch the feed list for red lines (a link reset on the OTA side) and
   Bookings → Conflicts for same-window clashes.

### Rules the code relies on
- An imported block remembers `ical_feed_id` + `ical_uid`. A UID that leaves the
  feed = cancelled on the OTA → block deleted; changed dates = moved. Staff
  closures, website bookings and Ezee imports are never touched (no feed id).
- A fetch that is not a calendar (HTML login page, 404) is an error and removes
  NOTHING — a broken link must never wipe a channel's bookings.
- **Echoes:** an OTA re-exports the dates we sent it. An event whose dates exactly
  match a block from elsewhere (this unit or a buyout-linked unit) is recorded in
  `ical_echoes`, not imported. When the original goes away the echo is ignored for
  `ICAL_ECHO_GRACE_HOURS` (6) while the OTA re-reads our feed; still there after
  that = a real booking, imported. Airbnb "Reserved" is never treated as an echo.
  Without this, a cancelled booking bounces between channels forever.
- Export publishes bookings, **24h holds**, closures, other channels' imports and
  the whole-property rule (`room_conflict_unit_ids()`), each as an anonymous
  "Not available" — guest names and staff notes never leave the system.
- "Sync now" runs under the staff session (POST `action=sync_now`); the sync
  secret is no longer printed into the Calendar / iCal pages.

### Known limits of iCal (why Stage 2 exists)
- Dates only — no prices, no guest details, no payments.
- Delay: the OTAs read our feed on their own schedule (typically every few
  hours). A booking on two channels inside that window = a conflict to resolve.
- Booking.com offers iCal only for rooms that are one unit each; a room type with
  several identical units (Maya Ilai studios/villas) needs Stage 2.
- Maya Ilai per-bedroom products can't be expressed in iCal; an OTA booking on a
  villa takes the whole villa.

## Stage 2 — a channel-manager API (business decision pending)

Booking.com and Airbnb only let certified "connectivity partners" talk to their
APIs; a single operator can't connect directly (Booking.com had also paused new
partner applications, Sept 2026). So we connect our system to a certified channel
manager's API and it relays to every OTA: rates, availability, restrictions out;
reservations with guest details back (webhook), into holds + the bookings ledger.

| Option | Fit | Cost (published) | Notes |
|---|---|---|---|
| **Beds24** | Recommended first call | from ~€15.50/mo, scales with channels | Preferred/premier partner with Booking.com, Airbnb, VRBO; Expedia. API V2 open to any account holder (invite code → token); booking webhooks with JSON body. Hopper: not confirmed. |
| **Channex** | Best API, but built for software vendors | $130/mo + $7/property (or $0.50/VR unit) | Free sandbox (staging.channex.io). Booking.com, Airbnb, Expedia, Hopper and 70+ channels. Says single hotels should buy a channel manager instead — we have our own PMS + booking engine, so ask. |
| **eZee Centrix** | Already used (Zuri export) | per contract | XML API provisioned per partner through their connectivity portal — needs onboarding. Ask what our current plan includes. |
| Rentals United / NextPax | Hopper Homes, VRBO | per unit | Vacation-rental focused; consider if Hopper becomes a priority. |

Engineering shape once one is chosen (mirrors the Zuri outbox/inbox sync):
1. Map each of our rooms/units to the channel manager's room types.
2. Push availability + nightly prices from `rates_nightly_map()` (the ONE pricing
   path) on every change, plus a nightly full refresh.
3. Receive reservations by webhook → create a hold + block + ledger row
   (`bookings_import_upsert()`-style), conflicts → Bookings → Conflicts.
4. Turn off iCal for each listing that moves to the API (never both on one
   listing — that double-closes dates).
