# Shared Booking Portal — C-1 (Foundation + Requests) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A per-booking "share reservation" toggle that lets co-guests open the full guest portal via their existing `?g=` link and make service requests attributed to their name.

**Architecture:** Reuse ONE portal token variable `$ref` carrying **either** a lead magic-link ref **or** a co-guest `g`-token; the resolvers try both formats, so every existing view/form/JS that already threads `$ref` works for co-guests unchanged. Co-guest access + `g`-token endpoints are gated by `holds.share_reservation`. Requests stamp `booking_addons.requested_by` (FK to `checkin_guests`). No portal-wide refactor; booking-management (cancel/change) stays lead-only.

**Tech Stack:** Vanilla PHP 8.5, PostgreSQL/PDO, server-rendered portal, logic tests via `php tests/checkin_logic.php`.

**Spec:** `docs/superpowers/specs/2026-08-11-checkin-shared-portal-design.md` (this is stage **C-1**; C-2 = bill + messaging, planned after C-1 lands).

**Branch:** `feature/checkin-shared-portal` (created; spec committed; stacked on B).

**Conventions:** `can_view_guest_docs` gating; `verify_csrf()`; `verify_guest_ref` / `verify_guest_pass_token`; pre-migration `*_supported()` guards; `audit_log()`.

---

## Task 1: Migration — share flag + request attribution

**Files:**
- Create: `db/migrations/add_shared_portal.sql`

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_shared_portal.sql`:
```sql
-- Shared booking portal (C-1): per-booking share toggle + request attribution.
-- Idempotent. requested_by references the checkin_guests roster (A/B).
ALTER TABLE holds          ADD COLUMN IF NOT EXISTS share_reservation BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS requested_by INT REFERENCES checkin_guests(id) ON DELETE SET NULL;
```

- [ ] **Step 2: Apply to the dev DB**

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_shared_portal.sql")); echo "applied\n";'`
Expected: `applied`

- [ ] **Step 3: Verify the columns**

Run: `php -r 'require "includes/db.php"; db_query("SELECT share_reservation FROM holds LIMIT 0"); db_query("SELECT requested_by FROM booking_addons LIMIT 0"); echo "columns ok\n";'`
Expected: `columns ok`

- [ ] **Step 4: Commit**
```bash
git add db/migrations/add_shared_portal.sql
git commit -m "feat(portal): migration for share_reservation + request attribution"
```

---

## Task 2: Helpers — support guards, name, actor resolver, lead-seed

**Files:**
- Modify: `includes/booking.php`
- Modify: `includes/checkin.php`
- Test: `tests/checkin_logic.php`

- [ ] **Step 1: Write the failing test** (pure helpers only)

In `tests/checkin_logic.php`, before the final `echo $failures ...`:
```php
// ── Shared portal: display name (pure) ──────────────────────────────────────
check('display name first word',  guest_display_name(['passport_name'=>'Jess Achieng']) === 'Jess');
check('display name blank=Guest',  guest_display_name(['passport_name'=>'']) === 'Guest');
check('display name null=Guest',   guest_display_name(null) === 'Guest');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/checkin_logic.php`
Expected: fatal — `Call to undefined function guest_display_name()`

- [ ] **Step 3: Implement the helpers**

In `includes/booking.php`, near `resolve_booking_by_ref()` (around line 110), add:
```php
/** True once add_shared_portal.sql is applied (holds.share_reservation). Cached. */
function share_reservation_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT share_reservation FROM holds LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** True if this hold has sharing turned on (and the column exists). */
function share_reservation_on(array $hold): bool {
    return share_reservation_supported() && !empty($hold['share_reservation']);
}

/** True once booking_addons.requested_by exists. Cached. */
function addon_requested_by_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_query('SELECT requested_by FROM booking_addons LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/** First name for attribution display, else "Guest". Pure. */
function guest_display_name(?array $g): string {
    $n = trim((string)($g['passport_name'] ?? ''));
    return $n === '' ? 'Guest' : explode(' ', $n)[0];
}

/**
 * Resolve a portal token (from ?ref= / ?g= / a posted field) to the acting party.
 * Returns ['hold'=>row, 'guest_id'=>int, 'is_lead'=>bool] or false.
 *   - a valid lead ref  → the lead's checkin_guests row (seeded if needed)
 *   - a valid g-token   → that co-guest, but ONLY if the booking is shared
 */
function resolve_portal_actor(string $token): array|false {
    $token = trim($token);
    if (($holdId = verify_guest_ref($token)) !== false) {
        $hold = fetch_hold_for_guest($holdId);
        if (!$hold) return false;
        return ['hold' => $hold, 'guest_id' => checkin_ensure_lead_guest_id($holdId), 'is_lead' => true];
    }
    if (($r = verify_guest_pass_token($token)) !== false) {
        [$holdId, $guestId] = $r;
        $hold = fetch_hold_for_guest($holdId);
        if (!$hold || !share_reservation_on($hold)) return false;
        return ['hold' => $hold, 'guest_id' => $guestId, 'is_lead' => false];
    }
    return false;
}
```

