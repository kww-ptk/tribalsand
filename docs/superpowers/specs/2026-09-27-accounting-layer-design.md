# Accounting layer — companies, documents & payments, QuickBooks, eTIMS

**Date:** 2026-09-27 · **Status:** design approved; spec reviewed 2026-09-28 (corrections in §12) · P1 + P2a + P2b built (P3 QuickBooks, P4 eTIMS need external accounts)
**Depends on:** bookings ledger (`add_bookings_finance.sql`), POS (`add_pos.sql` →
`add_pos_job_types.sql` → `add_pos_v2.sql`), room bill (`add_bill_items.sql`).
Inventory (`db/migrations/add_inventory.sql`, `includes/inventory.php` — merged to
`master`; design in `docs/superpowers/specs/2026-09-27-inventory-assets-design.md`) is
consumed through its §8 seam; the small additions it needs are listed in §7.

## 1. Goal

Different properties belong to different legal companies, each with its own KRA PIN,
VAT status, bank accounts, tills and invoice numbering. The shared outlets (shop,
spa, kite school, experiences) belong to their own operating company. The app must
turn every money event — room stays, extras, POS sales, room charges, stock moving
between companies, payments — into correct documents **for the right company**, get
them signed by KRA eTIMS, and land them in that company's books.

What exists today (read before designing):
- `bookings` knows what each stay **earned** (gross frozen at confirm, per venue, per
  currency). `bookings.amount_paid` exists but is **never written**.
- The room bill (`fetch_bill_lines()` / `bill_items` / `bill_total()`) has charges but
  **no payments, no settlement, no invoice number**.
- `pos_sales` has VAT, tip, service charge, payment method and a per-outlet receipt
  reference. Seeded outlets have `venue_id = NULL` (shared), so no company owns them.
- Nothing anywhere records money **received**, and nothing issues a tax invoice.

Owner decisions (brainstorm, 2026-09-27):

| Question | Decision |
|---|---|
| Books today | **Accountant + spreadsheets** — no package yet |
| Package | **QuickBooks Online**, one QBO company file per legal company |
| Company structure | **Shared services have their own company**; properties map to companies many-to-one |
| VAT status | **A mix** — set per company |
| POS room charge across companies | **Accountant to confirm** — build "collected on behalf" (default) with "re-invoice" as a per-company setting |
| Inventory across companies | **Invoice per transfer**, at the move's cost snapshot, no markup |
| Home currency | **KES for every company** |
| Payments | **Recorded in the app** — reception sees balance due; QBO receives applied payments |
| Tax invoice timing | **Accountant decides** — per-company setting, default one folio at checkout |
| Architecture | **Approach A** — the app issues + signs every sales document; QBO is the ledger |
| eTIMS route | **Decide later** — one adapter; provider picked at the start of P4 |

Rejected approaches: **B** (daily journal entries only — QBO loses per-agent/per-guest
receivables); **C** (QBO issues invoices via a QBO↔eTIMS connector — a POS receipt must
carry its eTIMS number and QR when it prints, which a QBO round trip cannot give).

## 2. Phases

Each phase gets its own implementation plan, ships alone, and is inert until switched on.

| Phase | Adds | Migration |
|---|---|---|
| **P1 Companies** | `companies`, company money accounts, company ↔ venue / outlet / stock-location ownership, gapless numbering | `add_companies.sql` |
| **P2 Documents & payments** | The sales sub-ledger: folios, invoices, credit notes, payments, allocations, inter-company entries; monthly CSV for the accountant | `add_acct_documents.sql` (after `add_companies`) |
| **P3 QuickBooks sync** | OAuth per company, outbox worker, mapping, sync status | `add_acct_qbo.sql` (after `add_acct_documents`) |
| **P4 eTIMS** | Adapter, signing of invoices, credit notes and POS receipts, offline queue | `add_acct_etims.sql` (after `add_acct_qbo`) |

