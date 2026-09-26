-- Tribal Sand POS — outlets, catalogue, stock ledger, sales, terminals.
-- Run via /admin/migrate.php. Idempotent. Spec: docs/pos/POS-PLAN.md §4.
--
-- Load-bearing rules (see CLAUDE.md "Point of Sale"):
--   • A sale is single-currency (its outlet's) and immutable; corrections are a
--     VOID, never an edit.
--   • pos_stock_moves is the stock source of truth; pos_items.stock_qty is a
--     cached running total written in the same transaction.
--   • A room charge is ONE bill_items row carrying pos_sale_id, so it shows on the
--     admin Bill tab, the printed bill and the guest portal with no new code.

-- ── Outlets ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_outlets (
    id                 SERIAL PRIMARY KEY,
    name               VARCHAR(120) NOT NULL,
    slug               VARCHAR(60)  NOT NULL UNIQUE,
    kind               VARCHAR(20)  NOT NULL DEFAULT 'other'
                       CHECK (kind IN ('experiences','shop','salon_spa','kite','other')),
    venue_id           INT REFERENCES venues(id) ON DELETE SET NULL,   -- NULL = shared by every property
    currency           CHAR(3)      NOT NULL DEFAULT 'USD',
    service_charge_pct NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (service_charge_pct >= 0 AND service_charge_pct <= 100),
    allow_room_charge  BOOLEAN      NOT NULL DEFAULT TRUE,
    next_ref           INT          NOT NULL DEFAULT 1001,              -- per-outlet receipt sequence
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order         INT          NOT NULL DEFAULT 0,
    created_at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Staff explicitly allowed to sell at an outlet (owner/managers are implicit).
CREATE TABLE IF NOT EXISTS pos_outlet_staff (
    outlet_id     INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    admin_user_id INT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    PRIMARY KEY (outlet_id, admin_user_id)
);

-- Cross-sell: outlet_id may ALSO sell source_outlet_id's items. The sale line
-- keeps the owning outlet so revenue and stock attribute correctly.
CREATE TABLE IF NOT EXISTS pos_outlet_links (
    outlet_id        INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    source_outlet_id INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    PRIMARY KEY (outlet_id, source_outlet_id),
    CHECK (outlet_id <> source_outlet_id)
);

CREATE TABLE IF NOT EXISTS pos_categories (
    id         SERIAL PRIMARY KEY,
    outlet_id  INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    name       VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_pos_categories_outlet ON pos_categories (outlet_id, sort_order);

-- ── Consignment suppliers ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_consignors (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    phone          VARCHAR(40),
    email          VARCHAR(160),
    commission_pct NUMERIC(5,2) NOT NULL DEFAULT 0 CHECK (commission_pct >= 0 AND commission_pct <= 100),
    notes          TEXT,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Catalogue ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_items (
    id             SERIAL PRIMARY KEY,
    outlet_id      INT NOT NULL REFERENCES pos_outlets(id) ON DELETE CASCADE,
    category_id    INT REFERENCES pos_categories(id) ON DELETE SET NULL,
    kind           VARCHAR(10) NOT NULL DEFAULT 'product' CHECK (kind IN ('product','service')),
    tour_id        INT REFERENCES tours(id) ON DELETE SET NULL,     -- linked experience (price from tours)
    name           VARCHAR(160) NOT NULL,
    sku            VARCHAR(60),
    price          NUMERIC(10,2) CHECK (price IS NULL OR price >= 0), -- NULL: tour price, else open price at sale
    per_person     BOOLEAN NOT NULL DEFAULT FALSE,
    image_key      TEXT,
    track_stock    BOOLEAN NOT NULL DEFAULT FALSE,
    stock_qty      INT     NOT NULL DEFAULT 0,
    low_stock_at   INT,
    allow_negative BOOLEAN NOT NULL DEFAULT FALSE,
    consignor_id   INT REFERENCES pos_consignors(id) ON DELETE SET NULL,
    consignor_cost NUMERIC(10,2) CHECK (consignor_cost IS NULL OR consignor_cost >= 0),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order     INT NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pos_items_outlet ON pos_items (outlet_id, is_active, sort_order);

-- Walk-in customers (searchable next time).
CREATE TABLE IF NOT EXISTS pos_customers (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(160) NOT NULL,
    phone      VARCHAR(40),
    email      VARCHAR(160),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Terminals (registered tablets — same pattern as attendance_devices) ─────
CREATE TABLE IF NOT EXISTS pos_terminals (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    venue_id     INT REFERENCES venues(id) ON DELETE SET NULL,
    token_hash   TEXT NOT NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at TIMESTAMPTZ,
    created_by   INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS pos_terminal_outlets (
    terminal_id INT NOT NULL REFERENCES pos_terminals(id) ON DELETE CASCADE,
    outlet_id   INT NOT NULL REFERENCES pos_outlets(id)   ON DELETE CASCADE,
    PRIMARY KEY (terminal_id, outlet_id)
);

-- ── Sales ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pos_sales (
    id              SERIAL PRIMARY KEY,
    reference       VARCHAR(40) NOT NULL UNIQUE,              -- POS-<OUTLET>-<n>
    outlet_id       INT NOT NULL REFERENCES pos_outlets(id),
    terminal_id     INT REFERENCES pos_terminals(id) ON DELETE SET NULL,
    admin_user_id   INT REFERENCES admin_users(id) ON DELETE SET NULL,   -- who completed it
    customer_type   VARCHAR(10) NOT NULL CHECK (customer_type IN ('inhouse','walkin')),
    hold_id         INT REFERENCES holds(id) ON DELETE SET NULL,
    guest_id        INT REFERENCES checkin_guests(id) ON DELETE SET NULL,
    pos_customer_id INT REFERENCES pos_customers(id) ON DELETE SET NULL,
    customer_name   VARCHAR(160) NOT NULL DEFAULT '',                    -- snapshot
    currency        CHAR(3) NOT NULL,
    subtotal        NUMERIC(12,2) NOT NULL,
    service_charge  NUMERIC(12,2) NOT NULL DEFAULT 0,
    total           NUMERIC(12,2) NOT NULL,
    payment_method  VARCHAR(20) NOT NULL
                    CHECK (payment_method IN ('cash','card','room_charge','mobile_money','other')),
    payment_ref     VARCHAR(80),
    cash_tendered   NUMERIC(12,2),
    status          VARCHAR(10) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed','voided')),
    void_reason     TEXT,
    voided_by       INT REFERENCES admin_users(id) ON DELETE SET NULL,
    voided_at       TIMESTAMPTZ,
    client_uuid     VARCHAR(64) NOT NULL UNIQUE,              -- idempotency: a double-tap can't make 2 sales
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pos_sales_outlet ON pos_sales (outlet_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pos_sales_hold   ON pos_sales (hold_id);

CREATE TABLE IF NOT EXISTS pos_sale_lines (
    id                       SERIAL PRIMARY KEY,
    sale_id                  INT NOT NULL REFERENCES pos_sales(id) ON DELETE CASCADE,
    item_id                  INT REFERENCES pos_items(id) ON DELETE SET NULL,
    owning_outlet_id         INT NOT NULL REFERENCES pos_outlets(id),
    tour_id                  INT REFERENCES tours(id) ON DELETE SET NULL,
    name                     VARCHAR(160) NOT NULL,       -- snapshot
    kind                     VARCHAR(10)  NOT NULL,
    qty                      INT NOT NULL CHECK (qty > 0),
    unit_price               NUMERIC(10,2) NOT NULL,
    line_total               NUMERIC(12,2) NOT NULL,
    consignor_id             INT REFERENCES pos_consignors(id) ON DELETE SET NULL,
    consignor_commission_pct NUMERIC(5,2),
    consignor_cost           NUMERIC(10,2)
);
CREATE INDEX IF NOT EXISTS idx_pos_sale_lines_sale ON pos_sale_lines (sale_id);

-- Stock ledger — the source of truth for stock_qty.
CREATE TABLE IF NOT EXISTS pos_stock_moves (
    id            SERIAL PRIMARY KEY,
    item_id       INT NOT NULL REFERENCES pos_items(id) ON DELETE CASCADE,
    qty_delta     INT NOT NULL,
    reason        VARCHAR(10) NOT NULL CHECK (reason IN ('receive','sale','void','adjust','return')),
    sale_id       INT REFERENCES pos_sales(id) ON DELETE SET NULL,
    unit_cost     NUMERIC(10,2),
    note          TEXT,
    admin_user_id INT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pos_stock_moves_item ON pos_stock_moves (item_id, created_at);

CREATE TABLE IF NOT EXISTS pos_consignor_payouts (
    id            SERIAL PRIMARY KEY,
    consignor_id  INT NOT NULL REFERENCES pos_consignors(id) ON DELETE CASCADE,
    period_from   DATE NOT NULL,
    period_to     DATE NOT NULL,
    amount        NUMERIC(12,2) NOT NULL,
    currency      CHAR(3) NOT NULL DEFAULT 'USD',
    paid_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    note          TEXT,
    admin_user_id INT REFERENCES admin_users(id) ON DELETE SET NULL
);

-- ── Links into existing tables ──────────────────────────────────────────────
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS pos_pin_hash   TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS pos_pin_set_at TIMESTAMPTZ;

ALTER TABLE bill_items ADD COLUMN IF NOT EXISTS pos_sale_id INT REFERENCES pos_sales(id) ON DELETE SET NULL;
-- One bill line per sale: a retry can never post a room charge twice.
CREATE UNIQUE INDEX IF NOT EXISTS uq_bill_items_pos_sale ON bill_items (pos_sale_id) WHERE pos_sale_id IS NOT NULL;