In `includes/checkin.php`, near `checkin_target_guest_id()` (around line 234), add:
```php
/** The hold's lead checkin_guests row id, seeding it if absent. */
function checkin_ensure_lead_guest_id(int $holdId): int {
    db_query("INSERT INTO checkin_guests (hold_id, is_lead) VALUES (:h, TRUE) ON CONFLICT (hold_id) WHERE is_lead DO NOTHING", [':h' => $holdId]);
    return (int) db_query('SELECT id FROM checkin_guests WHERE hold_id = :h AND is_lead', [':h' => $holdId])->fetchColumn();
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/checkin_logic.php`
Expected: the 3 new lines PASS; suite `ALL PASS`. (`resolve_portal_actor` / `checkin_ensure_lead_guest_id` are DB-backed and covered by the Task 7 E2E.)

- [ ] **Step 5: Commit**
```bash
git add includes/booking.php includes/checkin.php tests/checkin_logic.php
git commit -m "feat(portal): share-support guards, display name, portal-actor resolver"
```

---

## Task 3: The "Share reservation" admin toggle

**Files:**
- Modify: `admin/booking.php` (POST dispatcher — add `share_toggle` after `guest_count_set`)
- Modify: `admin/_ws_checkin.php` (button next to "Require check-in")

- [ ] **Step 1: Add the action**

In `admin/booking.php`, immediately after the `guest_count_set` action block (before the guest-management block added in B), insert:
```php
    if ($act === 'share_toggle' && checkin_supported() && can_view_guest_docs($holdId) && share_reservation_supported()) {
        $on = ($_POST['share_reservation'] ?? '') === '1';
        db_query('UPDATE holds SET share_reservation = :s WHERE id = :id', [':s' => $on, ':id' => $holdId]);
        audit_log('portal.share_toggle', 'hold', $holdId, $on ? 'on' : 'off');
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => $on ? 'Sharing turned on — co-guests can use the portal.' : 'Sharing turned off.'];
        header("Location: /admin/booking.php?hold=$holdId&tab=checkin"); exit;
    }
```

- [ ] **Step 2: Add the toggle button**

In `admin/_ws_checkin.php`, inside the owner controls `<div>` (the one holding the "Require check-in" form, around line 44-50), and change its guard from `is_owner()` to `can_view_guest_docs`. Specifically replace:
```php
  <?php if (is_owner()): ?>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
```
with:
```php
  <?php if ($__canDocs): ?>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
```
Then, immediately after the "Require check-in" `</form>` (around line 50), add:
```php
    <?php if (share_reservation_supported()): ?>
    <form method="POST" action="/admin/booking.php?hold=<?= $holdId ?>&tab=checkin" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="hold_id" value="<?= $holdId ?>">
      <input type="hidden" name="action" value="share_toggle">
      <input type="hidden" name="share_reservation" value="<?= !empty($hold['share_reservation']) ? '0' : '1' ?>">
      <button class="btn-sm btn-outline"><?= !empty($hold['share_reservation']) ? 'Stop sharing' : 'Share reservation' ?></button>
    </form>
    <?php endif; ?>
```

Note: the guest-count and require-checkin forms inside this block are still owner-relevant, but `can_view_guest_docs` (owner + venue manager) is the correct audience for the shared block per the spec. (`guest_count_set` / `checkin_toggle` actions keep their own `is_owner()` server gate, so widening the *display* here does not widen those actions.)

- [ ] **Step 3: Verify parse**

Run: `php -l admin/booking.php && php -l admin/_ws_checkin.php`
Expected: `No syntax errors detected` (both)

- [ ] **Step 4: Commit**
```bash
git add admin/booking.php admin/_ws_checkin.php
git commit -m "feat(portal): admin share-reservation toggle"
```

---

## Task 4: Co-guest portal access (booking.php)

**Files:**
- Modify: `booking.php` (the `?g=` branch ~30-49, and the cancel guard ~94)
- Modify: `includes/app/checkin-guest.php` (done-card "continue" link)

- [ ] **Step 1: Give shared, checked-in co-guests the full portal**

