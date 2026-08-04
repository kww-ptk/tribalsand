# Portal Home redesign + per-property stay info + requests-as-conversations — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the guest portal Home tab (no marketing nav, greeting-led, address→Maps, collapsible per-property "Your stay" and "My Calendar", updated concierge tiles) and make every concierge request auto-start a message conversation.

**Architecture:** Vanilla PHP 8.2 templates under `includes/app/*` composed by `booking.php`; per-property content moves from the global `settings` table onto new `venues` columns edited in `admin/venue-edit.php`. Request submission (`api/booking-addon.php`) seeds a `booking_messages` thread and redirects the guest into it. All new logic that can be unit-tested lands in `includes/booking.php` and is covered by `tests/portal_logic.php` (a bespoke `check()` script, run with `php tests/portal_logic.php`).

**Tech Stack:** PHP 8.2 (no framework), PostgreSQL via PDO `db_query()`, vanilla CSS (`css/portal-app.css`), vanilla JS (`js/booking-manage.js`). Local dev: `php -S localhost:8765 router.php` against the local `.env` DB.

**Conventions:** Escape all output with `e()`. Never `$_SERVER['REMOTE_ADDR']` (use `client_ip()`). Admin mutations: `require_login()` + `require_owner()` + `verify_csrf()` + PRG + `audit_log()`. Portal writes stay CSRF/Turnstile-free by design. Commit trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File map

| File | Change |
|------|--------|
| `db/migrations/add_venue_stay_info.sql` | **Create** — 6 new `venues` columns + one-time seed from global settings |
| `includes/booking.php` | **Modify** — add `fetch_venue_stay()`, `venue_maps_link()`, `seed_request_message()` |
| `tests/portal_logic.php` | **Modify** — checks for the three new helpers |
| `api/booking-addon.php` | **Modify** — seed request thread + return redirect URL |
| `js/booking-manage.js` | **Modify** — follow `data.redirect` on success |
| `admin/venue-edit.php` | **Modify** — "Stay info & location" card + `save_stay` handler |
| `admin/stay-info.php` | **Delete** |
| `admin/_layout.php` | **Modify** — remove the Stay Info nav item |
| `booking.php` | **Modify** — drop `header.php`, set `$portal_chrome`, greeting topbar title |
| `includes/footer.php` | **Modify** — gate marketing chrome behind `$portal_chrome`, always emit modal JS |
| `includes/app/status-header.php` | **Modify** — address→Maps row |
| `includes/app/_stay_essentials.php` | **Modify** — read per-venue stay values |
| `includes/app/_trip.php` | **Modify** — rename "My Calendar", wrap in `<details>` |
| `includes/app/_services.php` | **Modify** — Make a request / Activities / Messages tiles |
| `includes/app/_greeting_board.php` | **Modify** — remove greeting line (board only) |
| `includes/app/home.php` | **Modify** — new include order |
| `css/portal-app.css` | **Modify** — address row, nav tiles, resized layout |

---

## Task 1: DB migration — per-property stay/location columns

**Files:**
- Create: `db/migrations/add_venue_stay_info.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Tribal Sand: per-property stay info + location. Moves stay_* off the global
-- settings table onto each venue. Run via /admin/migrate.php. Idempotent.
ALTER TABLE venues
  ADD COLUMN IF NOT EXISTS address          TEXT,
  ADD COLUMN IF NOT EXISTS maps_url         TEXT,
  ADD COLUMN IF NOT EXISTS stay_wifi        TEXT,
  ADD COLUMN IF NOT EXISTS stay_checkout    TEXT,
  ADD COLUMN IF NOT EXISTS stay_house_rules TEXT,
  ADD COLUMN IF NOT EXISTS stay_area_guide  TEXT;

-- One-time seed: carry existing GLOBAL stay values into every venue as an
-- editable starting point, so the app isn't blank on deploy. Only fills NULLs.
UPDATE venues SET
  stay_wifi        = COALESCE(stay_wifi,        (SELECT setting_value FROM settings WHERE setting_key='stay_wifi')),
  stay_checkout    = COALESCE(stay_checkout,    (SELECT setting_value FROM settings WHERE setting_key='stay_checkout')),
  stay_house_rules = COALESCE(stay_house_rules, (SELECT setting_value FROM settings WHERE setting_key='stay_house_rules')),
  stay_area_guide  = COALESCE(stay_area_guide,  (SELECT setting_value FROM settings WHERE setting_key='stay_area_guide'));
```

