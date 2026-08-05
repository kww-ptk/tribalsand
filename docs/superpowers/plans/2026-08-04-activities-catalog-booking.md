# Activities Catalog & Booking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make activities per-property + priced (numeric, per-person/flat, max pax, "what's included"), let guests pick pax + a date within their stay with a live total, and snapshot pax/date/price onto each request.

**Architecture:** Extends the existing `tours` catalog. New `tour_venues` join for availability + structured columns on `tours`; `booking_addons.pax` for party size (date reuses `scheduled_for`, price reuses `price_amount`). Query/price helpers in `includes/booking.php` (+ `addon_pax_supported()` beside `addon_price_supported()` in `includes/services.php`). A single `insert_booking_addon()` helper replaces the two-branch insert. Portal `activities.php` gains a pax/date/total form; `admin/tour-edit.php` gains the new fields + a property checklist.

**Tech Stack:** Vanilla PHP 8.2, PDO `db_query()` (pgsql — **do not reuse a named placeholder**; use distinct names). Portal tokens (`.pa-*`), admin classes (`.card`, `.btn-sm`), `format_price()` from `includes/services.php`, `setting('site_currency')`.

**Conventions:** Escape all output `e()`; ids/pax `(int)`, prices `(float)`; prepared statements only. Owner admin mutations: `require_owner()` + `verify_csrf()` + `audit_log()` + PRG. Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/activities-catalog` — no branch switch, no push.

---

## File map

| File | Change |
|------|--------|
| `db/migrations/add_activity_booking.sql` | **Create** — `tour_venues` + tours cols + `booking_addons.pax` |
| `includes/services.php` | **Modify** — add `addon_pax_supported()` |
| `includes/booking.php` | **Modify** — venue-scoped `fetch_portal_activities`, `fetch_tour_for_booking`, `activity_venue_ids`, `activity_price_total`, `insert_booking_addon` |
| `tests/activities_logic.php` | **Create** — helper + scoping + snapshot tests |
| `api/booking-addon.php` | **Modify** — tour branch (availability + pax + date), use `insert_booking_addon` |
| `includes/app/activities.php` | **Modify** — venue scope, price, pax/date/total form + JS |
| `admin/tour-edit.php` | **Modify** — booking price / per-person / max pax / what's included + property checklist |
| `admin/concierge-desk.php` | **Modify** — show "· N pax" on activity requests |
| `admin/_ws_requests.php` | **Modify** — show "· N pax" on activity requests |

---

## Task 1: Migration

**Files:** Create `db/migrations/add_activity_booking.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Tribal Sand: per-property activities + structured booking price/capacity, and
-- a party-size snapshot on requests. Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS tour_venues (
    tour_id  INT NOT NULL REFERENCES tours(id)  ON DELETE CASCADE,
    venue_id INT NOT NULL REFERENCES venues(id) ON DELETE CASCADE,
    PRIMARY KEY (tour_id, venue_id)
);

ALTER TABLE tours
  ADD COLUMN IF NOT EXISTS price_amount     NUMERIC(10,2),
  ADD COLUMN IF NOT EXISTS price_per_person BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS max_pax          INT,
  ADD COLUMN IF NOT EXISTS whats_included   TEXT;

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS pax INT;
```

- [ ] **Step 2: Apply locally**

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_activity_booking.sql")); echo "applied\n";'`
Expected: `applied`

- [ ] **Step 3: Verify**

Run: `php -r 'require "includes/db.php"; db()->query("SELECT price_amount,price_per_person,max_pax,whats_included FROM tours LIMIT 1"); db()->query("SELECT 1 FROM tour_venues LIMIT 1"); db()->query("SELECT pax FROM booking_addons LIMIT 1"); echo "ok\n";'`
Expected: `ok` (no "does not exist" error).

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_activity_booking.sql
git commit -m "feat(db): per-property activities + booking price/pax columns

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Helpers (TDD)

**Files:**
- Modify: `includes/services.php` (add `addon_pax_supported()`)
- Modify: `includes/booking.php` (activity helpers + `insert_booking_addon`; change `fetch_portal_activities` signature)
- Test: `tests/activities_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/activities_logic.php`:

