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