- [ ] **Step 2: Apply it to the local dev DB**

Run: `php admin/migrate.php` is browser-only; apply directly with psql-over-PDO via a one-off script, or run the SQL through your local client. Simplest local apply:

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_venue_stay_info.sql")); echo "applied\n";'`
Expected: `applied`

- [ ] **Step 3: Verify columns exist**

Run: `php -r 'require "includes/db.php"; var_dump(db()->query("SELECT address, maps_url, stay_wifi, stay_checkout, stay_house_rules, stay_area_guide FROM venues LIMIT 1")->fetch());'`
Expected: an array (or `false` if no venues) — no "column does not exist" error.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_venue_stay_info.sql
git commit -m "feat(db): per-property stay info + location columns on venues

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Helpers — `venue_maps_link`, `fetch_venue_stay`, `seed_request_message` (TDD)

**Files:**
- Modify: `includes/booking.php` (add three functions near `fetch_guest_board`, ~line 327)
- Test: `tests/portal_logic.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/portal_logic.php`, immediately before the final `echo` summary line (after the itinerary block). If unsure where the summary is, append before the line that prints the pass/fail total.

```php
// ── per-property stay info + maps link ───────────────────────
check('maps_link: prefers stored maps_url',
      venue_maps_link(['maps_url'=>'https://maps.app.goo.gl/xyz','address'=>'Ignored']) === 'https://maps.app.goo.gl/xyz');
check('maps_link: builds a search URL from the address',
      venue_maps_link(['maps_url'=>'','address'=>'Zuri Beach, Vipingo'])
        === 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Zuri Beach, Vipingo'));
check('maps_link: empty when nothing to link', venue_maps_link(['maps_url'=>'','address'=>'']) === '');
check('maps_link: tolerates missing keys', venue_maps_link([]) === '');

$vsNull = fetch_venue_stay(null);
check('venue_stay(null) returns the six blank keys',
      $vsNull['address']==='' && $vsNull['maps_url']==='' && $vsNull['stay_wifi']==='' &&
      $vsNull['stay_checkout']==='' && $vsNull['stay_house_rules']==='' && $vsNull['stay_area_guide']==='');

$vsVid = (int)(db()->query("SELECT id FROM venues ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($vsVid) {
    db_query("UPDATE venues SET stay_wifi='ZZ Net · pw zz', address='ZZ Addr' WHERE id=:v", [':v'=>$vsVid]);
    $vs = fetch_venue_stay($vsVid);
    check('venue_stay(venue) reads stored wifi', $vs['stay_wifi'] === 'ZZ Net · pw zz');
    check('venue_stay(venue) reads stored address', $vs['address'] === 'ZZ Addr');
    db_query("UPDATE venues SET stay_wifi=NULL, address=NULL WHERE id=:v", [':v'=>$vsVid]);
}

// ── request auto-starts a conversation ───────────────────────
$shid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($shid) {
    db_query("INSERT INTO booking_addons (hold_id, kind, details) VALUES (:h,'other','ZZ seed request')", [':h'=>$shid]);
    $saId = (int)db()->lastInsertId();
    seed_request_message($shid, $saId, 'ZZ seed request');
    $seeded = db_query("SELECT sender, body, read_by_admin, read_by_guest FROM booking_messages WHERE addon_id=:a", [':a'=>$saId])->fetch();
    check('seed_request_message posts a guest opening message',
          $seeded && $seeded['sender']==='guest' && $seeded['body']==='ZZ seed request');
    check('seed_request_message leaves it unread for admin, read for guest',
          $seeded && !$seeded['read_by_admin'] && $seeded['read_by_guest']);
    db_query("DELETE FROM booking_messages WHERE addon_id=:a", [':a'=>$saId]);
    db_query("DELETE FROM booking_addons WHERE id=:a", [':a'=>$saId]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/portal_logic.php`
Expected: FAIL lines / a fatal `Call to undefined function venue_maps_link()` (or `fetch_venue_stay` / `seed_request_message`).

- [ ] **Step 3: Implement the three helpers**

