# Check-in: passport reading, guest links, terms dialog, deposit choice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The four guest check-in changes in
`docs/superpowers/specs/2026-10-09-checkin-passport-guests-consent-deposit-design.md`.

**Architecture:** Pure rules in `includes/checkin.php` (passport clean-up + MRZ check digits,
completion rules, WhatsApp text, deposit plan) tested by plain-PHP test files; one new AI
helper `ai_read_image_json()` in `includes/ai.php` (both wire formats, stubbable); the
upload endpoint returns read fields; a shared browser helper `js/checkin-terms.js` for the
terms dialog used by both guest pages; wizard UI changes in `includes/app/checkin.php` +
`js/checkin-wizard.js`; co-guest page inline script; one migration.

**Tech Stack:** PHP 8.2 vanilla, PostgreSQL/PDO, vanilla JS, `css/portal-app.css`.

Run every test with `php tests/<file>.php`; expected last line `ALL PASS` (checkin_logic)
or `0 failures` style per file.

---

### Task 1: Passport read clean-up (pure) + MRZ check digits

**Files:** Modify `includes/checkin.php` (new section "Passport reading"); Create
`tests/checkin_passport_read_logic.php`.

Functions:
- `checkin_mrz_check_digit(string $s): int` — ICAO 9303: weights 7,3,1; `0-9` = value,
  `A-Z` = 10..35, `<` = 0; sum mod 10.
- `checkin_mrz_line2(string $line): ?array` — normalise (upper, strip spaces); must be 44
  chars of `[A-Z0-9<]`; returns `['number','number_ok','nat','expiry','expiry_ok']`
  (number = chars 0–8 minus `<`; check digit at 9; nationality 10–12; expiry YYMMDD 21–26,
  check digit 27; expiry year = 2000+YY).
- `checkin_nationality_name(string $code): string` — 3-letter ICAO code → English name for
  a fixed list (KEN, TZA, UGA, GBR, USA, DEU/D, ITA, FRA, NLD, BEL, CHE, AUT, ESP, PRT, SWE,
  NOR, DNK, FIN, IRL, POL, CZE, CAN, AUS, NZL, ZAF, IND, CHN, JPN, ISR, ARE, RUS, UKR, BRA);
  unknown → `''`.
- `checkin_passport_from_ai(array $raw, string $today): array` → `['fields'=>[…], 'warnings'=>[…]]`.
  Input keys: `is_passport`, `given_names`, `surname`, `passport_number`, `nationality`,
  `nationality_code`, `date_of_expiry`, `mrz_line2`. Rules: `is_passport === false` → no
  fields, warning "That doesn't look like a passport photo page."; name = title-cased
  `given surname`; number = upper alnum, 5–20 chars; with a valid MRZ line: MRZ number with a
  good check digit wins (AI number dropped if it disagrees), a bad check digit drops the
  number; MRZ expiry with a good check digit wins; nationality = AI name, else name from
  code (AI code, else MRZ), else the code; expiry must be a real `Y-m-d` date; expiry
  `< $today` → warning "This passport has expired.".

- [ ] Write tests (check digits on the ICAO specimen `L898902C3`→6, `740812`→2? use computed
  values from the specimen line `L898902C36UTO7408122F1204159ZE184226B<<<<<10`; name casing;
  bad MRZ digit drops number; MRZ number overrides AI; expired warning; invalid date dropped;
  not-a-passport). Run → FAIL (undefined function).
- [ ] Implement. Run → all PASS. Commit.

### Task 2: `ai_read_image_json()`

**Files:** Modify `includes/ai.php`; extend `tests/checkin_passport_read_logic.php`.

