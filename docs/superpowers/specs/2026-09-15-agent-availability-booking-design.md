# Travel-agent portal — live availability + "Request to book" (design)

**Date:** 2026-09-15 · **Status:** approved for implementation (autonomous session — see
"Decisions made without confirmation" at the end)

> **Revision 2026-09-16 (owner decision): a trade request never places a hold.** Everything below
> that says the request creates a 24h hold is superseded: `agent_submit_request()` writes the
> submission only (no hold, no block, no sweep); reservations place the hold with Convert to Hold in
> the enquiry view, and `agent_tag_converted_hold()` links that hold to the agent and freezes their
> net for the room actually booked. §5.1 (`holds.agent_id`), §5.5, §5.7 and the ledger rule still
> apply to the *converted* hold. The staff hold-notification trade rows and the pending-hold cap
> were dropped with the automatic hold. Implemented as shipped in `includes/agent.php`.

## 1. Problem

Travel agents can now sign in at `/agent` (T3, commit `a153cf9`), but the portal is a
read-only rates table. They cannot check whether dates are free and cannot send the
"Request to Book" that the public site offers, so every trade booking still goes through
an email to reservations. The T3 commit itself listed "agent-initiated holds
(`source='agent'`)" as the follow-up. This is that follow-up.

## 2. Goal

A signed-in agent can:

1. **Check live availability** for a date range and party size across every published
   property (or one of them) and see, per option, the published stay total struck through
   and their **net (trade) total** — resolved through the ONE pricing path.
2. **Request to book** any single room or the whole property. That creates the **same 24h
   hold the public widget creates** (or an enquiry when the room is in enquiry mode), tagged
   as a trade booking at the agent's net rate, so staff see it in the holds list, the
   booking workspace, the enquiry inbox, the notification email and the revenue ledger.
3. **See the state of their requests** (on hold + expiry, confirmed, expired, cancelled,
   enquiry sent) on a "Your requests" page.

Non-goals (v1): online payment; agents editing/cancelling holds themselves (they use the
manage link they are emailed, like a guest); an atomic multi-room hold for combinations
(same v1 rule as guests — a combination becomes an enquiry); Maya Ilai's configurator
pricing (see §8).

## 3. Approaches considered

| | Approach | Verdict |
|---|---|---|
| A | **Server-rendered pages under `/agent` reusing the shared resolvers** (`ts_property_configurations()`, `room_stay_quote()`, `create_hold_with_block()` / `mi_allocate_and_hold()`), agent discount applied by a pure helper. | **Chosen.** One pricing path, one hold path, no new JS surface, matches the portal's standalone/no-admin-assets rule, no-JS-friendly forms. |
| B | Reuse the public `api/property-availability.php` + booking modal from the portal, applying the discount client-side. | Rejected: the net price would be computed in the browser (not the ONE path, not testable), and the guest modal posts to the Turnstile-gated public endpoint, which an agent session should not go through. |
| C | Give agents an `admin_users` role and reuse `admin/hold-new.php`. | Rejected: breaks the load-bearing identity isolation (agents must never reach `/admin`), and `hold-new` deliberately skips availability checks. |

## 4. Architecture

```
agent/availability.php ──GET──▶ ts_property_configurations() per venue (ONE pricing path)
        │                          └─ agent_price_configurations()  (pure: adds net_* figures)
        │ "Request to book" links
        ▼
agent/request.php ──GET──▶ agent_stay_quote() + live re-check ▶ summary + traveller form
        │ POST (CSRF)
        ▼
agent_submit_request()  ── ONE transaction ──▶ submissions row (type 'enquiry', payload.agent_*)
        │                                     └▶ hold (availability mode) via the SAME writers the
        │                                        guest path uses; holds.agent_id + quoted_amount=net
        │ after commit: staff hold/enquiry notification (+ trade rows), agent acknowledgement
        ▼
agent/requests.php ──▶ agent_requests(): submissions WHERE payload_json->>'agent_id' = me
                                          LEFT JOIN holds → status / expiry / manage link
```

