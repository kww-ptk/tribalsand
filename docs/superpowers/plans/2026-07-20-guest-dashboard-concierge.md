# Guest Dashboard + Concierge (v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reshape `booking.php` into an app (home dashboard + Concierge hub + Stay info), reusing the existing `booking_addons` request pipeline; add a small admin Stay-info editor and a "Mark done" action.

**Architecture:** `booking.php` gains a `?view=home|concierge|stay|manage` switch: a shared status header + one of four view includes. Concierge = new free-text `kind` values on the existing `booking_addons` table (housekeeping/amenities/maintenance/restaurant) posted through the existing `api/booking-addon.php`; Transfer/Activity tiles link into the existing pickers. Stay info = admin-editable `settings` keys. Admin gains a "Mark done" (`status='completed'`) action.

**Tech Stack:** Vanilla PHP 8.2, Postgres via PDO (`db_query()`), Turnstile (`verify_captcha`), admin auth (`require_login`/`verify_csrf`/`set_setting`/`setting`), brand `.bk-*` CSS. No framework/build. No PHP test framework — pure logic via `php`-CLI, DB/endpoint paths via `php -r` + curl, admin UI via auth-gate curl + login click-through.

**Key facts (verified):**
- `api/booking-addon.php` validates `kind IN ('tour','transfer','itinerary','other')`; the `else` branch already requires free-text `details` — so new service kinds just need adding to that whitelist.
- `admin/booking-request-action.php` actions `booking_addons` (Confirm/Decline/Cancel) with a `status='requested'` guard; `admin/holds.php` renders the per-hold request list.
- `set_setting($key,$val)` writes, `setting($key,$default)` reads (`includes/db.php`), both used by `admin/settings.php`.
- `booking.php` (~547 lines): after resolving the hold it renders a `.bk-card` summary + status notices, then (for pending/confirmed) includes `includes/booking-manage-actions.php` + a cancel card. `$ref`, `$hold`, `$status`, `$can_cancel`, `$cancel_blocked_reason` are in scope.
- Local dev: `php -S localhost:8765 router.php`, Neon-less local Postgres via `.env`; NO `DATABASE_URL` — use `php -r`, never `psql`. Turnstile unset locally → captcha dev-bypass.

**⚠️ Deploy:** production uses a SEPARATE Neon DB and does NOT auto-migrate. Task 1's migration MUST be run on prod (Neon console / `php bin/migrate.php` on Render) or concierge writes fail the CHECK.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `db/migrations/add_concierge.sql` | Widen `booking_addons` kind + status CHECKs (idempotent); append to `db/run-migrations.sql` |
| `api/booking-addon.php` | Accept new concierge kinds (free-text) |
| `admin/booking-request-action.php` | Add `completed` ("Mark done") action for add-ons |
| `admin/holds.php` | "Mark done" button on the per-hold request list |
| `admin/stay-info.php` | New — admin Stay-info editor |
| `admin/_layout.php` | Nav link to Stay info |
| `includes/app/status-header.php` | New — shared compact status header (extracted from booking.php) |
| `includes/app/home.php` | New — home tiles (Concierge, Stay info) + Manage link |
| `includes/app/concierge.php` | New — category grid + free-text forms + recent requests |
| `includes/app/stay.php` | New — stay info read view |
| `booking.php` | `?view=` routing around the shared header + view includes |

---

## Task 1: Migration — widen `booking_addons` constraints

**Files:** Create `db/migrations/add_concierge.sql`; modify `db/run-migrations.sql`

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_concierge.sql`:
```sql
-- Migration: concierge request types + completed status on booking_addons
-- Run: php bin/migrate.php db/migrations/add_concierge.sql
-- Idempotent — safe to re-run.

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other',
                    'housekeeping','amenities','maintenance','restaurant'));

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_status_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_status_check
    CHECK (status IN ('requested','confirmed','declined','cancelled','completed'));