P2 is useful before QBO exists: the accountant gets per-company documents + payments
as CSV. Every migration is idempotent; every read is pre-migration-safe (§6).

## 3. P1 — companies and ownership

### `companies`
`id, name, legal_name, kra_pin, vat_registered BOOL, etims_enabled BOOL,
home_currency CHAR(3) DEFAULT 'KES', invoice_timing ('checkout'|'confirm', default
'checkout'), room_charge_mode ('on_behalf'|'reinvoice', default 'on_behalf'),
accounting_starts_on DATE NULL, address TEXT, logo_key, is_active, created_at`

- `accounting_starts_on` is the **go-live gate**. NULL = accounting off for that
  company: no documents are issued, nothing changes on any page. Set = documents are
  issued for folios and sales dated on/after it. There is **no historical backfill**;
  the accountant enters opening balances in QBO.
- `room_charge_mode` lives on the **selling** company (the services company) — it
  decides how its room charges reach another company's folio (§4.4).

### `company_accounts` — where money lands
`id, company_id, label, kind ('bank'|'mpesa_till'|'mpesa_paybill'|'cash'|'card_merchant'),
currency, bank_name, account_number, is_default BOOL, is_active`
- One default per `(company_id, currency)` (unique partial index).
- A payment always names a `company_accounts` row; the row's company must equal the
  document's company (checked server-side).

### `company_doc_sequences` — gapless numbering
`company_id, doc_type ('invoice'|'credit_note'|'proforma'|'ic_invoice'), prefix,
next_no, PRIMARY KEY (company_id, doc_type)`
- Number format `<prefix><zero-padded next_no>`, e.g. `ZUR-INV-000123`.
- `acct_next_number($companyId, $type)` takes the row `FOR UPDATE` and increments it
  **inside the issuing transaction**, so a rolled-back issue rolls the counter back —
  no gaps, no duplicates. The prefix is editable until the first document of that type
  is issued, then locked.
- The POS `POS-<OUTLET>-<n>` reference stays as the till's receipt id; the tax
  document carries the company number alongside it.

### Ownership — one resolver per source
New columns: `venues.company_id`, `pos_outlets.company_id` (both NULL-able FK).
Inventory: new `inv_locations.company_id` (see §7).

| Source | Owning company |
|---|---|
| Room booking / folio | `venues.company_id` of the hold's venue |
| POS sale | `pos_outlets.company_id` (shared outlets → the services company) |
| POS sale line | `pos_outlets.company_id` of `owning_outlet_id` (cross-sold lines belong to the outlet whose item it is) |
| Stock location | its `venue_id` → `venues.company_id`; a shared location (`venue_id` NULL, e.g. Main stock) → its own `inv_locations.company_id` |

Functions in `includes/companies.php`: `company_for_venue()`, `company_for_outlet()`,
`company_for_location()`. Each returns `null` when unresolved. **Fail closed once live:**
a POS outlet whose company has accounting live but that resolves to no company cannot
sell; a stock transfer that would cross an unresolved location is refused.

### Pages (owner-only, `require_owner()`)
`admin/companies.php` (list) + `admin/company-edit.php` (details, accounts, numbering,
venues and outlets owned). Venue and outlet edit pages show a company picker.

## 4. P2 — documents, payments, inter-company

### 4.1 Documents
`acct_documents`: `id, company_id, doc_type ('proforma'|'invoice'|'credit_note'|'ic_invoice'),
number NULL (set on issue), status ('draft'|'issued'), customer_kind
('guest'|'agent'|'ota'|'company'|'walkin'), customer_name, customer_pin NULL,
counterparty_company_id NULL (ic_invoice + company customers), agent_id NULL,
currency, fx_to_home NUMERIC(14,6) (rate snapshot at issue), subtotal, vat_amount,
total, hold_id NULL, booking_id NULL, pos_sale_id NULL, transfer_ref NULL,
credits_document_id NULL (credit notes), issued_by, issued_at, created_at`