In `includes/booking.php`, add after the `fetch_guest_board()` function:

```php
/**
 * Per-property stay info + location. Always returns the six keys as strings
 * (blank when the venue is null, missing, or the columns predate the migration).
 */
function fetch_venue_stay(?int $venueId): array {
    $blank = ['address'=>'','maps_url'=>'','stay_wifi'=>'','stay_checkout'=>'','stay_house_rules'=>'','stay_area_guide'=>''];
    if ($venueId === null) return $blank;
    try {
        $row = db_query(
            "SELECT address, maps_url, stay_wifi, stay_checkout, stay_house_rules, stay_area_guide
             FROM venues WHERE id = :id",
            [':id' => $venueId]
        )->fetch();
    } catch (Throwable $e) {
        return $blank; // columns absent pre-migration
    }
    if (!$row) return $blank;
    foreach ($blank as $k => $_) { $blank[$k] = trim((string)($row[$k] ?? '')); }
    return $blank;
}

/**
 * A tappable Google Maps URL for a venue-stay row: the owner's stored maps_url
 * if present, else a Maps search for the address, else '' (render no link).
 */
function venue_maps_link(array $stay): string {
    $url = trim((string)($stay['maps_url'] ?? ''));
    if ($url !== '') return $url;
    $addr = trim((string)($stay['address'] ?? ''));
    if ($addr !== '') return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($addr);
    return '';
}

/**
 * Open a concierge request's message thread by posting the guest's request as
 * the first message. Unread for staff, read for the guest who just sent it.
 */
function seed_request_message(int $holdId, int $addonId, string $body): void {
    db_query(
        "INSERT INTO booking_messages (hold_id, addon_id, sender, body, read_by_guest, read_by_admin)
         VALUES (:h, :a, 'guest', :b, TRUE, FALSE)",
        [':h' => $holdId, ':a' => $addonId, ':b' => $body]
    );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/portal_logic.php`
Expected: all new checks PASS, existing checks still PASS, exit summary shows 0 failures.

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php tests/portal_logic.php
git commit -m "feat(portal): venue-stay helpers + request-thread seeding, with tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Requests auto-start a conversation (`booking-addon.php` + `booking-manage.js`)

**Files:**
- Modify: `api/booking-addon.php` (the `try { ... }` insert block, ~lines 64-78)
- Modify: `js/booking-manage.js` (the `if (data.ok)` branch)

- [ ] **Step 1: Seed the thread and return a redirect in the API**

In `api/booking-addon.php`, replace the whole `try { ... } catch { ... }` block that inserts the addon with:

```php
try {
    db_query(
        "INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for)
         VALUES (:h, :k, :t, :d, :sf)",
        [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details, ':sf'=>$schedSql]
    );
    $addonId = (int)db()->lastInsertId();

    // Auto-start a conversation for this request so guest + staff manage it in one place.
    // Never fail the request if the messages table is unavailable.
    $redirect = null;
    try {
        seed_request_message((int)$hold['id'], $addonId, $details);
        $ref = make_guest_ref((int)$hold['id']); // re-sign; never trust the posted ref for a URL
        $redirect = '/booking.php?ref=' . urlencode($ref) . '&view=messages&thread=' . $addonId;
    } catch (Throwable $e) {
        error_log('[booking-addon] thread seed failed: ' . $e->getMessage());
    }

    if (function_exists('send_addon_request_notification')) {
        send_addon_request_notification($hold, ['kind'=>$kind,'details'=>$details]);
    }
    echo json_encode(['ok'=>true, 'redirect'=>$redirect]);
} catch (Throwable $e) {
    error_log('[booking-addon] failed: ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save your request. Please try again.']);
}
```

- [ ] **Step 2: Follow the redirect in the JS on success**

In `js/booking-manage.js`, replace the success branch:

```js
        if (data.ok) {
          if (status) { status.textContent = form.getAttribute('data-bm-success') || 'Request sent — we’ll be in touch by email.'; status.className = 'bm-status ok'; }
          var next = data.redirect;
          setTimeout(function () { window.location = next ? next : window.location.href.split('#')[0]; }, 1200);
        } else {
```

(Requests now land in their Messages thread; forms without a redirect still reload as before.)