```

- [ ] **Step 2: Append the same to `db/run-migrations.sql`**

Append the block from Step 1 (minus the `-- Run:` line) to the end of `db/run-migrations.sql` under a header `-- ── Concierge request types ──`.

- [ ] **Step 3: Apply + verify (NO psql — use the project runner)**

Run: `php bin/migrate.php db/migrations/add_concierge.sql`
Expected: `OK`, `All migrations completed successfully.`

Verify the new values are accepted and the old ones still valid:
```bash
php -r '
require "includes/db.php";
$uid=(int)db()->query("SELECT id FROM units WHERE is_active=TRUE LIMIT 1")->fetchColumn();
$sid=(int)db()->query("SELECT id FROM submissions ORDER BY id LIMIT 1")->fetchColumn();
$hid=create_hold_with_block($uid,$sid,"2028-01-01","2028-01-02","Concierge Mig","cmig@example.com");
db_query("INSERT INTO booking_addons (hold_id,kind,details) VALUES (:h,:k,:d)",[":h"=>$hid,":k"=>"housekeeping",":d"=>"test"]);
db_query("UPDATE booking_addons SET status=:s WHERE hold_id=:h",[":s"=>"completed",":h"=>$hid]);
$r=db_query("SELECT kind,status FROM booking_addons WHERE hold_id=:h",[":h"=>$hid])->fetch();
echo "kind={$r["kind"]} status={$r["status"]} (expect housekeeping/completed)\n";
db_query("DELETE FROM booking_addons WHERE hold_id=:h",[":h"=>$hid]);
db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$hid]);
db_query("DELETE FROM holds WHERE id=:h",[":h"=>$hid]);
echo "cleaned\n";
'
```
Expected: `kind=housekeeping status=completed`, `cleaned`. Re-run the migrate command → still OK (idempotent).

- [ ] **Step 4: Commit**
```bash
git add db/migrations/add_concierge.sql db/run-migrations.sql
git commit -m "feat(db): concierge request kinds + completed status on booking_addons"
```

---

## Task 2: Endpoint — accept concierge kinds

**Files:** Modify `api/booking-addon.php`

- [ ] **Step 1: Widen the kind whitelist**

In `api/booking-addon.php`, change:
```php
if (!in_array($kind, ['tour','transfer','itinerary','other'], true)) {
```
to:
```php
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant'], true)) {
```
No other change — the existing `else` branch (require non-empty `$details`) already handles the new free-text kinds; `tour`/`transfer` keep their structured validation.

- [ ] **Step 2: Verify**

`php -l api/booking-addon.php` → clean. Start `php -S localhost:8765 router.php`; get a real ref:
```bash
REF=$(php -r 'require "includes/booking.php"; $r=db_query("SELECT id FROM holds WHERE status IN (\047pending\047,\047confirmed\047) ORDER BY id DESC LIMIT 1")->fetch(); echo $r? make_guest_ref((int)$r["id"]) : "";')
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"housekeeping\",\"details\":\"Please make up the room at 2pm\"}"   # {"ok":true}
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"maintenance\"}"                                                       # 422 "Please add a few details."
curl -s -X POST localhost:8765/api/booking-addon.php -H 'Content-Type: application/json' -d "{\"ref\":\"$REF\",\"kind\":\"bogus\"}"                                                             # 422 "Unknown add-on type."
```
Then confirm the housekeeping row exists and clean it:
```bash
php -r 'require "includes/db.php"; $r=db_query("SELECT id,kind,status FROM booking_addons WHERE kind=\047housekeeping\047 ORDER BY id DESC LIMIT 1")->fetch(); echo var_export($r,true)."\n"; db_query("DELETE FROM booking_addons WHERE id=:i",[":i"=>$r["id"]]);'
```
Expected: first `{"ok":true}`; second/third are 422 with those messages; row had `kind=housekeeping status=requested`. Stop the server.

- [ ] **Step 3: Commit**
```bash
git add api/booking-addon.php
git commit -m "feat: accept concierge request kinds on the add-on endpoint"
```

---

## Task 3: Admin "Mark done" (completed)

**Files:** Modify `admin/booking-request-action.php`, `admin/holds.php`

- [ ] **Step 1: Allow the `completed` transition in the handler**

In `admin/booking-request-action.php`, replace the addon branch condition and guard. Change:
```php
if ($type === 'addon' && in_array($status, ['confirmed', 'declined', 'cancelled'], true) && $id) {
```
to also allow `completed`:
```php
if ($type === 'addon' && in_array($status, ['confirmed', 'declined', 'cancelled', 'completed'], true) && $id) {
```
Then change the status-guard so `completed` may come from `requested` OR `confirmed` (a service can be done after being confirmed), while the others still require `requested`. Replace:
```php
    if ($cur['status'] !== 'requested') {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Add-on request #{$id} is already {$cur['status']} — no action taken."];
        header('Location: /admin/holds.php');
        exit;
    }
    db_query("UPDATE booking_addons SET status=:s WHERE id=:id AND status='requested'", [':s' => $status, ':id' => $id]);
```
with:
```php
    $allowedFrom = $status === 'completed' ? ['requested','confirmed'] : ['requested'];
    if (!in_array($cur['status'], $allowedFrom, true)) {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => "Add-on request #{$id} is already {$cur['status']} — no action taken."];
        header('Location: /admin/holds.php');
        exit;
    }
    $placeholders = implode(',', array_map(fn($s) => "'" . $s . "'", $allowedFrom)); // values are code literals — safe
    db_query("UPDATE booking_addons SET status=:s WHERE id=:id AND status IN ($placeholders)", [':s' => $status, ':id' => $id]);
```

- [ ] **Step 2: Add the "Mark done" button in `admin/holds.php`**

Read the per-hold add-on rendering block (the `foreach ($h_addons as $a)` loop). It currently shows Confirm/Decline only when `$a['status'] === 'requested'`. Add a "Mark done" button that shows when status is `requested` OR `confirmed`. Inside that add-on row, alongside the existing action form, add:
```php
<?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
  <form method="post" action="/admin/booking-request-action.php" style="display:inline;margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
    <button name="status" value="completed" class="btn-primary btn-sm" onclick="return confirm('Mark this request done?')">Mark done</button>
  </form>
<?php endif; ?>
```
(Match the surrounding markup; the existing Confirm/Decline buttons already carry `csrf_field()` — reuse the same form pattern.)

- [ ] **Step 3: Verify**

`php -l admin/booking-request-action.php admin/holds.php` → clean.

Guard logic via direct simulation (no login needed):
```bash
php -r '
require "includes/db.php";
$uid=(int)db()->query("SELECT id FROM units WHERE is_active=TRUE LIMIT 1")->fetchColumn();
$sid=(int)db()->query("SELECT id FROM submissions ORDER BY id LIMIT 1")->fetchColumn();
$hid=create_hold_with_block($uid,$sid,"2028-02-01","2028-02-02","MarkDone","md@example.com");
db_query("INSERT INTO booking_addons (hold_id,kind,details) VALUES (:h,\047amenities\047,\047towels\047)",[":h"=>$hid]);
$aid=(int)db()->query("SELECT id FROM booking_addons WHERE hold_id=$hid")->fetchColumn();
db_query("UPDATE booking_addons SET status=\047completed\047 WHERE id=:i AND status IN (\047requested\047,\047confirmed\047)",[":i"=>$aid]);
echo "after mark done: ".db_query("SELECT status FROM booking_addons WHERE id=:i",[":i"=>$aid])->fetchColumn()." (expect completed)\n";
$n=db_query("UPDATE booking_addons SET status=\047declined\047 WHERE id=:i AND status=\047requested\047",[":i"=>$aid]);
echo "re-action rowCount: ".$n->rowCount()." (expect 0)\n";
db_query("DELETE FROM booking_addons WHERE hold_id=:h",[":h"=>$hid]); db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$hid]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$hid]);
echo "cleaned\n";
'
```
Expected: `after mark done: completed`, `re-action rowCount: 0`, `cleaned`. And `grep -c "Mark done" admin/holds.php` → ≥1.

- [ ] **Step 4: Commit**
```bash
git add admin/booking-request-action.php admin/holds.php
git commit -m "feat(admin): Mark done (completed) action for requests"
```

---

## Task 4: Stay-info admin editor

**Files:** Create `admin/stay-info.php`; modify `admin/_layout.php`

- [ ] **Step 1: Create `admin/stay-info.php`**

Mirror `admin/settings.php` conventions (`require_login`, `verify_csrf`, `set_setting`, `setting`, `_layout`).
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$success = '';
$fields = ['stay_wifi' => 'Wi-Fi', 'stay_checkout' => 'Check-out', 'stay_house_rules' => 'House rules', 'stay_area_guide' => 'Area guide'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($fields) as $k) {
        set_setting($k, trim((string)($_POST[$k] ?? '')));
    }
    $success = 'Stay info saved.';
}

$pageTitle  = 'Stay Info';
$activeMenu = 'stay_info';
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Stay Info</h1></div>
<?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
<div class="card">
  <div class="card__body" style="padding:20px">
    <p style="margin:0 0 16px;font-size:13px;color:var(--muted)">Shown to guests in the app under “Stay info”. Leave a field blank to hide it.</p>
    <form method="POST" action="/admin/stay-info.php">
      <?= csrf_field() ?>
      <?php foreach ($fields as $k => $label): ?>
      <label style="display:block;margin:0 0 14px;font-size:13px;font-weight:500"><?= e($label) ?>
        <textarea name="<?= e($k) ?>" rows="3" style="display:block;width:100%;margin-top:4px;padding:9px;border:1px solid #d1d5db;border-radius:6px;font:inherit"><?= e(setting($k, '')) ?></textarea>
      </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Add the nav link in `admin/_layout.php`**

After the existing `settings.php` sidebar link, add:
```php
      <a href="/admin/stay-info.php"    class="sidebar__link <?= ($activeMenu??'')==='stay_info'    ? 'is-active':'' ?>">Stay info</a>