**Everything the guest path proves is reused, not re-implemented:** allocation and
oversell safety (`find_available_unit()` + `create_hold_with_block()`; `mi_allocate_and_hold()`
for a Maya Ilai composite room), the rate resolver (`room_stay_quote()` /
`ts_property_configurations()`), the ledger snapshot (`bookings_sync_hold()` reads
`holds.quoted_amount`), and the existing emails.

## 5. Components

### 5.1 Data — migration `db/migrations/add_holds_agent.sql` (after `add_travel_agents`)

```sql
ALTER TABLE holds ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_holds_agent_id ON holds(agent_id) WHERE agent_id IS NOT NULL;
-- Server-written link from a request to its agent (the payload is client-posted):
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_submissions_agent ON submissions (agent_id) WHERE agent_id IS NOT NULL;
-- Pre-column rows are found by payload id + HMAC marker (agent_sig):
CREATE INDEX IF NOT EXISTS idx_submissions_payload_agent ON submissions ((payload_json->>'agent_id'));
```

- `holds.agent_id` = which agent requested the hold. Guarded everywhere by a new
  `holds_agent_supported()` (information_schema probe in `includes/db.php`, beside
  `holds_room_id_supported()` — never a failing SELECT, because it is first reached inside
  the booking transaction).
- The **net price is frozen on the hold** in the existing `holds.quoted_amount` /
  `quoted_currency` (migration `add_holds_quoted_amount.sql`, guarded by
  `holds_quoted_amount_supported()`). `bookings_sync_hold()` already prefers that column,
  so the ledger records what the agent will actually pay with no new money code.
- The **submission payload is the canonical record of the request** and needs no
  migration: `agent_id, agent_name, agency, agent_email, traveller_email, traveller_phone,
  discount_pct, published_total, quoted_total (= net), quoted_currency, quoted_label,
  source: 'trade-portal'` (+ `rooms` for a combination). The admin enquiry view renders
  these automatically (generic payload rows + the existing "Price at enquiry" block).

Pre-migration behaviour (deploy before migrate): requests still work — the hold is created
without `agent_id` (the submission payload still names the agent), the admin badge and
ledger source fall back to the plain website behaviour, and "Your requests" still works
because it reads the payload. Nothing 500s.

### 5.2 Helpers — `includes/agent.php`

| Function | Pure? | Contract |
|---|---|---|
| `agent_price_configurations(array $cfg, array $agent, int $venueId): array` | yes | Same shape as `ts_property_configurations()` output, with `discount_pct` at the top and `net_total` added to every single / entire item, every combo, and every combo room (`agent_net_price()` on the published total). Published figures are never altered. |
| `agent_stay_quote(array $room, array $agent, string $ci, string $co): array` | no (DB via `room_stay_quote`) | `['nights','published','net','currency','discount_pct']`. `nights === 0` = not a quote — callers must reject it (never a $0 stay). |
| `agent_valid_stay(string $ci, string $co): ?array` | yes | `rates_window_ymd()` on both, `ci < co`, `ci >= today` (Nairobi-local), ≤ 30 nights → `[ci, co, nights]` or null. |
| `agent_room_form_mode(array $room): string` | no | The exact rule `api/submit-enquiry.php` uses: room `form_mode` → global `form_mode` setting → `'enquiry'` when the inventory room (via `room_inventory_room_id()`) has no active units. |
| `agent_submit_request(array $agent, array $req, array $tracking = []): array` | no | The writer (§5.3). Never sends email. |
| `agent_requests(array $agent, int $limit = 100): array` | no | The agent's requests, newest first (§5.5). |
| `agent_request_status(array $row): array` | yes | `['label','class']` for a request row (hold status + expiry countdown, or "Enquiry sent"). |
| `agent_trade_lines(array $agent, array $quote, int $nights): array` | yes | The two human strings used in emails/admin: booked-by (`Agency — Name <email>`) and rate (`USD 850 net · 2 nights · 15% off USD 1,000`). |

`holds_agent_supported()` lives in `includes/db.php`.

### 5.3 The writer — `agent_submit_request()`