In `booking.php`, replace the co-guest branch (the block starting `// ── Co-guest self-service branch` around line 30, through its closing `}` at ~line 49) with:
```php
// ── Co-guest branch (opened via a per-guest ?g= link, or a ?ref= carrying a g-token) ──
$gtoken = trim((string)($_GET['g'] ?? $_POST['g'] ?? ''));
if ($gtoken === '' && $ref !== '' && verify_guest_ref($ref) === false) $gtoken = $ref; // internal nav carries the g-token in ref=
$isCoGuest = false;
$meGuestId = 0;
if (!$holdId && $gtoken !== '' && ($gc = verify_guest_pass_token($gtoken)) !== false) {
    [$gHoldId, $gGuestId] = $gc;
    $gHold = fetch_hold_for_guest($gHoldId);
    $me = null;
    foreach (fetch_checkin_guests($gHoldId) as $row) { if ((int)$row['id'] === $gGuestId) { $me = $row; break; } }
    if ($gHold && $me) {
        $meDone = (!checkin_required($gHold)) || (checkin_guest_passport_complete($me) && checkin_guest_waiver_signed($me));
        // Shared + this co-guest has finished check-in → give them the full portal.
        if (share_reservation_on($gHold) && $meDone) {
            $hold = $gHold; $holdId = $gHoldId;
            $ref = $gtoken;             // the whole portal threads $ref; a g-token here makes every view work for the co-guest
            $isCoGuest = true; $meGuestId = $gGuestId;
        } elseif (checkin_required($gHold)) {
            // Not shared yet, or still needs to check in → the check-in screen (unchanged).
            $hold = $gHold; $holdId = $gHoldId;
            $page_title = 'Guest check-in · Tribal Sand'; $page_desc = 'Complete your check-in.';
            $page_url = site_url('booking'); $noindex = true;
            $hide_floating_chat = true; $portal_chrome = true;
            include __DIR__ . '/includes/head.php';
            include __DIR__ . '/includes/app/checkin-guest.php';
            include __DIR__ . '/includes/footer.php';
            exit;
        }
    }
}
```

- [ ] **Step 2: Never let a co-guest cancel or manage the booking**

In `booking.php`, find the cancel-eligibility block (around line 79, `if ($hold && !$error) {`). Change its opening condition to exclude co-guests:
```php
if ($hold && !$error) {
```
becomes:
```php
if ($hold && !$error && !$isCoGuest) {
```
This leaves `$can_cancel` false for co-guests, so the cancel card (`includes/app/home.php:29`) is hidden and the cancel POST handler (`booking.php:94`, guarded by `$can_cancel`) is a no-op for them. (The change-dates endpoint `api/booking-change.php` already rejects a g-token: `resolve_booking_by_ref` only accepts a `TS-` ref.)

- [ ] **Step 3: Make the co-guest's own check-in gate per-guest**

In `booking.php`, the check-in gate (around line 118) is lead-centric. Leave it as-is for the lead; it does not apply to the co-guest branch (a co-guest only reaches the router when `$meDone` is already true in Step 1). No change needed here — this step is a verification note: confirm `$checkin_gate` is only consulted on the ref/lead path.

- [ ] **Step 4: Add a "continue to your stay" link on the co-guest done card (shared only)**

In `includes/app/checkin-guest.php`, inside the `$done` card (after the "Download my signed waiver" link, around line 39), add:
```php
        <?php if (share_reservation_on($hold)): ?>
        <a class="pa-btn pa-btn--primary" href="/booking.php?g=<?= e($gtoken) ?>&view=home" style="margin-top:12px">Continue to your stay &rarr;</a>
        <?php endif; ?>
```

- [ ] **Step 5: Verify parse + a quick manual smoke**

Run: `php -l booking.php && php -l includes/app/checkin-guest.php`
Expected: `No syntax detected` (both). (Full flow verified in Task 7.)

- [ ] **Step 6: Commit**
```bash
git add booking.php includes/app/checkin-guest.php
git commit -m "feat(portal): co-guests reach the full portal when a booking is shared"
```

---

## Task 5: Requests attributed to the acting guest

**Files:**
- Modify: `api/booking-addon.php` (auth + attribution + redirect)
- Modify: `includes/booking.php` (`insert_booking_addon`, `fetch_booking_addons`)

- [ ] **Step 1: Stamp `requested_by` in the insert helper**

In `includes/booking.php`, in `insert_booking_addon()` (around line 432), after the `addon_assigned_supported()` line, add:
```php
    if (addon_requested_by_supported()) { $cols[] = 'requested_by'; $vals[] = ':rb'; $p[':rb'] = $d['requested_by'] ?? null; }
```

