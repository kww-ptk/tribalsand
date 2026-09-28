-- Tribal Sand: Accounting P2b — POS sale documents, inter-company entries (room
-- charges, re-invoices, stock transfers, settlements), partial credit notes.
-- Spec: docs/superpowers/specs/2026-09-27-accounting-layer-design.md §4.3–4.5.
-- Run via /admin/migrate.php AFTER add_acct_documents.sql. Idempotent.

-- ── Documents: POS link, transfer link ──────────────────────────────────────
ALTER TABLE acct_documents ADD COLUMN IF NOT EXISTS pos_sale_id  INT;
ALTER TABLE acct_documents ADD COLUMN IF NOT EXISTS transfer_ref VARCHAR(40);
DO $$
BEGIN
    IF to_regclass('public.pos_sales') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'acct_documents_pos_sale_fk') THEN
        ALTER TABLE acct_documents ADD CONSTRAINT acct_documents_pos_sale_fk FOREIGN KEY (pos_sale_id) REFERENCES pos_sales(id) ON DELETE RESTRICT;
    END IF;
END $$;
-- A sale / a transfer is documented once per issuing company (credit notes excluded).
CREATE UNIQUE INDEX IF NOT EXISTS uq_acct_documents_pos_sale ON acct_documents (pos_sale_id, company_id)
    WHERE pos_sale_id IS NOT NULL AND doc_type <> 'credit_note';
CREATE UNIQUE INDEX IF NOT EXISTS uq_acct_documents_transfer ON acct_documents (transfer_ref, company_id)
    WHERE transfer_ref IS NOT NULL AND doc_type <> 'credit_note';

-- ── Partial credit notes: a credit line names the line it credits ───────────
DROP INDEX IF EXISTS uq_acct_documents_credits;   -- an invoice can now be credited in parts
ALTER TABLE acct_document_lines ADD COLUMN IF NOT EXISTS credits_line_id INT REFERENCES acct_document_lines(id) ON DELETE RESTRICT;
CREATE UNIQUE INDEX IF NOT EXISTS uq_acct_document_lines_credits ON acct_document_lines (credits_line_id) WHERE credits_line_id IS NOT NULL;
-- Backfill P2a full credit notes: pair each credit line with the invoice line in the same position.
UPDATE acct_document_lines cl SET credits_line_id = x.orig_id
  FROM (SELECT c.id AS credit_line_id, o.id AS orig_id
          FROM (SELECT l.id, d.credits_document_id AS inv, ROW_NUMBER() OVER (PARTITION BY l.document_id ORDER BY l.id) AS rn
                  FROM acct_document_lines l JOIN acct_documents d ON d.id = l.document_id
                 WHERE d.doc_type = 'credit_note' AND d.credits_document_id IS NOT NULL) c
          JOIN (SELECT l.id, l.document_id AS inv, ROW_NUMBER() OVER (PARTITION BY l.document_id ORDER BY l.id) AS rn
                  FROM acct_document_lines l) o ON o.inv = c.inv AND o.rn = c.rn) x
 WHERE cl.id = x.credit_line_id AND cl.credits_line_id IS NULL;

ALTER TABLE acct_document_lines DROP CONSTRAINT IF EXISTS acct_document_lines_source_kind_check;
ALTER TABLE acct_document_lines ADD CONSTRAINT acct_document_lines_source_kind_check
    CHECK (source_kind IN ('','stay','addon','bill_item','pos_line','pos_sale','inv_move'));

-- ── Payments: inter-company receipts have no bank account; POS link ─────────
ALTER TABLE acct_payments ALTER COLUMN account_id DROP NOT NULL;
ALTER TABLE acct_payments ADD COLUMN IF NOT EXISTS counterparty_company_id INT REFERENCES companies(id) ON DELETE RESTRICT;
ALTER TABLE acct_payments ADD COLUMN IF NOT EXISTS pos_sale_id INT;
ALTER TABLE acct_payments DROP CONSTRAINT IF EXISTS acct_payments_account_check;
ALTER TABLE acct_payments ADD CONSTRAINT acct_payments_account_check CHECK (account_id IS NOT NULL OR method = 'intercompany');
CREATE INDEX IF NOT EXISTS idx_acct_payments_pos_sale ON acct_payments (pos_sale_id) WHERE pos_sale_id IS NOT NULL;

-- ── Inter-company ledger: "from owes to", positive = more owed ──────────────
-- Append-only: a reversal or a settlement is a NEGATIVE row.
CREATE TABLE IF NOT EXISTS acct_ic_entries (
    id              SERIAL PRIMARY KEY,
    from_company_id INT           NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,   -- owes
    to_company_id   INT           NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,   -- is owed
    amount          NUMERIC(12,2) NOT NULL CHECK (amount <> 0),
    currency        CHAR(3)       NOT NULL,
    source_kind     VARCHAR(14)   NOT NULL CHECK (source_kind IN ('room_charge','ic_invoice','stock_transfer','settlement')),
    document_id     INT           REFERENCES acct_documents(id) ON DELETE RESTRICT,
    document_line_id INT          REFERENCES acct_document_lines(id) ON DELETE RESTRICT,   -- the folio line collected on behalf
    payment_id      INT           REFERENCES acct_payments(id) ON DELETE RESTRICT,
    transfer_ref    VARCHAR(40),
    note            TEXT          NOT NULL DEFAULT '',
    created_by      INT           REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    CHECK (from_company_id <> to_company_id)
);
CREATE INDEX IF NOT EXISTS idx_acct_ic_pair ON acct_ic_entries (from_company_id, to_company_id, currency);
CREATE INDEX IF NOT EXISTS idx_acct_ic_doc  ON acct_ic_entries (document_id);

-- ── Inventory: one reference shared by every line of one transfer action ────
DO $$
BEGIN
    IF to_regclass('public.inv_moves') IS NOT NULL THEN
        ALTER TABLE inv_moves ADD COLUMN IF NOT EXISTS transfer_ref VARCHAR(40);
        CREATE INDEX IF NOT EXISTS idx_inv_moves_transfer_ref ON inv_moves (transfer_ref) WHERE transfer_ref IS NOT NULL;
    END IF;
END $$;