`acct_document_lines`: `id, document_id, description, qty, unit_price, line_total,
category, tax_band ('A'|'B'|'C'|'D'|'E'), vat_rate, vat_amount, is_disbursement BOOL,
supplier_company_id NULL, source_kind, source_id`

- **Issued documents are immutable.** Corrections are credit notes (full or partial),
  never edits or deletes — the POS-void rule.
- **One currency per document.** A folio that holds USD and KES charges issues one
  invoice per currency. Totals, statements and aging are always keyed by currency.
- **Categories** drive QBO items and eTIMS bands: `room | fnb | retail | activity |
  spa | transfer | service_charge | other`, plus non-revenue `tip | disbursement`.
  Resolved by `acct_category_for_*()` helpers (POS outlet kind → category; add-on kind
  → category). Tips are a **liability to staff**, never revenue.
- **Amounts come from the existing single sources — never re-quoted.** Stay =
  `bookings.gross_amount` (frozen at confirm; for imports, the entered amount); extras
  = the bill lines' stored prices; POS = the sale's stored lines and VAT snapshot.
  Accounting never runs its own nightly loop or re-prices a line.
- **VAT per line** from the company: not VAT-registered → band D, VAT 0. VAT-registered
  → band per category (set in the mapping, default B 16%). VAT-inclusive vs exclusive
  follows the source (POS outlets already snapshot `vat_inclusive`).

### 4.2 Payments
`acct_payments`: `id, company_id, account_id → company_accounts, kind ('receipt'|'refund'),
is_security_deposit BOOL, method ('cash'|'card'|'mpesa'|'bank'|'ota_payout'|'intercompany'),
amount, currency, fx_to_home, reference, payer_name, hold_id NULL,
counterparty_company_id NULL, received_at, recorded_by, created_at`

`acct_allocations`: `id, payment_id, document_id, amount (in the document's currency),
rate NUMERIC(14,6) (payment currency per document currency), created_at`

- A payment recorded before any invoice (a deposit) stays **unallocated** — a
  prepayment on the hold. Issuing the folio invoice applies the hold's unallocated
  payments automatically, oldest first.
- A KES payment against a USD invoice records its own rate; QBO books the FX gain/loss.
- **Security deposits** (charged at the property, see check-in deposit step) are
  `is_security_deposit` receipts: held as a liability, never allocated to revenue
  unless staff explicitly apply them (damage), otherwise refunded.
- Payments are never edited; a mistake is a `refund` of the same amount with a reason.

### 4.3 Where documents come from
- **Room folio.** The booking workspace **Bill tab becomes the folio**: stay + extras +
  room charges − allocated payments = **balance due**, per currency. Reception records
  payments there. With `invoice_timing = checkout`: "Issue invoice" creates the tax
  invoice(s) at checkout and applies prepayments. With `confirm`: the stay is invoiced
  when the hold is confirmed (hooked into the three existing confirm sites next to
  `bookings_sync_hold()`), extras at checkout; a cancellation after that issues a
  credit note (next to `bookings_mark_hold_cancelled()`). A pro-forma (not a tax
  document) can be printed any time.
- **Agent bookings** — invoice to the agent (`customer_kind = agent`) at the frozen net
  (`holds.quoted_amount`).
- **OTA bookings** — invoice to the guest or to the OTA, per channel setting
  (`ota_collect` vs `hotel_collect`). Recording an OTA payout records the commission
  (gross − payout) as an expense line for QBO (§5).