- [ ] **Step 3: Manual verification (local)**

Run: `php -S localhost:8765 router.php` (background) then, in a PHP shell, seed a portal URL:
`php -r 'require "includes/db.php"; require "includes/booking.php"; $h=(int)db()->query("SELECT id FROM holds WHERE status IN (\'pending\',\'confirmed\') ORDER BY id DESC LIMIT 1")->fetchColumn(); echo "/booking.php?ref=".urlencode(make_guest_ref($h))."&view=home\n";'`

Open that URL in the in-app browser, submit a "Make a request" (or Laundry) request, and confirm:
- the page navigates to `view=messages&thread=<id>`
- the thread shows the request text as the first (guest) message.
Expected: PASS. Delete the seeded addon + message afterward.

- [ ] **Step 4: Commit**

```bash
git add api/booking-addon.php js/booking-manage.js
git commit -m "feat(portal): every concierge request opens a message thread

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Admin — per-property Stay info card + retire global Stay Info

**Files:**
- Modify: `admin/venue-edit.php` (new POST handler + new card)
- Delete: `admin/stay-info.php`
- Modify: `admin/_layout.php` (remove the `stay_info` nav item at line 99)

- [ ] **Step 1: Add the `save_stay` handler**

In `admin/venue-edit.php`, after the `if ($action === 'save_content' && !$isNew) { ... }` block, add:

```php
    if ($action === 'save_stay' && !$isNew) {
        db_query(
            'UPDATE venues SET address=:addr, maps_url=:maps, stay_wifi=:wifi,
                    stay_checkout=:co, stay_house_rules=:hr, stay_area_guide=:ag, updated_at=NOW()
             WHERE id=:id',
            [
                ':addr' => trim($_POST['address'] ?? ''),
                ':maps' => trim($_POST['maps_url'] ?? ''),
                ':wifi' => trim($_POST['stay_wifi'] ?? ''),
                ':co'   => trim($_POST['stay_checkout'] ?? ''),
                ':hr'   => trim($_POST['stay_house_rules'] ?? ''),
                ':ag'   => trim($_POST['stay_area_guide'] ?? ''),
                ':id'   => $id,
            ]
        );
        audit_log('venue.stay', 'venue', $id);
        header("Location: /admin/venue-edit.php?id={$id}&saved=1");
        exit;
    }
```

- [ ] **Step 2: Add the "Stay info & location" card**

In `admin/venue-edit.php`, inside the `<?php if (!$isNew): ?>` region (place it right after the "Page Content" card's closing `</div>` that ends that card), add:

```php
<div class="card">
  <div class="card__head"><span class="card__title">Stay info &amp; location</span></div>
  <div class="card__body">
    <p style="margin:0 0 14px;font-size:13px;color:var(--muted)">Shown to guests in the app for this property. Leave a field blank to hide it.</p>
    <form method="POST" action="/admin/venue-edit?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_stay">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Address <span style="color:var(--muted);font-weight:400">(shown in the booking box)</span></label>
        <input type="text" name="address" value="<?= e($venue['address'] ?? '') ?>" placeholder="e.g. Zuri Beach House, Vipingo Ridge, Kilifi County" style="width:100%;max-width:640px;padding:8px 10px">
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Google Maps link <span style="color:var(--muted);font-weight:400">(optional)</span></label>
        <input type="url" name="maps_url" value="<?= e($venue['maps_url'] ?? '') ?>" placeholder="Paste a Google Maps share link (overrides the address search)" style="width:100%;max-width:640px;padding:8px 10px">
        <p style="font-size:12px;color:var(--muted);margin:4px 0 0">If blank, the map pin searches Google Maps for the address above.</p>
      </div>

      <?php
        $__stay = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
        foreach ($__stay as $__k => $__label): ?>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px"><?= e($__label) ?></label>
        <textarea name="<?= e($__k) ?>" rows="3" style="width:100%;max-width:640px;padding:8px 10px;font-family:inherit;line-height:1.6"><?= e($venue[$__k] ?? '') ?></textarea>
      </div>
      <?php endforeach; ?>

      <button type="submit" class="btn-primary btn-sm">Save Stay Info</button>
    </form>
  </div>
