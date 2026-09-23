# Zuri sync — finish the Tribalsand side (plan for 2026-09-23)

Hand this file to a fresh session. It is self-contained: read it, then
`docs/restaurant-sync.md` (runbook) and the spec sections it cites.

## Context in five lines

- Two-way restaurant sync between **Tribalsand** (us: PHP 8.2 + PostgreSQL on AWS
  RDS, ECS) and **Zuri** (Bhumika: PHP + MySQL at `https://zuriwatamu.com/sync/v1`).
- Model: outbox → dispatcher → peer's `/sync/v1/events` → inbox → applier, HMAC
  signed (`hash_hmac('sha256', ts . '.' . rawBody, secret)`), **every field has
  exactly one owner** (spec §1 — do NOT make things two-way).
- **Zuri's side is DONE.** Her handover doc ("Tribalsand-Zuri Sync - Handover for
  Tribalsand", 21 Sep) is the agreed contract; mirrored in the Zuri repo at
  `docs/sync-contract.json` (github.com/rel1eftech/Zuri). Section refs below
  (“H§6”) point at that handover; “S§n” = the original spec PDF.
- **Our transport is on master and live but inert** (`/sync/v1/health` answers
  `not_configured` until `SYNC_SHARED_SECRET` is set on ECS).
- Branch `claude/zuri-tribalsand-integration-f898ad` holds today's contract
  alignment (see "Already done"). **It must be committed/pushed before a new
  session can see it.**

## Ownership (H§6 — the contract)

| Entity | Owner | Direction | Fields (contract names) |
|---|---|---|---|
| menu_category | TS | TS→Z | group(food\|drinks), name≤80, subtitle≤80, icon≤40, sort_order, is_active |
| menu_item | TS | TS→Z | category_uuid, name≤120, description, **price (required, "0.00" string)**, is_signature, is_vegetarian, is_vegan, is_spicy, contains_nuts, contains_gluten, sort_order, is_active — **never is_available** |
| item_availability | Zuri | Z→TS | sync_uuid = the menu item's uuid; `is_available` |
| restaurant_table | TS | TS→Z | number≤10 (req), name≤80, zone≤60, capacity 1–255 (req), in_service, sort_order, is_active |
| opening_hours | TS | TS→Z | **ONE record, fixed uuid we mint**: lunch (text), dinner (text), first_slot HH:MM, last_slot HH:MM, slot_minutes≥15, duration_minutes≥15 |
| customer | Zuri | Z→TS | name≤120 (req), email≤190, phone≤40, notes, source≤20 |
| reservation | **creator** | both | reference (Zuri ZR-XXXXXX, read-only), external_id≤64 (ours), customer_uuid, table_uuid, date, time, duration_minutes, guests, preference (Lunch\|Dinner\|Private Dining), special_requests, notes, status, source, cancellation_reason, confirmed_at, seated_at, cancelled_at, created_at. **status + cancellation_reason are shared** (state machine S§6). |

Staff bookings made on Tribalsand go **only through Zuri's `POST /sync/v1/reserve`**
(agreed) — never as direct create events. Zuri never sends menu/tables/hours.

## Already done (on the branch, uncommitted at time of writing)

- `includes/menu-sync.php` — `menu_sync_tx()` + `menu_sync_emit()` (bump
  `sync_version`, queue event in the same transaction, silent while applying),
  soft deletes (`menu_delete_item/category/menu`). Wired into
  `admin/menu-edit.php` + `admin/menus.php`. Readers skip deleted rows via
  `menu_live_sql()` in `includes/menu.php`.
- `includes/sync-mappers.php` — contract field names; only `SYNC_VENUE_SLUG`
  (default `zuri`) syncs; no `menu` entity; unpriced items held back;
  `sync_menu_rows()` loader; `sync_backfill_export()` in Zuri's matcher format (H§9).
- `admin/sync-export.php` (default = backfill.json, `?format=events`) and
  `bin/sync-export.php` (`--events`).
- Endpoints: 401 `bad_signature|bad_timestamp|ip_not_allowed`, `X-Sync-Source`
  must be `zuri`, envelope `source` must match, rejections `{event_id, code,
  message}`, `/changes` has `next_cursor`, `limit`, `?entity=&sync_uuid=`.
- Dispatcher records Zuri's per-event rejection code.
- Tests: `tests/sync_logic.php`, `tests/restaurant_sync_models.php`,
  `tests/menu_sync_logic.php` — all pure parts PASS.