```php
<?php
declare(strict_types=1);
// Activities catalog & booking helpers. Run: php tests/activities_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function slugs(array $rows): array { return array_column($rows, 'slug'); }

// Two venues (need a second for scoping); skip cleanly if fewer than two exist.
$venues = db()->query("SELECT id FROM venues ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($venues) < 2) { echo "SKIP  need two venues\n\nALL PASS\n"; exit(0); }
$vA = (int)$venues[0]; $vB = (int)$venues[1];

// Two published tours: T1 unrestricted, T2 assigned to venue B only.
db_query("INSERT INTO tours (slug,name,category,is_published,price_amount,price_per_person,max_pax) VALUES ('zz-t1','ZZ T1','excursion',TRUE,1000,TRUE,4)");
$t1 = (int)db()->lastInsertId();
db_query("INSERT INTO tours (slug,name,category,is_published,price_amount,price_per_person,max_pax) VALUES ('zz-t2','ZZ T2','excursion',TRUE,1500,FALSE,6)");
$t2 = (int)db()->lastInsertId();
db_query("INSERT INTO tour_venues (tour_id,venue_id) VALUES (:t,:v)", [':t'=>$t2, ':v'=>$vB]);

$all = fetch_portal_activities(null);
check('activities(null) includes both', in_array('zz-t1', slugs($all), true) && in_array('zz-t2', slugs($all), true));

$atA = fetch_portal_activities($vA);
check('activities(A) includes the unrestricted T1', in_array('zz-t1', slugs($atA), true));
check('activities(A) excludes B-only T2',           !in_array('zz-t2', slugs($atA), true));

$atB = fetch_portal_activities($vB);
check('activities(B) includes both', in_array('zz-t1', slugs($atB), true) && in_array('zz-t2', slugs($atB), true));

check('tour_for_booking(T2,A) is false (wrong venue)', fetch_tour_for_booking('zz-t2', $vA) === false);
$okB = fetch_tour_for_booking('zz-t2', $vB);
check('tour_for_booking(T2,B) returns the row', $okB && (int)$okB['id'] === $t2);
db_query("UPDATE tours SET is_published=FALSE WHERE id=:id", [':id'=>$t1]);
check('tour_for_booking excludes unpublished', fetch_tour_for_booking('zz-t1', $vA) === false);
db_query("UPDATE tours SET is_published=TRUE WHERE id=:id", [':id'=>$t1]);

check('venue_ids(T2) = [vB]', activity_venue_ids($t2) === [$vB]);

$per  = ['price_amount'=>1000,'price_per_person'=>true];
$flat = ['price_amount'=>1500,'price_per_person'=>false];
check('price_total per-person × pax', activity_price_total($per, 3) === 3000.0);
check('price_total flat ignores pax',  activity_price_total($flat, 3) === 1500.0);
check('price_total null when unpriced', activity_price_total(['price_amount'=>null], 2) === null);

check('addon_pax_supported true after migration', addon_pax_supported() === true);

// insert_booking_addon snapshots pax + price.
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    $aid = insert_booking_addon(['hold_id'=>$hid,'kind'=>'tour','tour_id'=>$t2,'details'=>'ZZ activity','scheduled_for'=>null,'price_amount'=>3000,'pax'=>3]);
    $row = db_query("SELECT pax, price_amount FROM booking_addons WHERE id=:id", [':id'=>$aid])->fetch();
    check('insert_booking_addon stored pax + price', $row && (int)$row['pax'] === 3 && (float)$row['price_amount'] === 3000.0);
    db_query("DELETE FROM booking_addons WHERE id=:id", [':id'=>$aid]);
}

db_query("DELETE FROM tour_venues WHERE tour_id IN (:a,:b)", [':a'=>$t1, ':b'=>$t2]);
db_query("DELETE FROM tours WHERE id IN (:a,:b)", [':a'=>$t1, ':b'=>$t2]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/activities_logic.php`
Expected: FAIL / fatal `Call to undefined function fetch_tour_for_booking()`.

- [ ] **Step 3: Add `addon_pax_supported()` to `includes/services.php`**

Immediately after the `addon_price_supported()` function:

```php
/** True if booking_addons has the pax column (memoised). False pre-migration. */
function addon_pax_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'booking_addons' AND column_name = 'pax' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}
```

- [ ] **Step 4: Update `fetch_portal_activities` and add the activity helpers in `includes/booking.php`**

Replace the existing `fetch_portal_activities()` with:

```php
/**
 * Published activities for the portal, scoped to a property. $venueId null = all.
 * A tour with no tour_venues rows shows everywhere; otherwise only at its venues.
 * The venue clause is added in PHP (not `:vid IS NULL`) so :vid binds exactly once
 * — pgsql can't infer the type of a NULL-compared placeholder and won't reuse names.
 */
function fetch_portal_activities(?int $venueId = null): array {
    $venueClause = '';
    $params = [];
    if ($venueId !== null) {
        $venueClause = " AND (NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
                              OR EXISTS  (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :vid))";
        $params[':vid'] = $venueId;
    }
    try {
        return db_query(
            "SELECT t.id, t.slug, t.name, t.category, t.tag_label, t.duration, t.short_desc, t.long_desc,
                    t.price_amount, t.price_per_person, t.max_pax, t.whats_included,
                    (SELECT filename FROM tour_images ti WHERE ti.tour_id = t.id AND ti.is_hero = TRUE LIMIT 1) AS hero
             FROM tours t
             WHERE t.is_published = TRUE{$venueClause}
             ORDER BY t.sort_order ASC, t.name ASC",
            $params
        )->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** A published activity available at $venueId, by slug — else false (request-time validation). */
function fetch_tour_for_booking(string $slug, ?int $venueId): array|false {
    $venueClause = '';
    $params = [':slug' => $slug];
    if ($venueId !== null) {
        $venueClause = " AND (NOT EXISTS (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id)
                              OR EXISTS  (SELECT 1 FROM tour_venues tv WHERE tv.tour_id = t.id AND tv.venue_id = :vid))";
        $params[':vid'] = $venueId;
    }
    try {
        $r = db_query(
            "SELECT t.* FROM tours t WHERE t.slug = :slug AND t.is_published = TRUE{$venueClause}",
            $params
        )->fetch();
    } catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Venue ids an activity is offered at (empty = all properties). For the admin checklist. */
function activity_venue_ids(int $tourId): array {
    try {
        return array_map('intval', db_query("SELECT venue_id FROM tour_venues WHERE tour_id = :t", [':t' => $tourId])->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** Booking total for an activity given pax: per-person × pax, or the flat amount; null when unpriced. */
function activity_price_total(array $tour, int $pax): ?float {
    if (!isset($tour['price_amount']) || $tour['price_amount'] === null || $tour['price_amount'] === '') return null;
    $amt = (float) $tour['price_amount'];
    $pp  = $tour['price_per_person'] ?? false;
    $perPerson = ($pp === true || $pp === 't' || $pp === 'true' || $pp === 1 || $pp === '1');
    return $perPerson ? $amt * max(1, $pax) : $amt;
}

/**
 * Insert a booking_addon, including price_amount / pax only when those columns
 * exist (so every kind works pre- and post-migration). Returns the new id.
 */
function insert_booking_addon(array $d): int {
    $cols = ['hold_id', 'kind', 'tour_id', 'details', 'scheduled_for'];
    $vals = [':h', ':k', ':t', ':d', ':sf'];
    $p = [
        ':h' => $d['hold_id'], ':k' => $d['kind'], ':t' => $d['tour_id'] ?? null,
        ':d' => $d['details'] ?? '', ':sf' => $d['scheduled_for'] ?? null,
    ];
    if (addon_price_supported()) { $cols[] = 'price_amount'; $vals[] = ':price'; $p[':price'] = $d['price_amount'] ?? null; }
    if (addon_pax_supported())   { $cols[] = 'pax';          $vals[] = ':pax';   $p[':pax']   = $d['pax'] ?? null; }
    db_query('INSERT INTO booking_addons (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')', $p);
    return (int) db()->lastInsertId();
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `php tests/activities_logic.php`
Expected: all PASS, `ALL PASS`, exit 0.

- [ ] **Step 6: Regression**

Run: `php tests/portal_logic.php`
Expected: `ALL PASS` (the no-arg `fetch_portal_activities()` still works via the default).

- [ ] **Step 7: Commit**

```bash
git add includes/services.php includes/booking.php tests/activities_logic.php
git commit -m "feat(activities): venue-scoped catalog + booking helpers, with tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Request API — activity pax + date + snapshot

**Files:** Modify `api/booking-addon.php`

- [ ] **Step 1: Initialise snapshot vars**