Input `$req`: `kind` (`room` | `combo`), `room_slug` (room) or `venue_slug` + `rooms`
`[['slug','units'],…]` (combo), `check_in`, `check_out`, `adults`, `children`,
`guest_name` (traveller, required), `guest_email`, `guest_phone`, `notes`.

Steps, all inside ONE transaction (`$ownTx` pattern from `mi_book_configuration()` so a
test can wrap it in a transaction it rolls back):

1. Validate: `agent_valid_stay()`; room published (or venue published + every combo room
   published and belonging to that venue); guests 1..30 adults, 0..20 children; traveller
   name non-empty. Failure → `['ok'=>false,'error','code'=>422]`.
2. Quote server-side: `agent_stay_quote()` per room (× units for a combo; mixed currencies
   in one combo → 422, money is never summed across currencies). Nothing from the form is
   trusted for money.
3. `room` kind, availability mode: sweep lapsed holds ONCE before the transaction
   (`expire_stale_holds()`), then inside it re-check without sweeping
   (`find_available_unit_internal(…, false)`) — a sweep inside the transaction would be
   rolled back by a 409/500 after its expiry e-mails had gone out — then insert the
   submission, then the hold — `mi_allocate_and_hold()` for a Maya Ilai composite room,
   else `create_hold_with_block(unit, submissionId, ci, co, traveller, AGENT EMAIL,
   'pending', 24, $unit['_mi_components'] ?? null, roomId)`. Then `UPDATE holds SET
   agent_id, quoted_amount = net, quoted_currency` (each column only when supported).
   Lost the race → rollback → `['ok'=>false,'code'=>409]`.
4. `room` kind, enquiry mode, and every `combo`: submission only (type `enquiry`;
   `room_id` = the room, or NULL for a combo).
   Before any write (both modes): an identical re-send inside 30 s — same agent, dates and
   product/room set (`agent_recent_duplicate()`) — returns the earlier request instead of
   writing again; `agent_request_throttled()` refuses beyond 12 requests / 10 min or 15
   pending holds per agent; `agent_room_bookable()` refuses the six unit-less Maya Ilai
   products.
5. Commit. Return `['ok'=>true,'submission_id','hold_id'|null,'mode'=>'hold'|'enquiry',
   'quote','room','hold'(row joined with unit/room names, for the email)]`.

**Contact of record = the agent.** `submissions.guest_email` and `holds.guest_email` are the
agent's login email; `guest_name` is the traveller's name. Every automatic email about the
booking (acknowledgement, confirmation, cancellation, expiry, the manage link) therefore
goes to the agent, and admin's "Reply via Email" reaches the agent — the trade partner
owns the client relationship. The traveller's own email/phone are recorded on the
submission (payload + message) for reception.

### 5.4 Portal pages (standalone chrome, `agent/_layout.php`)

- `_layout.php` gains a nav (**Rates · Availability · Your requests** · Sign out, with
  `$agentActive`), loads the shared `/css/datepicker.css` + `/js/datepicker.js` (self-
  contained, no admin assets), and a few more classes (option cards, inline form, badges).
- `availability.php` — GET form: check-in/out (`.dp-btn` + hidden inputs, the shared
  picker), adults, children, property (All | one). Valid params → for each published venue
  in scope: `ts_property_configurations($v, $ci, $co, $guests, null, true)` →
  `agent_price_configurations()` → sections **Available rooms**, **Combinations** (only
  multi-room ones, as on the property page), **Whole property**; each option shows
  `published total` (struck when a discount applies) and **net total**, nights, sleeps, and a
  "Request to book" link into `request.php` carrying `room`/`venue`+`rooms`, dates and
  guests. No options → the same "sleeps up to N" hint the guest widget gives. Invalid
  dates → inline error; past check-in refused (Nairobi-local today).
- `request.php` — GET: re-validates the query, re-quotes and re-checks live availability,
  shows the summary (property, room(s), dates, nights, guests, published, discount, **net
  total**) and the traveller form (name*, email, phone, notes) with `csrf_field()`; hold
  mode says "Dates are held for 24 hours pending confirmation", enquiry mode says "We'll
  confirm availability and price by email". Gone already → message + back link. POST:
  `verify_csrf()`, `agent_submit_request()`, then emails (§5.6), then PRG to
  `requests.php?sent=<submission id>` (409/422 re-render the page with the message).