## Ground rules for this work (from CLAUDE.md / memory)

- **Pre-migration-safe everything** — catalog checks (`to_regclass`,
  `information_schema`), never a failing SELECT (it aborts a Postgres transaction).
- **No native UI** — styled `.eselect`, `.dp-btn` datepicker, `.optchip`, Lucide
  icons; copy patterns from existing admin pages.
- **DB = AWS RDS only.** The local `.env` points at a retired Neon DB — **never
  use it**. DB test sections SKIP locally; run pure tests with
  `DATABASE_URL="postgres://x:y@127.0.0.1:70000/none" php tests/<file>.php`.
  Prod migrations run via `/admin/migrate.php` after deploy.
- Deploy = push to master → GitHub Actions → ECS. **Ask the user before pushing
  to master.**
- Never write the shared key into the repo, a plan, or a commit.
- Timezone: app is Africa/Nairobi, but **sync timestamps are UTC with `Z`**
  (`gmdate`) — S§3.

## Tasks — in order

Each task: build → pure tests → lint (`D:\php84\php.exe -l`) → update
`docs/restaurant-sync.md`. Commit per task.

### 0. Commit + deploy what's done (15 min)
- Commit the branch; with the user's OK, merge/push to master (deploys, still inert).
- User sets on ECS: `SYNC_SHARED_SECRET` (from Bhumika), `SYNC_PEER_URL=https://zuriwatamu.com/sync/v1`,
  `SYNC_PEER_IPS=13.60.72.12`. Leave `SYNC_ENABLED` off.
- **Accept:** `GET https://tribalsand.com/sync/v1/health` unsigned → 401
  (not `not_configured`). User downloads `/admin/sync-export.php` → `backfill.json` → Bhumika.

### 1. Opening hours as ONE record (1–1.5 h)
- New migration `db/migrations/add_restaurant_hours.sql` (after
  `add_restaurant_sync_models`): table `restaurant_hours` — one row per venue
  (`venue_id UNIQUE`), `lunch TEXT, dinner TEXT, first_slot TIME, last_slot TIME,
  slot_minutes INT ≥15, duration_minutes INT ≥15` + the six `sync_*` columns.
  Seed Zuri's row from today's reservation rules (`reservation_slots()` in
  `includes/reservations.php`: 12:00–22:00, 30-min slots — confirm lunch/dinner
  text with the user).
- Helpers + mapper `sync_map_opening_hours()` rewritten to the contract; include
  `{"sync_uuid"}` in `sync_backfill_export()['opening_hours']`.
- Owner/manager editor (a card on an existing restaurant admin page is fine),
  save → `menu_sync_tx` + emit `opening_hours` update.
- Decide: keep or drop the per-day `opening_hours` table from Phase A (nothing
  reads it yet) — ask the user; don't delete data silently.
- **Accept:** mapper test for exact contract keys; uuid stable across saves;
  tell the user the uuid to send Bhumika.

### 2. Tables: admin editor + outbox hooks (1–1.5 h)
- `includes/restaurant-tables.php` create/update/delete → wrap in a tx, emit
  `restaurant_table` (generalise `menu_sync_emit` or add a sibling; don't copy
  the loop-guard/version logic twice). Delete = soft (`is_deleted`).
- Admin page for tables (manager-scoped by `admin_venue_ids()`), validate
  `label` ≤10 chars (contract `number`), seats 1–255.
- **Accept:** DB test (rolled back) mirrors `tests/menu_sync_logic.php`; only
  Zuri-venue tables emit.

### 3. Schedule the dispatcher (30 min)
- `docker/scheduler.sh`: run `php bin/sync-dispatch.php` every ~10 s (or start
  `--loop` as its own background job from `docker/entrypoint.sh`). It self-gates on
  `SYNC_ENABLED` / `SYNC_TS_TO_ZURI` and supports `SYNC_SHADOW=true`.
- ⚠️ Shadow mode currently marks rows **sent** after logging — fine for Stage 1,
  but then the full dataset must be re-queued for go-live: add
  `bin/sync-requeue.php` (idempotent, queues a `create` for every live synced row
  of the Zuri venue, categories before items) for S§7 step 6.
- **Accept:** script lints; runbook documents the env flags per stage.

