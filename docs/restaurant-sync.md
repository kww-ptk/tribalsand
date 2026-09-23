# Tribalsand ↔ Zuri two-way sync — Tribalsand side

Implements the Tribalsand (PostgreSQL / Aly) half of *Tribalsand ↔ Zuri Two-Way
Sync — Technical Specification*. This doc is the runbook for what is built, how to
turn it on, and what Zuri needs from us to start Stage 1.

## What Zuri asked us for (their three prerequisites)

1. **Our sync endpoint URL** — `https://tribalsand.com/sync/v1` (so `/events`,
   `/changes`, `/health`). Served directly by the existing strip-`.php` rewrite
   from `sync/v1/*.php`; POST bodies pass through intact.
2. **Confirmation we hold the same shared key** — yes. Set `SYNC_SHARED_SECRET`
   in the ECS environment to the identical value Zuri holds. It is never in Git.
3. **Our outbound IP for their allowlist** — ⚠️ **infra action, not code.** We run
   on AWS ECS behind CloudFront; Fargate tasks have ephemeral egress IPs unless
   the service sits behind a NAT gateway with an Elastic IP. Confirm/allocate a
   stable egress IP before promising one to Zuri. Their inbound address for *our*
   allowlist is `13.60.72.12` → set `SYNC_PEER_IPS=13.60.72.12`.

## Built in this change (the transport foundation)

| Piece | File |
|---|---|
| §4 migration — `sync_*` columns + 4 engine tables | [`db/migrations/add_restaurant_sync.sql`](../db/migrations/add_restaurant_sync.sql) |
| HMAC, loop-guard, ownership, conflict resolver, state machine, envelope, outbox/inbox, health | [`includes/sync.php`](../includes/sync.php) |
| HTTP plumbing + HMAC gate for the endpoints | [`includes/sync-api.php`](../includes/sync-api.php) |
| `POST /sync/v1/events` — receive a batch, de-dupe, 202 | [`sync/v1/events.php`](../sync/v1/events.php) |
| `GET /sync/v1/changes?since=` — catch-up pull | [`sync/v1/changes.php`](../sync/v1/changes.php) |
| `GET /sync/v1/health` — queue depth / last sync (§9) | [`sync/v1/health.php`](../sync/v1/health.php) |
| Dispatcher — claim outbox, sign, POST, §5 retry schedule, **shadow mode** | [`bin/sync-dispatch.php`](../bin/sync-dispatch.php) |
| Menu → envelope mappers (our owned entity; v1 field-map proposal) | [`includes/sync-mappers.php`](../includes/sync-mappers.php) |
| **Backfill / shadow export** (§7) — the exact payloads we'd send | [`bin/sync-export.php`](../bin/sync-export.php) |
| Tests (pure always; DB round-trip in a rolled-back tx) | [`tests/sync_logic.php`](../tests/sync_logic.php) |

Load-bearing behaviours already correct and tested: **loop prevention**
(`SyncContext::applying()` — the outbox writer skips while applying an inbound
change), **exactly-once** (unique `event_id` index on `sync_inbox`), **HMAC with
a 300s replay window** compared via `hash_equals`, **ownership 403 `not_owner`**
(Zuri writing a menu price is refused), and the **reservation state machine's
terminal guard** (a late `confirmed` can never revive a `cancelled` booking).

## Aligned with Zuri's handover (21 Sep 2026)

Bhumika's handover document is the agreed field map (mirrored in
`docs/sync-contract.json` in the Zuri repo). What we changed to match it:

- **Mappers** ([`includes/sync-mappers.php`](../includes/sync-mappers.php)) use the
  contract names. `menu_category`: `section`→`group`, `tag`→`subtitle`,
  `is_visible`→`is_active`. `menu_item`: `is_veg`→`is_vegetarian`,
  `has_nuts`/`has_gluten`→`contains_*`, our "Hidden" toggle (`is_available`)→
  `is_active`; **`is_available` is never sent** (sold-out is Zuri's
  `item_availability`, and sending it gets the event rejected `not_owner`);
  `is_gf` has no contract field. `price` is required — an unpriced item is held
  back until its first priced edit. `restaurant_table`: `label`→`number`,
  `seats`→`capacity`, `section`→`zone`.
- **No `menu` entity** — Zuri has none. Menus stay local (versioned and
  soft-deleted, never sent).
- **Only the Zuri property syncs** — `SYNC_VENUE_SLUG` (default `zuri`). Other
  properties' menus/tables never leave.
- **Backfill file** in Zuri's matcher format (handover §9) — `/admin/sync-export.php`
  (default) or `php bin/sync-export.php > backfill.json`; `skipped` lists unpriced
  items for the manual review. `?format=events` / `--events` gives the envelopes.
- **Endpoints:** 401 `bad_signature` | `bad_timestamp` | `ip_not_allowed` (same
  codes as Zuri); `X-Sync-Source` must be `zuri` and every envelope's `source`
  must match it; `/events` rejections are `{event_id, code, message}` plus
  `received`/`applies`; `/changes` returns `next_cursor`, takes `limit`, and
  `?entity=&sync_uuid=` returns one record's current state (for Zuri after a
  `stale_version`). The dispatcher records Zuri's per-event `code`/`message`.