```
(Match the exact markup of the neighbouring `<a>` links — copy one and change the href/label/activeMenu.)

- [ ] **Step 3: Verify**

`php -l admin/stay-info.php admin/_layout.php` → clean. Persistence round-trip (no login needed for the setting helpers):
```bash
php -r 'require "includes/db.php"; set_setting("stay_wifi","TribalSand / villa123"); echo "read back: ".setting("stay_wifi","MISS")."\n"; set_setting("stay_wifi","");'
```
Expected: `read back: TribalSand / villa123`. Auth-gate: start the server; `curl -s -o /dev/null -w '%{http_code}\n' localhost:8765/admin/stay-info.php` → 302. `grep -c "stay-info.php" admin/_layout.php` → ≥1. Stop server.

- [ ] **Step 4: Commit**
```bash
git add admin/stay-info.php admin/_layout.php
git commit -m "feat(admin): Stay info content editor"
```

---

## Task 5: App shell — view routing + shared header + home

**Files:** Modify `booking.php`; create `includes/app/status-header.php`, `includes/app/home.php`

- [ ] **Step 1: Add `$view` resolution in `booking.php`**

After the block that resolves `$hold`/`$ref` and computes `$status`/`$can_cancel` (i.e. after the cancel-eligibility section, before `include head.php`), add:
```php
$view = in_array($_GET['view'] ?? 'home', ['home','concierge','stay','manage'], true) ? ($_GET['view'] ?? 'home') : 'home';
require_once __DIR__ . '/includes/booking.php'; // fetch_booking_addons(), fetch_published_tours(), TRANSFER_OPTIONS (already required, safe)
```

- [ ] **Step 2: Create the shared status header include**

Create `includes/app/status-header.php` (expects `$hold`, `$ref`, `$status` in scope). Move the existing `.bk-card` summary markup here — read the current summary card in `booking.php` (the `<div class="bk-card">` … reference, `access_code`, dates table, expiry/confirmed row … `</div><!-- /bk-card -->`) and the status notices block, and paste them verbatim into this file. Then in `booking.php`, replace that inline markup with `<?php include __DIR__ . '/includes/app/status-header.php'; ?>`.

> This is a mechanical extraction — cut the summary card + notices out of booking.php, paste into the include, and reference the include. Verify byte-for-byte that the markup is unchanged.

- [ ] **Step 3: Replace the "manage-actions + cancel" block with a view switch**

In `booking.php`, the current block reads (roughly):
```php
    <?php if (in_array($status, ['pending', 'confirmed'], true)):
      $tours = ...; include __DIR__ . '/includes/booking-manage-actions.php';
    endif; ?>
    <?php if ($can_cancel): ?> ...cancel card... <?php elseif ($cancel_blocked_reason): ?> ...contact... <?php endif; ?>
