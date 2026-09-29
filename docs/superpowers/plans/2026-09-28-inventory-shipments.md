# Inventory Shipments Implementation Plan

> **Scope reduced (2026-09-28, owner):** built = shared stores (§2, shares as
> `inv_locations.share_venue_ids INT[]`, migration `add_inventory_stores.sql`) + the
> Excel reader/parser (§4.1) + a one-step **Import items** page (items only, no
> stock, no preview editing). Shipments, receiving, damage and claims (§3 tables,
> §4.2, §5–§7) were dropped as over-engineering.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import a supplier's shipment Excel (first use: Maya Ilai, 4 containers), receive it on a phone (good / damaged / note / photo), put the good stock into a store that can belong to one property and be shared with others ("TD Main Stock"), and keep the ordered-vs-received record for claims.

**Architecture:** A new migration adds multi-store columns to `inv_locations` (`is_main`, `share_venue_ids INT[]`), two tables (`inv_shipments`, `inv_shipment_lines`) and `inv_moves.shipment_line_id`. A pure importer (`includes/inventory-shipment-import.php`) reads every master-list sheet (the xlsx reader learns sheet names + bold), a DB layer (`includes/inventory-shipments.php`) creates shipments (no stock moves) and applies receiving rounds as running totals → `inv_move()` deltas. Scope rules gain one idea — a location's *venue set* = owning venue ∪ shares — used by visibility and move scope.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via PDO `db_query()`, vanilla JS/CSS, house admin design system. Tests are plain PHP scripts (`php tests/<name>.php`).

**Spec:** `docs/superpowers/specs/2026-09-28-inventory-shipments-design.md`

---

## Ground rules for every task

- Work in the worktree `~/.config/superpowers/worktrees/Tribal Sand/inventory-shipments` (branch `feat/inventory-shipments`). **Never** touch the main folder `~/Desktop/CLAUDE CODE/Tribal Sand` — another session uses it.
- `.env` is already copied into the worktree (local Postgres). Run tests from the worktree root.
- pdo_pgsql: **never reuse a named placeholder** in one statement, and **never bind a parameter the statement doesn't use** — both error.
- Every read of the new columns/tables is guarded by `inv_shipments_supported()` (catalog lookup — safe inside a transaction). Code must keep working on a DB that has `add_inventory.sql` but not yet `add_inventory_shipments.sql`.
- Quantities change **only** through `inv_move()` (and helpers that call it). Lock order: `inv_shipments` → `inv_shipment_lines` (id order) → balances by `(item_id, location_id)` via `inv_lock_balances()` → `inv_assets`.
- House UI only: `.inp`, `.eselect`, `.optchip`, `.dp-btn`, `.filefield`, `.btn-primary/.btn-outline/.btn-icon`, `admin_icon()` names that exist (`check, x, edit, trash, search, plus, minus, filter, calendar, arrow-left, arrow-right, download, inbox, image, eye, settings, clock, user, users, copy, link`). Confirmations use `data-confirm="…"` on the submit button (handled by `admin/_layout_end.php`).
- Existing suites must keep passing: `php tests/inventory_logic.php`, `php tests/inventory_views_logic.php`, `php tests/inventory_counts_logic.php`, `php tests/pos_logic.php`, `php tests/booking_import_logic.php` (that one has one known pre-existing failure about `zuri-buyout` — ignore only that).
- Commit after each task with a message ending in:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## File map

| File | Status | Responsibility |
|---|---|---|
| `db/migrations/add_inventory_shipments.sql` | create | multi-store columns, shipments tables, `inv_moves.shipment_line_id` |
| `includes/inventory-support.php` | modify | `inv_shipments_supported()` |
| `includes/inventory.php` | modify | venue sets, scope, `inv_store_location_id()` by `is_main`, stores create/update, `shipment_line_id` on moves |
| `includes/inventory-views.php` | modify | visibility by venue set, SQL visibility with shares, hidden venues get locations, restock source default |
| `includes/xlsx-reader.php` | modify | `xlsx_read_sheets()` — every sheet, by name, with a bold flag per cell |
| `includes/inventory-shipment-import.php` | create | PURE: read master lists, normalise, group by name, suggestions, preview merge |
| `includes/inventory-shipments.php` | create | DB: create shipment, receive rounds, status, reads, scope |
| `admin/inventory-locations.php` | modify | Add store; store owner + shares (owner) |
| `admin/inventory-location.php` | modify | Restock-to-par source picker |
| `admin/inventory-shipments.php` | create | list + Import Excel + preview + confirm |
| `admin/inventory-shipment.php` | create | one shipment: lines by section, claims, CSV, mark received / cancel |
| `admin/inventory-receive.php` | create | phone receiving screen |
| `admin/inventory-shipment-file.php` | create | serves a damage photo (private) |
| `admin/inventory-count.php` | modify | "Deliveries to receive" card for staff |
| `admin/_layout.php` | modify | Shipments link in the Inventory group |
| `Dockerfile` | modify | `max_input_vars=5000` |
| `tests/inventory_shipments_logic.php` | create | all tests for this feature |
| `tests/fixtures/shipment-maya-ilai.xlsx` | create | copy of the real list (supplier items only, no personal data) |
| `tests/inventory_logic.php`, `tests/inventory_views_logic.php` | modify | the "one store" assertions count Main stock by `is_main` |
| `CLAUDE.md` | modify | Inventory section bullets + file map rows |

---

### Task 1: Migration, support guard, test skeleton, fixture

**Files:**
- Create: `db/migrations/add_inventory_shipments.sql`
- Modify: `includes/inventory-support.php` (append a function)
- Create: `tests/inventory_shipments_logic.php`
- Create: `tests/fixtures/shipment-maya-ilai.xlsx`

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_inventory_shipments.sql`:

```sql
-- Inventory — Shipments (import a supplier list, receive it on a phone) and more
-- than one store (a store may belong to a property and be shared with others).
-- Run via /admin/migrate.php AFTER add_inventory.sql. Idempotent.
-- Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md
--
-- Load-bearing rules (see CLAUDE.md "Inventory & Assets"):
--   • Main stock is the store flagged is_main (exactly one). Other stores carry an
--     OWNING venue (venue_id — the accounting seam) and share_venue_ids: the
--     venues whose managers may also use it. A location's "venue set" = owner ∪ shares.
--   • Importing a shipment NEVER moves stock. Receiving posts running totals; the
--     server writes only the difference as moves (inv_moves.shipment_line_id links
--     them back). Damaged units stay on the line and never enter stock.

-- ── Stores ──────────────────────────────────────────────────────────────────
ALTER TABLE inv_locations ADD COLUMN IF NOT EXISTS is_main         BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE inv_locations ADD COLUMN IF NOT EXISTS share_venue_ids INT[]   NOT NULL DEFAULT '{}';
UPDATE inv_locations SET is_main = TRUE
 WHERE id = (SELECT MIN(id) FROM inv_locations WHERE kind = 'store')
   AND NOT EXISTS (SELECT 1 FROM inv_locations WHERE is_main);
DROP INDEX IF EXISTS uq_inv_locations_store;                       -- the old "one store only" rule
CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_locations_main ON inv_locations (is_main) WHERE is_main;
ALTER TABLE inv_locations DROP CONSTRAINT IF EXISTS inv_locations_store_fields_check;
ALTER TABLE inv_locations ADD CONSTRAINT inv_locations_store_fields_check CHECK (
    (NOT is_main OR kind = 'store') AND (cardinality(share_venue_ids) = 0 OR kind = 'store'));

