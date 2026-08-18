# Fix Plan — Legal-integrity + UX tasks (Aug 2026)

Status: **A, B, D, C implemented.** (A+B+D: commit `80f0e79`. C: this pass — see below.)
Remaining: only the at-cutover re-crawl of C, plus the §5 backlog.
Owner: dev. Audited against current code before writing.

---

## 0. Sequencing (what we do first, and why)

| Order | Task | Why this order | Risk | Blocked on |
|-------|------|----------------|------|------------|
| 1 | **B — Consent-mutation bug** (freeze the signed record + void signature on edit) | Highest priority: this is an *active legal-integrity defect* — the "signed" evidence document silently changes when a guest field is edited. Directly what the lawyer + you flagged. | Medium (DB migration + write-path changes) | — |
| 2 | **A — Audit-trail verification & hardening** | Mostly verification; the enumerated data is already captured. Fold the small hardening into the same migration/PR as B (same subsystem). | Low | — |
| 3 | **D — Homepage search step-2 inline** | Self-contained front-end UX polish, no data risk, quick win, easy to preview-test. | Low | — |
| 4 | **C — Redirect / SEO parity** | Needs the old-site URL inventory from you (Search Console export) before it can be *completed*. Start the audit now, finish at cutover. | Low | **Old URL list (you)** |
| 5 | **Backlog** (earlier list) | After the above. See §5. | Varies | Varies |

Tasks B and A ship together (one migration, one PR). D is independent. C is a parallel audit that waits on your data.

---

## A. Lawyer request — "strengthen the evidentiary trail": **is it done?**

**Short answer: yes, the core is already built and satisfies the enumerated requirements.** Two optional hardening items remain for the exact "tamper-evident" / "certification" language.

### What already exists (verified in code)
Migration `db/migrations/add_checkin_reference.sql` + `includes/checkin.php` + `record.php`:

| Lawyer's requirement | Where it lives | Status |
|---|---|---|
| Unique transaction/reference ID | `waiver_reference` (`TSR-<hold>-<guest>-<rand>`), `checkin_make_reference()` / `checkin_ensure_reference()` | ✅ |
| Personal link issued | `waiver_signed_link` + `checkin_personal_link()` | ✅ |
| Exact terms version displayed | `waiver_version` (sha1 of terms) + `waiver_terms_snapshot` (full text frozen) | ✅ |
| Date & time of each step | `checkin_signing_audit.created_at` (TIMESTAMPTZ) | ✅ |
| IP, device, method per step | `ip`, `user_agent`, `method` columns | ✅ |
| Append-only server-side log | `checkin_signing_audit` table; app only ever INSERTs; steps `signed`, `record_viewed` | ✅ |

`checkin_log_signing_step()` writes one immutable row per material step; all reads are migration-safe via `*_supported()` guards.

### Gaps to close (recommended, not blocking)
1. **Confirm the migration is applied on production.** Run/verify `add_checkin_reference.sql` on the prod DB (RDS/Neon). Until then `checkin_reference_supported()` returns false and references mint lazily on first view only. → *Verification step, add to B's rollout.*
2. **True tamper-evidence (optional).** The table is append-only *by policy* (app never UPDATEs/DELETEs) but not *by construction*. To match "tamper-evident" precisely:
   - Add a hash-chain: each audit row stores `prev_hash` + `row_hash = sha256(prev_hash || canonical(row))`. Any later deletion/edit becomes detectable. ~30 lines in `checkin_log_signing_step()` + one column.
   - And/or revoke `UPDATE, DELETE` on `checkin_signing_audit` from the app DB role at the database level.
3. **Admin viewer/export (optional but useful).** There is currently no screen to *read* the trail. A read-only "Signing history" panel on the booking check-in tab (+ CSV/PDF export) turns the log into something you can actually hand to a certifier or produce in a dispute.

**Recommendation:** treat #1 as required (part of B rollout), #2 and #3 as a fast-follow "audit hardening" ticket. Ask the lawyer whether the append-only-by-policy log is sufficient or whether they specifically need the hash-chain for certification — that decides #2.

---

## B. Signed-consent mutation bug (the serious one)

### The bug (confirmed)
- `record.php:110-113` renders **live** `passport_name`, `passport_number`, `nationality` straight from the `checkin_guests` row.
- Signing (`api/checkin-save.php:166-174`) snapshots only the *waiver* fields (`waiver_signature`, `waiver_terms_snapshot`, `waiver_version`, signed name/at/ip/ua/method). **Identity is never snapshotted.**
- Three paths overwrite identity live, with no regard to whether the guest already signed:
  - `admin/booking.php:172-174` (`guest_fill` — the pencil-edit in your screenshot)
  - `api/checkin-save.php:122-126` (guest wizard)
  - `api/checkin-guest.php` (add adult/child — creation only)
