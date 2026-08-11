# Check-in Signature & Legal Consent — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture a hand-drawn signature per adult guest, freeze an immutable legal snapshot at signing, and produce a per-guest downloadable consent record.

**Architecture:** Extend the existing `checkin_guests` table with signature + evidence columns (idempotent migration). Add pure helpers to `includes/checkin.php` (TDD via `tests/checkin_logic.php`). A small vanilla `<canvas>` pad (`js/signature-pad.js`) writes a PNG data-URL into a hidden field; `api/checkin-save.php` validates it, enforces self-signing, and stamps the frozen snapshot. A standalone print page (`admin/consent-print.php`) renders the record for Save-as-PDF. No booking-model changes.

**Tech Stack:** Vanilla PHP 8.5 (no framework), PostgreSQL via PDO (`db_query()`/`db()`), vanilla JS/CSS (no build), logic tests via `php tests/checkin_logic.php`.

**Spec:** `docs/superpowers/specs/2026-08-10-checkin-signature-consent-design.md`

**Branch:** `feature/checkin-signature-consent` (already created; spec committed).

**Conventions:** pre-migration guards (`checkin_supported()` / new `checkin_signature_supported()`); CSRF via `verify_csrf()`; IP via `client_ip()`; idempotent migration; admin gating via `is_owner()`/`is_manager()`/`staff_can_hold()`/`can_view_guest_docs()`. Run tests with `php tests/checkin_logic.php` (whole suite: `php tests/*_logic.php`).

---

## Task 1: Migration — signature & evidence columns

**Files:**
- Create: `db/migrations/add_checkin_signature.sql`

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_checkin_signature.sql`:

```sql
-- Signature & legal consent: per-guest drawn signature + frozen legal snapshot.
-- Idempotent — safe to run multiple times. Apply AFTER add_checkin.sql and
-- add_multiguest_checkin.sql (they create holds check-in cols + checkin_guests).
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signature         TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_terms_snapshot    TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_user_agent TEXT;
ALTER TABLE checkin_guests ADD COLUMN IF NOT EXISTS waiver_signed_method     TEXT;
```

- [ ] **Step 2: Apply it to the dev DB**

Run (uses the app's own connection logic, so it works with `.env`'s discrete `DB_*` vars):

```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_checkin_signature.sql")); echo "applied\n";'
```
Expected: `applied`

- [ ] **Step 3: Verify the columns exist**

```bash
php -r 'require "includes/db.php"; db_query("SELECT waiver_signature, waiver_terms_snapshot, waiver_signed_user_agent, waiver_signed_method FROM checkin_guests LIMIT 0"); echo "columns ok\n";'
```
Expected: `columns ok` (throws if any column is missing).

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_checkin_signature.sql
git commit -m "feat(checkin): migration for signature + legal-consent columns"
```

---

## Task 2: Pure helper — `checkin_valid_signature()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test**

In `tests/checkin_logic.php`, add before the final `echo $failures ...` line:

```php
// ── Signature validation (pure) ─────────────────────────────────────────────
$pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat("x", 200);
check('valid png data-url',      checkin_valid_signature('data:image/png;base64,' . base64_encode($pngBytes)) === true);
check('reject non-png mime',     checkin_valid_signature('data:image/gif;base64,' . base64_encode($pngBytes)) === false);
check('reject plain string',     checkin_valid_signature('hello') === false);
check('reject empty',            checkin_valid_signature('') === false);
check('reject non-png bytes',    checkin_valid_signature('data:image/png;base64,' . base64_encode(str_repeat("x", 200))) === false);
$oversize = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("x", 300 * 1024));
check('reject oversize',         checkin_valid_signature($oversize) === false);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: FAIL / fatal — `Call to undefined function checkin_valid_signature()`

- [ ] **Step 3: Implement the helper**

In `includes/checkin.php`, add inside the "Multi-guest per booking" section (e.g., after `checkin_guest_waiver_signed()`):

```php
/** True if $s is a PNG data-URL within the size cap (a drawn signature). Pure. */
function checkin_valid_signature(string $s): bool {
    $prefix = 'data:image/png;base64,';
    if (strncmp($s, $prefix, strlen($prefix)) !== 0) return false;
    $bin = base64_decode(substr($s, strlen($prefix)), true);
    if ($bin === false) return false;
    $len = strlen($bin);
    if ($len < 8 || $len > 250 * 1024) return false;         // too small / too large
    return strncmp($bin, "\x89PNG\r\n\x1a\n", 8) === 0;       // PNG magic bytes
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: the 6 new lines PASS; suite ends `ALL PASS` (or unchanged prior count + 6).

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): checkin_valid_signature() PNG data-URL validator"
```

---

## Task 3: Pure helpers — `checkin_signing_method()` + `checkin_can_sign_self()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/checkin_logic.php` (before the final `echo`):

```php
// ── Signing method + self-sign integrity (pure) ─────────────────────────────
check('method reception',        checkin_signing_method('reception') === 'reception');
check('method default own_link',  checkin_signing_method('') === 'own_link');
check('method other own_link',    checkin_signing_method('whatever') === 'own_link');
check('co-guest signs self',      checkin_can_sign_self(42, false) === true);
check('lead signs own lead row',  checkin_can_sign_self(null, true) === true);
check('lead cannot sign other',   checkin_can_sign_self(null, false) === false);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_signing_method()`

- [ ] **Step 3: Implement the helpers**

In `includes/checkin.php`, add near `checkin_valid_signature()`:

```php
/** How the signing surface was reached: 'reception' (admin's device) or 'own_link'. Pure. */
function checkin_signing_method(string $via): string {
    return $via === 'reception' ? 'reception' : 'own_link';
}