### 4. Inbound: sold-out + applier (2–3 h) — the big one
- Migration `add_menu_item_sold_out.sql`: `menu_items.is_sold_out BOOLEAN NOT NULL DEFAULT FALSE`
  (+ `sold_out_at`). Public `menu.php` shows a "Sold out" state; admin shows it read-only.
- `bin/sync-apply.php`: drain `sync_inbox` (`status='pending'`, oldest first)
  under `pg_advisory_lock`, inside `SyncContext::applying()`. For each event:
  resolve via `sync_id_map` / `sync_uuid`, run `sync_resolve()` (S§6 order:
  unknown+create → insert + id_map; unknown+update → pull
  `GET /changes?entity=&sync_uuid=` from Zuri then insert; higher version →
  apply; lower → mark `rejected stale_version`; equal/different → conflict row).
  Entities: `item_availability` → `menu_items.is_sold_out`; `customer` →
  `customers` (helpers exist in `includes/customers.php`); `reservation` →
  `reservations` (+ `sync_*`, `table_id`/`customer_id` via uuid map; status via
  the state machine — terminal never revives; write a `sync_conflicts` row with
  resolution `terminal_state`).
- **Ordering race (H§8):** if a referenced uuid (customer/table) hasn't arrived,
  leave the event pending with an attempt counter; fail after ~5 tries.
- Run it from the scheduler next to the dispatcher (gated on `SYNC_ZURI_TO_TS`).
- **Accept:** pure tests for the per-entity mappers + resolver paths; DB test in
  a rolled-back tx; loop guard proven (applying writes no outbox rows).

### 5. Reservations outbound + `/reserve` (1.5–2 h)
- Status changes staff make on Tribalsand (confirm/seat/complete/no-show/cancel
  in `admin/reservations.php` via `set_reservation_status()`) → emit a
  `reservation` update. For a Zuri-created booking send **status (+
  cancellation_reason) only** — anything else is `not_owner`.
- Add Seat / Complete / No-show buttons (state machine already guards them).
- Staff-created booking → `POST https://zuriwatamu.com/sync/v1/reserve` (signed,
  `Idempotency-Key`, mint `sync_uuid` first, send `external_id`); 201 → store
  locally; 409 → show `alternatives` in the UI. Find where staff create
  reservations today (only the public `reserve.php` form may exist — check
  before building a form).
- **Accept:** client function unit-tested with a stubbed HTTP call
  (`function_exists`-guarded, like `ai_claude_request()`).

### 6. Monitoring (1–1.5 h)
- Owner-only `admin/sync.php`: our `sync_health()`, Zuri's `/health` (signed GET
  — show its `alerts[]` directly), failed outbox rows with a Retry button (reset
  to `pending`, `next_retry_at=now()`), open `sync_conflicts` side-by-side, the
  switch states. House UI only.
- `bin/reconcile.php` nightly (report-only, S§9): per-entity checksum
  `md5(string_agg(sync_uuid||'|'||sync_version ORDER BY sync_uuid))` for our
  owned entities; log mismatches. (Zuri has no checksum endpoint yet — compare
  against a `/changes` pull or agree an endpoint with Bhumika.)

### 7. Small hardening (15 min)
- Dockerfile/Apache: `ServerSignature Off`, `ServerTokens Prod` (the 403 page
  currently leaks the internal ECS hostname + Apache version).

## Joint checks with Bhumika (H§10 — after tasks 0–3)
1. `GET /health` both ways with the real key.
2. One category + item create → visible on Zuri ≤10 s; nothing comes back.
3. Price change → applied; resend same event → `duplicates`.
4. Zuri sold-out toggle → arrives as `item_availability` (needs task 4).
5. `/reserve` → 201; the reservation event comes back with our uuid (task 5).
6. Cancel on Zuri, send stale `confirmed` → stays cancelled.
7. Stop our endpoint 10 min → backlog drains in order.

## Rollout (S§10) — not today
shadow → menu one-way → availability reverse → reservations one-way →
reservations two-way → full. Each stage runs until boring; flip one direction at
a time (`SYNC_TS_TO_ZURI`, `SYNC_ZURI_TO_TS`).

## Needs the user / others
- Set the ECS env vars (task 0) and delete the key from the Teams chat.
- Lunch/dinner wording + slot/duration values for task 1.
- Owner: fixed outbound IP (NAT + EIP, ~$32/mo) or HMAC only — HMAC only for now.
- Push to master (each deploy).
