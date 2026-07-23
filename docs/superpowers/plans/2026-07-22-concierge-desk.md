# Concierge Full-App + Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Laundry service, service-tile icons, optional scheduling, and friendly status labels to the guest concierge; add a unified admin "Concierge Desk" to manage all requests across guests.

**Architecture:** Reuse the existing `booking_addons` pipeline. One migration adds `'laundry'` to the kind CHECK and a nullable `scheduled_for TIMESTAMP`. Guest concierge gains icons + a structured laundry form + a preferred-time field; status labels are humanized via one helper. Admin gains a Concierge Desk page that lists all requests with filters and inline actions posting to the existing `booking-request-action.php` (extended with a whitelisted `return` redirect).

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via `db_query()`, vanilla CSS/JS.

---

## Task 1: Migration + schema

**Files:**
- Create: `db/migrations/add_concierge_desk.sql`
- Modify: `db/schema.sql`

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_concierge_desk.sql`:

```sql
-- Migration: laundry concierge kind + optional scheduled_for on booking_addons
-- Run via /admin/migrate.php. Idempotent — safe to re-run.

ALTER TABLE booking_addons DROP CONSTRAINT IF EXISTS booking_addons_kind_check;
ALTER TABLE booking_addons ADD CONSTRAINT booking_addons_kind_check
    CHECK (kind IN ('tour','transfer','itinerary','other',
                    'housekeeping','amenities','maintenance','restaurant','laundry'));

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS scheduled_for TIMESTAMP;
```

- [ ] **Step 2: Apply to local DB**

Run: `php -r "require 'includes/db.php'; db()->exec(file_get_contents('db/migrations/add_concierge_desk.sql')); echo 'ok';"`
Expected: `ok`. Re-run once to confirm idempotency (still `ok`).

- [ ] **Step 3: Verify**

Run: `php -r "require 'includes/db.php'; db_query(\"INSERT INTO booking_addons (hold_id,kind,details,scheduled_for) SELECT id,'laundry','test','2029-01-01 09:00' FROM holds LIMIT 1\"); echo 'insert-ok'; db()->exec(\"DELETE FROM booking_addons WHERE kind='laundry' AND details='test'\");"`
Expected: `insert-ok` (proves the CHECK accepts laundry and the column exists), then cleanup.

- [ ] **Step 4: Append to `db/schema.sql`**

If `db/schema.sql` defines `booking_addons` inline, update its `kind` CHECK to include `'laundry'` and add a `scheduled_for TIMESTAMP` column to the table definition. If the table is only created via migrations (not in schema.sql), instead append the two `ALTER TABLE` statements from Step 1 to the end of `db/schema.sql`. Read the file first to decide which applies; keep it consistent with how prior migrations (e.g. concierge kinds) were reflected there.

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_concierge_desk.sql db/schema.sql
git commit -m "feat(concierge): migration — laundry kind + scheduled_for column"
```

---

## Task 2: Helpers + endpoint + tests

**Files:**
- Modify: `includes/booking.php` (add `LAUNDRY_OPTIONS`, `addon_status_label()`)
- Modify: `api/booking-addon.php` (laundry + scheduled_for)
- Modify: `tests/portal_logic.php`

- [ ] **Step 1: Add constant + helper to `includes/booking.php`**

Add near `TRANSFER_OPTIONS`:

```php
/** Laundry service options offered on the concierge laundry form. */
const LAUNDRY_OPTIONS = [
    'wash_fold' => 'Wash & fold',
    'iron'      => 'Ironing',
    'dry_clean' => 'Dry-clean',
    'wash_iron' => 'Wash & iron',
];
```

Add near `addon_label()`:

```php
/** Guest-facing label for an addon request status. */
function addon_status_label(string $status): string {
    return [
        'requested' => 'Requested',
        'confirmed' => 'In progress',
        'completed' => 'Done',
        'declined'  => 'Declined',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst($status);
}
```

- [ ] **Step 2: Extend `api/booking-addon.php`**

(a) Add `laundry` to the kind whitelist. Change the `in_array($kind, [...])` list (currently ends `...'restaurant'`) to also include `'laundry'`:

```php
if (!in_array($kind, ['tour','transfer','itinerary','other',
                      'housekeeping','amenities','maintenance','restaurant','laundry'], true)) {
```