/**
 * Integrity: only the signer may sign. A co-guest (onlyGuestId set) always signs their
 * own row; the lead (null) may sign only the lead row. Pure.
 */
function checkin_can_sign_self(?int $onlyGuestId, bool $targetIsLead): bool {
    return $onlyGuestId !== null || $targetIsLead;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: 6 new lines PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): signing method + self-sign integrity helpers"
```

---

## Task 4: `checkin_waiver_text()` — centralize the waiver terms

**Files:**
- Modify: `includes/checkin.php`
- Modify: `includes/app/checkin.php`
- Modify: `includes/app/checkin-guest.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/checkin_logic.php`:

```php
// ── Waiver text resolution (default + override) ─────────────────────────────
$prevWaiver = setting('checkin_waiver_text', '');
set_setting('checkin_waiver_text', '');
check('waiver text default non-empty', trim(checkin_waiver_text()) !== '');
set_setting('checkin_waiver_text', 'Custom terms XYZ');
check('waiver text uses override',      checkin_waiver_text() === 'Custom terms XYZ');
set_setting('checkin_waiver_text', $prevWaiver); // restore
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function checkin_waiver_text()`

- [ ] **Step 3: Implement the helper**

In `includes/checkin.php`, add near `waiver_version()`:

```php
/** The waiver terms the guest sees: the setting override, else the canonical default. */
function checkin_waiver_text(): string {
    $w = trim((string) setting('checkin_waiver_text', ''));
    return $w !== '' ? $w
        : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: 2 new lines PASS.

- [ ] **Step 5: Refactor the two display surfaces to use it**

In `includes/app/checkin.php`, replace lines 9-10:

```php
$waiver  = setting('checkin_waiver_text', '');
$waiverText = $waiver !== '' ? $waiver : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.';
```
with:
```php
$waiverText = checkin_waiver_text();
```

In `includes/app/checkin-guest.php`, replace lines 8-9:

```php
$waiver = setting('checkin_waiver_text', '');
$waiverText = $waiver !== '' ? $waiver : 'I confirm the information provided is accurate and accept the terms of stay, indemnity and insurance requirements.';
```
with:
```php
$waiverText = checkin_waiver_text();
```

- [ ] **Step 6: Verify nothing broke**

Run: `php tests/checkin_logic.php`
Expected: still passing (`ALL PASS`).

- [ ] **Step 7: Commit**

```bash
git add includes/checkin.php includes/app/checkin.php includes/app/checkin-guest.php tests/checkin_logic.php
git commit -m "refactor(checkin): centralize waiver text in checkin_waiver_text()"
```

---

## Task 5: Require a signature for a signed waiver + `checkin_signature_supported()`

**Files:**
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Update existing fixtures so they include a signature, then add new assertions**

In `tests/checkin_logic.php`:

(a) The inline waiver-complete assertion — replace:
```php
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00']) === true);
```
with:
```php
check('waiver complete when signed',      checkin_step_complete('waiver', [], ['waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06 10:00', 'waiver_signature' => 'sig']) === true);
```

(b) The `$fullLead` fixture — replace:
```php
$fullLead = ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'k', 'waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06'];
```
with:
```php
$fullLead = ['passport_name' => 'A', 'passport_number' => 'B', 'passport_file_key' => 'k', 'waiver_signed_name' => 'A', 'waiver_signed_at' => '2026-08-06', 'waiver_signature' => 'sig'];
```

(c) The `$adult` fixture — replace:
```php
$adult = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06'];
```
with:
```php
$adult = ['passport_name'=>'A','passport_number'=>'B','passport_file_key'=>'k','waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig'];
```

(d) Add new assertions after the `guest waiver unsigned` line:
```php
check('waiver needs a signature',  checkin_guest_waiver_signed(['waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06']) === false);
check('waiver signed w/ signature', checkin_guest_waiver_signed(['waiver_signed_name'=>'A','waiver_signed_at'=>'2026-08-06','waiver_signature'=>'sig']) === true);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: `waiver needs a signature` FAILS (current helper returns true without a signature).

- [ ] **Step 3: Extend `checkin_guest_waiver_signed()` and add the support guard**

In `includes/checkin.php`, replace the whole `checkin_guest_waiver_signed()` function:

```php
/** A single guest row has signed the waiver (name + timestamp). */
function checkin_guest_waiver_signed(?array $g): bool {
    return $g !== null
        && !empty($g['waiver_signed_at'])
        && trim((string)($g['waiver_signed_name'] ?? '')) !== '';
}
```
with:
```php
/** A single guest row has signed the waiver (name + timestamp + a drawn signature). */
function checkin_guest_waiver_signed(?array $g): bool {
    return $g !== null
        && !empty($g['waiver_signed_at'])
        && trim((string)($g['waiver_signed_name'] ?? '')) !== ''
        && trim((string)($g['waiver_signature'] ?? '')) !== '';
}
```

Then add near `checkin_supported()`:

```php
/** True once add_checkin_signature.sql is applied. Cached per-request. */
function checkin_signature_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT waiver_signature FROM checkin_guests LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/checkin.php tests/checkin_logic.php
git commit -m "feat(checkin): require a drawn signature for a signed waiver"
```

---

## Task 6: Capture the signature + frozen snapshot in `api/checkin-save.php`

**Files:**
- Modify: `api/checkin-save.php:51-55` (the waiver block)

- [ ] **Step 1: Replace the waiver-signing block**

In `api/checkin-save.php`, replace:

```php
// ── Per-guest waiver signature (only when they tick + type a name) ──────────
if (!empty($_POST['waiver_agree']) && $s('waiver_signed_name')) {
    db_query("UPDATE checkin_guests SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip, waiver_version=:v WHERE id=:g AND hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version(setting('checkin_waiver_text','')), ':g'=>$guestId, ':h'=>$holdId]);
}
```
with:
```php
// ── Per-guest waiver signature: self-sign only, requires a drawn signature ──
$sig = (string)($_POST['waiver_signature'] ?? '');
$targetIsLead = (bool) db_query('SELECT is_lead FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetchColumn();
if (!empty($_POST['waiver_agree']) && $s('waiver_signed_name')
    && checkin_can_sign_self($onlyGuestId, $targetIsLead)
    && checkin_valid_signature($sig)) {
    $terms  = checkin_waiver_text();
    $method = checkin_signing_method((string)($_POST['via'] ?? ''));
    db_query(
        "UPDATE checkin_guests
            SET waiver_signed_name=:n, waiver_signed_at=now(), waiver_signed_ip=:ip,
                waiver_version=:v, waiver_signature=:sig, waiver_terms_snapshot=:terms,
                waiver_signed_user_agent=:ua, waiver_signed_method=:m
          WHERE id=:g AND hold_id=:h",
        [':n'=>$s('waiver_signed_name'), ':ip'=>client_ip(), ':v'=>waiver_version($terms),
         ':sig'=>$sig, ':terms'=>$terms, ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
         ':m'=>$method, ':g'=>$guestId, ':h'=>$holdId]);
}
```

(`$onlyGuestId` is already in scope from `checkin_auth_context()` at the top of the file.)

- [ ] **Step 2: Sanity-check PHP parses**

Run: `php -l api/checkin-save.php`
Expected: `No syntax errors detected in api/checkin-save.php`

- [ ] **Step 3: Verify with tests (helpers exercised by the endpoint)**

Run: `php tests/checkin_logic.php`
Expected: `ALL PASS` (endpoint itself is verified end-to-end in Task 13).

- [ ] **Step 4: Commit**

```bash
git add api/checkin-save.php
git commit -m "feat(checkin): stamp signature + frozen legal snapshot on sign"
```

---

## Task 7: The signature pad — `js/signature-pad.js`

**Files:**
- Create: `js/signature-pad.js`

- [ ] **Step 1: Write the pad**

Create `js/signature-pad.js`:

```js
(function () {
  // Fixed backing store (600x150) so the pad works even if it is initialised while
  // its wizard step is hidden (display:none => 0 client size). Pointer coords are
  // scaled from the on-screen box to the backing store, so strokes follow the finger.
  function initPad(canvas) {
    if (canvas.__ciSignInit) return; canvas.__ciSignInit = true;
    var sel = canvas.getAttribute('data-target');
    var hidden = sel ? document.querySelector(sel) : null;
    var ctx = canvas.getContext('2d');
    canvas.width = 600; canvas.height = 150;
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#16375a';
    var drawing = false, dirty = false, last = null;
    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      var t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      var sx = rect.width ? canvas.width / rect.width : 1;
      var sy = rect.height ? canvas.height / rect.height : 1;
      return { x: (t.clientX - rect.left) * sx, y: (t.clientY - rect.top) * sy };
    }
    function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
    function move(e) {
      if (!drawing) return; e.preventDefault();
      var p = pos(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; dirty = true;
    }
    function end() { if (!drawing) return; drawing = false; if (hidden && dirty) hidden.value = canvas.toDataURL('image/png'); }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
    var wrap = canvas.closest('.ci-sign');
    var clr = wrap ? wrap.querySelector('.ci-sign-clear') : null;
    if (clr) clr.addEventListener('click', function (e) {
      e.preventDefault();
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      dirty = false; if (hidden) hidden.value = '';
    });
  }
  function initAll() { document.querySelectorAll('canvas.ci-sign-pad').forEach(initPad); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll); else initAll();
  window.ciSignInitAll = initAll; // exposed in case markup is injected later
})();
```

- [ ] **Step 2: Commit**

```bash
git add js/signature-pad.js
git commit -m "feat(checkin): vanilla canvas signature pad"
```

(Rendered behaviour is verified in Task 9/10 once the markup is wired in.)

---

## Task 8: Signature pad styles

**Files:**
- Modify: `css/portal-app.css` (append)

- [ ] **Step 1: Append the styles**

Add to the end of `css/portal-app.css`:

```css
/* ── Signature pad ─────────────────────────────────────────────────────────── */
.ci-sign { position: relative; margin: 6px 0 4px; }
.ci-sign-pad {
  width: 100%; height: 150px; display: block;
  background: #fff; border: 1.5px dashed #c3c8bf; border-radius: 9px;
  touch-action: none;               /* critical: draw instead of scroll on touch */
}
.ci-sign-clear {
  position: absolute; top: 8px; right: 10px;
  background: none; border: 0; color: #123c30; font-weight: 600; font-size: 12px; cursor: pointer;
}
.ci-sign-hint { font-size: 11px; color: #9aa39a; margin-top: 4px; }
```

- [ ] **Step 2: Commit**

```bash
git add css/portal-app.css
git commit -m "feat(checkin): signature pad styles"
```

---

## Task 9: Wire the pad into the lead wizard + drop waiver from the co-guest inline card

**Files:**
- Modify: `includes/app/checkin.php` (lead card waiver block ~141-146; additional-adult inline waiver ~182-187; script includes ~228)

- [ ] **Step 1: Add the pad to the lead's own card**

In `includes/app/checkin.php`, replace the lead-card waiver block:

```php
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($lead) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <?php endif; ?>
```
with:
```php
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($lead) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $val('waiver_signed_name', $lead) ?>" placeholder="Full name">
          <label class="ci-l">Sign below with your finger</label>
          <div class="ci-sign">
            <button type="button" class="ci-sign-clear">Clear</button>
            <canvas class="ci-sign-pad" data-target="#ciLeadSig"></canvas>
          </div>
          <input type="hidden" name="waiver_signature" id="ciLeadSig">
          <p class="ci-sign-hint">Reception can fill your details, but you sign yourself.</p>
          <?php endif; ?>
```

- [ ] **Step 2: Remove waiver signing from the additional-adult inline card**

In `includes/app/checkin.php`, replace the additional-adult inline waiver block:

```php
            <?php if ($showWaiver): ?>
            <label class="ci-radio"><input type="checkbox" data-field="waiver_agree" value="1" <?= checkin_guest_waiver_signed($g) ? 'checked' : '' ?>> They agree to the terms</label>
            <label class="ci-l">Their full name (signature)</label>
            <input class="ci-in" data-field="waiver_signed_name" value="<?= e((string)$g['waiver_signed_name']) ?>" placeholder="Full name">
            <p class="ci-hint">Tip: for a personal signature, use “Send them a link” so they sign it themselves.</p>
            <?php endif; ?>
```
with:
```php
            <?php if ($showWaiver): ?>
            <p class="ci-hint">They sign the waiver themselves — use “Send them a link”, or “Sign on this device” from the admin check-in tab if they’re with you.</p>
            <?php endif; ?>
```

- [ ] **Step 3: Load the pad script before the wizard script**

In `includes/app/checkin.php`, replace the final script line:

```php
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
```
with:
```php
<script src="/js/signature-pad.js?v=<?= @filemtime(__DIR__ . '/../../js/signature-pad.js') ?: time() ?>" defer></script>
<script src="/js/checkin-wizard.js?v=<?= @filemtime(__DIR__ . '/../../js/checkin-wizard.js') ?: time() ?>" defer></script>
```

- [ ] **Step 4: Verify in the browser**

Start the dev server (`.claude/launch.json` "tribalsand"), open a booking's check-in wizard (see Task 13 for a throwaway hold), navigate to the "Your party" step. Confirm: the pad renders, drawing with the mouse leaves a stroke, "Clear" wipes it, and the additional-adult card shows only the "they sign themselves" hint (no name/agree waiver fields).

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin.php
git commit -m "feat(checkin): signature pad in lead card; co-guests sign via their own link"
```

---

## Task 10: Wire the pad into the co-guest page + `via=reception`

**Files:**
- Modify: `includes/app/checkin-guest.php` (lead-card waiver block ~69-74; add `via` field to the form ~50-52; add script at the bottom)

- [ ] **Step 1: Add the pad to the co-guest card**

In `includes/app/checkin-guest.php`, replace:

```php
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($me) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $v('waiver_signed_name') ?>" placeholder="Full name">
          <?php endif; ?>
```
with:
```php
          <?php if ($showWaiver): ?>
          <div class="ci-waiver"><?= nl2br(e($waiverText)) ?></div>
          <label class="ci-radio"><input type="checkbox" name="waiver_agree" value="1" <?= checkin_guest_waiver_signed($me) ? 'checked' : '' ?>> I have read and agree to the terms</label>
          <label class="ci-l">Type your full name to sign</label>
          <input class="ci-in" name="waiver_signed_name" value="<?= $v('waiver_signed_name') ?>" placeholder="Full name">
          <label class="ci-l">Sign below with your finger</label>
          <div class="ci-sign">
            <button type="button" class="ci-sign-clear">Clear</button>
            <canvas class="ci-sign-pad" data-target="#ciGSig"></canvas>
          </div>
          <input type="hidden" name="waiver_signature" id="ciGSig">
          <?php endif; ?>
```

- [ ] **Step 2: Add the `via` marker to the form**

In `includes/app/checkin-guest.php`, replace:

```php
        <input type="hidden" name="g" value="<?= e($gtoken) ?>">
```
with:
```php
        <input type="hidden" name="g" value="<?= e($gtoken) ?>">
        <input type="hidden" name="via" value="<?= e((string)($_GET['via'] ?? '')) ?>">
```

- [ ] **Step 3: Load the pad script**

In `includes/app/checkin-guest.php`, immediately after the opening `<link rel="stylesheet" href="/css/portal-app.css...">` line (line 26), add:

```php
<script src="/js/signature-pad.js?v=<?= @filemtime(__DIR__ . '/../../js/signature-pad.js') ?: time() ?>" defer></script>
```

- [ ] **Step 4: Verify in the browser**

Open a co-guest link (`/booking.php?g=<token>` — see Task 13). Confirm the pad renders and draws, and that opening `/booking.php?g=<token>&via=reception` still shows the pad (the `via` value rides along on submit).

- [ ] **Step 5: Commit**

```bash
git add includes/app/checkin-guest.php
git commit -m "feat(checkin): signature pad on the co-guest page + reception marker"
```

---

## Task 11: The consent record — `admin/consent-print.php`

**Files:**
- Create: `admin/consent-print.php`

- [ ] **Step 1: Write the page**

Create `admin/consent-print.php`:

```php
<?php
/** Printable signed-consent record for one guest. Owner/venue-manager, or the guest via their own token. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';

$holdId  = (int)($_GET['hold'] ?? 0);
$guestId = (int)($_GET['guest'] ?? 0);
$ref     = trim((string)($_GET['ref'] ?? ''));
$gTok    = trim((string)($_GET['g'] ?? ''));

// Auth: guest token (lead ref = any guest on the hold; co-guest g = own row only), else admin session.
if ($ref !== '' && verify_guest_ref($ref) === $holdId) {
    // lead — PII Policy A: may view any guest on their hold
} elseif ($gTok !== '' && ($gc = verify_guest_pass_token($gTok)) !== false && $gc[0] === $holdId && $gc[1] === $guestId) {
    // co-guest — own record only
} else {
    require_login();
    if (!can_view_guest_docs($holdId)) { http_response_code(403); exit('Forbidden'); }
}
if (!$holdId || !$guestId) { http_response_code(400); exit('Bad request.'); }
if (!checkin_signature_supported()) { http_response_code(503); exit('Run the add_checkin_signature.sql migration first.'); }

$hold  = fetch_hold_for_guest($holdId);
$guest = db_query('SELECT * FROM checkin_guests WHERE id=:g AND hold_id=:h', [':g'=>$guestId, ':h'=>$holdId])->fetch();
if (!$hold || !$guest)                     { http_response_code(404); exit('Record not found.'); }
if (!checkin_guest_waiver_signed($guest))  { http_response_code(404); exit('This guest has not signed the waiver yet.'); }

audit_log('checkin.consent_view', 'hold', $holdId, 'guest ' . $guestId);

$ppNum  = trim((string)($guest['passport_number'] ?? ''));
$ppMask = $ppNum === '' ? '—' : (strlen($ppNum) <= 2 ? str_repeat('•', strlen($ppNum))
          : substr($ppNum, 0, 1) . str_repeat('•', max(1, strlen($ppNum) - 2)) . substr($ppNum, -1));
$stayLoc     = trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·');
$terms       = (string)($guest['waiver_terms_snapshot'] ?? '');
$signedAt    = (string)($guest['waiver_signed_at'] ?? '');
$methodLabel = ((string)($guest['waiver_signed_method'] ?? '') === 'reception')
             ? 'On a Tribal Sand device, in person' : 'Own device, via personal check-in link';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Signed consent · <?= e((string)($guest['passport_name'] ?: 'Guest')) ?></title>
<style>
  html{background:#e9ebef}
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1c;max-width:720px;margin:22px auto;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.15)}
  .printbtn{position:fixed;top:14px;right:14px;padding:10px 16px;background:#123c30;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px}
  .head{background:#123c30;color:#fff;padding:20px 30px;display:flex;justify-content:space-between;align-items:flex-end}
  .brand{font-family:Georgia,serif;font-size:22px;letter-spacing:3px;font-weight:700}
  .subh{font-size:10px;letter-spacing:2px;opacity:.75;text-transform:uppercase;margin-top:3px}
  .bd{padding:26px 30px 30px}
  .sec{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#123c30;font-weight:700;border-bottom:1.5px solid #123c30;padding-bottom:5px;margin:22px 0 10px}
  table{width:100%;border-collapse:collapse;font-size:12.5px}
  td{padding:4px 0;vertical-align:top}
  td.k{color:#666;width:150px}
  .terms{font-size:11.5px;line-height:1.6;color:#333;background:#f7f8f7;border:1px solid #e4e7e4;border-radius:3px;padding:12px 14px;white-space:pre-wrap}
  .sigbox{border:1px solid #d8dbd8;border-radius:4px;padding:8px;max-width:280px;background:#fff}
  .sigbox img{display:block;max-width:100%;height:auto}
  .stamp{border:1.5px solid #123c30;color:#123c30;font-size:9px;letter-spacing:1.5px;font-weight:700;padding:6px 10px;border-radius:3px;transform:rotate(-3deg)}
  code{background:#eee;padding:1px 5px;border-radius:3px}
  @media print{.printbtn{display:none}html,body{background:#fff;box-shadow:none;margin:0}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<div class="head">
  <div><div class="brand">TRIBAL SAND</div><div class="subh">Kilifi · Kenya Coast</div></div>
  <div style="text-align:right;font-size:11px;opacity:.85;line-height:1.5">Guest Indemnity &amp; Waiver<br><strong style="opacity:1">Signed Consent Record</strong></div>
</div>
<div class="bd">
  <table>
    <tr><td class="k">Booking reference</td><td><strong>TS-<?= (int)$holdId ?><?= !empty($hold['access_code']) ? ' · code ' . e((string)$hold['access_code']) : '' ?></strong></td></tr>
    <tr><td class="k">Stay</td><td><strong><?= e($stayLoc) ?></strong></td></tr>
    <tr><td class="k">Dates</td><td><strong><?= e(date('D j M Y', strtotime((string)$hold['check_in']))) ?> &rarr; <?= e(date('D j M Y', strtotime((string)$hold['check_out']))) ?></strong></td></tr>
  </table>

  <div class="sec">1 · Signatory</div>
  <table>
    <tr><td class="k">Full name</td><td><strong><?= e((string)($guest['passport_name'] ?: '—')) ?></strong></td></tr>
    <tr><td class="k">Role</td><td><?= !empty($guest['is_lead']) ? 'Lead guest' : 'Adult guest' ?></td></tr>
    <tr><td class="k">Nationality</td><td><?= e((string)($guest['nationality'] ?: '—')) ?></td></tr>
    <tr><td class="k">Passport</td><td>No. <?= e($ppMask) ?><?= !empty($guest['passport_file_key']) ? ' · scan on file' : '' ?></td></tr>
  </table>

  <div class="sec">2 · Terms agreed</div>
  <div class="terms"><?= e($terms) ?></div>
  <div style="font-size:10.5px;color:#888;margin-top:8px">Waiver version <code><?= e((string)($guest['waiver_version'] ?? '')) ?></code> — the exact terms shown to the guest at signing.</div>

  <div class="sec">3 · Signature</div>
  <div style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap">
    <div class="sigbox"><img src="<?= e((string)$guest['waiver_signature']) ?>" alt="Signature"></div>
    <div style="font-size:12px;line-height:1.7">
      <div style="color:#666">Signed by</div>
      <div style="font-weight:600"><?= e((string)$guest['waiver_signed_name']) ?></div>
      <div style="color:#666;margin-top:6px">&#9745; Confirmed &ldquo;I have read and agree&rdquo;</div>
    </div>
  </div>

  <div class="sec">4 · Evidence of signing</div>
  <table>
    <tr><td class="k">Date &amp; time</td><td><strong><?= $signedAt ? e(date('j M Y, H:i:s', strtotime($signedAt))) : '—' ?></strong></td></tr>
    <tr><td class="k">IP address</td><td><?= e((string)($guest['waiver_signed_ip'] ?: '—')) ?></td></tr>
    <tr><td class="k">Device</td><td><?= e((string)($guest['waiver_signed_user_agent'] ?: '—')) ?></td></tr>
    <tr><td class="k">Method</td><td><?= e($methodLabel) ?></td></tr>
  </table>

  <div style="margin-top:26px;padding-top:12px;border-top:1px solid #e0e2e0;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:10px;color:#999;line-height:1.5">Electronic record generated by the Tribal Sand guest system</div>
    <div class="stamp">ELECTRONICALLY&nbsp;SIGNED</div>
  </div>
</div>
</body></html>
```

- [ ] **Step 2: Sanity-check PHP parses**

Run: `php -l admin/consent-print.php`
Expected: `No syntax errors detected in admin/consent-print.php`

- [ ] **Step 3: Commit**

```bash
git add admin/consent-print.php
git commit -m "feat(checkin): printable signed-consent record"
```

(Rendering is verified in Task 13 once a guest has signed.)

---

## Task 12: Admin check-in tab — download & reception-sign links

**Files:**
- Modify: `admin/_ws_checkin.php:92` (the Waiver `<td>`)

- [ ] **Step 1: Add the links to the waiver cell**

In `admin/_ws_checkin.php`, replace:

```php
        <td><?php if ($__waiverOk): ?>Signed by <?= e((string)$g['waiver_signed_name']) ?> on <?= e(date('j M Y', strtotime((string)$g['waiver_signed_at']))) ?><?php else: ?><span class="text-muted">Not signed</span><?php endif; ?></td>
```
with:
```php
        <td>
          <?php if ($__waiverOk): ?>
            Signed by <?= e((string)$g['waiver_signed_name']) ?> on <?= e(date('j M Y', strtotime((string)$g['waiver_signed_at']))) ?>
            <?php if ($__canDocs): ?><br><a href="/admin/consent-print.php?hold=<?= $holdId ?>&guest=<?= (int)$g['id'] ?>" target="_blank" class="btn-sm btn-outline" style="margin-top:4px">Download consent &rarr;</a><?php endif; ?>
          <?php else: ?>
            <span class="text-muted">Not signed</span>
            <?php $__signLink = make_guest_pass_url($holdId, (int)$g['id']); if ($__signLink !== ''): ?><br><a href="<?= e($__signLink) ?>&amp;via=reception" target="_blank" class="btn-sm btn-outline" style="margin-top:4px">Sign on this device &rarr;</a><?php endif; ?>
          <?php endif; ?>
        </td>
```

- [ ] **Step 2: Sanity-check PHP parses**

Run: `php -l admin/_ws_checkin.php`
Expected: `No syntax errors detected in admin/_ws_checkin.php`

- [ ] **Step 3: Commit**

```bash
git add admin/_ws_checkin.php
git commit -m "feat(checkin): admin download-consent + sign-on-this-device links"
```

---

## Task 13: Full end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole logic suite**

Run: `php tests/checkin_logic.php` then `php tests/*_logic.php`
Expected: `ALL PASS` for check-in; no regressions elsewhere.

- [ ] **Step 2: Create a throwaway booking directly (never via the New Booking form)**

```bash
php -r '
require "includes/db.php";
$u = db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id LIMIT 1")->fetchColumn();
db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at, require_checkin, guest_count) VALUES (:u, CURRENT_DATE+30, CURRENT_DATE+33, :n, :e, \"confirmed\", now(), TRUE, 2)", [":u"=>(int)$u, ":n"=>"ZZ Sig Test", ":e"=>"zz-sig@example.com"]);
$h = (int)db()->lastInsertId();
require "includes/booking.php";
echo "hold_id=$h\n";
echo "lead manage url: ".make_manage_url($h)."\n";
'
```
Note the `hold_id` and the lead magic-link URL.

- [ ] **Step 3: Drive the guest flow in the browser**

Using the dev server (`.claude/launch.json` "tribalsand") and the printed manage URL:
1. Land on the hard-gated check-in wizard; walk to the **Your party** step.
2. As the **lead**: fill passport fields, tick agree, type name, **draw a signature**, and note the additional-adult card offers only "sign via link".
3. **Add a 2nd adult**, then use **"Send them a link"** and copy the co-guest URL (or open it directly).
4. Open the co-guest URL in a second browser/incognito window; fill passport, tick, type name, **draw a signature**, submit.
5. Confirm the booking flips to **checked-in ✓** once both adults are complete.

- [ ] **Step 4: Confirm the frozen snapshot persisted**

```bash
php -r '
require "includes/db.php";
$h = (int) $argv[1];
foreach (db_query("SELECT passport_name, is_lead, (waiver_signature IS NOT NULL) AS has_sig, waiver_signed_method, left(waiver_terms_snapshot,20) AS terms, waiver_signed_user_agent IS NOT NULL AS has_ua FROM checkin_guests WHERE hold_id=:h ORDER BY is_lead DESC", [":h"=>$h])->fetchAll() as $r) { print_r($r); }
' <hold_id>
```
Expected: each adult row shows `has_sig = t`, a non-empty `terms` snapshot, `has_ua = t`, and a `waiver_signed_method`.

- [ ] **Step 5: Verify the consent document**

As an owner (logged into admin), open `/admin/booking.php?hold=<hold_id>&tab=checkin`. For each signed adult click **Download consent →**; confirm the record renders with the drawn signature image, the frozen terms, and the evidence block, and that **Print / Save PDF** produces a clean page. For an unsigned guest, confirm **Sign on this device →** opens their signing page.

- [ ] **Step 6: Clean up the throwaway booking**

```bash
php -r 'require "includes/db.php"; db_query("DELETE FROM holds WHERE id=:h", [":h"=>(int)$argv[1]]); echo "deleted (checkin_guests cascade)\n";' <hold_id>
```

- [ ] **Step 7: Final commit (if any verification tweaks were needed)**

```bash
git add -A && git commit -m "test(checkin): verify signature + consent end-to-end" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- Drawn signature per adult → Tasks 7–10.
- Frozen legal snapshot (terms, signature, ip, ua, method) → Tasks 1, 4, 6.
- Downloadable consent record → Task 11; access points → Task 12 (admin) + guest token in Task 11.
- Signing integrity (staff pre-fill all but the signature) → Task 3 (`checkin_can_sign_self`), enforced in Task 6; UI in Task 9 (inline waiver removed).
- Reception-tablet flow → `via=reception` in Tasks 10 & 12; method in Tasks 3, 6, 11.
- Signature required for completion → Task 5 (flows through existing `checkin_recompute_completion`).
- Migration safety → Tasks 1 & 5 (`checkin_signature_supported`).
- Tests → Tasks 2–5; E2E → Task 13.

**Placeholder scan:** none — every code step contains full code; every command has expected output.

**Type/name consistency:** `checkin_valid_signature`, `checkin_signing_method`, `checkin_can_sign_self`, `checkin_waiver_text`, `checkin_signature_supported`, `checkin_guest_waiver_signed` used identically across tasks. Hidden field `waiver_signature` and its ids `#ciLeadSig` / `#ciGSig` match their `data-target` attributes. `via` posted in Tasks 10/12 and read in Task 6.

**Migration ordering note:** apply `add_checkin_signature.sql` together with `add_checkin.sql` + `add_multiguest_checkin.sql` on the live DB — the stricter `checkin_guest_waiver_signed()` needs the `waiver_signature` column present, so don't enable check-in in production without this migration.
