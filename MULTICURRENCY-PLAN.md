# Tribal Sand — Multi-Currency (Display) System: Implementation Plan

> Self-contained spec. A fresh session can execute this without prior context.
> Approved 2026-08-19.

## Progress
- **Phase 1 — DONE.** Helpers in `includes/db.php` (`TS_CURRENCIES`, `fx_rates()`, `current_currency()`/`resolve_currency()`, `convert_price()`, `format_money()`, `money_html()`), seed rates, `tests/currency_logic.php` (33 pass).
- **Phase 3 — DONE.** Switcher in `includes/header.php` (desktop nav + mobile drawer chips), `js/currency.js` (instant re-render + cookie), globals injected in `includes/head.php`. `?cur=` also switches server-side.
- **Phase 4 — DONE (core).** All primary guest price surfaces convert + re-render live on switch:
  - `search.php` results + `includes/rooms-and-rates.php` suite cards (server-rendered via `money_html()`).
  - `js/booking-widget.js` availability sidebar (live per-night + total) — emits `.ts-price` spans via `window.tsPriceSpan`; `renderAll()` reformats on switch. Booking still holds in the room's real `price_currency` (data-price untouched).
  - `includes/booking-modal.php` — reuses the same `#bkTotal*` elements, so it's covered by the widget change (verified: $3,101 ↔ KES 400,000 ↔ €2,853). Existing "final price confirmed by email" note communicates indicative pricing.
  - **Optional remainder:** `activities.php` legacy free-text prices (`format_price()`) — low priority.
- **Phase 2 (rate automation) & Phase 5 (polish) — NOT STARTED.** Seed rates are live in the meantime.

---

## 0. Ground rules (READ FIRST)

- **Stack:** PHP 8.2, vanilla JS/CSS. **No framework, no build system, no npm, no React.** PostgreSQL via PDO (`db_query()`), settings KV via `setting()` / `set_setting()`.
- **This is DISPLAY-ONLY, not transactional.** Bookings are 24h email-confirmed holds — no on-site payment. So we **never settle FX**. Converted prices are *indicative*; every hold/enquiry is stored and confirmed in the property's **real** currency (`rooms.price_currency`, today USD or KES). This removes all payment/reconciliation/legal complexity — do not add it.
- **No native UI rule (hard constraint):** never use native `<select>`, unicode arrow/tick glyphs (`▾ ▸ ✓ ✔`) as UI. The currency switcher is a styled dropdown + inline Lucide SVG chevron (mirror the hero stepper / booking-widget pattern). See `memory/no-native-ui.md`.
- **Never break a price.** If a rate is missing or conversion fails, fall back to rendering the original stored currency unconverted — never show `NaN`, `0`, or a blank.
- **SEO / structured data stays stable.** `includes/schema.php` price fields keep the room's **real** currency — do NOT localize schema.org markup (currency is per-user; structured data must be deterministic).
- **Cache-busting:** CSS/JS in `includes/head.php` already use `?v=filemtime()`.
- **Local dev:** `D:\php84\php.exe -S localhost:8765 -t D:\TribalIsland D:\TribalIsland\router.php` (Neon cloud DB via `.env`). Do NOT submit live enquiries during testing.

