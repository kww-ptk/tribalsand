# Tribalsand ↔ Zuri Two-Way Sync — Execution Plan

**Date:** 2026-09-22 · **Owners:** Aly (Tribalsand / PostgreSQL), Bhumika (Zuri / MySQL)
**Spec:** *Tribalsand ↔ Zuri Two-Way Sync — Technical Specification* · **Runbook:** [`docs/restaurant-sync.md`](../../restaurant-sync.md)
**Status:** transport foundation SHIPPED to master (91e893e). Stage 1 ready to run.

---

## The strategy in one line

**Shadow first, one direction at a time, and lock the shared contract before writing
any mapper.** Never turn on both directions on live data at once. Each stage runs
"until it's boring" before the next begins.

## The single most important thing to grasp

The **long pole is the joint field-map / payload contract** (§11). Every mapper and
the applier — on *both* sides — is blocked until that is signed off. So the #1 move
is to put a real payload (`menu.json`) in front of Bhumika **today** and start that
clock, then do all the contract-independent work in parallel while it's agreed.
Do NOT sit idle waiting for the contract, and do NOT write mappers before it.

---

## Recommended order — do these first, in this sequence

These are ordered to finish fast by front-loading the long pole and parallelising:

1. **Apply the migration** (`add_restaurant_sync.sql`) on RDS — *5 min, Aly.* Unblocks everything below.
2. **Export `menu.json` and send it to Bhumika** — *Aly, same day.* This starts the contract clock (the long pole) and gives her backfill matcher real data.
3. **Owner decision: egress IP** — HMAC-only vs NAT+EIP (see Decisions). Not on the critical path; decide before Stage 2.
4. **In parallel while the contract is discussed** (none of these need the contract):
   - Aly: build the 3 missing models + reservation status extension (Phase A).
   - Aly: build the applier skeleton — inbox drain, `sync_resolve()` wiring, conflict logging (Phase B, mapper calls stubbed).
5. **Lock the contract** (Phase C) — the gate. Nothing mapper-shaped merges before this.
6. Then Stages 2→6 in order (Phases D→H).

> Critical path: **1 → 2 → C (contract) → D**. Phases A and B run alongside 2/C so they're
> done by the time the contract lands, collapsing the schedule.

---

## Phases

Each phase lists its owner: **(Aly)**, **(Bhumika)**, **(Joint)**, **(Owner)** = business/infra decision.

### Phase 0 — Foundation ✅ DONE (shipped 91e893e)
- [x] §4 migration: `sync_*` columns + outbox/inbox/id_map/conflicts tables
- [x] HMAC scheme, loop-guard, ownership rules, conflict resolver, reservation state machine
- [x] `/sync/v1/{events,changes,health}` endpoints (HMAC-gated)
- [x] Dispatcher with §5 retry schedule + `SYNC_SHADOW` mode
- [x] Menu→envelope mappers (v1 proposal) + backfill/shadow export + tests