**Opening hours are ONE record** (contract shape): `restaurant_hours`, one row
per venue (migration `add_restaurant_hours.sql`), `lunch`/`dinner` text +
`first_slot`/`last_slot` (HH:MM) + `slot_minutes`/`duration_minutes` (≥15). Its
`sync_uuid` is minted once by the migration and never changes — it is the fixed
uuid Zuri keys the record on, shown to the owner under the hours form in
**Admin → Restaurant → Hours & tables** and included in `backfill.json`
(`opening_hours: [{sync_uuid}]`). The Phase A per-day `opening_hours` table is
left in place, unused (not dropped — no data lost).

Staff bookings go through Zuri's `/reserve` only (agreed).

## Stage 1 — Shadow (ready now)

Stage 1 logs what we would send and applies nothing. Run it read-only, with no
live write-path changes:

```
php admin/migrate.php   (apply add_restaurant_sync.sql on RDS) — one time
php bin/sync-export.php > backfill.json # Zuri's matcher file (§7) — or /admin/sync-export.php in the browser
```

`bin/sync-export.php` needs no env flags and touches nothing. The dispatcher's
`SYNC_SHADOW=true` mode logs outbox rows instead of sending them, for once the
Stage-2 outbox hooks land. Hand `backfill.json` to Bhumika to run her backfill
matcher against real data (the field map itself is agreed — see above).

## Still to build (in rollout order)

- ~~**Menu outbox hooks**~~ — **DONE.** [`includes/menu-sync.php`](../includes/menu-sync.php):
  every write in `admin/menu-edit.php` + `admin/menus.php` (create, save, publish
  toggle, availability toggle, reorder, delete) runs inside `menu_sync_tx()` and
  calls `menu_sync_emit()`, which bumps `sync_version` and queues the §3 event in
  the same transaction (skipped while applying — loop guard). Deletes are now
  **soft** (`is_deleted = TRUE`, children announced before parents; a deleted menu
  is also unpublished and its slug freed); readers in `includes/menu.php` skip
  deleted rows via `menu_live_sql()`. Pre-migration everything degrades to the old
  hard-delete / no-event path. Events queue even while `SYNC_ENABLED` is off (§10:
  they drain in order when it comes on). Test: `php tests/menu_sync_logic.php`.
- **Reservation outbox hooks** — same pattern for reservations; staff-created
  bookings must first call Zuri's `/reserve` (§1 rule 1).
- **The applier** (`bin/sync-apply.php`) — drain `sync_inbox` in order under a
  lock, run `sync_resolve()`, write locally via per-entity mappers (reservation,
  customer, item_availability — field list in Zuri's handover §6). Retry an event
  whose reference hasn't arrived yet a few times before failing it (the ordering
  race Zuri found in testing).
- ~~**Opening hours as ONE record**~~ + ~~**table editor**~~ — **DONE.**
  `admin/restaurant-setup.php` (owner + manager, scoped by `admin_venue_ids()`;
  sidebar "Hours & tables"): the hours form (`includes/restaurant-hours.php` —
  an unchanged save writes and emits nothing) and the tables list/editor
  (`includes/restaurant-tables.php` — number ≤10 and unique per venue, seats
  1–255, zone ≤60; delete is soft). Every write goes through the ONE emit path,
  `menu_sync_tx()` + `menu_sync_emit()` (its entity map now covers
  `restaurant_table` and `opening_hours`), so only the synced venue emits and the
  loop guard/version bump live in one place. Test: `php tests/restaurant_setup_sync.php`.
- **Reservation Seat / Complete / No-show buttons** (state machine already exists).
- **Staff booking → Zuri `/reserve`** synchronous call (Zuri owns seat inventory).
- **Dashboard + alerts + `bin/reconcile.php`** (§9).

## Environment variables

```
SYNC_ENABLED=false          # master kill switch (§10). OFF by default.
SYNC_TS_TO_ZURI=false       # per-direction: push our menu/tables/hours to Zuri
SYNC_ZURI_TO_TS=false       # per-direction: apply Zuri's availability/reservations
SYNC_SHARED_SECRET=         # the HMAC secret, identical on both sides (never in Git)
SYNC_PEER_URL=https://zuriwatamu.com/sync/v1   # for the dispatcher
SYNC_VENUE_SLUG=zuri        # the property whose menu/tables sync (default zuri)
SYNC_PEER_IPS=13.60.72.12   # Zuri's outbound IP(s), comma-separated; empty = don't block
```

Sync does nothing until `SYNC_ENABLED` **and** the relevant direction flag are on
— matching the spec's stage-by-stage rollout. Flip one direction at a time.

## Running the dispatcher

The in-container scheduler ([`docker/scheduler.sh`](../docker/scheduler.sh)) is the
natural home. Add a job that runs `php bin/sync-dispatch.php` on a short interval,
or `--loop` for a supervised worker (near-real-time reservations). It is idempotent
and self-disables via the kill switch, so it is safe to always schedule.

## Tests

```
php tests/sync_logic.php
php tests/menu_sync_logic.php
php tests/restaurant_sync_models.php
php tests/restaurant_setup_sync.php
```

Pure logic (HMAC, ownership, state machine, resolver, envelope) runs anywhere. The
outbox/inbox round-trip runs only when a migrated DB is reachable, else SKIPs. As
with the other suites, force an unreachable `DATABASE_URL` to run pure-only without
a localhost hang.
