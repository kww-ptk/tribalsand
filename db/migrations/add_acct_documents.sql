-- Tribal Sand: Accounting P2a — the sales sub-ledger for ROOM FOLIOS: tax invoices,
-- credit notes, payments and their allocation, plus the bill-line currency and
-- invoice locks the review found missing (spec §4, corrections §12 #1–#3).
-- Run via /admin/migrate.php AFTER add_companies.sql. Idempotent.
-- Inert until the owner sets a company's "Invoicing starts on" date.

-- Room / extras prices of a VAT-registered company: do they already include VAT?
-- (Kenyan practice: yes.) POS sales keep their own snapshot and ignore this.
ALTER TABLE companies ADD COLUMN IF NOT EXISTS prices_include_vat BOOLEAN NOT NULL DEFAULT TRUE;

-- ── Documents ───────────────────────────────────────────────────────────────
-- Issued documents are immutable: a correction is a credit note, never an edit.
CREATE TABLE IF NOT EXISTS acct_documents (
    id                  SERIAL PRIMARY KEY,
    company_id          INT          NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    doc_type            VARCHAR(12)  NOT NULL CHECK (doc_type IN ('invoice','credit_note','proforma','ic_invoice')),
    number              VARCHAR(40)  NOT NULL,
    customer_kind       VARCHAR(10)  NOT NULL CHECK (customer_kind IN ('guest','agent','ota','company','walkin')),
    customer_name       VARCHAR(200) NOT NULL DEFAULT '',
    customer_pin        VARCHAR(11)  NOT NULL DEFAULT '',
    agent_id            INT,                                        -- travel_agents.id (no FK: table may be absent)
    counterparty_company_id INT      REFERENCES companies(id) ON DELETE RESTRICT,
    currency            CHAR(3)      NOT NULL,
    fx_to_home          NUMERIC(14,6) NOT NULL DEFAULT 1,           -- home-currency units per 1 document unit, at issue
    subtotal            NUMERIC(12,2) NOT NULL DEFAULT 0,           -- net of VAT
    vat_amount          NUMERIC(12,2) NOT NULL DEFAULT 0,
    total               NUMERIC(12,2) NOT NULL DEFAULT 0,           -- what the customer owes (credit note: what is given back)
    hold_id             INT          REFERENCES holds(id) ON DELETE RESTRICT,
    credits_document_id INT          REFERENCES acct_documents(id) ON DELETE RESTRICT,
    reason              TEXT         NOT NULL DEFAULT '',           -- credit notes: why
    issued_by           INT          REFERENCES admin_users(id) ON DELETE SET NULL,
    issued_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (doc_type <> 'credit_note' OR credits_document_id IS NOT NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_acct_documents_number ON acct_documents (company_id, number);
-- v1 credit notes are full: an invoice is credited at most once.
CREATE UNIQUE INDEX IF NOT EXISTS uq_acct_documents_credits ON acct_documents (credits_document_id) WHERE credits_document_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_acct_documents_hold    ON acct_documents (hold_id);
CREATE INDEX IF NOT EXISTS idx_acct_documents_company ON acct_documents (company_id, issued_at);

CREATE TABLE IF NOT EXISTS acct_document_lines (
    id                  SERIAL PRIMARY KEY,
    document_id         INT           NOT NULL REFERENCES acct_documents(id) ON DELETE RESTRICT,
    description         VARCHAR(300)  NOT NULL,
    qty                 NUMERIC(10,2) NOT NULL DEFAULT 1,
    unit_price          NUMERIC(12,2) NOT NULL DEFAULT 0,
    line_total          NUMERIC(12,2) NOT NULL DEFAULT 0,           -- gross, as charged to the customer
    net_amount          NUMERIC(12,2) NOT NULL DEFAULT 0,
    vat_amount          NUMERIC(12,2) NOT NULL DEFAULT 0,
    vat_rate            NUMERIC(5,2)  NOT NULL DEFAULT 0,
    tax_band            CHAR(1)       NOT NULL DEFAULT 'D' CHECK (tax_band IN ('A','B','C','D','E')),
    category            VARCHAR(16)   NOT NULL DEFAULT 'other',
    is_disbursement     BOOLEAN       NOT NULL DEFAULT FALSE,       -- collected on behalf of another company: not revenue, no VAT
    supplier_company_id INT           REFERENCES companies(id) ON DELETE RESTRICT,
    source_kind         VARCHAR(10)   NOT NULL DEFAULT '' CHECK (source_kind IN ('','stay','addon','bill_item')),
    source_id           INT
);
CREATE INDEX IF NOT EXISTS idx_acct_document_lines_doc ON acct_document_lines (document_id);

-- ── Payments ────────────────────────────────────────────────────────────────
-- Never edited. A mistake is a refund row naming the payment it reverses.
CREATE TABLE IF NOT EXISTS acct_payments (
    id                  SERIAL PRIMARY KEY,
    company_id          INT           NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    account_id          INT           NOT NULL REFERENCES company_accounts(id) ON DELETE RESTRICT,
    kind                VARCHAR(8)    NOT NULL CHECK (kind IN ('receipt','refund')),
    refunds_payment_id  INT           REFERENCES acct_payments(id) ON DELETE RESTRICT,
    is_security_deposit BOOLEAN       NOT NULL DEFAULT FALSE,       -- a liability: never revenue unless applied
    method              VARCHAR(14)   NOT NULL CHECK (method IN ('cash','card','mpesa','bank','ota_payout','intercompany')),
    amount              NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    currency            CHAR(3)       NOT NULL,
    fx_to_home          NUMERIC(14,6) NOT NULL DEFAULT 1,
    reference           VARCHAR(80)   NOT NULL DEFAULT '',
    payer_name          VARCHAR(200)  NOT NULL DEFAULT '',
    reason              TEXT          NOT NULL DEFAULT '',
    hold_id             INT           REFERENCES holds(id) ON DELETE RESTRICT,
    received_at         TIMESTAMPTZ   NOT NULL DEFAULT now(),
    recorded_by         INT           REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CHECK (kind <> 'refund' OR refunds_payment_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_acct_payments_hold    ON acct_payments (hold_id);
CREATE INDEX IF NOT EXISTS idx_acct_payments_company ON acct_payments (company_id, received_at);

-- Append-only: releasing an allocation (a credited invoice) is a NEGATIVE row.
CREATE TABLE IF NOT EXISTS acct_allocations (
    id          SERIAL PRIMARY KEY,
    payment_id  INT           NOT NULL REFERENCES acct_payments(id) ON DELETE RESTRICT,
    document_id INT           NOT NULL REFERENCES acct_documents(id) ON DELETE RESTRICT,
    amount      NUMERIC(12,2) NOT NULL CHECK (amount <> 0),       -- in the DOCUMENT's currency
    pay_amount  NUMERIC(12,2) NOT NULL CHECK (pay_amount <> 0),   -- the same, in the PAYMENT's currency
    rate        NUMERIC(14,6) NOT NULL DEFAULT 1,                   -- payment-currency units per 1 document unit
    created_at  TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_acct_allocations_payment ON acct_allocations (payment_id);
CREATE INDEX IF NOT EXISTS idx_acct_allocations_doc     ON acct_allocations (document_id);

-- ── Bill lines: currency + invoice lock ─────────────────────────────────────
-- currency NULL = the site currency (what the bill has always shown), so legacy
-- rows keep their meaning. document_id set = on an issued invoice: locked.
ALTER TABLE bill_items     ADD COLUMN IF NOT EXISTS currency       CHAR(3);
ALTER TABLE bill_items     ADD COLUMN IF NOT EXISTS document_id    INT REFERENCES acct_documents(id) ON DELETE RESTRICT;
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS price_currency CHAR(3);
ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS document_id    INT REFERENCES acct_documents(id) ON DELETE RESTRICT;
