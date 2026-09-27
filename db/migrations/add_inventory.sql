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

-- ── Backstops: a move's shape matches its reason; a link column only on its kind ──
ALTER TABLE inv_moves DROP CONSTRAINT IF EXISTS inv_moves_reason_shape_check;
ALTER TABLE inv_moves ADD CONSTRAINT inv_moves_reason_shape_check CHECK (
       (reason IN ('receive','found','opening','void')                   AND from_location_id IS NULL     AND to_location_id IS NOT NULL)
    OR (reason IN ('sale','broken','missing','stolen','written_off')     AND from_location_id IS NOT NULL AND to_location_id IS NULL)
    OR (reason IN ('transfer','assign','return','replaced')              AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL));
ALTER TABLE inv_locations DROP CONSTRAINT IF EXISTS inv_locations_link_kind_check;
ALTER TABLE inv_locations ADD CONSTRAINT inv_locations_link_kind_check CHECK (
    (pos_outlet_id IS NULL OR kind = 'outlet') AND (hr_staff_id IS NULL OR kind = 'person'));

-- ── Default locations ───────────────────────────────────────────────────────
INSERT INTO inv_locations (kind, name, sort_order)
SELECT 'store', 'Main stock', 0
 WHERE NOT EXISTS (SELECT 1 FROM inv_locations WHERE kind = 'store');

INSERT INTO inv_locations (kind, name, venue_id, sort_order)
SELECT 'property', LEFT(v.name, 120), v.id, 10 + v.sort_order
  FROM venues v
 WHERE v.is_published = TRUE
   AND NOT EXISTS (SELECT 1 FROM inv_locations l WHERE l.kind = 'property' AND l.venue_id = v.id);

INSERT INTO inv_locations (kind, name, pos_outlet_id, venue_id, sort_order)
SELECT 'outlet', LEFT(o.name, 120), o.id, o.venue_id, 100 + o.sort_order
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