(b) Handle the laundry structured form. In the `if ($kind === 'tour') { ... } elseif ($kind === 'transfer') { ... }` chain, add a branch BEFORE the final `else`:

```php
} elseif ($kind === 'laundry') {
    $opt = $str($data['service'] ?? '');
    if (!array_key_exists($opt, LAUNDRY_OPTIONS)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a laundry service.'])); }
    $label   = LAUNDRY_OPTIONS[$opt];
    $details = $details === '' ? $label : "{$label} — {$details}";
```

(c) Parse an optional `scheduled_for`. After the kind-branch block (right before the `try {` insert), add:

```php
$sched = $str($data['scheduled_for'] ?? '');
$schedSql = null;
if ($sched !== '') {
    $ts = strtotime($sched);
    if ($ts !== false) $schedSql = date('Y-m-d H:i:s', $ts); // silently ignore an unparseable value
}
```

(d) Store it. Change the INSERT to include `scheduled_for`:

```php
    db_query(
        "INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for)
         VALUES (:h, :k, :t, :d, :sf)",
        [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details, ':sf'=>$schedSql]
    );
```

- [ ] **Step 3: Add tests to `tests/portal_logic.php`**

Before the final summary line, add:

```php
// ── concierge status labels + laundry options ────────────────
check('status label: requested', addon_status_label('requested') === 'Requested');
check('status label: confirmed → In progress', addon_status_label('confirmed') === 'In progress');
check('status label: completed → Done', addon_status_label('completed') === 'Done');
check('status label: declined', addon_status_label('declined') === 'Declined');
check('status label: cancelled', addon_status_label('cancelled') === 'Cancelled');
check('status label: unknown falls back', addon_status_label('weird') === 'Weird');
check('laundry options non-empty', is_array(LAUNDRY_OPTIONS) && count(LAUNDRY_OPTIONS) >= 2);

// laundry addon persists with scheduled_for
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    db_query("INSERT INTO booking_addons (hold_id,kind,details,scheduled_for) VALUES (:h,'laundry','Wash & fold — 3 shirts','2029-05-01 09:00')", [':h'=>$hid]);
    $la = db_query("SELECT kind, scheduled_for FROM booking_addons WHERE hold_id=:h AND kind='laundry' ORDER BY id DESC LIMIT 1", [':h'=>$hid])->fetch();
    check('laundry addon stored with schedule', $la && $la['kind']==='laundry' && !empty($la['scheduled_for']));
    db_query("DELETE FROM booking_addons WHERE hold_id=:h AND kind='laundry' AND details='Wash & fold — 3 shirts'", [':h'=>$hid]);
}
```

- [ ] **Step 4: Run tests + lint**

Run: `php tests/portal_logic.php` → expect `ALL PASS`.
Run: `php -l includes/booking.php && php -l api/booking-addon.php` → no errors.

- [ ] **Step 5: Commit**

```bash
git add includes/booking.php api/booking-addon.php tests/portal_logic.php
git commit -m "feat(concierge): laundry kind + scheduled_for in endpoint; status-label helper + tests"
```

---

## Task 3: Concierge view — icons, laundry form, scheduling, status labels

**Files:**
- Modify: `includes/app/concierge.php` (full replacement below)

- [ ] **Step 1: Replace `includes/app/concierge.php` with this file**

Read the current file first to confirm it still expects `$hold`, `$ref`, `$status` and uses `fetch_booking_addons`, `TRANSFER_OPTIONS`, `captcha_site_key()`, the `.cx-tile`/`.cx-form` toggle JS, and `.pa-*` classes — then replace with:

```php
<?php /** Concierge view. Expects $hold, $ref, $status. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__addons = fetch_booking_addons((int)$hold['id']);
$__kinds = ['housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','other'=>'Something else'];
// Tile grid: laundry + services + transfer (structured), then "Something else".
$__tiles = ['laundry'=>'Laundry','housekeeping'=>'Housekeeping','amenities'=>'Towels & amenities','maintenance'=>'Maintenance','restaurant'=>'Restaurant','transfer'=>'Transfer','other'=>'Something else'];
$__icons = [
  'laundry'      => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="13" r="4"/><line x1="7" y1="6" x2="7.01" y2="6"/></svg>',
  'housekeeping' => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4"/><path d="M3 18h18M4 12V8a2 2 0 0 1 2-2h5v6"/></svg>',
  'amenities'    => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="9" y1="4" x2="9" y2="20"/></svg>',
  'maintenance'  => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-2.4z"/></svg>',
  'restaurant'   => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v7a2 2 0 0 0 4 0V3M8 10v11"/><path d="M17 3c-1.5 0-3 1.8-3 4.5S15.5 12 17 12v9"/></svg>',
  'transfer'     => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l1.6-4.6A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.4L19 13v4h-2v-2H7v2H5z"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/></svg>',
  'other'        => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5.4A8 8 0 1 1 21 12z"/></svg>',
];
// Shared optional preferred-time field markup.
$__sched = '<label class="pa-field">Preferred time (optional)<input type="datetime-local" name="scheduled_for"></label>';
?>
<style>
.cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
@media (min-width:720px){.cx-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.cx-tile{display:flex;flex-direction:column;align-items:flex-start;gap:8px;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px;padding:14px;text-align:left;font:inherit;cursor:pointer;font-size:14px;font-weight:600;color:var(--pa-ink)}
.cx-tile svg{color:var(--pa-teal)}
.cx-tile[aria-expanded=true]{border-color:var(--pa-teal-d)}
.cx-form{display:none;margin-top:10px;background:var(--pa-card);border:1px solid var(--pa-line);border-radius:12px;padding:14px}
.cx-form.open{display:block}
</style>
<p style="margin:0 0 16px"><a href="<?= e($__u) ?>" class="pa-back">&larr; Back to home</a></p>
<h2 class="pa-h2">Concierge</h2>
<p class="pa-sub">Tap what you need — our team confirms by return.</p>

<div class="cx-grid">
  <?php foreach ($__tiles as $k=>$label): ?>
  <button type="button" class="cx-tile" data-cx="<?= e($k) ?>" aria-expanded="false" aria-controls="cx-form-<?= e($k) ?>">
    <span aria-hidden="true"><?= $__icons[$k] ?? '' ?></span>
    <?= e($label) ?>
  </button>
  <?php endforeach; ?>
</div>

<?php foreach ($__kinds as $k=>$label): ?>
<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-<?= e($k) ?>">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="<?= e($k) ?>">
  <label class="pa-field"><?= e($label) ?> — what do you need?
    <textarea name="details" rows="2" required></textarea>
  </label>
  <?= $__sched ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Send request</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>
<?php endforeach; ?>

<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-laundry">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="laundry">
  <label class="pa-field">Laundry service
    <select name="service" required>
      <option value="">— select —</option>
      <?php foreach (LAUNDRY_OPTIONS as $__lk => $__ll): ?><option value="<?= e($__lk) ?>"><?= e($__ll) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="pa-field">Notes (items, instructions…)<textarea name="details" rows="2"></textarea></label>
  <?= $__sched ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Request laundry</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>

<form data-bm action="/api/booking-addon.php" class="cx-form" id="cx-form-transfer">
  <input type="hidden" name="ref" value="<?= e($ref) ?>">
  <input type="hidden" name="kind" value="transfer">
  <label class="pa-field">Transfer
    <select name="transfer" required>
      <option value="">— select —</option>
      <?php foreach (TRANSFER_OPTIONS as $__tk => $__tl): ?><option value="<?= e($__tk) ?>"><?= e($__tl) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label class="pa-field">Details (flight no., time, pickup…)<textarea name="details" rows="2"></textarea></label>
  <?= $__sched ?>
  <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 10px"></div>
  <button type="submit" class="pa-btn pa-btn--primary">Request transfer</button>
  <p class="bm-status" aria-live="polite" style="margin:10px 0 0;font-size:13px"></p>
</form>

<?php if ($__addons): ?>
<div style="margin-top:20px">
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--pa-muted);margin-bottom:8px">Recent requests</div>
  <?php foreach ($__addons as $a): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid var(--pa-line);font-size:14px">
    <strong style="text-transform:capitalize"><?= e($a['kind']) ?></strong>
    <span style="color:var(--pa-muted)">
      <?= e(addon_label($a)) ?><?php if (!empty($a['scheduled_for'])): ?> · <?= e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) ?><?php endif; ?>
    </span>
    <span class="pa-pill pa-pill--<?= e($a['status']) ?>" style="margin-left:auto"><?= e(addon_status_label($a['status'])) ?></span>
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

- [ ] **Step 2: Lint**

Run: `php -l includes/app/concierge.php` → expect `No syntax errors detected`.

- [ ] **Step 3: Confirm `fetch_booking_addons` returns `scheduled_for`**

The recent-requests block reads `$a['scheduled_for']`. `fetch_booking_addons()` uses `SELECT ba.*` so the new column is included automatically. Confirm by reading `fetch_booking_addons` in `includes/booking.php` that it selects `ba.*` (it does) — no change needed.

- [ ] **Step 4: Commit**

```bash
git add includes/app/concierge.php
git commit -m "feat(concierge): service-tile icons, laundry form, scheduling field, friendly status labels"
```

---

## Task 4: Booking "Your requests" — labels + schedule

**Files:**
- Modify: `includes/booking-manage-actions.php`

- [ ] **Step 1: Humanize the addon status + show schedule**

In `includes/booking-manage-actions.php`, the addons loop currently renders:
```php
<span class="pa-pill pa-pill--<?= e($a['status']) ?>" style="margin-left:auto"><?= e($a['status']) ?></span>
```
and the details line:
```php
<span style="color:var(--pa-muted)"><?= e(addon_label($a)) ?></span>
```
Change the details span to append the schedule when present, and the pill text to the friendly label:
```php
<span style="color:var(--pa-muted)"><?= e(addon_label($a)) ?><?php if (!empty($a['scheduled_for'])): ?> · <?= e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) ?><?php endif; ?></span>
```
```php
<span class="pa-pill pa-pill--<?= e($a['status']) ?>" style="margin-left:auto"><?= e(addon_status_label($a['status'])) ?></span>
```
Leave the change-request rows unchanged (they use a separate `handled`/`declined` set which `addon_status_label` also renders sensibly if you choose to apply it — but not required; keep the change rows as-is).

- [ ] **Step 2: Lint**

Run: `php -l includes/booking-manage-actions.php` → expect no errors.

- [ ] **Step 3: Commit**

```bash
git add includes/booking-manage-actions.php
git commit -m "feat(concierge): friendly status + preferred time in Your requests list"
```

---

## Task 5: Admin Concierge Desk + return redirect

**Files:**
- Create: `admin/concierge-desk.php`
- Modify: `admin/_layout.php` (nav link)
- Modify: `admin/booking-request-action.php` (whitelisted return)

- [ ] **Step 1: Add whitelisted `return` to `admin/booking-request-action.php`**

Near the top (after `verify_csrf();`), add:
```php
$returnTo = ($_POST['return'] ?? '') === 'concierge-desk' ? '/admin/concierge-desk.php' : '/admin/holds.php';
```
Then replace EVERY `header('Location: /admin/holds.php');` in the file with `header('Location: ' . $returnTo);`. There are four occurrences (two early-exit guards in the addon branch, plus the final success/failure redirect, and any in the change branch). Leave the `$_SESSION['hold_flash']` assignments unchanged. The change-request branch redirects can stay pointing at holds.php OR also use `$returnTo` — use `$returnTo` for all of them for consistency.

- [ ] **Step 2: Add the nav link to `admin/_layout.php`**

After the Holds sidebar link (`$activeMenu==='holds'`), add a link matching the neighboring markup (copy the exact `<a class="sidebar__link ...">` shape, including any icon span the neighbors use):
```php
      <a href="/admin/concierge-desk.php" class="sidebar__link <?= ($activeMenu??'')==='concierge_desk' ? 'is-active':'' ?>">Concierge desk</a>
