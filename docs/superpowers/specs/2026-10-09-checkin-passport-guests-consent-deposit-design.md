# Check-in: passport reading, guest links, consent dialog, deposit choice

Date: 2026-10-09 · Owner-approved design (four changes to the guest pre-check-in).

Pages touched: the lead wizard `includes/app/checkin.php` + `js/checkin-wizard.js`, the
co-guest page `includes/app/checkin-guest.php` (own inline script), `api/checkin-upload.php`,
`api/checkin-save.php`, `includes/checkin.php`, `includes/ai.php`, the admin
`admin/_ws_checkin.php`.

## 1. Passport — upload OR type; an uploaded photo is read automatically

**Owner decisions:** either a photo or typed details finishes the step; the photo is read by
the AI the site already uses (Claude by default, via `includes/ai.php`).

- In every passport block (lead card, each adult on the party step, the co-guest page) the
  upload moves to the top with the hint *"Upload a photo of your passport's photo page and we'll
  fill this in for you — or type the details below."*
- **Reading happens inside the upload request.** `api/checkin-upload.php` stores the file exactly
  as today, then — only when the file is an image (JPEG/PNG), `ai_assistant_supported()` is true
  and the session has done fewer than `CHECKIN_PASSPORT_READS_MAX` (6) reads — sends the image
  to `ai_read_image_json($bytes, $mime, $prompt)` and returns
  `{ok:true, fields:{passport_name, passport_number, nationality, passport_expiry}, warnings:[…]}`.
  Otherwise it returns `{ok:true}` as today. A read failure never fails the upload.
- **`ai_read_image_json()`** (new, `includes/ai.php`): one single-turn, no-tools request with an
  image block, both wire formats (Claude `image`/base64 source; OpenAI `image_url` data URL),
  expecting a JSON object back. Reuses `ai_claude_request()` / `ai_openai_request()` (stubbable).
  Returns the decoded array or `null`.
- **Pure clean-up `checkin_passport_from_ai(array $raw, string $today): array`**
  (`includes/checkin.php`): trims, upper-cases the number, normalises dates to `Y-m-d`
  (rejects impossible dates), turns a 3-letter nationality code into a country name when it
  knows it (else keeps the text), and — when the reply includes the MRZ second line — verifies
  the passport number's ICAO 9303 check digit (`checkin_mrz_check_digit()`). A number that
  fails its check digit is dropped (the guest types it). An expiry before `$today` adds the
  warning *"This passport has expired."* Returns `['fields'=>[…only valid ones…], 'warnings'=>[…]]`.
- **Filling in the browser:** fills only fields that are empty or were AI-filled before
  (`data-ai-filled`); never overwrites typed text. Filled fields get a highlight and the note
  *"Please check these details."* While reading, the upload box says *"Reading your passport…"*.
- **Completion rule changes** — `checkin_guest_passport_complete()`:
  name + number + (photo on file **or** nationality + expiry). The client `validateStep`
  mirrors it. The signed-record identity snapshot is unchanged (it uses the fields).
- Not read: PDFs, no AI key, over the session cap — upload behaves exactly as today.

## 2. Add guest — Save closes the form and shows the link, with WhatsApp

- After **Save this guest** succeeds, the guest card collapses to a summary row (name, ✓,
  **Edit** reopens the form) and shows the link panel, always visible:
  *"Send {name} their check-in link"* — **WhatsApp** + **Copy link**.
- WhatsApp = `https://wa.me/?text=<encoded>` (WhatsApp's own contact picker; no phone field is
  added). Message: *"Hi {name}, please complete your check-in for {property}, {dates}: {link}"*.
  Built by pure `checkin_guest_whatsapp_url($name, $property, $dates, $link)`.
- The old hidden "Send them a link" reveal goes; the "waiting on others" card gets the same
  WhatsApp button next to its link.

## 3. Agree & sign — a dialog with the terms, no error, no reload

- When **Save & continue** or **Complete check-in** is pressed with the agree box unticked, the
  guest dialog (`.pa-modal`) opens showing the full terms (`checkin_waiver_text()`), buttons
  **I accept the terms** / **Not now**. *I accept* ticks the box and continues the action the
  guest pressed; a still-missing signature then shows the normal inline nudge.
- Both the lead wizard and the co-guest page (which today has no client check and reloads on
  the server error).
- The server consent check (`checkin_consent_missing()`) stays as the backstop. `saveThen()`
  stops ignoring the response: a 422 shows its message inline instead of being swallowed.

## 4. Deposit — Complete appears only once the card is dealt with

**Owner decision:** the fallback is a choice of card-at-arrival or cash-at-arrival.

- With the deposit step enabled, **Complete check-in** is hidden until the card photo is
  uploaded **or** the guest taps **"I can't upload my card now"** and picks
  ○ *I'll bring my card — the deposit will be taken at arrival* or
  ○ *I'll pay the deposit in cash at arrival* (amount shown when the property has one).
- Server: `checkin_step_complete('deposit')` = card on file **or** a deposit plan; the deposit
  step is checked whenever it is **enabled** (not only when marked required) in the submit gate.
- Migration `add_checkin_deposit_plan.sql` (after `add_checkin_deposit`):
  `booking_checkin.deposit_plan TEXT CHECK (IN ('card_at_arrival','cash_at_arrival'))`,
  `deposit_plan_at TIMESTAMPTZ`. Guard `checkin_deposit_plan_supported()` is an
  `information_schema` lookup (never a failing SELECT). Pre-migration: the choice is not
  offered and the old behaviour stands. A later card upload wins (shown as "card on file").
- Admin `_ws_checkin.php` shows *"No card photo — will bring card"* /
  *"Will pay cash deposit at arrival"*.

## Out of scope
No phone field for co-guests; no changes to the admin step-settings page; no OCR on PDFs.

## Tests
`php tests/checkin_logic.php` extended: passport completion (photo-or-fields), deposit
completion (card or plan), WhatsApp URL. New `php tests/checkin_passport_read_logic.php`:
AI-reply clean-up, MRZ check digits, dates, expiry warning, `ai_read_image_json()` payload
shape with a stubbed request (no network).
