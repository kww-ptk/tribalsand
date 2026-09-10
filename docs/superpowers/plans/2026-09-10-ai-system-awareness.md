# AI system-awareness — make the assistant aware of every property & room

> **Reconstructed 2026-09-10.** The original of this plan (authored on branch
> `claude/sync-remote-repo-b6e978`) was never committed and was lost with that
> session's working tree. This version is rebuilt from the project memory note
> `ai-system-awareness-plan` + the live capacity code. **Phases A–I are
> implemented on this branch** (E was the original final phase; F–I add more of
> the AI's senses — activities, menu items, sustainability, currency, services,
> next-free-date search, and staff-only occupancy/ops). The only step that cannot be done from code
> is *running* the Phase A audit/backfill against production (that needs the AWS
> console) and the three owner data decisions it surfaces.

## Problem

The Tribal Sand AI assistant (staff: `admin/assistant.php`) and concierge (guest:
`concierge.php`) answer availability/price by **calling the app's own live
resolvers** — `ts_property_configurations()` / `ts_rank_combos()` /
`room_stay_quote()` in `includes/db.php` — never a snapshot. That design is
correct and stays.

The reported symptom — asking for a 6-guest stay at **Zuri** and being offered
**"2× a one-room suite"** — is **not** an algorithm or prompt bug:

- Combos already cap units at the number actually **free** and rank by fewest
  units → least waste → cheapest (`ts_rank_combos`).
- The system prompt already forbids inventing a room, price, or combination.

It is a **production DATA defect**. Search does `free_units × capacity` per room
type, where:

- `rooms.capacity` = max occupancy of **one unit** of that room type
  (NULL/0 = "unknown" → the room is skipped by the combo search), and
- active `units` = how many bookable units that room type has.

If a one-room suite carries **2 active units** on prod (or a capacity is wrong),
the math legitimately produces "2× that suite". The dev DB (`.env` / Neon) is
**not** production, so this was invisible in local testing. The two seeds even
disagree: `db/seed_rooms_2026.sql` is the source of truth; `db/seed_rooms_model.sql`
is stale — **do not run it as-is**, and never run the rebuild seeds against prod
(they `DELETE … CASCADE` real holds/blocks).

**This affects every property, not just Zuri.** Any published room with a wrong
capacity or a wrong active-unit count corrupts search and the AI equally.

## Guardrails (unchanged, all phases)

- **ONE pricing path** — always `room_stay_quote()` / `ts_search_availability()`.
  Never a second nightly loop.
- **Read-only** — the assistant quotes and describes; it never books/holds.
- **Facts via tools, prose via RAG** — availability and price are always tool
  calls; only descriptions come from the embeddings layer. The two never cross.
- **Nairobi-local dates**, never invent a price/availability/booking.

---

## Phase A — THE fix: reconcile capacity & units on prod  *(this branch)*

Make the data true so the already-correct resolvers produce correct suggestions.
Delivered as an **audit-first** workflow because prod RDS is private (VPC-only)
and the dev DB is not prod — we look before we change.

**Deliverables (in this branch):**

1. **`bin/audit-capacity-units.php`** — read-only. Prints every room's
   `capacity`, `is_entire_place`, active/total unit counts, per-venue theoretical
   max party, and seven anomaly buckets (A–G): single rooms with no capacity;
   single-room types with >1 active unit (the defect); published rooms with 0
   active units; whole-property rooms with no capacity; orphaned rooms;
   capacity/unit counts that differ from the source-of-truth. Changes nothing.
