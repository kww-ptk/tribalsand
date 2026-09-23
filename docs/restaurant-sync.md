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
- ~~**Reservation outbox hooks + `/reserve`**~~ — **DONE.**
  - **One booking entry point:** `reservation_book()` ([`includes/sync-reserve.php`](../includes/sync-reserve.php)),
    used by the public form (`api/submit-reservation.php`) and the new staff
    **New booking** card on `admin/reservations.php`. For the synced venue with
    `SYNC_RESERVATIONS` on it calls Zuri's `POST /sync/v1/reserve` (signed,
    `Idempotency-Key` = the `sync_uuid` we mint first, `external_id` =
    `TSR-<venue>-<rand>`): **201** → stored locally with Zuri's reference/status,
    `sync_source='tribalsand'`, `sync_last_at` set; **409** → nothing stored, the
    form shows Zuri's `alternatives`; **anything else** (down/5xx/400) → saved as a
    local pending request with `staff_notes` "NOT ON ZURI YET …" and an ALERT in
    the log, so a guest's booking is never lost. Every other venue (and sync off)
    is the old `create_reservation()` path, byte for byte.
  - **Status out:** `set_reservation_status($id, $to, $reason)` bumps the version,
    stamps `confirmed_at`/`seated_at`/`cancelled_at` + `cancellation_reason`, and
    — only when `reservation_on_zuri()` (synced venue + `sync_last_at` set) —
    queues a `reservation` update carrying **status (+ cancellation_reason) only**,
    in the same transaction. Local-only requests are never announced. Skipped
    while applying (no echo).
  - Admin: Seat / Complete / No-show buttons (one per move the state machine
    allows), status filter covers all six states, "on Zuri" / "not on Zuri yet"
    markers. `create_reservation()` now stamps `sync_source='tribalsand'` (the
    column defaults to `zuri`).
  - The partner endpoint `api/reservation-api.php` still creates local requests
    only — once Zuri sends its bookings through sync, retire that integration.
  - Test: `php tests/sync_reserve_logic.php` (HTTP stubbed).