### Stage 1 — Shadow (ready now)
- [x] **(Aly)** Apply `add_restaurant_sync.sql` on RDS via `admin/migrate.php` — DONE 2026-09-22
- [ ] **(Aly)** `php bin/sync-export.php > menu.json`; hand to Bhumika ← **NEXT**
- [ ] **(Bhumika)** Same four sync tables + `sync_*` columns on the MySQL side (SKIP LOCKED needs MySQL 8.0; 5.7 = claim-token workaround)
- [ ] **(Bhumika)** Run the backfill matcher against `menu.json`; report unmatched rows
- [ ] **(Joint)** Confirm the HMAC handshake end-to-end (spec test #12: wrong secret → 401 + alert)

### Phase A — Contract-independent Tribalsand build (parallel with Stage 1)
- [ ] **(Aly)** `restaurant_table` model + admin UI (we own → Zuri) + `sync_*` columns
- [ ] **(Aly)** `opening_hours` model + admin UI (we own → Zuri) + `sync_*` columns
- [ ] **(Aly)** `customers` model (Zuri owns → we receive) + `sync_*` columns
- [ ] **(Aly)** Extend reservation status `pending|confirmed|cancelled` → add `seated|completed|no_show` (CHECK + admin UI)

### Phase B — Applier skeleton (parallel; mapper calls stubbed)
- [ ] **(Aly)** `bin/sync-apply.php` — drain `sync_inbox`, resolve via `sync_resolve()`, write `sync_conflicts`, maintain `sync_id_map`
- [ ] **(Aly)** Wrap every local write in `SyncContext::applying()` (loop prevention — spec test #1)
- [ ] **(Aly)** Handle the unknown-uuid cases (create → insert; update → pull full record via `/changes`)

### Phase C — Lock the shared contract 🚦 GATE (Joint)
- [ ] **(Joint)** Field maps for every entity agreed in a shared Git repo (not each other's code)
- [ ] **(Joint)** Payload contract (`menu.json` shape) signed off
- [ ] **(Joint)** Ownership rules class agreed identical on both sides
- [ ] **(Owner/Joint)** Resolve the two ownership decisions below (menu soft-delete, reservation intake)

### Stage 2 — Menu one-way (TS → Zuri)  *[Phase D]*
- [ ] **(Aly)** Menu editor: **hard-delete → soft-delete** refactor (`admin/menu-edit.php`)
- [ ] **(Aly)** Repo-layer `sync_outbox_push()` hooks on menu/category/item writes, in-transaction, bump `sync_version`
- [ ] **(Both)** Test kill switch BEFORE flipping (spec: test it before Stage 2, not during an incident)
- [ ] **(Aly)** `SYNC_ENABLED=true`, `SYNC_TS_TO_ZURI=true`, dispatcher out of shadow
- [ ] **(Bhumika)** Applier writes menu into MySQL; verify a price edit lands (test #1, #6, #7)

### Stage 3 — Availability reverse (Zuri → TS)  *[Phase E]*
- [ ] **(Bhumika)** Outbox hooks on `item_availability` + seat inventory
- [ ] **(Aly)** Applier writes availability locally; a sold-out toggle from Zuri reflects on our menu (test #3)
- [ ] **(Aly)** `SYNC_ZURI_TO_TS=true`

### Stage 4 — Reservations one-way (Zuri → TS, new bookings)  *[Phase F]*
- [ ] **(Bhumika)** New reservations push to us; **(Aly)** applier inserts them
- [ ] **(Joint)** Confirm reservation-intake ownership decision is honoured

### Stage 5 — Reservations two-way (status both ways)  *[Phase G]*
- [ ] **(Aly)** Staff booking calls Zuri's `/reserve` synchronously (Zuri owns seat inventory)
- [ ] **(Both)** State-machine transitions sync both ways; verify the terminal guard (test #13: stale confirmed vs cancelled stays cancelled)
- [ ] **(Both)** Concurrent-edit conflict writes one winner + a `sync_conflicts` row (test #2, #8)

### Stage 6 — Full + monitoring  *[Phase H]*
- [ ] **(Aly)** Dashboard in admin fed by both `/health` endpoints (queue depth, oldest pending, failed w/ retry, open conflicts, kill switch)
- [ ] **(Aly)** Alerts: oldest pending >5min, queue >500, any failed, 401 bad_signature (critical), no sync 15min
- [ ] **(Aly)** `bin/reconcile.php` nightly (per-entity checksums; reports drift, does NOT auto-repair in v1)
- [ ] **(Both)** Everything on, both directions

---

## Decisions to lock (before Stage 2)

1. **Menu deletes → soft delete.** `admin/menu-edit.php` HARD-deletes menu/category/item rows; a synced hard-delete lets the peer re-create the row. Must become `is_deleted = TRUE`. *(Aly — mechanical, but changes admin behaviour.)*
2. **Reservation intake ownership.** §1 says new reservations are Zuri→TS. But tribalsand.com also takes reservations (`reserve.php`). Decide: are our website reservations ours to push (like staff bookings), or does Zuri become the sole intake? *(Joint.)*
3. **Egress IP.** ECS Express Mode = ephemeral outbound IP, not allowlistable as-is. Pick: (a) HMAC-only, skip the allowlist — no infra, unblocks now; (b) NAT Gateway + Elastic IP (~$32/mo) if Zuri requires a fixed IP. Only matters for the push direction (Stage 2+). *(Owner.)*
4. **Menu images.** We don't store menu-item images today; §1 lists images as TS-owned. Decide files-vs-URLs, ideally a shared CDN both point at. *(Joint — can defer past Stage 2.)*

## Go-live gate (from the spec, before Stage 6)
Ownership table signed · backups restore-tested · all 15 spec tests pass on staging ·
secrets in env not Git · firewall allowlists set · dashboard + alerts live · kill switch
tested · reconcile cron scheduled · rollback written down · both devs free 48h after Stage 2.

## Standing rules (never violate)
- One pricing/data path — never a second copy of a mapper or loop.
- Money is a decimal string; timestamps UTC on the wire, Nairobi only at render.
- Never hard-delete a synced row. Never let a terminal reservation state revive.
- Loop prevention on every applied write. Exactly-once via `event_id`.
- One direction at a time on live data; kill switch tested before each stage.

## Environment (set per stage, all default OFF)
`SYNC_ENABLED` · `SYNC_TS_TO_ZURI` · `SYNC_ZURI_TO_TS` · `SYNC_SHADOW` ·
`SYNC_SHARED_SECRET` · `SYNC_PEER_URL` · `SYNC_PEER_IPS`
