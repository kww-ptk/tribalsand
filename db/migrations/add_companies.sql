-- Tribal Sand: Accounting P1 — legal companies, where their money lands, gapless
-- document numbering, and which company owns each property / outlet / shared
-- stock location. Spec: docs/superpowers/specs/2026-09-27-accounting-layer-design.md §3.
-- Run via /admin/migrate.php (after add_pos_v2.sql and add_inventory.sql when those
-- exist — the POS / inventory columns are added only if their tables are there).
-- Idempotent. Nothing changes on any page until the owner assigns companies, and no
-- document is issued until a later phase sets companies.accounting_starts_on.

CREATE TABLE IF NOT EXISTS companies (
    id                   SERIAL PRIMARY KEY,
    name                 VARCHAR(120) NOT NULL,                     -- what staff call it
    code                 VARCHAR(10)  NOT NULL,                     -- short code for number prefixes, e.g. ZUR
    legal_name           VARCHAR(200) NOT NULL DEFAULT '',          -- as registered, printed on invoices
    kra_pin              VARCHAR(11)  NOT NULL DEFAULT '',          -- A/P + 9 digits + letter; '' = not set yet
    vat_registered       BOOLEAN      NOT NULL DEFAULT FALSE,
    etims_enabled        BOOLEAN      NOT NULL DEFAULT FALSE,
    home_currency        CHAR(3)      NOT NULL DEFAULT 'KES',
    invoice_timing       VARCHAR(10)  NOT NULL DEFAULT 'checkout' CHECK (invoice_timing IN ('checkout','confirm')),
    room_charge_mode     VARCHAR(10)  NOT NULL DEFAULT 'on_behalf' CHECK (room_charge_mode IN ('on_behalf','reinvoice')),
    accounting_starts_on DATE,                                      -- NULL = accounting off (the go-live gate)
    address              TEXT         NOT NULL DEFAULT '',
    email                VARCHAR(160) NOT NULL DEFAULT '',
    phone                VARCHAR(40)  NOT NULL DEFAULT '',
    logo_key             VARCHAR(300),
    is_active            BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_companies_code    ON companies (UPPER(code));
CREATE UNIQUE INDEX IF NOT EXISTS uq_companies_kra_pin ON companies (kra_pin) WHERE kra_pin <> '';

-- Where a company's money lands. A payment (P2) always names one of these.
CREATE TABLE IF NOT EXISTS company_accounts (
    id             SERIAL PRIMARY KEY,
    company_id     INT          NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    label          VARCHAR(120) NOT NULL,
    kind           VARCHAR(14)  NOT NULL CHECK (kind IN ('bank','mpesa_till','mpesa_paybill','cash','card_merchant')),
    currency       CHAR(3)      NOT NULL DEFAULT 'KES',
    bank_name      VARCHAR(120) NOT NULL DEFAULT '',
    branch         VARCHAR(120) NOT NULL DEFAULT '',
    account_number VARCHAR(40)  NOT NULL DEFAULT '',                -- bank a/c, till or paybill number, merchant id
    swift_code     VARCHAR(11)  NOT NULL DEFAULT '',
    is_default     BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order     INT          NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_company_accounts_company ON company_accounts (company_id, sort_order);
-- One default account per company per currency.
CREATE UNIQUE INDEX IF NOT EXISTS uq_company_accounts_default
    ON company_accounts (company_id, currency) WHERE is_default;

-- Gapless numbering: acct_next_number() locks the row and increments it inside the
-- issuing transaction, so a rolled-back issue rolls the counter back too.
CREATE TABLE IF NOT EXISTS company_doc_sequences (
    company_id     INT         NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    doc_type       VARCHAR(12) NOT NULL CHECK (doc_type IN ('invoice','credit_note','proforma','ic_invoice')),
    prefix         VARCHAR(20) NOT NULL,
    next_no        INT         NOT NULL DEFAULT 1 CHECK (next_no >= 1),
    last_issued_at TIMESTAMPTZ,                                     -- set once a number is taken → prefix + start locked
    PRIMARY KEY (company_id, doc_type)
);

-- Ownership. RESTRICT: a company is never deleted from under what it owns (the
-- admin switches it off instead).
ALTER TABLE venues ADD COLUMN IF NOT EXISTS company_id INT REFERENCES companies(id) ON DELETE RESTRICT;
CREATE INDEX IF NOT EXISTS idx_venues_company ON venues (company_id);

DO $$
BEGIN
    IF to_regclass('public.pos_outlets') IS NOT NULL THEN
        ALTER TABLE pos_outlets ADD COLUMN IF NOT EXISTS company_id INT REFERENCES companies(id) ON DELETE RESTRICT;
        CREATE INDEX IF NOT EXISTS idx_pos_outlets_company ON pos_outlets (company_id);
    END IF;
    -- Only SHARED locations that are not outlets use this (Main stock, a venue-less
    -- person). A property/area/person with a venue follows the venue's company, an
    -- outlet's shelf follows the outlet's company — never a second copy of either.
    IF to_regclass('public.inv_locations') IS NOT NULL THEN
        ALTER TABLE inv_locations ADD COLUMN IF NOT EXISTS company_id INT REFERENCES companies(id) ON DELETE RESTRICT;
    END IF;
END $$;