-- ── Shipments ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inv_shipments (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(160) NOT NULL,
    supplier        VARCHAR(160),
    reference       VARCHAR(80),
    containers      TEXT,
    expected_on     DATE,
    to_location_id  INT NOT NULL REFERENCES inv_locations(id),
    status          VARCHAR(10) NOT NULL DEFAULT 'expected'
                    CHECK (status IN ('expected','receiving','received','cancelled')),
    source_filename VARCHAR(200),
    created_by      INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    received_at     TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_inv_shipments_status ON inv_shipments (status, created_at DESC);

CREATE TABLE IF NOT EXISTS inv_shipment_lines (
    id            SERIAL PRIMARY KEY,
    shipment_id   INT NOT NULL REFERENCES inv_shipments(id) ON DELETE CASCADE,
    sort_order    INT NOT NULL DEFAULT 0,
    section       VARCHAR(120),
    code          VARCHAR(40),
    hs_code       VARCHAR(20),
    description   TEXT NOT NULL,
    item_id       INT NOT NULL REFERENCES inv_items(id),
    qty_expected  INT NOT NULL CHECK (qty_expected > 0),
    qty_good      INT NOT NULL DEFAULT 0 CHECK (qty_good >= 0),
    qty_damaged   INT NOT NULL DEFAULT 0 CHECK (qty_damaged >= 0),
    note          TEXT,
    photo_key     TEXT,
    unit_cost     NUMERIC(12,2) CHECK (unit_cost IS NULL OR unit_cost >= 0),   -- filled when the invoice arrives (later)
    cost_currency CHAR(3),
    updated_by    INT REFERENCES admin_users(id) ON DELETE SET NULL,
    updated_at    TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_inv_shipment_lines_shipment ON inv_shipment_lines (shipment_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_inv_shipment_lines_item     ON inv_shipment_lines (item_id);

ALTER TABLE inv_moves ADD COLUMN IF NOT EXISTS shipment_line_id INT REFERENCES inv_shipment_lines(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_inv_moves_shipment_line ON inv_moves (shipment_line_id) WHERE shipment_line_id IS NOT NULL;
```

- [ ] **Step 2: Add the support guard**

Append to `includes/inventory-support.php` (after `inv_supported()`):

```php
/**
 * True once add_inventory_shipments.sql has run: shipments, and stores that can
 * belong to a property and be shared (inv_locations.is_main / share_venue_ids).
 * A catalog lookup — safe inside a transaction.
 */
function inv_shipments_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = inv_supported() && (bool) db_query("SELECT to_regclass('public.inv_shipment_lines') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}
```

- [ ] **Step 3: Copy the fixture**

```bash
mkdir -p tests/fixtures && cp "/Users/patrikgiuliana/Downloads/Inventory List Maya Ilai 4 Containers Shipment 1.xlsx" tests/fixtures/shipment-maya-ilai.xlsx
```

- [ ] **Step 4: Write the test skeleton (with a schema check)**

Create `tests/inventory_shipments_logic.php`:

```php
<?php
declare(strict_types=1);
// Inventory — shipments and shared stores. Run: php tests/inventory_shipments_logic.php
// Pure rules always run. The DB block runs in ONE rolled-back transaction and
// SKIPs when no DB is reachable or add_inventory_shipments.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory-views.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$fixture = __DIR__ . '/fixtures/shipment-maya-ilai.xlsx';
check('fixture: the real shipment list is present', is_file($fixture));

// ── Pure checks (each task inserts its section above this line) ──

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_shipments_supported()) {
    echo "\nSKIP  DB block (add_inventory_shipments.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx     = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins     = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count   = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    $refused = function (callable $fn): string { try { $fn(); } catch (InvRefusal $e) { return $e->getMessage(); } return ''; };

    check('schema: Main stock is flagged, exactly once', $count('SELECT COUNT(*) FROM inv_locations WHERE is_main') === 1);
    check('schema: shipments tables exist', $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('inv_shipments','inv_shipment_lines')") === 2);
    check('schema: moves link to a shipment line', $count("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inv_moves' AND column_name = 'shipment_line_id'") === 1);

    // ── DB checks (each task inserts its block above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 5: Run it — the DB block must SKIP (migration not applied yet)**

Run: `php tests/inventory_shipments_logic.php`
Expected: `PASS  fixture…`, then `SKIP  DB block (add_inventory_shipments.sql not applied)`, `ALL PASS`.

- [ ] **Step 6: Apply the migration locally (twice — it must be idempotent)**

```bash
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_inventory_shipments.sql")); echo "ok\n";'
php -r 'require "includes/db.php"; db()->exec(file_get_contents("db/migrations/add_inventory_shipments.sql")); echo "ok\n";'
```
Expected: `ok` twice.

- [ ] **Step 7: Run the tests again**

Run: `php tests/inventory_shipments_logic.php`
Expected: the three `schema:` checks PASS, `ALL PASS`.

- [ ] **Step 8: Commit**

```bash
git add db/migrations/add_inventory_shipments.sql includes/inventory-support.php tests/inventory_shipments_logic.php tests/fixtures/shipment-maya-ilai.xlsx
git commit -m "feat(inventory): shipments migration + guard, test skeleton with the real list

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Stores that belong to a property and are shared — core + scope

**Files:**
- Modify: `includes/inventory.php` (constants, pure rules, `inv_move_in_scope()`, `inv_store_location_id()`, new store functions)
- Modify: `includes/inventory-views.php` (`inv_location_visible()`, `inv_visible_sql()`, `inv_visible_venues()`, `inv_ensure_default_locations()`, `inv_item_units()`, `inv_item_history()`)
- Modify: `tests/inventory_logic.php:129`, `tests/inventory_views_logic.php:228-229`
- Test: `tests/inventory_shipments_logic.php`

- [ ] **Step 1: Write the failing pure tests**

In `tests/inventory_shipments_logic.php`, insert above `// ── Pure checks (each task inserts its section above this line) ──`:

```php
// ── Stores: venue sets and scope ────────────────────────────────────────────
$td    = ['kind' => 'store', 'venue_id' => 7, 'share_venue_ids' => '{6,8}'];      // TD Main Stock: Tribal Dunes, shared with Maya Ilai + Off-Duty
$mainS = ['kind' => 'store', 'venue_id' => null, 'share_venue_ids' => '{}'];
$mi    = ['kind' => 'property', 'venue_id' => 6];
$zuriP = ['kind' => 'property', 'venue_id' => 3];
check('pg int[]: text and PHP arrays both read', inv_pg_int_array('{6,8}') === [6, 8] && inv_pg_int_array([6, '8']) === [6, 8]
    && inv_pg_int_array(null) === [] && inv_pg_int_array('{}') === [] && inv_pg_int_array('') === []);
check('pg int[]: literal for a bind', inv_pg_int_array_literal([6, 8]) === '{6,8}' && inv_pg_int_array_literal([]) === '{}');
check('venue set: owner then shares', inv_location_venue_set($td) === [7, 6, 8]);
check('venue set: Main stock has none', inv_location_venue_set($mainS) === [] && inv_location_venue_set(['venue_id' => '3']) === [3]);
check('shared store: a Maya Ilai manager sees it', inv_location_visible($td, [6]));
check('shared store: a Zuri manager does not', !inv_location_visible($td, [3]));
check('shared store: Maya Ilai manager moves it to Maya Ilai', inv_move_in_scope($td, $mi, [6]));
check('shared store: …but not to Zuri', !inv_move_in_scope($td, $zuriP, [6]));
check('shared store: a Zuri manager cannot draw from it', !inv_move_in_scope($td, $zuriP, [3]));
check('shared store: its settings stay with the owning property', inv_location_editable($td, [7]) && !inv_location_editable($td, [6]));
check('Main stock: still shared with everyone', inv_location_visible($mainS, [3]) && inv_move_in_scope($mainS, $zuriP, [3]));
check('shares: cleaned, the owner dropped, sorted', inv_clean_share_ids(['8', '6', 'x', '6', '7', '-1'], 7) === [6, 8]);
check('restock source: the store serving the property wins over Main stock', inv_default_restock_source([
        ['id' => 1, 'kind' => 'store', 'is_main' => 't', 'venue_id' => null, 'share_venue_ids' => '{}'],
        ['id' => 9, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 7, 'share_venue_ids' => '{6,8}']], $mi) === 9);
check('restock source: otherwise Main stock', inv_default_restock_source([
        ['id' => 1, 'kind' => 'store', 'is_main' => 't', 'venue_id' => null, 'share_venue_ids' => '{}'],
        ['id' => 9, 'kind' => 'store', 'is_main' => 'f', 'venue_id' => 7, 'share_venue_ids' => '{6,8}']], $zuriP) === 1);
check('restock source: none offered', inv_default_restock_source([], $mi) === null);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_shipments_logic.php`
Expected: fatal `Call to undefined function inv_pg_int_array()`.

- [ ] **Step 3: Pure rules + scope in `includes/inventory.php`**

1. Change the constant (a store is no longer necessarily "Main stock"):

```php
const INV_LOCATION_KINDS = ['store' => 'Store', 'property' => 'Property', 'area' => 'Area', 'outlet' => 'Outlet', 'person' => 'Team member'];
```

2. Add after `inv_shortfall_message()`:

```php
/** A Postgres int[] as PHP ints: '{6,8}' (as PDO returns it) or an array → [6, 8]; null/'' → [] — PURE. */
function inv_pg_int_array(mixed $v): array {
    if ($v === null || $v === '') return [];
    if (is_string($v)) $v = explode(',', trim($v, '{}'));
    $out = [];
    foreach ((array)$v as $x) { if (is_numeric($x) && (int)$x > 0) $out[] = (int)$x; }
    return $out;
}

/** PHP ints → a Postgres int[] literal for CAST(:x AS int[]) — PURE. */
function inv_pg_int_array_literal(array $ids): string {
    return '{' . implode(',', array_map('intval', $ids)) . '}';
}

/**
 * The venues a location belongs to: its OWNING venue, then the venues it is
 * shared with (stores only) — PURE. Empty = shared by everyone (Main stock,
 * venue-less outlets) or a venue-less team member.
 */
function inv_location_venue_set(array $loc): array {
    $set = [];
    $v = $loc['venue_id'] ?? null;
    if ($v !== null && $v !== '') $set[] = (int)$v;
    foreach (inv_pg_int_array($loc['share_venue_ids'] ?? null) as $s) if (!in_array($s, $set, true)) $set[] = $s;
    return $set;
}

/** A store's share list from a form: positive unique ints, sorted, never the owning venue — PURE. */
function inv_clean_share_ids(array $posted, ?int $ownerVenueId): array {
    $out = [];
    foreach ($posted as $v) {
        $v = is_numeric($v) ? (int)$v : 0;
        if ($v > 0 && $v !== $ownerVenueId) $out[$v] = $v;
    }
    sort($out);
    return array_values($out);
}
```

3. Replace the whole `inv_move_in_scope()` (docblock included) with:

```php
/**
 * May an account with $venueIds (null = owner, all) make a move between these
 * two location rows (either may be null for in/out moves)? — PURE.
 * A location with a venue set (inv_location_venue_set(): owner ∪ shares) is the
 * manager's when that set meets their venues — so a store shared with Maya Ilai is
 * a Maya Ilai manager's for this purpose. Shared locations (empty set: Main stock,
 * venue-less outlets — never a person) are open to a manager only as the OTHER end
 * of a move touching one of their own places; every other end must be theirs.
 */
function inv_move_in_scope(?array $from, ?array $to, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $mine  = array_map('intval', $venueIds);
    $owned = false;
    foreach ([$from, $to] as $loc) {
        if ($loc === null) continue;
        $set = inv_location_venue_set($loc);
        if (!$set) {
            if (($loc['kind'] ?? '') === 'person') return false;   // a venue-less team member's items are owner business
            continue;                                              // Main stock / a shared outlet
        }
        if (!array_intersect($set, $mine)) return false;
        $owned = true;
    }
    return $owned;
}
```

4. Replace the whole `inv_store_location_id()` (docblock included) with:

```php
/**
 * The Main stock location (the store flagged is_main). Read first: it exists after
 * the first call, and an INSERT … ON CONFLICT on every page view would burn a
 * sequence value each time. Before add_inventory_shipments.sql there is only one
 * store, found by kind.
 */
function inv_store_location_id(): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    if (!inv_shipments_supported()) {
        $id = db_query("SELECT id FROM inv_locations WHERE kind = 'store' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id !== false) return (int)$id;
        db_query("INSERT INTO inv_locations (kind, name) VALUES ('store', 'Main stock') ON CONFLICT (kind) WHERE kind = 'store' DO NOTHING");
        return (int) db_query("SELECT id FROM inv_locations WHERE kind = 'store' ORDER BY id LIMIT 1")->fetchColumn();
    }
    $id = db_query('SELECT id FROM inv_locations WHERE is_main')->fetchColumn();
    if ($id !== false) return (int)$id;
    db_query("INSERT INTO inv_locations (kind, name, is_main) VALUES ('store', 'Main stock', TRUE) ON CONFLICT (is_main) WHERE is_main DO NOTHING");
    return (int) db_query('SELECT id FROM inv_locations WHERE is_main')->fetchColumn();
}

/** Refuse unless every venue id exists. */
function inv_assert_venues(array $ids): void {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return;
    $found = (int) db_query('SELECT COUNT(*) FROM venues WHERE id = ANY(CAST(:ids AS int[]))', [':ids' => inv_pg_int_array_literal($ids)])->fetchColumn();
    if ($found !== count($ids)) throw new InvRefusal('Pick properties that exist.');
}

/**
 * Add a store (owner only — the caller checks). $venueId = the property it belongs
 * to (NULL = shared by all, like Main stock); $shareVenueIds = the other properties
 * whose managers may use it. Returns the new location id.
 */
function inv_create_store(string $name, ?int $venueId, array $shareVenueIds): int {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) throw new InvRefusal('Give the store a name (up to 120 characters).');
    $shares = inv_clean_share_ids($shareVenueIds, $venueId);
    inv_assert_venues(array_merge($venueId !== null ? [$venueId] : [], $shares));
    if (db_query("SELECT 1 FROM inv_locations WHERE kind = 'store' AND is_active = TRUE AND lower(name) = lower(:n)", [':n' => $name])->fetchColumn()) {
        throw new InvRefusal("A store is already called {$name}.");
    }
    db_query("INSERT INTO inv_locations (kind, name, venue_id, share_venue_ids) VALUES ('store', :n, :v, CAST(:s AS int[]))",
        [':n' => $name, ':v' => $venueId, ':s' => inv_pg_int_array_literal($shares)]);
    return (int) db()->lastInsertId();
}

/** Change which property a store belongs to and who shares it (owner only — the caller checks). Main stock has neither. */
function inv_update_store_owner(int $id, ?int $venueId, array $shareVenueIds): void {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $loc = inv_fetch_location($id);
    if (!$loc || $loc['kind'] !== 'store') throw new InvRefusal('That store no longer exists.');
    if (inv_bool($loc['is_main'] ?? false)) throw new InvRefusal('Main stock is shared by every property — it has no owner.');
    $shares = inv_clean_share_ids($shareVenueIds, $venueId);
    inv_assert_venues(array_merge($venueId !== null ? [$venueId] : [], $shares));
    db_query('UPDATE inv_locations SET venue_id = :v, share_venue_ids = CAST(:s AS int[]) WHERE id = :id',
        [':v' => $venueId, ':s' => inv_pg_int_array_literal($shares), ':id' => $id]);
}
```

- [ ] **Step 4: Visibility in `includes/inventory-views.php`**

1. Replace the whole `inv_location_visible()` with:

```php
/** May an account with $venueIds (null = owner) SEE this location row? — PURE. Mirrors inv_move_in_scope(). */
function inv_location_visible(array $loc, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $set = inv_location_venue_set($loc);
    if (!$set) return ($loc['kind'] ?? '') !== 'person';   // shared Main stock / outlets; a venue-less person is owner-only
    return (bool) array_intersect($set, array_map('intval', $venueIds));
}
```

(`inv_location_editable()` stays as it is — settings follow the OWNING venue only.)

2. Add after `inv_location_label()`:

```php
/**
 * The store a location restocks from by default — PURE: a store that belongs to,
 * or is shared with, the location's property; else Main stock; else the first one.
 */
function inv_default_restock_source(array $stores, array $loc): ?int {
    $v    = isset($loc['venue_id']) && $loc['venue_id'] !== null && $loc['venue_id'] !== '' ? (int)$loc['venue_id'] : null;
    $main = null;
    foreach ($stores as $s) {
        if (inv_bool($s['is_main'] ?? false)) { $main ??= (int)$s['id']; continue; }
        if ($v !== null && in_array($v, inv_location_venue_set($s), true)) return (int)$s['id'];
    }
    return $main ?? (isset($stores[0]) ? (int)$stores[0]['id'] : null);
}

/** SQL for a location alias's share list, or an empty array before the shipments migration. */
function inv_share_col(string $a): string {
    return inv_shipments_supported() ? "{$a}.share_venue_ids" : "'{}'::int[]";
}
```

3. Replace the whole `inv_visible_sql()` (keep its docblock, add one sentence "A store is also visible to the venues it is shared with.") body:

```php
function inv_visible_sql(string $a, ?array $venueIds, array &$p, string $tag = 'vis'): string {
    if ($venueIds === null) return 'TRUE';
    $shares = inv_shipments_supported();   // share_venue_ids exists only after add_inventory_shipments.sql
    $ph = []; $sh = [];
    foreach (array_values($venueIds) as $i => $v) {
        $ph[] = ":{$tag}{$i}"; $p[":{$tag}{$i}"] = (int)$v;
        if ($shares) { $sh[] = ":{$tag}s{$i}"; $p[":{$tag}s{$i}"] = (int)$v; }   // a placeholder may not be reused in one statement
    }
    $own = $ph ? "{$a}.venue_id IN (" . implode(',', $ph) . ')' : 'FALSE';
    if (!$shares) return "({$own} OR ({$a}.venue_id IS NULL AND {$a}.kind <> 'person'))";
    $shared = $sh ? "{$a}.share_venue_ids && ARRAY[" . implode(',', $sh) . ']::int[]' : 'FALSE';
    return "({$own} OR {$shared} OR ({$a}.venue_id IS NULL AND cardinality({$a}.share_venue_ids) = 0 AND {$a}.kind <> 'person'))";
}
```

4. In `inv_visible_venues()` change `FROM venues WHERE is_published = TRUE ORDER BY` to `FROM venues ORDER BY`, and its docblock to `/** Venues (published or hidden) the account may filter by: [id => name]. */`.

5. In `inv_ensure_default_locations()` change `FROM venues v WHERE v.is_published = TRUE\n                          AND NOT EXISTS` to `FROM venues v WHERE NOT EXISTS`, and its docblock to `/** Main stock + one location per property (hidden ones too — Off-Duty) exist (idempotent; new venues get theirs here). */`.

6. In `inv_item_units()`: select the share list and pass it on. Replace
`$rows = db_query("SELECT a.*, l.name AS location_name, l.kind, l.venue_id, pl.name AS parent_name`
with
`$rows = db_query("SELECT a.*, l.name AS location_name, l.kind, l.venue_id, " . inv_share_col('l') . " AS share_venue_ids, pl.name AS parent_name`
and replace
`? inv_location_visible(['venue_id' => $u['venue_id'], 'kind' => $u['kind']], $venueIds)`
with
`? inv_location_visible(['venue_id' => $u['venue_id'], 'kind' => $u['kind'], 'share_venue_ids' => $u['share_venue_ids']], $venueIds)`.

7. In `inv_item_history()`: replace
`lf.venue_id AS from_venue, lf.kind AS from_kind, lt.venue_id AS to_venue, lt.kind AS to_kind, u.serial`
with
`lf.venue_id AS from_venue, lf.kind AS from_kind, " . inv_share_col('lf') . " AS from_shares, lt.venue_id AS to_venue, lt.kind AS to_kind, " . inv_share_col('lt') . " AS to_shares, u.serial`
and the two visibility calls with
`inv_location_visible(['venue_id' => $r['from_venue'], 'kind' => $r['from_kind'], 'share_venue_ids' => $r['from_shares']], $venueIds)` and
`inv_location_visible(['venue_id' => $r['to_venue'], 'kind' => $r['to_kind'], 'share_venue_ids' => $r['to_shares']], $venueIds)`.

- [ ] **Step 5: Keep the old "one store" assertions true with several stores**

In `tests/inventory_logic.php` line 129 replace `$count("SELECT COUNT(*) FROM inv_locations WHERE kind = 'store'") === 1` with
`$count('SELECT COUNT(*) FROM inv_locations WHERE ' . (inv_shipments_supported() ? 'is_main' : "kind = 'store'")) === 1`.
In `tests/inventory_views_logic.php` line 229 make the same replacement.

- [ ] **Step 6: Add the DB checks**

In `tests/inventory_shipments_logic.php`, insert above `// ── DB checks (each task inserts its block above this line) ──`:

```php
    // ── Stores ──
    $vTD  = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Dunes')", [':s' => "zz-td-{$sfx}"]);
    $vMI  = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Ilai')",  [':s' => "zz-mi-{$sfx}"]);
    $vZ   = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Zuri')",  [':s' => "zz-zu-{$sfx}"]);
    $vOff = $ins("INSERT INTO venues (slug, name, is_published) VALUES (:s, 'ZZ Off-Duty', FALSE)", [':s' => "zz-od-{$sfx}"]);
    $main = inv_store_location_id();
    $tdStore = inv_create_store("ZZ TD Main Stock {$sfx}", $vTD, [$vMI, $vOff, $vTD]);
    $tdRow   = inv_fetch_location($tdStore);
    $sharesExpected = [$vMI, $vOff]; sort($sharesExpected);
    check('store: created with its owner and shares (the owner is not repeated)',
        (int)$tdRow['venue_id'] === $vTD && inv_pg_int_array($tdRow['share_venue_ids']) === $sharesExpected);
    check('store: Main stock is unchanged', inv_store_location_id() === $main && !inv_bool($tdRow['is_main']));
    check('store: a duplicate name is refused', str_contains($refused(fn() => inv_create_store("zz td main stock {$sfx}", null, [])), 'already called'));
    check('store: an unknown property is refused', str_contains($refused(fn() => inv_create_store("ZZ Other {$sfx}", 99999999, [])), 'exist'));
    check('store: Main stock cannot get an owner', str_contains($refused(fn() => inv_update_store_owner($main, $vTD, [])), 'no owner'));
    $visMI = array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vMI]));
    $visZ  = array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vZ]));
    check('store: listed for a Maya Ilai manager, not for a Zuri manager', in_array($tdStore, $visMI, true) && !in_array($tdStore, $visZ, true));
    check('store: Main stock still listed for everyone', in_array($main, $visZ, true));
    inv_update_store_owner($tdStore, $vTD, [$vMI]);
    check('store: shares can be changed', !in_array($tdStore, array_map(fn($l) => (int)$l['id'], inv_locations_visible([$vOff])), true));
    inv_update_store_owner($tdStore, $vTD, [$vMI, $vOff]);
    inv_ensure_default_locations();
    check('hidden property: gets its inventory location', $count("SELECT COUNT(*) FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $vOff]) === 1);
    check('hidden property: offered in the filters', isset(inv_visible_venues(null)[$vOff]));
    $plate = inv_create_item(['name' => "ZZ Ship plate {$sfx}", 'item_type' => 'operational', 'replacement_value' => 100]);
    inv_move(['item_id' => $plate, 'qty' => 5, 'to' => $tdStore, 'reason' => 'receive']);
    $miLoc = inv_property_location_id($vMI);
    inv_transfer($plate, 2, $tdStore, $miLoc, null);
    $hist = inv_item_history($plate, [$vMI]);
    check('history: a shared store is named for a manager who shares it', $hist && !array_filter($hist, fn($m) => $m['from_name'] === 'Another location' || $m['to_name'] === 'Another location'));
```

- [ ] **Step 7: Run all inventory suites**

Run:
```bash
php tests/inventory_shipments_logic.php && php tests/inventory_logic.php && php tests/inventory_views_logic.php && php tests/inventory_counts_logic.php && php tests/pos_logic.php
```
Expected: every suite ends with `ALL PASS`.

- [ ] **Step 8: Commit**

```bash
git add includes/inventory.php includes/inventory-views.php tests/inventory_shipments_logic.php tests/inventory_logic.php tests/inventory_views_logic.php
git commit -m "feat(inventory): stores can belong to a property and be shared; hidden properties get a location

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The xlsx reader reads every sheet, by name, with bold

**Files:**
- Modify: `includes/xlsx-reader.php`
- Test: `tests/inventory_shipments_logic.php`, and `php tests/booking_import_logic.php` must still behave as before

- [ ] **Step 1: Write the failing test**

Add `require_once __DIR__ . '/../includes/xlsx-reader.php';` under the other `require_once` lines of `tests/inventory_shipments_logic.php`, then insert above `// ── Pure checks (each task inserts its section above this line) ──`:

```php
// ── xlsx reader ─────────────────────────────────────────────────────────────
$sheets = xlsx_read_sheets($fixture);
check('xlsx: every sheet, by name, in workbook order', array_keys($sheets) === ['Master Shipper Owned Container', 'PL NONE 6585458',
    'PL NONE6848636', 'Master List Vessel Container', 'PL MSBU781565', 'PL TEMU8316834']);
$soc = $sheets['Master Shipper Owned Container'];
check('xlsx: row numbers kept (row 7 is index 6)', ($soc[6][3]['v'] ?? '') === 'Villas' && ($soc[7][1]['v'] ?? '') === 'V001');
check('xlsx: bold section heading, plain line', ($soc[6][3]['b'] ?? false) === true && ($soc[7][3]['b'] ?? true) === false);
check('xlsx: first-sheet reader unchanged (plain strings)', is_string(xlsx_read_rows($fixture)[0][3] ?? null));
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_shipments_logic.php`
Expected: fatal `Call to undefined function xlsx_read_sheets()`.

- [ ] **Step 3: Implement**

In `includes/xlsx-reader.php`:

1. Update the file docblock's last paragraph to:

```
 * xlsx_read_rows(path):   the FIRST worksheet as rows of plain-string cells (booking importer).
 * xlsx_read_sheets(path): EVERY worksheet by name — [name => rows], each cell
 *                         ['v' => string, 'b' => bool bold]; empty rows kept, so
 *                         row index = spreadsheet row − 1 (shipment importer).
```

2. Add after `xlsx_shared_strings()`:

```php
/** Parse xl/styles.xml → [cellXfs index => bold?]. */
function xlsx_bold_styles(string $xml): array {
    $out = [];
    $doc = new DOMDocument();
    if (!@$doc->loadXML($xml)) return $out;
    $fonts = [];
    $fontsEl = $doc->getElementsByTagName('fonts')->item(0);
    if ($fontsEl) foreach ($fontsEl->childNodes as $f) {
        if (!($f instanceof DOMElement) || $f->localName !== 'font') continue;
        $b = $f->getElementsByTagName('b')->item(0);
        $fonts[] = $b !== null && !in_array($b->getAttribute('val'), ['0', 'false'], true);
    }
    $xfs = $doc->getElementsByTagName('cellXfs')->item(0);
    if ($xfs) foreach ($xfs->childNodes as $xf) {
        if (!($xf instanceof DOMElement) || $xf->localName !== 'xf') continue;
        $out[] = $fonts[(int)$xf->getAttribute('fontId')] ?? false;
    }
    return $out;
}

/** [sheet name => ZIP part path] in workbook order, from xl/workbook.xml and its rels. */
function xlsx_sheet_parts(string $zip): array {
    $wb   = xlsx_zip_read($zip, 'xl/workbook.xml');
    $rels = xlsx_zip_read($zip, 'xl/_rels/workbook.xml.rels');
    if ($wb === null || $rels === null) return [];
    $targets = [];
    $rd = new DOMDocument();
    if (@$rd->loadXML($rels)) foreach ($rd->getElementsByTagName('Relationship') as $r) {
        $t = $r->getAttribute('Target');
        $targets[$r->getAttribute('Id')] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
    }
    $out = [];
    $wd = new DOMDocument();
    if (@$wd->loadXML($wb)) foreach ($wd->getElementsByTagName('sheet') as $s) {
        $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if (isset($targets[$rid])) $out[$s->getAttribute('name')] = $targets[$rid];
    }
    return $out;
}

/** Read and sanity-check an .xlsx file's bytes. */
function xlsx_file_bytes(string $path): string {
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') throw new RuntimeException('Could not read the uploaded file.');
    if (substr($bytes, 0, 2) !== 'PK')     throw new RuntimeException('That does not look like an .xlsx file.');
    return $bytes;
}

/**
 * One worksheet's XML → rows of ['v' => string, 'b' => bool]. $fillGaps keeps
 * rows the XML omits (so index = spreadsheet row − 1).
 */
function xlsx_sheet_cells(string $sheetXml, array $shared, array $bold, bool $fillGaps): array {
    $doc = new DOMDocument();
    if (!@$doc->loadXML($sheetXml)) throw new RuntimeException('The worksheet XML could not be parsed.');
    $rows = [];
    foreach ($doc->getElementsByTagName('row') as $rowEl) {
        if ($fillGaps && ($rn = (int)$rowEl->getAttribute('r')) > 0) {
            while (count($rows) < $rn - 1) $rows[] = [];
        }
        $cells = [];
        $max   = -1;
        foreach ($rowEl->getElementsByTagName('c') as $c) {
            $ref  = $c->getAttribute('r');
            $idx  = $ref !== '' ? xlsx_col_index($ref) : count($cells);
            $type = $c->getAttribute('t');
            $val  = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
            } else {
                $vEl = $c->getElementsByTagName('v')->item(0);
                $raw = $vEl ? $vEl->textContent : '';
                $val = ($type === 's' && $raw !== '') ? ($shared[(int)$raw] ?? '') : $raw;
            }
            $s = $c->getAttribute('s');
            $cells[$idx] = ['v' => $val, 'b' => $s !== '' && ($bold[(int)$s] ?? false)];
            if ($idx > $max) $max = $idx;
        }
        $dense = [];
        for ($i = 0; $i <= $max; $i++) $dense[$i] = $cells[$i] ?? ['v' => '', 'b' => false];
        $rows[] = $dense;
    }
    return $rows;
}

/** Every worksheet of an .xlsx file: [sheet name => rows of ['v','b'] cells]. Throws RuntimeException. */
function xlsx_read_sheets(string $path): array {
    $bytes  = xlsx_file_bytes($path);
    $ss     = xlsx_zip_read($bytes, 'xl/sharedStrings.xml');
    $shared = $ss !== null ? xlsx_shared_strings($ss) : [];
    $st     = xlsx_zip_read($bytes, 'xl/styles.xml');
    $bold   = $st !== null ? xlsx_bold_styles($st) : [];
    $out = [];
    foreach (xlsx_sheet_parts($bytes) as $name => $part) {
        $xml = xlsx_zip_read($bytes, $part);
        if ($xml !== null) $out[(string)$name] = xlsx_sheet_cells($xml, $shared, $bold, true);
    }
    if (!$out) throw new RuntimeException('No worksheet found in the file.');
    return $out;
}
```

3. Replace the body of `xlsx_read_rows()` so it reuses the new helpers but returns exactly what it returned before (plain strings, no gap-filling):

```php
function xlsx_read_rows(string $path): array {
    $bytes = xlsx_file_bytes($path);

    $shared = [];
    $ss = xlsx_zip_read($bytes, 'xl/sharedStrings.xml');
    if ($ss !== null) $shared = xlsx_shared_strings($ss);

    // First worksheet: prefer sheet1.xml, else the lowest-numbered sheet part.
    $sheetXml = xlsx_zip_read($bytes, 'xl/worksheets/sheet1.xml');
    if ($sheetXml === null) {
        $cands = array_values(array_filter(xlsx_zip_list($bytes),
            fn($n) => preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)));
        sort($cands, SORT_NATURAL);
        if ($cands) $sheetXml = xlsx_zip_read($bytes, $cands[0]);
    }
    if ($sheetXml === null) throw new RuntimeException('No worksheet found in the file.');

    return array_map(fn(array $r): array => array_map(fn(array $c): string => $c['v'], $r),
                     xlsx_sheet_cells($sheetXml, $shared, [], false));
}
```

- [ ] **Step 4: Run the tests**

Run: `php tests/inventory_shipments_logic.php && php tests/booking_import_logic.php`
Expected: the four `xlsx:` checks PASS; the booking importer suite shows the same result as before (only the known `zuri-buyout` failure, if any).

- [ ] **Step 5: Commit**

```bash
git add includes/xlsx-reader.php tests/inventory_shipments_logic.php
git commit -m "feat(xlsx): read every sheet by name with a bold flag per cell

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The pure importer — master lists, sections, merge by name

**Files:**
- Create: `includes/inventory-shipment-import.php`
- Test: `tests/inventory_shipments_logic.php`

- [ ] **Step 1: Write the failing tests**

Add `require_once __DIR__ . '/../includes/inventory-shipment-import.php';` to the test's requires, then insert above `// ── Pure checks (each task inserts its section above this line) ──`:

```php
// ── Importer (pure) ─────────────────────────────────────────────────────────
check('norm: HS codes with commas / float noise', inv_ship_hs('9404,90,90') === '9404.90.90' && inv_ship_hs('9403.8900000000003') === '9403.89' && inv_ship_hs('4602.12') === '4602.12');
check('norm: item codes trimmed', inv_ship_code('OV003/ ') === 'OV003' && inv_ship_code(' OS001 ') === 'OS001');
check('norm: quantities are whole positive numbers', inv_ship_qty('8') === 8 && inv_ship_qty('8.0') === 8 && inv_ship_qty('2.5') === null && inv_ship_qty('') === null && inv_ship_qty('x') === null);
check('norm: merge key ignores case, spacing, trailing dots', inv_ship_key('  Woven  Basket Med. ') === inv_ship_key('woven basket med'));
check('suggest: fridge → appliance, serial', inv_ship_suggest_category('Mini Bar Fridge') === 'Appliances' && inv_ship_suggest_kind('Appliances') === 'serial');
check('suggest: linen, throws, rugs, curtains', inv_ship_suggest_category('Fitted Sheet King - White') === 'Linen'
    && inv_ship_suggest_category('Bed Throw 1.83m x 0.3m') === 'Cushions & throws' && inv_ship_suggest_category('Runner Rug 0.8m x 3.0m') === 'Rugs'
    && inv_ship_suggest_category('Double Curtain Rails (166 pcs with brackets and screws)') === 'Curtains & blinds');
check('suggest: furniture, lighting, décor', inv_ship_suggest_category('Outdoor Dining Set (9 pce)') === 'Furniture'
    && inv_ship_suggest_category('Wood Acorn Lights 200mm') === 'Lighting' && inv_ship_suggest_category('Crab Statue') === 'Décor'
    && inv_ship_suggest_category('Candle Holders') === 'Décor' && inv_ship_suggest_category('Napkin Holder (Set of 6)') === 'Kitchen & dining');
check('suggest: consumables are spare stock', inv_ship_suggest_category('Plugs') === 'Consumables' && inv_ship_suggest_kind('Consumables') === 'spare'
    && inv_ship_suggest_kind('Décor') === 'operational');
check('suggest: sets are counted as sets', inv_ship_suggest_unit('Outdoor Dining Set (9 pce)') === 'sets' && inv_ship_suggest_unit('Lounge Set (3 pce)') === 'sets'
    && inv_ship_suggest_unit('Couch 2.6m x 1m') === 'pcs');

$wb = inv_ship_parse_workbook($sheets);
$by = fn(string $code): array => array_values(array_filter($wb['lines'], fn($l) => $l['code'] === $code));
$idx = fn(string $code): int => (int) array_key_first(array_filter($wb['lines'], fn($l) => $l['code'] === $code));
check('parse: both master lists, packing lists skipped', $wb['sheets'] === ['Master Shipper Owned Container', 'Master List Vessel Container']);
check('parse: 199 lines, 4,333 pieces', count($wb['lines']) === 199 && array_sum(array_column($wb['lines'], 'qty')) === 4333);
check('parse: the four containers', $wb['containers'] === ['NONE 6585458 45 G1', 'NONE 6848636 45 G1', 'MSBU781565 45G1', 'TEMU831683 45G1']);
check('parse: sections from the bold headings', $by('V001')[0]['section'] === 'Villas' && $by('S001')[0]['section'] === 'Studio Rooms'
    && $by('OD005')[0]['section'] === 'Off-Duty' && $by('B001')[0]['section'] === 'General');
check('parse: 11 sections', count(array_unique(array_column($wb['lines'], 'section'))) === 11);
check('parse: linen spec rows joined to their line', $by('B001')[0]['description'] === 'Mattress Protector Fitted Quilted · Microfibre King 183cm x 190cm x 30cm · T200 100% Cotton Percale');
check('parse: HS code normalised', $by('V006')[1]['hs_code'] === '9404.90.90' && $by('V004')[0]['hs_code'] === '9403.89');
check('parse: nothing skipped', $wb['skipped'] === []);
check('parse: spreadsheet row numbers kept', $by('V001')[0]['row'] === 8 && $by('V001')[0]['sheet'] === 'Master Shipper Owned Container');

$g = inv_ship_group($wb['lines']);
check('group: 154 items', count($g) === 154);
$canvas = $g[inv_ship_key('Wall Art - Canvas Print')];
check('group: same name merges across codes', $canvas['qty'] === 96 && count($canvas['lines']) === 5);
check('group: different linen sizes stay apart', count(array_filter($g, fn($x) => str_starts_with((string)$x['key'], 'mattress protector'))) === 4);
check('group: first-seen order', array_key_first($g) === inv_ship_key('Couch 2.6m x 1m'));
$split = inv_ship_group($wb['lines'], [$idx('S001') => 'Wall Art - Canvas Print (studio)']);
check('group: a rename splits a line off', $split[inv_ship_key('Wall Art - Canvas Print')]['qty'] === 88
    && $split[inv_ship_key('Wall Art - Canvas Print (studio)')]['qty'] === 8);
$merged = inv_ship_group($wb['lines'], [$idx('V023') => 'Woven Basket Medium', $idx('S004') => 'woven basket medium']);
check('group: a rename can merge into another item', $merged[inv_ship_key('Woven Basket Medium')]['qty'] === 40);

// The preview form: rename a whole group, split one line, choices remembered per line.
$canvasGid = inv_ship_gid(inv_ship_key('Wall Art - Canvas Print'));
[$names, $choices] = inv_ship_apply_preview($g, ['g' => [$canvasGid => ['name' => 'Canvas print', 'category' => 'Art', 'kind' => 'operational', 'unit' => 'pcs']],
                                                 'split' => [$idx('G009') => 'Canvas print (spare)']], [], []);
$g2 = inv_ship_groups_with_choices($wb['lines'], $names, $choices);
check('preview: renaming a group renames all its lines', $g2[inv_ship_key('Canvas print')]['qty'] === 70 && !isset($g2[inv_ship_key('Wall Art - Canvas Print')]));
check('preview: a split wins for its line', $g2[inv_ship_key('Canvas print (spare)')]['qty'] === 26);
check('preview: choices follow the lines', $g2[inv_ship_key('Canvas print')]['category'] === 'Art' && $g2[inv_ship_key('Canvas print (spare)')]['category'] === 'Art');
check('preview: an unknown kind falls back to the suggestion', inv_ship_apply_preview($g, ['g' => [$canvasGid => ['kind' => 'gadget']]], [], [])[1][$idx('V008')]['kind'] === 'operational');
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_shipments_logic.php`
Expected: fatal `Failed opening required …inventory-shipment-import.php`.

- [ ] **Step 3: Implement `includes/inventory-shipment-import.php`**

```php
<?php
declare(strict_types=1);
/**
 * Inventory — reading a supplier's shipment list (Excel) — PURE, no DB.
 * Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md §4.1
 * Test: php tests/inventory_shipments_logic.php
 *
 * Input is xlsx_read_sheets(): [sheet name => rows of ['v' => string, 'b' => bold]].
 *   • A MASTER-LIST sheet has "Item No", "Qty" and "Description" headers and no
 *     Length / Weight / Cubes column (those are container packing lists — skipped).
 *   • Below its header: code + qty + description = a LINE. A description-only row is
 *     a SECTION (destination hint, e.g. "Off-Duty") when it is bold, else a
 *     CONTINUATION of the line above (the linen spec rows). "Studio Rooms" follows
 *     a line directly, so position alone can't tell them apart — bold can. With no
 *     bold in the sheet: after a blank row = section, otherwise continuation.
 *   • Lines become ITEMS by their normalised name ("merge by name"). The preview can
 *     rename a group (every line in it) or split one line off under a new name.
 */

const INV_SHIP_MAX_LINES = 2000;
const INV_SHIP_MAX_QTY   = 100000;
const INV_SHIP_KINDS     = ['operational' => 'Item', 'spare' => 'Spare / consumable', 'serial' => 'Serial-tracked'];
// Category suggestions: first match wins. Keywords match whole words; a trailing * is a prefix.
const INV_SHIP_CATEGORIES = [
    'Appliances'        => ['fridge*', 'kettle*', 'toaster*', 'microwave*', 'oven*', 'hob', 'washing', 'dryer*'],
    'Kitchen & dining'  => ['teaspoon*', 'napkin holder*', 'cutlery', 'crockery'],
    'Linen'             => ['sheet*', 'duvet*', 'duver', 'pillow*', 'mattress', 'servietts', 'napkin*', 'table cloth*', 'placemat*'],
    'Cushions & throws' => ['cushion*', 'throw*'],
    'Rugs'              => ['rug*', 'runner*'],
    'Curtains & blinds' => ['curtain*', 'blind*', 'romashade'],
    'Lighting'          => ['light*', 'lantern*', 'lamp*'],
    'Consumables'       => ['plug*', 'anchor*', 'scented candle*'],
    'Furniture'         => ['couch*', 'sofa*', 'table*', 'chair*', 'stool*', 'ottoman*', 'lounger*', 'bed*', 'daybed*', 'cabinet*',
                            'server', 'shelving', 'set', 'wash station', 'console', 'workstation'],
];

function inv_ship_text(string $s): string { return trim((string)preg_replace('/\s+/u', ' ', $s)); }

/** The merge key of a name: lower-case, single spaces, no trailing punctuation — PURE. */
function inv_ship_key(string $name): string { return rtrim(mb_strtolower(inv_ship_text($name)), ' .,;:'); }

/** A short, form-safe id for a group key — PURE. */
function inv_ship_gid(string $key): string { return substr(md5($key), 0, 12); }

function inv_ship_code(string $s): string { return mb_substr(trim(rtrim(inv_ship_text($s), '/')), 0, 40); }

/** "9404,90,90" → "9404.90.90"; a float cell's noise ("9403.8900000000003") → "9403.89" — PURE. */
function inv_ship_hs(string $s): string {
    $s = str_replace([',', ' '], ['.', ''], trim($s));
    if (preg_match('/^\d+\.\d{5,}$/', $s)) $s = rtrim(rtrim(number_format(round((float)$s, 4), 4, '.', ''), '0'), '.');
    return mb_substr($s, 0, 20);
}

/** A whole, positive quantity, or null — PURE. */
function inv_ship_qty(string $s): ?int {
    $s = trim($s);
    if ($s === '' || !is_numeric($s)) return null;
    $f = (float)$s;
    if ($f < 1 || $f > INV_SHIP_MAX_QTY || abs($f - round($f)) > 1e-9) return null;
    return (int)round($f);
}

function inv_ship_kw_regex(string $kw): string {
    $prefix = str_ends_with($kw, '*');
    $word   = preg_quote(rtrim($kw, '*'), '/');
    return '/(?<![\p{L}\d])' . $word . ($prefix ? '[\p{L}\d]*' : '') . '(?![\p{L}\d])/u';
}

function inv_ship_suggest_category(string $name): string {
    $n = mb_strtolower($name);
    foreach (INV_SHIP_CATEGORIES as $cat => $kws) {
        foreach ($kws as $kw) if (preg_match(inv_ship_kw_regex($kw), $n)) return $cat;
    }
    return 'Décor';
}

function inv_ship_suggest_kind(string $category): string {
    return match ($category) { 'Appliances' => 'serial', 'Consumables' => 'spare', default => 'operational' };
}

function inv_ship_suggest_unit(string $name): string {
    return preg_match('/\bsets?\b|\(\s*\d+\s*(pce|pcs|pc)\s*\)/i', $name) ? 'sets' : 'pcs';
}

/** Column map of a master-list header row, 'packing' for a packing-list header, or null — PURE. */
function inv_ship_header(array $cells): array|string|null {
    $map = [];
    foreach ($cells as $i => $c) {
        $t = mb_strtolower(inv_ship_text((string)($c['v'] ?? '')));
        if (in_array($t, ['length', 'weight', 'cubes'], true)) return 'packing';
        if (in_array($t, ['item no', 'item no.', 'code'], true)) $map['code'] ??= $i;
        elseif (in_array($t, ['qty', 'quantity'], true))       $map['qty']  ??= $i;
        elseif ($t === 'description')                           $map['desc'] ??= $i;
        elseif ($t === 'hs code')                               $map['hs']   ??= $i;
    }
    return isset($map['code'], $map['qty'], $map['desc']) ? $map : null;
}

/** One sheet → ['containers','lines','skipped'], or null when it is not a master list — PURE. */
function inv_ship_parse_sheet(string $sheetName, array $rows): ?array {
    $map = null; $headerAt = null; $containers = [];
    foreach ($rows as $n => $cells) {
        $first = mb_strtolower(inv_ship_text((string)($cells[0]['v'] ?? '')));
        if ($first === 'containers') {
            $rest = [];
            foreach (array_slice($cells, 1) as $c) if (($t = inv_ship_text((string)($c['v'] ?? ''))) !== '') $rest[] = $t;
            foreach (preg_split('#\s*/\s*#', implode(' / ', $rest)) ?: [] as $c) if (trim($c) !== '') $containers[] = trim($c);
            continue;
        }
        $h = inv_ship_header($cells);
        if ($h === 'packing') return null;
        if (is_array($h)) { $map = $h; $headerAt = $n; break; }
    }
    if ($map === null) return null;

    $cell     = fn(array $cells, ?int $i): string => $i === null ? '' : inv_ship_text((string)($cells[$i]['v'] ?? ''));
    $descOnly = fn(array $cells): bool => $cell($cells, $map['code']) === '' && $cell($cells, $map['qty']) === '' && $cell($cells, $map['desc']) !== '';
    // Rows are SPARSE (xlsx_read_sheets() keys them by spreadsheet row − 1; a missing key is an empty row).
    $body     = array_filter($rows, fn($k) => $k > $headerAt, ARRAY_FILTER_USE_KEY);
    $boldMode = false;
    foreach ($body as $cells) if ($descOnly($cells) && !empty($cells[$map['desc']]['b'])) { $boldMode = true; break; }

    $lines = []; $skipped = []; $section = ''; $prev = null; $lastN = $headerAt;
    foreach ($body as $n => $cells) {
        if ($n !== $lastN + 1) $prev = null;   // a gap in row numbers = blank row(s)
        $lastN = $n;
        $code   = inv_ship_code($cell($cells, $map['code']));
        $qtyRaw = $cell($cells, $map['qty']);
        $desc   = $cell($cells, $map['desc']);
        if ($code === '' && $qtyRaw === '' && $desc === '') { $prev = null; continue; }
        if ($descOnly($cells)) {
            $isSection = $boldMode ? !empty($cells[$map['desc']]['b']) : $prev === null;
            if ($isSection || $prev === null) { $section = mb_substr($desc, 0, 120); $prev = null; }
            else $lines[$prev]['description'] .= ' · ' . $desc;
            continue;
        }
        $qty = inv_ship_qty($qtyRaw);
        if ($code !== '' && $desc !== '' && $qty !== null) {
            $lines[] = ['sheet' => $sheetName, 'row' => $n + 1, 'section' => $section, 'code' => $code,
                        'hs_code' => isset($map['hs']) ? inv_ship_hs($cell($cells, $map['hs'])) : '',
                        'description' => $desc, 'qty' => $qty];
            $prev = array_key_last($lines);
            continue;
        }
        $skipped[] = ['sheet' => $sheetName, 'row' => $n + 1, 'text' => trim("{$code} {$qtyRaw} {$desc}")];
        $prev = null;
    }
    return ['containers' => $containers, 'lines' => $lines, 'skipped' => $skipped];
}

/** Every master list in a workbook, lines in sheet order — PURE. */
function inv_ship_parse_workbook(array $sheets): array {
    $out = ['containers' => [], 'lines' => [], 'skipped' => [], 'sheets' => []];
    foreach ($sheets as $name => $rows) {
        $s = inv_ship_parse_sheet((string)$name, $rows);
        if ($s === null || !$s['lines']) continue;
        $out['sheets'][] = (string)$name;
        array_push($out['containers'], ...$s['containers']);
        array_push($out['lines'], ...$s['lines']);
        array_push($out['skipped'], ...$s['skipped']);
    }
    $out['containers'] = array_values(array_unique($out['containers']));
    return $out;
}

/**
 * Group lines into proposed items — PURE. $names: [line index => item name] (from
 * the preview); a line keeps its description as its name otherwise. Lines whose
 * names share a key are ONE item. Returns [key => ['key','name','qty','lines' =>
 * [line index…],'category','kind','unit']] in first-seen order.
 */
function inv_ship_group(array $lines, array $names = []): array {
    $g = [];
    foreach ($lines as $i => $l) {
        $name = inv_ship_text((string)($names[$i] ?? ''));
        if ($name === '') $name = inv_ship_text((string)$l['description']);
        $name = mb_substr($name, 0, 160);
        $key  = inv_ship_key($name);
        if (!isset($g[$key])) {
            $cat = inv_ship_suggest_category($name);
            $g[$key] = ['key' => $key, 'name' => $name, 'qty' => 0, 'lines' => [], 'category' => $cat,
                        'kind' => inv_ship_suggest_kind($cat), 'unit' => inv_ship_suggest_unit($name)];
        }
        $g[$key]['qty'] += (int)$l['qty'];
        $g[$key]['lines'][] = $i;
    }
    return $g;
}

/**
 * Fold the preview form into the per-line state — PURE. $groups: the grouping the
 * form was drawn from; $post: ['g' => [gid => name/category/kind/unit], 'split' =>
 * [line index => name]]. A renamed group renames every line in it; a non-empty
 * "split off as" wins for its line; a group's choices are remembered on each of
 * its lines. Returns [$names, $choices].
 */
function inv_ship_apply_preview(array $groups, array $post, array $names, array $choices): array {
    foreach ($groups as $key => $g) {
        $gp      = (array)($post['g'][inv_ship_gid((string)$key)] ?? []);
        $newName = inv_ship_text((string)($gp['name'] ?? ''));
        $unit    = mb_substr(inv_ship_text((string)($gp['unit'] ?? $g['unit'])), 0, 20);
        $ch = [
            'category' => mb_substr(inv_ship_text((string)($gp['category'] ?? $g['category'])), 0, 60),
            'kind'     => isset(INV_SHIP_KINDS[$gp['kind'] ?? '']) ? (string)$gp['kind'] : (string)$g['kind'],
            'unit'     => $unit !== '' ? $unit : 'pcs',
        ];
        foreach ($g['lines'] as $i) {
            if ($newName !== '' && $newName !== $g['name']) $names[$i] = mb_substr($newName, 0, 160);
            $choices[$i] = $ch;
        }
    }
    foreach ((array)($post['split'] ?? []) as $i => $n) {
        $n = inv_ship_text((string)$n);
        if ($n !== '' && is_numeric($i)) $names[(int)$i] = mb_substr($n, 0, 160);
    }
    return [$names, $choices];
}

/** inv_ship_group() with the remembered choices applied (a group's first line decides) — PURE. */
function inv_ship_groups_with_choices(array $lines, array $names, array $choices): array {
    $g = inv_ship_group($lines, $names);
    foreach ($g as &$x) {
        $c = $choices[$x['lines'][0]] ?? null;
        if ($c) { $x['category'] = $c['category']; $x['kind'] = $c['kind']; $x['unit'] = $c['unit']; }
    }
    unset($x);
    return $g;
}
```

- [ ] **Step 4: Run the tests**

Run: `php tests/inventory_shipments_logic.php`
Expected: every `norm:`, `suggest:`, `parse:`, `group:`, `preview:` check PASS, `ALL PASS`. If a count differs (199 / 4,333 / 154 / 11), print the offending data with a one-off `php -r` and fix the PARSER, not the expected number — these numbers were computed independently from the file.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory-shipment-import.php tests/inventory_shipments_logic.php
git commit -m "feat(inventory): pure shipment importer — master lists, sections, merge by name

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Shipments in the database — create, receive rounds, status

**Files:**
- Modify: `includes/inventory.php` (`inv_normalize_move()`, the INSERT in `inv_move_tx()`, `inv_asset_create()`)
- Create: `includes/inventory-shipments.php`
- Test: `tests/inventory_shipments_logic.php`

- [ ] **Step 1: Write the failing tests**

Add `require_once __DIR__ . '/../includes/inventory-shipments.php';` to the test's requires. Insert above `// ── Pure checks (each task inserts its section above this line) ──`:

```php
// ── Receiving plan (pure) ───────────────────────────────────────────────────
$line = ['code' => 'V001', 'description' => 'Couch', 'qty_expected' => 8, 'qty_good' => 3, 'qty_damaged' => 0, 'note' => null];
$p = inv_ship_receive_plan($line, ['good' => '5', 'damaged' => '1', 'note' => ' torn cover ']);
check('plan: new totals give the difference', is_array($p) && $p['good'] === 5 && $p['damaged'] === 1 && $p['delta'] === 2 && $p['note'] === 'torn cover' && $p['changed']);
check('plan: blank keeps what is saved', ($q = inv_ship_receive_plan($line, ['good' => '', 'damaged' => ''])) && $q['good'] === 3 && $q['delta'] === 0 && !$q['changed']);
check('plan: a lower total is a correction', inv_ship_receive_plan($line, ['good' => '1'])['delta'] === -2);
check('plan: not a whole number is refused', is_string(inv_ship_receive_plan($line, ['good' => '2.5'])) && is_string(inv_ship_receive_plan($line, ['damaged' => '-1'])));
check('plan: more than ordered needs the tick', str_contains((string)inv_ship_receive_plan($line, ['good' => '8', 'damaged' => '1']), 'More than ordered')
    && is_array(inv_ship_receive_plan($line, ['good' => '8', 'damaged' => '1', 'over' => '1'])));
check('short: expected − good − damaged, never below 0', inv_ship_short(['qty_expected' => 8, 'qty_good' => 5, 'qty_damaged' => 1]) === 2
    && inv_ship_short(['qty_expected' => 8, 'qty_good' => 9, 'qty_damaged' => 0]) === 0);
check('store access: owner, and managers the store serves', inv_ship_store_allowed($td, null) && inv_ship_store_allowed($td, [6]) && !inv_ship_store_allowed($td, [3])
    && !inv_ship_store_allowed($mainS, [3]) && !inv_ship_store_allowed($mi, [6]));
```

And insert above `// ── DB checks (each task inserts its block above this line) ──`:

```php
    // ── Shipments ──
    $owner = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'owner', 'ZZ Ship Owner', TRUE)", [':e' => "zz-ship-o-{$sfx}@example.com"]);
    $slines = [
        ['section' => 'Villas',   'code' => 'V1',  'hs_code' => '9401.80.90', 'description' => "ZZ Couch {$sfx}",   'qty' => 8],
        ['section' => 'Villas',   'code' => 'V2',  'hs_code' => '',           'description' => "ZZ Cushion {$sfx}", 'qty' => 16],
        ['section' => 'Off-Duty', 'code' => 'OD1', 'hs_code' => '',           'description' => "zz cushion {$sfx}", 'qty' => 4],
        ['section' => 'General',  'code' => 'G1',  'hs_code' => '',           'description' => "ZZ Fridge {$sfx}",  'qty' => 2],
    ];
    $sgroups = inv_ship_group($slines);
    $sgroups[inv_ship_key("ZZ Fridge {$sfx}")]['kind'] = 'serial';
    $existing = inv_create_item(['name' => "ZZ Couch {$sfx}", 'item_type' => 'operational']);
    $head = ['name' => "ZZ Shipment {$sfx}", 'supplier' => 'ZZ Supplier', 'containers' => 'C1 / C2', 'expected_on' => '2026-10-01',
             'to_location_id' => $tdStore, 'source_filename' => "zz-{$sfx}.xlsx"];
    $sid = inv_shipment_create($head, $slines, $sgroups, $owner);
    $sl  = []; foreach (inv_shipment_lines($sid) as $r) $sl[$r['code']] = $r;
    check('create: 4 lines, cushions merged into one item, the couch matched to the existing item',
        count($sl) === 4 && $sl['V2']['item_id'] === $sl['OD1']['item_id'] && (int)$sl['V1']['item_id'] === $existing);
    check('create: the fridge is serial-tracked', $sl['G1']['tracking'] === 'serial');
    check('create: importing moves no stock', $count('SELECT COUNT(*) FROM inv_moves m JOIN inv_shipment_lines l ON l.item_id = m.item_id WHERE l.shipment_id = :s', [':s' => $sid]) === 0);
    check('create: expected status, sections kept', inv_shipment_fetch($sid)['status'] === 'expected' && $sl['OD1']['section'] === 'Off-Duty');
    check('create: a re-import of the same file is spotted', inv_ship_find_duplicate("zz-{$sfx}.xlsx", 4) === $sid && inv_ship_find_duplicate("zz-{$sfx}.xlsx", 5) === null);
    check('create: a store is required', str_contains($refused(fn() => inv_shipment_create(['name' => 'x', 'to_location_id' => $miLoc] + $head, $slines, $sgroups, $owner)), 'store'));

    $lid = fn(string $c): int => (int)$sl[$c]['id'];
    $bal = fn(string $c): int => inv_balance((int)$sl[$c]['item_id'], $tdStore);
    $r1 = inv_shipment_receive($sid, [$lid('V1') => ['good' => '5'], $lid('V2') => ['good' => '10', 'damaged' => '1', 'note' => 'torn']], $owner);
    check('receive: goods go into the store', $r1['received'] === 15 && $bal('V1') === 5 && $bal('V2') === 10);
    check('receive: moves link back to their line', $count('SELECT COUNT(*) FROM inv_moves WHERE shipment_line_id = :l', [':l' => $lid('V2')]) === 1);
    check('receive: status moves to receiving', inv_shipment_fetch($sid)['status'] === 'receiving');
    $r2 = inv_shipment_receive($sid, [$lid('V1') => ['good' => '5'], $lid('V2') => ['good' => '10', 'damaged' => '1', 'note' => 'torn']], $owner);
    check('receive: the same totals again change nothing', $r2['lines'] === 0 && $r2['received'] === 0 && $bal('V1') === 5);
    inv_shipment_receive($sid, [$lid('V1') => ['good' => '8']], $owner);
    check('receive: a later round adds only the difference', $bal('V1') === 8 && $count('SELECT COUNT(*) FROM inv_moves WHERE shipment_line_id = :l', [':l' => $lid('V1')]) === 2);
    inv_shipment_receive($sid, [$lid('V2') => ['damaged' => '3']], $owner);
    check('receive: damaged units never enter stock', $bal('V2') === 10 && $count('SELECT COUNT(*) FROM inv_moves WHERE shipment_line_id = :l', [':l' => $lid('V2')]) === 1
        && (int)inv_shipment_lines($sid)[1]['qty_damaged'] === 3);
    inv_shipment_receive($sid, [$lid('V1') => ['good' => '6']], $owner);
    check('receive: a lower count writes a correction', $bal('V1') === 6
        && $count("SELECT COUNT(*) FROM inv_moves WHERE shipment_line_id = :l AND reason = 'written_off'", [':l' => $lid('V1')]) === 1);
    check('receive: more than ordered is refused without the tick', str_contains($refused(fn() => inv_shipment_receive($sid, [$lid('OD1') => ['good' => '5']], $owner)), 'More than ordered'));
    inv_shipment_receive($sid, [$lid('OD1') => ['good' => '5', 'over' => '1']], $owner);
    check('receive: …and accepted with it', $bal('OD1') === 15);
    inv_shipment_receive($sid, [$lid('G1') => ['good' => '2', 'serials' => ['ZZSN1-' . $sfx, '']]], $owner);
    check('receive: serial items become units, one per piece', $count("SELECT COUNT(*) FROM inv_assets WHERE item_id = :i AND location_id = :l AND status = 'active'",
        [':i' => (int)$sl['G1']['item_id'], ':l' => $tdStore]) === 2 && $bal('G1') === 2);
    check('receive: a serial count can’t be lowered here', str_contains($refused(fn() => inv_shipment_receive($sid, [$lid('G1') => ['good' => '1']], $owner)), 'item page'));
    inv_transfer((int)$sl['V1']['item_id'], 6, $tdStore, $miLoc, $owner);
    check('receive: a correction is refused once the stock has moved on', $refused(fn() => inv_shipment_receive($sid, [$lid('V1') => ['good' => '5']], $owner)) !== ''
        && (int)db_query('SELECT qty_good FROM inv_shipment_lines WHERE id = :l', [':l' => $lid('V1')])->fetchColumn() === 6);
    check('receive: a line from another shipment is refused', str_contains($refused(fn() => inv_shipment_receive($sid, [999999999 => ['good' => '1']], $owner)), 'belong'));

    inv_shipment_mark_received($sid);
    check('status: marked received', inv_shipment_fetch($sid)['status'] === 'received');
    inv_shipment_receive($sid, [$lid('V2') => ['good' => '11']], $owner);
    check('status: a later correction reopens it', inv_shipment_fetch($sid)['status'] === 'receiving');
    check('status: cancel is refused once something arrived', str_contains($refused(fn() => inv_shipment_cancel($sid)), 'already'));
    $sid2 = inv_shipment_create(['name' => "ZZ Empty {$sfx}", 'source_filename' => ''] + $head, $slines, $sgroups, $owner);
    inv_shipment_cancel($sid2);
    check('status: an untouched shipment cancels', inv_shipment_fetch($sid2)['status'] === 'cancelled'
        && str_contains($refused(fn() => inv_shipment_receive($sid2, [], $owner)), 'cancelled'));

    $f = inv_shipment_fetch($sid);
    check('read: totals', (int)$f['line_count'] === 4 && (int)$f['pieces_expected'] === 30 && (int)$f['pieces_good'] === 6 + 11 + 5 + 2);
    check('read: sections in list order', array_column(inv_shipment_sections($sid), 'section') === ['Villas', 'Off-Duty', 'General']);
    check('read: a section filter', count(inv_shipment_lines($sid, 'Villas')) === 2);
    check('scope: the list follows the store', in_array($sid, array_column(inv_shipments_list([$vMI]), 'id'), false)
        && !in_array($sid, array_map('intval', array_column(inv_shipments_list([$vZ]), 'id')), true));
    check('scope: open deliveries leave out cancelled ones', !in_array($sid2, array_map('intval', array_column(inv_shipments_open(null), 'id')), true));
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_shipments_logic.php`
Expected: fatal `Failed opening required …inventory-shipments.php`.

- [ ] **Step 3: Let a move carry its shipment line (`includes/inventory.php`)**

1. In `inv_normalize_move()` add after the `'count_line_id'` entry:

```php
        'shipment_line_id' => $id($m['shipment_line_id'] ?? null),
```

2. In `inv_move()`'s docblock key list add `shipment_line_id? (a shipment receipt),` after `count_line_id?,`.

3. In `inv_move_tx()` replace the whole `db_query('INSERT INTO inv_moves (…) VALUES (…)', [...]);` statement with:

```php
    // shipment_line_id only exists after add_inventory_shipments.sql, and is only set by a shipment.
    $slCol = $n['shipment_line_id'] !== null ? ', shipment_line_id' : '';
    $slVal = $n['shipment_line_id'] !== null ? ', :sl' : '';
    $params = [':i' => $n['item_id'], ':q' => $qty, ':f' => $from, ':t' => $to, ':r' => $n['reason'],
         ':uv' => $unit, ':v' => $unit === null ? null : round($unit * $qty, 2), ':cur' => (string)$item['currency'],
         ':a' => $n['asset_id'], ':s' => $n['pos_sale_id'], ':cl' => $n['count_line_id'],
         ':ci' => !empty($terms['consignor_id']) ? (int)$terms['consignor_id'] : null,
         ':cp' => isset($terms['consign_pct']) && $terms['consign_pct'] !== null ? (float)$terms['consign_pct'] : null,
         ':cc' => isset($terms['consignor_cost']) && $terms['consignor_cost'] !== null ? (float)$terms['consignor_cost'] : null,
         ':n' => $n['note'] !== '' ? $n['note'] : null, ':u' => $n['user_id']];
    if ($n['shipment_line_id'] !== null) $params[':sl'] = $n['shipment_line_id'];
    db_query(
        "INSERT INTO inv_moves (item_id, qty, from_location_id, to_location_id, reason, unit_value, value, currency,
                                asset_id, pos_sale_id, count_line_id, consignor_id, consign_pct, consignor_cost, note, admin_user_id{$slCol})
         VALUES (:i, :q, :f, :t, :r, :uv, :v, :cur, :a, :s, :cl, :ci, :cp, :cc, :n, :u{$slVal})",
        $params
    );
```

4. In `inv_asset_create()`, in its `inv_move([...])` call, add `'shipment_line_id' => $f['shipment_line_id'] ?? null,` after `'user_id' => $userId,`, and add `shipment_line_id` to the docblock's `$f` list.

- [ ] **Step 4: Create `includes/inventory-shipments.php`**

```php
<?php
declare(strict_types=1);
/**
 * Inventory — Shipments: a supplier list turned into inventory.
 * Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md
 * Migration: db/migrations/add_inventory_shipments.sql. Test: php tests/inventory_shipments_logic.php
 *
 * Load-bearing rules:
 *   • Importing NEVER moves stock — it creates items (matched by name) and lines.
 *   • Receiving posts each line's NEW TOTALS; the server writes only the difference
 *     (receive moves into the shipment's store, or a written-off "Receiving
 *     correction"), so a retry or a double tap never counts twice. Damaged units
 *     are stored on the line only — they never enter stock.
 *   • Lock order: inv_shipments → inv_shipment_lines (id order) → balances
 *     (inv_lock_balances) → inv_assets.
 *   • Nothing here scopes by venue — pages check inv_ship_store_allowed().
 */

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/inventory-shipment-import.php';

const INV_SHIP_STATUS = [
    'expected'  => ['Expected', 'badge--grey'],
    'receiving' => ['Receiving', 'badge--orange'],
    'received'  => ['Received', 'badge--green'],
    'cancelled' => ['Cancelled', 'badge--grey'],
];

// ── Pure rules ──────────────────────────────────────────────────────────────

/** Pieces not accounted for: expected − good − damaged, never below 0 — PURE. */
function inv_ship_short(array $l): int {
    return max(0, (int)$l['qty_expected'] - (int)$l['qty_good'] - (int)$l['qty_damaged']);
}

/**
 * Check one posted card against its line — PURE. $p: good, damaged (the NEW TOTALS,
 * '' = keep), note (absent = keep), over ('1' = "more than ordered" confirmed).
 * Returns ['good','damaged','delta','note','changed'] or the refusal text.
 */
function inv_ship_receive_plan(array $line, array $p): array|string {
    $label = trim(((string)($line['code'] ?? '')) . ' ' . mb_strimwidth((string)$line['description'], 0, 60, '…'));
    $num = function (string $k, int $cur) use ($p): ?int {
        $v = trim((string)($p[$k] ?? ''));
        if ($v === '') return $cur;
        return (ctype_digit($v) && strlen($v) <= 6) ? (int)$v : null;
    };
    $good = $num('good', (int)$line['qty_good']);
    $dmg  = $num('damaged', (int)$line['qty_damaged']);
    if ($good === null || $dmg === null) return "{$label}: enter whole numbers.";
    if ($good + $dmg > (int)$line['qty_expected'] && ($p['over'] ?? '') !== '1') {
        return "{$label}: that is more than the {$line['qty_expected']} ordered — tick “More than ordered” to confirm.";
    }
    $oldNote = trim((string)($line['note'] ?? ''));
    $note    = array_key_exists('note', $p) ? mb_substr(trim((string)$p['note']), 0, 1000) : $oldNote;
    $delta   = $good - (int)$line['qty_good'];
    return ['good' => $good, 'damaged' => $dmg, 'delta' => $delta, 'note' => $note,
            'changed' => $delta !== 0 || $dmg !== (int)$line['qty_damaged'] || $note !== $oldNote];
}

/** May this account work on shipments landing in this store? Owner: yes; others: the store's venue set meets theirs — PURE. */
function inv_ship_store_allowed(array $store, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    return ($store['kind'] ?? '') === 'store'
        && (bool) array_intersect(inv_location_venue_set($store), array_map('intval', $venueIds));
}

/** The store of a shipment row (from inv_shipment_fetch()/list) as a location row — PURE. */
function inv_shipment_store_row(array $s): array {
    return ['id' => (int)$s['to_location_id'], 'kind' => $s['store_kind'], 'name' => $s['store_name'],
            'venue_id' => $s['store_venue_id'], 'share_venue_ids' => $s['store_share_venue_ids'], 'is_active' => $s['store_active']];
}

// ── Reads ───────────────────────────────────────────────────────────────────

function inv_shipment_select_sql(): string {
    return "SELECT s.*, l.name AS store_name, l.kind AS store_kind, l.venue_id AS store_venue_id,
                   l.share_venue_ids AS store_share_venue_ids, l.is_active AS store_active, a.name AS created_by_name,
                   COALESCE(t.line_count, 0) AS line_count, COALESCE(t.pieces_expected, 0) AS pieces_expected,
                   COALESCE(t.pieces_good, 0) AS pieces_good, COALESCE(t.pieces_damaged, 0) AS pieces_damaged
              FROM inv_shipments s
              JOIN inv_locations l      ON l.id = s.to_location_id
              LEFT JOIN admin_users a   ON a.id = s.created_by
              LEFT JOIN (SELECT shipment_id, COUNT(*) AS line_count, SUM(qty_expected) AS pieces_expected,
                                SUM(qty_good) AS pieces_good, SUM(qty_damaged) AS pieces_damaged
                           FROM inv_shipment_lines GROUP BY shipment_id) t ON t.shipment_id = s.id";
}

function inv_shipment_fetch(int $id): array|false {
    if (!inv_shipments_supported() || $id <= 0) return false;
    return db_query(inv_shipment_select_sql() . ' WHERE s.id = :id', [':id' => $id])->fetch();
}

/** Shipments this account may see, newest first (at most 200). */
function inv_shipments_list(?array $venueIds): array {
    if (!inv_shipments_supported()) return [];
    $rows = db_query(inv_shipment_select_sql() . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 200')->fetchAll();
    return array_values(array_filter($rows, fn($s) => inv_ship_store_allowed(inv_shipment_store_row($s), $venueIds)));
}

/** Shipments still to receive (expected / receiving) for this account. */
function inv_shipments_open(?array $venueIds): array {
    return array_values(array_filter(inv_shipments_list($venueIds), fn($s) => in_array($s['status'], ['expected', 'receiving'], true)));
}

/** A shipment's lines in list order (optionally one section), with their item. */
function inv_shipment_lines(int $shipmentId, string $section = ''): array {
    if (!inv_shipments_supported()) return [];
    $p = [':s' => $shipmentId];
    $w = '';
    if ($section !== '') { $w = ' AND sl.section = :sec'; $p[':sec'] = $section; }
    return db_query("SELECT sl.*, i.name AS item_name, i.tracking, i.unit_label, i.image_key, i.icon, i.category
                       FROM inv_shipment_lines sl JOIN inv_items i ON i.id = sl.item_id
                      WHERE sl.shipment_id = :s{$w} ORDER BY sl.sort_order, sl.id", $p)->fetchAll();
}

/** [['section','lines','pieces']] in list order. */
function inv_shipment_sections(int $shipmentId): array {
    if (!inv_shipments_supported()) return [];
    return db_query("SELECT COALESCE(section, '') AS section, COUNT(*) AS lines, SUM(qty_expected) AS pieces
                       FROM inv_shipment_lines WHERE shipment_id = :s GROUP BY COALESCE(section, '') ORDER BY MIN(sort_order)",
                    [':s' => $shipmentId])->fetchAll();
}

/** Active stores this account may send a shipment to (Main stock first). */
function inv_ship_target_stores(?array $venueIds): array {
    if (!inv_shipments_supported()) return [];
    $rows = db_query("SELECT * FROM inv_locations WHERE kind = 'store' AND is_active = TRUE ORDER BY is_main DESC, name")->fetchAll();
    return array_values(array_filter($rows, fn($s) => inv_ship_store_allowed($s, $venueIds)));
}

/** A live shipment already imported from this file with this many lines, or null. */
function inv_ship_find_duplicate(string $filename, int $lineCount): ?int {
    if (!inv_shipments_supported() || $filename === '') return null;
    $id = db_query("SELECT s.id FROM inv_shipments s
                     WHERE s.status <> 'cancelled' AND s.source_filename = :f
                       AND (SELECT COUNT(*) FROM inv_shipment_lines l WHERE l.shipment_id = s.id) = :n
                     ORDER BY s.id LIMIT 1", [':f' => $filename, ':n' => $lineCount])->fetchColumn();
    return $id === false ? null : (int)$id;
}

// ── Writes ──────────────────────────────────────────────────────────────────

/**
 * Create a shipment from a parsed list — NO stock moves. Items are matched by name
 * (an active item, case-insensitive) or created with the group's category, kind
 * (operational | spare | serial) and unit. $head: name, supplier, reference,
 * containers, expected_on (Y-m-d or ''), to_location_id (a store),
 * source_filename. $lines: parsed lines; $groups: inv_ship_group()-shaped, with the
 * preview's choices applied. Does NO scoping. Returns the shipment id.
 */
function inv_shipment_create(array $head, array $lines, array $groups, ?int $userId): int {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $name = mb_substr(trim((string)($head['name'] ?? '')), 0, 160);
    if ($name === '') throw new InvRefusal('Give the shipment a name.');
    if (!$lines) throw new InvRefusal('The list has no lines.');
    if (count($lines) > INV_SHIP_MAX_LINES) throw new InvRefusal('That list is too long — split it into smaller files.');
    $store = inv_fetch_location((int)($head['to_location_id'] ?? 0));
    if (!$store || $store['kind'] !== 'store' || !inv_bool($store['is_active'])) throw new InvRefusal('Pick the store it lands in.');
    $exp = trim((string)($head['expected_on'] ?? ''));
    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) throw new InvRefusal('Pick a valid expected date.');
    $opt = fn(string $k, int $max): ?string => ($s = trim((string)($head[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;

    return inv_tx(function () use ($lines, $groups, $userId, $name, $store, $exp, $opt): int {
        $itemFor = [];
        foreach ($groups as $g) {
            $gname = mb_substr(inv_ship_text((string)($g['name'] ?? '')), 0, 160);
            if ($gname === '') throw new InvRefusal('Every item needs a name.');
            $kind = isset(INV_SHIP_KINDS[$g['kind'] ?? '']) ? (string)$g['kind'] : 'operational';
            $id = db_query('SELECT id FROM inv_items WHERE is_active = TRUE AND lower(name) = lower(:n) ORDER BY id LIMIT 1', [':n' => $gname])->fetchColumn();
            if ($id === false) {
                $id = inv_create_item(['name' => $gname, 'item_type' => $kind === 'spare' ? 'spare' : 'operational',
                    'tracking' => $kind === 'serial' ? 'serial' : 'qty', 'category' => (string)($g['category'] ?? ''),
                    'unit_label' => (string)($g['unit'] ?? 'pcs')]);
            }
            foreach ((array)$g['lines'] as $i) $itemFor[$i] = (int)$id;
        }
        db_query('INSERT INTO inv_shipments (name, supplier, reference, containers, expected_on, to_location_id, source_filename, created_by)
                  VALUES (:n, :s, :r, :c, :e, :l, :f, :u)', [
            ':n' => $name, ':s' => $opt('supplier', 160), ':r' => $opt('reference', 80), ':c' => $opt('containers', 2000),
            ':e' => $exp !== '' ? $exp : null, ':l' => (int)$store['id'], ':f' => $opt('source_filename', 200), ':u' => $userId]);
        $sid = (int) db()->lastInsertId();
        $pos = 0;
        foreach ($lines as $i => $l) {
            if (!isset($itemFor[$i])) throw new InvRefusal('A line was left without an item.');
            $qty = (int)($l['qty'] ?? 0);
            if ($qty < 1 || $qty > INV_SHIP_MAX_QTY) throw new InvRefusal('A line has an impossible quantity.');
            db_query('INSERT INTO inv_shipment_lines (shipment_id, sort_order, section, code, hs_code, description, item_id, qty_expected)
                      VALUES (:s, :o, :sec, :c, :hs, :d, :i, :q)', [
                ':s' => $sid, ':o' => ++$pos,
                ':sec' => ($x = trim((string)($l['section'] ?? ''))) !== '' ? mb_substr($x, 0, 120) : null,
                ':c'   => ($x = trim((string)($l['code'] ?? ''))) !== '' ? mb_substr($x, 0, 40) : null,
                ':hs'  => ($x = trim((string)($l['hs_code'] ?? ''))) !== '' ? mb_substr($x, 0, 20) : null,
                ':d'   => (string)$l['description'], ':i' => $itemFor[$i], ':q' => $qty]);
        }
        return $sid;
    });
}

/**
 * Save a receiving round. $posted: [line id => ['good','damaged','note','over','serials' => […]]]
 * where good/damaged are the line's NEW TOTALS. Writes receive moves for what
 * arrived since the last save (a serial item: one unit per piece, with the serials
 * given), a written-off "Receiving correction" for a lowered good count, nothing
 * for damaged units. Does NO scoping. Returns ['lines','received','corrected'].
 */
function inv_shipment_receive(int $shipmentId, array $posted, ?int $userId): array {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    $byId = [];
    foreach ($posted as $k => $v) if (is_numeric($k) && (int)$k > 0) $byId[(int)$k] = (array)$v;
    ksort($byId);

    return inv_tx(function () use ($shipmentId, $byId, $userId): array {
        $s = db_query('SELECT id, status, to_location_id FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $shipmentId])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        if ($s['status'] === 'cancelled') throw new InvRefusal('That shipment was cancelled.');
        $store = (int)$s['to_location_id'];

        $lines = [];
        foreach (array_keys($byId) as $lid) {   // id order = the lock order
            $l = db_query('SELECT sl.*, i.tracking, i.name AS item_name FROM inv_shipment_lines sl JOIN inv_items i ON i.id = sl.item_id
                            WHERE sl.id = :l AND sl.shipment_id = :s FOR UPDATE OF sl', [':l' => $lid, ':s' => $shipmentId])->fetch();
            if (!$l) throw new InvRefusal('A line does not belong to this shipment.');
            $lines[$lid] = $l;
        }
        $plans = [];
        foreach ($lines as $lid => $l) {
            $p = inv_ship_receive_plan($l, $byId[$lid]);
            if (is_string($p)) throw new InvRefusal($p);
            if (!$p['changed']) continue;
            if ($l['tracking'] === 'serial' && $p['delta'] < 0) {
                throw new InvRefusal("{$l['item_name']}: a registered unit is taken back from its item page, not here.");
            }
            $plans[$lid] = $p;
        }
        $pairs = [];
        foreach ($plans as $lid => $p) if ($p['delta'] !== 0) $pairs[] = [(int)$lines[$lid]['item_id'], $store];
        inv_lock_balances($pairs);

        $received = 0; $corrected = 0;
        foreach ($plans as $lid => $p) {
            $l    = $lines[$lid];
            $item = (int)$l['item_id'];
            $unit = $l['unit_cost'] !== null ? (float)$l['unit_cost'] : null;
            if ($p['delta'] > 0 && $l['tracking'] === 'serial') {
                $serials = array_values((array)($byId[$lid]['serials'] ?? []));
                for ($k = 0; $k < $p['delta']; $k++) {
                    inv_asset_create($item, $store, ['serial' => trim((string)($serials[$k] ?? '')), 'condition' => 'new',
                        'purchase_value' => $unit, 'shipment_line_id' => $lid], $userId);
                }
            } elseif ($p['delta'] > 0) {
                inv_move(['item_id' => $item, 'qty' => $p['delta'], 'to' => $store, 'reason' => 'receive', 'unit_value' => $unit,
                          'user_id' => $userId, 'shipment_line_id' => $lid, 'note' => trim('Shipment ' . ($l['code'] ?? ''))]);
            } elseif ($p['delta'] < 0) {
                inv_move(['item_id' => $item, 'qty' => -$p['delta'], 'from' => $store, 'reason' => 'written_off', 'unit_value' => $unit,
                          'user_id' => $userId, 'shipment_line_id' => $lid, 'note' => 'Receiving correction']);
            }
            $received  += max(0, $p['delta']);
            $corrected += max(0, -$p['delta']);
            db_query('UPDATE inv_shipment_lines SET qty_good = :g, qty_damaged = :d, note = :n, updated_by = :u, updated_at = now() WHERE id = :id',
                [':g' => $p['good'], ':d' => $p['damaged'], ':n' => $p['note'] !== '' ? $p['note'] : null, ':u' => $userId, ':id' => $lid]);
        }
        if ($plans) {
            db_query("UPDATE inv_shipments SET status = 'receiving', received_at = NULL WHERE id = :id AND status IN ('expected', 'received')", [':id' => $shipmentId]);
        }
        return ['lines' => count($plans), 'received' => $received, 'corrected' => $corrected];
    });
}

/** Mark a shipment received (it can still be corrected, which reopens it). */
function inv_shipment_mark_received(int $id): void {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    inv_tx(function () use ($id): void {
        $s = db_query('SELECT status FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        if ($s['status'] === 'cancelled') throw new InvRefusal('That shipment was cancelled.');
        db_query("UPDATE inv_shipments SET status = 'received', received_at = now() WHERE id = :id AND status <> 'received'", [':id' => $id]);
    });
}

/** Cancel a shipment — only while nothing has been received or marked damaged. */
function inv_shipment_cancel(int $id): void {
    if (!inv_shipments_supported()) throw new InvRefusal('Run add_inventory_shipments.sql first.');
    inv_tx(function () use ($id): void {
        $s = db_query('SELECT status FROM inv_shipments WHERE id = :id FOR UPDATE', [':id' => $id])->fetch();
        if (!$s) throw new InvRefusal('That shipment no longer exists.');
        $touched = (int) db_query('SELECT COALESCE(SUM(qty_good + qty_damaged), 0) FROM inv_shipment_lines WHERE shipment_id = :id', [':id' => $id])->fetchColumn();
        if ($touched > 0 || !in_array($s['status'], ['expected', 'cancelled'], true)) {
            throw new InvRefusal('Something has already been received — correct the lines instead of cancelling.');
        }
        db_query("UPDATE inv_shipments SET status = 'cancelled' WHERE id = :id", [':id' => $id]);
    });
}

/** Record a line's damage photo; returns the previous key (for the caller to delete) or null. */
function inv_shipment_set_photo(int $shipmentId, int $lineId, string $key): ?string {
    $old = db_query('SELECT photo_key FROM inv_shipment_lines WHERE id = :l AND shipment_id = :s', [':l' => $lineId, ':s' => $shipmentId])->fetchColumn();
    if ($old === false) throw new InvRefusal('A line does not belong to this shipment.');
    db_query('UPDATE inv_shipment_lines SET photo_key = :k WHERE id = :l', [':k' => $key, ':l' => $lineId]);
    return $old !== null && $old !== '' ? (string)$old : null;
}

/** CSV rows (header first) for the shipment page's export — PURE. */
function inv_shipment_csv_rows(array $lines): array {
    $out = [['Section', 'Code', 'HS code', 'Description', 'Item', 'Expected', 'Good', 'Damaged', 'Short', 'Note']];
    foreach ($lines as $l) {
        $out[] = [(string)$l['section'], (string)$l['code'], (string)$l['hs_code'], (string)$l['description'], (string)$l['item_name'],
                  (int)$l['qty_expected'], (int)$l['qty_good'], (int)$l['qty_damaged'], inv_ship_short($l), (string)($l['note'] ?? '')];
    }
    return $out;
}
```

- [ ] **Step 5: Run the tests**

Run: `php tests/inventory_shipments_logic.php && php tests/inventory_logic.php && php tests/pos_logic.php`
Expected: all `plan:`, `short:`, `store access:`, `create:`, `receive:`, `status:`, `read:`, `scope:` checks PASS; every suite `ALL PASS`.

Note on one check: `check('read: totals' …)` expects good = V1 6 + V2 11 + OD1 5 + G1 2 = 24, and expected = 8 + 16 + 4 + 2 = 30.

- [ ] **Step 6: Commit**

```bash
git add includes/inventory.php includes/inventory-shipments.php tests/inventory_shipments_logic.php
git commit -m "feat(inventory): shipments — create without moves, receiving rounds as totals, status

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Locations page — add a store, owner + shares; restock from a chosen store

**Files:**
- Modify: `admin/inventory-locations.php`
- Modify: `admin/inventory-location.php`

- [ ] **Step 1: Locations page POST actions**

In `admin/inventory-locations.php`, inside the POST `try`, after the `add_area` branch add:

```php
        } elseif ($act === 'add_store') {
            if (!is_owner()) throw new InvRefusal('Only the owner adds stores.');
            $owner = (int)($_POST['venue_id'] ?? 0);
            $newId = inv_create_store((string)($_POST['name'] ?? ''), $owner > 0 ? $owner : null, (array)($_POST['share'] ?? []));
            audit_log('inv.store_add', 'inv_location', $newId, (string)($_POST['name'] ?? ''));
            $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => 'Store added.'];
```

and in the `save_location` branch, right after `inv_update_location((int)$loc['id'], $v);` add:

```php
            // A store's owner + shares are owner business (Main stock has neither).
            if ($loc['kind'] === 'store' && is_owner() && inv_shipments_supported() && !inv_bool($loc['is_main'] ?? false) && isset($_POST['venue_id'])) {
                $owner = (int)$_POST['venue_id'];
                inv_update_store_owner((int)$loc['id'], $owner > 0 ? $owner : null, (array)($_POST['share'] ?? []));
            }
```

- [ ] **Step 2: Data for the forms**

After `$usersFor  = [];` add:

```php
$canStores = is_owner() && $supported && inv_shipments_supported();
$allVenues = $canStores ? db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll() : [];
```

- [ ] **Step 3: Owner + shares in a store's settings sheet**

In the settings form, after the `<?php if (in_array($l['kind'], ['area', 'store'], true)): ?> … Name … <?php endif; ?>` block, add:

```php
                <?php if ($canStores && $l['kind'] === 'store' && !inv_bool($l['is_main'] ?? false)): $shares = inv_pg_int_array($l['share_venue_ids'] ?? null); ?>
                <div class="field"><label>Belongs to</label><select name="venue_id" class="eselect eselect--block">
                  <option value="0">No property — shared by all</option>
                  <?php foreach ($allVenues as $vv): ?><option value="<?= (int)$vv['id'] ?>" <?= (int)$l['venue_id'] === (int)$vv['id'] ? 'selected' : '' ?>><?= e((string)$vv['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Also used by</label><div class="inv-chips">
                  <?php foreach ($allVenues as $vv): ?><label class="optchip"><input type="checkbox" name="share[]" value="<?= (int)$vv['id'] ?>" <?= in_array((int)$vv['id'], $shares, true) ? 'checked' : '' ?>><?= e((string)$vv['name']) ?></label><?php endforeach; ?></div></div>
                <?php endif; ?>
```

Also, under the location name in the table, show who a shared store serves. Replace
`<span class="inv-sub"><?= e(INV_LOCATION_KINDS[$l['kind']] ?? $l['kind']) ?><?= $closed ? ' · closed' : '' ?></span></td>`
with:

```php
            <?php $servesIds = $l['kind'] === 'store' ? inv_location_venue_set($l) : [];
                  $serves = $servesIds ? implode(', ', array_map(fn($vid) => (string)(db_query('SELECT name FROM venues WHERE id = :v', [':v' => $vid])->fetchColumn() ?: '?'), $servesIds)) : ''; ?>
            <span class="inv-sub"><?= e(inv_bool($l['is_main'] ?? false) ? 'Main stock · shared by all' : (INV_LOCATION_KINDS[$l['kind']] ?? $l['kind'])) ?><?= $serves !== '' ? ' · for ' . e($serves) : '' ?><?= $closed ? ' · closed' : '' ?></span></td>
```

(Only a handful of stores exist, so the per-store name lookups are fine.)

- [ ] **Step 4: "Add a store" card (owner)**

In the right-hand `<div class="inv-stack">`, before the "Add an area" card, add:

```php
    <?php if ($canStores): ?>
    <div class="card">
      <div class="card__head"><span class="card__title">Add a store</span></div>
      <div class="card__body" style="padding:16px 18px">
        <form method="POST" action="<?= $self ?>" class="inv-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_store">
          <div class="field"><label>Store name</label><input name="name" class="inp" maxlength="120" placeholder="TD Main Stock" required></div>
          <div class="field"><label>Belongs to</label><select name="venue_id" class="eselect eselect--block">
            <option value="0">No property — shared by all</option>
            <?php foreach ($allVenues as $vv): ?><option value="<?= (int)$vv['id'] ?>"><?= e((string)$vv['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Also used by</label><div class="inv-chips">
            <?php foreach ($allVenues as $vv): ?><label class="optchip"><input type="checkbox" name="share[]" value="<?= (int)$vv['id'] ?>"><?= e((string)$vv['name']) ?></label><?php endforeach; ?></div></div>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add store</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 5: Restock source on `admin/inventory-location.php`**

1. Replace line 38 (`$storeName = (string)(inv_fetch_location(inv_store_location_id())['name'] ?? 'Main stock');`) with:

```php
// Where "Restock to par" may pull from: open stores this account can move out of into here.
$sources = [];
if ($loc['kind'] !== 'store') {
    foreach (inv_locations_visible($vids) as $s) {
        if ($s['kind'] === 'store' && (int)$s['id'] !== (int)$loc['id'] && inv_move_in_scope($s, $loc, $vids)) $sources[(int)$s['id']] = $s;
    }
}
$defaultSource = inv_default_restock_source(array_values($sources), $loc);
```

2. Replace the start of the `restock` branch:

```php
        } elseif ($act === 'restock') {
            $store = inv_fetch_location(inv_store_location_id());
            if (!inv_move_in_scope($store, $loc, $vids)) throw new InvRefusal('That restock is outside your properties.');
            $r = inv_restock_to_par((int)$loc['id'], (int)$me['id']);
```

with:

```php
        } elseif ($act === 'restock') {
            $source = $sources[(int)($_POST['source_id'] ?? 0)] ?? null;   // only a store offered on this page
            if (!$source) throw new InvRefusal('Pick a store you can restock from.');
            $storeName = (string)$source['name'];
            $r = inv_restock_to_par((int)$loc['id'], (int)$me['id'], (int)$source['id']);
```

3. Replace the restock form (the `<?php if ($editable && $open && $needs > 0 && $loc['kind'] !== 'store'): ?>` block through its `</form>`) with:

```php
    <?php if ($editable && $open && $needs > 0 && $loc['kind'] !== 'store' && $sources): ?>
    <form method="POST" action="<?= e($self) ?>" class="inv-restock">
      <?= csrf_field() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
      <select name="source_id" class="eselect" aria-label="Restock from">
        <?php foreach ($sources as $sid => $s): ?><option value="<?= (int)$sid ?>" <?= $sid === $defaultSource ? 'selected' : '' ?>><?= e((string)$s['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary btn-sm" data-confirm="Move what is short from the chosen store to here?"><?= admin_icon('arrow-right', 15) ?> Restock to par</button>
    </form>
```

4. In the page's `<style>` block (or, if it has none, in a new `<style>` before `<?php include __DIR__ . '/_layout_end.php'; ?>`) add:

```css
.inv-restock{margin-left:auto;align-self:center;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
```

5. Update the page docblock line "then "Restock to par" pulls the shortfall from Main stock" to "pulls the shortfall from a store the account may use (default: the store serving this property, else Main stock)".

- [ ] **Step 6: Lint and run the suites**

Run:
```bash
php -l admin/inventory-locations.php && php -l admin/inventory-location.php && php tests/inventory_views_logic.php && php tests/inventory_shipments_logic.php
```
Expected: `No syntax errors detected` ×2, both suites `ALL PASS`.

- [ ] **Step 7: Commit**

```bash
git add admin/inventory-locations.php admin/inventory-location.php
git commit -m "feat(inventory): add stores with an owner and shares; restock from a chosen store

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Shipments list, Import Excel, preview, confirm

**Files:**
- Create: `admin/inventory-shipments.php`

- [ ] **Step 1: Write the page**

Create `admin/inventory-shipments.php`:

```php
<?php
/**
 * Admin: Shipments — the list, and Import Excel → preview → confirm. The preview
 * merges lines by name into items (rename a group, or split one line off), shows
 * the suggested category / kind / unit, and matches existing items by name.
 * Confirming creates the items and the shipment; stock moves only when it is
 * received (admin/inventory-receive.php). Owner + managers of a store's properties.
 * The parsed file lives in the session for an hour (the file itself is not kept).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-shipments.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_login();
require_manager();

$self      = '/admin/inventory-shipments.php';
$me        = current_admin();
$vids      = admin_venue_ids();
$supported = inv_shipments_supported();
$flash     = $_SESSION['invs_flash'] ?? null; unset($_SESSION['invs_flash']);
$targets   = $supported ? inv_ship_target_stores($vids) : [];

function invs_go(string $url): never { header('Location: ' . $url); exit; }
function invs_import(string $token): ?array {
    $x = $_SESSION['inv_ship_import'][$token] ?? null;
    return (is_array($x) && time() - (int)$x['at'] < 3600) ? $x : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act   = (string)($_POST['action'] ?? '');
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
    $back  = $self;
    try {
        if ($act === 'upload') {
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new InvRefusal('Choose the Excel file to import.');
            if ((int)$f['size'] > 10 * 1024 * 1024) throw new InvRefusal('That file is larger than 10 MB.');
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new InvRefusal('Import an .xlsx file.');
            try { $wb = inv_ship_parse_workbook(xlsx_read_sheets((string)$f['tmp_name'])); }
            catch (RuntimeException $e) { throw new InvRefusal($e->getMessage()); }
            if (!$wb['lines']) throw new InvRefusal('No shipment list found — the file needs a sheet with “Item No”, “Qty” and “Description” columns.');
            if (count($wb['lines']) > INV_SHIP_MAX_LINES) throw new InvRefusal('That list is too long — split it into smaller files.');
            $token = bin2hex(random_bytes(8));
            $all = (array)($_SESSION['inv_ship_import'] ?? []);
            $all[$token] = ['at' => time(), 'filename' => mb_substr(basename((string)$f['name']), 0, 200), 'parsed' => $wb, 'names' => [], 'choices' => []];
            $_SESSION['inv_ship_import'] = array_slice($all, -3, null, true);   // keep the three newest
            invs_go($self . '?preview=' . $token);
        }
        $imp = $token !== '' ? invs_import($token) : null;
        if (in_array($act, ['regroup', 'confirm', 'discard'], true) && !$imp) throw new InvRefusal('That preview has expired — import the file again.');
        if ($act === 'discard') {
            unset($_SESSION['inv_ship_import'][$token]);
            invs_go($self);
        }
        if ($act === 'regroup' || $act === 'confirm') {
            $back   = $self . '?preview=' . $token;
            $lines  = $imp['parsed']['lines'];
            $groups = inv_ship_groups_with_choices($lines, $imp['names'], $imp['choices']);
            [$names, $choices] = inv_ship_apply_preview($groups, ['g' => (array)($_POST['g'] ?? []), 'split' => (array)($_POST['split'] ?? [])], $imp['names'], $imp['choices']);
            $_SESSION['inv_ship_import'][$token]['names']   = $names;
            $_SESSION['inv_ship_import'][$token]['choices'] = $choices;
            $_SESSION['inv_ship_import'][$token]['head']    = array_map(fn($v) => mb_substr(trim((string)$v), 0, 2000), (array)($_POST['head'] ?? []));
            if ($act === 'regroup') invs_go($back);

            $head  = $_SESSION['inv_ship_import'][$token]['head'];
            $store = null;
            foreach ($targets as $t) if ((int)$t['id'] === (int)($head['to_location_id'] ?? 0)) $store = $t;
            if (!$store) throw new InvRefusal('Pick the store it lands in.');
            if (($dup = inv_ship_find_duplicate($imp['filename'], count($lines))) && empty($_POST['allow_duplicate'])) {
                throw new InvRefusal('This file was already imported — open that shipment, or tick “Import it again”.');
            }
            $sid = inv_shipment_create($head + ['source_filename' => $imp['filename']], $lines,
                                       inv_ship_groups_with_choices($lines, $names, $choices), (int)$me['id']);
            unset($_SESSION['inv_ship_import'][$token]);
            audit_log('inv.shipment_import', 'inv_shipment', $sid, $imp['filename'] . ': ' . count($lines) . ' lines');
            $_SESSION['invs_flash'] = ['type' => 'success', 'msg' => 'Shipment created — nothing is in stock until it is received.'];
            invs_go('/admin/inventory-shipment.php?id=' . $sid);
        }
    } catch (InvRefusal $e) {
        $_SESSION['invs_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    invs_go($back);
}

$token   = preg_replace('/[^a-f0-9]/', '', (string)($_GET['preview'] ?? ''));
$imp     = ($supported && $token !== '') ? invs_import($token) : null;
$list    = (!$imp && $supported) ? inv_shipments_list($vids) : [];
$groups  = $imp ? inv_ship_groups_with_choices($imp['parsed']['lines'], $imp['names'], $imp['choices']) : [];
$known   = $imp ? inv_ship_existing_items() : [];   // [merge key => existing active item] — the same match create uses
$head    = $imp['head'] ?? [];
$hv      = fn(string $k, string $def = ''): string => (string)($head[$k] ?? $def);
$dupId   = $imp ? inv_ship_find_duplicate($imp['filename'], count($imp['parsed']['lines'])) : null;
// "Lands in" defaults to the first store that is not Main stock (TD Main Stock), else Main stock.
$defaultTarget = 0;
foreach ($targets as $t) if (!inv_bool($t['is_main'])) { $defaultTarget = (int)$t['id']; break; }
if (!$defaultTarget && $targets) $defaultTarget = (int)$targets[0]['id'];

$pageTitle  = 'Shipments';
$activeMenu = 'inventory_shipments';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= $imp ? 'Check the import' : 'Shipments' ?></h1>
  <a href="<?= $imp ? $self : '/admin/inventory.php' ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> <?= $imp ? 'Shipments' : 'Inventory' ?></a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_inventory_shipments.sql</code> migration (Admin → Migrations) to use shipments.</div>

<?php elseif ($imp): $wb = $imp['parsed']; ?>
  <form method="POST" action="<?= $self ?>" id="invsPreview">
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="card" style="margin-bottom:18px"><div class="card__body" style="padding:16px 18px">
      <p style="margin:0 0 12px"><strong><?= e($imp['filename']) ?></strong> —
        <?= count($wb['lines']) ?> lines · <?= count($groups) ?> items · <?= number_format(array_sum(array_column($wb['lines'], 'qty'))) ?> pieces ·
        <?= count(array_unique(array_column($wb['lines'], 'section'))) ?> sections, from <?= e(implode(' + ', $wb['sheets'])) ?>.</p>
      <?php if ($wb['skipped']): ?>
      <div class="alert alert--info" style="margin:0 0 12px">These rows were not understood and are left out:
        <?php foreach (array_slice($wb['skipped'], 0, 10) as $sk): ?><br><?= e($sk['sheet']) ?> row <?= (int)$sk['row'] ?>: <?= e($sk['text']) ?><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($dupId): ?>
      <div class="alert alert--info" style="margin:0 0 12px">This file was already imported — <a href="/admin/inventory-shipment.php?id=<?= (int)$dupId ?>">open that shipment</a>.
        <label class="optchip" style="margin-left:8px"><input type="checkbox" name="allow_duplicate" value="1">Import it again</label></div>
      <?php endif; ?>
      <div class="invs-head">
        <div class="field"><label>Shipment name</label><input name="head[name]" class="inp" maxlength="160" required value="<?= e($hv('name', pathinfo($imp['filename'], PATHINFO_FILENAME))) ?>"></div>
        <div class="field"><label>Supplier</label><input name="head[supplier]" class="inp" maxlength="160" value="<?= e($hv('supplier')) ?>"></div>
        <div class="field"><label>Reference</label><input name="head[reference]" class="inp" maxlength="80" value="<?= e($hv('reference')) ?>"></div>
        <div class="field"><label>Containers</label><input name="head[containers]" class="inp" maxlength="2000" value="<?= e($hv('containers', implode(' / ', $wb['containers']))) ?>"></div>
        <div class="field"><label>Expected</label>
          <button type="button" class="dp-btn inp" data-dp-target="invsExp" data-dp-placeholder="Pick a date"><?= $hv('expected_on') !== '' ? e(date('j M Y', strtotime($hv('expected_on')))) : 'Pick a date' ?></button>
          <input type="hidden" id="invsExp" name="head[expected_on]" value="<?= e($hv('expected_on')) ?>"></div>
        <div class="field"><label>Lands in</label>
          <?php if (!$targets): ?><p class="text-muted" style="margin:0;font-size:13px">No store you can use — the owner adds one in Locations.</p>
          <?php else: ?><select name="head[to_location_id]" class="eselect eselect--block">
            <?php foreach ($targets as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$hv('to_location_id', (string)$defaultTarget) === (int)$t['id'] ? 'selected' : '' ?>><?= e((string)$t['name']) ?></option><?php endforeach; ?>
          </select><?php endif; ?></div>
      </div>
    </div></div>

    <div class="card">
      <div class="card__head"><span class="card__title">Items</span>
        <span class="text-muted" style="font-size:12.5px">Lines with the same name are one item. Rename an item to change it for all its lines; “Split off as” moves one line to its own item.</span></div>
      <div class="table-wrap"><table class="data-table invs-table">
        <thead><tr><th>Item</th><th>Category</th><th>Kind</th><th>Unit</th><th class="inv-num">Qty</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $key => $g): $gid = inv_ship_gid((string)$key); $ex = $known[(string)$g['key']] ?? null;
              $clash = $ex && (($ex['tracking'] === 'serial') !== ($g['kind'] === 'serial')); ?>
          <tr class="invs-group">
            <td><input name="g[<?= $gid ?>][name]" class="inp" maxlength="160" value="<?= e((string)$g['name']) ?>" aria-label="Item name">
              <?php if ($ex): ?><span class="badge <?= $clash ? 'badge--red' : 'badge--green' ?>" style="margin-top:4px"><?= $clash
                ? 'Already exists, tracked by ' . ($ex['tracking'] === 'serial' ? 'serial number' : 'quantity') . ' — rename it or choose the same kind'
                : 'Existing item — stock is added to it' ?></span><?php endif; ?></td>
            <td><input name="g[<?= $gid ?>][category]" class="inp" maxlength="60" value="<?= e((string)$g['category']) ?>" aria-label="Category"></td>
            <td><select name="g[<?= $gid ?>][kind]" class="eselect" aria-label="Kind">
              <?php foreach (INV_SHIP_KINDS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $g['kind'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></td>
            <td><input name="g[<?= $gid ?>][unit]" class="inp invs-unit" maxlength="20" value="<?= e((string)$g['unit']) ?>" aria-label="Unit"></td>
            <td class="inv-num"><strong><?= (int)$g['qty'] ?></strong></td>
          </tr>
          <?php foreach ($g['lines'] as $i): $l = $wb['lines'][$i]; ?>
          <tr class="invs-line">
            <td colspan="4"><span class="inv-sub"><?= e($l['code']) ?> · <?= e($l['section'] !== '' ? $l['section'] : '—') ?><?= inv_ship_key($l['description']) !== inv_ship_key((string)$g['name']) ? ' · ' . e($l['description']) : '' ?></span>
              <?php if (count($g['lines']) > 1): ?><input name="split[<?= (int)$i ?>]" class="inp inp--sm invs-split" maxlength="160" placeholder="Split off as…" aria-label="Split this line off as a new item"><?php endif; ?></td>
            <td class="inv-num"><?= (int)$l['qty'] ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="invs-bar">
      <button type="submit" name="action" value="discard" class="btn-outline" formnovalidate>Discard</button>
      <button type="submit" name="action" value="regroup" class="btn-outline"><?= admin_icon('rotate', 16) ?> Update preview</button>
      <?php if ($targets): ?><button type="submit" name="action" value="confirm" class="btn-primary" data-confirm="Create this shipment and its items? Nothing goes into stock until it is received."><?= admin_icon('check', 16) ?> Create shipment</button><?php endif; ?>
    </div>
  </form>

<?php else: ?>
  <div class="inv-grid">
    <div class="card">
      <div class="card__head"><span class="card__title">Shipments</span></div>
      <?php if (!$list): ?>
        <?php dt_empty('No shipments yet — import a supplier list to start.'); ?>
      <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Shipment</th><th>Lands in</th><th class="inv-num">Received</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($list as $s): [$sl, $sc] = INV_SHIP_STATUS[$s['status']] ?? [$s['status'], 'badge--grey']; ?>
          <tr>
            <td><a href="/admin/inventory-shipment.php?id=<?= (int)$s['id'] ?>"><strong><?= e((string)$s['name']) ?></strong></a>
              <span class="inv-sub"><?= (int)$s['line_count'] ?> lines<?= $s['expected_on'] ? ' · expected ' . e(date('j M', strtotime((string)$s['expected_on']))) : '' ?><?= $s['supplier'] ? ' · ' . e((string)$s['supplier']) : '' ?></span></td>
            <td><?= e((string)$s['store_name']) ?></td>
            <td class="inv-num"><?= number_format((int)$s['pieces_good'] + (int)$s['pieces_damaged']) ?> / <?= number_format((int)$s['pieces_expected']) ?></td>
            <td><span class="badge <?= e($sc) ?>"><?= e($sl) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <div class="inv-stack">
      <div class="card">
        <div class="card__head"><span class="card__title">Import Excel</span></div>
        <div class="card__body" style="padding:16px 18px">
          <?php if (!$targets): ?>
            <p class="text-muted" style="margin:0;font-size:13px">There is no store you can receive into yet. The owner adds one in <a href="/admin/inventory-locations.php">Locations</a>.</p>
          <?php else: ?>
          <form method="POST" action="<?= $self ?>" enctype="multipart/form-data" class="inv-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="filefield">
              <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('download', 15) ?> Choose .xlsx<input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-file-input hidden required></label>
              <span class="filefield__name" data-file-name>No file</span>
            </div>
            <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Read the list</button>
          </form>
          <p class="text-muted" style="margin:12px 0 0;font-size:13px">The sheet needs “Item No”, “Qty” and “Description” columns. Bold rows are read as sections (Villas, Off-Duty…). You check everything before anything is saved.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invs-head{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.invs-table .inp{width:100%;min-width:0}
.invs-table td{vertical-align:top}
.invs-group td{border-top:2px solid var(--border)}
.invs-line td{padding-top:4px;padding-bottom:4px;border-top:0}
.invs-line td:first-child{padding-left:28px}
.invs-unit{max-width:90px}
.invs-split{margin-top:4px;max-width:260px}
.invs-bar{position:sticky;bottom:0;z-index:20;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;padding:12px 0;background:var(--bg)}
@media (max-width:768px){.invs-table thead{display:none}.invs-table tr{display:grid;grid-template-columns:minmax(0,1fr)}.invs-table td{display:block}}
</style>
<script>
(function () {
  document.querySelectorAll('[data-file-input]').forEach(function (fi) {
    fi.addEventListener('change', function () {
      var box = fi.closest('.filefield'), out = box ? box.querySelector('[data-file-name]') : null;
      if (out && fi.files && fi.files[0]) out.textContent = fi.files[0].name;
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

Notes for the implementer:
- `rotate` is an existing icon name. The "Lands in" default is the first store that is not Main stock (TD Main Stock).
- The Create button uses `data-confirm`; `admin/_layout_end.php`'s `armSubmit()` mirrors the clicked button's `name`/`value` into a hidden input before `form.submit()`, so `action=confirm` arrives.
- The preview posts ~4 fields per item + 1 per line (≈ 820 for the real file); PHP's default `max_input_vars` is 1000, and Task 10 raises it to 5000 in production.

- [ ] **Step 2: Lint**

Run: `php -l admin/inventory-shipments.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/inventory-shipments.php
git commit -m "feat(inventory): shipments list and Excel import with a merge-by-name preview

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: One shipment — lines by section, claims, CSV, received / cancel, photos

**Files:**
- Create: `admin/inventory-shipment.php`
- Create: `admin/inventory-shipment-file.php`

- [ ] **Step 1: The shipment page**

Create `admin/inventory-shipment.php`:

```php
<?php
/**
 * Admin: one shipment — progress, every line by section (expected / good / damaged
 * / short), the claims view (short or damaged lines only), CSV export, and Mark
 * received / Cancel. Owner + managers of the store's properties
 * (inv_ship_store_allowed()). Receiving itself is admin/inventory-receive.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-shipments.php';
require_login();
require_manager();

$vids = admin_venue_ids();
$id   = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
$s    = inv_shipment_fetch($id);
if (!$s || !inv_ship_store_allowed(inv_shipment_store_row($s), $vids)) {
    http_response_code(404);
    $pageTitle = 'Shipment'; $activeMenu = 'inventory_shipments';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Shipment not found. <a href="/admin/inventory-shipments.php">Back to Shipments</a></p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}
$self  = '/admin/inventory-shipment.php?id=' . $id;
$flash = $_SESSION['invs_flash'] ?? null; unset($_SESSION['invs_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'mark_received') {
            inv_shipment_mark_received($id);
            audit_log('inv.shipment_received', 'inv_shipment', $id, (string)$s['name']);
            $_SESSION['invs_flash'] = ['type' => 'success', 'msg' => 'Marked as received.'];
        } elseif ($act === 'cancel') {
            inv_shipment_cancel($id);
            audit_log('inv.shipment_cancel', 'inv_shipment', $id, (string)$s['name']);
            $_SESSION['invs_flash'] = ['type' => 'success', 'msg' => 'Shipment cancelled.'];
        }
    } catch (InvRefusal $e) {
        $_SESSION['invs_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$lines = inv_shipment_lines($id);

if (($_GET['export'] ?? '') === 'csv') {
    $fname = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$s['name']) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8 with a BOM
    foreach (inv_shipment_csv_rows($lines) as $row) fputcsv($out, $row, ',', '"', '');
    fclose($out);
    exit;
}

$claims  = ($_GET['view'] ?? '') === 'claims';
$shown   = $claims ? array_values(array_filter($lines, fn($l) => inv_ship_short($l) > 0 || (int)$l['qty_damaged'] > 0)) : $lines;
$bySec   = [];
foreach ($shown as $l) $bySec[(string)($l['section'] ?? '')][] = $l;
$short   = array_sum(array_map('inv_ship_short', $lines));
$damaged = (int)$s['pieces_damaged'];
[$stl, $stc] = INV_SHIP_STATUS[$s['status']] ?? [$s['status'], 'badge--grey'];
$open    = in_array($s['status'], ['expected', 'receiving', 'received'], true);

$pageTitle  = (string)$s['name'];
$activeMenu = 'inventory_shipments';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e((string)$s['name']) ?></h1>
  <a href="/admin/inventory-shipments.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Shipments</a>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:18px">
  <div class="inv-kpis">
    <div class="inv-kpi"><span>Status</span><span class="badge <?= e($stc) ?>"><?= e($stl) ?></span></div>
    <div class="inv-kpi"><span>Received</span><strong><?= number_format((int)$s['pieces_good']) ?> of <?= number_format((int)$s['pieces_expected']) ?> pieces</strong></div>
    <div class="inv-kpi"><span>Damaged</span><strong><?= number_format($damaged) ?></strong></div>
    <div class="inv-kpi"><span>Short</span><strong><?= number_format($short) ?></strong></div>
    <div class="inv-kpi"><span>Lands in</span><strong><?= e((string)$s['store_name']) ?></strong>
      <span class="inv-sub"><?= $s['containers'] ? e((string)$s['containers']) : '' ?><?= $s['supplier'] ? ' · ' . e((string)$s['supplier']) : '' ?><?= $s['expected_on'] ? ' · expected ' . e(date('j M Y', strtotime((string)$s['expected_on']))) : '' ?></span></div>
  </div>
  <div class="invs-actions">
    <?php if ($open): ?><a href="/admin/inventory-receive.php?shipment=<?= $id ?>" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Receive</a><?php endif; ?>
    <a href="/admin/inventory-location.php?id=<?= (int)$s['to_location_id'] ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-right', 15) ?> Hand out from <?= e((string)$s['store_name']) ?></a>
    <a href="<?= e($self) ?>&amp;export=csv" class="btn-outline btn-sm"><?= admin_icon('download', 15) ?> CSV</a>
    <?php if (in_array($s['status'], ['expected', 'receiving'], true) && (int)$s['pieces_good'] + $damaged > 0): ?>
    <form method="POST" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mark_received"><input type="hidden" name="shipment_id" value="<?= $id ?>">
      <button type="submit" class="btn-outline btn-sm" data-confirm="Mark this shipment as received? You can still correct lines later."><?= admin_icon('check-check', 15) ?> Mark received</button></form>
    <?php endif; ?>
    <?php if ($s['status'] === 'expected' && (int)$s['pieces_good'] + $damaged === 0): ?>
    <form method="POST" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="shipment_id" value="<?= $id ?>">
      <button type="submit" class="btn-outline btn-sm" data-confirm="Cancel this shipment? Its items stay in the catalogue."><?= admin_icon('x', 15) ?> Cancel</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="inv-chips" style="margin-bottom:12px">
  <a href="<?= e($self) ?>" class="optchip <?= $claims ? '' : 'is-on' ?>">All lines</a>
  <a href="<?= e($self) ?>&amp;view=claims" class="optchip <?= $claims ? 'is-on' : '' ?>">Short or damaged</a>
</div>

<?php if (!$shown): ?>
  <?php dt_empty($claims ? 'Nothing short or damaged.' : 'No lines.'); ?>
<?php else: foreach ($bySec as $sec => $rows): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card__head"><span class="card__title"><?= e($sec !== '' ? $sec : 'No section') ?></span><span class="text-muted" style="font-size:12.5px"><?= count($rows) ?> lines</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Code</th><th>Description</th><th class="inv-num">Expected</th><th class="inv-num">Good</th><th class="inv-num">Damaged</th><th class="inv-num">Short</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): $sh = inv_ship_short($l); ?>
      <tr>
        <td class="inv-nowrap"><?= e((string)$l['code']) ?></td>
        <td><a href="/admin/inventory-item.php?id=<?= (int)$l['item_id'] ?>"><?= e((string)$l['description']) ?></a>
          <?php if (inv_ship_key((string)$l['description']) !== inv_ship_key((string)$l['item_name'])): ?><span class="inv-sub">as <?= e((string)$l['item_name']) ?></span><?php endif; ?></td>
        <td class="inv-num"><?= (int)$l['qty_expected'] ?></td>
        <td class="inv-num"><?= (int)$l['qty_good'] ?></td>
        <td class="inv-num"><?= (int)$l['qty_damaged'] ? '<span class="badge badge--red">' . (int)$l['qty_damaged'] . '</span>' : '0' ?></td>
        <td class="inv-num"><?= $sh ? '<span class="badge badge--orange">' . $sh . '</span>' : '0' ?></td>
        <td><?= e((string)($l['note'] ?? '')) ?>
          <?php if ($l['photo_key']): ?> <a href="/admin/inventory-shipment-file.php?line=<?= (int)$l['id'] ?>" target="_blank" rel="noopener" class="btn-icon" data-tip="Photo" aria-label="Damage photo"><?= admin_icon('image', 15) ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; endif; ?>

<?= inv_shared_css() ?>
<style>
.invs-actions{display:flex;gap:8px;flex-wrap:wrap;padding:0 18px 16px}
.invs-actions form{margin:0}
a.optchip{text-decoration:none}
.optchip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
</style>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

The house `.optchip` (admin/assets/admin.css) shows "selected" only for a checked input inside it (`:has(input:checked)`); link chips here use `.is-on`, styled in the page's `<style>` with the same colours.

- [ ] **Step 2: The private photo endpoint**

Create `admin/inventory-shipment-file.php`:

```php
<?php
/**
 * Admin: stream one shipment line's damage photo. Private storage only — re-checks
 * on every view that the shipment's store is one the account works with
 * (inv_ship_store_allowed()), because the line id comes from the URL.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/hr-documents.php';        // hr_doc_read_bytes() — the private-file reader
require_once __DIR__ . '/../includes/inventory-shipments.php';
require_login();

$lineId = (int)($_GET['line'] ?? 0);
$line   = inv_shipments_supported() && $lineId > 0
    ? db_query('SELECT id, shipment_id, photo_key FROM inv_shipment_lines WHERE id = :l', [':l' => $lineId])->fetch() : false;
$s = $line ? inv_shipment_fetch((int)$line['shipment_id']) : false;
if (!$line || !$line['photo_key'] || !$s || !inv_ship_store_allowed(inv_shipment_store_row($s), admin_venue_ids())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Photo not found.');
}
$r = hr_doc_read_bytes((string)$line['photo_key']);
if (!$r['ok']) {
    http_response_code($r['status']);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The photo could not be read.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($r['data']) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($r['data']));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $r['data'];
```

- [ ] **Step 3: Lint**

Run: `php -l admin/inventory-shipment.php && php -l admin/inventory-shipment-file.php`
Expected: `No syntax errors detected` ×2.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory-shipment.php admin/inventory-shipment-file.php
git commit -m "feat(inventory): shipment page — lines by section, claims view, CSV, received/cancel, photos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: The phone receiving screen (+ "Deliveries to receive" for staff)

**Files:**
- Create: `admin/inventory-receive.php`
- Modify: `admin/inventory-count.php`

- [ ] **Step 1: The receiving screen**

Create `admin/inventory-receive.php`:

```php
<?php
/**
 * Receiving — the phone/tablet screen for unloading a shipment. One card per line:
 * expected, received so far, Good, Damaged, a note and a damage photo. Saving posts
 * each changed card's NEW TOTALS (so a retry never counts twice); the server moves
 * only the difference into the store (inv_shipment_receive()). Damaged units never
 * go on the shelf. Anyone whose properties the store serves may receive (owner,
 * managers, their staff). ?shipment=ID[&section=…] — a section keeps the page short.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/inventory-views.php';
require_once __DIR__ . '/../includes/inventory-shipments.php';
require_login();

$me   = current_admin();
$vids = admin_venue_ids();
$id   = (int)($_GET['shipment'] ?? $_POST['shipment_id'] ?? 0);
$s    = inv_shipment_fetch($id);
if (!$s || !inv_ship_store_allowed(inv_shipment_store_row($s), $vids)) {
    http_response_code(404);
    $pageTitle = 'Receive'; $activeMenu = 'inventory_count';
    include __DIR__ . '/_layout.php';
    echo '<p style="padding:32px;color:var(--muted)">Shipment not found.</p>';
    include __DIR__ . '/_layout_end.php';
    exit;
}
$section = trim((string)($_GET['section'] ?? $_POST['section'] ?? ''));   // '' = all lines, '__none__' = lines with no section
$self    = '/admin/inventory-receive.php?shipment=' . $id . ($section !== '' ? '&section=' . rawurlencode($section) : '');
$canCorrectReceived = is_owner() || is_manager();   // spec §5: a received shipment is corrected by a manager
$flash   = $_SESSION['invr_flash'] ?? null; unset($_SESSION['invr_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        // The sentinel is the LAST field: if PHP dropped fields (max_input_vars), refuse
        // rather than save half a page.
        if (($_POST['end'] ?? '') !== '1') throw new InvRefusal('Too much on one page to save — pick a section and save again.');
        if ($s['status'] === 'received' && !$canCorrectReceived) throw new InvRefusal('This delivery is marked received — ask a manager to correct it.');
        $posted = [];
        foreach (['good', 'damaged', 'note', 'over', 'base_good', 'base_damaged'] as $f) {
            foreach ((array)($_POST[$f] ?? []) as $lid => $v) $posted[(int)$lid][$f] = (string)$v;
        }
        foreach ((array)($_POST['serial'] ?? []) as $lid => $list) $posted[(int)$lid]['serials'] = array_map('strval', (array)$list);
        $r = $posted ? inv_shipment_receive($id, $posted, (int)$me['id']) : ['lines' => 0, 'received' => 0, 'corrected' => 0];

        // Damage photos — after the stock is saved; a failed upload never undoes a count.
        $photos = 0; $photoErr = '';
        foreach ((array)($_FILES['photo']['tmp_name'] ?? []) as $lid => $tmp) {
            if (($_FILES['photo']['error'][$lid] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmp)) continue;
            if ((int)($_FILES['photo']['size'][$lid] ?? 0) > 15 * 1024 * 1024) { $photoErr = 'A photo was larger than 15 MB and was skipped.'; continue; }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$tmp) ?: '';
            $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if ($ext === null) { $photoErr = 'Photos must be JPG, PNG or WEBP.'; continue; }
            $key = 'inventory/shipments/' . $id . '/' . (int)$lid . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!storage_put_private((string)$tmp, $key, $mime)) { $photoErr = 'A photo could not be stored.'; continue; }
            $old = inv_shipment_set_photo($id, (int)$lid, $key);
            if ($old) storage_delete_private($old);
            $photos++;
        }
        audit_log('inv.shipment_receive', 'inv_shipment', $id, "{$r['lines']} line(s), +{$r['received']}, −{$r['corrected']}, {$photos} photo(s)");
        $msg = $r['lines'] ? "Saved {$r['lines']} line" . ($r['lines'] === 1 ? '' : 's') . ($r['received'] ? " — {$r['received']} into {$s['store_name']}" : '') . '.' : 'Nothing changed.';
        if ($r['corrected']) $msg .= " {$r['corrected']} taken back out (correction).";
        if ($photos) $msg .= " {$photos} photo" . ($photos === 1 ? '' : 's') . ' saved.';
        $_SESSION['invr_flash'] = ['type' => $photoErr ? 'info' : 'success', 'msg' => trim($msg . ' ' . $photoErr), 'saved' => true];
    } catch (InvRefusal $e) {
        $_SESSION['invr_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }
    header('Location: ' . $self); exit;
}

$sections = inv_shipment_sections($id);
$lines    = inv_shipment_lines($id, $section === '' ? null : ($section === '__none__' ? '' : $section));
$closed   = $s['status'] === 'cancelled';
$draftKey = 'invr-draft-' . $id . '-' . md5($section);
$pageTitle  = 'Receive · ' . $s['name'];
$activeMenu = 'inventory_count';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><?= e((string)$s['name']) ?></h1>
  <?php if (is_owner() || is_manager()): ?><a href="/admin/inventory-shipment.php?id=<?= $id ?>" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Shipment</a><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!empty($flash['saved'])): ?><script>try{localStorage.removeItem(<?= json_encode($draftKey) ?>)}catch(e){}</script><?php endif; ?>

<p class="text-muted" style="margin:-4px 0 12px;font-size:13px">Into <strong><?= e((string)$s['store_name']) ?></strong> ·
  <?= number_format((int)$s['pieces_good']) ?> of <?= number_format((int)$s['pieces_expected']) ?> pieces received. Enter the running total for each line — damaged pieces are kept aside, not put on the shelf.</p>

<div class="inv-chips invr-sections">
  <a href="/admin/inventory-receive.php?shipment=<?= $id ?>" class="optchip <?= $section === '' ? 'is-on' : '' ?>">All</a>
  <?php foreach ($sections as $sec): $key = $sec['section'] === '' ? '__none__' : (string)$sec['section']; ?>
  <a href="/admin/inventory-receive.php?shipment=<?= $id ?>&amp;section=<?= e(rawurlencode($key)) ?>" class="optchip <?= $section === $key ? 'is-on' : '' ?>"><?= e($sec['section'] === '' ? 'No section' : (string)$sec['section']) ?> <span class="text-muted">(<?= (int)$sec['lines'] ?>)</span></a>
  <?php endforeach; ?>
</div>
<div class="inv-chips invr-filter" role="group" aria-label="Show">
  <label class="optchip"><input type="radio" name="invrShow" value="all" checked>All</label>
  <label class="optchip"><input type="radio" name="invrShow" value="todo">Not yet</label>
  <label class="optchip"><input type="radio" name="invrShow" value="short">Short</label>
  <label class="optchip"><input type="radio" name="invrShow" value="damaged">Damaged</label>
  <input type="search" class="inp inp--sm invr-search" placeholder="Search code or name" aria-label="Search">
</div>

<?php if ($closed): ?>
  <?php dt_empty('This shipment was cancelled.'); ?>
<?php elseif ($s['status'] === 'received' && !$canCorrectReceived): ?>
  <?php dt_empty('This delivery is marked received. Ask a manager if something needs correcting.'); ?>
<?php elseif (!$lines): ?>
  <?php dt_empty('No lines here.'); ?>
<?php else: ?>
<form method="POST" action="<?= e($self) ?>" id="invrForm" enctype="multipart/form-data" data-draft="<?= e($draftKey) ?>" novalidate>
  <?= csrf_field() ?><input type="hidden" name="shipment_id" value="<?= $id ?>"><input type="hidden" name="section" value="<?= e($section) ?>">
  <div class="invc-grid">
    <?php foreach ($lines as $l): $lid = (int)$l['id']; $exp = (int)$l['qty_expected']; $serial = $l['tracking'] === 'serial';
      $left = max(0, $exp - (int)$l['qty_good']); ?>
    <div class="invc-card invr-card" data-line="<?= $lid ?>" data-expected="<?= $exp ?>" data-good="<?= (int)$l['qty_good'] ?>" data-damaged="<?= (int)$l['qty_damaged'] ?>"
         data-search="<?= e(mb_strtolower($l['code'] . ' ' . $l['description'] . ' ' . $l['item_name'])) ?>">
      <!-- The totals this card was drawn from: the server refuses the save if someone else changed the line since (never a silent write-off). -->
      <input type="hidden" name="base_good[<?= $lid ?>]" value="<?= (int)$l['qty_good'] ?>"><input type="hidden" name="base_damaged[<?= $lid ?>]" value="<?= (int)$l['qty_damaged'] ?>">
      <div class="invc-card__top"><?= inv_thumb_html($l, 48) ?>
        <div><strong><?= e((string)$l['item_name']) ?></strong>
          <span class="inv-sub"><?= e((string)$l['code']) ?><?= $l['section'] ? ' · for ' . e((string)$l['section']) : '' ?> · expected <b><?= $exp ?></b> <?= e((string)$l['unit_label']) ?></span>
          <span class="inv-sub invr-sofar">So far: <?= (int)$l['qty_good'] ?> good<?= (int)$l['qty_damaged'] ? ', ' . (int)$l['qty_damaged'] . ' damaged' : '' ?></span></div></div>
      <div class="invr-row"><span class="invr-lbl">Good</span>
        <div class="invc-step">
          <button type="button" class="invc-btn" data-step="-1" data-for="good" aria-label="One less good">−</button>
          <input name="good[<?= $lid ?>]" type="number" inputmode="numeric" min="0" step="1" class="inp inp--num no-spin invc-inp" data-f="good" value="<?= (int)$l['qty_good'] ?>" aria-label="Good <?= e((string)$l['item_name']) ?>">
          <button type="button" class="invc-btn" data-step="1" data-for="good" aria-label="One more good">+</button>
          <button type="button" class="invc-same" data-all aria-label="All <?= $exp ?> arrived fine">= <?= $exp ?></button>
        </div></div>
      <div class="invr-row"><span class="invr-lbl">Damaged</span>
        <div class="invc-step invc-step--3">
          <button type="button" class="invc-btn" data-step="-1" data-for="damaged" aria-label="One less damaged">−</button>
          <input name="damaged[<?= $lid ?>]" type="number" inputmode="numeric" min="0" step="1" class="inp inp--num no-spin invc-inp" data-f="damaged" value="<?= (int)$l['qty_damaged'] ?>" aria-label="Damaged <?= e((string)$l['item_name']) ?>">
          <button type="button" class="invc-btn" data-step="1" data-for="damaged" aria-label="One more damaged">+</button>
        </div></div>
      <?php if ($serial && $left > 0): ?>
      <details class="invr-more"><summary>Serial numbers of the new pieces, in order (<?= min($left, 50) ?>)</summary>
        <?php for ($k = 0; $k < min($left, 50); $k++): ?><input name="serial[<?= $lid ?>][]" class="inp inp--sm" maxlength="80" placeholder="Serial <?= $k + 1 ?> (optional)" data-f="serial"><?php endfor; ?>
      </details>
      <?php endif; ?>
      <details class="invr-more" <?= $l['note'] ? 'open' : '' ?>><summary>Note / photo<?= $l['photo_key'] ? ' · photo saved' : '' ?></summary>
        <textarea name="note[<?= $lid ?>]" class="inp" rows="2" maxlength="1000" data-f="note" data-orig="<?= e((string)($l['note'] ?? '')) ?>" placeholder="What was wrong?"><?= e((string)($l['note'] ?? '')) ?></textarea>
        <label class="filefield"><span class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('image', 15) ?> Photo</span>
          <input type="file" name="photo[<?= $lid ?>]" accept="image/jpeg,image/png,image/webp" capture="environment" data-f="photo" hidden>
          <span class="filefield__name" data-file-name><?= $l['photo_key'] ? 'Replace the saved photo' : 'No photo' ?></span></label>
      </details>
      <label class="optchip invr-over" hidden><input type="checkbox" name="over[<?= $lid ?>]" value="1" data-f="over">More than ordered — I’m sure</label>
      <div class="invc-diff" aria-live="polite"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="end" value="1">
  <div class="invc-bar">
    <span id="invrProgress" class="text-muted"></span>
    <span id="invrAlert" role="status" aria-live="polite" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap"></span>
    <button type="submit" class="btn-primary"><?= admin_icon('check', 16) ?> Save</button>
  </div>
</form>
<?php endif; ?>

<?= inv_shared_css() ?>
<style>
.invc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding-bottom:96px}
.invc-card{background:var(--white);border:2px solid var(--border);border-radius:var(--radius);padding:14px;display:grid;gap:10px;min-width:0}
.invc-card.is-off{border-color:var(--orange, #d97706)}
.invc-card.is-ok{border-color:var(--green)}
.invc-card.is-bad{border-color:var(--red)}
.invc-card__top{display:flex;gap:12px;align-items:center;min-width:0}
.invc-card__top > div{min-width:0;overflow-wrap:anywhere}
.invc-step{display:grid;grid-template-columns:48px minmax(0,1fr) 48px auto;gap:8px;align-items:center}
.invc-step--3{grid-template-columns:48px minmax(0,1fr) 48px}
.invc-btn{height:48px;border-radius:10px;border:1px solid var(--border);background:var(--bg);font-size:24px;line-height:1;cursor:pointer}
.invc-inp{height:48px;font-size:22px;text-align:center;width:100%}
.invc-same{height:48px;padding:0 12px;border-radius:10px;border:1px solid var(--border);background:var(--white);cursor:pointer;font-weight:600;white-space:nowrap}
.invc-diff{font-size:13px;font-weight:600;min-height:1em}
.invc-bar{position:fixed;left:0;right:0;bottom:0;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom));background:var(--white);border-top:1px solid var(--border);box-shadow:var(--shadow)}
@media (min-width:769px){.invc-bar{left:var(--sidebar-w)}}
.invr-row{display:grid;gap:4px}
.invr-lbl{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.invr-more summary{cursor:pointer;font-size:13px;color:var(--muted)}
.invr-more[open]{display:grid;gap:6px}
.invr-sections,.invr-filter{margin-bottom:10px;flex-wrap:wrap}
.invr-search{max-width:220px}
.invr-card.is-hidden{display:none}
.invr-over[hidden]{display:none}   /* .optchip sets display, which would beat the hidden attribute */
a.optchip{text-decoration:none}
.optchip.is-on{background:var(--brand);border-color:var(--brand);color:#fff}
</style>
<script>
(function () {
  var form = document.getElementById('invrForm'); if (!form) return;
  var key = form.getAttribute('data-draft');
  var cards = Array.prototype.slice.call(form.querySelectorAll('.invr-card'));
  var pr = document.getElementById('invrProgress'), alertBox = document.getElementById('invrAlert');
  var draft = {};
  try { draft = JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch (e) { draft = {}; }
  function whole(v) { return /^\d+$/.test(v); }
  function num(c, f) { var v = c.querySelector('[data-f="' + f + '"]').value; return whole(v) ? parseInt(v, 10) : NaN; }
  function changed(c) {
    if (num(c, 'good') !== parseInt(c.getAttribute('data-good'), 10)) return true;
    if (num(c, 'damaged') !== parseInt(c.getAttribute('data-damaged'), 10)) return true;
    var n = c.querySelector('[data-f="note"]'); if (n && n.value.trim() !== n.getAttribute('data-orig').trim()) return true;
    var p = c.querySelector('[data-f="photo"]'); if (p && p.files && p.files.length) return true;
    return Array.prototype.some.call(c.querySelectorAll('[data-f="serial"]'), function (i) { return i.value.trim() !== ''; });
  }
  function paint(c) {
    var exp = parseInt(c.getAttribute('data-expected'), 10), g = num(c, 'good'), d = num(c, 'damaged'), out = c.querySelector('.invc-diff');
    var over = c.querySelector('.invr-over');
    c.classList.remove('is-off', 'is-ok', 'is-bad'); out.textContent = '';
    if (isNaN(g) || isNaN(d)) { c.classList.add('is-bad'); out.textContent = 'Whole numbers only'; over.hidden = true; return; }
    over.hidden = g + d <= exp;
    if (g + d > exp) { c.classList.add('is-bad'); out.textContent = (g + d - exp) + ' more than ordered'; }
    else if (g === exp) { c.classList.add('is-ok'); out.textContent = '✓ All arrived'; }
    else if (g + d === exp) { c.classList.add('is-off'); out.textContent = d + ' damaged'; }
    else if (g + d > 0) { c.classList.add('is-off'); out.textContent = (exp - g - d) + ' still missing' + (d ? ', ' + d + ' damaged' : ''); }
  }
  function progress() {
    var n = cards.filter(changed).length;
    pr.textContent = n ? n + ' line' + (n === 1 ? '' : 's') + ' to save' : 'Nothing changed yet';
  }
  function save() {
    var d = {};
    cards.forEach(function (c) {
      // Remember what the card was drawn from: a draft is only restored onto the same saved totals.
      d['base:' + c.getAttribute('data-line')] = c.getAttribute('data-good') + '|' + c.getAttribute('data-damaged');
      c.querySelectorAll('[data-f="good"],[data-f="damaged"],[data-f="note"]').forEach(function (i) { d[i.name] = i.value; });
    });
    try { localStorage.setItem(key, JSON.stringify(d)); } catch (e) {}
  }
  cards.forEach(function (c) {
    var fresh = draft['base:' + c.getAttribute('data-line')] === c.getAttribute('data-good') + '|' + c.getAttribute('data-damaged');
    c.querySelectorAll('[data-f="good"],[data-f="damaged"],[data-f="note"]').forEach(function (i) {
      if (fresh && draft[i.name] !== undefined) i.value = draft[i.name];   // a draft drawn from older totals is dropped
      i.addEventListener('input', function () { paint(c); progress(); save(); });
    });
    c.querySelectorAll('[data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = c.querySelector('[data-f="' + b.getAttribute('data-for') + '"]');
        var n = parseInt(i.value, 10) || 0;
        i.value = Math.max(0, n + parseInt(b.getAttribute('data-step'), 10));
        paint(c); progress(); save();
      });
    });
    var all = c.querySelector('[data-all]');
    if (all) all.addEventListener('click', function () {
      c.querySelector('[data-f="good"]').value = Math.max(0, parseInt(c.getAttribute('data-expected'), 10) - (num(c, 'damaged') || 0));
      paint(c); progress(); save();
    });
    var ph = c.querySelector('[data-f="photo"]');
    if (ph) ph.addEventListener('change', function () {
      var out = ph.closest('.filefield').querySelector('[data-file-name]');
      if (ph.files && ph.files[0]) out.textContent = ph.files[0].name;
      progress();
    });
    c.querySelectorAll('[data-f="serial"]').forEach(function (i) { i.addEventListener('input', progress); });
    paint(c);
  });
  progress();

  // Show filter + search (client-side only).
  var search = document.querySelector('.invr-search');
  function filter() {
    var mode = (document.querySelector('input[name="invrShow"]:checked') || {}).value || 'all';
    var q = search ? search.value.trim().toLowerCase() : '';
    cards.forEach(function (c) {
      var exp = parseInt(c.getAttribute('data-expected'), 10), g = num(c, 'good') || 0, d = num(c, 'damaged') || 0;
      var ok = mode === 'all' || (mode === 'todo' && g + d === 0) || (mode === 'short' && g + d < exp) || (mode === 'damaged' && d > 0);
      if (q && c.getAttribute('data-search').indexOf(q) === -1) ok = false;
      c.classList.toggle('is-hidden', !ok);
    });
  }
  document.querySelectorAll('input[name="invrShow"]').forEach(function (r) { r.addEventListener('change', filter); });
  if (search) search.addEventListener('input', filter);

  form.addEventListener('submit', function (ev) {
    var bad = cards.filter(function (c) { return c.classList.contains('is-bad') && !(c.querySelector('.invr-over input') || {}).checked; });
    if (bad.length) {
      ev.preventDefault();
      bad[0].classList.remove('is-hidden');
      bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
      var warn = bad.length + ' line' + (bad.length === 1 ? ' needs' : 's need') + ' checking';
      pr.textContent = warn; alertBox.textContent = ''; setTimeout(function () { alertBox.textContent = warn; }, 50);
      return;
    }
    var todo = cards.filter(changed);
    if (!todo.length) { ev.preventDefault(); pr.textContent = 'Nothing changed yet'; return; }
    // Post only the changed cards — keeps the request small (PHP drops fields past max_input_vars).
    cards.forEach(function (c) {
      if (todo.indexOf(c) !== -1) return;
      c.querySelectorAll('[name]').forEach(function (i) { i.disabled = true; });
    });
  });
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
```

- [ ] **Step 2: "Deliveries to receive" on the stock-count page**

In `admin/inventory-count.php`:

1. After `require_once __DIR__ . '/../includes/inventory-count-views.php';` add:
```php
require_once __DIR__ . '/../includes/inventory-shipments.php';   // deliveries to receive
```
2. After the line `$places = (!$sheet && !$loc && $supported) ? inv_countable_locations($meId, $role, $vids, $today) : [];` add:
```php
$deliveries = (!$sheet && !$loc && $supported) ? inv_shipments_open($vids) : [];
```
3. In the final `<?php else: ?>` branch (the list of places), insert at its very top — before `<?php if (!$places): ?>`:
```php
  <?php if ($deliveries): ?>
  <div class="card" style="margin-bottom:14px"><div class="card__head"><span class="card__title">Deliveries to receive</span></div>
    <div class="card__body" style="padding:0">
    <?php foreach ($deliveries as $d): ?>
    <a href="/admin/inventory-receive.php?shipment=<?= (int)$d['id'] ?>" class="invc-place">
      <span><strong><?= e((string)$d['name']) ?></strong>
        <span class="inv-sub">Into <?= e((string)$d['store_name']) ?> · <?= number_format((int)$d['pieces_good']) ?> of <?= number_format((int)$d['pieces_expected']) ?> pieces</span></span>
      <span class="badge <?= e(INV_SHIP_STATUS[$d['status']][1] ?? 'badge--grey') ?>"><?= e(INV_SHIP_STATUS[$d['status']][0] ?? $d['status']) ?></span>
    </a>
    <?php endforeach; ?>
  </div></div>
  <?php endif; ?>
```
4. So someone with only deliveries doesn't see "nothing to count" (and never an empty places card), replace

```php
  <?php if (!$places): ?>
    <?php dt_empty('There’s nothing for you to count.'); ?>
  <?php else: ?>
```

with

```php
  <?php if (!$places && !$deliveries): ?>
    <?php dt_empty('There’s nothing for you to count.'); ?>
  <?php elseif ($places): ?>
```

(the closing `<?php endif; ?>` after the places card stays as it is).

- [ ] **Step 3: Lint**

Run: `php -l admin/inventory-receive.php && php -l admin/inventory-count.php`
Expected: `No syntax errors detected` ×2.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory-receive.php admin/inventory-count.php
git commit -m "feat(inventory): phone receiving screen — good/damaged/note/photo as running totals

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Sidebar, input limit, docs, full check in the browser

**Files:**
- Modify: `admin/_layout.php`
- Modify: `Dockerfile`
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-09-28-inventory-shipments-design.md` (§2.1 as built)

- [ ] **Step 1: Sidebar link**

In `admin/_layout.php`, inside the Inventory group, after the Locations link and before the Counts link, add:

```php
        <?php if (function_exists('inv_shipments_supported') && inv_shipments_supported()): ?>
        <a href="/admin/inventory-shipments.php" class="sidebar__link <?= in_array($activeMenu ?? '', ['inventory_shipments'], true) ? 'is-active' : '' ?>">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          Shipments
        </a>
        <?php endif; ?>
```

(`inventory-support.php` — which defines `inv_shipments_supported()` — is already required by `_layout.php`; the `function_exists` guard only protects against an old cached include.)

- [ ] **Step 2: Raise PHP's input limit in production**

In `Dockerfile`, change
`RUN printf 'upload_max_filesize=16M\npost_max_size=40M\nmax_file_uploads=20\n' > /usr/local/etc/php/conf.d/tribalsand-uploads.ini`
to
`RUN printf 'upload_max_filesize=16M\npost_max_size=40M\nmax_file_uploads=20\nmax_input_vars=5000\n' > /usr/local/etc/php/conf.d/tribalsand-uploads.ini`

- [ ] **Step 3: CLAUDE.md**

In the `### Inventory & Assets` section, after the last bullet, add:

```markdown
- **Shipments** (migration `add_inventory_shipments.sql`, after `add_inventory.sql`; guard `inv_shipments_supported()`; logic `includes/inventory-shipments.php`, pure importer `includes/inventory-shipment-import.php`; test `php tests/inventory_shipments_logic.php`, fixture = the real Maya Ilai list). **Import never moves stock** — it creates items (matched by name) and lines. **Receiving posts running TOTALS** per line and `inv_shipment_receive()` writes only the difference (`receive` into the shipment's store, or a written-off "Receiving correction"), so a retry never double-counts; **damaged units stay on the line and never enter stock**. Moves carry `shipment_line_id`. Lock order extends the global one: `inv_shipments` → `inv_shipment_lines` (id order) → balances → assets. The importer reads every sheet with "Item No"/"Qty"/"Description" headers (packing lists with Length/Weight are skipped); **bold** description-only rows are sections, plain ones continue the line above (`xlsx_read_sheets()` returns the bold flag) — don't switch to position-only detection, "Studio Rooms" follows a line directly.
- **Several stores.** Main stock is the store with `is_main` (`inv_store_location_id()`); other stores carry an owning venue plus `share_venue_ids INT[]`. A location's **venue set** (`inv_location_venue_set()`) = owner ∪ shares; visibility, `inv_move_in_scope()` and `inv_ship_store_allowed()` use it, `inv_location_editable()` still follows the owning venue only. Adding a store and changing its owner/shares is owner-only (Locations page). Restock-to-par picks its source store (default: the store serving the property, else Main stock). Hidden (unpublished) properties get inventory locations too.
```

And add File Map rows after the inventory rows:

```markdown
| `includes/inventory-shipments.php` · `includes/inventory-shipment-import.php` | Shipments — create (no moves), receiving rounds as totals, status, reads · PURE Excel importer (sections by bold, merge by name, suggestions) |
| `admin/inventory-shipments.php` · `admin/inventory-shipment.php` · `admin/inventory-receive.php` | Shipments list + Import/preview · one shipment (claims, CSV) · the phone receiving screen |
```

- [ ] **Step 4: Bring the spec in line with what was built**

In `docs/superpowers/specs/2026-09-28-inventory-shipments-design.md` §2.1, replace the `inv_location_shares` table description with: shares are a column `inv_locations.share_venue_ids INT[] NOT NULL DEFAULT '{}'` (every `SELECT *` already carries it, so the pure scope functions stay pure); a store's name and count schedule stay editable by managers of the owning venue, while owner and shares are owner-only. Add to §6 "Hand out": Restock-to-par gains a source-store picker (default: the store serving the property, else Main stock). In §6 replace the last bullet (section hint on the store's stock page) with: the section hint ("for Off-Duty") shows on the shipment page and the receiving cards; showing it on the store's stock page is left for later.

- [ ] **Step 5: Run every suite**

```bash
php tests/inventory_shipments_logic.php && php tests/inventory_logic.php && php tests/inventory_views_logic.php && php tests/inventory_counts_logic.php && php tests/pos_logic.php; php tests/booking_import_logic.php | tail -3
```
Expected: `ALL PASS` for the five inventory/POS suites; booking import unchanged from before this branch.

- [ ] **Step 6: Browser check (controller does this, not a subagent)**

Temporarily point `.claude/launch.json` (in the MAIN folder — restore it afterwards) at this worktree, start the dev server with `-d max_input_vars=5000`, sign in via a local session cookie (admin_id=1), then:

1. Locations → **Add a store** "TD Main Stock", belongs to Tribal Dunes, also used by Maya Ilai + Off-Duty. Check the list shows "for Tribal Dunes, Maya Ilai, Off-Duty"; Off-Duty's property location exists.
2. Shipments → Import `tests/fixtures/shipment-maya-ilai.xlsx`. Preview: 199 lines · 154 items · 4,333 pieces · 11 sections; rename one group, split one line, Update preview, Create shipment (lands in TD Main Stock).
3. Receive at 375 px: Villas section, "= 8" on the couch, 1 damaged with a note, a photo; Save; the flash; the shipment page shows good/damaged/short; CSV downloads.
4. Maya Ilai property page → Restock to par offers TD Main Stock by default.
5. No horizontal scroll at 375 / 768 on the three new pages; no console errors.

Clean up the local test shipment/store afterwards only if the user asks (it is local data).

- [ ] **Step 7: Commit**

```bash
git add admin/_layout.php Dockerfile CLAUDE.md docs/superpowers/specs/2026-09-28-inventory-shipments-design.md
git commit -m "docs+nav(inventory): Shipments in the sidebar, max_input_vars, CLAUDE.md, spec as built

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Rollout (after merge)

1. Run `db/migrations/add_inventory_shipments.sql` on production via `/admin/migrate.php`.
2. Owner: Inventory → Locations → **Add a store**: "TD Main Stock", belongs to Tribal Dunes, also used by Maya Ilai + Off-Duty.
3. Inventory → Shipments → Import the Excel, check the preview, Create shipment.
4. On unloading day: open the shipment → **Receive** on a phone, section by section.