- ⇒ Editing passport # after signing **mutates the signed document**. Exactly what you reproduced.
- Extra defect: `checkin_recompute_completion()` only flips `checkin_completed_at` `NULL→now()`, **never clears it**. So even after we void a signature, a booking already marked "Checked in ✓" would stay green over an unsigned guest.

### Desired behaviour (your words)
> After a guest signs and check-in is complete, editing a field should require a **new signature** and must **not** change the already-signed version.

### Fix — two complementary parts

**Part 1 — Freeze the record (snapshot identity at signing).**
- New migration `db/migrations/add_checkin_identity_snapshot.sql` (apply **after** `add_checkin_reference.sql`), idempotent:
  ```sql
  ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_name_snapshot        TEXT;
  ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_passport_snapshot    TEXT;
  ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_nationality_snapshot TEXT;
  ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_passport_expiry_snapshot TEXT;
  ```
- In the signing UPDATE (`api/checkin-save.php:166`), also write the four snapshot columns from the *current* identity values at sign time.
- `record.php` renders the **snapshot** columns, falling back to live values only when snapshot is NULL (legacy rows signed before this migration). Add a `checkin_identity_snapshot_supported()` guard mirroring the existing `*_supported()` helpers.
- Result: the document is self-contained and frozen — it can never change after signing, independent of later edits.