- [ ] **Step 2: Return the requester's name from the fetch**

In `includes/booking.php`, replace `fetch_booking_addons()` (around line 136) with:
```php
/** Add-ons already recorded against a hold (for display), with requester name when attributed. */
function fetch_booking_addons(int $holdId): array {
    $join = addon_requested_by_supported()
        ? "LEFT JOIN checkin_guests cg ON cg.id = ba.requested_by"
        : "";
    $sel  = addon_requested_by_supported()
        ? ", cg.passport_name AS requested_by_name, cg.is_lead AS requested_by_is_lead"
        : "";
    return db_query(
        "SELECT ba.*, t.name AS tour_name{$sel}
         FROM booking_addons ba
         LEFT JOIN tours t ON t.id = ba.tour_id
         {$join}
         WHERE ba.hold_id = :id ORDER BY ba.created_at DESC",
        [':id' => $holdId]
    )->fetchAll();
}
```

- [ ] **Step 3: Auth via `resolve_portal_actor` + stamp + token-aware redirect**

In `api/booking-addon.php`, replace the auth line (line 13):
```php
$hold = resolve_booking_by_ref($str($data['ref'] ?? ''));
if (!$hold) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
```
with:
```php
$actor = resolve_portal_actor($str($data['ref'] ?? $data['g'] ?? ''));
if (!$actor) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Booking not found.'])); }
$hold = $actor['hold'];
```
Then in the `insert_booking_addon([...])` array (around line 96-106), add:
```php
        'requested_by' => $actor['guest_id'],
```
Then replace the redirect line (around line 113-114):
```php
        $ref = make_guest_ref((int)$hold['id']); // re-sign; never trust the posted ref for a URL
        $redirect = '/booking.php?ref=' . urlencode($ref) . '&view=messages&thread=' . $addonId;
```
with:
```php
        // Re-mint the acting party's own token for the redirect (never trust the posted value).
        $tok = $actor['is_lead']
            ? make_guest_ref((int)$hold['id'])
            : make_guest_pass_token((int)$hold['id'], (int)$actor['guest_id']);
        $redirect = '/booking.php?ref=' . urlencode($tok) . '&view=messages&thread=' . $addonId;
```

- [ ] **Step 4: Verify parse + tests**

Run: `php -l api/booking-addon.php && php -l includes/booking.php && php tests/checkin_logic.php`
Expected: no syntax errors; `ALL PASS`.

- [ ] **Step 5: Commit**
```bash
git add api/booking-addon.php includes/booking.php
git commit -m "feat(portal): attribute service requests to the acting guest"
```

---

## Task 6: Show "Requested by <name>" to admin

**Files:**
- Modify: `admin/_ws_requests.php`

- [ ] **Step 1: Add the requester line to the request row's Details cell**

In `admin/_ws_requests.php`, the addon row's Details `<td>` ends (line 23) with the scheduled-time span. Find this exact fragment:
```php
<span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?></td>
```
and replace it with (same fragment, plus a requested-by line before `</td>`):
```php
<span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?><?php if (!empty($a['requested_by_name'])): ?><div class="text-muted" style="font-size:12px">Requested by <?= e(guest_display_name(['passport_name'=>$a['requested_by_name']])) ?><?= !empty($a['requested_by_is_lead']) ? ' (lead)' : '' ?></div><?php endif; ?></td>
```
(`$a` rows come from `fetch_booking_addons()`, which Task 5 taught to return `requested_by_name` / `requested_by_is_lead`.)

- [ ] **Step 2: Verify parse**

