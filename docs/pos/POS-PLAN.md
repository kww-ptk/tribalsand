# Tribal Sand POS — Implementation Plan

> Self-contained spec for a fresh session. Read this file top to bottom before writing code.
> Drafted 2026-09-26 from a codebase analysis + a clickable prototype
> (`docs/pos/pos-prototype.html` — open it in a browser; PINs are on the lock screen).
> Stack rules from `CLAUDE.md` apply: vanilla PHP 8.2, PDO prepared statements via `db_query()`,
> vanilla JS/CSS, no build step, **no native UI chrome** (styled selects/datepickers/Lucide icons only),
> `client_ip()` never `REMOTE_ADDR`, Africa/Nairobi time, pre-migration-safe reads.


> **Status 2026-09-26: P1–P6 BUILT** (branch `claude/pos-implementation-steps-1-2-addf47`, not yet merged).
> `tests/pos_logic.php` 156/156 against a local Postgres 16 with every migration applied; `bill_logic`,
> `frontdesk_logic`, `reception_role`, `services_logic`, `agent_portal_logic` green (`team_logic` has one
> pre-existing stale assertion about the owner's home page — unrelated). Browser-verified: admin-mode till
> sale with room charge → Bill tab (POS badge, forged delete refused); terminal registration → PIN unlock →
> walk-in cash sale → lock → lockout; revoke; sales list, sale detail, Z-report, consignment statement; phone layout.
> Deviations from the text below: permission helpers live in `includes/pos.php`; the light guards in
> `includes/pos-support.php`; `pos_outlets.next_ref` is the receipt sequence; `pos_consignor_payouts.currency`
> added; PIN management is its own page (`admin/pos-pins.php`) rather than inside `admin/staff.php`;
> "lock after each sale" per terminal is not built (idle lock + Lock button only); spa seed items are
> inactive + unpriced. Found + fixed during the build: PIN failures were locking ADMIN logins from the
> same IP (`is_rate_limited()` now ignores `pos:%` rows). P7 items remain open.

---

## 0. What we are building (one paragraph)

One POS app, many **outlets** (Experiences desk, Shop, Salon & Spa, Kite School — more later).
Staff open it on a tablet, tap their name + a 4–6 digit PIN, and see only the outlets they're
allowed to sell in. They pick a customer (**in-house guest from a live booking** or a **walk-in**),
tap items into a cart, review, choose payment (**Card / Cash / Room charge / M-Pesa / Other**),
and complete. Completing a sale writes one immutable sale record, decrements stock for physical
products, records consignment ownership, and — for **Room charge** — posts the charge onto the
guest's existing bill (`bill_items`), so it appears on the admin Bill tab, the printed bill and the
guest's own portal bill with **zero new display code**. Every outlet has a Sales History; admins get
catalogue/stock/consignment management and reports inside the existing admin.

---

## 1. Findings from the codebase (why the design looks like this)

| Area | What exists today | Consequence for the POS |
|---|---|---|
| **Accounts** | `admin_users` with `role` owner/manager/reception/staff, `job_type` for staff, `admin_user_venues` scoping, `admin_venue_ids()`, `venue_scope_sql()` (`includes/auth.php`). | POS reuses the same accounts. **No second user table.** Outlet access is a new join table, venue scoping reuses `admin_venue_ids()`. |
| **Staff login** | `login_staff()` = 12-char hex access code, rate-limited via `is_rate_limited()` + `login_attempts`. | Too slow for a till. Add a **short PIN, only valid on a registered POS terminal** (see §3). |
| **Kiosk devices** | Clock kiosk: `attendance_devices` (token **hash** stored, per venue, revocable, `last_seen_at`) + `clock_device_by_token()` in `includes/attendance-clock.php`. | Copy this exact pattern for `pos_terminals`. A PIN alone is never enough — PIN + registered device. |
| **Isolated sessions** | Travel-agent portal uses its own session key `agent_id`, never `admin_id` (`includes/agent.php`, `/agent/`). | A PIN login sets **`pos_user_id`**, never `admin_id` — a POS PIN session can't open admin pages. |
| **Guest bill** | `bill_items (hold_id, label, amount, guest_id)` + `fetch_bill_items()`/`bill_total()` in `includes/booking.php`; shown in `admin/_ws_bill.php`, `admin/bill-print.php`, **guest portal `includes/app/bill.php`**. Currency = `setting('site_currency','USD')` (one currency per bill). | **Room charge = insert into `bill_items`** with a link back to the sale. Guest sees it itemized under their name automatically. |
| **Bill deletion** | `admin/booking.php` `bill_del` hard-DELETEs any `bill_items` row. | Must be blocked for POS-sourced rows (else sale and bill diverge). Corrections go through **POS void**. |
| **In-house guests** | `frontdesk_rows($venueIds, $datePredicate, $params)` in `includes/frontdesk.php` — confirmed + staff-typed-pending holds, room/unit/venue names, venue-scoped. `checkin_guests` holds each person on the booking. | In-house search reuses `frontdesk_rows()` with `check_in <= :d AND check_out >= :d`. Charge can be attributed to one person (`guest_id`), same as the Bill tab. |
| **Experiences catalogue** | `tours` (published, `price_amount` NUMERIC USD, `price_per_person`, `category`, `tour_venues`, images in `tour_images`). Kite lessons + wellness already exist as tours ("2hr Kite Taster Course", "Private Yoga Session", "In-House Wellness Treatments"). Some are **"On request"** (no `price_amount`). | Experiences/Kite outlets **link to tours** (no copy of price). Unpriced tours need an **open price** entered at sale. |
| **Service catalogue** | `service_options` (laundry/transfer only, owner-edited in `admin/services.php`). | Salon/Spa/Barber/Nails/Massage are **new POS-owned service items** (not shoe-horned into `service_options`, whose CHECK is laundry/transfer). |
| **Money rules** | Rooms price in USD or KES; "money is never summed across currencies" (bookings ledger rule). Bills are single-currency. | Each **outlet has one currency**. A sale is single-currency. Room charge only when outlet currency == bill currency (see D3). |
| **Reports** | `admin/reports.php` + `bookings` ledger are room revenue only. | POS gets its own sales tables + reports. Do **not** write POS sales into the `bookings` ledger. |
| **Admin UI kit** | `admin/_layout.php` sidebar groups, `dt_*` data-table toolkit, `.eselect`, `.inp`, `.optchip`, `.filefield`, `.btn-icon`, `admin_icon()`, drag-reorder pattern (`admin/services.php`). | Admin management pages use the kit. The **till itself is a standalone full-screen page** (like `clock.php`), not inside the admin shell. |
| **Tests** | `tests/*.php` — pure logic always, DB assertions in a rolled-back transaction. | Same for POS: `tests/pos_logic.php`. |
| **⚠ Local DB** | This checkout has **no working DB**: the worktree has no `.env`, and `D:\TribalIsland\.env` points at the **retired Neon DB** (never use it). DB-backed suites (`bill_logic`, `team_logic`, `frontdesk_logic`, `services_logic`) crash here. | **Prerequisite P0** below: get a local Postgres (Postgres.app/Docker) seeded from schema + migrations before Phase 1. Pure tests run anywhere with `DATABASE_URL=postgres://x:y@127.0.0.1:70000/none` (avoids the localhost hang). |

---

## 2. Decisions

**Locked (recommended — change only with a reason):**

- **D1 — Where it lives: a path, not a subdomain (for now).** Till at **`/pos/`** (own layout, PWA
  manifest, full-screen), management under **Admin → Point of Sale**. A subdomain
  (`pos.tribalsand.com`) needs a Route 53 record, an ACM cert, a CloudFront/ALB host rule and
  cookie-domain decisions, and buys nothing for v1. Build `/pos/` with **root-relative URLs only**
  so a subdomain later is just a host alias pointing at the same container (Phase 7).
- **D2 — One account system.** POS users are `admin_users`. PIN is an extra credential on that row.
- **D3 — One currency per outlet; room charge requires same currency as the bill.** Outlet currency
  defaults to `site_currency`. If an outlet's currency differs from the bill currency, the Room
  charge button is disabled with a reason (never convert silently). Revisit if the owner wants a
  KES shop charging to a USD bill (would need a stored FX rate per sale — out of scope v1).
- **D4 — Sales are immutable.** No editing a completed sale. Corrections = **Void** (manager+,
  reason required) which restores stock, removes the linked bill line, and is audit-logged;
  then ring a new sale.
- **D5 — No payment integration in v1.** Card = external card machine, M-Pesa = paid to till
  number; we record method + optional reference (last 4 / M-Pesa code). Nothing is charged online
  (consistent with the deposit rule "charged at the property, never online").
- **D6 — Stock is a ledger.** `pos_stock_moves` is the source of truth; `pos_items.stock_qty` is a
  cached running total updated in the same transaction (`SELECT … FOR UPDATE`). **Overselling is
  blocked** by default (per-item "allow negative stock" flag off).
- **D7 — Experiences link to `tours`, never copy them.** Price read from `tours.price_amount` at
  sale time and **snapshotted onto the sale line**. Per-person tours: quantity = pax.

**Open — ask the owner (Patrik) before/while building; defaults in brackets:**

- **Q1** Service charge % per outlet? (image shows 10%) [configurable per outlet, default 0].
- **Q2** VAT/tax lines on receipts? [none in v1; prices tax-inclusive].
- **Q3** Which properties can a shared outlet (Kite School, Shop) room-charge? [outlet with a
  `venue_id` → that venue's guests only; outlet with `venue_id NULL` → guests of all venues].
- **Q4** Consignment terms: commission % or fixed "cost to us" per item? [support both:
  `commission_pct` OR `consignor_cost` per item; report shows what we owe the supplier].
- **Q5** Outlet currencies — is the shop priced in KES? [every outlet defaults to `site_currency`].
- **Q6** Tipping? [no in v1].
- **Q7** Does a room charge need the guest's signature on the tablet? [no in v1; guest sees it live on their portal bill].

---

## 3. Access model

```
Owner ─────────────► every outlet, every venue, all admin POS pages
Manager ───────────► outlets whose venue ∈ admin_venue_ids()  (+ venue-less shared outlets if assigned)
Reception/Staff ───► ONLY outlets explicitly assigned in pos_outlet_staff
Cross-sell ────────► pos_outlet_links: outlet A may also sell outlet B's items
                     (e.g. Experiences desk sells Kite lessons; Spa sells Shop products).
                     The sale line records the OWNING outlet → revenue + stock attribute correctly.
```

- **Two ways into `/pos/`:**
  1. **Signed-in admin session** (owner/manager/reception already logged into admin) → straight in,
     sees their permitted outlets. Works on any device. Build this FIRST (Phase 2).
  2. **Terminal + PIN** (Phase 4): a tablet registered by a manager (`pos_terminals`, token in a
     long-lived httpOnly cookie, hash in DB — clone `attendance_devices`). The lock screen shows the
     staff allowed on that terminal's outlets; tap name → PIN keypad. Sets session keys
     `pos_user_id` + `pos_terminal_id` (**never `admin_id`**).
- **PIN rules:** 4–6 digits, stored with `password_hash()`; must be set by the person or a manager;
  refuse trivial PINs (`0000`, `1234`, repeated digits); **per-user lockout** after 5 wrong tries
  (reuse `login_attempts` keyed `pos:<user_id>`) + per-terminal IP rate limit. PIN is useless
  without a registered terminal cookie.
- **Auto-lock:** idle 2 min (configurable) → lock screen; also "Lock" button; also lock after each
  sale (per-terminal setting). Session hard cap 12 h.
- **New job types** (extend the CHECK like `add_laundry_job.sql`): `shop`, `spa`, `kite`. Their
  `admin_home_url()` → `/pos/`. House managers are `role=manager` (no job type needed).
- **Helpers** in `includes/pos-auth.php`: `pos_current_user()`, `require_pos()`,
  `pos_user_outlet_ids(int $userId): array` (owner → all active; manager → venue-scoped +
  assigned; others → assigned), `pos_can_sell_item(userId, outletId, itemId)` (own outlet or linked).
  **Every write re-checks** outlet + item permission server-side; the client's outlet id is a request, not a fact.

---

## 4. Data model (migration `db/migrations/add_pos.sql`, idempotent)

```sql
pos_outlets        id, name, slug UNIQUE, kind ('experiences'|'shop'|'salon_spa'|'kite'|'other'),
                   venue_id NULL→venues, currency CHAR(3), service_charge_pct NUMERIC(5,2) DEFAULT 0,
                   allow_room_charge BOOL DEFAULT TRUE, is_active, sort_order, created_at
pos_outlet_staff   (outlet_id, admin_user_id) PK
pos_outlet_links   (outlet_id, source_outlet_id) PK   -- "outlet_id may sell source_outlet_id's items"
pos_categories     id, outlet_id, name, sort_order              -- chips on the till
pos_consignors     id, name, phone, email, commission_pct NUMERIC(5,2), notes, is_active
pos_items          id, outlet_id, category_id NULL, kind ('product'|'service'),
                   tour_id NULL→tours            -- linked experience (price comes from tours)
                   name, sku NULL, price NUMERIC(10,2) NULL   -- NULL + tour_id → use tour price;
                                                              -- NULL + no tour → open price at sale
                   per_person BOOL, image_key NULL, track_stock BOOL, stock_qty INT DEFAULT 0,
                   low_stock_at INT NULL, allow_negative BOOL DEFAULT FALSE,
                   consignor_id NULL→pos_consignors, consignor_cost NUMERIC(10,2) NULL,
                   is_active, sort_order, created_at, updated_at
pos_stock_moves    id, item_id, qty_delta INT, reason ('receive'|'sale'|'void'|'adjust'|'return'),
                   sale_id NULL, unit_cost NULL, note, admin_user_id, created_at
pos_customers      id, name, phone, email, created_at      -- walk-ins (searchable next time)
pos_terminals      id, name, venue_id NULL, token_hash, is_active, last_seen_at, created_by, created_at
pos_terminal_outlets (terminal_id, outlet_id) PK
pos_sales          id, reference UNIQUE ('POS-<OUTLET>-<n>'), outlet_id, terminal_id NULL,
                   admin_user_id,                     -- who completed it
                   customer_type ('inhouse'|'walkin'), hold_id NULL, guest_id NULL→checkin_guests,
                   pos_customer_id NULL, customer_name (snapshot),
                   currency, subtotal, service_charge, total,
                   payment_method ('cash'|'card'|'room_charge'|'mobile_money'|'other'),
                   payment_ref NULL, cash_tendered NULL,
                   status ('completed'|'voided'), void_reason, voided_by, voided_at,
                   client_uuid UNIQUE,                -- idempotency: a double-tap can't make 2 sales
                   created_at TIMESTAMPTZ DEFAULT now()
pos_sale_lines     id, sale_id, item_id NULL, owning_outlet_id, tour_id NULL, name (snapshot),
                   kind, qty, unit_price, line_total, consignor_id NULL,
                   consignor_commission_pct NULL, consignor_cost NULL
pos_consignor_payouts id, consignor_id, period_from, period_to, amount, paid_at, note, admin_user_id
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS pos_pin_hash TEXT, pos_pin_set_at TIMESTAMPTZ;
ALTER TABLE bill_items  ADD COLUMN IF NOT EXISTS pos_sale_id INT REFERENCES pos_sales(id) ON DELETE SET NULL;
-- job_type CHECK += 'shop','spa','kite' (separate small migration add_pos_job_types.sql)
```

Indexes: `pos_sales (outlet_id, created_at DESC)`, `pos_sales (hold_id)`, `pos_sale_lines (sale_id)`,
`pos_stock_moves (item_id, created_at)`, `pos_items (outlet_id, is_active, sort_order)`.
All reads pre-migration-safe via `pos_supported()` (`to_regclass('public.pos_sales')`, catalog lookup —
never a failing SELECT inside a transaction).

**Seed** (`db/seeds/seed_pos.php`, idempotent): four outlets (Experiences, Shop, Salon & Spa, Kite
School) with categories; link published priced tours into Experiences; kite tours into Kite School;
a starter spa menu with prices left 0 for the owner to fill.

---

## 5. Core logic (`includes/pos.php`) — pure where possible, unit-tested

- `pos_cart_totals(array $lines, float $servicePct): array` — **pure**; integer-cents math, returns
  `subtotal/service_charge/total`. Rounding: service charge rounded half-up to 2 dp.
- `pos_resolve_line(array $item, ?array $tour, int $qty, ?float $openPrice): array|string` — **pure**;
  price precedence (item price → tour price → open price), error string for "no price", qty ≥ 1,
  per-person handling, consignment snapshot.
- `pos_room_charge_eligible(array $hold, string $todayYmd, array $outlet, string $billCurrency): ?string`
  — **pure**; returns `null` when OK or the reason: not confirmed, not in house today
  (`check_in <= today <= check_out`), venue outside outlet scope (Q3), currency mismatch (D3),
  outlet has room charge off.
- `pos_inhouse_search(?array $venueIds, string $q, string $today): array` — wraps `frontdesk_rows()`;
  matches guest name, room/unit name, booking access code; returns booking + `checkin_guests` adults.
- `pos_complete_sale(array $req, int $userId, ?int $terminalId): array` — **the one write path**:
  1. `db()->beginTransaction()` (only if not already in one — tests wrap in a rolled-back txn).
  2. Idempotency: `client_uuid` already used → return the existing sale (no second sale).
  3. Re-check permission for outlet + every item (own or linked outlet).
  4. `SELECT … FOR UPDATE` every stock-tracked item; refuse if stock would go negative (D6).
  5. Re-price every line server-side (**never trust client prices**); recompute totals.
  6. Room charge → re-check `pos_room_charge_eligible()` against the live hold; `staff_can_hold()`-style scope.
  7. Insert `pos_sales` + `pos_sale_lines`; insert `pos_stock_moves` (reason `sale`), update `stock_qty`.
  8. Room charge → insert **one `bill_items` row per sale** (label `"<Outlet>: <item summary> (POS-…)"`,
     amount = total, `guest_id`, `pos_sale_id`).
  9. Commit; `audit_log('pos.sale', 'pos_sale', id, ref)`.
- `pos_void_sale(int $saleId, string $reason, int $userId)` — manager+ only; restock (`void` moves),
  delete the linked `bill_items` row (by `pos_sale_id`), mark voided, audit. Refuse voiding a room
  charge once the hold is checked out **and** the bill is printed/settled? → v1: allow, but warn.
- `pos_next_reference(outletSlug)` — per-outlet sequence (`POS-SHOP-1042`).

---

## 6. Screens

### Till — `/pos/` (standalone, full-screen, tablet-landscape first) — see prototype
- `pos/index.php` — lock screen (terminal mode) or outlet workspace (session mode).
- Layout = the reference image: left outlet rail · centre category chips + search + item tiles
  (image, name, price, stock badge, "Consignment" tag, "from <outlet>" tag for cross-sold items)
  · right panel: **Customer** (In-house guest | Walk-in), **Order** lines (qty −/+, remove, clear),
  subtotal / service charge / **Total**, **payment tiles (Card · Cash · Room charge · M-Pesa)**, big
  **Review sale** button.
- **Room charge tile** is disabled until an in-house guest is selected, with the reason shown
  underneath (validated in prototype). Picking a guest shows booking card (property/room, dates,
  booking ref) + chips for each adult on the booking to attribute the charge.
- Review modal → Confirm. Cash shows quick-tender buttons + change due.
- Receipt modal → Print (80 mm `pos/receipt.php?sale=`), New sale. Email receipt = later.
- Sales history drawer (this outlet, today by default) → tap a row → receipt.
- Portrait tablet / phone: order panel becomes a bottom sheet (prototype shows portrait is cramped
  with a fixed side panel — **design for 1024×768 landscape, degrade to sheet below 900px**).
- JS: `js/pos.js` (vanilla). Catalogue loaded once per outlet (`api/pos/catalog.php`), cart lives
  client-side, **complete posts the cart + `client_uuid`**; server re-prices. Poll nothing.
- Endpoints (`api/pos/*.php`, JSON, CSRF token in body like `api/assistant.php`, `require_pos()`):
  `catalog`, `inhouse` (search), `customers` (walk-in search/create), `sale` (complete),
  `sale-void`, `sales` (history), `receipt`.

### Admin — sidebar group **Point of Sale** (`admin/_layout.php`, `$__navPos`)
- `admin/pos-sales.php` — Sales history (`dt_*` toolkit): date range, outlet, staff, payment
  method, customer search; row → sale detail with **Void** (manager+). CSV export.
  Per-currency totals (never summed across currencies).
- `admin/pos-outlets.php` — outlets CRUD (owner): name, kind, venue, currency, service %, room
  charge on/off, staff assignment (`.optchip` list), "also sells from" links, categories (drag reorder).
- `admin/pos-items.php` — catalogue per outlet: add product/service, **"Add from Activities"**
  picker for tours, price, per-person, image (`storage_put()` like venue galleries), stock tracking,
  consignor, active toggle, drag reorder.
- `admin/pos-stock.php` — **Receive stock** (qty + unit cost + note), **Adjust** (count correction
  with reason), movement ledger per item, low-stock list.
- `admin/pos-consignors.php` — suppliers + report per period: qty sold, gross, commission, **owed to
  supplier**, "Mark paid" (`pos_consignor_payouts`).
- `admin/pos-terminals.php` — register tablet (shows one-time setup link/QR like the clock kiosk),
  assign outlets, revoke.
- Staff PIN: on `admin/staff.php` add "Set POS PIN" (manager+) and on the user's own profile.
- **Booking workspace Bill tab** (`admin/_ws_bill.php`): POS lines show a `POS-…` badge linking to the
  sale; **no trash icon** for them; `bill_del` in `admin/booking.php` must refuse rows with
  `pos_sale_id IS NOT NULL` (server-side, not just hidden).

---

## 7. Phased delivery (strategic order — each phase ships something usable)

| # | Phase | Delivers | Why this order |
|---|---|---|---|
| **P0** | **Prereqs** | Local Postgres + `.env` for the worktree; run all migrations; confirm `tests/bill_logic.php`, `frontdesk_logic.php`, `team_logic.php` pass; owner answers Q1–Q7 (defaults allowed). | Nothing DB-backed can be tested today. |
| **P1** | **Schema + core logic** | `add_pos.sql`, `includes/pos.php` (pure functions + `pos_complete_sale`/`pos_void_sale`), `tests/pos_logic.php`. No UI. | The money/stock/room-charge rules are the risky part; lock them with tests first. |
| **P2** | **Catalogue admin** | Outlets, categories, items (incl. link tours), consignors, stock receive/adjust, staff assignment, seed script. | You can't sell what isn't set up; also lets the owner fill prices while P3 is built. |
| **P3** | **Till v1 (admin session)** | `/pos/` UI from the prototype: outlet switch, cart, walk-in customer, Cash/Card/M-Pesa/Other, complete, receipt, today's history. Stock decrements. | First real sales. Uses the existing admin login → no new security surface yet. |
| **P4** | **Room charge** | In-house search + guest attribution, `bill_items` posting, Bill-tab badge + delete guard, void removes the bill line. | The headline integration; depends on P1 eligibility rules + P3 till. |
| **P5** | **Terminals + PIN** | `pos_terminals`, registration flow, lock screen, PIN set/reset, lockout, auto-lock, new job types → home `/pos/`. | Security-sensitive; built on a working till. |
| **P6** | **History + reports** | Admin sales history + void, Z-report per outlet/day by payment method, stock + low-stock report, consignment statement + payouts, CSV. | Needs real sales data to be meaningful. |
| **P7** | **Later / optional** | `pos.tribalsand.com` alias, email receipts, M-Pesa STK push, receipt printer (ESC/POS), guest signature on room charge, offline queue, POS revenue on `admin/reports.php`, AI assistant tool for POS sales (read-only). | Nice-to-haves. |

### Acceptance per phase (tests that must pass)

**P1 — `tests/pos_logic.php`** (pure always; DB block inside a rolled-back transaction):
- totals: 10% service on $210 → $21 / $231; cents rounding; zero-service outlet.
- line resolution: item price beats tour price; tour price used when item price NULL; open price
  required when both NULL; per-person qty; qty 0/negative refused.
- room-charge eligibility: in-house today ✔; arrives tomorrow ✘; checked out yesterday ✘;
  departure day ✔; pending web enquiry (has expiry) ✘; staff-typed pending ✔; wrong venue ✘;
  currency mismatch ✘; outlet room charge off ✘.
- DB: sell 2 of 10 → stock 8 + one `sale` move; sell 3 of 2 → refused, nothing written;
  same `client_uuid` twice → one sale; room charge → exactly one `bill_items` row with
  `pos_sale_id` and correct `guest_id`, `bill_total()` increases by the sale total;
  void → stock back to 10, bill row gone, sale `voided`; consignment line snapshots consignor +
  commission; a staff user not assigned to the outlet → refused; cross-sold item via
  `pos_outlet_links` → allowed and `owning_outlet_id` = source outlet.
- existing suites still green: `bill_logic`, `frontdesk_logic`, `team_logic`, `reception_role`.

**P3/P4 — browser smoke** (dev server + Browser pane, same script as the prototype run):
login → Experiences → add items → in-house guest → Room charge → complete → open
`admin/booking.php?hold=<id>&tab=bill` and see the POS line with badge and no trash → open the guest
portal bill and see it under the guest's name → void from admin → line disappears, stock restored.
Shop: sell until out of stock → tile disabled; walk-in cannot pick Room charge.

**P5 —** PIN only works with a terminal cookie; 5 wrong PINs locks the user; POS session cannot
open `/admin/dashboard.php`; revoked terminal is bounced; idle auto-lock fires.

---

## 8. Risks & guardrails (don't skip)

1. **Client-trusted prices** — the server re-prices every line; the cart only sends item ids + qty
   (+ open price for "on request" items, manager-bounded).
2. **Double sale on double-tap / flaky Wi-Fi retry** — `client_uuid` UNIQUE + return-existing.
3. **Stock race between two tablets** — `SELECT … FOR UPDATE` inside the sale transaction.
4. **Bill and sale drifting apart** — POS bill rows only change through `pos_void_sale()`;
   `bill_del` refuses them.
5. **Cross-currency sums** — sale single-currency; reports grouped by currency; room charge gated (D3).
6. **Scope leaks** — every endpoint re-checks outlet permission and, for room charge, the hold's
   venue against the outlet; never trust posted `outlet_id`/`hold_id`.
7. **PIN brute force** — terminal-bound, hashed, per-user lockout, IP rate limit, trivial-PIN refusal.
8. **Pre-migration deploy** — `pos_supported()` hides nav + `/pos/` shows "not enabled yet"; the
   Bill tab keeps working when `bill_items.pos_sale_id` doesn't exist (guard like `bill_item_guest_supported()`).
9. **Timezone** — "in house today" uses `frontdesk_today_ymd()` (Nairobi).
10. **Prod** — migrations applied to RDS separately via `/admin/migrate.php`; then `seed_pos.php`.

---

## 9. File map (to create)

```
db/migrations/add_pos.sql, add_pos_job_types.sql     db/seeds/seed_pos.php
includes/pos.php          (catalogue, totals, sale/void, eligibility, history)
includes/pos-auth.php     (terminal + PIN auth, outlet permissions, require_pos)
pos/index.php  pos/receipt.php  pos/register.php (terminal setup)  pos/logout.php  pos/manifest.json
js/pos.js   css/pos.css
api/pos/catalog.php inhouse.php customers.php sale.php sale-void.php sales.php
admin/pos-sales.php pos-outlets.php pos-items.php pos-stock.php pos-consignors.php pos-terminals.php
tests/pos_logic.php
Edits: admin/_layout.php (nav group), admin/_ws_bill.php (POS badge, no trash), admin/booking.php
(bill_del guard), admin/staff.php (PIN), includes/auth.php (admin_home_url for shop/spa/kite),
CLAUDE.md (POS section + file map rows).
```

---

## 10. How to start the next chat

> "Implement the POS per `docs/pos/POS-PLAN.md`. Start with P0 then P1. Use the prototype
> `docs/pos/pos-prototype.html` as the UI reference for P3. Stop after each phase with test output."
