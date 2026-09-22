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

## Stage 1 — Shadow (ready now)

Stage 1 logs what we would send and applies nothing. Run it read-only, with no
live write-path changes:

```
php admin/migrate.php   (apply add_restaurant_sync.sql on RDS) — one time
php bin/sync-export.php > menu.json     # the payloads we'd send + Zuri's backfill dataset (§7)
```

`bin/sync-export.php` needs no env flags and touches nothing. The dispatcher's
`SYNC_SHADOW=true` mode logs outbox rows instead of sending them, for once the
Stage-2 outbox hooks land. Hand `menu.json` to Bhumika to (a) confirm the v1
field map and (b) run her backfill matcher against real data.

## Still to build (in rollout order)

- **Repository-layer outbox hooks** — call `sync_outbox_push()` in the same
  transaction as each menu/reservation write, bumping `sync_version`. Needed for
  **Stage 2** (menu one-way TS→Zuri). Note two decisions this forces: the menu
  editor (`admin/menu-edit.php`) currently HARD-deletes rows — must become
  `is_deleted = TRUE` soft deletes; and reservation *ownership direction* (are
  website reservations ours to push, or is Zuri the sole intake per §1?) is a
  joint call to settle before wiring `create_reservation`.
- **The applier** (`bin/sync-apply.php`) — drain `sync_inbox`, run `sync_resolve()`,
  write locally via per-entity mappers. **The field maps are jointly owned (§11)**
  and must be agreed in the shared contract repo before the mappers are written —
  that boundary is deliberate.
- **Three missing models** Tribalsand owns/consumes: `restaurant_table`,
  `opening_hours` (we own → Zuri), `customers` (Zuri → us). Each gets the same six
  `sync_*` columns when created.
- **Reservation status extension** — today's `pending|confirmed|cancelled` →
  add `seated|completed|no_show` (CHECK constraint + admin UI).
- **Staff booking → Zuri `/reserve`** synchronous call (Zuri owns seat inventory).
- **Dashboard + alerts + `bin/reconcile.php`** (§9).

## Environment variables

```
SYNC_ENABLED=false          # master kill switch (§10). OFF by default.
SYNC_TS_TO_ZURI=false       # per-direction: push our menu/tables/hours to Zuri
SYNC_ZURI_TO_TS=false       # per-direction: apply Zuri's availability/reservations
SYNC_SHARED_SECRET=         # the HMAC secret, identical on both sides (never in Git)
SYNC_PEER_URL=https://zuriwatamu.com/sync/v1   # for the dispatcher
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
```

Pure logic (HMAC, ownership, state machine, resolver, envelope) runs anywhere. The
outbox/inbox round-trip runs only when a migrated DB is reachable, else SKIPs. As
with the other suites, force an unreachable `DATABASE_URL` to run pure-only without
a localhost hang.
