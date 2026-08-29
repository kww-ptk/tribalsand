# Tribal Sand — Fixes + Promo-Section Plan

> **STATUS (2026-08-29):** §5 (sitemap links), §6 (per-page SEO images), and §7
> (Offers/Promo — full build) are **DONE** on the working tree and verified
> locally (see `tests/offers_logic.php`, 15/15). §7's `add_offers.sql` migration
> is applied to the **Neon dev DB only** — it must still be applied to **prod
> RDS** (+ real offers created in admin). Items **§1–§4 remain TODO** (to be done
> in a separate chat).
>
> Investigation + implementation plan. Every root cause below was
> confirmed against source (file:line) and, where possible, reproduced on a local
> `php -S` boot on 2026-08-29.
>
> Suggested execution order is at the bottom (§9).
>
> **DECISIONS LOCKED (2026-08-29):**
> - §2 Menu popup: **Click-outside + Esc + X button + auto-close siblings.** No hover-close.
> - §4 Tour View-live: **Anchor on Experiences page** — `/activities#tour-<slug>` (no new `tour.php`).
> - §5 Sitemap HTML: **Keep it, fix its links** (Option B — rewrite the 18 absolute links to relative clean URLs).
> - §7 Offers: **Homepage promo strip only, site-wide** (no per-property scope, no `/offers` page for v1).

---

## 1. Front desk — empty state ("This week") should be centered + consistent

**Where:** `admin/frontdesk.php`

**Problem (from screenshot):** On **This week**, when there are no arrivals it shows a
thin card with a bare, left-aligned line: *"No arrivals in the next 7 days."*
Today/Tomorrow instead show a KPI row + a 3-column kanban. The week empty state
looks unfinished and does not match the other tabs.

**Root cause (confirmed):**
- Week empty: `admin/frontdesk.php:160`
  ```php
  <div class="card"><div class="card__body"><p class="fd-empty" style="margin:0">No arrivals in the next 7 days.</p></div></div>
  ```
- Per-column empties (Today/Tomorrow): `admin/frontdesk.php:189` — small `.fd-empty` left-aligned text.
- `.fd-empty` style: `admin/frontdesk.php:107` — `color:var(--muted);font-size:13px;margin:2px 0` (no centering).

**Fix plan:**
1. Add a reusable centered empty-state block (new CSS class, e.g. `.fd-empty-state`):
   an icon (use `admin_icon('calendar')` or similar from the existing `admin_icon()`
   set — confirm the key exists), a bold heading, and a muted subline. Center with
   `text-align:center; padding:48px 20px; max-width:420px; margin:24px auto`.
2. Replace the week empty card (line 160) with that block:
   heading *"No arrivals this week"*, subline *"Nothing scheduled in the next 7 days."*
3. For consistency the user explicitly asked for, keep the Today/Tomorrow **per-column**
   empties as-is (they belong inside each kanban column), **but** consider: when *all
   three* columns for a day are empty, the page still looks bare — optional: show the
   same centered `.fd-empty-state` above/instead of the kanban when
   `arriving+inhouse+departing === 0`. (Recommend doing this too — it makes all three
   tabs feel like one system.)

**Files:** `admin/frontdesk.php` only (markup + `<style>` block near line 107).

**Test:**
- Load `/admin/frontdesk?when=week` with no upcoming arrivals → centered block.
- `?when=today` / `?when=tomorrow` with empty data → consistent look.
- Owner + a scoped manager (single venue) both render.

**Risk:** none (presentational). No DB/logic change.

---

## 2. Restaurant menus — edit popovers stack, can't be closed

**Where:** `admin/menu-edit.php`

**Problem (from screenshot):** Clicking the pencil (Edit) on several items opens
multiple edit panels that **stack on top of each other**, with **no way to close**
them. Expected: opening one closes the previous; clicking/moving outside closes it;
and each panel has an explicit **X** button.

**Root cause (confirmed):** Each item's edit UI is a **native `<details class="menu-inline">`**
popover:
- `admin/menu-edit.php:369` — `<details class="menu-inline"><summary …>edit</summary>`
- Panel: `.menu-inline-form` (`admin/menu-edit.php:468`), positioned absolutely to the
  right when `[open]` (`admin/menu-edit.php:470-471`).

Native `<details>` don't auto-close siblings and have no outside-click behavior, so
every one you open stays open → the stack. There is currently **no JS** managing them.

**Fix plan (small, self-contained JS + one CSS/markup tweak):**
1. **Auto-close siblings:** add a script at the bottom of the page that listens for the
   `toggle` event on every `details.menu-inline`; when one opens, close all others
   (`el.open = false`). (Note: `toggle` doesn't bubble — attach per-element or use a
   capture listener.)