- `requests.php` — the list (§5.5) with a success banner after a send.
- `rates.php` / `login.php` copy: "view your rates, check live availability and request
  bookings"; login lands on `availability.php`.

### 5.5 "Your requests" — `agent_requests()`

```sql
SELECT s.id, s.created_at, s.check_in, s.check_out, s.guest_name, s.room_id, s.payload_json,
       r.name AS room_name, v.name AS venue_name,
       h.id AS hold_id, h.status AS hold_status, h.expires_at, h.access_code
  FROM submissions s
  LEFT JOIN rooms  r ON r.id = s.room_id
  LEFT JOIN venues v ON v.id = r.venue_id
  LEFT JOIN LATERAL (SELECT id, status, expires_at, access_code FROM holds
                      WHERE submission_id = s.id ORDER BY id DESC LIMIT 1) h ON TRUE
 WHERE <agent_requests_filter()>   -- s.agent_id = :aid, or payload id + HMAC agent_sig for pre-column rows
 ORDER BY s.created_at DESC LIMIT :lim
```

Columns: sent, property · room(s) (combo rooms from the payload), dates + nights,
traveller, net price (payload `quoted_total`/`quoted_currency`), status
(`agent_request_status()`: "On hold · expires in 11h 40m" / Confirmed / Expired /
Cancelled / "Enquiry sent"), and "Manage" (`make_manage_url()`) for holds. Reads the
payload, so it works with or without the new column. Wrapped in try/catch → friendly
message, never a blank page.

### 5.6 Emails (`includes/mail.php`)

- `send_hold_notification($hold)` accepts two **optional** keys, `trade_agent` and
  `trade_rate` (from `agent_trade_lines()`); when present the subject becomes
  `[Trade Hold Request] …` and two rows ("Booked by", "Trade rate") are added to the text
  and HTML tables. Absent → byte-identical to today.
- Enquiry mode / combos: `send_notification()` unchanged — the trade lines are the first
  lines of the stored `message`, so staff (and the inbox) see them.