2. **`db/backfill_room_capacity.sql`** — idempotent, NULL/0-guarded capacity
   backfill for every known published room (Zuri/Maya Kobe/Maya Ilai suites +
   buyouts, Sandbox, My Amani provisional). Never overwrites a deliberately-set
   value; never touches units. Wrong-but-non-null fixes and unit normalisation
   are documented as **reviewed, surgical** steps (deactivate a surplus unit,
   don't delete — units carry live bookings).

3. **`bin/reconcile-capacity-units.php`** — the booking-aware fix. Dry-run by
   default (`--apply` to write). Sets the two NULL buyout capacities and reduces
   each room to its target active-unit count by **deactivating only the empty
   surplus unit** (keeps any unit with a future booking; reports any room it can't
   safely reduce). Idempotent.

**Ran on prod 2026-09-10 — root cause confirmed.** The audit showed **every room
carries exactly one extra active unit** (suites 2 not 1; Maya Ilai villa/studio 9
not 8) — the `add_availability` default-unit seed ran on top of the by-room seed.
That stray unit is the entire "2×" bug. Also: `maya-kobe-buyout` and `sandbox`
had NULL capacity. **The three "open decisions" resolved themselves:** Enkare = 10
and My Amani = 10 are already set on prod, and `superior-suite` is a real
published room (keep it; just drop its extra unit) — the "0 units/unpublish" note
was from a stale comment and was wrong.

**How to run on prod** (region `eu-west-1`, cluster `default`, service
`tribalsand`, container `Main`): one-off ECS run-task with command override
`["php","bin/reconcile-capacity-units.php"]` to preview, then
`[...,"--apply"]` to write; read output from CloudWatch
(`/aws/ecs/default/tribalsand-3abb`). Re-run `bin/audit-capacity-units.php` until
sections A–G are clean. (`db/backfill_room_capacity.sql` remains a capacity-only
manual alternative; the reconciler is the authoritative fix for units + the two
capacities.)

**Acceptance:** the audit reports zero anomalies; a 6-guest Zuri query returns a
sensible combination (distinct suites, never 2× the same one-unit suite) and the
AI's quote equals the booking widget's for identical dates.

---

## Phase B — a `property_facts` read-only tool  *(built)*

New tool `property_facts` in `includes/assistant-tools.php`
(`assistant_tool_property_facts()`), enabled via the new `$withFacts` flag on
`assistant_tool_definitions()` / `assistant_system_prompt()`. It answers the
structured questions the factual tools couldn't: per-property room count, how
many rooms are individually bookable, **max occupancy** (via
`assistant_static_max_capacity()`, the date-independent sibling of
`ts_property_configurations()`'s `max_capacity`), the security **deposit**
(amount + currency, always framed "collected at the property, never online"),
checkout time and location. Read-only, `admin_venue_ids()`-scoped,
pre-migration-safe (`SELECT *` + defensive reads so a DB without the stay/deposit
columns still returns the core facts). Carries **no nightly price** — those stay
in `quote_stay`. Wi-Fi is reported as a boolean (`wifi_available`), never the raw
value, so a public concierge can't leak a network password.

## Phase C — graceful max-occupancy answers  *(built)*

`check_availability` already returned each property's `max_capacity`; Phase C adds
the always-on prompt rule: when a party is too large for anywhere, don't stop at
"no availability" — tell the guest the largest group each property can host (from
the tool's `max_capacity`) and suggest a buyout / split / different dates, citing
only the figures the tool returns. Prompt-only; no new nightly loop.

## Phase D — a "what's new / what's on" tool  *(built)*

New tool `whats_on` (`assistant_tool_whats_on()`), also behind `$withFacts`.
Reuses the existing helpers — `fetch_published_offers()` (site-wide),
published `menus` (scope- and property-filtered), and `fetch_reservable_venues()`
— to answer "any deals?", "is there a menu?", "can I book a table?". Read-only,
scoped, pre-migration-safe (a subsystem whose table is absent contributes
nothing). No nightly prices.

**Wiring:** all three endpoints (`api/assistant.php`, `api/concierge.php`,
`api/assistant-draft.php`) pass `$withFacts = true`, so staff, guest and the
enquiry drafter all get the two new tools. The Phase-1 three-tool shape is
preserved for any caller that passes no flags.

## Phase E — golden tests, RAG↔DB cross-check, observability  *(built)*

Extended `tests/assistant_tools.php`: the **golden invariant** now asserts every
room total from `check_availability` equals `quote_stay` across **all**
properties (the acceptance bar, not just one room); a **cross-surface** check
asserts `property_facts.max_occupancy` equals `check_availability.max_capacity`
for an all-free window (the two AI surfaces must agree — the RAG↔DB-consistency
spirit applied to the structured facts); plus wrapper + scope + no-price tests
for `property_facts` and `whats_on`, and the updated tool-count/prompt
assertions. Observability is already covered by the returned `tool_calls` trail
(both endpoints) and `concierge_log` (guest turns). All pure-logic tests pass;
DB-backed and model calls follow the existing SKIP-without-DB / mocked pattern.

## Phase F — activities, menu items  *(built)*

Two more read-only tools behind `$withFacts`, closing the most-asked guest gaps:

- **`list_activities`** (`assistant_tool_list_activities()`) — the published
  `tours` catalogue with its published price string, category, area
  (watamu/kilifi/vipingo), duration and summary; optional area/category filter.
  Site-wide (tours aren't venue-scoped), pre-migration-safe.
- **`menu_details`** (`assistant_tool_menu_details()`) — the actual dishes on a
  menu (by menu slug or a property's first published menu): sections → items with
  KES price, dietary/other badges (`menu_badge_defs()`) and description.
  Scope-checked (a venue-linked menu must be in scope; a NULL-venue menu is
  public). Complements `whats_on`, which only says a menu *exists*.

## Phase G — live sustainability figures  *(built)*

**`sustainability_facts`** (`assistant_tool_sustainability_facts()`) — the live,
accrued environmental numbers (`sus_metrics()`): solar MWh, CO₂ avoided, beach
waste, desalinated water, each with value/unit/note. Numbers via this tool;
initiative *prose* stays with `search_property_info`. Pre-migration-safe (returns
the built-in fallback figures, never zeros).

All Phase F/G tools are wired through the same `$withFacts = true` on the three
endpoints and covered by `tests/assistant_tools.php` (wrapper + filter + no-price
+ shape assertions). Tool counts: no-arg 3, `(true)` 4, `(false,true)` 8,
`(true,true)` 9.

## Phase H — currency, services, next-free-date  *(built)*

Three more guest-safe tools behind `$withFacts`:

- **`convert_currency`** — display-only FX on a figure the model already has, via
  the canonical `convert_price()` / `fx_rates()` (the site's own switcher rates).
  Never a second pricing path: the booking stays the room's currency.
- **`list_services`** — the `service_options` catalogue (transfer, laundry) with
  configured amounts. No currency column, so amounts are returned bare with a
  "confirm currency with the property" note — never assumed.
- **`find_next_availability`** — scans forward (≤120 days, early-exit) for the
  soonest window with availability, using the single `ts_search_availability()`
  path. Scope-filtered, optional single property.

*Upsells* needed no new tool — they are just tours (`upsell_placement`), already
covered by `list_activities`. *Transfers* as a standing catalogue = `service_options`
(the per-booking `departure_transfer` is check-in data, not a catalogue).

## Phase I — staff-only occupancy & operations  *(built)*

Behind a **separate** `$withStaffOps` flag that **only `api/assistant.php`**
turns on — never the guest concierge, never the enquiry drafter:

- **`occupancy_report`** — per-currency revenue / ADR / RevPAR / room-nights +
  occupancy % from the bookings ledger for a range (mirrors `admin/reports.php`:
  `bookings_in_window` → `summarize`/`occupancy`, `to` inclusive). Scope-filtered.
- **`daily_operations`** — the day's confirmed arrivals & departures + table-
  reservation counts. Scope-filtered.

The system prompt gains a staff-ops line only when `$withStaffOps`; the guest
prompt can never contain it (regression-tested). Tool counts:
`(false,true,true)` = 13, `(true,true,true)` = 14.

## Still deferred (not built — needs a product decision)

- **Events** — there is no `events` table; "events" are `guest_board_posts`
  (category `event`, with `event_date`/`price_amount`), part of the guest
  portal. Surfacing them publicly needs a clear rule on which posts are public.

## Related

- Memory: `ai-system-awareness-plan`, `capacity-search-ai-plan`,
  `ai-rag-assistant-plan`, `ical-sync-and-prod-rds-access`, `booking-model-state`.
- Code: `includes/db.php` (`ts_property_configurations`, `ts_rank_combos`,
  `count_available_units`), `includes/assistant-tools.php`, `includes/assistant-rag.php`.
- `docs/ai/tribalsand-knowledge-base.md` — **rebuilt** (room/capacity/units truth
  table + per-property fact sheet + tool map). Brand-narrative section is a TODO
  needing the owner's March 2026 PDF. `docs/ai/ai-intelligence-strategy.md` was
  also lost and is not rebuilt (it was a review/diagnosis doc, superseded by this
  plan's Problem section).