- **POS sale** — `pos_complete_sale()` issues the company's document (`doc_type =
  invoice`, customer `walkin` or `guest`) inside its own transaction when the outlet's
  company is live; `pos_void_sale()` issues the credit note. `pos_sales` stays the
  till's truth; the document is derived from it. **Exception — room charges:** a room
  charge to a guest of the **same** company issues no POS document (it is invoiced once,
  on the folio); a cross-company room charge follows §4.4. Nothing is ever invoiced twice.

### 4.4 POS room charge across companies
A room charge whose outlet company ≠ the guest's property company:

- **`on_behalf` (default).** The services company issues its own document to the guest.
  The property's folio shows the charge as an `is_disbursement` line (no VAT, no
  property revenue, `supplier_company_id` = services). When the property issues the
  folio, an inter-company entry records *property owes services* for that amount, and
  the services document is settled by an `intercompany` payment.
- **`reinvoice`.** The services company issues an `ic_invoice` to the property
  company; the folio line is property revenue with the property's VAT.

Same-company room charges are ordinary folio lines. Either mode leaves an exact
inter-company balance.

### 4.5 Inter-company
`acct_ic_entries`: `id, from_company_id (owes), to_company_id, amount, currency,
source_kind ('room_charge'|'ic_invoice'|'settlement'), document_id, payment_id, created_at`
- Cross-company **stock transfer** → one `ic_invoice` per `transfer_ref` (all lines of
  one transfer), valued at the `inv_moves` value snapshot, no markup, VAT per the
  sending company.
- **Settlement** between companies is a payment (`method = intercompany`,
  `counterparty_company_id`) that reduces the balance.
- `admin/acct-intercompany.php` shows net balance per company pair per currency.

### 4.6 Pages & export
`admin/acct-documents.php` — documents + payments, filter by company / type / period,
per-currency totals, **monthly CSV per company** for the accountant, credit-note
action. Printable invoice/credit note/pro-forma at `admin/acct-document-print.php`
(company name, legal name, KRA PIN, address, logo, number, eTIMS QR once P4 is live).

## 5. P3 — QuickBooks Online sync

- **Connection.** Admin → Companies → *company* → **Connect QuickBooks** (owner-only)
  runs QBO OAuth 2.0 (`admin/qbo-connect.php` → `admin/qbo-callback.php`, `state`
  bound to the session). `qbo_connections`: `company_id, realm_id, access_token_enc,
  refresh_token_enc, access_expires_at, refresh_expires_at, connected_by, connected_at`.
  Tokens are sodium-encrypted with `ACCT_TOKEN_KEY`. The worker refreshes on every run
  (the refresh token rolls ~100 days); a lapsed connection shows a reconnect banner.
- **One adapter.** `includes/qbo.php` is the only file that knows QBO (raw cURL; the
  request function is `function_exists`-guarded so tests stub it — the `includes/ai.php`
  pattern). Unset `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` → sync inert, P1/P2 unaffected.
- **Outbox.** Issuing a document, recording a payment or allocation, or closing a POS
  day writes `acct_sync_outbox` (`id, company_id, entity, local_id, status
  ('pending'|'sent'|'failed'|'blocked'), attempts, next_attempt_at, last_error, qbo_id`)
  **in the same transaction**. `bin/acct-sync.php` runs every 5 min from
  `docker/scheduler.sh` behind a Postgres advisory lock, with exponential backoff.
  Every QBO call carries `requestid = <outbox id>`, so a retry after a timeout never
  duplicates.

| App | QBO |
|---|---|
| Folio / agent / OTA / ic invoice | Invoice |
| Credit note | Credit Memo |
| Payment + allocations | Payment linked to its invoices; unallocated deposits wait for the invoice |
| POS walk-in day — per outlet, payment method, currency (Nairobi day) | One Sales Receipt after the day closes; a later void → Credit Memo next day. Room-charge sales are excluded (they travel on folios / ic) |
| OTA payout commission, consignor payout | Expense / Bill |
| Inter-company settlement | Payment/transfer via the mapped inter-company account |

- **Customers.** Agents, OTAs and the companies (as each other's customers) are real
  QBO customers. Individual guests post to one generic customer per currency
  (`Guests – USD`, `Guests – KES` — QBO customers are single-currency), with the guest
  name and document number on the invoice.
- **Mapping, never guessing.** `acct_qbo_map` (`company_id, kind, local_key, qbo_id,
  qbo_name`) per company: category → Item/income account, tax band → TaxCode, company
  account → bank/undeposited account, venue → Class, counterparty company →
  inter-company account, tips → liability account. An unmapped value sets the outbox row
  `blocked` with the missing key — the Ezee room-map rule. Page: `admin/acct-mapping.php`.
- **Write-only.** The app never reads balances back from QBO. Accountant edits in QBO do
  not flow back; app documents are immutable anyway.
- **Status.** `admin/acct-sync.php` (owner + manager): pending / failed / blocked rows,
  error text, retry.

## 6. P4 — KRA eTIMS

- **One adapter.** `includes/etims.php`: `etims_sign(array $document): array` →
  `['ok', 'etims_invoice_no', 'signature', 'qr_url', 'signed_at', 'error']`, plus
  `etims_supported($company)`. `ETIMS_PROVIDER` selects the backend (`off` default; a
  certified integrator or direct KRA OSCU, chosen at the start of P4 — the P4 plan
  defines that provider's credentials). Nothing outside the adapter knows the provider.
- **Per company:** `etims_enabled`, branch id, device serial (added by
  `add_acct_etims.sql`). eTIMS applies to non-VAT-registered companies too (band D);
  the accountant confirms each company's obligation.
- **Document columns:** `etims_status ('not_required'|'pending'|'signed'|'failed')`,
  `etims_invoice_no, etims_signature, etims_qr_url, etims_signed_at`.
- **Sale first, sign second.** A POS sale commits (and its document issues) before any
  eTIMS call, so a KRA outage never loses a sale. The till then signs synchronously with
  a short timeout; signed → receipt prints with QR; not signed → receipt prints
  "eTIMS pending", the scheduler retries, and a reprint shows the QR. The provider's
  offline rules bound how long the queue may hold a document.
- Folio invoices, credit notes and ic invoices are signed at issue the same way.
- Buyer KRA PIN: optional on the POS and the folio; stored for agents and companies.

## 7. Seams required in other specs

- **Inventory** (as merged on `master`): (a) new `inv_moves.transfer_ref` shared by every
  line of one transfer action (`inv_transfer()` and the multi-line restock path), so one
  transfer becomes one ic invoice — today each line is its own `inv_moves` row with no
  shared key; (b) new `inv_locations.company_id` for shared locations (`venue_id` NULL)
  that belong to no venue; (c) a transfer that crosses companies requires a
  unit value on every line (a zero-value inter-company invoice is refused).
- **POS:** `pos_complete_sale()` / `pos_void_sale()` call the document writer inside
  their existing transaction (`pos_tx()` savepoints already allow it).
- **Bookings:** the three confirm sites and the cancel paths call the document writer
  only when `invoice_timing = confirm`.

## 8. Access, safety, testing

- **Access.** Companies, QBO connect, mapping, eTIMS settings → `require_owner()`.
  Folio, record payment, issue invoice → `require_frontdesk()`, scoped by
  `admin_venue_ids()` with a per-row re-check of the hold's venue. Credit notes and
  refunds → owner or manager of that venue, reason required. Documents list, CSV,
  inter-company and sync status → owner + manager, scoped.
- **Pre-migration safety.** `companies_supported()`, `acct_supported()`,
  `acct_qbo_supported()`, `acct_etims_supported()` — `information_schema` probes, never a
  failing `SELECT` (they run inside `pos_complete_sale()`'s transaction).
- **Money.** `NUMERIC(12,2)` storage, integer-cents arithmetic in PHP (the POS
  pattern), never summed across currencies.
- **Tests.**
  - `tests/acct_logic.php` — pure: ownership resolution, category/band resolution,
    folio totals per currency, allocation math with FX, on_behalf vs reinvoice entries,
    ic invoice from a transfer; DB block in one rolled-back transaction: gapless numbering
    under a rolled-back issue, POS sale → document → void → credit note, prepayment
    applied at folio issue, fail-closed when a live outlet has no company.
  - `tests/qbo_sync_logic.php` — adapter stubbed: payload per entity, `requestid`
    idempotency, blocked on unmapped key, token refresh, backoff.
  - `tests/etims_logic.php` — adapter stubbed: sign at issue, pending → retry → signed,
    band D for non-VAT companies.

## 9. Rollout

1. **P1:** run `add_companies.sql` on prod via `/admin/migrate.php`; owner creates the
   companies, accounts and numbering, assigns every venue and outlet.
2. **P2:** migrate; set `accounting_starts_on` per company; accountant takes the monthly
   CSV.
3. **P3:** create the Intuit app; connect each company to a QBO **sandbox**, map, verify;
   then production.
4. **P4:** choose the eTIMS provider, register each company's device, go live company by
   company.

## 10. For the accountant (settings, not code)

Per company: VAT registration and eTIMS obligation; `invoice_timing`; the services
company's `room_charge_mode`; VAT treatment of service charge; whether the 2% Tourism
Fund levy applies and how it should appear; QBO chart of accounts for the mapping.

## 11. Out of scope (v1)

Purchases and supplier bills (entered directly in QBO; consignor payouts are the only
exception), payroll, fixed assets and depreciation, stock valuation / COGS in QBO,
bank reconciliation (QBO does it), historical backfill before `accounting_starts_on`,
reading data back from QBO, M-Pesa STK push and card-terminal integration (payments are
recorded, not initiated).

## 12. Review corrections (2026-09-28)

A check of this design against the code on `master` (57056f1). Each item names what the
code actually does and what the phase that touches it must do. Items marked **decide**
need Patrik or the accountant; the rest are the default the plan follows.

| # | Finding in the code | Correction | Phase |
|---|---|---|---|
| 1 | `bill_items` and `booking_addons.price_amount` carry **no currency**; `admin/_ws_bill.php` renders every extra in `site_currency` (USD), while a stay may be in KES (`bookings.currency`). "One invoice per currency" has nothing to split on. | Add `currency` to `bill_items` and snapshot a currency on a request when it is priced (default `site_currency`, the value the bill shows today, so existing rows keep their meaning). The folio groups on it. | P2 |
| 2 | Bill lines stay mutable after an invoice would be issued: `bill_del` hard-deletes (`admin/booking.php:167`), a request's status can change, and `pos_void_sale()` deletes its room-charge line (`includes/pos.php:1153`). | Issuing a folio invoice **locks** the lines it contains (`document_id` on the line). A locked line is never deleted or re-priced; a later void / removal issues a credit note against that invoice. `bill_del` and the void path refuse / credit accordingly. | P2 |
| 3 | `bill_total()` counts a confirmed request with a NULL price as 0. | "Issue invoice" is refused while any line on the folio is unpriced (the Ezee "unmapped blocks" rule). | P2 |
| 4 | OTA / Ezee imports create `bookings` rows keyed on `block_id` with **no hold**, so they have no Bill tab to invoice from; the importer's "agent" is free text, and no channel table exists for an `ota_collect` / `hotel_collect` setting. | **decide:** v1 either (a) leaves OTA stays to the accountant in QBO, or (b) adds a channel list + a folio keyed on `booking_id`. Default until decided: (a) — OTA stays are listed, not invoiced. | P2 |
| 5 | POS outlets already charge `vat_pct` / `vat_inclusive`, snapshotted on the sale; the guest has paid that VAT. §4.1 says a non-VAT company's line is band D, VAT 0. | **decide** (default): when an outlet's company is **not** VAT-registered the outlet's VAT is forced to 0 on save and the till never adds it; the document always copies the sale's own VAT snapshot, never recomputes it. | P2 |
| 6 | A cross-currency room charge stores the sale in KES and the bill line in USD (`pos_sales.bill_amount` / `fx_rate`). §4.4 does not say which currency the inter-company entry uses. | The services company's document is in the **sale** currency; the folio disbursement line and the `acct_ic_entries` row are in the **bill** currency at the sale's frozen `fx_rate` — so both sides agree to the cent and nothing is re-converted. | P2 |
| 7 | An outlet's stock location copies the outlet's `venue_id` (NULL for shared outlets), so §3 would make `inv_locations.company_id` a second copy of `pos_outlets.company_id` that can drift. | **Built in P1:** `company_for_location()` resolves an **outlet** location through `company_for_outlet()`; `inv_locations.company_id` is used only for shared locations that are not outlets (Main stock, a venue-less person). | P1 ✔ |
| 8 | Moves between two places are `transfer`, `assign`, `return` and `replaced`. Handing a laptop from Main stock to a staff member of another company would raise an inter-company invoice. | **decide** (default): only `transfer` and `replaced` between companies raise an ic invoice; `assign` / `return` (staff equipment) never do — the item stays the company's asset. | P2 |
| 9 | `accounting_starts_on` does not say which date a folio is judged by. | A folio belongs to accounting when its **check-out** date is on/after the go-live date (it is invoiced at checkout by default); a POS sale by its Nairobi sale date. | P2 |
| 10 | "Closing a POS day" has no trigger — the Z-report is a view, nothing closes a day. | The scheduler posts the previous Nairobi day's Sales Receipts after 01:00 EAT. | P3 |
| 11 | Gapless numbering takes a row lock per sale, so a company's POS sales serialise on its counter. | Accepted: the lock is held only for the rest of the sale's transaction (milliseconds). Revisit only if tills queue. | P2 |

### P2b build notes (what shipped)
- POS sale documents inside the sale's transaction (paid at once into a matching account; voids credit + refund); same-company room charges invoiced on the folio only.
- `acct_ic_entries` ledger; on_behalf settles the seller's invoice with an inter-company receipt at folio issue; reinvoice issues an `ic_invoice` at sale time; settlements page.
- `inv_moves.transfer_ref`; one `ic_invoice` per cross-company transfer at the value snapshot + sender's VAT; no-value lines refused (correction #7c); `assign`/`return` never invoiced (correction #8 default).
- Partial credit notes via `credits_line_id`; deposit → damages; `invoice_timing = confirm` + credit on cancel.
- Fail closed: going live is refused while an open POS outlet has no company.
- Cross-sold items in a directly paid sale are invoiced by the company that owns them (one invoice per company); room charges stay with the selling outlet's company.

### P2a build notes (room folio — what shipped)
- Built: payments, allocation (cross-currency at the site FX rate), tax invoices per currency, full credit notes, refunds, security deposits, pro-forma, invoice print, documents list + CSV. Corrections **#1, #2, #3, #6 (for the disbursement line), #9** are implemented.
- #5 is implemented as "copy the sale's VAT snapshot"; forcing an outlet's VAT to 0 for a non-VAT company is still open (decide).
- `companies.prices_include_vat` (default on) says whether room/extras prices include VAT for a VAT-registered company.
- Payment method must fit the account kind (M-Pesa → till/paybill, card → merchant/bank, cash → cash/bank).
- (P2b then built the deferred items — see above — except OTA folios.)

### P1 build notes (what shipped beyond §3)
- `companies.code` — a short code (e.g. `ZUR`) used for the default numbering prefixes (`ZUR-INV-`, `ZUR-CN-`, `ZUR-PF-`, `ZUR-IC-`).
- `company_doc_sequences.last_issued_at` — set when a number is taken; the prefix and starting number are editable until then, and locked after (a starting number lets the accountant continue the spreadsheet's numbering).
- KRA PIN is validated (`A`/`P` + 9 digits + a letter) and unique across companies; a VAT-registered or eTIMS company must have one.
- A company that owns properties, outlets or stock locations — or has taken a number — is switched off, never deleted.