</div>
```

- [ ] **Step 3: Delete the global Stay Info page and its nav item**

```bash
git rm admin/stay-info.php
```

In `admin/_layout.php`, delete the entire Stay Info `<a ...>` nav link at line ~99 (the one with `$activeMenu==='stay_info'` / `href="/admin/stay-info.php"`).

- [ ] **Step 4: Manual verification (local)**

With `php -S localhost:8765 router.php` running, log into `/admin`, open a property via Properties → Edit, fill the new "Stay info & location" card, Save, and confirm the values persist on reload. Confirm the sidebar no longer shows "Stay Info" and `/admin/stay-info.php` 404s.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/venue-edit.php admin/_layout.php
git commit -m "feat(admin): per-property stay info + location on venue edit; retire global page

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Portal chrome — remove marketing nav, gate footer, greeting topbar

**Files:**
- Modify: `booking.php` (the include block ~line 101-103 and the topbar block ~line 197)
- Modify: `includes/footer.php`

- [ ] **Step 1: Drop the marketing header and set the portal-chrome flag**

In `booking.php`, find:

```php
include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/header.php';
```

Change to (remove the header include; head.php stays for `<head>` meta/CSS):

```php
include __DIR__ . '/includes/head.php';
```

**Note:** This codebase never emits an explicit `</head>`/`<body>` — `head.php` opens `<head>` and the browser implicitly starts `<body>` at the first flow content (previously `header.php`'s `<nav>`, now `booking.php`'s `<div class="pa-app">`). Removing the header is safe; **do not** add a `<body>` tag to compensate.

- [ ] **Step 2: Compute the greeting and use it as the home topbar title**

In `booking.php`, replace the topbar block:

```php
  <?php
    $__titles = ['home'=>'Your stay','activities'=>'Activities','messages'=>'Messages'];
    $__t = $hold ? ($__titles[$view] ?? 'Your stay') : 'Your booking';
  ?>
  <div class="pa-topbar"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= e($__t) ?></div></div>
```

with:

```php
  <?php
    $__first = trim((string)($hold['guest_name'] ?? ''));
    $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
    $__titles = ['home'=>'Karibu, ' . $__first, 'activities'=>'Activities', 'messages'=>'Messages'];
    $__t = $hold ? ($__titles[$view] ?? ('Karibu, ' . $__first)) : 'Your booking';
  ?>
  <div class="pa-topbar"><div class="pa-topbar__eyebrow">Tribal Sand</div><div class="pa-topbar__title"><?= e($__t) ?></div></div>
```

- [ ] **Step 3: Set `$portal_chrome` before the footer include**

In `booking.php`, find:

```php
<?php $hide_floating_chat = true; // portal is app-like; suppress the site chat bubble (it overlaps the bottom nav) ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Change to:

```php
<?php $hide_floating_chat = true; // portal is app-like; suppress the site chat bubble (it overlaps the bottom nav) ?>
<?php $portal_chrome = true;      // suppress marketing footer + cookie banner; keep the success-modal JS ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

- [ ] **Step 4: Gate the marketing chrome in the footer, keep the modal JS**

In `includes/footer.php`, wrap the visible marketing `<footer class="ts-footer">...</footer>` block **and** the cookie-banner block (`<div class="cookie-banner" ...>` plus its associated `<script>` that toggles it) in a guard, so they render only on non-portal pages:

```php
<?php if (!($portal_chrome ?? false)): ?>
<footer class="ts-footer">
  <!-- ...existing footer markup unchanged... -->