2. **Close on outside click:** `document.addEventListener('mousedown', …)` → if the
   click target is not inside an open `details.menu-inline`, close it. (Prefer
   `mousedown` over `click` so it fires before form focus.)
3. **Escape to close.**
4. **X button:** add a small close control inside `.menu-inline-form` (top-right) that
   sets the parent `<details>.open = false`. Reuse `admin_icon('x',14)` and the
   existing `.btn-icon` style so it stays house-design (no native chrome).
5. **Hover-out close: DECIDED — NOT doing it.** User chose click-outside + Esc + X +
   auto-close siblings (steps 1–4). Do not bind `mouseleave`.

**Also apply the same treatment** to the "Add item" / "Edit category" `<details>` at the
bottom of each category (`admin/menu-edit.php:404`, `:421`) so behavior is uniform — but
confirm they don't use `.menu-inline` (they don't today) and either add the class or a
shared selector.

**Files:** `admin/menu-edit.php` (add `<script>` + X markup + minor CSS). Consider
extracting the JS to `admin/assets/` if it grows, but inline is fine for v1.

**Test:**
- Open item A's edit, then item B's → A closes automatically.
- Click on empty page area → open panel closes.
- Press Esc → closes. Click X → closes.
- Existing save/delete forms inside the panel still submit (don't swallow their events).

**Risk:** low. Keep the native `<details>` fallback working if JS fails (progressive
enhancement — they already work without JS, just stacked).

---

## 3. Room "View live" → 404 ("Lost somewhere on the coast")

**Where:** `admin/rooms.php:122`

**Problem (from screenshot):** From Admin → Rooms, the external-link ("View live")
icon opens `/zuri-bahari` and lands on the 404 page, even though the room is **LIVE**.
Same for every room.

**Root cause (confirmed):**
```php
// admin/rooms.php:122
<a href="/<?= e($room['slug']) ?>" … target="_blank">…</a>
```
It links to `/<room_slug>`. But **rooms have no per-room page** — they are rendered as
cards **inside the property page** via `includes/rooms-and-rates.php`
(included by `zuri.php`, `maya-kobe.php`, `my-amani.php`, `enkare-bofa.php`,
`sandbox.php`, `maya_ilai.php`). Room slugs like `zuri-bahari`, `zuri-maji`,
`maya-kobe-prestige`, `maya-ilai-villa` have **no matching `.php` file** → `.htaccess`
`ErrorDocument 404 /404.php` serves the "Lost somewhere on the coast" page.
(It only *appears* to work for `enkare-bofa`, `sandbox`, `tribal-dunes` — because those
room slugs happen to equal a venue-slug page filename.)

**Key fact:** venue slugs **equal** their page filenames — `zuri`→`zuri.php`,
`maya-kobe`→`maya-kobe.php`, `my-amani`→`my-amani.php`, `enkare-bofa`→`enkare-bofa.php`,
`sandbox`→`sandbox.php`, `maya_ilai`→`maya_ilai.php`. So the correct live URL for a room
is its **venue's** property page.

**Fix plan:**
1. In `admin/rooms.php`, the list query already joins `venues` for `v.name` (see
   `admin/rooms.php:39` search). **Add `v.slug AS venue_slug`** to the SELECT.
2. Change the link to the venue page, anchored to the rooms section:
   ```php
   <a href="/<?= e($room['venue_slug']) ?>#rooms" … target="_blank">…</a>
   ```
   `includes/rooms-and-rates.php:42` already has `<section class="rr" id="rooms">`, so
   `#rooms` scrolls to the room list. (Optional nicety: give each room card a real
   anchor `id="room-<slug>"` in `rooms-and-rates.php` and link `#room-<slug>` so it
   scrolls to the exact suite. Cards already carry `data-room-slug` at line 60/90 — just
   add matching `id`.)
3. Do the **same fix** in `admin/room-edit.php` — it has the identical broken pattern in
   **three** places: `:267` ("View on site"), `:308` ("live URL /<slug>" hint), `:651`
   (the Google-preview `tribalsand.com/<slug>`). Pull `venue_slug` into that page's room
   fetch and correct all three.
4. Handle the **whole-villa / entire-place** rooms (e.g. `tribal-dunes`, `my-amani`
   whole villa): if a room's slug already equals a real page (venue page), the venue-slug
   link still resolves correctly, so no special-case needed — but verify `tribal-dunes`
   is modeled as its own venue with slug `tribal-dunes`.

**Files:** `admin/rooms.php`, `admin/room-edit.php`, (optional) `includes/rooms-and-rates.php`.

**Test:** For every room in the list, the "View live" link resolves to a 200 property
page showing that room's card — not the 404. Spot-check `zuri-bahari`, `zuri-maji`,
`maya-kobe-prestige`, `maya-ilai-villa`, `superior-suite`, `tribal-dunes`.

**Risk:** low. Pure URL correction. Confirm `venue_slug` is non-null for all rooms
(rooms with no venue would produce `/#rooms` — add a guard/fallback to `#`).

---

## 4. Tour "View live" → 404

**Where:** `admin/tours.php:132`

**Problem:** Same as rooms — the external-link icon 404s.

**Root cause (confirmed):**
```php
// admin/tours.php:132
<a href="/tour.php?slug=<?= e($tour['slug']) ?>" … target="_blank">…</a>
```
**`tour.php` does not exist** (`ls tour.php` → none). DB tours are actually rendered on
**`activities.php`** (`activities.php:28-29` selects `FROM tours`). So the live link
points at a non-existent script → 404.

Secondary inconsistency: `includes/cross-sell-tours.php:34` links tours to
`/excursions.php#<slug>`, but `excursions.php` does **not** render DB tours (only static
hero/adventure sections) and its cards have no `#<slug>` anchors. So that cross-sell link
is *also* effectively broken (lands on a page without the tour).

**Fix plan (DECIDED — anchor on Experiences page, no new `tour.php`):**
- Canonical tour page is **`activities.php`**.
  1. Add an anchor id to each tour card: `activities.php:114`
     `<article class="act-card" id="tour-<?= e($a['slug']) ?>">`.
  2. Admin "View live": `/activities#tour-<?= e($tour['slug']) ?>` (`admin/tours.php:132`
     and the same pattern in `admin/tour-edit.php` if present).
  3. Fix `includes/cross-sell-tours.php:34` to `/activities#tour-<slug>` too, so all tour
     links agree.
- **Alternative (NOT chosen):** a real `tour.php?slug=` detail renderer. Deferred — the
  `tours` table already has SEO fields (`db/schema.sql:54-68`) if this is revisited later.

**Files:** `admin/tours.php`, `activities.php`, `includes/cross-sell-tours.php`
(+ `admin/tour-edit.php` if it has a view-live link; +new `tour.php` only if going the
alternative route).

**Test:** "View live" on Tsavo East, Swahili Cooking, In-House Wellness → lands on
Experiences page scrolled to that tour. Cross-sell cards on room pages link correctly.

**Risk:** low for the recommended path.

---

## 5. `tribalsand_sitemap.html` — nav links jump to `tribalsand.com` / "pages not built"

**Where:** `tribalsand_sitemap.html` (static file at web root, publicly reachable —
`.htaccess` blocks `.md/.sql/.env/.log` but **not `.html`**).

**Problem (from user):** On this sitemap page, Home / Experiences / Contact go to
`tribalsand.com`, and it looks like those pages "aren't built."

**Root cause (confirmed):** The file has **18 hardcoded absolute** `https://tribalsand.com/*`
links, e.g.:
```
href="https://tribalsand.com"                Home
href="https://tribalsand.com/activities.php" Experiences
href="https://tribalsand.com/contact.php"    Contact
href="https://tribalsand.com/events.php"     Events
```
So on the current ECS host they navigate **off** to the old production domain. The target
pages **do exist** (`activities.php`, `contact.php`, `events.php` are all present) — the
issue is (a) absolute domain leaves the host, and (b) `.php` links now 301 to clean URLs.

**Important context to resolve first (decision for the user):** There are **three**
different "site map" artefacts — clarify which is the real one:
- `interactive-site-map.php` — the **Tribal Dunes interactive map** page, and the one the
  **site header links to** (`includes/header.php:533`, `:659`). This is the intended
  human-facing site map.
- `tribalsand_sitemap.html` — a **large (768 KB) static reference/mockup** (looks like an
  exported design). It is *not* linked from the site nav; it's reachable only if you type
  the URL. Also used by `interactive-site-map.php` as an image source (it reads
  `reference/tribalsand_sitemap.html`).
- `sitemap.php` → `/sitemap.xml` — the **machine sitemap** for search engines (fine, no
  change).

**Fix plan (DECIDED — Option B: keep it public, fix the links):**
- Rewrite all 18 `https://tribalsand.com/*` links to **relative, clean** URLs
  (`/`, `/activities`, `/contact`, `/events`, …) — drop the `.php` so they don't 301.
- Leave genuinely-external links intact where intended (e.g. `book.tribalsand.com`
  booking engine, `tribalkiteschool.com`, `tel:` — confirm which should stay absolute).
- Verify each internal target resolves 200 after rewrite.
- (Optional hygiene, not required by this decision: audit `ls *.html` in web root for
  other strays; leave them unless clearly broken.)

**Files:** `tribalsand_sitemap.html` and/or `.htaccess`; audit `ls *.html` in web root
for other strays.

**Test:** Confirm the header's "Interactive Site Map" link still works; confirm the stray
HTML is either not publicly reachable (A) or fully relative + all targets 200 (B).

**Risk:** low. Confirm nothing critical links to `/tribalsand_sitemap.html` (grep says
only `interactive-site-map.php`, `sitemap.php`, `tribal-dunes.php`, `includes/header.php`
reference the *string* — verify each usage before moving/blocking the file).

---

## 6. SEO — verify meta title / description / social image site-wide

**Where:** `includes/head.php` (central) + per-page `$page_*` variables.

**Assessment (confirmed):** The **infrastructure is already strong**. `includes/head.php`
emits, on every page: `<title>`, `meta description`, canonical, robots (noindex opt-in),
full **Open Graph** (`og:title/description/url/image` + 1200×630 dimensions), **Twitter
`summary_large_image`**, `hreflang`, JSON-LD slot (`$page_schema`), and sensible
**defaults** for every field (`head.php:18-23`) including a default share image
(`asset_url('images/Maya-Kobe-1-hero.webp')`). Canonical `.php`→clean normalization is
handled (`head.php:35`). This is well above baseline.

**So "implement SEO perfectly" = a per-page audit, not a rebuild.** Gaps found:
1. **Pages relying on generic defaults** — e.g. `excursions.php` sets **no** `$page_desc`
   (falls back to the generic homepage description) and no per-page `$page_image`. Sweep
   all root pages for missing `$page_desc` / `$page_title` / `$page_image` and give each a
   unique, keyword-appropriate value.
2. **Per-page social share images** — many pages inherit the single default Maya-Kobe
   hero. Set `$page_image` per property/experience page to its own hero for better link
   unfurls (Zuri page → Zuri hero, etc.).
3. **Room / tour SEO fields already exist** in the DB (`rooms.seo_title/seo_description`,
   `tours.seo_title/seo_description`) — if per-room/per-tour pages get built (§3/§4
   alternative), wire those into `$page_title/$page_desc`.
4. **Structured data coverage** — verify `$page_schema` (`includes/schema.php`) is set on
   key templates (Hotel/LodgingBusiness on property pages, BreadcrumbList, Organization on
   home). Add where missing.
5. **`og:image` absolute URL** — confirm `asset_url()` yields an absolute `https://…`
   (crawlers reject relative og:image). It should via `ASSET_URL`/CloudFront — verify on
   prod.
6. **Titles length** — audit for <60 char titles / <155 char descriptions (there was a
   prior pass per memory `seo-audit-state`; re-check the pages touched since).

**Deliverable of this item:** a per-page table (path → title, desc, image, schema) with
the fixes, applied page-by-page. No head.php changes needed beyond maybe adding
`article:published_time` for journal pages.

**Files:** individual root `.php` pages (set `$page_*`), possibly `includes/schema.php`.

**Test:** Facebook Sharing Debugger / Twitter Card validator against a few prod URLs;
`curl` each page and diff the `<title>`/`og:*` for uniqueness.

**Risk:** none structural — content-only edits.

---

## 7. Promo / Offers section (port from Claris) — PLAN ONLY, do not build yet

**Reference source:** `D:/clarisafricanexperience` (sibling project on D: drive).

**What Claris has (confirmed by reading the files):** a complete **Offers/Specials**
feature:
- **DB-backed offers** with categories `rental | safari | sale | special`
  (`fetch_offers()`, grouped in `offers.php` and `index.php`).
- **Homepage promo strip** (`index.php:55-96`): pulls published offers, and when there
  are **3+** it renders an **auto-scrolling marquee**, otherwise a static centered row.
- **Dedicated `/offers` listing page** (`offers.php`) with hero + grouped sections +
  empty-state.
- **"Request this offer" enquiry modal** (`includes/offer-modal.php`) — a branded popup
  with name/email/phone/message, honeypot, Turnstile, wired via `script.js`
  (`#offerEnquiryForm`); offer name injected per trigger via
  `data-offer-request` / `data-offer-title`.
- Almost certainly an **admin editor** for offers + an `offers` migration (to confirm when
  planning: check `clarisafricanexperience/admin` and `clarisafricanexperience/db`).

**DECIDED SCOPE (v1):** **Homepage promo strip only, site-wide.** No per-property scoping,
no dedicated `/offers` listing page. (Steps 1, 6 below simplify accordingly; steps 5 and
per-property `venue_id` are deferred to a possible v1.1.)

**Proposed Tribal Sand port (design):**
1. **DB:** new migration `db/migrations/add_offers.sql` — `offers` table:
   `id, slug, title, category, eyebrow, body, image_key, price_text, cta_label,
   valid_from, valid_to, sort_order, is_published, created_at, updated_at`.
   (Drop `venue_id` for v1 — site-wide only; add later if per-property is wanted.)
   Follow the project's pre-migration-safe pattern (`offers_supported()` guard in a new
   `includes/offers.php`), like `menus`/`reservations`.