Run: `php -l admin/_ws_requests.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**
```bash
git add admin/_ws_requests.php
git commit -m "feat(portal): show request attribution (requested by name) in admin"
```

---

## Task 7: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole suite**

Run: `php tests/checkin_logic.php` then `for f in tests/*_logic.php; do printf "%s " "$(basename $f)"; php "$f" 2>&1 | tail -1; done`
Expected: check-in `ALL PASS`; only the known flaky `team_logic.php`.

- [ ] **Step 2: Create a shared throwaway booking with a checked-in co-guest**

```bash
php -r '
require "includes/db.php"; require "includes/booking.php"; require "includes/checkin.php";
$u=db_query("SELECT u.id FROM units u JOIN rooms r ON r.id=u.room_id LIMIT 1")->fetchColumn();
db_query("INSERT INTO holds (unit_id,check_in,check_out,guest_name,guest_email,status,expires_at,require_checkin,guest_count,share_reservation) VALUES (:u,CURRENT_DATE+40,CURRENT_DATE+43,\"ZZ Share\",\"zz-share@example.com\",\"confirmed\",now(),TRUE,2,TRUE)",[":u"=>(int)$u]);
$h=(int)db()->lastInsertId();
db_query("INSERT INTO checkin_guests (hold_id,is_lead,is_child,passport_name) VALUES (:h,TRUE,FALSE,\"ZZ Lead\")",[":h"=>$h]);
db_query("INSERT INTO checkin_guests (hold_id,is_lead,is_child,passport_name,passport_number,passport_file_key,waiver_signed_name,waiver_signed_at,waiver_signature) VALUES (:h,FALSE,FALSE,\"Jess CoGuest\",\"P1\",\"k/x.jpg\",\"Jess CoGuest\",now(),\"sig\")",[":h"=>$h]);
$cg=(int)db()->lastInsertId();
echo "HOLD=$h COGUEST=$cg TOKEN=".make_guest_pass_token($h,$cg)."\n";
'
```

- [ ] **Step 3: Co-guest portal + request (browser, dev server "tribalsand")**

Open `http://localhost:8765/booking.php?g=<TOKEN>`. Confirm: because sharing is on and this co-guest is checked in, the **full portal** loads (Home / Activities / Messages nav), NOT the check-in screen, and there is **no "Cancel My Booking" card** under Home → Your stay. Go to Home → Requests, submit a simple request (e.g. an "other" concierge ask). Confirm it succeeds and lands on the message thread.

- [ ] **Step 4: Confirm attribution in the DB + admin**

```bash
php -r '
require "includes/db.php"; require "includes/booking.php";
$h=(int)$argv[1];
foreach (fetch_booking_addons($h) as $a) echo $a["kind"]." · requested_by=".($a["requested_by"]??"NULL")." · name=".($a["requested_by_name"]??"-")."\n";
' <HOLD>
```
Expected: the new request shows `requested_by=<coguest id>` and `name=Jess CoGuest`. (Optionally, as owner, open `/admin/booking.php?hold=<HOLD>&tab=requests` and confirm the row shows "Requested by Jess".)

- [ ] **Step 5: Confirm sharing OFF reverts behavior**

```bash
php -r 'require "includes/db.php"; db_query("UPDATE holds SET share_reservation=FALSE WHERE id=:h",[":h"=>(int)$argv[1]]);' <HOLD>
```
Reload `http://localhost:8765/booking.php?g=<TOKEN>` → the co-guest now sees the **check-in done screen** (or check-in), not the portal. Re-enable with `share_reservation=TRUE` if continuing.

- [ ] **Step 6: Clean up**
```bash
php -r 'require "includes/db.php"; db_query("DELETE FROM holds WHERE id=:h",[":h"=>(int)$argv[1]]); echo "deleted\n";' <HOLD>
```

- [ ] **Step 7: Final commit (only if verification required tweaks)**
```bash
git add -A && git commit -m "test(portal): verify C-1 shared access + request attribution" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage (C-1 scope):**
- Share toggle → Task 1 (column) + Task 3 (admin UI/action).
- Co-guest full-portal access when shared → Task 4 (booking.php unified `$ref`), gated by `share_reservation_on`.
- Requests attributed by name → Task 1 (`requested_by`) + Task 2 (resolver, name helper) + Task 5 (stamp + fetch) + Task 6 (admin display).
- Cancel/change stays lead-only → Task 4 Step 2 (`!$isCoGuest`) + `booking-change.php` already ref-only.
- Backward-compat → `*_supported()` guards (Tasks 1, 2, 5); nullable `requested_by`.
- Deferred to C-2 (not this plan): bill view + `bill_items.guest_id`, messaging `sender_guest_id` + name labels.

**Placeholder scan:** none — every code step has full code; the one prose insertion (Task 6) resolves to an exact one-line snippet. Commands have expected output.

**Type/name consistency:** `resolve_portal_actor` returns `['hold','guest_id','is_lead']`, consumed identically in Task 5. `guest_display_name(?array)`, `share_reservation_on(array $hold)`, `share_reservation_supported()`, `addon_requested_by_supported()`, `checkin_ensure_lead_guest_id(int)` used consistently. The unified `$ref` (lead ref OR g-token) is set in Task 4 and relied on by the existing views/forms + the endpoint's `resolve_portal_actor` (Task 5).

**Migration ordering note:** `add_shared_portal.sql` must be applied with the other check-in migrations on the live DB. `requested_by` FKs `checkin_guests`, which the earlier migrations create.