Find `$priceSnapshot = null; // set for laundry/transfer from the catalog` (added by the pricing feature, just before `if ($kind === 'tour')`). Replace that line with:

```php
$priceSnapshot = null; // set for laundry/transfer/tour from the catalog
$paxValue      = null; // set for tour
$schedOverride = null; // tour date → scheduled_for (set after the generic sched block)
```

- [ ] **Step 2: Replace the tour branch**

Replace:

```php
if ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_by_slug($slug) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid tour.'])); }
    $tour_id = (int)$tour['id'];
    if ($details === '') $details = $tour['name'];
} elseif ($kind === 'transfer' || $kind === 'laundry') {
```

with:

```php
if ($kind === 'tour') {
    $slug = $str($data['tour_slug'] ?? '');
    $tour = $slug ? fetch_tour_for_booking($slug, isset($hold['venue_id']) ? (int)$hold['venue_id'] : null) : false;
    if (!$tour) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'That activity isn’t available for your stay.'])); }
    $tour_id = (int)$tour['id'];
    $cap = (int)($tour['max_pax'] ?? 0);
    $pax = (int)($data['pax'] ?? 1); if ($pax < 1) $pax = 1; if ($cap > 0 && $pax > $cap) $pax = $cap;
    $atDate = $str($data['at_date'] ?? '');
    $ts = $atDate !== '' ? strtotime($atDate) : false;
    if ($ts === false || $atDate < (string)$hold['check_in'] || $atDate > (string)$hold['check_out']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a date within your stay.']));
    }
    $paxValue      = $pax;
    $schedOverride = date('Y-m-d H:i:s', $ts);
    $priceSnapshot = activity_price_total($tour, $pax);
    $note = $str($data['details'] ?? '');
    $details = $tour['name'] . ' · ' . $pax . ' pax' . ($note !== '' ? ' — ' . $note : '');
} elseif ($kind === 'transfer' || $kind === 'laundry') {
```

- [ ] **Step 3: Apply the tour date override after the generic sched block**

Find the generic scheduled-for block:

```php
$sched = $str($data['scheduled_for'] ?? '');
$schedSql = null;
if ($sched !== '') {
    $ts = strtotime($sched);
    if ($ts !== false) $schedSql = date('Y-m-d H:i:s', $ts); // silently ignore an unparseable value
}
```

Immediately AFTER it, add:

```php
if ($schedOverride !== null) $schedSql = $schedOverride; // tour date wins over any preferred-time field
```

- [ ] **Step 4: Replace the two-branch insert with the helper**

Replace the whole `if (addon_price_supported()) { db_query(...) } else { db_query(...) }` block (the two-branch INSERT) with:

```php
    $addonId = insert_booking_addon([
        'hold_id'      => $hold['id'],
        'kind'         => $kind,
        'tour_id'      => $tour_id,
        'details'      => $details,
        'scheduled_for'=> $schedSql,
        'price_amount' => $priceSnapshot,
        'pax'          => $paxValue,
    ]);
```

(Delete the now-redundant `$addonId = (int)db()->lastInsertId();` line that followed the old insert — `insert_booking_addon` already returns it.)

- [ ] **Step 5: Lint + smoke test**

