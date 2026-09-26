-- Tribal Sand POS v2 — VAT, tips, room-charge properties, KES→bill FX, guest
-- signatures, consignment terms per delivery. Run via /admin/migrate.php AFTER
-- add_pos.sql. Idempotent. The code is pre-migration-safe (pos_v2_supported()).

-- ── Outlet settings ─────────────────────────────────────────────────────────
ALTER TABLE pos_outlets ADD COLUMN IF NOT EXISTS vat_pct NUMERIC(5,2) NOT NULL DEFAULT 0;
ALTER TABLE pos_outlets ADD COLUMN IF NOT EXISTS vat_inclusive BOOLEAN NOT NULL DEFAULT TRUE;     -- Kenyan shelf prices include VAT
ALTER TABLE pos_outlets ADD COLUMN IF NOT EXISTS tips_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE pos_outlets ADD COLUMN IF NOT EXISTS require_signature BOOLEAN NOT NULL DEFAULT TRUE; -- guest signs a room charge
ALTER TABLE pos_outlets DROP CONSTRAINT IF EXISTS pos_outlets_vat_pct_check;
ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_vat_pct_check CHECK (vat_pct >= 0 AND vat_pct <= 100);

-- Which properties' guests may room-charge at an outlet. NO rows = every property.
CREATE TABLE IF NOT EXISTS pos_outlet_charge_venues (
    outlet_id INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    venue_id  INT NOT NULL REFERENCES venues(id)      ON DELETE CASCADE,
    PRIMARY KEY (outlet_id, venue_id)
);

-- ── Consignment terms per item (set when a delivery is received) ────────────
-- consign_pct = the commission WE keep on this item; NULL = the supplier's default.
-- consignor_cost (from add_pos.sql) = a fixed amount per unit we owe; it wins when set.
ALTER TABLE pos_items ADD COLUMN IF NOT EXISTS consign_pct NUMERIC(5,2);
ALTER TABLE pos_items DROP CONSTRAINT IF EXISTS pos_items_consign_pct_check;
ALTER TABLE pos_items ADD CONSTRAINT pos_items_consign_pct_check CHECK (consign_pct IS NULL OR (consign_pct >= 0 AND consign_pct <= 100));

-- The terms a delivery was received on (audit trail on the stock ledger).
ALTER TABLE pos_stock_moves ADD COLUMN IF NOT EXISTS consignor_id INT REFERENCES pos_consignors(id) ON DELETE SET NULL;
ALTER TABLE pos_stock_moves ADD COLUMN IF NOT EXISTS consign_pct NUMERIC(5,2);
ALTER TABLE pos_stock_moves ADD COLUMN IF NOT EXISTS consignor_cost NUMERIC(10,2);

-- ── Sale snapshots ──────────────────────────────────────────────────────────
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS service_pct   NUMERIC(5,2)  NOT NULL DEFAULT 0;
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS vat_pct       NUMERIC(5,2)  NOT NULL DEFAULT 0;
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS vat_inclusive BOOLEAN       NOT NULL DEFAULT TRUE;
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS vat_amount    NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS tip_amount    NUMERIC(12,2) NOT NULL DEFAULT 0;
-- Room charge from an outlet whose currency differs from the bill (e.g. a KES shop
-- onto a USD bill): the amount posted, in the bill's currency, and the rate used
-- (units of the SALE currency per 1 unit of the BILL currency, e.g. 129.0).
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS bill_currency CHAR(3);
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS bill_amount   NUMERIC(12,2);
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS fx_rate       NUMERIC(14,6);
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS guest_venue_id INT REFERENCES venues(id) ON DELETE SET NULL;  -- the guest's property

-- The guest's signature for a room charge (PNG data URL, private — shown only on
-- the authenticated receipt and admin sale page).
CREATE TABLE IF NOT EXISTS pos_sale_signatures (
    sale_id     INT PRIMARY KEY REFERENCES pos_sales(id) ON DELETE CASCADE,
    signature   TEXT NOT NULL,
    signer_name VARCHAR(160),
    ip          VARCHAR(45),
    user_agent  VARCHAR(300),
    signed_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
