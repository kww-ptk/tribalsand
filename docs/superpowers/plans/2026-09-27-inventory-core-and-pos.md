# Inventory Core + POS Switch-over — Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the one inventory database (items, locations, balances, append-only moves, serial units, counts) and move the POS's stock onto it, with the till unchanged.

**Architecture:** New `inv_*` tables (migration `add_inventory.sql`) with ONE write path, `inv_move()`, in `includes/inventory.php`. The POS keeps `pos_items` as its sale listing; a stock-tracked listing links to an `inv_items` row via `pos_items.inv_item_id`, and its shelf is the outlet's `inv_locations` row (`kind='outlet'`). The existing POS stock functions keep their signatures and become thin wrappers over `inv_move()` once `inv_supported()` is true.

**Tech Stack:** PHP 8.2 (vanilla), PostgreSQL via PDO (`db_query()`), plain-PHP test scripts (`php tests/*.php`).

**Spec:** `docs/superpowers/specs/2026-09-27-inventory-assets-design.md`
**Plan 2 (admin pages — central list, item page, locations, property inventory, count screen, discrepancy queue, employee card, My Work)** is written after this plan lands, against the real function signatures below.

---

## Conventions an engineer new to this repo must know

- Every SQL call goes through `db_query($sql, $params)` (`includes/db.php`) — prepared statements, named params. **pdo_pgsql does not allow the same named placeholder twice in one statement** — use `:a`, `:b`, … even for the same value.
- The app runs in **Africa/Nairobi**; the DB connection is set to that zone, so a `TIMESTAMPTZ` read back as a string (`'2026-09-20 18:30:00+03'`) starts with the Nairobi-local date.
- **Pre-migration safety:** every read/write surface first asks a guard (`inv_supported()`) that is an `information_schema`/`to_regclass` catalog lookup — never a failing `SELECT`, because in Postgres a failed statement aborts the whole enclosing transaction.
- **Transactions:** a helper opens a transaction only when none is open, otherwise uses a SAVEPOINT (tests wrap everything in one transaction they roll back). Today that helper is `pos_tx()`; this plan moves its body to `inv_tx()` and makes `pos_tx()` delegate.
- **Refusals vs errors:** a refusal the user should see is an exception type (`PosRefusal` today; `InvRefusal` added here). POS callers catch `PosRefusal`, so inventory refusals raised inside POS paths are converted to `PosRefusal` by `pos_inv()`.
- Tests are plain PHP: a `check(label, bool)` helper prints `PASS`/`FAIL`, a DB block runs inside one transaction that is rolled back, and the script SKIPs the DB block when the DB or migration is missing. Run: `php tests/inventory_logic.php`, `php tests/pos_logic.php`.
- Local DB = local Postgres (`.env` `DB_*`), not production. Apply migrations locally with `php bin/migrate.php <file>`.
- Work on branch `feat/inventory-assets` (already created; the spec is committed there). **Do not push** — a push to master auto-deploys.

## File map

| File | Status | Responsibility |
|---|---|---|
| `db/migrations/add_inventory.sql` | Create | Schema, default locations, carry-over of seeded POS stock |
| `includes/inventory-support.php` | Create | `inv_supported()`, `InvRefusal`, `inv_tx()` — light, no business logic |
| `includes/inventory.php` | Create | Pure rules, locations, items, `inv_move()`, serial units, compound actions, counts, reads |
| `tests/inventory_logic.php` | Create | Pure + rolled-back DB tests for the inventory core |
| `includes/pos.php` | Modify | `pos_tx` → `inv_tx`; `PosRefusal extends InvRefusal`; stock functions over inventory |
| `tests/pos_logic.php` | Modify | Stock assertions read the inventory ledger |
| `admin/pos-items.php` | Modify | Link tracked items to inventory; carry stock when an item changes outlet; show on-hand |
| `admin/pos-stock.php` | Modify | On-hand from inventory; history labels for inventory reasons |
| `docs/superpowers/specs/2026-09-27-inventory-assets-design.md` | Modify | Two simplifications (Task 1) |
| `CLAUDE.md` | Modify | New "Inventory & Assets" section |

---

### Task 1: Migration `add_inventory.sql`

**Files:**
- Create: `db/migrations/add_inventory.sql`
- Modify: `docs/superpowers/specs/2026-09-27-inventory-assets-design.md`

Two simplifications vs the spec, applied here and written back into the spec:
1. `inv_locations.venue_id` is the **owning venue for every kind** (area = its property's venue, outlet = the outlet's venue, person = the staff member's home venue, store = NULL/shared). There is no separate `owner_venue_id` column.
2. The outlet link is one-directional: `inv_locations.pos_outlet_id` (UNIQUE). There is no `pos_outlets.inv_location_id` — two columns for one link could disagree.

- [ ] **Step 1: Write the migration**

Create `db/migrations/add_inventory.sql`:

```sql
-- Inventory & Assets — ONE inventory database under POS, properties, staff and
-- central stock. Run via /admin/migrate.php AFTER add_pos_v2.sql (and after
-- add_hr_staff.sql). Idempotent.
-- Spec: docs/superpowers/specs/2026-09-27-inventory-assets-design.md
--
-- Load-bearing rules (see CLAUDE.md "Inventory & Assets"):
--   • inv_moves is the ONLY record of a quantity change; inv_balances is its cached
--     total, written by inv_move() in the same transaction. Moves are never
--     updated or deleted — a correction is a new move.
--   • from_location_id NULL = stock entering; to_location_id NULL = stock leaving.
--   • A move snapshots unit_value/value/currency, so a later price edit never
--     rewrites a loss or a report.
--   • inv_locations.venue_id is the OWNING venue of every location (NULL = shared,
--     e.g. Main stock). Accounting will map venues to companies through it.
--   • The POS no longer writes pos_items.stock_qty / pos_stock_moves once this
--     has run; a tracked listing points at inv_items via pos_items.inv_item_id and
--     its shelf is the outlet's inv_locations row.

-- ── Catalogue ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inv_items (
    id                SERIAL PRIMARY KEY,
    name              VARCHAR(160) NOT NULL,
    item_type         VARCHAR(12)  NOT NULL DEFAULT 'operational'
                      CHECK (item_type IN ('sellable','operational','employee','consignment','spare')),
    category          VARCHAR(60),
    sku               VARCHAR(60),
    image_key         TEXT,
    icon              VARCHAR(40),
    tracking          VARCHAR(6)   NOT NULL DEFAULT 'qty' CHECK (tracking IN ('qty','serial')),
    unit_label        VARCHAR(20)  NOT NULL DEFAULT 'pcs',
    replacement_value NUMERIC(12,2) CHECK (replacement_value IS NULL OR replacement_value >= 0),
    currency          CHAR(3)      NOT NULL DEFAULT 'KES',
    consignor_id      INT REFERENCES pos_consignors(id) ON DELETE SET NULL,
    low_stock_at      INT CHECK (low_stock_at IS NULL OR low_stock_at >= 0),
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_inv_items_type ON inv_items (item_type, is_active);

-- ── Locations (a tree) ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inv_locations (
    id                SERIAL PRIMARY KEY,
    parent_id         INT REFERENCES inv_locations(id) ON DELETE RESTRICT,
    kind              VARCHAR(10)  NOT NULL CHECK (kind IN ('store','property','area','outlet','person')),
    name              VARCHAR(120) NOT NULL,
    venue_id          INT REFERENCES venues(id) ON DELETE SET NULL,          -- OWNING venue; NULL = shared
    pos_outlet_id     INT UNIQUE REFERENCES pos_outlets(id) ON DELETE SET NULL,
    hr_staff_id       INT UNIQUE REFERENCES hr_staff(id) ON DELETE SET NULL,
    count_every_days  INT CHECK (count_every_days IS NULL OR count_every_days BETWEEN 1 AND 365),  -- NULL = manual only
    count_assignee_id INT REFERENCES admin_users(id) ON DELETE SET NULL,
    last_counted_at   TIMESTAMPTZ,
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order        INT          NOT NULL DEFAULT 0,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (kind <> 'area' OR parent_id IS NOT NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_locations_store    ON inv_locations (kind)     WHERE kind = 'store';
CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_locations_property ON inv_locations (venue_id) WHERE kind = 'property';
CREATE INDEX IF NOT EXISTS idx_inv_locations_parent ON inv_locations (parent_id);

-- ── Quantity per item per location (cached; written only by inv_move()) ─────
CREATE TABLE IF NOT EXISTS inv_balances (
    item_id     INT NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
    location_id INT NOT NULL REFERENCES inv_locations(id) ON DELETE RESTRICT,
    qty         INT NOT NULL DEFAULT 0,
    par_qty     INT CHECK (par_qty IS NULL OR par_qty >= 0),
    PRIMARY KEY (item_id, location_id)
);
CREATE INDEX IF NOT EXISTS idx_inv_balances_location ON inv_balances (location_id);

-- ── Serial-tracked units ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inv_assets (
    id             SERIAL PRIMARY KEY,
    item_id        INT NOT NULL REFERENCES inv_items(id),
    serial         VARCHAR(80),
    tag            VARCHAR(40),
    condition      VARCHAR(6)  NOT NULL DEFAULT 'good' CHECK (condition IN ('new','good','fair','poor')),
    status         VARCHAR(12) NOT NULL DEFAULT 'active' CHECK (status IN ('active','written_off','sold')),
    location_id    INT REFERENCES inv_locations(id),
    purchase_date  DATE,
    purchase_value NUMERIC(12,2) CHECK (purchase_value IS NULL OR purchase_value >= 0),
    notes          TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (status <> 'active' OR location_id IS NOT NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_assets_serial ON inv_assets (item_id, serial) WHERE serial IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_inv_assets_location ON inv_assets (location_id) WHERE status = 'active';

-- ── Counts (the check) ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inv_counts (
    id           SERIAL PRIMARY KEY,
    location_id  INT NOT NULL REFERENCES inv_locations(id),
    counted_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    started_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    submitted_at TIMESTAMPTZ,
    status       VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','submitted','resolved'))
);
CREATE INDEX IF NOT EXISTS idx_inv_counts_location ON inv_counts (location_id, started_at DESC);

CREATE TABLE IF NOT EXISTS inv_count_lines (
    id                 SERIAL PRIMARY KEY,
    count_id           INT NOT NULL REFERENCES inv_counts(id) ON DELETE CASCADE,
    item_id            INT NOT NULL REFERENCES inv_items(id),
    expected           INT NOT NULL,                                      -- snapshot at count start
    counted            INT CHECK (counted IS NULL OR counted >= 0),
    resolution         VARCHAR(10) CHECK (resolution IS NULL OR resolution IN ('missing','broken','stolen','found','recount','accepted')),
    resolved_by        INT REFERENCES admin_users(id) ON DELETE SET NULL,
    resolved_at        TIMESTAMPTZ,
    balance_at_resolve INT,
    UNIQUE (count_id, item_id)
);

-- ── The movement log — the ONLY way a quantity changes ──────────────────────
CREATE TABLE IF NOT EXISTS inv_moves (
    id               SERIAL PRIMARY KEY,
    item_id          INT NOT NULL REFERENCES inv_items(id),
    qty              INT NOT NULL CHECK (qty > 0),
    from_location_id INT REFERENCES inv_locations(id),
    to_location_id   INT REFERENCES inv_locations(id),
    reason           VARCHAR(12) NOT NULL CHECK (reason IN (
                         'receive','found','opening','void',                       -- in:   from NULL
                         'sale','broken','missing','stolen','written_off',         -- out:  to NULL
                         'transfer','assign','return','replaced')),                -- move: both
    unit_value       NUMERIC(12,2),
    value            NUMERIC(14,2),
    currency         CHAR(3) NOT NULL DEFAULT 'KES',
    asset_id         INT REFERENCES inv_assets(id),
    pos_sale_id      INT REFERENCES pos_sales(id) ON DELETE SET NULL,
    count_line_id    INT REFERENCES inv_count_lines(id) ON DELETE SET NULL,
    consignor_id     INT REFERENCES pos_consignors(id) ON DELETE SET NULL,  -- consignment terms of a delivery
    consign_pct      NUMERIC(5,2),
    consignor_cost   NUMERIC(10,2),
    note             TEXT,
    admin_user_id    INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (from_location_id IS NOT NULL OR to_location_id IS NOT NULL),
    CHECK (from_location_id IS DISTINCT FROM to_location_id)
);
CREATE INDEX IF NOT EXISTS idx_inv_moves_item     ON inv_moves (item_id, created_at);
CREATE INDEX IF NOT EXISTS idx_inv_moves_from     ON inv_moves (from_location_id);
CREATE INDEX IF NOT EXISTS idx_inv_moves_to       ON inv_moves (to_location_id);
CREATE INDEX IF NOT EXISTS idx_inv_moves_reason   ON inv_moves (reason, created_at);
CREATE INDEX IF NOT EXISTS idx_inv_moves_pos_sale ON inv_moves (pos_sale_id) WHERE pos_sale_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_inv_moves_asset    ON inv_moves (asset_id)    WHERE asset_id IS NOT NULL;

-- ── POS link ────────────────────────────────────────────────────────────────
ALTER TABLE pos_items ADD COLUMN IF NOT EXISTS inv_item_id INT REFERENCES inv_items(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_pos_items_inv_item ON pos_items (inv_item_id) WHERE inv_item_id IS NOT NULL;

-- ── Default locations ───────────────────────────────────────────────────────
INSERT INTO inv_locations (kind, name, sort_order)
SELECT 'store', 'Main stock', 0
 WHERE NOT EXISTS (SELECT 1 FROM inv_locations WHERE kind = 'store');

INSERT INTO inv_locations (kind, name, venue_id, sort_order)
SELECT 'property', v.name, v.id, 10 + v.sort_order
  FROM venues v
 WHERE v.is_published = TRUE
   AND NOT EXISTS (SELECT 1 FROM inv_locations l WHERE l.kind = 'property' AND l.venue_id = v.id);

INSERT INTO inv_locations (kind, name, pos_outlet_id, venue_id, sort_order)
SELECT 'outlet', o.name, o.id, o.venue_id, 100 + o.sort_order
  FROM pos_outlets o
 WHERE NOT EXISTS (SELECT 1 FROM inv_locations l WHERE l.pos_outlet_id = o.id);

-- ── Carry over seeded POS stock (the POS is not live; runs once per item) ───
DO $$
DECLARE
    r      RECORD;
    new_id INT;
    loc    INT;
BEGIN
    FOR r IN
        SELECT i.id, i.name, i.sku, i.image_key, i.consignor_id, i.low_stock_at, i.stock_qty, i.outlet_id, o.currency
          FROM pos_items i JOIN pos_outlets o ON o.id = i.outlet_id
         WHERE i.track_stock = TRUE AND i.inv_item_id IS NULL
         ORDER BY i.id
    LOOP
        INSERT INTO inv_items (name, item_type, category, sku, image_key, currency, consignor_id, low_stock_at)
        VALUES (r.name, CASE WHEN r.consignor_id IS NULL THEN 'sellable' ELSE 'consignment' END,
                'Retail', r.sku, r.image_key, r.currency, r.consignor_id, r.low_stock_at)
        RETURNING id INTO new_id;
        UPDATE pos_items SET inv_item_id = new_id WHERE id = r.id;
        SELECT id INTO loc FROM inv_locations WHERE pos_outlet_id = r.outlet_id;
        IF r.stock_qty > 0 THEN
            INSERT INTO inv_moves (item_id, qty, to_location_id, reason, currency, note)
            VALUES (new_id, r.stock_qty, loc, 'opening', r.currency, 'Carried over from POS stock');
        ELSIF r.stock_qty < 0 THEN
            -- An allow-negative item already oversold: keep balance == Σ moves.
            INSERT INTO inv_moves (item_id, qty, from_location_id, reason, currency, note)
            VALUES (new_id, -r.stock_qty, loc, 'sale', r.currency, 'Carried over negative POS stock');
        END IF;
        IF r.stock_qty <> 0 THEN
            INSERT INTO inv_balances (item_id, location_id, qty) VALUES (new_id, loc, r.stock_qty);
        END IF;
    END LOOP;
END $$;
```

- [ ] **Step 2: Apply it locally (twice — it must be idempotent)**

Run:
```bash
php bin/migrate.php db/migrations/add_inventory.sql && php bin/migrate.php db/migrations/add_inventory.sql
```
Expected: `→ add_inventory.sql ... OK` both times.

- [ ] **Step 3: Verify the default locations exist**

Run:
```bash
psql -d tribalsand -Atc "SELECT kind, COUNT(*) FROM inv_locations GROUP BY kind ORDER BY kind"
```
Expected: `property|7` and `store|1` (plus `outlet|N` if any POS outlets exist locally). Exactly one `store` row after two runs.

- [ ] **Step 4: Write the two simplifications back into the spec**

In `docs/superpowers/specs/2026-09-27-inventory-assets-design.md`:
- In the `inv_locations` column list replace `hr_staff_id → hr_staff, owner_venue_id → venues,` with `hr_staff_id → hr_staff,` and replace the bullet starting "`owner_venue_id` is the accounting seam" with:
  `- `venue_id` is the **owning venue of every location** and the accounting seam (§8): a property/area/outlet/person carries its venue (an area copies its property's, an outlet its outlet's, a person their home venue); a store carries NULL (shared).`
- In "POS links" replace the bullet `- `pos_outlets.inv_location_id → inv_locations`.` with `- The outlet link is one-directional: `inv_locations.pos_outlet_id` (UNIQUE).`
- In §8 replace "every location resolves to an owning venue" with "every location carries its owning venue (`inv_locations.venue_id`)".

- [ ] **Step 5: Commit**

```bash
git add db/migrations/add_inventory.sql docs/superpowers/specs/2026-09-27-inventory-assets-design.md
git commit -m "feat(inventory): add_inventory.sql — items, locations, balances, moves, serial units, counts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `inventory-support.php` — guard, refusal type, shared transaction helper

**Files:**
- Create: `includes/inventory-support.php`
- Modify: `includes/pos.php` (the `pos_tx()` function and the `PosRefusal` class, both near line 286–310)

- [ ] **Step 1: Create the support file**

Create `includes/inventory-support.php`:

```php
<?php
declare(strict_types=1);
/**
 * Inventory — pre-migration guard, the refusal type and the ONE transaction helper.
 * Kept apart from includes/inventory.php so light callers (the admin sidebar, the
 * POS) can ask "is inventory installed?" without loading the library.
 *
 * inv_supported() is a catalog lookup, never a failing SELECT: it runs inside
 * transactions, and in Postgres a failed statement aborts the whole transaction.
 */

require_once __DIR__ . '/db.php';

/** A refusal the caller shows to the user (not enough stock, closed location…). */
class InvRefusal extends RuntimeException {}

/** True once add_inventory.sql has run. */
function inv_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $ok = (bool) db_query("SELECT to_regclass('public.inv_moves') IS NOT NULL")->fetchColumn(); }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * Run $fn atomically. Opens a transaction when none is open; inside an existing
 * one (tests wrap everything in a rolled-back transaction, the POS sale wraps its
 * stock moves) it uses a SAVEPOINT, so a refusal discards its own partial writes
 * without aborting the caller's transaction. pos_tx() delegates here — there is
 * one savepoint counter for the whole request.
 */