```
Replace that entire block with:
```php
    <?php if ($view === 'home'): ?>
      <?php include __DIR__ . '/includes/app/home.php'; ?>
    <?php elseif ($view === 'concierge'): ?>
      <?php include __DIR__ . '/includes/app/concierge.php'; ?>
    <?php elseif ($view === 'stay'): ?>
      <?php include __DIR__ . '/includes/app/stay.php'; ?>
    <?php elseif ($view === 'manage'): ?>
      <p style="margin:0 0 16px"><a href="/booking.php?ref=<?= e(urlencode($ref)) ?>" style="font-size:13px;color:var(--teal,#1E5C6B)">&larr; Back to home</a></p>
      <?php if (in_array($status, ['pending', 'confirmed'], true)):
          try { $tours = fetch_published_tours(); } catch (Throwable $e) { $tours = []; }
          include __DIR__ . '/includes/booking-manage-actions.php';
      endif; ?>
      <?php /* MOVE THE EXISTING cancel-card markup here, unchanged — see note below */ ?>
    <?php endif; ?>
```

> CRITICAL: `booking.php` is a GUEST page and does NOT load the admin auth helpers, so it has **no `csrf_field()`** — the guest cancel form is token-gated (a hidden `name="ref"` + `name="action"=cancel` + an `onsubmit` confirm), NOT CSRF-protected. Do the `manage` branch by **cutting the CURRENT `$can_cancel` / `$cancel_blocked_reason` cancel-card block out of booking.php verbatim and pasting it inside the `manage` branch** (right after the manage-actions include). Do NOT rewrite it, do NOT add `csrf_field()`, do NOT change the cancel form's fields or how it POSTs. The only change is its location (now under `?view=manage`).

- [ ] **Step 4: Create `includes/app/home.php`**

```php
<?php /** Home dashboard. Expects $hold, $ref, $status. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref); ?>
<div class="bk-tiles" style="display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:8px">
  <a href="<?= e($__u) ?>&view=concierge" style="text-decoration:none;background:#0e6b7a;color:#fff;border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px">
    <span style="font-size:24px">&#128276;</span>
    <span><span style="display:block;font-weight:700;font-size:16px">Concierge</span><span style="display:block;font-size:13px;opacity:.85">Towels, housekeeping, anything you need</span></span>
    <span style="margin-left:auto">&rarr;</span>
  </a>
  <a href="<?= e($__u) ?>&view=stay" style="text-decoration:none;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px;color:#1a1a1a">
    <span style="font-size:24px">&#8505;</span>
    <span><span style="display:block;font-weight:700;font-size:16px">Stay info</span><span style="display:block;font-size:13px;color:#6b7280">Wi-Fi, check-out, area guide</span></span>
    <span style="margin-left:auto;color:#9ca3af">&rarr;</span>
  </a>
  <a href="<?= e($__u) ?>&view=manage" style="text-decoration:none;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:14px;color:#1a1a1a">
    <span style="font-size:20px">&#9998;</span>
    <span style="font-weight:600;font-size:14px">Manage booking</span>
    <span style="margin-left:auto;color:#9ca3af;font-size:13px">Add tours &middot; changes &middot; cancel</span>
  </a>
</div>
```

- [ ] **Step 5: Verify**

`php -l booking.php includes/app/status-header.php includes/app/home.php` → clean. Start the server; with a real pending/confirmed hold's ref in `$REF`:
```bash
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c "Concierge"            # >=1 (home tile)
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -c "Back to home"   # >=1
curl -s "localhost:8765/booking.php?ref=$REF&view=manage" | grep -c "Send change request"  # >=1 (existing manage still works)
curl -s "localhost:8765/booking.php?ref=$REF" | grep -c '<meta name="robots"'   # >=1 (still noindex)
```
Concierge/stay includes don't exist until Tasks 6/7 — `?view=concierge` will PHP-warn/blank until then; that's expected mid-plan. Stop the server.

- [ ] **Step 6: Commit**
```bash
git add booking.php includes/app/status-header.php includes/app/home.php
git commit -m "feat: guest app view routing + home dashboard"
```

---

## Task 6: Concierge view

**Files:** Create `includes/app/concierge.php`

- [ ] **Step 1: Create `includes/app/concierge.php`**

Free-text categories post to `/api/booking-addon.php` via the existing `js/booking-manage.js` fetch handler (binds `form[data-bm]`, writes `.bm-status`); Transfer/Activity link into `?view=manage`. Recent requests reuse `fetch_booking_addons()`.
```php
<?php /** Concierge view. Expects $hold, $ref, $status. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref); $__addons = fetch_booking_addons((int)$hold['id']); ?>
<style>
.cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.cx-tile{background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px;text-align:left;font:inherit;cursor:pointer;font-size:14px;font-weight:600;color:#1a1a1a}
.cx-tile[aria-expanded=true]{border-color:#0e6b7a}
.cx-form{display:none;margin-top:10px;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px}
.cx-form.open{display:block}
.cx-submit{background:#102F3A;color:#fff;border:0;padding:11px 18px;border-radius:8px;font-weight:600;cursor:pointer}
.cx-pill{margin-left:auto;font-size:12px;padding:2px 9px;border-radius:999px;text-transform:capitalize}
.cx-pill-requested{background:#fff7e6;color:#8a5a00}.cx-pill-confirmed{background:#e6eefb;color:#1a4a9c}
.cx-pill-completed,.cx-pill-done{background:#e6f6ec;color:#146c37}.cx-pill-declined,.cx-pill-cancelled{background:#fbe6e6;color:#a12}
</style>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" style="font-size:13px;color:var(--teal,#1E5C6B)">&larr; Back to home</a></p>
<h2 style="font-family:'Cormorant Garamond',serif;font-weight:500;margin:0 0 4px">Concierge</h2>
<p style="margin:0 0 14px;font-size:13px;color:#6b7280">Tap what you need — our team confirms by return.</p>

<div class="cx-grid">
  <?php foreach (['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Something else'] as $k=>$label): ?>
  <button type="button" class="cx-tile" data-cx="<?= e($k) ?>" aria-expanded="false"><?= e($label) ?></button>
  <?php endforeach; ?>
  <a href="<?= e($__u) ?>&view=manage" class="cx-tile" style="display:block;text-decoration:none">Transfer</a>
  <a href="<?= e($__u) ?>&view=manage" class="cx-tile" style="display:block;text-decoration:none">Activity</a>
</div>

<?php foreach (['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Something else'] as $k=>$label): ?>
<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-<?= e($k) ?>">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="<?= e($k) ?>">
  <label style="display:block;font-size:13px;margin-bottom:8px"><?= e($label) ?> — what do you need?
    <textarea name="details" rows="2" required style="display:block;width:100%;margin-top:4px;padding:9px;border:1px solid #d1d5db;border-radius:6px;font:inherit"></textarea>
  </label>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="cx-submit">Send request</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
<?php endforeach; ?>

<?php if ($__addons): ?>
<div style="margin-top:20px">
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#9ca3af;margin-bottom:8px">Recent requests</div>
  <?php foreach ($__addons as $a): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #eee;font-size:14px">
    <strong style="text-transform:capitalize"><?= e($a['kind']) ?></strong>
    <span style="color:#555"><?= e(trim(($a['tour_name'] ?? '') . ' ' . $a['details'])) ?></span>
    <span class="cx-pill cx-pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.cx-tile[data-cx]').forEach(function(b){
  b.addEventListener('click',function(){
    var k=b.getAttribute('data-cx'); var f=document.getElementById('cx-form-'+k);
    var open=f.classList.contains('open');
    document.querySelectorAll('.cx-form').forEach(function(x){x.classList.remove('open')});
    document.querySelectorAll('.cx-tile[data-cx]').forEach(function(x){x.setAttribute('aria-expanded','false')});
    if(!open){f.classList.add('open'); b.setAttribute('aria-expanded','true'); f.scrollIntoView({behavior:'smooth',block:'nearest'});}
  });
});
</script>
```

> `js/booking-manage.js` is already loaded by `booking.php` and binds `form[data-bm]` → submits JSON to the form's `action`, shows `.bm-status`, reloads on success. Confirm it's included on the page (it is, from the earlier feature). `captcha_site_key()` is available (booking.php requires turnstile.php).

- [ ] **Step 2: Verify**

`php -l includes/app/concierge.php` → clean. Start the server; with `$REF` a pending/confirmed hold:
```bash
curl -s "localhost:8765/booking.php?ref=$REF&view=concierge" | grep -c "Housekeeping"   # >=1
curl -s "localhost:8765/booking.php?ref=$REF&view=concierge" | grep -c 'name="kind" value="housekeeping"'  # >=1
```
Real submit through the JS is exercised in Task 8 (browser). Endpoint accepting the kind was already verified in Task 2. Stop the server.

- [ ] **Step 3: Commit**
```bash
git add includes/app/concierge.php
git commit -m "feat: concierge view (request grid + recent requests)"
```

---

## Task 7: Stay info view

**Files:** Create `includes/app/stay.php`

- [ ] **Step 1: Create `includes/app/stay.php`**

```php
<?php /** Stay info view. Expects $ref. Reads admin-edited settings. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref);
$__info = ['stay_wifi'=>'Wi-Fi','stay_checkout'=>'Check-out','stay_house_rules'=>'House rules','stay_area_guide'=>'Area guide'];
$__any = false; foreach (array_keys($__info) as $__k) { if (trim((string)setting($__k,'')) !== '') { $__any = true; break; } } ?>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" style="font-size:13px;color:var(--teal,#1E5C6B)">&larr; Back to home</a></p>
<h2 style="font-family:'Cormorant Garamond',serif;font-weight:500;margin:0 0 14px">Stay info</h2>
<?php if (!$__any): ?>
  <p style="color:#6b7280;font-size:14px">Stay details will appear here soon.</p>
<?php else: foreach ($__info as $__k=>$__label): $__v = trim((string)setting($__k,'')); if ($__v==='') continue; ?>
  <div style="background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px 16px;margin-bottom:10px">
    <div style="font-size:12px;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px"><?= e($__label) ?></div>
    <div style="font-size:14px;line-height:1.6;white-space:pre-wrap"><?= e($__v) ?></div>
  </div>
<?php endforeach; endif; ?>
```

- [ ] **Step 2: Verify**

`php -l includes/app/stay.php` → clean. Seed a value, render, then clear:
```bash
php -r 'require "includes/db.php"; set_setting("stay_wifi","TribalSand / villa123"); set_setting("stay_checkout","11:00");'
# with $REF and the server running:
curl -s "localhost:8765/booking.php?ref=$REF&view=stay" | grep -c "villa123"   # >=1
php -r 'require "includes/db.php"; set_setting("stay_wifi",""); set_setting("stay_checkout","");'
```
Expected: ≥1. Stop the server.

- [ ] **Step 3: Commit**
```bash
git add includes/app/stay.php
git commit -m "feat: stay info view"
```

---

## Task 8: E2E + regression + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Lint + test suites**
```bash
php tests/manage_logic.php | tail -1     # ALL PASS
php tests/convert_logic.php | tail -1    # ALL PASS
for f in booking.php api/booking-addon.php admin/booking-request-action.php admin/holds.php admin/stay-info.php admin/_layout.php includes/app/status-header.php includes/app/home.php includes/app/concierge.php includes/app/stay.php; do php -l "$f" >/dev/null && echo "OK $f"; done
```

- [ ] **Step 2: Browser E2E (dev server)** — open `booking.php?ref=<real pending/confirmed hold>`:
  1. Home shows the status header + Concierge + Stay info + Manage booking tiles.
  2. Tap Concierge → a category → fill the free-text → Send request → "Request sent", reloads, the item appears under "Recent requests" as `requested`. (Confirm a `booking_addons` row was created via `php -r`.)
  3. Tap Stay info → shows any admin-seeded content (seed via `admin/stay-info.php` or `set_setting`).
  4. Manage booking → the existing add-tours/change/cancel still work (change request creates a row).
  5. Code-only login (enter the code) reaches the same home.

- [ ] **Step 3: Admin round-trip (needs admin login)** — Holds screen → find the hold → the concierge request shows in its request list → click **Mark done** → status becomes `completed`; reload the guest concierge view → the pill reads `completed`.

- [ ] **Step 4: Regression** — public booking widget still creates a pending hold; convert-enquiry still works; existing add-on/change/cancel flows unaffected.

- [ ] **Step 5: Clean up** — delete any test holds/add-ons created during E2E:
```bash
php -r '$h=(int)($argv[1]??0); if(!$h){echo "pass id\n";exit;} require "includes/db.php"; db_query("DELETE FROM booking_addons WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM booking_change_requests WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM availability_blocks WHERE hold_id=:h",[":h"=>$h]); db_query("DELETE FROM holds WHERE id=:h",[":h"=>$h]); echo "removed $h\n";' <HID>
```
And clear any seeded stay-info: `php -r 'require "includes/db.php"; foreach(["stay_wifi","stay_checkout","stay_house_rules","stay_area_guide"] as $k) set_setting($k,"");'` (unless you want to keep real content).

---

## Self-Review Notes (author checklist)

- **Spec coverage:** view routing + home (T5), concierge reusing booking_addons (T1 migration, T2 endpoint, T6 UI), Mark-done/completed (T1 status, T3 admin), Stay info storage+editor+view (T4, T7), manage features preserved under `?view=manage` (T5), noindex retained (T5 verify). ✔
- **Deploy gap called out:** T1 notes prod must run the migration manually.
- **Type/name consistency:** kinds `housekeeping/amenities/maintenance/restaurant` used identically in T1 CHECK, T2 whitelist, T6 forms; status `completed` in T1 CHECK, T3 handler + button, T6 pill class; `fetch_booking_addons()`, `fetch_published_tours()`, `TRANSFER_OPTIONS`, `set_setting()`/`setting()`, `captcha_site_key()`, `form[data-bm]`/`.bm-status` all pre-existing and referenced consistently.
- **Extraction risk (T5 Step 2):** moving the summary card into an include is mechanical — verify markup unchanged and `$hold/$ref/$status` in scope. Preserve the EXISTING cancel form exactly (T5 Step 3 note).
- **No placeholders; CSRF + e() + is_scalar hardening on all new writes; concierge free-text reuses the already-hardened endpoint.**
