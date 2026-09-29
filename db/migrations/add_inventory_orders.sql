-- Inventory orders — an Excel import goes ON ORDER; people then record how many
-- arrived and where they were put. Run AFTER add_inventory.sql. Idempotent.
--
-- Load-bearing rules (see CLAUDE.md "Inventory & Assets"):
--   • An order never touches stock. Stock enters only when a receipt is recorded,
--     through inv_move() / inv_asset_create() (includes/inventory-orders.php).
--   • ONE order line per item + planned place (quantities of the same item and place
--     are summed at import); qty_received grows with each receipt row.
--   • inv_order_receipts is the append-only trail: how many, into which place, who, when.
--   • inv_order_line_containers are HINTS from the packing-list sheets (which container a
--     line is in, how many pieces) — they never change qty_ordered, only drive the
--     container filter and "Receive this container". `seq` keeps the sheets' order.
--   • inv_order_lines.hs_code is the customs code from the master list; inv_order_packing
--     keeps EVERY row of the packing-list sheets (boxes, L x W x H in metres, weight in kg,
--     cubes in m3 — matched or not, continuation boxes and footer totals included) so the
--     whole spreadsheet is in the system. line_id links a row to the order line it was
--     matched to (NULL = not on the master list). Like the hints, it never changes qty_ordered.

CREATE TABLE IF NOT EXISTS inv_orders (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(160) NOT NULL,
    source_filename VARCHAR(200),
    fingerprint     VARCHAR(40),
    status          VARCHAR(10)  NOT NULL DEFAULT 'open' CHECK (status IN ('open','partial','received','cancelled')),
    created_by      INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_inv_orders_status ON inv_orders (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_inv_orders_fingerprint ON inv_orders (fingerprint);

CREATE TABLE IF NOT EXISTS inv_order_lines (
    id                  SERIAL PRIMARY KEY,
    order_id            INT NOT NULL REFERENCES inv_orders(id) ON DELETE CASCADE,
    sort_order          INT NOT NULL DEFAULT 0,
    item_id             INT NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
    code                VARCHAR(40),
    description         TEXT NOT NULL,
    section             VARCHAR(120),
    qty_ordered         INT NOT NULL CHECK (qty_ordered > 0),
    qty_received        INT NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    planned_location_id INT REFERENCES inv_locations(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_inv_order_lines_order ON inv_order_lines (order_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_inv_order_lines_item  ON inv_order_lines (item_id);

-- Created last: inv_orders_supported() probes this table, so a run that dies
-- partway through never reads as done.
CREATE TABLE IF NOT EXISTS inv_order_receipts (
    id            SERIAL PRIMARY KEY,
    line_id       INT NOT NULL REFERENCES inv_order_lines(id) ON DELETE CASCADE,
    qty           INT NOT NULL CHECK (qty > 0),
    location_id   INT NOT NULL REFERENCES inv_locations(id),
    admin_user_id INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_inv_order_receipts_line ON inv_order_receipts (line_id);

-- Packing-list hints: which container each order line travels in. Created after the
-- receipts table; inv_order_containers_supported() probes it separately, so orders
-- keep working on a database that has not run this part yet.
CREATE TABLE IF NOT EXISTS inv_order_line_containers (
    line_id   INT          NOT NULL REFERENCES inv_order_lines(id) ON DELETE CASCADE,
    container VARCHAR(80)  NOT NULL,
    qty       INT          NOT NULL CHECK (qty > 0),
    seq       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (line_id, container)
);

-- The customs (HS) code from the master list, one per order line.
ALTER TABLE inv_order_lines ADD COLUMN IF NOT EXISTS hs_code VARCHAR(20);

-- The packing lists in full, one row per spreadsheet row, in sheet order (seq counts per
-- container). kind: row (has an item code) | continuation (no code — an extra box of the
-- row above) | note (a heading line) | total (the sheet's own footer total; the page
-- shows it as "sheet total" and never adds it to the computed totals).
-- Created last: inv_order_packing_supported() probes this table AND hs_code.
CREATE TABLE IF NOT EXISTS inv_order_packing (
    id          SERIAL PRIMARY KEY,
    order_id    INT          NOT NULL REFERENCES inv_orders(id) ON DELETE CASCADE,
    container   VARCHAR(80)  NOT NULL,
    seq         INT          NOT NULL,
    sheet       VARCHAR(80),
    row_no      INT,
    code        VARCHAR(40),
    description TEXT,
    qty         INT,
    boxes       INT,
    length_m    NUMERIC(8,3),
    width_m     NUMERIC(8,3),
    height_m    NUMERIC(8,3),
    weight_kg   NUMERIC(10,2),
    cubes_m3    NUMERIC(10,4),
    kind        VARCHAR(12)  NOT NULL DEFAULT 'row' CHECK (kind IN ('row','continuation','note','total')),
    line_id     INT REFERENCES inv_order_lines(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_inv_order_packing_order ON inv_order_packing (order_id, container, seq);