function inv_tx(callable $fn): mixed {
    static $depth = 0;
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    $sp = 'inv_sp_' . (++$depth);
    $pdo->exec("SAVEPOINT {$sp}");
    try { $r = $fn(); $pdo->exec("RELEASE SAVEPOINT {$sp}"); $depth--; return $r; }
    catch (Throwable $e) {
        try { $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}"); $pdo->exec("RELEASE SAVEPOINT {$sp}"); } catch (Throwable $ignored) {}
        $depth--;
        throw $e;
    }
}
```

- [ ] **Step 2: Make `pos_tx()` delegate and `PosRefusal` extend `InvRefusal`**

In `includes/pos.php`, add to the `require_once` block at the top (after `require_once __DIR__ . '/pos-support.php';`):

```php
require_once __DIR__ . '/inventory-support.php';   // inv_supported(), InvRefusal, inv_tx()
```

Replace the whole `pos_tx()` function (the doc comment stays) with:

```php
function pos_tx(callable $fn): mixed {
    return inv_tx($fn);
}
```

Replace

```php
final class PosRefusal extends RuntimeException {}
```

with

```php
final class PosRefusal extends InvRefusal {}
```

- [ ] **Step 3: Run the POS tests — nothing may change**

Run: `php tests/pos_logic.php | tail -3`
Expected: `ALL PASS` (same 196 checks).

- [ ] **Step 4: Commit**

```bash
git add includes/inventory-support.php includes/pos.php
git commit -m "feat(inventory): inv_supported(), InvRefusal and the shared inv_tx(); pos_tx delegates

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Pure rules — move shape, scope, counts, due dates, money, restock plan

**Files:**
- Create: `includes/inventory.php`
- Create: `tests/inventory_logic.php`

- [ ] **Step 1: Write the failing test file (pure section + an empty DB scaffold)**

Create `tests/inventory_logic.php`:

```php
<?php
declare(strict_types=1);
// Inventory core. Run: php tests/inventory_logic.php
// Pure rules always run. The DB block runs inside ONE transaction that is rolled
// back, and SKIPs when no database is reachable or add_inventory.sql is missing.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/inventory.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Move shape ──────────────────────────────────────────────────────────────
$base = ['item_id' => 1, 'qty' => 2, 'from' => null, 'to' => null, 'asset_id' => null, 'unit_value' => null];
check('move: receive into a location is valid', inv_move_error(['reason' => 'receive', 'to' => 5] + $base) === null);
check('move: receive with a source is refused', inv_move_error(['reason' => 'receive', 'from' => 4, 'to' => 5] + $base) !== null);
check('move: sale leaves (source, no destination)', inv_move_error(['reason' => 'sale', 'from' => 4] + $base) === null
    && inv_move_error(['reason' => 'sale', 'from' => 4, 'to' => 5] + $base) !== null);
check('move: every loss reason leaves a location',
    count(array_filter(INV_LOSS_REASONS, fn($r) => inv_move_error(['reason' => $r, 'from' => 4] + $base) === null)) === count(INV_LOSS_REASONS));
check('move: transfer needs both ends', inv_move_error(['reason' => 'transfer', 'from' => 4] + $base) !== null);
check('move: transfer to the same place refused', inv_move_error(['reason' => 'transfer', 'from' => 4, 'to' => 4] + $base) !== null);
check('move: transfer between two places is valid', inv_move_error(['reason' => 'transfer', 'from' => 4, 'to' => 5] + $base) === null);
check('move: unknown reason refused', inv_move_error(['reason' => 'teleport', 'to' => 5] + $base) !== null);
check('move: qty 0 refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'qty' => 0] + $base) !== null);
check('move: qty over the max refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'qty' => INV_MAX_QTY + 1] + $base) !== null);
check('move: no item refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'item_id' => 0] + $base) !== null);
check('move: a serial unit moves one at a time', inv_move_error(['reason' => 'receive', 'to' => 5, 'asset_id' => 9] + $base) !== null
    && inv_move_error(['reason' => 'receive', 'to' => 5, 'asset_id' => 9, 'qty' => 1] + $base) === null);
check('move: negative value refused', inv_move_error(['reason' => 'receive', 'to' => 5, 'unit_value' => -1.0] + $base) !== null);

$n = inv_normalize_move(['item_id' => '7', 'qty' => '3', 'from' => '0', 'to' => '12', 'reason' => 'receive', 'unit_value' => '4.556']);
check('normalize: strings → ints, 0 → null, value rounded to the cent',
    $n['item_id'] === 7 && $n['qty'] === 3 && $n['from'] === null && $n['to'] === 12 && $n['unit_value'] === 4.56);
check('normalize: a negative qty becomes 0 (and is refused)', inv_normalize_move(['qty' => '-2'])['qty'] === 0);

check('reason: store → property is a transfer', inv_transfer_reason('store', 'property') === 'transfer');
check('reason: anything → person is an assignment', inv_transfer_reason('store', 'person') === 'assign');
check('reason: person → store is a return', inv_transfer_reason('person', 'store') === 'return');

check('shortfall: none left', inv_shortfall_message('Plates', 0, 'My Amani') === 'No Plates left at My Amani.');
check('shortfall: some left', inv_shortfall_message('Plates', 3, 'My Amani') === 'Only 3 × Plates at My Amani.');

// ── Scope (who may move what) ───────────────────────────────────────────────
$main = ['venue_id' => null]; $amani = ['venue_id' => 1]; $zuri = ['venue_id' => 3];
check('scope: owner moves anything', inv_move_in_scope($amani, $zuri, null));
check('scope: manager restocks own property from Main stock', inv_move_in_scope($main, $amani, [1]));
check('scope: manager returns to Main stock', inv_move_in_scope($amani, $main, [1]));
check('scope: manager cannot move into another property', !inv_move_in_scope($amani, $zuri, [1]));
check('scope: manager cannot move out of another property', !inv_move_in_scope($zuri, $main, [1]));
check('scope: manager cannot shuffle shared stock only', !inv_move_in_scope($main, ['venue_id' => null], [1]));
check('scope: manager writes off at own property', inv_move_in_scope($amani, null, [1]));
check('scope: an account with no properties does nothing', !inv_move_in_scope($main, $amani, []));

// ── Count resolutions ───────────────────────────────────────────────────────
check('count: short line → missing move of the gap', inv_resolution_move('missing', 12, 11) === ['reason' => 'missing', 'qty' => 1, 'dir' => 'out']);
check('count: broken also leaves', inv_resolution_move('broken', 20, 17) === ['reason' => 'broken', 'qty' => 3, 'dir' => 'out']);
check('count: extra line → found', inv_resolution_move('found', 5, 7) === ['reason' => 'found', 'qty' => 2, 'dir' => 'in']);
check('count: found on a short line refused', is_string(inv_resolution_move('found', 12, 11)));
check('count: missing on an extra line refused', is_string(inv_resolution_move('missing', 5, 7)));
check('count: recount never moves stock', inv_resolution_move('recount', 12, 11) === null);
check('count: accepted only when it matches', inv_resolution_move('accepted', 4, 4) === null && is_string(inv_resolution_move('accepted', 4, 3)));
check('count: unknown resolution refused', is_string(inv_resolution_move('lost-at-sea', 4, 3)));

// ── Count schedule (Nairobi-local dates) ────────────────────────────────────
check('due: manual-only location', inv_count_status(null, null, '2026-09-27') === 'manual');
check('due: scheduled but never counted is due', inv_count_status(null, 7, '2026-09-27') === 'due');
check('due: weekly, counted 20 Sep → due 27 Sep',
    inv_count_due_ymd('2026-09-20 18:30:00+03', 7) === '2026-09-27' && inv_count_status('2026-09-20 18:30:00+03', 7, '2026-09-27') === 'due');
check('due: before the due day is ok', inv_count_status('2026-09-20 09:00:00+03', 7, '2026-09-26') === 'ok');
check('due: after the due day is overdue', inv_count_status('2026-09-20 09:00:00+03', 7, '2026-09-28') === 'overdue');
check('due: daily', inv_count_due_ymd('2026-09-27 07:00:00+03', 1) === '2026-09-28');

// ── Money never crosses currencies ──────────────────────────────────────────
$tot = inv_sum_by_currency([
    ['value' => '100.50', 'currency' => 'KES'], ['value' => 20, 'currency' => 'usd'],
    ['value' => null, 'currency' => 'KES'],     ['value' => '49.50', 'currency' => 'KES'],
]);
check('money: summed per currency, never across', $tot === ['KES' => 150.0, 'USD' => 20.0]);

// ── Restock plan ────────────────────────────────────────────────────────────
$plan = inv_restock_plan([
    ['item_id' => 3, 'qty' => 17, 'par_qty' => 20], ['item_id' => 1, 'qty' => 25, 'par_qty' => 20],
    ['item_id' => 2, 'qty' => -2, 'par_qty' => 6],  ['item_id' => 4, 'qty' => 0,  'par_qty' => null],
]);
check('restock: tops up to par; ignores over-par and no-par rows; negative counts as 0', $plan === [2 => 6, 3 => 3]);

// ── DB-backed ───────────────────────────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!inv_supported()) {
    echo "\nSKIP  DB block (add_inventory.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx    = substr(bin2hex(random_bytes(4)), 0, 8);
    $ins    = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $count  = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();
    // Σ moves into a location − Σ moves out of it: must always equal the cached balance.
    $ledger = fn(int $item, int $loc) => (int) db_query(
        'SELECT COALESCE(SUM(CASE WHEN to_location_id = :a THEN qty ELSE -qty END), 0)
           FROM inv_moves WHERE item_id = :i AND (to_location_id = :b OR from_location_id = :c)',
        [':a' => $loc, ':i' => $item, ':b' => $loc, ':c' => $loc])->fetchColumn();

    // ── DB checks (tasks 4–8 insert their blocks above this line) ──
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: fatal error — `Failed opening required '.../includes/inventory.php'`.

- [ ] **Step 3: Create `includes/inventory.php` with the pure rules**

```php
<?php
declare(strict_types=1);
/**
 * Inventory & Assets — ONE inventory database under the POS, the properties, the
 * staff and central stock. Spec: docs/superpowers/specs/2026-09-27-inventory-assets-design.md
 * Migration: db/migrations/add_inventory.sql. Test: php tests/inventory_logic.php
 *
 * Load-bearing rules:
 *   • inv_move() is the ONLY writer of inv_moves and inv_balances. It locks the
 *     balance rows in location-id order, refuses going below zero (except a POS
 *     listing flagged allow_negative), snapshots value, and updates both balances
 *     in one transaction. Compound actions (replace, restock, count resolution)
 *     are several inv_move() calls inside ONE inv_tx().
 *   • Counting never moves stock. A manager's resolution does, and only while the
 *     live balance still equals what the counter was shown.
 *   • Money is never summed across currencies (inv_sum_by_currency()).
 *   • Every surface checks inv_supported() first (catalog lookup — safe in a tx).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory-support.php';   // inv_supported(), InvRefusal, inv_tx()

const INV_TYPES = [
    'sellable'    => 'Sellable product',
    'operational' => 'Property asset',
    'employee'    => 'Employee asset',
    'consignment' => 'Consignment product',
    'spare'       => 'Stock / spare',
];
const INV_LOCATION_KINDS = ['store' => 'Main stock', 'property' => 'Property', 'area' => 'Area', 'outlet' => 'Outlet', 'person' => 'Team member'];
const INV_REASONS_IN     = ['receive', 'found', 'opening', 'void'];                   // from = NULL
const INV_REASONS_OUT    = ['sale', 'broken', 'missing', 'stolen', 'written_off'];    // to   = NULL
const INV_REASONS_MOVE   = ['transfer', 'assign', 'return', 'replaced'];              // both ends
const INV_LOSS_REASONS   = ['broken', 'missing', 'stolen', 'written_off'];
const INV_RESOLUTIONS    = ['missing', 'broken', 'stolen', 'found', 'recount', 'accepted'];
const INV_CONDITIONS     = ['new', 'good', 'fair', 'poor'];
const INV_MAX_QTY        = 100000;
const INV_DEFAULT_CURRENCY = 'KES';

// ── Pure rules ──────────────────────────────────────────────────────────────

/** Postgres/PDO boolean → PHP bool ('t', true, 1, '1', 'true'). */
function inv_bool(mixed $v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/** A move request with ids as int|null (0/'' → null), qty as int (bad → 0), value rounded. */
function inv_normalize_move(array $m): array {
    $id  = fn($v) => ($v === null || $v === '' || !is_numeric($v) || (int)$v <= 0) ? null : (int)$v;
    $qty = $m['qty'] ?? 0;
    $qty = is_int($qty) ? $qty : (ctype_digit((string)$qty) ? (int)$qty : 0);
    $uv  = $m['unit_value'] ?? null;
    return [
        'item_id'        => (int)($id($m['item_id'] ?? null) ?? 0),
        'qty'            => $qty,
        'from'           => $id($m['from'] ?? null),
        'to'             => $id($m['to'] ?? null),
        'reason'         => (string)($m['reason'] ?? ''),
        'user_id'        => $id($m['user_id'] ?? null),
        'note'           => mb_substr(trim((string)($m['note'] ?? '')), 0, 500),
        'asset_id'       => $id($m['asset_id'] ?? null),
        'pos_sale_id'    => $id($m['pos_sale_id'] ?? null),
        'count_line_id'  => $id($m['count_line_id'] ?? null),
        'unit_value'     => ($uv !== null && $uv !== '' && is_numeric($uv)) ? round((float)$uv, 2) : null,
        'terms'          => (array)($m['terms'] ?? []),
        'allow_negative' => !empty($m['allow_negative']),
    ];
}

/** Why a (normalized) move is malformed, or null — PURE. Stock levels are checked later, under lock. */
function inv_move_error(array $m): ?string {
    if ((int)($m['item_id'] ?? 0) <= 0) return 'Pick an item.';
    $qty = $m['qty'] ?? 0;
    if (!is_int($qty) || $qty < 1) return 'Enter a quantity of at least 1.';
    if ($qty > INV_MAX_QTY) return 'That quantity is too large.';
    $from = $m['from'] ?? null;
    $to   = $m['to'] ?? null;
    $r    = (string)($m['reason'] ?? '');
    if (in_array($r, INV_REASONS_IN, true)) {
        if ($from !== null || $to === null) return 'Stock coming in needs a destination and no source.';
    } elseif (in_array($r, INV_REASONS_OUT, true)) {
        if ($from === null || $to !== null) return 'Stock leaving needs a source and no destination.';
    } elseif (in_array($r, INV_REASONS_MOVE, true)) {
        if ($from === null || $to === null) return 'Pick where it comes from and where it goes.';
        if ($from === $to) return 'Pick two different locations.';
    } else {
        return 'Unknown movement type.';
    }
    if (($m['asset_id'] ?? null) !== null && $qty !== 1) return 'A serial-tracked unit moves one at a time.';
    if (($m['unit_value'] ?? null) !== null && $m['unit_value'] < 0) return 'Value cannot be negative.';
    return null;
}

/** The reason a location-to-location move is recorded under — PURE. */
function inv_transfer_reason(string $fromKind, string $toKind): string {
    if ($toKind === 'person') return 'assign';
    if ($fromKind === 'person') return 'return';
    return 'transfer';
}

/** The refusal shown when a location does not hold enough — PURE. */
function inv_shortfall_message(string $name, int $have, string $where): string {
    return $have <= 0 ? "No {$name} left at {$where}." : "Only {$have} × {$name} at {$where}.";
}

/**
 * May an account with $venueIds (null = owner, all) make a move between these
 * two location rows (either may be null for in/out moves)? — PURE.
 * Shared locations (venue_id NULL: Main stock, venue-less outlets) are open to a
 * manager only as the OTHER end of a move into/out of one of their own
 * properties; every non-shared end must be theirs.
 */
function inv_move_in_scope(?array $from, ?array $to, ?array $venueIds): bool {
    if ($venueIds === null) return true;
    $owned = false;
    foreach ([$from, $to] as $loc) {
        if ($loc === null) continue;
        $v = isset($loc['venue_id']) && $loc['venue_id'] !== null ? (int)$loc['venue_id'] : null;
        if ($v === null) continue;
        if (!in_array($v, array_map('intval', $venueIds), true)) return false;
        $owned = true;
    }
    return $owned;
}

/**
 * The move a count resolution implies — PURE.
 * Returns ['reason','qty','dir'=>'in'|'out'], null (no move), or an error string.
 */
function inv_resolution_move(string $resolution, int $expected, int $counted): array|string|null {
    if (!in_array($resolution, INV_RESOLUTIONS, true)) return 'Pick what happened.';
    $gap = $counted - $expected;
    if ($resolution === 'recount') return null;
    if ($resolution === 'accepted') return $gap === 0 ? null : 'A gap cannot be accepted as it is — pick what happened, or recount.';
    if ($resolution === 'found') return $gap > 0 ? ['reason' => 'found', 'qty' => $gap, 'dir' => 'in'] : '"Found" only applies when more were counted than expected.';
    return $gap < 0 ? ['reason' => $resolution, 'qty' => -$gap, 'dir' => 'out'] : 'Nothing is short on this line.';
}

/** Nairobi-local date the next count is due, or null (manual only / never counted) — PURE. */
function inv_count_due_ymd(?string $lastCountedAt, ?int $everyDays): ?string {
    if (!$everyDays || $everyDays < 1 || !$lastCountedAt) return null;
    $d = DateTime::createFromFormat('!Y-m-d', substr($lastCountedAt, 0, 10));
    return $d ? $d->modify("+{$everyDays} days")->format('Y-m-d') : null;
}

/** 'manual' | 'ok' | 'due' | 'overdue' for a location's count schedule — PURE. */
function inv_count_status(?string $lastCountedAt, ?int $everyDays, string $todayYmd): string {
    if (!$everyDays) return 'manual';
    if (!$lastCountedAt) return 'due';
    $due = inv_count_due_ymd($lastCountedAt, $everyDays);
    if ($due === null) return 'due';
    return $todayYmd > $due ? 'overdue' : ($todayYmd === $due ? 'due' : 'ok');
}

/** Sum a value column per currency (never across) — PURE. ['KES' => 150.0, 'USD' => 20.0] */
function inv_sum_by_currency(array $rows, string $valueKey = 'value', string $currencyKey = 'currency'): array {
    $out = [];
    foreach ($rows as $r) {
        $v = $r[$valueKey] ?? null;
        if ($v === null || $v === '') continue;
        $c = strtoupper((string)($r[$currencyKey] ?? INV_DEFAULT_CURRENCY));
        $out[$c] = round(($out[$c] ?? 0) + (float)$v, 2);
    }
    ksort($out);
    return $out;
}

/** [item_id => qty needed to reach par] for balance rows (item_id, qty, par_qty) — PURE. */
function inv_restock_plan(array $rows): array {
    $plan = [];
    foreach ($rows as $r) {
        if (!isset($r['par_qty']) || $r['par_qty'] === null || $r['par_qty'] === '') continue;
        $need = (int)$r['par_qty'] - max(0, (int)$r['qty']);
        if ($need > 0) $plan[(int)$r['item_id']] = $need;
    }
    ksort($plan);
    return $plan;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/inventory_logic.php`
Expected: every pure check `PASS`, then `ALL PASS` (the DB block runs but has no checks yet).

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): pure rules — move shape, scope, count resolutions, due dates, money, restock plan

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Locations and items

**Files:**
- Modify: `includes/inventory.php` (append a section)
- Modify: `tests/inventory_logic.php` (insert a block above the `// ── DB checks …` marker)

- [ ] **Step 1: Write the failing DB checks**

In `tests/inventory_logic.php`, insert above the line `    // ── DB checks (tasks 4–8 insert their blocks above this line) ──`:

```php
    // ── Locations + items ──
    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Inv A')", [':s' => "zz-inv-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Inv B')", [':s' => "zz-inv-b-{$sfx}"]);
    $store = inv_store_location_id();
    check('locations: Main stock exists exactly once',
        $store > 0 && inv_store_location_id() === $store && $count("SELECT COUNT(*) FROM inv_locations WHERE kind = 'store'") === 1);
    $locA = inv_property_location_id($vA);
    check('locations: one property location per venue', $locA > 0 && inv_property_location_id($vA) === $locA);
    $la = inv_fetch_location($locA);
    check('locations: a property carries its venue', $la && (int)$la['venue_id'] === $vA && $la['kind'] === 'property');
    $locB = inv_property_location_id($vB);
    $outlet = $ins("INSERT INTO pos_outlets (name, slug, kind, venue_id, currency) VALUES ('ZZ Inv Shop', :s, 'shop', :v, 'KES')",
        [':s' => "zz-inv-shop-{$sfx}", ':v' => $vA]);
    $locShop = inv_outlet_location_id($outlet);
    check('locations: an outlet location is tied to the outlet and its venue',
        $locShop === inv_outlet_location_id($outlet) && (int)inv_fetch_location($locShop)['venue_id'] === $vA);
    $staff = $ins("INSERT INTO hr_staff (full_name, venue_id) VALUES ('ZZ Jane Wanjiru', :v)", [':v' => $vA]);
    $locJane = inv_person_location_id($staff);
    check('locations: a person location is created on demand, once',
        $locJane === inv_person_location_id($staff) && inv_fetch_location($locJane)['kind'] === 'person');
    $plates = inv_create_item(['name' => 'ZZ Dinner plate', 'item_type' => 'operational', 'category' => 'Kitchen', 'replacement_value' => '850', 'currency' => 'KES']);
    $laptop = inv_create_item(['name' => 'ZZ Laptop', 'item_type' => 'employee', 'tracking' => 'serial', 'replacement_value' => 95000]);
    $p = inv_fetch_item($plates);
    check('items: created with type, value and qty tracking',
        $p && $p['item_type'] === 'operational' && (float)$p['replacement_value'] === 850.0 && $p['tracking'] === 'qty' && $p['currency'] === 'KES');
    check('items: a serial item', inv_fetch_item($laptop)['tracking'] === 'serial');
    $threw = false; try { inv_create_item(['name' => '  ', 'item_type' => 'operational']); } catch (InvRefusal $e) { $threw = true; }
    check('items: a name is required', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'gadget']); } catch (InvRefusal $e) { $threw = true; }
    check('items: an unknown type is refused', $threw);
    $threw = false; try { inv_create_item(['name' => 'X', 'item_type' => 'spare', 'replacement_value' => '-3']); } catch (InvRefusal $e) { $threw = true; }
    check('items: a negative value is refused', $threw);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_store_location_id()`.

- [ ] **Step 3: Append the locations/items section to `includes/inventory.php`**

```php

// ── Reads ───────────────────────────────────────────────────────────────────

function inv_fetch_item(int $id): array|false {
    if (!inv_supported() || $id <= 0) return false;
    return db_query('SELECT * FROM inv_items WHERE id = :id', [':id' => $id])->fetch();
}

function inv_fetch_location(int $id): array|false {
    if (!inv_supported() || $id <= 0) return false;
    return db_query('SELECT * FROM inv_locations WHERE id = :id', [':id' => $id])->fetch();
}

/** Current quantity of an item at a location (0 when there is no balance row). */
function inv_balance(int $itemId, int $locationId): int {
    if (!inv_supported()) return 0;
    $q = db_query('SELECT qty FROM inv_balances WHERE item_id = :i AND location_id = :l', [':i' => $itemId, ':l' => $locationId])->fetchColumn();
    return $q === false ? 0 : (int)$q;
}

/** Lock (creating if needed) an item's balance row at a location; returns its qty. Call inside inv_tx(). */
function inv_balance_lock(int $itemId, int $locationId): int {
    db_query('INSERT INTO inv_balances (item_id, location_id, qty) VALUES (:i, :l, 0) ON CONFLICT (item_id, location_id) DO NOTHING',
        [':i' => $itemId, ':l' => $locationId]);
    return (int) db_query('SELECT qty FROM inv_balances WHERE item_id = :i AND location_id = :l FOR UPDATE',
        [':i' => $itemId, ':l' => $locationId])->fetchColumn();
}

// ── Locations (created on demand, once) ─────────────────────────────────────

/** The one Main stock location. */
function inv_store_location_id(): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    db_query("INSERT INTO inv_locations (kind, name) VALUES ('store', 'Main stock') ON CONFLICT (kind) WHERE kind = 'store' DO NOTHING");
    return (int) db_query("SELECT id FROM inv_locations WHERE kind = 'store'")->fetchColumn();
}

/** A property's location (named after the venue). */
function inv_property_location_id(int $venueId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $name = db_query('SELECT name FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn();
    if ($name === false) throw new InvRefusal('That property does not exist.');
    db_query("INSERT INTO inv_locations (kind, name, venue_id) VALUES ('property', :n, :v)
              ON CONFLICT (venue_id) WHERE kind = 'property' DO NOTHING", [':n' => $name, ':v' => $venueId]);
    return (int) db_query("SELECT id FROM inv_locations WHERE kind = 'property' AND venue_id = :v", [':v' => $venueId])->fetchColumn();
}

/** A POS outlet's shelf. Owning venue = the outlet's venue (NULL = shared). */
function inv_outlet_location_id(int $outletId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $o = db_query('SELECT name, venue_id FROM pos_outlets WHERE id = :o', [':o' => $outletId])->fetch();
    if (!$o) throw new InvRefusal('That outlet does not exist.');
    db_query("INSERT INTO inv_locations (kind, name, pos_outlet_id, venue_id) VALUES ('outlet', :n, :o, :v)
              ON CONFLICT (pos_outlet_id) DO NOTHING", [':n' => $o['name'], ':o' => $outletId, ':v' => $o['venue_id']]);
    return (int) db_query('SELECT id FROM inv_locations WHERE pos_outlet_id = :o', [':o' => $outletId])->fetchColumn();
}

/** A team member's location — "assigned to Jane" means "at Jane's location". Owning venue = their home venue. */
function inv_person_location_id(int $hrStaffId): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $s = db_query('SELECT full_name, venue_id FROM hr_staff WHERE id = :s', [':s' => $hrStaffId])->fetch();
    if (!$s) throw new InvRefusal('That team member does not exist.');
    db_query("INSERT INTO inv_locations (kind, name, hr_staff_id, venue_id) VALUES ('person', :n, :s, :v)
              ON CONFLICT (hr_staff_id) DO NOTHING", [':n' => mb_substr((string)$s['full_name'], 0, 120), ':s' => $hrStaffId, ':v' => $s['venue_id']]);
    return (int) db_query('SELECT id FROM inv_locations WHERE hr_staff_id = :s', [':s' => $hrStaffId])->fetchColumn();
}

// ── Items ───────────────────────────────────────────────────────────────────

/**
 * Create a catalogue item. $v: name, item_type, and optionally category, sku,
 * image_key, icon, tracking ('qty'|'serial'), unit_label, replacement_value,
 * currency, consignor_id, low_stock_at. Returns the new id.
 */
function inv_create_item(array $v): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $name = trim((string)($v['name'] ?? ''));
    if ($name === '') throw new InvRefusal('Give the item a name.');
    $type = (string)($v['item_type'] ?? 'operational');
    if (!isset(INV_TYPES[$type])) throw new InvRefusal('Pick an item type.');
    $val = $v['replacement_value'] ?? null;
    if ($val !== null && $val !== '' && (!is_numeric($val) || (float)$val < 0)) throw new InvRefusal('Replacement value must be zero or more.');
    $cur = strtoupper(trim((string)($v['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = INV_DEFAULT_CURRENCY;
    $opt = fn(string $k, int $max) => ($s = trim((string)($v[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;
    $low = $v['low_stock_at'] ?? null;
    db_query('INSERT INTO inv_items (name, item_type, category, sku, image_key, icon, tracking, unit_label,
                                     replacement_value, currency, consignor_id, low_stock_at)
              VALUES (:n, :t, :c, :s, :img, :ic, :tr, :u, :v, :cur, :cs, :low)', [
        ':n'   => mb_substr($name, 0, 160),
        ':t'   => $type,
        ':c'   => $opt('category', 60),
        ':s'   => $opt('sku', 60),
        ':img' => !empty($v['image_key']) ? (string)$v['image_key'] : null,
        ':ic'  => $opt('icon', 40),
        ':tr'  => ($v['tracking'] ?? 'qty') === 'serial' ? 'serial' : 'qty',
        ':u'   => $opt('unit_label', 20) ?? 'pcs',
        ':v'   => ($val === null || $val === '') ? null : round((float)$val, 2),
        ':cur' => $cur,
        ':cs'  => !empty($v['consignor_id']) ? (int)$v['consignor_id'] : null,
        ':low' => ($low === null || $low === '') ? null : max(0, (int)$low),
    ]);
    return (int) db()->lastInsertId();
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_logic.php | tail -4`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): locations created on demand (store, property, outlet, person) and items

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `inv_move()` — the one write path

**Files:**
- Modify: `includes/inventory.php` (append)
- Modify: `tests/inventory_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above the marker:

```php
    // ── The one write path ──
    $m1 = inv_move(['item_id' => $plates, 'qty' => 100, 'to' => $store, 'reason' => 'receive', 'unit_value' => 800]);
    check('move: receive 100 plates into Main stock', inv_balance($plates, $store) === 100);
    $mv = db_query('SELECT * FROM inv_moves WHERE id = :id', [':id' => $m1])->fetch();
    check('move: a receive keeps its unit value, total and currency',
        (float)$mv['unit_value'] === 800.0 && (float)$mv['value'] === 80000.0 && $mv['currency'] === 'KES');
    inv_move(['item_id' => $plates, 'qty' => 20, 'from' => $store, 'to' => $locA, 'reason' => 'transfer']);
    inv_move(['item_id' => $plates, 'qty' => 20, 'from' => $store, 'to' => $locB, 'reason' => 'transfer']);
    check('move: 60 left in Main stock, 20 at each property',
        inv_balance($plates, $store) === 60 && inv_balance($plates, $locA) === 20 && inv_balance($plates, $locB) === 20);
    $uv = db_query("SELECT unit_value FROM inv_moves WHERE item_id = :i AND reason = 'transfer' ORDER BY id DESC LIMIT 1", [':i' => $plates])->fetchColumn();
    check('move: without a value it snapshots the replacement value', (float)$uv === 850.0);
    $msg = ''; try { inv_move(['item_id' => $plates, 'qty' => 21, 'from' => $locA, 'reason' => 'broken']); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('move: cannot take more than is there', str_contains($msg, 'Only 20') && inv_balance($plates, $locA) === 20);
    check('move: the outer transaction survives a refusal', $count('SELECT 1') === 1);
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $locA, 'reason' => 'broken', 'note' => 'dropped']);
    check('move: a breakage leaves the property', inv_balance($plates, $locA) === 19);
    check('ledger: every balance equals the sum of its moves',
        $ledger($plates, $store) === 60 && $ledger($plates, $locA) === 19 && $ledger($plates, $locB) === 20);
    db_query('UPDATE inv_locations SET is_active = FALSE WHERE id = :l', [':l' => $locB]);
    $threw = false; try { inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $store, 'to' => $locB, 'reason' => 'transfer']); } catch (InvRefusal $e) { $threw = true; }
    check('move: nothing moves into a closed location', $threw && inv_balance($plates, $store) === 60);
    db_query('UPDATE inv_locations SET is_active = TRUE WHERE id = :l', [':l' => $locB]);
    $cap = inv_create_item(['name' => 'ZZ Cap', 'item_type' => 'sellable', 'replacement_value' => 1200]);
    inv_move(['item_id' => $cap, 'qty' => 2, 'from' => $locShop, 'reason' => 'sale', 'allow_negative' => true]);
    check('move: allow_negative (POS only) may go below zero, ledger still agrees',
        inv_balance($cap, $locShop) === -2 && $ledger($cap, $locShop) === -2);
    $threw = false; try { inv_move(['item_id' => $cap, 'qty' => 1, 'from' => $locShop, 'reason' => 'sale']); } catch (InvRefusal $e) { $threw = true; }
    check('move: without allow_negative a negative shelf sells nothing', $threw);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_move()`.

- [ ] **Step 3: Append `inv_move()` to `includes/inventory.php`**

```php