```

- [ ] **Step 3: Create `admin/concierge-desk.php`**

```php
<?php
/**
 * Admin: Concierge Desk — every guest request across all bookings, with filters + inline actions.
 * Actions post to booking-request-action.php (validated transitions + audit_log).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$pageTitle  = 'Concierge desk';
$activeMenu = 'concierge_desk';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$KINDS  = ['tour','transfer','laundry','housekeeping','amenities','maintenance','restaurant','itinerary','other'];
$STATUS_SETS = [
    'open'      => ['requested','confirmed'],
    'requested' => ['requested'],
    'confirmed' => ['confirmed'],
    'completed' => ['completed'],
    'declined'  => ['declined'],
    'cancelled' => ['cancelled'],
];
$statusKey = $_GET['status'] ?? 'open';
if ($statusKey !== 'all' && !isset($STATUS_SETS[$statusKey])) $statusKey = 'open';
$kindKey = $_GET['kind'] ?? 'all';
if ($kindKey !== 'all' && !in_array($kindKey, $KINDS, true)) $kindKey = 'all';

$where = [];
$params = [];
if ($statusKey !== 'all') {
    $set = $STATUS_SETS[$statusKey];
    $names = [];
    foreach ($set as $i => $s) { $n = ":st{$i}"; $names[] = $n; $params[$n] = $s; }
    $where[] = 'ba.status IN (' . implode(',', $names) . ')';
}
if ($kindKey !== 'all') { $where[] = 'ba.kind = :kind'; $params[':kind'] = $kindKey; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = db_query(
    "SELECT ba.*, t.name AS tour_name,
            h.guest_name, h.check_in, h.check_out,
            r.name AS room_name, v.name AS venue_name
     FROM booking_addons ba
     JOIN holds h  ON h.id = ba.hold_id
     JOIN units u  ON u.id = h.unit_id
     JOIN rooms r  ON r.id = u.room_id
     LEFT JOIN venues v ON v.id = r.venue_id
     LEFT JOIN tours t  ON t.id = ba.tour_id
     {$whereSql}
     ORDER BY ba.created_at DESC",
    $params
)->fetchAll();

$statusTabs = ['open'=>'Open','all'=>'All','requested'=>'Requested','confirmed'=>'In progress','completed'=>'Done','declined'=>'Declined','cancelled'=>'Cancelled'];

/** Map an addon status to the admin badge colour class. */
$badgeClass = fn(string $s): string => [
    'requested'=>'badge--orange','confirmed'=>'badge--blue','completed'=>'badge--green',
    'declined'=>'badge--red','cancelled'=>'badge--grey',
][$s] ?? 'badge--grey';

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Concierge desk</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm">← Dashboard</a>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <span class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Status</span>
    <?php foreach ($statusTabs as $sk=>$sl): ?>
      <a href="?status=<?= e($sk) ?>&kind=<?= e($kindKey) ?>" class="btn-sm <?= $statusKey===$sk?'btn-primary':'btn-outline' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card__body" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;border-top:1px solid #eee">
    <span class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Service</span>
    <a href="?status=<?= e($statusKey) ?>&kind=all" class="btn-sm <?= $kindKey==='all'?'btn-primary':'btn-outline' ?>">All</a>
    <?php foreach ($KINDS as $k): ?>
      <a href="?status=<?= e($statusKey) ?>&kind=<?= e($k) ?>" class="btn-sm <?= $kindKey===$k?'btn-primary':'btn-outline' ?>" style="text-transform:capitalize"><?= e($k) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="card__body" style="padding:0">
    <table class="data-table">
      <thead><tr><th>Guest</th><th>Service</th><th>Request</th><th>Preferred time</th><th>Submitted</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No requests match this filter.</td></tr>
        <?php else: foreach ($rows as $a): ?>
        <tr>
          <td>
            <strong><?= e($a['guest_name'] ?: 'Guest') ?></strong><br>
            <span class="text-muted" style="font-size:12px"><?= e(trim(($a['venue_name'] ?? '') . ' · ' . ($a['room_name'] ?? ''), ' ·')) ?></span>
          </td>
          <td style="text-transform:capitalize"><?= e($a['kind']) ?></td>
          <td><?= e(addon_label($a)) ?></td>
          <td><?= !empty($a['scheduled_for']) ? e(date('D j M, H:i', strtotime((string)$a['scheduled_for']))) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted" style="font-size:12px"><?= e(date('j M, H:i', strtotime((string)$a['created_at']))) ?></td>
          <td><span class="badge <?= $badgeClass($a['status']) ?>"><?= e(addon_status_label($a['status'])) ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="confirmed" class="btn-primary btn-sm">Accept</button></form>
            <?php endif; ?>
            <?php if (in_array($a['status'], ['requested','confirmed'], true)): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="completed" class="btn-outline btn-sm">Mark done</button></form>
            <?php endif; ?>
            <?php if ($a['status'] === 'requested'): ?>
            <form method="POST" action="/admin/booking-request-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="type" value="addon"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="return" value="concierge-desk"><button name="status" value="declined" class="btn-danger btn-sm">Decline</button></form>
            <?php endif; ?>
            <?php if (!in_array($a['status'], ['requested','confirmed'], true)): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

