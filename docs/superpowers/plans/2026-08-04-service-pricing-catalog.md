# Service Pricing Catalog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded laundry/transfer option arrays with an owner-editable DB catalog (label + price + active + order), show prices to guests, and snapshot each request's price onto `booking_addons`.

**Architecture:** New `service_options` table + a `booking_addons.price_amount` snapshot column (one migration). Query/format helpers isolated in `includes/services.php` (required from `includes/booking.php`, so available wherever booking logic loads). The guest portal, request API, a new owner-only `admin/services.php` CRUD page, and the admin request views all read the catalog. Tested via `tests/services_logic.php`.

**Tech Stack:** Vanilla PHP 8.2, PostgreSQL via PDO `db_query()`. Admin UI classes: `.card`, `.card__body`, `.badge`, `.btn-sm btn-primary|btn-outline|btn-danger`, `.page-header`, `var(--muted)`. Owner mutations: `require_owner()` + `verify_csrf()` + `audit_log()` + PRG.

**Conventions:** Escape all output with `e()`. Prepared statements only. Currency from `setting('site_currency','USD')`. Commit trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/service-pricing` — no branch switch, no push.

---

## File map

| File | Change |
|------|--------|
| `db/migrations/add_service_options.sql` | **Create** — table + `booking_addons.price_amount` + seed |
| `includes/services.php` | **Create** — `fetch_service_options` / `fetch_service_option` / `format_price` / `addon_price_supported` |
| `includes/booking.php` | **Modify** — `require_once services.php`; remove `LAUNDRY_OPTIONS`/`TRANSFER_OPTIONS` consts |
| `tests/services_logic.php` | **Create** — helper + snapshot tests |
| `api/booking-addon.php` | **Modify** — validate option id via catalog; snapshot price into the insert |
| `includes/app/_services.php` | **Modify** — render catalog options with prices; submit option id |
| `admin/services.php` | **Create** — owner CRUD (add/save/delete/reorder) |
| `admin/_layout.php` | **Modify** — "Service pricing" nav link (owner-only) |
| `admin/concierge-desk.php` | **Modify** — show captured price |
| `admin/_ws_requests.php` | **Modify** — show captured price |

---

## Task 1: Migration — `service_options` table + price snapshot column

**Files:**
- Create: `db/migrations/add_service_options.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Tribal Sand: owner-editable service pricing catalog (laundry & transfer),
-- plus a price snapshot on requests. Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS service_options (
    id           SERIAL PRIMARY KEY,
    service      VARCHAR(20)   NOT NULL CHECK (service IN ('laundry','transfer')),
    label        VARCHAR(120)  NOT NULL,
    price_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    is_active    BOOLEAN       NOT NULL DEFAULT TRUE,
    sort_order   INT           NOT NULL DEFAULT 0,
    created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_service_options_service ON service_options (service, sort_order);

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

-- Seed today's fixed options (prices 0 — owner fills them in). Only when the
-- table is empty, so re-running never duplicates.
INSERT INTO service_options (service, label, price_amount, sort_order)
SELECT v.service, v.label, v.price_amount, v.sort_order FROM (VALUES
    ('laundry','Wash & fold',0,1),
    ('laundry','Ironing',0,2),
    ('laundry','Dry-clean',0,3),
    ('laundry','Wash & iron',0,4),
    ('transfer','Airport → Property',0,1),
    ('transfer','Property → Airport',0,2),
    ('transfer','Between properties',0,3),
    ('transfer','Custom transfer',0,4)
) AS v(service,label,price_amount,sort_order)
WHERE NOT EXISTS (SELECT 1 FROM service_options);
```

- [ ] **Step 2: Apply it to the local dev DB**

Run: `php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_service_options.sql")); echo "applied\n";'`
Expected: `applied`

- [ ] **Step 3: Verify the table + column + seed**

Run: `php -r 'require "includes/db.php"; var_dump((int)db()->query("SELECT COUNT(*) FROM service_options")->fetchColumn()); var_dump(db()->query("SELECT price_amount FROM booking_addons LIMIT 1")->fetch() !== false || true);'`
Expected: an int count of `8` (the seeded rows), and no "column/table does not exist" error.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/add_service_options.sql
git commit -m "feat(db): service pricing catalog table + addon price snapshot

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Helpers `includes/services.php` (TDD)

**Files:**
- Create: `includes/services.php`
- Modify: `includes/booking.php` (add `require_once` at top; remove the two consts)
- Test: `tests/services_logic.php`

- [ ] **Step 1: Write the failing test**

Create `tests/services_logic.php`:

```php
<?php
declare(strict_types=1);
// Service pricing helpers. Run: php tests/services_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// format_price — pure formatting, explicit currency avoids depending on settings.
check('format_price: whole number, no decimals', format_price(500, 'KES') === 'KES 500');
check('format_price: fractional keeps 2dp',      format_price(12.5, 'KES') === 'KES 12.50');
check('format_price: thousands separator',       format_price(1500, 'USD') === 'USD 1,500');

// Seed two catalog rows (one active, one inactive).
db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES ('laundry','ZZ Active',500,TRUE,900)");
$activeId = (int)db()->lastInsertId();
db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES ('laundry','ZZ Inactive',0,FALSE,901)");
$inactiveId = (int)db()->lastInsertId();

$active = fetch_service_options('laundry', true);
$labels = array_column($active, 'label');
check('fetch_service_options(active) includes the active row', in_array('ZZ Active', $labels, true));
check('fetch_service_options(active) excludes the inactive row', !in_array('ZZ Inactive', $labels, true));

$all = fetch_service_options('laundry', false);
check('fetch_service_options(all) includes the inactive row', in_array('ZZ Inactive', array_column($all, 'label'), true));
check('fetch_service_options rejects unknown service', fetch_service_options('bogus') === []);

$one = fetch_service_option($activeId);
check('fetch_service_option returns the row', $one && $one['service'] === 'laundry' && (float)$one['price_amount'] === 500.0);
check('fetch_service_option: false for missing id', fetch_service_option(-1) === false);

check('addon_price_supported is true after migration', addon_price_supported() === true);

// Snapshot: an addon insert carrying the option's price stores it.
$hid = (int)(db()->query("SELECT id FROM holds ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($hid) {
    db_query("INSERT INTO booking_addons (hold_id, kind, details, price_amount) VALUES (:h,'laundry','ZZ Active',:p)",
             [':h'=>$hid, ':p'=>(float)$one['price_amount']]);
    $aid = (int)db()->lastInsertId();
    $stored = db_query("SELECT price_amount FROM booking_addons WHERE id=:id", [':id'=>$aid])->fetch();
    check('addon price snapshot stored', $stored && (float)$stored['price_amount'] === 500.0);
    db_query("DELETE FROM booking_addons WHERE id=:id", [':id'=>$aid]);
}

db_query("DELETE FROM service_options WHERE id IN (:a,:b)", [':a'=>$activeId, ':b'=>$inactiveId]);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/services_logic.php`
Expected: fatal `Failed opening require '.../includes/services.php'`.

- [ ] **Step 3: Create the helpers**

Create `includes/services.php`:

```php
<?php
declare(strict_types=1);
/** Service pricing catalog helpers (laundry & transfer). Depends on includes/db.php. */

/** Active (or all) options for a service, ordered for display. [] on bad service / missing table. */
function fetch_service_options(string $service, bool $activeOnly = true): array {
    if (!in_array($service, ['laundry','transfer'], true)) return [];
    $sql = "SELECT * FROM service_options WHERE service = :s"
         . ($activeOnly ? " AND is_active = TRUE" : "")
         . " ORDER BY sort_order ASC, id ASC";
    try { return db_query($sql, [':s' => $service])->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** One option by id (any service / active state), or false. */
function fetch_service_option(int $id): array|false {
    try { $r = db_query("SELECT * FROM service_options WHERE id = :id", [':id' => $id])->fetch(); }
    catch (Throwable $e) { return false; }
    return $r ?: false;
}

/** Format a money amount with the site currency: "KES 500", "USD 12.50". */
function format_price(float|int $amount, ?string $currency = null): string {
    $currency = $currency ?? setting('site_currency', 'USD');
    $amount   = (float) $amount;
    $formatted = ($amount == floor($amount)) ? number_format($amount, 0) : number_format($amount, 2);
    return trim($currency . ' ' . $formatted);
}

/** True if booking_addons has the price_amount snapshot column (memoised). False pre-migration. */
function addon_price_supported(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $r = db_query(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'booking_addons' AND column_name = 'price_amount' LIMIT 1"
        )->fetch();
        return $cached = (bool) $r;
    } catch (Throwable $e) { return $cached = false; }
}
```

- [ ] **Step 4: Wire it into `includes/booking.php` and remove the old consts**

At the top of `includes/booking.php`, just after its opening `<?php declare(strict_types=1);` / initial requires, add:

```php
require_once __DIR__ . '/services.php';
```

Then delete the two `const` blocks from `includes/booking.php` (the `TRANSFER_OPTIONS` array and the `LAUNDRY_OPTIONS` array, including their doc comments). They are replaced by the catalog. (Their only other usages — `api/booking-addon.php` and `includes/app/_services.php` — are updated in Tasks 3 and 4; leaving the consts removed here is fine because this branch updates those in the same series.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/services_logic.php`
Expected: all checks PASS, final `ALL PASS`, exit 0.

- [ ] **Step 6: Verify booking.php still loads**

Run: `php -r 'require "includes/booking.php"; echo function_exists("fetch_service_options") ? "ok\n" : "missing\n";'`
Expected: `ok` (services helpers available via booking.php; no "undefined constant" fatal).

- [ ] **Step 7: Commit**

```bash
git add includes/services.php includes/booking.php tests/services_logic.php
git commit -m "feat(services): pricing catalog helpers; retire hardcoded option consts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Request API — validate option id + snapshot price

**Files:**
- Modify: `api/booking-addon.php` (the `transfer`/`laundry` branches and the INSERT)

- [ ] **Step 1: Replace the transfer + laundry validation branches**

In `api/booking-addon.php`, replace these two `elseif` branches:

```php
} elseif ($kind === 'transfer') {
    $opt = $str($data['transfer'] ?? '');
    if (!array_key_exists($opt, TRANSFER_OPTIONS)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a transfer option.'])); }
    $label = TRANSFER_OPTIONS[$opt];
    $details = $details === '' ? $label : "{$label} — {$details}";
} elseif ($kind === 'laundry') {
    $opt = $str($data['service'] ?? '');
    if (!array_key_exists($opt, LAUNDRY_OPTIONS)) { http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a laundry service.'])); }
    $label   = LAUNDRY_OPTIONS[$opt];
    $details = $details === '' ? $label : "{$label} — {$details}";
} else { // itinerary / other
```

with (validates the posted option id against the catalog and captures the price):

```php
} elseif ($kind === 'transfer' || $kind === 'laundry') {
    $optId = (int)($data[$kind === 'laundry' ? 'service' : 'transfer'] ?? 0);
    $opt   = fetch_service_option($optId);
    if (!$opt || $opt['service'] !== $kind || !$opt['is_active']) {
        http_response_code(422); exit(json_encode(['ok'=>false,'error'=>'Please choose a valid option.']));
    }
    $label   = $opt['label'];
    $details = $details === '' ? $label : "{$label} — {$details}";
    $priceSnapshot = (float)$opt['price_amount'];
} else { // itinerary / other
```

- [ ] **Step 2: Initialise the snapshot before the branches**

Immediately after the line `$tour_id = null;` (just before the `if ($kind === 'tour') {` block), add:

```php
$priceSnapshot = null; // set for laundry/transfer from the catalog
```

- [ ] **Step 3: Include the price in the INSERT (only when the column exists)**

Replace the `db_query( "INSERT INTO booking_addons ...` call:

```php
    db_query(
        "INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for)
         VALUES (:h, :k, :t, :d, :sf)",
        [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details, ':sf'=>$schedSql]
    );
```

with:

```php
    if (addon_price_supported()) {
        db_query(
            "INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for, price_amount)
             VALUES (:h, :k, :t, :d, :sf, :price)",
            [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details, ':sf'=>$schedSql, ':price'=>$priceSnapshot]
        );
    } else {
        db_query(
            "INSERT INTO booking_addons (hold_id, kind, tour_id, details, scheduled_for)
             VALUES (:h, :k, :t, :d, :sf)",
            [':h'=>$hold['id'], ':k'=>$kind, ':t'=>$tour_id, ':d'=>$details, ':sf'=>$schedSql]
        );
    }
```

- [ ] **Step 4: Lint**

Run: `php -l api/booking-addon.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add api/booking-addon.php
git commit -m "feat(portal): validate service option from catalog, snapshot its price

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Guest portal — render catalog options with prices

**Files:**
- Modify: `includes/app/_services.php` (the laundry + transfer `<select>` loops)

- [ ] **Step 1: Replace the laundry option loop**

In `includes/app/_services.php`, replace:

```php
        <select name="service" required>
          <option value="">— select —</option>
          <?php foreach (LAUNDRY_OPTIONS as $__lk => $__ll): ?><option value="<?= e($__lk) ?>"><?= e($__ll) ?></option><?php endforeach; ?>
        </select>
```

with:

```php
        <select name="service" required>
          <option value="">— select —</option>
          <?php $__laundry = fetch_service_options('laundry'); ?>
          <?php if (!$__laundry): ?><option value="" disabled>— none available —</option><?php endif; ?>
          <?php foreach ($__laundry as $__o): ?><option value="<?= (int)$__o['id'] ?>"><?= e($__o['label'] . ((float)$__o['price_amount'] > 0 ? ' — ' . format_price($__o['price_amount']) : '')) ?></option><?php endforeach; ?>
        </select>
```

- [ ] **Step 2: Replace the transfer option loop**

In `includes/app/_services.php`, replace:

```php
        <select name="transfer" required>
          <option value="">— select —</option>
          <?php foreach (TRANSFER_OPTIONS as $__tk => $__tl): ?><option value="<?= e($__tk) ?>"><?= e($__tl) ?></option><?php endforeach; ?>
        </select>
```

with:

```php
        <select name="transfer" required>
          <option value="">— select —</option>
          <?php $__transfer = fetch_service_options('transfer'); ?>
          <?php if (!$__transfer): ?><option value="" disabled>— none available —</option><?php endif; ?>
          <?php foreach ($__transfer as $__o): ?><option value="<?= (int)$__o['id'] ?>"><?= e($__o['label'] . ((float)$__o['price_amount'] > 0 ? ' — ' . format_price($__o['price_amount']) : '')) ?></option><?php endforeach; ?>
        </select>
```

- [ ] **Step 3: Lint**

Run: `php -l includes/app/_services.php`
Expected: `No syntax errors detected`. (`fetch_service_options`/`format_price` are available because `booking.php` — which requires `services.php` — is loaded by `booking.php` the page before this partial renders.)

- [ ] **Step 4: Commit**

```bash
git add includes/app/_services.php
git commit -m "feat(portal): laundry/transfer options come from the priced catalog

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Admin — `admin/services.php` CRUD + nav link

**Files:**
- Create: `admin/services.php`
- Modify: `admin/_layout.php` (owner-only nav link)

- [ ] **Step 1: Create the admin page**

Create `admin/services.php`:

```php
<?php
/**
 * Admin: Service pricing — owner-editable laundry & transfer catalogs.
 * Add / rename / price / toggle active / delete / drag-reorder each option.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services.php';
require_login();
require_owner();

$SERVICES = ['laundry' => 'Laundry', 'transfer' => 'Transfer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $svc   = $_POST['service'] ?? '';
        $label = trim($_POST['label'] ?? '');
        $price = (float)($_POST['price_amount'] ?? 0);
        if (isset($SERVICES[$svc]) && $label !== '' && $price >= 0) {
            $max = (int)db_query("SELECT COALESCE(MAX(sort_order),0) AS m FROM service_options WHERE service=:s", [':s'=>$svc])->fetch()['m'];
            db_query("INSERT INTO service_options (service,label,price_amount,is_active,sort_order) VALUES (:s,:l,:p,TRUE,:o)",
                [':s'=>$svc, ':l'=>$label, ':p'=>$price, ':o'=>$max+1]);
            audit_log('service_option.add', 'service_option', (int)db()->lastInsertId(), "{$svc}: {$label}");
        }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $price = (float)($_POST['price_amount'] ?? 0);
        $active = isset($_POST['is_active']) ? 'TRUE' : 'FALSE';
        if ($id && $label !== '' && $price >= 0) {
            db_query("UPDATE service_options SET label=:l, price_amount=:p, is_active=:a WHERE id=:id",
                [':l'=>$label, ':p'=>$price, ':a'=>$active, ':id'=>$id]);
            audit_log('service_option.save', 'service_option', $id);
        }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { db_query("DELETE FROM service_options WHERE id=:id", [':id'=>$id]); audit_log('service_option.delete', 'service_option', $id); }
        header('Location: /admin/services.php'); exit;
    }

    if ($action === 'reorder') {
        $svc = $_POST['service'] ?? '';
        if (isset($SERVICES[$svc])) {
            foreach ((array)(json_decode($_POST['order'] ?? '[]', true) ?: []) as $o => $iid) {
                db_query("UPDATE service_options SET sort_order=:o WHERE id=:id AND service=:s", [':o'=>(int)$o, ':id'=>(int)$iid, ':s'=>$svc]);
            }
        }
        header('Content-Type: application/json'); exit(json_encode(['ok'=>true]));
    }
}

$pageTitle  = 'Service pricing';
$activeMenu = 'services';
$currency   = setting('site_currency', 'USD');
include __DIR__ . '/_layout.php';
?>

<style>
.svc-list{display:flex;flex-direction:column;gap:8px}
.svc-row{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:10px;padding:8px 10px}
.svc-row.is-off{opacity:.55}
.svc-handle{cursor:grab;color:var(--muted);font-size:16px;user-select:none;padding:0 2px}
.svc-row input[type=text]{flex:1;min-width:120px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px;font:inherit}
.svc-row input[type=number]{width:110px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px;font:inherit}
.svc-cur{color:var(--muted);font-size:12px}
.svc-add{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
</style>

<div class="page-header">
  <h1>Service pricing</h1>
  <a href="/admin/settings.php" class="btn-outline btn-sm">← Settings</a>
</div>
<p class="text-muted" style="margin:0 0 16px;font-size:13px">Guests see active options with their price when requesting laundry or transfers. A price of 0 shows the label only. Drag to reorder.</p>

<?php foreach ($SERVICES as $svc => $svcLabel):
    $rows = fetch_service_options($svc, false); ?>
<div class="card" style="margin-bottom:18px">
  <div class="card__head"><span class="card__title"><?= e($svcLabel) ?></span></div>
  <div class="card__body">
    <div class="svc-list" id="svc-<?= e($svc) ?>" data-service="<?= e($svc) ?>">
      <?php foreach ($rows as $r): ?>
      <form method="POST" action="/admin/services.php" class="svc-row <?= $r['is_active'] ? '' : 'is-off' ?>" draggable="true" data-id="<?= (int)$r['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <span class="svc-handle" title="Drag to reorder" aria-hidden="true">⠿</span>
        <input type="text" name="label" value="<?= e($r['label']) ?>" required>
        <span class="svc-cur"><?= e($currency) ?></span>
        <input type="number" name="price_amount" value="<?= e(rtrim(rtrim(number_format((float)$r['price_amount'], 2, '.', ''), '0'), '.')) ?>" min="0" step="0.01">
        <label style="font-size:12px;color:var(--muted);white-space:nowrap"><input type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>> Active</label>
        <button type="submit" name="action" value="save" class="btn-sm btn-primary">Save</button>
        <button type="submit" name="action" value="delete" class="btn-sm btn-danger" onclick="return confirm('Delete this option?')">Delete</button>
      </form>
      <?php endforeach; ?>
      <?php if (!$rows): ?><p class="text-muted" style="margin:0">No options yet — add one below.</p><?php endif; ?>
    </div>

    <form method="POST" action="/admin/services.php" class="svc-add">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="service" value="<?= e($svc) ?>">
      <input type="text" name="label" placeholder="New <?= e(strtolower($svcLabel)) ?> option" required style="flex:1;min-width:160px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px">
      <span class="svc-cur"><?= e($currency) ?></span>
      <input type="number" name="price_amount" value="0" min="0" step="0.01" style="width:110px;padding:7px 9px;border:1px solid #d9d2c6;border-radius:6px">
      <button type="submit" class="btn-sm btn-outline">+ Add option</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('.svc-list').forEach(function(list){
    var svc = list.dataset.service, dragged = null;
    list.querySelectorAll('.svc-row').forEach(function(row){
      row.addEventListener('dragstart', function(e){ dragged = row; row.style.opacity = '.4'; });
      row.addEventListener('dragend',   function(){ dragged = null; row.style.opacity = ''; save(); });
      row.addEventListener('dragover',  function(e){ e.preventDefault(); });
      row.addEventListener('dragenter', function(e){ e.preventDefault(); if (dragged && dragged !== row) { var k=[].slice.call(list.querySelectorAll('.svc-row')); var di=k.indexOf(dragged), ri=k.indexOf(row); list.insertBefore(dragged, di<ri ? row.nextSibling : row); } });
    });
    // Don't start a drag from inside the text/number inputs.
    list.querySelectorAll('input').forEach(function(i){ i.addEventListener('mousedown', function(e){ e.stopPropagation(); }); });
    function save(){
      var ids = [].slice.call(list.querySelectorAll('.svc-row')).map(function(x){ return x.dataset.id; });
      var fd = new FormData(); fd.append('action','reorder'); fd.append('service', svc); fd.append('order', JSON.stringify(ids)); fd.append('csrf_token', CSRF);
      fetch('/admin/services.php', { method:'POST', body:fd });
    }
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: Add the owner-only nav link**

In `admin/_layout.php`, find the Settings nav link (`href="/admin/settings.php"`, inside the second `<?php if (!is_staff()): ?>` owner block) and insert this immediately before it:

```php
      <a href="/admin/services.php"     class="sidebar__link <?= ($activeMenu??'')==='services'     ? 'is-active':'' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="1.6" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="8" cy="18" r="1.6" fill="currentColor" stroke="none"/></svg>
        Service pricing
      </a>
```

- [ ] **Step 3: Verify (local, in-app browser)**

Confirm `php -l admin/services.php` and `php -l admin/_layout.php` are clean. With the dev server running and logged in as owner, open `/admin/services.php`: two sections (Laundry, Transfer) list the seeded options; editing a price + Save persists; Add appends a row; Delete removes; toggling Active greys the row after save; dragging a row reorders and the order survives a reload. Confirm the "Service pricing" nav item shows for owner and NOT for staff.

- [ ] **Step 4: Commit**

```bash
git add admin/services.php admin/_layout.php
git commit -m "feat(admin): service pricing catalog page + nav link

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Show the captured price in admin request views

**Files:**
- Modify: `admin/concierge-desk.php` (the Request cell)
- Modify: `admin/_ws_requests.php` (the Request cell)

- [ ] **Step 1: Concierge desk — append the price**

In `admin/concierge-desk.php`, find the request cell `<td><?= e(addon_label($a)) ?></td>` and replace it with:

```php
          <td><?= e(addon_label($a)) ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?></td>
```

(`admin/concierge-desk.php` already `require_once`s `includes/booking.php`, so `format_price` is available.)

- [ ] **Step 2: Workspace requests — append the price**

In `admin/_ws_requests.php`, find the details cell that renders `addon_label($a)` (`<td><?= e(addon_label($a)) ?>...`) and add the price after the label, before any scheduled-for span:

```php
        <td><?= e(addon_label($a)) ?><?php if (isset($a['price_amount']) && $a['price_amount'] !== null && (float)$a['price_amount'] > 0): ?> <span class="badge badge--grey"><?= e(format_price((float)$a['price_amount'])) ?></span><?php endif; ?><?php if (!empty($a['scheduled_for'])): ?> <span class="text-muted" style="font-size:12px">· <?= e(date('j M, H:i', strtotime((string)$a['scheduled_for']))) ?></span><?php endif; ?></td>
```

- [ ] **Step 3: Verify (local)**

Confirm `php -l admin/concierge-desk.php` and `php -l admin/_ws_requests.php` are clean. With a seeded laundry/transfer request that has a non-null `price_amount`, the Concierge Desk row and the booking workspace Requests tab show the price badge; requests with no price show nothing extra.

- [ ] **Step 4: Commit**

```bash
git add admin/concierge-desk.php admin/_ws_requests.php
git commit -m "feat(admin): show captured service price on concierge requests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Full regression + verification

- [ ] **Step 1: Run the test suites**

Run: `php tests/services_logic.php` → `ALL PASS`.
Run: `php tests/portal_logic.php` → `ALL PASS`.
Run: `php tests/frontdesk_logic.php` → `ALL PASS`.

- [ ] **Step 2: Lint every touched PHP file**

Run: `for f in includes/services.php includes/booking.php api/booking-addon.php includes/app/_services.php admin/services.php admin/_layout.php admin/concierge-desk.php admin/_ws_requests.php; do php -l "$f"; done`
Expected: "No syntax errors detected" for each.

- [ ] **Step 3: End-to-end (local, in-app browser)**

With the dev server running:
- As owner on `/admin/services.php`, set a price on "Wash & fold" (e.g. 500) and Save.
- Open a seeded guest portal (`/booking.php?ref=…`), tap **Laundry**: the dropdown shows "Wash & fold — <currency> 500". Pick it and send.
- Confirm the request lands in Messages (existing behaviour) and that the created `booking_addons` row has `price_amount = 500` (check `/admin/concierge-desk.php` shows the price badge on that request).
- Set a laundry option **inactive** and confirm it no longer appears in the guest dropdown.
- Clean up any test requests afterward.

- [ ] **Step 4: Request a final code review**

Use superpowers:requesting-code-review to review the branch against this plan and the spec; fix any findings before finishing the branch.

---

## Rollout

Run `db/migrations/add_service_options.sql` via **/admin/migrate.php** after deploy (creates the table + `price_amount` column + seeds options at 0). Then set prices on `admin/services.php`. Guests immediately see priced options; new laundry/transfer requests capture the price snapshot.