```php
const AI_VISION_MAX_TOKENS = 1024;
/** One image + instruction → decoded JSON object, or null. Never throws. */
function ai_read_image_json(string $bytes, string $mime, string $instruction): ?array
```
Claude: `messages=[{role:user, content:[{type:image, source:{type:base64, media_type, data}}, {type:text, text}]}]`,
`max_tokens`, `output_config.effort=low` unless haiku → `ai_claude_request()`; text blocks
joined. OpenAI: `messages=[{role:user, content:[{type:text,text},{type:image_url,image_url:{url:"data:<mime>;base64,…"}}]}]`,
`response_format:{type:json_object}` → `ai_openai_request()`; `choices[0].message.content`.
Decode with `ai_json_from_text()` (pure: strips ```json fences, takes the first `{…}`).
Returns null when unsupported, provider not claude/openai, request fails, or no object.

- [ ] Test (stub `ai_claude_request` before require): payload has an image block with
  base64 data + media type; fenced JSON decodes; garbage → null. Run → FAIL; implement; PASS; commit.

### Task 3: Completion rules, WhatsApp text, deposit plan (pure + guard) + migration

**Files:** Modify `includes/checkin.php`; Create `db/migrations/add_checkin_deposit_plan.sql`;
Modify `tests/checkin_logic.php`.

- `checkin_guest_passport_complete()` → name + number + (file key OR nationality + expiry).
- `checkin_deposit_plans(): array` → `['card_at_arrival'=>'I’ll bring my card — the deposit will be taken at arrival', 'cash_at_arrival'=>'I’ll pay the deposit in cash at arrival']`.
- `checkin_deposit_plan_supported(): bool` — `information_schema.columns` lookup, cached.
- `checkin_deposit_handled(?array $data): bool` — card on file OR `deposit_plan` ∈ plans.
- `checkin_step_complete('deposit')` → `checkin_deposit_handled($data)`.
- `checkin_submit_missing(array $config, ?array $data, ?array $lead): array` —
  `checkin_missing_steps()` plus `'deposit'` when the deposit step is enabled, the plan
  column exists and the deposit is not handled (guest submit gate only; staff completion
  via `checkin_recompute_completion()` is unchanged).
- `checkin_guest_share_text(string $name, string $property, string $dates, string $link): string`
  → `"Hi {first}, please complete your check-in for {property}, {dates}: {link}"` (no name →
  `"Hi, please …"`; empty property/dates parts dropped).
- `checkin_whatsapp_url(string $text): string` → `https://wa.me/?text=` . rawurlencode.
- Migration: `ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS deposit_plan TEXT;`
  `… deposit_plan_at TIMESTAMPTZ;` + a CHECK constraint (added only if missing via DO block).

- [ ] Tests: passport complete with file / with nat+expiry / with neither; deposit handled
  by card / by plan / unknown plan; share text with & without name; wa.me encoding.
  FAIL → implement → apply migration locally (`psql` via `php` one-off) → PASS → commit.

### Task 4: Upload endpoint returns read fields; save endpoint stores the deposit plan

**Files:** Modify `api/checkin-upload.php`, `api/checkin-save.php`.

- Upload (passport branch, after storing): read the tmp file bytes BEFORE
  `storage_put_private()`; if `in_array($mime, ['image/jpeg','image/png'])`,
  `ai_assistant_supported()` and `($_SESSION['ci_passport_reads'] ?? 0) < CHECKIN_PASSPORT_READS_MAX`
  → increment, `ai_read_image_json($bytes,$mime, checkin_passport_read_prompt())`, then
  `checkin_passport_from_ai($raw, date('Y-m-d'))`; respond `{ok:true, fields, warnings}`;
  any Throwable → `{ok:true}`.
- Save (lead, booking-level block): when `checkin_deposit_plan_supported()` and the post
  has `deposit_plan` key: valid plan → store + `deposit_plan_at = now()`; `''` → NULL.
- Save submit gate: use `checkin_submit_missing()`; its deposit message "Please upload your
  card photo or choose how you'll pay the deposit at arrival." For `ajax=1` submit the
  wizard never posts it, so the redirect stays.

- [ ] Implement; `php -l` both; commit.

### Task 5: Terms dialog helper

**Files:** Create `js/checkin-terms.js`; Modify `css/portal-app.css`.

`window.ciTermsDialog(termsEl, onAccept)` — builds a `.pa-modal-backdrop` with a wider
`.pa-modal.ci-terms-modal` (left-aligned, max-width 520px, scrollable terms box cloned from
`termsEl.innerHTML`), title "Please accept the terms to continue", buttons
**I accept the terms** (primary) / **Not now**; Esc + backdrop close; focus the accept
button; body scroll locked while open.

- [ ] Implement + CSS; commit.

### Task 6: Lead wizard — passport block, terms dialog, save response, deposit

**Files:** Modify `includes/app/checkin.php`, `js/checkin-wizard.js`, `css/portal-app.css`.

- Passport block (lead): upload first with hint copy; label "Passport photo (photo page)";
  fields below in a `.ci-pp` wrapper; `data-passport-required` validation becomes name +
  number + (photo OR nationality + expiry).
- `fillPassport(scope, fields, warnings)`: for each field, target `[name=f]` or
  `[data-field=f]` in scope; fill when empty or `data-ai-filled`; mark `data-ai-filled` +
  `.ci-in--ai`; show `.ci-pp-note` "Please check these details." (+ warnings). Typing in a
  field removes its `data-ai-filled`/highlight.
- Upload handler: "Reading your passport…" when an image (not PDF); on response call
  `fillPassport` when `fields` present.
- validateStep(`you`): when the only/first missing item is the terms tick → open
  `ciTermsDialog(.ci-waiver, …)`; accept ticks `.ci-agree` and re-runs the pending action
  (next or submit). Submit handler likewise.
- `saveThen()`: on a 422 JSON response show its `error` via `showErr()` and do not advance.
- Deposit step: add "I can't upload my card now" link + two radios `name="deposit_plan"`
  (only when `checkin_deposit_plan_supported()`), pre-checked from `$data['deposit_plan']`;
  `.ci-submit` hidden (`hidden` attr) while the deposit is unhandled and the step holds the
  submit; JS `syncDepositGate()` reveals it on upload success or a radio choice; picking
  upload clears the radios? No — card wins server-side; radios stay.
  validateStep(deposit): enabled + plan supported → card or plan required (nudge
  "upload a photo of your card, or tell us how you'll pay the deposit").

- [ ] Implement; manual test in browser (Task 9); commit.

### Task 7: Party step — save closes the card, WhatsApp + Copy link

**Files:** Modify `includes/app/checkin.php`, `js/checkin-wizard.js`, `css/portal-app.css`.

- Card markup (rendered + template): `.ci-guest__form` (name, passport fields incl. upload
  at top, Save this guest) and `.ci-guest__done` (name, "Saved ✓", Edit) + `.ci-guest__send`
  panel ("Send {name} their check-in link", WhatsApp `<a target=_blank>` + Copy + read-only
  link input). Saved guests with a name render collapsed; new cards render open.
- Party step root carries `data-share-property` + `data-share-dates`; JS builds text with the
  same rule as `checkin_guest_share_text()` (first name or "Hi,").
- Save: require a name (inline nudge), check `r.ok`, then collapse + update summary name,
  WhatsApp href and chip. Edit reopens.
- Remove the Fill/Send mode buttons and their handlers; replace `confirm()` on remove with
  the terms-dialog-style modal? (keep native confirm — out of scope).
- Waiting-on-others card: add a WhatsApp button per outstanding guest.

- [ ] Implement; commit.

### Task 8: Co-guest page — passport read + terms dialog

**Files:** Modify `includes/app/checkin-guest.php`.

- Upload first with the same hint; on response fill via the same rules (inline copy of
  `fillPassport`, ~20 lines) — the page doesn't load `checkin-wizard.js`.
- Load `js/checkin-terms.js`; on submit, if the agree box is unticked → preventDefault,
  dialog, accept ticks + `form.requestSubmit()`. Also nudge (inline `.ci-err`) when name or
  signature is missing instead of the server round-trip.

- [ ] Implement; commit.

### Task 9: Admin display + docs + verification

**Files:** Modify `admin/_ws_checkin.php`, `CLAUDE.md` (Security-deposit + check-in sections).

- Deposit fact: when no card and a plan → "No card photo — will bring card" /
  "Will pay cash deposit at arrival".
- Run `php tests/checkin_logic.php`, `php tests/checkin_passport_read_logic.php`,
  `php tests/checkin_consent.php`; `php -l` on every touched PHP file.
- Browser check on the local dev server (`:8765`) with a test booking: passport block,
  party save/collapse/WhatsApp, terms dialog, deposit gate, phone width 375.
- Commit.