2. **Helpers:** `includes/offers.php` — `offers_supported()`, `fetch_offers()`,
   `fetch_published_offers()`, date-window filter (respect Africa/Nairobi "today" per
   CLAUDE.md), currency rendering via existing `money_html()`.
3. **Public homepage strip:** a new `includes/promo-offers.php` partial included on
   `index.php` — mirror Claris's "3+ → marquee, else static row" logic, but in Tribal
   Sand's design tokens (`css/main.css`). Reuse the brand card styles.
4. **Offer enquiry:** reuse the existing enquiry pipeline. Either adapt
   `includes/form-enquiry.php` / `api/submit-contact.php` with an `offer` subject, or port
   Claris's dedicated offer-modal → `api/submit-offer.php`. **Turnstile fail-closed** +
   IP rate-limit + CSRF, per project conventions.
5. **`/offers` page — DEFERRED (v1.1).** Homepage strip is the v1 deliverable.
6. **Admin:** `admin/offers.php` (list) + `admin/offer-edit.php` (editor), gated
   **`require_owner()`** (site-wide config, no per-property scoping in v1). Image upload
   via the same `storage_put()` + GD path used by venue galleries / nav thumbnails.
7. **Nav / entry points:** decide whether "Offers" gets a nav item (DB-driven mega menu →
   add a `nav_items` row) and/or a homepage anchor.