// ── The ONE write path ──────────────────────────────────────────────────────

/**
 * Record one movement and update the balances. The only writer of inv_moves and
 * inv_balances. $m keys:
 *   item_id, qty, reason, from (location id|null), to (location id|null),
 *   user_id?, note?, asset_id? (serial unit), pos_sale_id?, count_line_id?,
 *   unit_value? (defaults to the item's replacement_value),
 *   terms? ['consignor_id','consign_pct','consignor_cost'] (a consignment delivery),
 *   allow_negative? (only a POS listing flagged allow_negative passes true).
 * Returns the new move id. Throws InvRefusal to refuse.
 */
function inv_move(array $m): int {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    $n = inv_normalize_move($m);
    if ($err = inv_move_error($n)) throw new InvRefusal($err);
    return inv_tx(fn(): int => inv_move_tx($n));
}

/** Body of inv_move(), inside the transaction. */
function inv_move_tx(array $n): int {
    $item = db_query('SELECT id, name, tracking, replacement_value, currency FROM inv_items WHERE id = :i', [':i' => $n['item_id']])->fetch();
    if (!$item) throw new InvRefusal('That item no longer exists.');
    $from = $n['from'];
    $to   = $n['to'];
    $qty  = $n['qty'];

    // Lock both balance rows in location-id order, so two moves never deadlock.
    $locs = array_values(array_filter([$from, $to], fn($l) => $l !== null));
    sort($locs);
    $names = [];
    $have  = [];
    foreach ($locs as $l) {
        $loc = db_query('SELECT id, name, is_active FROM inv_locations WHERE id = :l', [':l' => $l])->fetch();
        if (!$loc) throw new InvRefusal('That location no longer exists.');
        if ($l === $to && !inv_bool($loc['is_active'])) throw new InvRefusal("{$loc['name']} is closed — reopen it before moving stock in.");
        $names[$l] = (string)$loc['name'];
        $have[$l]  = inv_balance_lock($n['item_id'], $l);
    }
    if ($from !== null && !$n['allow_negative'] && $have[$from] < $qty) {
        throw new InvRefusal(inv_shortfall_message((string)$item['name'], $have[$from], $names[$from]));
    }

    // Serial units: name the unit, move it from where it actually is.
    $isSerial = $item['tracking'] === 'serial';
    if ($isSerial && $n['asset_id'] === null) throw new InvRefusal("Pick which {$item['name']} — it is tracked by serial number.");
    if (!$isSerial && $n['asset_id'] !== null) throw new InvRefusal("{$item['name']} is not tracked by serial number.");
    if ($isSerial) {
        $a = db_query('SELECT id, status, location_id FROM inv_assets WHERE id = :a AND item_id = :i FOR UPDATE',
            [':a' => $n['asset_id'], ':i' => $n['item_id']])->fetch();
        if (!$a) throw new InvRefusal('That unit does not belong to this item.');
        $aLoc = $a['location_id'] !== null ? (int)$a['location_id'] : null;
        if ($from !== null) {
            if ($a['status'] !== 'active' || $aLoc !== $from) throw new InvRefusal("That unit is not at {$names[$from]}.");
        } else {
            $moved = (bool) db_query('SELECT 1 FROM inv_moves WHERE asset_id = :a LIMIT 1', [':a' => $n['asset_id']])->fetchColumn();
            if ($a['status'] === 'active' && $moved) throw new InvRefusal('That unit is already in stock.');
        }
    }

    $unit  = $n['unit_value'] ?? ($item['replacement_value'] !== null ? (float)$item['replacement_value'] : null);
    $terms = $n['terms'];
    db_query(
        'INSERT INTO inv_moves (item_id, qty, from_location_id, to_location_id, reason, unit_value, value, currency,
                                asset_id, pos_sale_id, count_line_id, consignor_id, consign_pct, consignor_cost, note, admin_user_id)
         VALUES (:i, :q, :f, :t, :r, :uv, :v, :cur, :a, :s, :cl, :ci, :cp, :cc, :n, :u)',
        [':i' => $n['item_id'], ':q' => $qty, ':f' => $from, ':t' => $to, ':r' => $n['reason'],
         ':uv' => $unit, ':v' => $unit === null ? null : round($unit * $qty, 2), ':cur' => (string)$item['currency'],
         ':a' => $n['asset_id'], ':s' => $n['pos_sale_id'], ':cl' => $n['count_line_id'],
         ':ci' => !empty($terms['consignor_id']) ? (int)$terms['consignor_id'] : null,
         ':cp' => isset($terms['consign_pct']) && $terms['consign_pct'] !== null ? (float)$terms['consign_pct'] : null,
         ':cc' => isset($terms['consignor_cost']) && $terms['consignor_cost'] !== null ? (float)$terms['consignor_cost'] : null,
         ':n' => $n['note'] !== '' ? $n['note'] : null, ':u' => $n['user_id']]
    );
    $moveId = (int) db()->lastInsertId();

    if ($from !== null) db_query('UPDATE inv_balances SET qty = qty - :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $n['item_id'], ':l' => $from]);
    if ($to !== null)   db_query('UPDATE inv_balances SET qty = qty + :q WHERE item_id = :i AND location_id = :l', [':q' => $qty, ':i' => $n['item_id'], ':l' => $to]);
    if ($isSerial) {
        if ($to !== null) {
            db_query("UPDATE inv_assets SET status = 'active', location_id = :l WHERE id = :a", [':l' => $to, ':a' => $n['asset_id']]);
        } else {
            db_query('UPDATE inv_assets SET status = :s, location_id = NULL WHERE id = :a',
                [':s' => $n['reason'] === 'sale' ? 'sold' : 'written_off', ':a' => $n['asset_id']]);
        }
    }
    return $moveId;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_logic.php | tail -4`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): inv_move() — the one write path (locking, no negatives, value snapshot)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Serial units, transfers and losses

**Files:**
- Modify: `includes/inventory.php` (append)
- Modify: `tests/inventory_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above the marker:

```php
    // ── Serial units, transfers, losses ──
    $a1 = inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-001', 'condition' => 'new', 'purchase_value' => 92000], null);
    $a2 = inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-002'], null);
    $status = fn(int $a) => db_query('SELECT status FROM inv_assets WHERE id = :a', [':a' => $a])->fetchColumn();
    check('serial: two units received into Main stock', inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_asset_create($laptop, $store, ['serial' => 'ZZ-SN-001'], null); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a duplicate serial is refused, nothing added', $threw && inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'from' => $store, 'to' => $locJane, 'reason' => 'assign']); } catch (InvRefusal $e) { $threw = true; }
    check('serial: moving without naming the unit is refused', $threw);
    inv_transfer($laptop, 1, $store, $locJane, null, 'Laptop for Jane', $a1);
    check('serial: assigned to Jane',
        inv_balance($laptop, $locJane) === 1 && (int)db_query('SELECT location_id FROM inv_assets WHERE id = :a', [':a' => $a1])->fetchColumn() === $locJane);
    check('serial: recorded as an assignment',
        db_query('SELECT reason FROM inv_moves WHERE asset_id = :a ORDER BY id DESC LIMIT 1', [':a' => $a1])->fetchColumn() === 'assign');
    $threw = false; try { inv_transfer($laptop, 1, $store, $locA, null, '', $a1); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a unit only leaves from where it is', $threw);
    inv_report_loss($laptop, 1, $locJane, 'stolen', null, 'taken from car', $a1);
    check('serial: a stolen unit is written off and leaves Jane', inv_balance($laptop, $locJane) === 0 && $status($a1) === 'written_off');
    $active = fn(int $loc) => $count("SELECT COUNT(*) FROM inv_assets WHERE item_id = :i AND location_id = :l AND status = 'active'", [':i' => $laptop, ':l' => $loc]);
    check('serial: balance = active units at every location',
        inv_balance($laptop, $store) === $active($store) && inv_balance($laptop, $locJane) === $active($locJane));
    inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'found', 'asset_id' => $a1]);
    check('serial: a found unit comes back into stock', inv_balance($laptop, $store) === 2 && $status($a1) === 'active');
    $threw = false; try { inv_move(['item_id' => $laptop, 'qty' => 1, 'to' => $store, 'reason' => 'found', 'asset_id' => $a2]); } catch (InvRefusal $e) { $threw = true; }
    check('serial: a unit already in stock cannot come in twice', $threw && inv_balance($laptop, $store) === 2);
    $threw = false; try { inv_report_loss($plates, 1, $locA, 'vanished', null); } catch (InvRefusal $e) { $threw = true; }
    check('loss: only broken / missing / stolen / written off', $threw);
    check('transfer: person → store is a return', inv_transfer_reason('person', 'store') === 'return');

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_asset_create()`.

- [ ] **Step 3: Append to `includes/inventory.php`**

```php

// ── Everyday actions (each is one or more inv_move() calls) ─────────────────

/** Move stock between two locations; recorded as transfer / assign / return by the location kinds. */
function inv_transfer(int $itemId, int $qty, int $fromId, int $toId, ?int $userId, string $note = '', ?int $assetId = null): int {
    $from = inv_fetch_location($fromId);
    $to   = inv_fetch_location($toId);
    if (!$from || !$to) throw new InvRefusal('Pick where it comes from and where it goes.');
    return inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $fromId, 'to' => $toId,
                     'reason' => inv_transfer_reason((string)$from['kind'], (string)$to['kind']),
                     'user_id' => $userId, 'note' => $note, 'asset_id' => $assetId]);
}

/** Record stock lost from a location: broken, missing, stolen or written off (value snapshotted). */
function inv_report_loss(int $itemId, int $qty, int $fromId, string $reason, ?int $userId, string $note = '', ?int $assetId = null): int {
    if (!in_array($reason, INV_LOSS_REASONS, true)) throw new InvRefusal('Pick what happened: broken, missing, stolen or written off.');
    return inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $fromId, 'reason' => $reason,
                     'user_id' => $userId, 'note' => $note, 'asset_id' => $assetId]);
}

/**
 * Register a serial-tracked unit and receive it into a location. $f: serial, tag,
 * condition (new|good|fair|poor), purchase_date (Y-m-d), purchase_value, notes.
 * Returns the unit (inv_assets) id.
 */
function inv_asset_create(int $itemId, int $toLocationId, array $f, ?int $userId): int {
    $item = inv_fetch_item($itemId);
    if (!$item || $item['tracking'] !== 'serial') throw new InvRefusal('Units can only be added to a serial-tracked item.');
    $serial = trim((string)($f['serial'] ?? ''));
    $cond   = in_array($f['condition'] ?? '', INV_CONDITIONS, true) ? (string)$f['condition'] : 'good';
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['purchase_date'] ?? '')) ? (string)$f['purchase_date'] : null;
    $pv     = $f['purchase_value'] ?? null;
    if ($pv !== null && $pv !== '' && (!is_numeric($pv) || (float)$pv < 0)) throw new InvRefusal('Purchase value must be zero or more.');
    $pv = ($pv === null || $pv === '') ? null : round((float)$pv, 2);
    try {
        return inv_tx(function () use ($itemId, $toLocationId, $serial, $cond, $date, $pv, $f, $userId): int {
            db_query("INSERT INTO inv_assets (item_id, serial, tag, condition, status, location_id, purchase_date, purchase_value, notes)
                      VALUES (:i, :s, :t, :c, 'active', :l, :d, :pv, :n)", [
                ':i' => $itemId, ':s' => $serial !== '' ? mb_substr($serial, 0, 80) : null,
                ':t' => ($t = trim((string)($f['tag'] ?? ''))) !== '' ? mb_substr($t, 0, 40) : null,
                ':c' => $cond, ':l' => $toLocationId, ':d' => $date, ':pv' => $pv,
                ':n' => ($nt = trim((string)($f['notes'] ?? ''))) !== '' ? $nt : null,
            ]);
            $assetId = (int) db()->lastInsertId();
            inv_move(['item_id' => $itemId, 'qty' => 1, 'to' => $toLocationId, 'reason' => 'receive', 'asset_id' => $assetId,
                      'unit_value' => $pv, 'user_id' => $userId, 'note' => $serial !== '' ? "Serial {$serial}" : '']);
            return $assetId;
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') throw new InvRefusal("Serial {$serial} is already registered for this item.");
        throw $e;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_logic.php | tail -4`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): serial units, transfers/assignments and loss reporting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Replace, par levels and restock-to-par