- ~~**The applier**~~ — **DONE.** [`includes/sync-apply.php`](../includes/sync-apply.php)
  + `bin/sync-apply.php` (scheduled, gated on `SYNC_ZURI_TO_TS`), migration
  `add_sync_applier.sql`. Drains `sync_inbox` oldest first, one transaction per
  event, inside `SyncContext::applying()` (nothing it writes is echoed back).
  - `item_availability` → `menu_items.is_sold_out` (+ `sold_out_at`), with its
    **own** version counter `sold_out_version` — it never bumps the menu item's
    `sync_version`, and it is separate from our "Hidden" toggle. `menu.php` shows
    a "Sold out" pill; the admin menu editor shows it read-only.
  - `customer` → `customers` (partial updates merge; never null a field the event
    didn't carry).
  - `reservation` → `reservations`; `special_requests` → our `notes`, contract
    `notes` → `staff_notes`; `customer_uuid`/`table_uuid` resolve to local ids.
    New Zuri bookings land on the synced venue with `sync_source='zuri'`. On a
    booking **we** created, Zuri may change only status, cancellation_reason, the
    status timestamps, its reference and the table — anything else is ignored and
    noted on the inbox row. **Terminal never revives** (`terminal_state` conflict).
  - Resolution per S§6: unknown+create → insert (+ `sync_id_map`); unknown+partial
    update → signed pull `GET /changes?entity=&sync_uuid=` then insert; lower
    version → `rejected stale_version`; equal version + different data → a
    `sync_conflicts` row (both sides kept, `resolved_at` NULL until reviewed).
  - **Ordering race (H§8):** a reservation whose customer hasn't arrived waits
    (`attempts` + back-off 10s/20s/40s/80s) and fails `missing_reference` after 5
    tries. A waiting row holds back every LATER row for the same record.
  - Test: `php tests/sync_apply_logic.php` (peer stubbed via the
    `function_exists`-guarded `sync_peer_request()`).
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
- ~~**Dashboard + alerts + `bin/reconcile.php`**~~ — **DONE.** **Admin → Zuri sync**
  (`admin/sync.php`, owner-only, Admin nav group; helpers
  [`includes/sync-monitor.php`](../includes/sync-monitor.php)): switches (the secret
  shows set/missing only), our queue KPIs, **Check Zuri now** (signed `GET /health`,
  Zuri's `alerts[]` shown verbatim), failed sends with Retry / Retry all (back to
  `pending`, attempts reset), rejected inbound with Apply again, open conflicts side
  by side with Mark reviewed (`resolved_at`), and the last reconcile report with Run
  now. Our own `/sync/v1/health` now also returns `switches`, `conflicts_open`,
  plain-English `alerts[]` and the last reconcile `checksums`.
  `bin/reconcile.php` (scheduler Job 6, daily, quiet while `SYNC_ENABLED` is off;
  `--force` to run anyway) is **report-only**: per owned entity, live-row count +
  checksum `md5(string_agg(sync_uuid||'|'||sync_version, ',' ORDER BY sync_uuid))`
  (`sync_checksum()` computes the same in PHP) and the rows whose current version
  was never delivered. Zuri has no checksum endpoint yet — when its `/health`
  carries a `checksums` map of the same shape, the report compares them; agree that
  with Bhumika. Test: `php tests/sync_monitor_logic.php`.

- ~~**Hardening**~~ — **DONE.** Dockerfile: `ServerTokens Prod` + `ServerSignature Off`
  (`tribalsand-hardening.conf`, loads after Debian's `security.conf`) and PHP
  `expose_php=Off` — error pages no longer print the ECS hostname / versions.

## Environment variables

```
SYNC_ENABLED=false          # master kill switch (§10). OFF by default.
SYNC_TS_TO_ZURI=false       # per-direction: push our menu/tables/hours to Zuri
SYNC_ZURI_TO_TS=false       # per-direction: apply Zuri's availability/reservations
SYNC_SHARED_SECRET=         # the HMAC secret, identical on both sides (never in Git)
SYNC_PEER_URL=https://zuriwatamu.com/sync/v1   # for the dispatcher
SYNC_VENUE_SLUG=zuri        # the property whose menu/tables sync (default zuri)
SYNC_PEER_IPS=13.60.72.12   # Zuri's outbound IP(s), comma-separated; empty = don't block
SYNC_RESERVATIONS=false     # reservations stage: route Zuri bookings through /reserve (needs SYNC_ENABLED)
SYNC_SHADOW=false           # Stage 1: the dispatcher logs instead of sending
```

Sync does nothing until `SYNC_ENABLED` **and** the relevant direction flag are on
— matching the spec's stage-by-stage rollout. Flip one direction at a time.

## Running the workers (scheduled)

[`docker/scheduler.sh`](../docker/scheduler.sh) Job 5 runs, every 10 seconds,
`php bin/sync-dispatch.php --quiet` (push our outbox → Zuri) and
`php bin/sync-apply.php --quiet` (apply Zuri's inbox → our DB). Both check the
env switches **before touching the DB** and exit at once while off, so the job is
inert until sync is switched on. Each takes a Postgres advisory lock
(`sync_worker_lock()`), so when ECS runs several tasks only one dispatcher and one
applier run a pass at a time — event order matters, the others just skip.

### Env flags per rollout stage (S§10)

| Stage | `SYNC_ENABLED` | `SYNC_SHADOW` | `SYNC_TS_TO_ZURI` | `SYNC_ZURI_TO_TS` | Notes |
|---|---|---|---|---|---|
| 0 — deployed, inert | off | off | off | off | Outbox rows still accumulate from edits. |
| 1 — shadow | off | **on** | off | off | Dispatcher logs `SHADOW would send …` and marks rows sent. Nothing leaves. |
| 2 — menu one-way | **on** | off | **on** | off | **Run `php bin/sync-requeue.php` first** (see below). |
| 3 — availability reverse | on | off | on | **on** | Applier starts writing `item_availability` / customers / reservations. |
| 4 — reservations | on | off | on | on | + **`SYNC_RESERVATIONS=true`**: bookings for Zuri go through `/reserve`; status changes sync. |

Kill switch: `SYNC_ENABLED=false` stops both workers on the next pass; outbox
rows keep accumulating and drain in order when it comes back on.

### Going live after shadow — `bin/sync-requeue.php`

Shadow marks rows `sent` after only logging them, so before the first real push
queue the complete current dataset again:

```
php bin/sync-requeue.php --dry-run   # counts per entity, writes nothing
php bin/sync-requeue.php             # queues a create per live row, one transaction
```

Order is categories → items → tables → opening hours (dependency order), built
from `sync_export_events()` — the same mappers as the live emit path. Idempotent:
a row with a pending event at the same or newer version is skipped. Unpriced
items are left out (their first priced edit sends them).

## Tests

```
php tests/sync_logic.php
php tests/menu_sync_logic.php
php tests/restaurant_sync_models.php
php tests/restaurant_setup_sync.php
php tests/sync_apply_logic.php
php tests/sync_reserve_logic.php
php tests/sync_monitor_logic.php
```

Pure logic (HMAC, ownership, state machine, resolver, envelope) runs anywhere. The
outbox/inbox round-trip runs only when a migrated DB is reachable, else SKIPs. As
with the other suites, force an unreachable `DATABASE_URL` to run pure-only without
a localhost hang.