- Agent acknowledgement: `send_guest_acknowledgement()` with `kind` `hold` | `enquiry`,
  addressed to the agent (`guest_name` = agent's name), `agency_name` row, the room/dates/
  guests rows, and a new optional **`price`** row (label "Price") that only this caller
  passes — so the agent has the net figure on record while guest acks stay unchanged.

### 5.7 Admin + ledger

- `includes/bookings.php` `bookings_sync_hold()`: when `holds_agent_supported()`, select
  `h.agent_id` and LEFT JOIN `travel_agents`; the **INSERT** writes `source = 'agent'` and
  `agent = agency ?: name` for an agent hold (else `'website'` as today). Gross already
  comes from the frozen `quoted_amount` (= net). The UPDATE path is untouched: an existing
  `agent` row is already treated as "money frozen" by the `$imported` guard, which is the
  behaviour we want (never restate revenue).
- `admin/holds.php`: LEFT JOIN `travel_agents` when supported; a `badge--blue` "Trade ·
  <agency|name>" under the guest name.
- `admin/booking.php` header line: "· Trade booking via <agency> (<name>, <email>)" and,
  when `quoted_amount` is set, "· net <currency amount>".
- `admin/agents.php` intro copy: agents can now check availability and request bookings.
- `admin/submissions.php` `source_label()`: `request*` → "Trade portal".
- `CLAUDE.md`: new "Travel-agent portal" section (identity isolation, ONE price, contact of
  record, the writer, pre-migration guards, known limits, the migration order).

## 6. Error handling

| Case | Behaviour |
|---|---|
| Bad / past / reversed dates, > 30 nights | 422-style inline message on the page; nothing written. |
| Room/venue not published, combo room from another venue, mixed currencies | 422 message; nothing written. |
| Dates taken between the search and the POST | 409 "Those dates just went" + back to availability; the transaction is rolled back so no orphan submission (unlike the guest path, which keeps the lead — an agent will simply search again). |
| DB failure mid-write | rollback, logged (`error_log('[agent-request] …')`), generic message. |
| `holds.agent_id` / `quoted_amount` columns missing | request still succeeds; those columns are skipped; the payload carries the facts. |
| `travel_agents` table missing | whole portal already reads "not enabled". |
| Mail failure | best-effort after commit (existing `_dispatch_mail` semantics); the request is already saved. |

## 7. Security

- Every page/endpoint: `agent_require_login()` (session key `agent_id`, never `admin_id`).
- The request POST is CSRF-checked with the shared session token (`verify_csrf()`).
- Money is computed server-side from the agent row at write time; the client sends only
  identifiers, dates and traveller details. Rooms/venues are re-validated as published.
- "Your requests" is filtered by the session agent's id; an agent can never see another
  agent's requests or rates.
- No Turnstile (authenticated surface); instead a 30 s idempotency window on identical
  re-sends and a per-agent throttle (12 requests / 10 min, 15 pending holds).
- The request list is keyed on the server-written `submissions.agent_id` (or the HMAC
  `agent_sig` for pre-column rows), never on a client-posted payload id.
- Inputs bounded (party sizes, 30-night cap, string lengths trimmed/limited as elsewhere).

## 8. Known limits (deliberate, documented in CLAUDE.md)

- **Maya Ilai is priced off the rate card** (`room_stay_quote()`), exactly as the existing
  agent rates page and `/search` already do; the six per-bedroom composite products are
  **refused** by `agent_room_bookable()` (not listed, and not bookable by URL). The villa
  and the studios are bookable, through the locked allocator.
- Combinations become an enquiry (no hold) — parity with guest v1; the atomic multi-room
  hold stays the v2 follow-up.
- Agents cannot cancel from the portal; they use the emailed manage link (as guests do).

## 9. Testing — `tests/agent_portal_logic.php` (extended)

- Pure: `agent_price_configurations()` (net = published × (1 − pct) on singles, entire,
  combos and combo rooms; 0 % leaves totals equal; per-venue override wins; published
  figures untouched); `agent_valid_stay()` (past, reversed, malformed, cap);
  `agent_request_status()`; `agent_trade_lines()`.
- DB, inside ONE transaction that is rolled back (SKIP with a clear message when the table
  or columns are absent): insert a test agent; pick a published room with an active unit;
  `agent_submit_request()` on a far-future window → submission payload (agent_id, net
  quoted_total), hold (`agent_id`, `quoted_amount` = net, `guest_email` = agent email,
  `guest_name` = traveller), availability block present; **parity**: the hold's
  `quoted_amount` equals `agent_net_price(room_stay_quote(...)['total'])`; flip the hold to
  confirmed and `bookings_sync_hold()` → ledger row `source = 'agent'`, `gross_amount` = net,
  `agent` = agency; `agent_requests()` returns the row with the hold status; a second
  request for the same single-unit room → 409, nothing written.
- Existing suites stay green: `tests/rates_logic.php`, `tests/capacity_search_logic.php`,
  `tests/reports_logic.php`, `tests/maya_ilai_inventory.php`.
- Manual smoke on the local dev server: log in as a test agent, search, request a hold,
  see it in `/admin/holds.php` with the Trade badge and in the enquiry inbox, confirm it,
  check the ledger row and the "Your requests" status.

## 10. Decisions made without confirmation (autonomous session)

1. **The agent is the contact of record** (emails and manage link go to the agent, the
   traveller's contact is recorded for reception). Standard trade practice; flip is a
   one-line change in the writer if the owner prefers traveller-addressed emails.
2. **Combinations = enquiry, not holds** (guest v1 parity).
3. **One small migration** (`holds.agent_id` + two indexes), applied to production via
   `/admin/migrate.php` after `add_travel_agents.sql` and `add_holds_quoted_amount.sql`.
4. **Search is cross-property by default** with an optional single-property filter.