8. **SEO:** offers listing page gets `ItemList` JSON-LD (Claris already does this in
   `offers.php`).

**Explicitly deferred:** per user, **plan only — build in a separate chat.** Before
building, open a focused planning pass on the Claris `admin/` + `db/` offers files to copy
the exact schema + admin UX, then adapt to Tribal Sand's conventions (pre-migration-safe
guards, house design system, no native chrome, Nairobi timezone, prod RDS + S3/CloudFront
for images).

**Resolved:** Site-wide (no per-property), homepage strip only for v1. **Still to confirm
in the build chat:** does an offer's CTA link to a booking/enquiry flow, or only open the
"Request this offer" modal? (Default assumption: the modal, like Claris.)

---

## 8. Cross-cutting notes

- **Local vs prod routing:** locally (`php -S router.php`) unknown paths currently render
  the **home page** (soft-200), while prod (`.htaccess ErrorDocument 404 /404.php`) serves
  the real 404 the user sees. The §3/§4 fixes are correct regardless; just don't rely on
  the local server to reproduce the 404 status — verify the *target* URLs resolve instead.
- **Deploy:** prod is **AWS ECS + RDS + S3/CloudFront** (not the local Neon/Postgres). Any
  new migration (§7 offers) and seed must be applied to **prod RDS separately** (per the
  mega-menu one-off ECS run-task precedent). Local `.env` never touches prod data.
- **Images:** any new upload feature (§7) needs S3/CloudFront on prod, same dependency as
  venue galleries + nav thumbnails.

---

## 9. Suggested execution order (separate chat)

| # | Item | Size | Risk | Notes |
|---|------|------|------|-------|
| 1 | §3 Room View-live URL fix | S | low | pure URL fix, high user impact |
| 2 | §4 Tour View-live URL fix | S | low | + fix cross-sell link |
| 3 | §2 Menu popover close/X/auto-close | S–M | low | JS enhancement, confirm hover-close decision |
| 4 | §1 Front-desk empty state | S | none | presentational |
| 5 | §5 Sitemap HTML links | S | low | decide retire vs relative-links first |
| 6 | §6 SEO per-page audit | M | none | content sweep, do as its own pass |
| 7 | §7 Promo/Offers port | L | med | **separate build chat**, needs migration + admin + prod deploy |

**Decisions — all resolved (2026-08-29):** (a) menu = click-outside+Esc+X, no hover;
(b) tour = `/activities#tour-<slug>`; (c) sitemap = keep + relativize links;
(d) offers = homepage strip, site-wide, owner-only. Only remaining sub-question: offer
CTA target (modal vs booking) — settle at the start of the §7 build chat.