</footer>
<!-- ...existing cookie-banner markup + its toggle <script> unchanged... -->
<?php endif; ?>
```

Leave the `window.showSuccessModal = function(...)` script (and any other portal-shared JS) **outside** the guard so it always loads. If the modal JS currently sits inside the same `<script>` as the cookie-banner toggle, split it into its own always-emitted `<script>` block.

- [ ] **Step 5: Manual verification (local)**

Open the seeded portal URL from Task 3. Confirm: no fixed teal marketing nav at the top; the app topbar reads "Karibu, {first}" on Home and "Activities"/"Messages" on those tabs; no marketing footer or cookie banner; the booking success modal (cancel flow, or a request confirmation) still works.
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add booking.php includes/footer.php
git commit -m "feat(portal): drop marketing nav/footer in-app; greeting-led topbar

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Booking box — address → Google Maps row

**Files:**
- Modify: `includes/app/status-header.php`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Render the address row from venue-stay data**

In `includes/app/status-header.php`, after the closing `</dl>` and before the closing `</div>` of `.pa-status`, insert:

```php
  <?php
    $__stay = fetch_venue_stay(isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null);
    $__maps = venue_maps_link($__stay);
    $__addrText = $__stay['address'] !== '' ? $__stay['address'] : ($hold['venue_name'] ?? '');
  ?>
  <?php if ($__maps !== '' && $__addrText !== ''): ?>
  <a class="pa-maps" href="<?= e($__maps) ?>" target="_blank" rel="noopener">
    <span class="pa-maps__pin" aria-hidden="true">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-5.2-6-10a6 6 0 1 1 12 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
    </span>
    <span class="pa-maps__text">
      <?php if (($hold['venue_name'] ?? '') !== ''): ?><b><?= e($hold['venue_name']) ?></b><br><?php endif; ?>
      <?= e($__addrText) ?>
      <span class="pa-maps__go">Open in Google Maps →</span>
    </span>
  </a>
  <?php endif; ?>
```

- [ ] **Step 2: Add the address-row styles**

Append to `css/portal-app.css`:

```css
/* ── Booking-box address → Google Maps ── */
.pa-maps{display:flex;align-items:center;gap:12px;margin:12px -6px 0;padding:12px;border-radius:12px;background:#f3f8f9;text-decoration:none;color:var(--pa-teal);border-top:1px solid var(--pa-line);}
.pa-maps__pin{width:40px;height:40px;flex:0 0 auto;border-radius:11px;background:#e0eef0;display:flex;align-items:center;justify-content:center;color:var(--pa-teal);}
.pa-maps__text{font-size:14px;line-height:1.4;color:var(--pa-ink);}
.pa-maps__text b{color:var(--pa-teal-d);}
.pa-maps__go{display:block;margin-top:2px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--pa-teal);}
```

- [ ] **Step 3: Manual verification (local)**

Set an `address` (and optionally a `maps_url`) on the seeded hold's venue via the admin Stay info card (Task 4). Reload the portal Home; confirm the address row shows in the booking box and the pin opens Google Maps in a new tab. Then blank the address + maps_url and confirm the row disappears.
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/app/status-header.php css/portal-app.css
git commit -m "feat(portal): address row in booking box, opens Google Maps

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: "Your stay" reads per-property values

**Files:**
- Modify: `includes/app/_stay_essentials.php`

- [ ] **Step 1: Source stay values from the venue instead of global settings**

Replace the PHP head of `includes/app/_stay_essentials.php`:

```php
<?php
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__vals = []; foreach ($__info as $__k => $__label) { $__vals[$__k] = trim((string)setting($__k, '')); }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } }
?>
```

with:

```php
<?php
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__stayVals = fetch_venue_stay(isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null);
$__vals = []; foreach ($__info as $__k => $__label) { $__vals[$__k] = $__stayVals[$__k] ?? ''; }
$__any = false; foreach ($__vals as $__v) { if ($__v !== '') { $__any = true; break; } }
?>
```

(The rest of the partial — the `<details>` block that iterates `$__info`/`$__vals` — is unchanged.)

- [ ] **Step 2: Manual verification (local)**

On the seeded hold's venue, set distinct Wi-Fi/check-out/house-rules/area-guide values; reload the portal and expand "Your stay" — confirm the per-property values show. Blank them all and confirm the whole section hides.
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/app/_stay_essentials.php
git commit -m "feat(portal): Your stay pulls per-property values

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: "My trip" → "My Calendar" collapsible

**Files:**
- Modify: `includes/app/_trip.php`

- [ ] **Step 1: Wrap the itinerary in a `<details>` and rename the heading**

In `includes/app/_trip.php`, replace the two heading lines:

```php
<h2 class="pa-h2">My trip</h2>
<p class="pa-sub">Your day-by-day itinerary. Tours and transfers you’ve booked appear automatically.</p>
```

with an opening `<details>` that carries the title:

```php
<details class="pa-details" open>
<summary class="pa-details__s">My Calendar</summary>
<div style="padding-top:2px">
<p class="pa-sub" style="margin-top:0">Your day-by-day itinerary. Tours and transfers you’ve booked appear automatically.</p>
```

Then, at the very end of the file, **after** the closing `</script>` (the add-to-plan script), add the closing tags:

```php
</div>
</details>
```

(The `<div class="pa-planday">` loop, the "+ Add to plan" form, and the script in between are unchanged. Keep `open` so first-time guests see the calendar; it still collapses on tap.)

- [ ] **Step 2: Manual verification (local)**

Reload the portal Home. Confirm the section header reads "My Calendar", it collapses/expands on tap, and "+ Add to plan" still adds an item (which reloads and shows in the list).
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/app/_trip.php
git commit -m "feat(portal): rename My trip to My Calendar, make it collapsible

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: "Need something?" tiles — Make a request, Activities, Messages

**Files:**
- Modify: `includes/app/_services.php`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Update the tile set and subheading**

In `includes/app/_services.php`, replace the `$__tiles` line:

```php
$__tiles = ['laundry'=>'Laundry','housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','transfer'=>'Transfer','other'=>'Something else'];
```

with (drop `amenities`; relabel `other` → "Make a request"):

```php
$__tiles = ['laundry'=>'Laundry','housekeeping'=>'Housekeeping','other'=>'Make a request','maintenance'=>'Maintenance','restaurant'=>'Restaurant','transfer'=>'Transfer'];
```

Replace the subheading line:

```php
<p class="pa-sub">Tap what you need — our team confirms by return.</p>
```

with:

```php
<p class="pa-sub">Tap what you need — it opens a chat with our team.</p>
```

Add an `other` icon (a plus) so "Make a request" has one — in the `$__icons` array, add:

```php
  'other'        => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