Run: `php -l api/booking-addon.php`
Expected: `No syntax errors detected`.
Run: `php tests/activities_logic.php && php tests/portal_logic.php` → both `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add api/booking-addon.php
git commit -m "feat(portal): activity requests validate availability, capture pax + date + price

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Guest Activities — price + pax/date/total form

**Files:** Modify `includes/app/activities.php`

- [ ] **Step 1: Scope to the property and read the currency**

At the top of `includes/app/activities.php`, replace:

```php
try { $__acts = fetch_portal_activities(); } catch (Throwable $e) { $__acts = []; }
```

with:

```php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__acts = fetch_portal_activities($__venue); } catch (Throwable $e) { $__acts = []; }
$__cur = setting('site_currency', 'USD');
$__ci  = (string)$hold['check_in']; $__co = (string)$hold['check_out'];
```

- [ ] **Step 2: Replace the card body (price + request form)**

In `includes/app/activities.php`, replace the card `<div class="pa-card__body"> … </div>` block with:

```php
    <div class="pa-card__body">
      <p class="pa-card__title"><?= e($a['name']) ?></p>
      <div class="pa-card__meta">
        <?php if (!empty($a['duration'])): ?><span><?= e($a['duration']) ?></span><?php endif; ?>
        <?php
          $__pp = ($a['price_per_person'] ?? false);
          $__perPerson = ($__pp === true || $__pp === 't' || $__pp === '1' || $__pp === 1 || $__pp === 'true');
          $__amt = ($a['price_amount'] ?? null);
          if ($__amt !== null && $__amt !== '' && (float)$__amt > 0):
        ?><span style="font-weight:600;color:var(--pa-ink)"><?= e(format_price((float)$__amt, $__cur)) ?><?= $__perPerson ? ' / person' : '' ?></span><?php endif; ?>
        <?php if (!empty($a['short_desc'])): ?><span style="flex-basis:100%;margin-top:4px;color:var(--pa-muted)"><?= e($a['short_desc']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($a['whats_included'])): ?>
      <div style="margin-top:8px;font-size:13px;color:var(--pa-muted)"><strong style="color:var(--pa-ink);font-weight:600">What’s included:</strong> <?= e($a['whats_included']) ?></div>
      <?php endif; ?>
      <?php if ($__active): ?>
      <?php $__cap = (int)($a['max_pax'] ?? 0); $__cap = $__cap > 0 ? $__cap : 8; ?>
      <button type="button" class="pa-btn pa-btn--primary act-toggle" style="margin-top:12px">Request</button>
      <form data-bm action="/api/booking-addon.php" class="act-form" style="display:none;margin-top:10px"
            data-price="<?= e((string)($__amt !== null ? (float)$__amt : 0)) ?>" data-perperson="<?= $__perPerson ? '1' : '0' ?>" data-cur="<?= e($__cur) ?>">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="kind" value="tour">
        <input type="hidden" name="tour_slug" value="<?= e($a['slug']) ?>">
        <label class="pa-field">Guests
          <select name="pax" class="act-pax"><?php for ($__i = 1; $__i <= $__cap; $__i++): ?><option value="<?= $__i ?>"><?= $__i ?></option><?php endfor; ?></select>
        </label>
        <label class="pa-field">Date
          <input type="date" name="at_date" required min="<?= e($__ci) ?>" max="<?= e($__co) ?>" value="<?= e($__ci) ?>">
        </label>
        <label class="pa-field">Notes (optional)<input type="text" name="details"></label>
        <?php if ($__amt !== null && (float)$__amt > 0): ?><p class="act-total" style="font-weight:600;margin:2px 0 8px"></p><?php endif; ?>
        <button type="submit" class="pa-btn pa-btn--primary">Request activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