**Files:**
- Modify: `includes/inventory.php` (append)
- Modify: `tests/inventory_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above the marker (plates at this point: Main stock 60, A 19, B 20):

```php
    // ── Replace, par, restock ──
    $r = inv_replace($plates, 2, $locA, 'broken', null, 'dinner party');
    check('replace: breakage + refill in one step', inv_balance($plates, $locA) === 19 && inv_balance($plates, $store) === 58);
    $loss = db_query('SELECT reason, value FROM inv_moves WHERE id = :id', [':id' => $r['loss_move_id']])->fetch();
    check('replace: the loss carries its value (2 × 850)', $loss['reason'] === 'broken' && (float)$loss['value'] === 1700.0);
    check('replace: the refill is a "replaced" move from Main stock',
        db_query('SELECT reason FROM inv_moves WHERE id = :id', [':id' => $r['replace_move_id']])->fetchColumn() === 'replaced');
    $threw = false; try { inv_replace($laptop, 1, $store, 'broken', null); } catch (InvRefusal $e) { $threw = true; }
    check('replace: serial items are replaced unit by unit, not here', $threw);
    inv_set_par($plates, $locA, 24);
    $glasses = inv_create_item(['name' => 'ZZ Wine glass', 'item_type' => 'operational', 'replacement_value' => 400]);
    inv_move(['item_id' => $glasses, 'qty' => 3, 'to' => $store, 'reason' => 'receive']);
    inv_set_par($glasses, $locA, 12);
    $res = inv_restock_to_par($locA, null);
    check('restock: plates topped up to par 24', inv_balance($plates, $locA) === 24 && ($res['moved'][$plates] ?? 0) === 5);
    check('restock: glasses limited by Main stock, shortfall reported',
        inv_balance($glasses, $locA) === 3 && ($res['short'][$glasses] ?? 0) === 9 && inv_balance($glasses, $store) === 0);
    $threw = false; try { inv_set_par($plates, $locA, -1); } catch (InvRefusal $e) { $threw = true; }
    check('par: cannot be negative', $threw);
    $losses = inv_sum_by_currency(db_query(
        "SELECT value, currency FROM inv_moves WHERE item_id IN (:a, :b) AND reason IN ('broken','missing','stolen','written_off')",
        [':a' => $plates, ':b' => $laptop])->fetchAll());
    check('money: losses per currency (3 plates × 850 + 1 laptop × 95,000)', $losses === ['KES' => 97550.0]);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_replace()`.

- [ ] **Step 3: Append to `includes/inventory.php`**

```php

/**
 * Replace lost stock in one step: record the loss at $atId, then refill the same
 * quantity from $sourceId (default Main stock). Counted items only — a serial unit
 * is reported lost and another unit assigned. Returns both move ids.
 */
function inv_replace(int $itemId, int $qty, int $atId, string $lossReason, ?int $userId, string $note = '', ?int $sourceId = null): array {
    if (!in_array($lossReason, INV_LOSS_REASONS, true)) throw new InvRefusal('Pick what happened: broken, missing, stolen or written off.');
    $item = inv_fetch_item($itemId);
    if (!$item) throw new InvRefusal('That item no longer exists.');
    if ($item['tracking'] === 'serial') throw new InvRefusal("Report the {$item['name']} unit lost, then assign another unit.");
    $sourceId ??= inv_store_location_id();
    if ($sourceId === $atId) throw new InvRefusal('The replacement has to come from somewhere else.');
    return inv_tx(function () use ($itemId, $qty, $atId, $lossReason, $userId, $note, $sourceId): array {
        $loss = inv_report_loss($itemId, $qty, $atId, $lossReason, $userId, $note);
        $rep  = inv_move(['item_id' => $itemId, 'qty' => $qty, 'from' => $sourceId, 'to' => $atId,
                          'reason' => 'replaced', 'user_id' => $userId, 'note' => $note]);
        return ['loss_move_id' => $loss, 'replace_move_id' => $rep];
    });
}

/** Set (or clear with null) the par level — "should always have N" — of an item at a location. */
function inv_set_par(int $itemId, int $locationId, ?int $par): void {
    if (!inv_supported()) throw new InvRefusal('Inventory is not set up yet.');
    if ($par !== null && $par < 0) throw new InvRefusal('Par level cannot be negative.');
    db_query('INSERT INTO inv_balances (item_id, location_id, qty, par_qty) VALUES (:i, :l, 0, :p)
              ON CONFLICT (item_id, location_id) DO UPDATE SET par_qty = EXCLUDED.par_qty',
        [':i' => $itemId, ':l' => $locationId, ':p' => $par]);
}

/**
 * Top every counted item at $locationId up to its par level from $sourceId
 * (default Main stock), as far as the source has stock — one transaction.
 * Returns ['moved' => [item_id => qty], 'short' => [item_id => qty still missing]].
 */