```

(There is already an `'other'` key in `$__icons` — a chat bubble. **Replace** that existing `'other' => ...` line with the plus icon above so the tile reads as "add/new request", not "chat". The `$__kinds` map that builds the free-form form already contains `'other'=>'Something else'`; leave it — it only labels the textarea. Optionally change that label to `'other'=>'Your request'` for nicer copy.)

- [ ] **Step 2: Append the Activities and Messages navigation tiles**

In `includes/app/_services.php`, immediately after the `</div>` that closes `<div class="cx-grid">`, add:

```php
<?php
$__unreadMsg = 0;
try { $__unreadMsg = count_unread_guest((int)$hold['id']); } catch (Throwable $e) { $__unreadMsg = 0; }
$__refU = urlencode($ref);
?>
<div class="cx-grid" style="margin-top:10px">
  <a class="cx-tile cx-tile--nav" href="/booking.php?ref=<?= e($__refU) ?>&amp;view=activities">
    <span aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/></svg></span>
    Activities
    <span class="cx-tile__go" aria-hidden="true">→</span>
  </a>
  <a class="cx-tile cx-tile--nav" href="/booking.php?ref=<?= e($__refU) ?>&amp;view=messages">
    <span aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 4z"/></svg></span>
    Messages
    <?php if ($__unreadMsg > 0): ?><span class="cx-tile__badge"><?= (int)$__unreadMsg ?></span><?php endif; ?>
  </a>