**Part 2 — Editing a material field after signing voids the signature (forces re-sign).**
- Define the **material identity set**: `passport_name`, `passport_number`, `nationality`, `passport_expiry`. (Arrival / transfer / dietary / requests are NOT part of the waiver and must NOT void it.)
- New helper in `includes/checkin.php`, e.g. `checkin_void_signature_on_identity_change(int $holdId, int $guestId, array $newValues): bool`:
  - Loads the stored row. **Only acts if the row is currently signed** (`checkin_guest_waiver_signed()`).
  - **Compares trimmed old vs new** for the material set; acts **only if at least one actually differs.** (Critical: the guest wizard re-posts unchanged passport fields on every step save — a blind void would nuke a guest's own signature when they save an unrelated step.)
  - On a real change: clear `waiver_signed_at, waiver_signed_name, waiver_signature, waiver_terms_snapshot, waiver_version, waiver_signed_ip, waiver_signed_user_agent, waiver_signed_method` (keep `waiver_reference` + the audit rows as history), then log an audit step `signature_voided` with detail naming which field(s) changed and who changed it (admin vs guest).
  - **Reopen completion:** if `holds.checkin_completed_at` was set, clear it (`SET checkin_completed_at = NULL`) so the badge returns to "Check-in X/N" and the party is correctly shown as awaiting the re-signature. Add this as a new branch (do NOT weaken `checkin_recompute_completion`'s NULL→now() guarantee; reopening is a separate, explicit action).
- Wire the helper into **all three edit paths** before their identity UPDATE:
  - `admin/booking.php` `guest_fill`
  - `api/checkin-save.php` passport UPDATE
  - (creation paths in `api/checkin-guest.php` can't hit an already-signed row, so no-op there — but call it for safety.)
- On re-sign, Part 1 re-snapshots the corrected identity, so the new record is consistent.

### Why both parts
Part 2 alone keeps live == signed (any edit un-signs), so the record would *look* correct — but Part 1 makes the evidence document genuinely immutable and self-contained, which is the stronger legal posture the lawyer asked for. Part 2 is what delivers your explicit "require a new signature" requirement and fixes the stale "Checked in ✓" badge.

### Open decisions for you
- **Passport *scan* replacement after signing** (`guest_upload`): void the signature too, or not? Default proposal: **no** (the waiver covers typed identity + terms; a scan swap logs an audit note but doesn't void). Confirm.
- **Who may edit a signed record at all?** Option to additionally require a confirm dialog ("This guest has signed — editing will void their signature and require a new one. Continue?") on the admin pencil. Recommended: yes, low effort (reuse `tsConfirm`).

### Test plan (preview + CLI)
- Extend `tests/team_logic.php`-style pure tests (or a new `tests/checkin_consent.php`) for the compare-before-void logic: unchanged re-post ⇒ no void; changed field ⇒ void; non-material change ⇒ no void.
- Preview walkthrough: sign as guest → view `/record.php` → note values → admin edits passport # → confirm (a) record still shows the *original* snapshot, (b) guest flips to "Incomplete", (c) booking badge reopens to X/N, (d) an audit `signature_voided` row exists → re-sign → record shows corrected data with a new signed timestamp, reference retained.

---

## C. Redirects / canonical — don't lose ranking at cutover

### The concern
The current live site (tribalsand.com, Namecheap brochure) has an indexed URL structure. When we repoint the domain to this app, **every old ranked URL must 301 to its new equivalent**, or we lose the ranking/link equity.

### What's already in place (verified)
- `.htaccess` already: clean-URL 301s (`/foo.php → /foo`), dynamic sitemap, and POST-safe rules.
- 13 legacy article pages already have one-line 301 stubs; 3 missing pages were ported; canonicals are clean.

### What's missing — a **completeness pass** (can't finish without your data)
1. **Get the old URL inventory** (pick one): Google Search Console → *Pages* export (indexed URLs), or the old site's `sitemap.xml`, or a Screaming Frog crawl of the current tribalsand.com. **← this is the blocker; please provide.**
2. **Diff** old URLs against the new site's routes.
3. For each old URL with **no** new equivalent, add a **specific** 301 to the closest relevant new page (never blanket-redirect to the homepage — Google treats mass redirect-to-home as soft-404 and drops the ranking). Add them as `.htaccess` `Redirect`/`RewriteRule` entries (and mirror in `router.php` for local).
4. Verify each old→new hop is a **single 301** (no chains, no loops).
5. Keep the **canonical** tags pointing at the new clean URLs (already the case via `site_url()`).
6. At DNS cutover, leave **MX + `book.` subdomain** records untouched (already in the cutover notes).

### Deliverable
A `redirects-map.csv` (old URL → new URL → status) + the `.htaccess` additions, checked against a re-crawl before flipping DNS.

### ✅ Implemented (this pass)
The blocker resolved itself: the old-site inventory was already in the repo as **`sitemap-tribalsand.xml`** (Namecheap brochure, lastmod 2025-03-19, 43 indexed URLs). Diffed against the new routes (`sitemap.php` core list + `includes/articles.php` manifest):

- **35 of 43 resolve natively (200)** — same clean URL is served by a `.php` page via `.htaccess` strip-`.php`. No redirect, no equity loss.
- **8 need a 301**, all single-hop to the closest live page (never home):
  - `/cultural-immersion-…` → `/exploring-the-local-markets-of-kilifi-…` (new `.php` stub — closest surviving cultural article).
  - `/the-kuruwitu-conservancy`, `/the-kitesurfing`, `/the-skydiving`, `/the-dolphin-safari`, `/the-deep-sea-fishing` → `/activities` (new `.php` stubs — retired orphaned old-design brochure pages; content preserved in git history). `tribal-dunes.php`'s "kite school" link repointed to `/activities`.
  - `/content/Watamu-Kenya-COMETA-2025.pdf` → `/watamu` (`.htaccess` RewriteRule + `router.php` mirror — non-`.php` path).
  - `/my-amani-voted-…` → `/my-amani` (pre-existing stub).
- **De-chained all 14 pre-existing stubs**: targets changed from `/x.php` to `/x` so each is a *single* 301 (previously `/old` → `/x.php` → `/x` was a 2-hop chain via the strip-`.php` rule).
- **Deliverable:** `docs/redirects-map.csv` (full 43-row diff). Verified locally via `router.php`: every old URL returns 200 or 301, every 301 is single-hop → 200, no chains/loops.

**Remaining (at cutover):** re-crawl the live domain after DNS flip to confirm no new indexed URLs appeared since the 2025-03-19 sitemap; leave MX + `book.` records untouched.

---

## D. Homepage search bar — make step 2 inline (not a popup)

### Current behaviour (verified)
- `index.php:317` `#heroSearch` = step 1 (dates + guests).
- On submit (`index.php:998`), it validates dates then calls `openLeadModal()` → shows `#savailModal` (`index.php:893`), a full-screen **overlay modal** with "Step 2 of 2" (name/email/phone) → posts to `/api/search-lead.php` → redirects to `/search`.

### Desired
Step 2 should feel like a **seamless continuation of the search bar**, not a popup overlay.

### Fix approach (localized to `index.php` — markup + CSS + ~2 JS functions)
- Replace the overlay with an **inline expanding panel** anchored to the hero search: a `.hero-search__step2` block that slides open **directly under the bar** (same white container, same width), containing the existing name/email/phone fields + Back/Check-availability actions.
- Reuse the existing `#savailForm` submit logic and `/api/search-lead.php` call verbatim — only the *presentation* changes (`openLeadModal`/`closeLeadModal` become expand/collapse of the inline panel instead of toggling the modal + `body` scroll-lock).
- Keep the lead-first flow (lead saved before results, so abandoned searches are still captured) and the graceful fallback (`window.location.href = resultsUrl(s)` if the panel is absent).
- Animate the expand (height/opacity) so it reads as one continuous bar. Mobile: the panel stacks under the bar full-width (the bar already wraps at ≤440px).
- Accessibility: move focus to the name field on expand; `Esc`/Back collapses; the panel is a labelled region rather than `role="dialog"`.

### Test (preview)
- `/` → pick dates → Search → step-2 fields expand inline (no overlay, no scroll-lock) → submit with name+email → redirects to `/search`. Check desktop + mobile widths and dark mode via `resize_window`.

---

## 5. Backlog (do AFTER A–D) — carried over from the earlier review

### 🔴 Blockers / security
1. **Rotate leaked Gmail credentials** — old `properties.php` app passwords (`generic.tribalsand@gmail.com`, `palaimanageacc@gmail.com`) are still in **git history** (commit `e386e97` and earlier). Code is fixed; you must revoke/rotate on the accounts. *(User action — I can't.)*
2. **Private check-in scans wiped on deploy** — no `R2_CHECKIN_BUCKET` / `CHECKIN_STORAGE_DIR` on prod; passport/waiver PII lands in temp and vanishes each deploy. Configure a private bucket/disk.
3. **Admin image uploads 404 on prod without R2** — uploads fall back to gitignored local disk; ~23 `rooms/*.jpg` gallery rows broken until re-uploaded through R2/S3.

### 🟡 Go-live cutover (AWS-GOLIVE-PLAN.md)
4. AWS stack migration (App Runner + RDS + S3 + SES + CloudFront/Route53); storage/mail drivers already support it; includes rewriting stored image URLs R2→CloudFront.
5. ✅ **DONE (already clean).** `render.yaml` already uses `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`; no `HCAPTCHA_*` anywhere in the repo. Nothing to reconcile.
6. Submit new sitemap to Search Console after deploy (ties into C).

### 🟢 Content / functionality
7. ✅ **DONE.** The `my-amani-*-single/twin` pages were already 301 stubs → `/my-amani` (de-chained in the C pass). `maya-kobe-main-house.php` + `maya-kobe-cottages.php` were old-design orphans with a defunct booking slug (blank widget) — retired to 301 stubs → `/maya-kobe` this pass. The `reservation.php`/`adwords.php` references are `<select>` option *values*, not page links, so they're unaffected. Fixed a dead-slug example in `includes/booking-widget.php`'s doc comment. Content preserved in git history.
8. Admin redesign #18 — **Stage 2 built + enabled on Settings; Stage 3 link mechanism added (this pass).** See `redesign-plan.md` §18 stage breakdown. `admin-nav.js` `shellSubmit` AJAX-swaps opt-in `[data-shell-form]` saves (live on Settings' two save forms), capture-phase to coexist with the site-wide submit guard, reusing the workspace's post-commit-safe pattern. Also fixed the sidebar bug where the active page's nav group collapsed on load (`_layout.php`: server opens the group holding the active link; the load script keeps it open, overriding saved-collapse). **Stage 2 now rolled out to the core config pages** (room/tour/property/venue edit, checkin-settings, services add, staff create, and the rooms/tours/properties publish toggles) — only confirm-free, non-upload PRG save forms tagged; `is-flash` added to those pages' banners for consistent toasts; `is-saving` dim/busy cue added while a save fetch is in flight. **Remaining:** interactive logged-in verification, and the list-swap skeleton retrofit (deferred).
9. TripAdvisor listing — **code side prepped (this pass).** All 5 consumers now read one source of truth, `ts_tripadvisor_url()` in `includes/schema.php` (currently `''`): the JSON-LD `sameAs` on Organization + LocalBusiness (via `ts_sameas()`, added only when set — never a search URL) and the 3 homepage badges (via `ts_tripadvisor_badge_url()`, which falls back to a TripAdvisor search link until approved). Verified both states render correctly. **Remaining (you):** claim the listing at tripadvisor.com/GetListedNew (2–5 day approval), then paste me the URL — swap = one line in `ts_tripadvisor_url()`.