function inv_restock_to_par(int $locationId, ?int $userId, ?int $sourceId = null): array {
    $sourceId ??= inv_store_location_id();
    if ($sourceId === $locationId) throw new InvRefusal('Restock from a different location.');
    return inv_tx(function () use ($locationId, $userId, $sourceId): array {
        $rows = db_query("SELECT b.item_id, b.qty, b.par_qty FROM inv_balances b JOIN inv_items i ON i.id = b.item_id
                           WHERE b.location_id = :l AND b.par_qty IS NOT NULL AND i.is_active = TRUE AND i.tracking = 'qty'
                           ORDER BY b.item_id", [':l' => $locationId])->fetchAll();
        $moved = [];
        $short = [];
        foreach (inv_restock_plan($rows) as $itemId => $need) {
            $take = min($need, max(0, inv_balance_lock($itemId, $sourceId)));
            if ($take > 0) {
                inv_move(['item_id' => $itemId, 'qty' => $take, 'from' => $sourceId, 'to' => $locationId,
                          'reason' => 'transfer', 'user_id' => $userId, 'note' => 'Restock to par']);
                $moved[$itemId] = $take;
            }
            if ($take < $need) $short[$itemId] = $need - $take;
        }
        return ['moved' => $moved, 'short' => $short];
    });
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_logic.php | tail -4`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): replace, par levels and restock-to-par

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Counts — snapshot, submit (never moves stock), manager resolution

**Files:**
- Modify: `includes/inventory.php` (append)
- Modify: `tests/inventory_logic.php` (insert above the marker)

- [ ] **Step 1: Write the failing DB checks**

Insert above the marker (at this point A holds 24 plates, par 24, and 3 glasses, par 12):

```php
    // ── Counts flag; a manager's resolution moves stock ──
    $counter = $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, 'staff', 'ZZ Counter', TRUE)",
        [':e' => "zz-inv-counter-{$sfx}@example.com"]);
    $cid = inv_count_start($locA, $counter);
    check('count: starting twice reuses the open count', inv_count_start($locA, $counter) === $cid);
    $exp = db_query('SELECT item_id, expected FROM inv_count_lines WHERE count_id = :c', [':c' => $cid])->fetchAll(PDO::FETCH_KEY_PAIR);
    check('count: expected is snapshotted from the system', (int)($exp[$plates] ?? -1) === 24 && (int)($exp[$glasses] ?? -1) === 3);
    $threw = false; try { inv_count_submit($cid, [$plates => 23], $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: every line needs a number', $threw);
    $res = inv_count_submit($cid, [$plates => 23, $glasses => 3], $counter);
    check('count: submitting never moves stock', $res['gaps'] === 1 && inv_balance($plates, $locA) === 24);
    check('count: a matching line is accepted automatically',
        db_query('SELECT resolution FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $cid, ':i' => $glasses])->fetchColumn() === 'accepted');
    check('count: the location records when it was counted', inv_fetch_location($locA)['last_counted_at'] !== null);
    $line = (int) db_query('SELECT id FROM inv_count_lines WHERE count_id = :c AND item_id = :i', [':c' => $cid, ':i' => $plates])->fetchColumn();
    $threw = false; try { inv_count_resolve_line($line, 'found', $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: "found" on a short line is refused', $threw);
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $store, 'to' => $locA, 'reason' => 'transfer']);
    $msg = ''; try { inv_count_resolve_line($line, 'missing', $counter); } catch (InvRefusal $e) { $msg = $e->getMessage(); }
    check('count: refused when stock changed since the count', str_contains($msg, 'changed since'));
    inv_move(['item_id' => $plates, 'qty' => 1, 'from' => $locA, 'to' => $store, 'reason' => 'transfer']);
    $mid = inv_count_resolve_line($line, 'missing', $counter);
    check('count: resolving "missing" writes the loss, linked to the line',
        $mid !== null && inv_balance($plates, $locA) === 23
        && (int)db_query('SELECT count_line_id FROM inv_moves WHERE id = :m', [':m' => $mid])->fetchColumn() === $line);
    check('count: the count is resolved once every line is',
        db_query('SELECT status FROM inv_counts WHERE id = :c', [':c' => $cid])->fetchColumn() === 'resolved');
    $threw = false; try { inv_count_resolve_line($line, 'missing', $counter); } catch (InvRefusal $e) { $threw = true; }
    check('count: a line resolves only once', $threw && inv_balance($plates, $locA) === 23);
    check('ledger: still equals every balance after all of it',
        $ledger($plates, $locA) === 23 && $ledger($plates, $store) === inv_balance($plates, $store) && $ledger($glasses, $locA) === 3);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/inventory_logic.php`
Expected: `FAIL  DB block threw: Call to undefined function inv_count_start()`.

- [ ] **Step 3: Append to `includes/inventory.php`**

```php

// ── Counts (the check) ──────────────────────────────────────────────────────

/**
 * Open a count at a location, snapshotting what the system expects there (every
 * active item with stock or a par level). An open count is reused, so a counter
 * who reloads the page keeps their lines. Returns the count id.
 */
function inv_count_start(int $locationId, int $userId): int {
    $loc = inv_fetch_location($locationId);
    if (!$loc || !inv_bool($loc['is_active'])) throw new InvRefusal('That location is not open.');
    return inv_tx(function () use ($locationId, $userId): int {
        $open = db_query("SELECT id FROM inv_counts WHERE location_id = :l AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [':l' => $locationId])->fetchColumn();
        if ($open) return (int)$open;
        db_query('INSERT INTO inv_counts (location_id, counted_by) VALUES (:l, :u)', [':l' => $locationId, ':u' => $userId]);
        $countId = (int) db()->lastInsertId();
        db_query('INSERT INTO inv_count_lines (count_id, item_id, expected)
                  SELECT :c, b.item_id, b.qty FROM inv_balances b JOIN inv_items i ON i.id = b.item_id
                   WHERE b.location_id = :l AND i.is_active = TRUE AND (b.qty <> 0 OR b.par_qty IS NOT NULL)',
            [':c' => $countId, ':l' => $locationId]);
        return $countId;
    });
}

/**
 * Save the counted numbers and submit. $counted = [item_id => whole number];
 * every line needs one. Matching lines close as 'accepted'; gaps wait for a
 * manager. Stock never changes here. Returns ['gaps' => int].
 */
function inv_count_submit(int $countId, array $counted, int $userId): array {
    return inv_tx(function () use ($countId, $counted, $userId): array {
        $c = db_query('SELECT id, location_id, status FROM inv_counts WHERE id = :c FOR UPDATE', [':c' => $countId])->fetch();
        if (!$c) throw new InvRefusal('That count does not exist.');
        if ($c['status'] !== 'open') throw new InvRefusal('This count was already submitted.');
        $lines = db_query('SELECT id, item_id, expected FROM inv_count_lines WHERE count_id = :c ORDER BY id', [':c' => $countId])->fetchAll();
        $gaps = 0;
        foreach ($lines as $l) {
            $v = $counted[(int)$l['item_id']] ?? null;
            if ($v === null || $v === '' || !ctype_digit((string)$v)) throw new InvRefusal('Enter a number for every item (0 if there are none).');
            $v = (int)$v;
            if ($v > INV_MAX_QTY) throw new InvRefusal('That count is too large.');
            if ($v === (int)$l['expected']) {
                db_query("UPDATE inv_count_lines SET counted = :v, resolution = 'accepted', resolved_by = :u, resolved_at = now(), balance_at_resolve = :b WHERE id = :id",
                    [':v' => $v, ':u' => $userId, ':b' => (int)$l['expected'], ':id' => (int)$l['id']]);
            } else {
                db_query('UPDATE inv_count_lines SET counted = :v WHERE id = :id', [':v' => $v, ':id' => (int)$l['id']]);
                $gaps++;
            }
        }
        db_query('UPDATE inv_counts SET status = :s, submitted_at = now() WHERE id = :c', [':s' => $gaps ? 'submitted' : 'resolved', ':c' => $countId]);
        db_query('UPDATE inv_locations SET last_counted_at = now() WHERE id = :l', [':l' => (int)$c['location_id']]);
        return ['gaps' => $gaps];
    });
}

/**
 * A manager resolves one gap: missing / broken / stolen (loss out of the
 * location), found (back in), recount or accepted (no move). Refused while the
 * live balance differs from what the counter was shown. Returns the move id, or
 * null when nothing moved.
 */
function inv_count_resolve_line(int $lineId, string $resolution, int $userId, string $note = ''): ?int {
    return inv_tx(function () use ($lineId, $resolution, $userId, $note): ?int {
        $l = db_query('SELECT cl.*, c.location_id, c.status AS count_status, i.name AS item_name, i.tracking
                         FROM inv_count_lines cl
                         JOIN inv_counts c ON c.id = cl.count_id
                         JOIN inv_items i  ON i.id = cl.item_id
                        WHERE cl.id = :id FOR UPDATE OF cl', [':id' => $lineId])->fetch();
        if (!$l) throw new InvRefusal('That count line does not exist.');
        if ($l['count_status'] === 'open') throw new InvRefusal('This count has not been submitted yet.');
        if ($l['resolution'] !== null) throw new InvRefusal('This line is already resolved.');
        $plan = inv_resolution_move($resolution, (int)$l['expected'], (int)$l['counted']);
        if (is_string($plan)) throw new InvRefusal($plan);
        $loc = (int)$l['location_id'];
        $bal = inv_balance_lock((int)$l['item_id'], $loc);
        $moveId = null;
        if ($plan !== null) {
            if ($bal !== (int)$l['expected']) {
                throw new InvRefusal("Stock of {$l['item_name']} changed since this count (now {$bal}) — mark it recount and count again.");
            }
            if ($l['tracking'] === 'serial') {
                throw new InvRefusal("{$l['item_name']} is tracked by serial number — report the specific unit from its page, then mark this line recount.");
            }
            $moveId = inv_move(['item_id' => (int)$l['item_id'], 'qty' => $plan['qty'],
                                'from' => $plan['dir'] === 'out' ? $loc : null, 'to' => $plan['dir'] === 'in' ? $loc : null,
                                'reason' => $plan['reason'], 'user_id' => $userId, 'count_line_id' => $lineId,
                                'note' => $note !== '' ? $note : 'Count #' . (int)$l['count_id']]);
        }
        db_query('UPDATE inv_count_lines SET resolution = :r, resolved_by = :u, resolved_at = now(), balance_at_resolve = :b WHERE id = :id',
            [':r' => $resolution, ':u' => $userId, ':b' => $bal, ':id' => $lineId]);
        $open = (int) db_query('SELECT COUNT(*) FROM inv_count_lines WHERE count_id = :c AND resolution IS NULL', [':c' => (int)$l['count_id']])->fetchColumn();
        if ($open === 0) db_query("UPDATE inv_counts SET status = 'resolved' WHERE id = :c", [':c' => (int)$l['count_id']]);
        return $moveId;
    });
}

// ── History reads ───────────────────────────────────────────────────────────

/** Moves of an item, newest first; with $locationId only those in or out of it. */
function inv_item_moves(int $itemId, ?int $locationId = null, int $limit = 100): array {
    if (!inv_supported()) return [];
    $p = [':i' => $itemId];
    $w = 'm.item_id = :i';
    if ($locationId !== null) {
        $w .= ' AND (m.from_location_id = :l1 OR m.to_location_id = :l2)';
        $p[':l1'] = $locationId;
        $p[':l2'] = $locationId;
    }
    return db_query(
        "SELECT m.*, a.name AS user_name, s.reference AS sale_reference, c.name AS consignor_name,
                lf.name AS from_name, lt.name AS to_name
           FROM inv_moves m
           LEFT JOIN admin_users a    ON a.id = m.admin_user_id
           LEFT JOIN pos_sales s      ON s.id = m.pos_sale_id
           LEFT JOIN pos_consignors c ON c.id = m.consignor_id
           LEFT JOIN inv_locations lf ON lf.id = m.from_location_id
           LEFT JOIN inv_locations lt ON lt.id = m.to_location_id
          WHERE {$w}
          ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, min(500, $limit)), $p
    )->fetchAll();
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/inventory_logic.php | tail -4`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventory.php tests/inventory_logic.php
git commit -m "feat(inventory): counts — snapshot, submit without moving stock, manager resolution; move history

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: POS switch-over — the till's stock is the outlet's inventory

**Files:**
- Modify: `includes/pos.php`
- Modify: `tests/pos_logic.php`

- [ ] **Step 1: Update `tests/pos_logic.php` so it asserts the new source of truth (these fail first)**

1. Replace

```php
    $stock = fn(int $id) => (int) db_query('SELECT stock_qty FROM pos_items WHERE id = :i', [':i' => $id])->fetchColumn();
```

with

```php
    $stock = fn(int $id) => pos_item_stock_on_hand($id);
```

2. Directly after the `$brace = $ins(...)` line add:

```php
    $inv = inv_supported();
    if ($inv) {   // stock lives in the outlet's inventory now; the raw stock_qty seeded above is not read
        pos_stock_receive($cap, 10, null, 'Opening stock', null);
        pos_stock_receive($brace, 9, null, 'Opening stock', null);
    }
```

3. Replace

```php
    check('sale: one "sale" stock move of -2', $count("SELECT COUNT(*) FROM pos_stock_moves WHERE item_id = :i AND reason = 'sale' AND qty_delta = -2 AND sale_id = :s", [':i' => $cap, ':s' => (int)($s1['id'] ?? 0)]) === 1);
```

with

```php
    check('sale: one "sale" stock move of 2', $inv
        ? $count("SELECT COUNT(*) FROM inv_moves m JOIN pos_items p ON p.inv_item_id = m.item_id
                   WHERE p.id = :i AND m.reason = 'sale' AND m.qty = 2 AND m.pos_sale_id = :s", [':i' => $cap, ':s' => (int)($s1['id'] ?? 0)]) === 1
        : $count("SELECT COUNT(*) FROM pos_stock_moves WHERE item_id = :i AND reason = 'sale' AND qty_delta = -2 AND sale_id = :s", [':i' => $cap, ':s' => (int)($s1['id'] ?? 0)]) === 1);
    if ($inv) {
        $capInv = (int) db_query('SELECT inv_item_id FROM pos_items WHERE id = :i', [':i' => $cap])->fetchColumn();
        check('sale: taken from the owning outlet\'s inventory location', inv_balance($capInv, inv_outlet_location_id($shop)) === 8);
    }
```

4. Replace

```php
        $mv = db_query("SELECT consignor_id, consignor_cost FROM pos_stock_moves WHERE item_id = :i AND reason = 'receive' ORDER BY id DESC LIMIT 1", [':i' => $cap])->fetch();
```

with

```php
        $mv = $inv
            ? db_query("SELECT m.consignor_id, m.consignor_cost FROM inv_moves m JOIN pos_items p ON p.inv_item_id = m.item_id
                         WHERE p.id = :i AND m.reason = 'receive' ORDER BY m.id DESC LIMIT 1", [':i' => $cap])->fetch()
            : db_query("SELECT consignor_id, consignor_cost FROM pos_stock_moves WHERE item_id = :i AND reason = 'receive' ORDER BY id DESC LIMIT 1", [':i' => $cap])->fetch();
```

5. Replace

```php
    check('void: one "void" stock move', $count("SELECT COUNT(*) FROM pos_stock_moves WHERE sale_id = :s AND reason = 'void'", [':s' => $rcId]) === 1);
```

with

```php
    check('void: one "void" stock move', $count($inv
        ? "SELECT COUNT(*) FROM inv_moves WHERE pos_sale_id = :s AND reason = 'void'"
        : "SELECT COUNT(*) FROM pos_stock_moves WHERE sale_id = :s AND reason = 'void'", [':s' => $rcId]) === 1);
```

6. Replace

```php
    $ledger = (int) db_query('SELECT COALESCE(SUM(qty_delta),0) FROM pos_stock_moves WHERE item_id = :i', [':i' => $cap])->fetchColumn();
    check('stock: cached stock_qty equals 10 + the ledger', 10 + $ledger === $stock($cap));
```

with

```php
    if ($inv) {
        $shopLoc = inv_outlet_location_id($shop);
        $ledger  = (int) db_query('SELECT COALESCE(SUM(CASE WHEN to_location_id = :a THEN qty ELSE -qty END), 0)
                                     FROM inv_moves WHERE item_id = :i AND (to_location_id = :b OR from_location_id = :c)',
            [':a' => $shopLoc, ':i' => $capInv, ':b' => $shopLoc, ':c' => $shopLoc])->fetchColumn();
        check('stock: the outlet balance equals its inventory ledger', $ledger === $stock($cap));
        check('stock: pos_items.stock_qty is no longer written (still the seeded 10)',
            (int) db_query('SELECT stock_qty FROM pos_items WHERE id = :i', [':i' => $cap])->fetchColumn() === 10);
        check('stock: a POS product is a sellable inventory item',
            db_query('SELECT item_type FROM inv_items WHERE id = :i', [':i' => $capInv])->fetchColumn() === 'sellable');
        check('stock: a consignment product is a consignment inventory item',
            db_query('SELECT v.item_type FROM inv_items v JOIN pos_items p ON p.inv_item_id = v.id WHERE p.id = :i', [':i' => $brace])->fetchColumn() === 'consignment');
    } else {
        $ledger = (int) db_query('SELECT COALESCE(SUM(qty_delta),0) FROM pos_stock_moves WHERE item_id = :i', [':i' => $cap])->fetchColumn();
        check('stock: cached stock_qty equals 10 + the ledger', 10 + $ledger === $stock($cap));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/pos_logic.php | tail -5`
Expected: `FAIL  DB block threw: Call to undefined function pos_item_stock_on_hand()`.

- [ ] **Step 3: Rewire `includes/pos.php`**

a) Add to the `require_once` block (after the `inventory-support.php` line from Task 2):

```php
require_once __DIR__ . '/inventory.php';           // inv_move() — the ONE stock write path
```

b) In the file header comment, replace the two lines

```
 *   • Stock is a ledger (pos_stock_moves); stock_qty is the cached running total,
 *     updated under SELECT … FOR UPDATE in the same transaction.
```

with

```
 *   • Stock lives in the shared inventory (includes/inventory.php): a tracked item
 *     links to inv_items (pos_items.inv_item_id) and its shelf is the outlet's
 *     inv_locations row. Every change goes through inv_move(). Before
 *     add_inventory.sql runs, the legacy pos_stock_moves / stock_qty path is used.
```

c) Replace the whole `pos_item_select_sql()` function with:

```php
/**
 * Item columns + the linked tour's price, the consignor's commission, and
 * stock_on_hand — the outlet's inventory balance once add_inventory.sql has run
 * (the legacy stock_qty before). Read stock_on_hand, never stock_qty.
 */
function pos_item_select_sql(): string {
    $inv   = inv_supported();
    $stock = $inv ? 'COALESCE(ib.qty, 0) AS stock_on_hand' : 'i.stock_qty AS stock_on_hand';
    $join  = $inv ? " LEFT JOIN inv_locations il ON il.pos_outlet_id = i.outlet_id
                      LEFT JOIN inv_balances ib  ON ib.item_id = i.inv_item_id AND ib.location_id = il.id" : '';
    return "SELECT i.*, {$stock}, c.name AS category_name, o.name AS outlet_name,
                   t.name AS tour_name, t.price_amount AS tour_price, t.price_per_person AS tour_per_person,
                   t.is_published AS tour_published,
                   cs.name AS consignor_name, cs.commission_pct AS consignor_commission_pct
              FROM pos_items i
              JOIN pos_outlets o      ON o.id = i.outlet_id
              LEFT JOIN pos_categories c  ON c.id = i.category_id
              LEFT JOIN tours t           ON t.id = i.tour_id
              LEFT JOIN pos_consignors cs ON cs.id = i.consignor_id{$join}";
}
```

d) In `pos_stock_shortfall()`, replace

```php
    $have = (int)($item['stock_qty'] ?? 0);
```

with

```php
    $have = (int)($item['stock_on_hand'] ?? $item['stock_qty'] ?? 0);
```

e) Replace the whole `// ── Stock ──` section — from the doc comment above `function pos_stock_move(` down to the end of `function pos_stock_moves(...)` — with the following. (`pos_consign_terms()` is included unchanged.)

```php
// ── Stock ───────────────────────────────────────────────────────────────────

/** Run an inventory call and surface its refusal as a PosRefusal — what every POS caller catches. */
function pos_inv(callable $fn): mixed {
    try { return $fn(); }
    catch (PosRefusal $e) { throw $e; }
    catch (InvRefusal $e) { throw new PosRefusal($e->getMessage(), 0, $e); }
}

/** The inventory item behind a tracked POS listing, created (and linked) on first use. */
function pos_item_ensure_inventory(int $posItemId): int {
    return pos_tx(function () use ($posItemId): int {
        $it = db_query('SELECT i.id, i.name, i.sku, i.image_key, i.low_stock_at, i.consignor_id, i.inv_item_id, o.currency
                          FROM pos_items i JOIN pos_outlets o ON o.id = i.outlet_id
                         WHERE i.id = :id FOR UPDATE OF i', [':id' => $posItemId])->fetch();
        if (!$it) throw new PosRefusal('That item no longer exists.');
        if ($it['inv_item_id'] !== null) return (int)$it['inv_item_id'];
        $invId = pos_inv(fn(): int => inv_create_item([
            'name' => $it['name'], 'item_type' => $it['consignor_id'] ? 'consignment' : 'sellable', 'category' => 'Retail',
            'sku' => $it['sku'], 'image_key' => $it['image_key'], 'currency' => $it['currency'],
            'consignor_id' => $it['consignor_id'], 'low_stock_at' => $it['low_stock_at'],
        ]));
        db_query('UPDATE pos_items SET inv_item_id = :v WHERE id = :id', [':v' => $invId, ':id' => $posItemId]);
        return $invId;
    });
}

/**
 * Ensure the link, then copy the listing's name, SKU, photo, low-stock alert and
 * consignor onto its inventory item. A sellable/consignment item flips between
 * those two types with the consignor; any other type is left as the owner set it.
 */
function pos_item_sync_inventory(int $posItemId): int {
    $invId = pos_item_ensure_inventory($posItemId);
    db_query("UPDATE inv_items v
                 SET name = i.name, sku = i.sku, image_key = i.image_key, low_stock_at = i.low_stock_at,
                     consignor_id = i.consignor_id,
                     item_type = CASE WHEN v.item_type IN ('sellable', 'consignment')
                                      THEN CASE WHEN i.consignor_id IS NULL THEN 'sellable' ELSE 'consignment' END
                                      ELSE v.item_type END,
                     updated_at = now()
                FROM pos_items i
               WHERE i.id = :p AND v.id = i.inv_item_id", [':p' => $posItemId]);
    return $invId;
}

/** On-hand stock of a POS listing at its outlet. With $lock, the balance row is locked (call inside pos_tx()). */
function pos_item_stock_on_hand(int $posItemId, bool $lock = false): int {
    if (!inv_supported()) {
        $q = db_query('SELECT stock_qty FROM pos_items WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), [':id' => $posItemId])->fetchColumn();
        return $q === false ? 0 : (int)$q;
    }
    $it = db_query('SELECT outlet_id, inv_item_id FROM pos_items WHERE id = :id', [':id' => $posItemId])->fetch();
    if (!$it || $it['inv_item_id'] === null) return 0;
    $loc = pos_inv(fn(): int => inv_outlet_location_id((int)$it['outlet_id']));
    return $lock ? inv_balance_lock((int)$it['inv_item_id'], $loc) : inv_balance((int)$it['inv_item_id'], $loc);
}

/**
 * Write one stock movement for a POS listing. Refuses (PosRefusal) a move that
 * would take a non-negative item below zero. Returns the new on-hand quantity.
 * Once inventory is installed this is a thin wrapper over inv_move() at the
 * outlet's location; before that it writes the legacy pos_stock_moves ledger.
 */
function pos_stock_move(int $itemId, int $delta, string $reason, ?int $saleId = null, ?float $unitCost = null, string $note = '', ?int $userId = null, array $terms = []): int {
    if (!in_array($reason, ['receive','sale','void','adjust','return'], true)) throw new InvalidArgumentException('bad stock reason');
    if (inv_supported()) return pos_stock_move_inv($itemId, $delta, $reason, $saleId, $unitCost, $note, $userId, $terms);
    return pos_tx(function () use ($itemId, $delta, $reason, $saleId, $unitCost, $note, $userId, $terms): int {
        $it = db_query('SELECT id, name, track_stock, stock_qty, allow_negative FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetch();
        if (!$it) throw new PosRefusal('That item no longer exists.');
        if (!pos_bool($it['track_stock'])) throw new PosRefusal("{$it['name']} does not track stock.");
        $new = (int)$it['stock_qty'] + $delta;
        if ($new < 0 && !pos_bool($it['allow_negative'])) {
            throw new PosRefusal(pos_stock_shortfall($it, -$delta) ?? "Not enough stock of {$it['name']}.");
        }
        $p = [':i' => $itemId, ':d' => $delta, ':r' => $reason, ':s' => $saleId, ':c' => $unitCost,
              ':n' => $note !== '' ? mb_substr($note, 0, 500) : null, ':u' => $userId ?: null];
        if ($terms && pos_v2_supported()) {
            db_query('INSERT INTO pos_stock_moves (item_id, qty_delta, reason, sale_id, unit_cost, note, admin_user_id, consignor_id, consign_pct, consignor_cost)
                      VALUES (:i, :d, :r, :s, :c, :n, :u, :ci, :cp, :cc)',
                $p + [':ci' => $terms['consignor_id'] ?? null, ':cp' => $terms['consign_pct'] ?? null, ':cc' => $terms['consignor_cost'] ?? null]);
        } else {
            db_query('INSERT INTO pos_stock_moves (item_id, qty_delta, reason, sale_id, unit_cost, note, admin_user_id)
                      VALUES (:i, :d, :r, :s, :c, :n, :u)', $p);
        }
        db_query('UPDATE pos_items SET stock_qty = :q, updated_at = now() WHERE id = :id', [':q' => $new, ':id' => $itemId]);
        return $new;
    });
}

/** pos_stock_move() with inventory installed: the outlet's inventory location is the shelf. */
function pos_stock_move_inv(int $itemId, int $delta, string $reason, ?int $saleId, ?float $unitCost, string $note, ?int $userId, array $terms): int {
    return pos_tx(function () use ($itemId, $delta, $reason, $saleId, $unitCost, $note, $userId, $terms): int {
        $it = db_query('SELECT id, name, outlet_id, track_stock, allow_negative FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetch();
        if (!$it) throw new PosRefusal('That item no longer exists.');
        if (!pos_bool($it['track_stock'])) throw new PosRefusal("{$it['name']} does not track stock.");
        $invId = pos_item_ensure_inventory($itemId);
        $loc   = pos_inv(fn(): int => inv_outlet_location_id((int)$it['outlet_id']));
        if ($delta === 0) return inv_balance($invId, $loc);
        $in = $delta > 0;
        $invReason = match ($reason) {
            'receive', 'return' => 'receive',
            'sale'   => 'sale',
            'void'   => 'void',
            'adjust' => $in ? 'found' : 'missing',
        };
        pos_inv(fn(): int => inv_move([
            'item_id' => $invId, 'qty' => abs($delta),
            'from' => $in ? null : $loc, 'to' => $in ? $loc : null,
            'reason' => $invReason, 'user_id' => $userId, 'note' => $note,
            'pos_sale_id' => $saleId, 'unit_value' => $unitCost, 'terms' => $terms,
            'allow_negative' => pos_bool($it['allow_negative']),
        ]));
        return inv_balance($invId, $loc);
    });
}

/**
 * Consignment terms for a delivery, from the receive form — PURE.
 *   ['mode' => 'own']                                         our own stock
 *   ['mode' => 'commission', 'consignor_id' => 3, 'value' => 20]   we keep 20%
 *   ['mode' => 'fixed',      'consignor_id' => 3, 'value' => 800]  we owe 800 per unit
 * Returns ['consignor_id','consign_pct','consignor_cost'] or an error string.
 */
function pos_consign_terms(array $in, array $consignorIds): array|string {
    $mode = (string)($in['mode'] ?? 'own');
    if ($mode === 'own' || $mode === '') return ['consignor_id' => null, 'consign_pct' => null, 'consignor_cost' => null];
    if (!in_array($mode, ['commission', 'fixed'], true)) return 'Pick how this supplier is paid.';
    $cid = (int)($in['consignor_id'] ?? 0);
    if (!$cid || !in_array($cid, $consignorIds, true)) return 'Pick the supplier these goods belong to.';
    $v = $in['value'] ?? '';
    if ($v === '' || !is_numeric($v) || (float)$v < 0) return $mode === 'commission' ? 'Enter the commission % we keep.' : 'Enter what we owe per item.';
    if ($mode === 'commission' && (float)$v > 100) return 'Commission must be 100% or less.';
    return $mode === 'commission'
        ? ['consignor_id' => $cid, 'consign_pct' => round((float)$v, 2), 'consignor_cost' => null]
        : ['consignor_id' => $cid, 'consign_pct' => null, 'consignor_cost' => round((float)$v, 2)];
}

/**
 * Receive a delivery. With $terms (pos_consign_terms()), the item's consignment
 * terms are set to what the stock manager chose for THIS delivery, and the ledger
 * row records them. Sales from then on follow the new terms; past sales keep the
 * terms they were sold on (snapshotted on the sale line).
 */
function pos_stock_receive(int $itemId, int $qty, ?float $unitCost, string $note, ?int $userId, ?array $terms = null): int {
    if ($qty < 1) throw new PosRefusal('Enter how many arrived (at least 1).');
    if ($unitCost !== null && $unitCost < 0) throw new PosRefusal('Unit cost cannot be negative.');
    return pos_tx(function () use ($itemId, $qty, $unitCost, $note, $userId, $terms): int {
        if ($terms !== null && pos_v2_supported()) {
            db_query('UPDATE pos_items SET consignor_id = :c, consign_pct = :p, consignor_cost = :k, updated_at = now() WHERE id = :i',
                [':c' => $terms['consignor_id'], ':p' => $terms['consign_pct'], ':k' => $terms['consignor_cost'], ':i' => $itemId]);
            if (inv_supported()) pos_item_sync_inventory($itemId);
        }
        return pos_stock_move($itemId, $qty, 'receive', null, $unitCost, $note, $userId, $terms ?? []);
    });
}

/** Count correction: set the stock to what is physically on the shelf. Reason required. */
function pos_stock_adjust(int $itemId, int $counted, string $note, ?int $userId): int {
    if ($counted < 0) throw new PosRefusal('A counted quantity cannot be negative.');
    if (trim($note) === '') throw new PosRefusal('Say why the count changed (e.g. "stock take", "damaged").');
    return pos_tx(function () use ($itemId, $counted, $note, $userId): int {
        if (db_query('SELECT id FROM pos_items WHERE id = :id FOR UPDATE', [':id' => $itemId])->fetchColumn() === false) {
            throw new PosRefusal('That item no longer exists.');
        }
        $cur   = pos_item_stock_on_hand($itemId, true);
        $delta = $counted - $cur;
        if ($delta === 0) return $cur;
        return pos_stock_move($itemId, $delta, 'adjust', null, null, $note, $userId);
    });
}

/**
 * Stock history of a POS listing, newest first — rows carry qty_delta (signed, at
 * this outlet), reason, note, unit_cost, sale_reference, user_name, consignor_*.
 */
function pos_stock_moves(int $itemId, int $limit = 100): array {
    if (!pos_supported()) return [];
    if (inv_supported()) {
        $it = db_query('SELECT outlet_id, inv_item_id FROM pos_items WHERE id = :id', [':id' => $itemId])->fetch();
        if (!$it || $it['inv_item_id'] === null) return [];
        $loc = pos_inv(fn(): int => inv_outlet_location_id((int)$it['outlet_id']));
        return array_map(fn(array $m): array => $m + [
            'qty_delta' => ($m['to_location_id'] !== null && (int)$m['to_location_id'] === $loc) ? (int)$m['qty'] : -(int)$m['qty'],
            'unit_cost' => $m['unit_value'],
        ], inv_item_moves((int)$it['inv_item_id'], $loc, $limit));
    }
    return db_query(
        "SELECT m.*, a.name AS user_name, s.reference AS sale_reference" . (pos_v2_supported() ? ", c.name AS consignor_name" : '') . "
           FROM pos_stock_moves m
           LEFT JOIN admin_users a ON a.id = m.admin_user_id
           LEFT JOIN pos_sales s   ON s.id = m.sale_id" . (pos_v2_supported() ? " LEFT JOIN pos_consignors c ON c.id = m.consignor_id" : '') . "
          WHERE m.item_id = :i ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, min(500, $limit)),
        [':i' => $itemId]
    )->fetchAll();
}

/** An item moved to another outlet takes its shelf stock with it (a transfer between the two outlet locations). */
function pos_item_move_stock(int $posItemId, int $fromOutletId, int $toOutletId, ?int $userId): void {
    if (!inv_supported()) return;   // legacy: stock_qty lives on the row and moves with it
    $invId = pos_item_ensure_inventory($posItemId);
    $from  = pos_inv(fn(): int => inv_outlet_location_id($fromOutletId));
    $to    = pos_inv(fn(): int => inv_outlet_location_id($toOutletId));
    $qty   = inv_balance($invId, $from);
    if ($qty > 0) {
        pos_inv(fn(): int => inv_move(['item_id' => $invId, 'qty' => $qty, 'from' => $from, 'to' => $to,
                                       'reason' => 'transfer', 'user_id' => $userId, 'note' => 'Item moved to another outlet']));
    }
}
```

f) In `pos_void_sale()`, replace

```php
            $moves = db_query("SELECT item_id, SUM(qty_delta) AS d FROM pos_stock_moves WHERE sale_id = :s AND reason = 'sale' GROUP BY item_id ORDER BY item_id", [':s' => $saleId])->fetchAll();
            foreach ($moves as $m) {
                // Put back exactly what the sale took, even if tracking was turned off since.
                pos_stock_restore((int)$m['item_id'], -(int)$m['d'], $saleId, (string)$sale['reference'], $userId);
            }
```

with

```php
            if (inv_supported()) {
                // Put back exactly what the sale took, to where it took it from.
                $moves = db_query("SELECT item_id, from_location_id, SUM(qty) AS q FROM inv_moves
                                    WHERE pos_sale_id = :s AND reason = 'sale'
                                    GROUP BY item_id, from_location_id ORDER BY item_id, from_location_id", [':s' => $saleId])->fetchAll();
                foreach ($moves as $m) {
                    pos_inv(fn(): int => inv_move(['item_id' => (int)$m['item_id'], 'qty' => (int)$m['q'], 'to' => (int)$m['from_location_id'],
                                                   'reason' => 'void', 'pos_sale_id' => $saleId, 'user_id' => $userId,
                                                   'note' => 'Void ' . $sale['reference']]));
                }
            } else {
                $moves = db_query("SELECT item_id, SUM(qty_delta) AS d FROM pos_stock_moves WHERE sale_id = :s AND reason = 'sale' GROUP BY item_id ORDER BY item_id", [':s' => $saleId])->fetchAll();
                foreach ($moves as $m) {
                    // Put back exactly what the sale took, even if tracking was turned off since.
                    pos_stock_restore((int)$m['item_id'], -(int)$m['d'], $saleId, (string)$sale['reference'], $userId);
                }
            }
```

g) In `pos_catalog_payload()`, replace

```php
            'stock'          => pos_bool($it['track_stock']) ? (int)$it['stock_qty'] : null,
```

with

```php
            'stock'          => pos_bool($it['track_stock']) ? (int)$it['stock_on_hand'] : null,
```

h) Replace the whole `pos_low_stock()` function with:

```php
/** Tracked items at these outlets that are out of stock or at/below their alert, lowest first. */
function pos_low_stock(array $outletIds): array {
    if (!pos_supported() || !$outletIds) return [];
    $ph = []; $p = [];
    foreach (array_values($outletIds) as $i => $v) { $ph[] = ":o{$i}"; $p[":o{$i}"] = (int)$v; }
    $rows = db_query(pos_item_select_sql() . ' WHERE i.outlet_id IN (' . implode(',', $ph) . ') AND i.is_active = TRUE AND i.track_stock = TRUE', $p)->fetchAll();
    $low = array_values(array_filter($rows, fn(array $r): bool =>
        (int)$r['stock_on_hand'] <= 0 || ($r['low_stock_at'] !== null && (int)$r['stock_on_hand'] <= (int)$r['low_stock_at'])));
    usort($low, fn(array $a, array $b): int => [(int)$a['stock_on_hand'], $a['outlet_name'], $a['name']] <=> [(int)$b['stock_on_hand'], $b['outlet_name'], $b['name']]);
    return array_map(fn(array $r): array => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'stock_qty' => (int)$r['stock_on_hand'],
        'low_stock_at' => $r['low_stock_at'], 'outlet_id' => (int)$r['outlet_id'], 'outlet' => $r['outlet_name'],
    ], $low);
}
```

- [ ] **Step 4: Run both test files**

Run: `php tests/pos_logic.php | tail -3 && php tests/inventory_logic.php | tail -3`
Expected: `ALL PASS` twice. `pos_logic.php` now has 200+ checks (the 196 originals plus the new inventory assertions).

- [ ] **Step 5: Commit**

```bash
git add includes/pos.php tests/pos_logic.php
git commit -m "feat(pos): stock lives in the shared inventory — sales, voids, receives and counts go through inv_move()

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: POS admin pages read inventory stock

**Files:**
- Modify: `admin/pos-items.php`
- Modify: `admin/pos-stock.php`

- [ ] **Step 1: Read on-hand stock from inventory on both pages**

Run:
```bash
sed -i '' "s/\['stock_qty'\]/['stock_on_hand']/g" admin/pos-items.php admin/pos-stock.php && grep -n "stock_qty\|stock_on_hand" admin/pos-items.php admin/pos-stock.php
```
Expected: every array read is now `['stock_on_hand']`; the only remaining `stock_qty` mentions are in the header comments. (On Linux use `sed -i` without `''`.)

- [ ] **Step 2: Update the header comments**

In `admin/pos-items.php`, replace the comment line
` * Stock is NEVER edited here — stock_qty is the cached total of the stock ledger,`
with
` * Stock is NEVER edited here — on-hand stock is the outlet's inventory balance,`

In `admin/pos-stock.php`, replace
```
 * Stock is a LEDGER: every change is a pos_stock_moves row and pos_items.stock_qty
 * is its cached total, written together under a row lock (pos_stock_move()).
 * Nothing here edits stock_qty directly. A count correction records the
 * difference as an "adjust" move with a required reason.
```
with
```
 * Stock is the outlet's shelf in the shared inventory: every change is an
 * inv_moves row written by inv_move() (via pos_stock_move()). Nothing here edits
 * a quantity directly. A count correction records the difference as a
 * found/missing move with a required reason.
```

- [ ] **Step 3: Label inventory reasons in the stock history**

In `admin/pos-stock.php` replace

```php
$REASONS = ['receive' => ['Received', 'badge--green'], 'sale' => ['Sale', 'badge--blue'], 'void' => ['Void', 'badge--orange'],
            'adjust' => ['Count', 'badge--purple'], 'return' => ['Return', 'badge--teal']];
```

with

```php
$REASONS = ['receive' => ['Received', 'badge--green'], 'opening' => ['Opening', 'badge--green'], 'sale' => ['Sale', 'badge--blue'],
            'void' => ['Void', 'badge--orange'], 'adjust' => ['Count', 'badge--purple'], 'found' => ['Count +', 'badge--purple'],
            'missing' => ['Count −', 'badge--purple'], 'return' => ['Return', 'badge--teal'], 'transfer' => ['Transfer', 'badge--teal'],
            'assign' => ['Assigned', 'badge--teal'], 'replaced' => ['Replacement', 'badge--teal'], 'broken' => ['Broken', 'badge--red'],
            'stolen' => ['Stolen', 'badge--red'], 'written_off' => ['Written off', 'badge--red']];
```

- [ ] **Step 4: Link tracked listings to inventory on save, and carry stock when a listing changes outlet**

In `admin/pos-items.php`, inside the `$newId = pos_tx(function () use (...)` closure:

Replace

```php
                if ($moveTo) {
                    // Categories belong to an outlet, so a moved item starts uncategorised
                    // at the end of its new outlet. Its stock and sales history move with it;
                    // past sale lines keep the outlet they were sold for.
                    $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_items WHERE outlet_id = :o', [':o' => $moveTo])->fetchColumn();
                    db_query('UPDATE pos_items SET outlet_id = :t, category_id = NULL, sort_order = :s WHERE id = :id', [':t' => $moveTo, ':s' => $max + 1, ':id' => $iid]);
                }
                return $iid;
```

with

```php
                if ($v['track_stock'] && inv_supported()) pos_item_sync_inventory($iid);
                if ($moveTo) {
                    // Categories belong to an outlet, so a moved item starts uncategorised
                    // at the end of its new outlet. Its stock and sales history move with it;
                    // past sale lines keep the outlet they were sold for.
                    if ($v['track_stock']) pos_item_move_stock($iid, $oid, $moveTo, (int)$me['id']);
                    $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_items WHERE outlet_id = :o', [':o' => $moveTo])->fetchColumn();
                    db_query('UPDATE pos_items SET outlet_id = :t, category_id = NULL, sort_order = :s WHERE id = :id', [':t' => $moveTo, ':s' => $max + 1, ':id' => $iid]);
                }
                return $iid;
```

and replace

```php
            $id = (int) db()->lastInsertId();
            if ($v['track_stock'] && $opening !== '' && (int)$opening > 0) {
```

with

```php
            $id = (int) db()->lastInsertId();
            if ($v['track_stock'] && inv_supported()) pos_item_sync_inventory($id);
            if ($v['track_stock'] && $opening !== '' && (int)$opening > 0) {
```

(`pos_item_move_stock()` runs BEFORE `outlet_id` changes because it reads the listing's link, not its outlet; both outlet ids are passed explicitly.)

- [ ] **Step 5: Lint and exercise the pages in the browser**

Run: `php -l admin/pos-items.php && php -l admin/pos-stock.php && php -l includes/pos.php && php -l includes/inventory.php`
Expected: `No syntax errors detected` ×4.

Then start the dev server (`preview_start` — add a `.claude/launch.json` entry `php -S localhost:8765` if one does not exist), sign in as the owner, and:
1. Admin → Point of Sale → Items: create a product "Test Tee" with stock tracking on and opening stock 5 → the list shows **5** on hand.
2. Admin → POS stock → Test Tee: receive 3 → **8**; count 7 with reason "stock take" → **7**; the history shows *Opening*/*Received*/*Count −* rows.
3. Open the till (`/pos/`), sell 2 Test Tee → the till shows **5 in stock** after the sale.
4. `psql -d tribalsand -Atc "SELECT reason, qty FROM inv_moves m JOIN pos_items p ON p.inv_item_id = m.item_id WHERE p.name = 'Test Tee' ORDER BY m.id"` → `receive|5`, `receive|3`, `missing|1`, `sale|2`.
5. Delete Test Tee from the catalogue afterwards.

- [ ] **Step 6: Commit**

```bash
git add admin/pos-items.php admin/pos-stock.php
git commit -m "feat(pos): admin catalogue + stock pages read and write the shared inventory

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Document the rules and run everything

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the section**

In `CLAUDE.md`, insert directly after the whole `### Point of Sale — outlets, one sale path, room charge onto the bill` section (before the next `###` heading):

```markdown
### Inventory & Assets — ONE inventory database under POS, properties, staff and central stock
Migration `add_inventory.sql` (after `add_pos_v2.sql` + `add_hr_staff.sql`). Logic **`includes/inventory.php`**; the light guard `inv_supported()`, the refusal type `InvRefusal` and the ONE transaction helper `inv_tx()` live in **`includes/inventory-support.php`** (`pos_tx()` delegates to it; `PosRefusal extends InvRefusal`). Spec: `docs/superpowers/specs/2026-09-27-inventory-assets-design.md`. Test: `php tests/inventory_logic.php` (pure always; DB block rolled back, SKIPs with no DB/migration).
- **`inv_move()` is the ONLY writer of `inv_moves` and `inv_balances`.** It locks both balance rows in location-id order, refuses going below zero (only a POS listing flagged `allow_negative` passes `allow_negative`), snapshots `unit_value`/`value`/`currency`, and updates both balances in one transaction. Compound actions (`inv_replace()`, `inv_restock_to_par()`, count resolution) are several `inv_move()` calls inside one `inv_tx()`. Never `UPDATE inv_balances.qty` anywhere else — `par_qty` (a target, not a quantity) is the one column edited directly (`inv_set_par()`).
- **Move shape is the reason:** `from = NULL` = stock entering (receive/found/opening/void), `to = NULL` = leaving (sale/broken/missing/stolen/written_off), both = transfer/assign/return/replaced. Moves are append-only — a correction is a new move.
- **A location's `venue_id` is its OWNING venue for every kind** (property; area → its property's; outlet → the outlet's; person → home venue; Main stock = NULL, shared). That is the accounting seam (venue → company later) and the scope rule: `inv_move_in_scope()` lets a manager move only between their own locations and shared ones, with at least one end their own. "Assigned to Jane" = stock at Jane's `kind='person'` location (`inv_person_location_id()`).
- **Serial-tracked items** (`tracking='serial'`) move one unit at a time with `asset_id`; the unit must be at `from`; a unit already in stock cannot come in again. Invariant: balance at L = active units at L.
- **Counting never moves stock.** `inv_count_start()` snapshots `expected`; `inv_count_submit()` saves numbers (matches auto-accept); a manager's `inv_count_resolve_line()` writes the move — refused while the live balance differs from `expected` (recount instead). A line resolves once.
- **POS on top:** a stock-tracked `pos_items` row links to `inv_items` via `pos_items.inv_item_id` (created on first use by `pos_item_ensure_inventory()`); its shelf is the outlet's `inv_locations` row (`pos_outlet_id`, UNIQUE). `pos_item_select_sql()` exposes **`stock_on_hand`** — read that, never `stock_qty`, which is no longer written (kept one release, then dropped). The legacy `pos_stock_moves` path runs only when `inv_supported()` is false. Inventory refusals inside POS paths are converted by `pos_inv()`.
- **Money is never summed across currencies** — `inv_sum_by_currency()`.
```

- [ ] **Step 2: Run the full relevant test set**

Run:
```bash
php tests/inventory_logic.php | tail -2 && php tests/pos_logic.php | tail -2 && php tests/team_logic.php | tail -2
```
Expected: `ALL PASS`, `ALL PASS`, and `1 FAILURE(S)` for team_logic — the known pre-existing `owner: home = dashboard` failure (owner lands on Front Desk since commit `f2bf3e9`); nothing new.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(inventory): CLAUDE.md — the one inventory write path and the POS link

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Production rollout (after merge — not part of this plan's execution)

Apply `add_inventory.sql` on the production database via `/admin/migrate.php`, **after** the three POS migrations. Until it runs, every surface keeps the POS's legacy stock behaviour (`inv_supported()` is false).