Note: the admin status badge uses `.badge` + `.badge--{orange,blue,green,red,grey}` (defined in `admin/assets/admin.css`, used by `admin/holds.php`). The `$badgeClass` closure above maps each status to the right colour — no new CSS needed.

- [ ] **Step 4: Lint + smoke**

Run: `php -l admin/concierge-desk.php && php -l admin/booking-request-action.php && php -l admin/_layout.php` → no errors.
Run: `php -r "\$_SERVER['REQUEST_METHOD']='GET'; require 'admin/concierge-desk.php';" 2>&1 | head -3` → no PHP Fatal/ParseError (a login redirect/exit is fine).
Confirm the four holds.php redirects were all swapped: `grep -c "Location: ' . \$returnTo" admin/booking-request-action.php` should be ≥3 and `grep -c "/admin/holds.php'" admin/booking-request-action.php` should be 0 (only the `$returnTo` default string mentions holds.php).

- [ ] **Step 5: Commit**

```bash
git add admin/concierge-desk.php admin/_layout.php admin/booking-request-action.php
git commit -m "feat(concierge): admin Concierge Desk (filters + inline actions) + return redirect"
```

---

## Task 6: E2E + regression + cleanup

**Files:** none (verification only)

- [ ] **Step 1: Tests + lint**