</div>
```

- [ ] **Step 3: Style the navigation tiles**

Append to `css/portal-app.css`:

```css
/* ── Concierge nav tiles (Activities / Messages) ── */
.cx-tile{position:relative;}
.cx-tile--nav{background:var(--pa-teal-d);border-color:var(--pa-teal-d);color:#fff;}
.cx-tile--nav svg{color:var(--pa-gold);}
.cx-tile__go{position:absolute;top:12px;right:13px;color:var(--pa-gold);font-size:15px;}
.cx-tile__badge{position:absolute;top:10px;right:10px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:#e05b49;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;}
```

- [ ] **Step 4: Manual verification (local)**

Reload the portal Home. Confirm: six request tiles with "Make a request" (plus icon) where amenities was; two dark tiles below — Activities (opens the Activities tab) and Messages (opens Messages, with an unread badge when there are unread guest messages). Tap "Make a request", send it, and confirm it lands in a Messages thread (Task 3 behavior).
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/app/_services.php css/portal-app.css
git commit -m "feat(portal): Make a request + Activities/Messages shortcut tiles

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: Home order + guest board (greeting removed)

**Files:**
- Modify: `includes/app/home.php`
- Modify: `includes/app/_greeting_board.php`

- [ ] **Step 1: Reorder the Home includes**

Replace the body of `includes/app/home.php`:

```php
<?php /** Home — merged concierge + stay. Expects $hold, $ref, $status. */ ?>
<?php include __DIR__ . '/_greeting_board.php'; ?>
<?php include __DIR__ . '/_trip.php'; ?>
<?php include __DIR__ . '/_services.php'; ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>
```

with (booking box is included by `booking.php` before this file; order: Your stay → My Calendar → Need something? → Guest board):

```php
<?php /** Home — stay essentials + calendar + concierge + board. Expects $hold, $ref, $status. */ ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>
<?php include __DIR__ . '/_trip.php'; ?>
<?php include __DIR__ . '/_services.php'; ?>
<?php include __DIR__ . '/_greeting_board.php'; ?>
```

- [ ] **Step 2: Remove the greeting line from the guest board partial**

In `includes/app/_greeting_board.php`, delete the greeting `<div>` line (the "Karibu" one) so the partial renders only the board. Also drop the now-unused `$__first` computation. Change the PHP head:

```php
<?php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
$__first = trim((string)$hold['guest_name']); $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest';
?>
<div style="font-family:'Cormorant Garamond',serif;font-size:24px;margin:4px 0 12px">Karibu, <?= e($__first) ?></div>
<?php if ($__board): ?>
```

to:

```php
<?php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__board = fetch_guest_board($__venue); } catch (Throwable $e) { $__board = []; }
$__tagClass = ['update'=>'pa-tag--update','excursion'=>'pa-tag--excursion','promotion'=>'pa-tag--promotion'];
?>
<?php if ($__board): ?>
<div class="pa-h2" style="margin-top:20px">What's on</div>
<?php endif; ?>
<?php if ($__board): ?>
```

(The `.pa-grid` board loop that follows is unchanged. The board now sits at the bottom of Home under a "What's on" heading; when there are no posts, nothing renders.)

- [ ] **Step 3: Manual verification (local)**

Reload the portal Home and confirm the top-to-bottom order: greeting topbar → booking box (+address) → Your stay → My Calendar → Need something? tiles → guest board ("What's on"). Confirm no duplicate "Karibu" greeting appears in the body.
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/app/home.php includes/app/_greeting_board.php
git commit -m "feat(portal): new Home order; guest board moves to bottom, greeting to topbar

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 11: Full regression + final review

- [ ] **Step 1: Run the test suite**

Run: `php tests/portal_logic.php`
Expected: 0 failures.

Run: `php tests/manage_logic.php` and `php tests/convert_logic.php` if present.
Expected: 0 failures (these don't touch the changed code but confirm no regression).

- [ ] **Step 2: End-to-end portal walkthrough (local, in-app browser)**

With `php -S localhost:8765 router.php` running and a seeded portal URL:
- Home shows greeting topbar, booking box with working Maps link, Your stay (per-property), My Calendar (collapsible), Need something? (Make a request + Activities + Messages), guest board.
- Submit a request → lands in its Messages thread seeded with the request text.
- Activities tile → Activities tab; Messages tile → Messages tab (unread badge if applicable).
- No marketing nav/footer/cookie banner anywhere in the portal; success modal still works.
- A hold on a venue with blank stay info → Your stay + address hidden, no errors.

- [ ] **Step 3: Clean up any test data**

Delete seeded addons/messages/board posts created during manual checks:
`php -r 'require "includes/db.php"; db()->exec("DELETE FROM booking_messages WHERE body LIKE \'ZZ %\'; DELETE FROM booking_addons WHERE details LIKE \'ZZ %\';"); echo "cleaned\n";'`

- [ ] **Step 4: Request a final code review**

Use superpowers:requesting-code-review to dispatch a review of the branch against this plan and the spec; fix any findings before finishing the branch.

---

## Rollout notes (post-merge)

1. User runs `db/migrations/add_venue_stay_info.sql` via `/admin/migrate.php` on the Neon production DB.
2. Owner fills per-property Stay info & location on each Property edit page (global values were seeded as a starting point, so nothing is blank on deploy).