### Decisions (locked)
| Decision | Value |
|---|---|
| Supported currencies | **KES, USD, EUR, GBP** |
| Rate source | Daily auto-fetch `open.er-api.com` (free, no key), cached in DB, **admin manual override** per rate, **last-good fallback** on failure |
| Rate base | **USD** (open.er-api.com's free base) |
| Default for unknown visitor | **USD** (geo-detect is optional, Phase 5) |
| Conversion semantics | Indicative display only; holds settle in `rooms.price_currency` |

---

## 1. Architecture (hybrid: server first-paint + instant client re-render)

1. **Server** resolves the active currency per request (`current_currency()`) and renders every price already converted → **no flash of the wrong currency**, SEO-clean.
2. Every rendered price also carries `data-base-amount` + `data-base-cur` attributes.
3. A tiny **`js/currency.js`** holds a copy of the rate table (injected as `window.TS_FX`) and reformats all `[data-base-amount]` **instantly on switch** (Airbnb-style, no reload) + writes the `ts_currency` cookie.
4. Switching without JS still works: the switcher is a set of links (`?cur=EUR`) that set the cookie server-side and reload — progressive enhancement.

Data flow:
```
open.er-api.com ──(daily cron)──► settings['fx_rates'] (JSON, USD-based)
                                        │
              admin manual override ────┤ (locked rates survive auto-sync)
                                        ▼
   current_currency()  ─┐        convert_price($amt,$from,$to)
   (param→cookie→USD)   ├──────► format_money($amt,$cur)  ──► server HTML (converted + data-attrs)
                        │                                       │
                        └──► window.TS_FX + window.TS_CUR ──► js/currency.js (instant re-render on switch)
```

---

## 2. Data model

**One settings row, JSON value** (use existing `setting()`/`set_setting()` — no migration needed):

`setting_key = 'fx_rates'`:
```json
{
  "base": "USD",
  "fetched_at": "2026-08-19T09:00:00+03:00",
  "rates":  { "USD": 1, "KES": 129.5, "EUR": 0.921, "GBP": 0.788 },
  "locked": { "KES": true }
}
```
- `rates` — units of currency per 1 USD.
- `locked` — currencies whose rate is admin-pinned; the auto-sync **must not** overwrite a locked rate.
- Seed once with sane starting values so the site works before the first cron run.

**Config constant** (in `includes/db.php`), single source of truth:
```php
const TS_CURRENCIES = [
    'USD' => ['symbol' => '$',    'name' => 'US Dollar',        'round' => 1],
    'EUR' => ['symbol' => '€',    'name' => 'Euro',             'round' => 1],
    'GBP' => ['symbol' => '£',    'name' => 'British Pound',    'round' => 1],
    'KES' => ['symbol' => 'KES ', 'name' => 'Kenyan Shilling',  'round' => 100],
];
const TS_CURRENCY_DEFAULT = 'USD';
```
`round` = nearest N to round the *converted* figure (KES to nearest 100 avoids ugly `KES 401,337`).

---

## 3. PHP helpers (`includes/db.php`)

```php
fx_rates(): array
// Returns the decoded settings['fx_rates'], or a hardcoded seed if unset/unparseable. Never throws.

current_currency(): string
// Resolution order: valid ?cur= (also sets the ts_currency cookie, 1yr) → ts_currency cookie → TS_CURRENCY_DEFAULT.
// Always validated against TS_CURRENCIES; unknown values fall back to default.

convert_price(float $amount, string $from, ?string $to = null): array
// $to defaults to current_currency(). Returns ['amount'=>float,'currency'=>string,'converted'=>bool].
// If a rate is missing → returns the ORIGINAL amount+currency with converted=false (never breaks).
// Math: usd = amount / rates[$from]; out = usd * rates[$to]; round to TS_CURRENCIES[$to]['round'].

format_money(float $amount, string $currency): string
// Symbol + thousands grouping, no decimals for whole-currency display. e.g. "$3,100", "KES 400,000".

money_html(float $amount, string $from): string
// Convenience: converts to current currency AND emits data-base-amount/data-base-cur for js/currency.js:
//   <span class="ts-price" data-base-amount="400000" data-base-cur="KES">$3,100</span>
```
All read-safe: any DB/parse failure degrades to the original currency, never a fatal.

---

## 4. Client re-render (`js/currency.js`)

- Reads `window.TS_FX` (`{base, rates}`), `window.TS_CUR`, and a JS mirror of `TS_CURRENCIES` (symbols + rounding) injected inline in `includes/head.php` or `footer.php`.
- `tsSetCurrency(cur)`: set `window.TS_CUR`, write `ts_currency` cookie (1yr), reformat **every** `.ts-price[data-base-amount]`, update the switcher label + active state. No reload.
- Same convert/round/format logic as PHP (keep them in lock-step — if you change rounding in one, change both).
- Guard: if `TS_FX` missing, do nothing (server-rendered values stand).

---

## 5. Currency switcher UI (`includes/header.php`)

- Place next to the existing **"English"** language toggle in the top nav.
- Styled dropdown (button shows active symbol+code, e.g. `$ USD` + inline Lucide chevron-down SVG). Menu lists the four currencies; each is an `<a href="?cur=XXX">` (no-JS fallback) that `js/currency.js` intercepts for instant switch.
- Active item highlighted. Mobile: fits in the mobile nav sheet.
- **No native `<select>`.**

---

## 6. FX sync + admin override

### 6a. Cron endpoint — `api/fx-sync.php`
- Mirror `api/sync-ical.php` auth: `Authorization: Bearer <FX_SYNC_SECRET>` (add env var). Legacy `?secret=` optional for external crons but header preferred (never log the secret).
- Fetch `https://open.er-api.com/v6/latest/USD`. On `result:"success"`, take `rates` for our four currencies.
- **Preserve locked rates:** merge — for any currency in `locked`, keep the stored (admin) value; update the rest.
- Write back to `settings['fx_rates']` with fresh `fetched_at`. On fetch failure: log, keep last-good, return 200 with a `stale` note (never wipe rates).
- Schedule: external daily cron (same mechanism as iCal sync) hitting the endpoint.

### 6b. Admin UI — section in `admin/settings.php` (or new `admin/currency.php`)
- `require_owner()` (pricing/settings tier — see `includes/auth.php`).
- Show each currency: current rate, `fetched_at`, a **manual override** field + a **lock** checkbox (locked = auto-sync won't touch it).
- **"Sync now"** button → calls the sync endpoint (Bearer header, like the Gantt "Sync Now").
- Save via the existing instant-save admin pattern.

---

## 7. Wire the guest-facing price surfaces

Replace ad-hoc money formatting / hardcoded `KES …` strings with `money_html()` (or `format_money()` where no live re-render is needed, e.g. emails).

| Surface | File | Notes |
|---|---|---|
| Search results | `search.php` | Replace the `$money` closure. `data-from` for sorting stays the **base** number (sort must not depend on display currency). |
| Property booking widget | `includes/booking-widget.php` | Per-night + stay total. |
| Rooms & rates | `includes/rooms-and-rates.php` | Rate tables. |
| Booking modal | `includes/booking-modal.php` | Show converted price + note "final price confirmed in <real cur>". Submit/hold uses the **real** currency (unchanged). |
| Trip builder | `trip-builder.php` | Any price displays. |
| Homepage cards | `index.php` | "From KES …" style figures. |
| Activities | `activities.php` | Tour prices. |
| **Emails** | `includes/mail.php` | Use `format_money()` in the property's **real** currency — emails are the source of truth; do NOT localize to the guest's browser currency. |
| **Admin** | `admin/*` | **Unchanged** — internal, always real stored currency. |

---

## 8. Edge cases & guardrails

- **Mixed source currencies:** rooms may be priced in USD or KES; `convert_price()` always converts from each row's own `price_currency`. Never assume a single base for stored prices.
- **Missing rate / API down:** `convert_price()` returns original currency (`converted=false`); UI shows the true price rather than a broken one.
- **Rounding:** convert then round to `TS_CURRENCIES[cur]['round']`; keep PHP and JS identical.
- **Indicative labelling:** show a subtle "≈" or "approx." affordance next to converted (non-base) prices, and the "final price confirmed in <real cur>" note in the booking modal.
- **Sorting/filtering** (search page): operate on base numbers, never on formatted/converted strings.
- **Booking integrity:** the hold/enquiry payload and admin views always store & show `rooms.price_currency` — conversion is presentation only.
- **Schema/SEO:** `includes/schema.php` price stays in the real currency.

---

## 9. Phasing (each phase independently shippable)

1. **Foundation (dark, no visible change):** `TS_CURRENCIES` const + `fx_rates()` + `current_currency()` + `convert_price()` + `format_money()` + `money_html()` in `includes/db.php`; seed `settings['fx_rates']`. **Test:** `tests/currency_logic.php` (convert, rounding, missing-rate fallback, cookie/param resolution) — follow the `tests/team_logic.php` pattern.
2. **Rates infra:** `api/fx-sync.php` (Bearer) + admin override/lock UI + "Sync now" + `FX_SYNC_SECRET` env. Verify locked rates survive a sync.
3. **Switcher UI:** header dropdown + `js/currency.js` + inject `window.TS_FX`/`window.TS_CUR`/symbols. Instant switch + cookie persistence; no-JS reload fallback.
4. **Wire surfaces:** convert §7's guest-facing files to `money_html()`; add `.ts-price` data attributes. QA each page in all 4 currencies (light + dark, mobile).
5. **Polish (optional):** geo-detect default (Cloudflare `CF-IPCountry` header if present, else Accept-Language), "≈/approx." affordance, analytics on currency choice, remember-choice UX.

---

## 10. Environment variable to add
```
FX_SYNC_SECRET=      # Random secret for the api/fx-sync.php endpoint (Bearer auth), like ICAL_SYNC_SECRET
```

## 11. Verify
- `php -l` clean on all touched files; `php tests/currency_logic.php` green.
- Switch currency on homepage/search/property page → all prices re-render instantly, cookie persists across navigation, no console errors.
- Kill the network to the FX API → site still renders last-good rates; sync endpoint reports stale without wiping.
- Booking modal still holds/enquires in the real currency; email shows the real currency.