Run: `php tests/portal_logic.php && php tests/manage_logic.php && php tests/convert_logic.php` → three `ALL PASS`.
Run `php -l` on: `includes/booking.php`, `api/booking-addon.php`, `includes/app/concierge.php`, `includes/booking-manage-actions.php`, `admin/concierge-desk.php`, `admin/booking-request-action.php`, `admin/_layout.php` → all clean.

- [ ] **Step 2: Regression — existing kinds still submit**

Confirm the endpoint still accepts a plain concierge kind: seed nothing new, just verify `api/booking-addon.php`'s whitelist still contains the original kinds and the transfer/tour branches are intact (read the file).

- [ ] **Step 3: Browser E2E**

Start `php -S localhost:8765 router.php`. Seed a confirmed hold (with a venue). As the guest at `booking.php?ref=<REF>&view=concierge`:
  - Confirm the tile grid shows icons and includes **Laundry**.
  - Open Laundry → pick a service + a preferred time → submit → `.bm-status`/reload shows it; the request appears under "Recent requests" with **Requested** + the time.
  - Go to `&view=manage` → the laundry request shows in "Your requests" with the friendly status + time.
Then as admin at `/admin/concierge-desk.php` (needs admin session — if not logged in, at least confirm the page requires login; the reviewer/human can complete the logged-in portion). If logged in: the laundry request shows under **Open**; click **Accept** → redirects back to the desk, status becomes **In progress**; reload the guest concierge → status shows **In progress**; **Mark done** on the desk → guest shows **Done**. Verify the status + service filter chips change the list.

- [ ] **Step 4: Clean up**

Delete any seeded holds, availability blocks, and booking_addons rows created during E2E. Confirm baseline counts.

- [ ] **Step 5: Done**

No code changes here; if all green the branch is ready for final review.

---

## Self-Review Notes
- Spec coverage: migration (T1), laundry+scheduling+status helper+endpoint+tests (T2), concierge icons/laundry/scheduling/labels (T3), Your-requests labels (T4), Concierge Desk + return redirect (T5), verification (T6).
- Type consistency: `addon_status_label(string): string` used in concierge.php, booking-manage-actions.php, concierge-desk.php; `LAUNDRY_OPTIONS` used in api + concierge.php; `scheduled_for` written by the endpoint and read via `ba.*`/`fetch_booking_addons` everywhere.
- Reuse: the Desk actions post to the existing `booking-request-action.php`; only a whitelisted `return` was added — transition logic untouched.
- Admin status badge resolved: use `.badge` + `.badge--{orange,blue,green,red,grey}` (existing admin.css), mapped via the `$badgeClass` closure in the Desk.