```

- [ ] **Step 3: Add the toggle + live-total script**

In `includes/app/activities.php`, inside the existing `<script>` IIFE (before its closing `})();`), add:

```js
  // Activity request: reveal the form and keep a live total.
  function fmtTotal(form){
    var t = form.querySelector('.act-total'); if(!t) return;
    var unit = parseFloat(form.dataset.price)||0, per = form.dataset.perperson==='1';
    var pax = parseInt(form.querySelector('.act-pax').value,10)||1;
    var total = per ? unit*pax : unit;
    t.textContent = 'Total: ' + form.dataset.cur + ' ' + total.toLocaleString();
  }
  document.querySelectorAll('.pa-card .act-toggle').forEach(function(btn){
    var form = btn.parentNode.querySelector('.act-form');
    btn.addEventListener('click', function(){
      var open = form.style.display !== 'none';
      form.style.display = open ? 'none' : 'block';
      if(!open){ fmtTotal(form); form.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    });
  });
  document.querySelectorAll('.act-form').forEach(function(form){
    var pax = form.querySelector('.act-pax');
    if(pax) pax.addEventListener('change', function(){ fmtTotal(form); });
  });
```

- [ ] **Step 4: Verify (local, in-app browser)**

`php -l includes/app/activities.php` clean. With the dev server + a seeded hold on a venue that has a priced activity: Activities tab shows only that property's activities with the unit price; tapping **Request** reveals pax/date/notes + a live total that updates with pax; the date picker is limited to the stay; submitting shows the **"Request sent" popup**; the created `booking_addons` row has `pax`, `scheduled_for` (the chosen date), and the snapshotted `price_amount`. Clean up the test request.

- [ ] **Step 5: Commit**

```bash
git add includes/app/activities.php
git commit -m "feat(portal): activity cards show price; request with pax + date + live total

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Admin — activity price/pax/included + property availability

**Files:** Modify `admin/tour-edit.php`

- [ ] **Step 1: Extend the `save_details` write + sync `tour_venues`**

In `admin/tour-edit.php`, in the `save_details` handler, extend the `$data` array (after the `:highlights` entry) with:

```php
                ':price_amount'  => ($_POST['price_amount'] ?? '') === '' ? null : (float)$_POST['price_amount'],
                ':per_person'    => isset($_POST['price_per_person']) ? 'TRUE' : 'FALSE',
                ':max_pax'       => ($_POST['max_pax'] ?? '') === '' ? null : (int)$_POST['max_pax'],
                ':whats_included'=> trim($_POST['whats_included'] ?? ''),
```

Update the INSERT to include the new columns:

```php
                db_query(
                    "INSERT INTO tours (name,slug,category,tag_label,duration,price,short_desc,long_desc,highlights_json,
                                        price_amount,price_per_person,max_pax,whats_included)
                     VALUES (:name,:slug,:category,:tag_label,:duration,:price,:short_desc,:long_desc,:highlights,
                             :price_amount,:per_person,:max_pax,:whats_included)",
                    $data
                );
                $id   = (int)db()->lastInsertId();
                sync_tour_venues($id, (array)($_POST['venue_ids'] ?? []));   // helper defined in Step 2
                $tour = db_query('SELECT * FROM tours WHERE id = :id', [':id' => $id])->fetch();
                $isNew = false;
                header("Location: /admin/tour-edit.php?id={$id}&saved=1");
                exit;
```

And the UPDATE:

```php
                $data[':id'] = $id;
                db_query(
                    "UPDATE tours SET name=:name,slug=:slug,category=:category,tag_label=:tag_label,
                     duration=:duration,price=:price,short_desc=:short_desc,long_desc=:long_desc,highlights_json=:highlights,
                     price_amount=:price_amount,price_per_person=:per_person,max_pax=:max_pax,whats_included=:whats_included,
                     updated_at=NOW() WHERE id=:id",
                    $data
                );
                sync_tour_venues($id, (array)($_POST['venue_ids'] ?? []));
                $success = 'Details saved.';
```

- [ ] **Step 2: Add the `sync_tour_venues` helper**

At the top of `admin/tour-edit.php`, after the `require`s (before the POST handling), add:

```php
/** Replace an activity's property assignments with the posted set (ints, existing venues only). */
function sync_tour_venues(int $tourId, array $venueIds): void {
    db_query("DELETE FROM tour_venues WHERE tour_id = :t", [':t' => $tourId]);
    $valid = db_query("SELECT id FROM venues")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique(array_map('intval', $venueIds)) as $vid) {
        if (in_array($vid, array_map('intval', $valid), true)) {
            db_query("INSERT INTO tour_venues (tour_id, venue_id) VALUES (:t,:v) ON CONFLICT DO NOTHING", [':t' => $tourId, ':v' => $vid]);
        }
    }
}
```

- [ ] **Step 3: Add the fields + property checklist to the details form**

In `admin/tour-edit.php`, inside the `save_details` `<form>` (near the existing marketing `price` input at ~line 255), add these fields (e.g. after the `price` input):

```php
      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">Booking price <span style="color:var(--muted);font-weight:400">(numeric — used for guest requests &amp; the bill)</span></label>
        <input type="number" name="price_amount" step="0.01" min="0" value="<?= e($tour['price_amount'] ?? '') ?>" style="width:160px;padding:8px 10px">
        <label style="margin-left:14px;font-size:13px"><input type="checkbox" name="price_per_person" value="1" <?= (($tour['price_per_person'] ?? true) && ($tour['price_per_person'] ?? 't') !== 'f') ? 'checked' : '' ?>> Price is per person</label>
        <label style="margin-left:14px;font-size:13px">Max pax <input type="number" name="max_pax" min="1" value="<?= e($tour['max_pax'] ?? '') ?>" style="width:80px;padding:8px 10px"></label>
      </div>

      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:4px">What’s included</label>
        <textarea name="whats_included" rows="3" style="width:100%;max-width:640px;padding:8px 10px" placeholder="e.g. Return transfers, guide, entrance fees, bottled water"><?= e($tour['whats_included'] ?? '') ?></textarea>
      </div>

      <div style="margin:14px 0">
        <label style="display:block;font-size:13px;color:var(--muted);margin-bottom:6px">Available at <span style="color:var(--muted);font-weight:400">(none ticked = shown at every property)</span></label>
        <?php
          $__assigned = $id ? activity_venue_ids((int)$id) : [];
          $__venues   = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
          foreach ($__venues as $__v):
        ?>
        <label style="display:inline-flex;align-items:center;gap:6px;margin:0 14px 8px 0;font-size:13px">
          <input type="checkbox" name="venue_ids[]" value="<?= (int)$__v['id'] ?>" <?= in_array((int)$__v['id'], $__assigned, true) ? 'checked' : '' ?>>
          <?= e($__v['name']) ?>
        </label>
        <?php endforeach; ?>
      </div>
```

(`activity_venue_ids()` is available because `admin/tour-edit.php` loads `includes/booking.php`. If it doesn't, add `require_once __DIR__ . '/../includes/booking.php';` near the top.)

- [ ] **Step 4: Verify (local)**

`php -l admin/tour-edit.php` clean. As owner, open an activity in `tour-edit.php`: set a booking price + per-person + max pax + what's included, tick one property, Save. Reload → values persist; `tour_venues` has the row (`php -r '...SELECT * FROM tour_venues...'`). Untick all + Save → the guest sees it at every property again.

- [ ] **Step 5: Commit**

```bash
git add admin/tour-edit.php
git commit -m "feat(admin): activity booking price/per-person/max pax/what's included + property availability

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Show pax on admin request views

**Files:** Modify `admin/concierge-desk.php`, `admin/_ws_requests.php`

- [ ] **Step 1: Concierge desk — append pax for activities**

In `admin/concierge-desk.php`, in the request cell (which already appends the price badge), add the pax right after `addon_label($a)` and before the price badge:

```php
          <td><?= e(addon_label($a)) ?><?php if (($a['kind'] ?? '') === 'tour' && !empty($a['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$a['pax'] ?> pax</span><?php endif; ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?></td>
```

- [ ] **Step 2: Workspace requests — same**

In `admin/_ws_requests.php`, in the details cell, insert the pax span right after `addon_label($a)` (before the existing price badge):

```php
        <td><?= e(addon_label($a)) ?><?php if (($a['kind'] ?? '') === 'tour' && !empty($a['pax'])): ?> <span class="text-muted" style="font-size:12px">· <?= (int)$a['pax'] ?> pax</span><?php endif; ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?><?php if (!empty($a['scheduled_for'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?></td>
```

- [ ] **Step 3: Verify + commit**

`php -l admin/concierge-desk.php admin/_ws_requests.php` clean. An activity request shows "· N pax" and its price on the desk and the workspace.

```bash
git add admin/concierge-desk.php admin/_ws_requests.php
git commit -m "feat(admin): show pax on activity requests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Full regression + verification

- [ ] **Step 1: Test suites** — `php tests/activities_logic.php`, `php tests/services_logic.php`, `php tests/portal_logic.php`, `php tests/frontdesk_logic.php` → all `ALL PASS`.
- [ ] **Step 2: Lint** — `for f in includes/services.php includes/booking.php api/booking-addon.php includes/app/activities.php admin/tour-edit.php admin/concierge-desk.php admin/_ws_requests.php; do php -l "$f"; done` → all clean.
- [ ] **Step 3: End-to-end (in-app browser)** — as owner, on an activity set booking price/per-person/max pax/what's included and assign it to a property; as a guest on a hold at that property, open Activities → the activity shows with price → Request → pick pax + date, live total updates → submit → popup → the request carries pax + date + snapshot price and appears (with "· N pax" + price) on the Concierge Desk. An activity assigned to a *different* property does NOT appear for this guest. Clean up test data (tours, tour_venues, addons) afterward.
- [ ] **Step 4: Final review** — use superpowers:requesting-code-review against this plan + spec; fix findings before finishing.

---

## Rollout

Run `db/migrations/add_activity_booking.sql` via **/admin/migrate.php** after deploy. Then per activity in `admin/tour-edit.php`: set booking price / per-person / max pax / what's included, and tick the properties it's offered at (none = everywhere).
