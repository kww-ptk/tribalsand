-- Paystack payment links (after add_acct_p2b.sql; POS columns only when add_pos.sql has run).
-- Each company connects its own Paystack account (Company → Paystack tab). A payment
-- link asks a guest to pay an amount for a room bill, an activity, a deposit, a POS
-- sale or anything else; when Paystack confirms it, the payment is recorded where it
-- belongs. Logic: includes/paystack.php + includes/pay-links.php. Test: php tests/paystack_logic.php.

-- ── Company settings ────────────────────────────────────────────────────────
-- Secret keys are stored encrypted (paystack_encrypt()). mode: off · practice (pretend
-- payments, test servers only) · test (Paystack test keys) · live.
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_mode            VARCHAR(8)   NOT NULL DEFAULT 'off';
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_test_public     VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_test_secret_enc TEXT         NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_live_public     VARCHAR(120) NOT NULL DEFAULT '';
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_live_secret_enc TEXT         NOT NULL DEFAULT '';
-- Where a paid link is recorded, per currency (a company_accounts row). NULL = that
-- currency can't be asked for.
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_account_kes INT REFERENCES company_accounts(id) ON DELETE SET NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_account_usd INT REFERENCES company_accounts(id) ON DELETE SET NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS paystack_channels VARCHAR(80) NOT NULL DEFAULT 'card,mobile_money';
ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_paystack_mode_check;
ALTER TABLE companies ADD CONSTRAINT companies_paystack_mode_check CHECK (paystack_mode IN ('off','practice','test','live'));

-- ── Payment links ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pay_links (
    id               SERIAL PRIMARY KEY,
    token            VARCHAR(40)   NOT NULL UNIQUE,              -- in the guest's URL
    reference        VARCHAR(50)   NOT NULL UNIQUE,              -- sent to Paystack
    company_id       INT           NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
    purpose          VARCHAR(10)   NOT NULL CHECK (purpose IN ('bill','activity','deposit','other','pos')),
    hold_id          INT           REFERENCES holds(id) ON DELETE SET NULL,
    tour_id          INT,
    description      VARCHAR(200)  NOT NULL,
    amount           NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    currency         CHAR(3)       NOT NULL,
    customer_name    VARCHAR(160)  NOT NULL DEFAULT '',
    customer_email   VARCHAR(160)  NOT NULL DEFAULT '',
    customer_phone   VARCHAR(40)   NOT NULL DEFAULT '',
    mode             VARCHAR(8)    NOT NULL CHECK (mode IN ('practice','test','live')),
    status           VARCHAR(10)   NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting','paid','expired','cancelled')),
    expires_at       TIMESTAMPTZ   NOT NULL,
    paystack_url     TEXT          NOT NULL DEFAULT '',          -- checkout page, made when the guest first presses Pay
    started_at       TIMESTAMPTZ,
    paid_at          TIMESTAMPTZ,
    paid_channel     VARCHAR(30)   NOT NULL DEFAULT '',
    paystack_txn_id  BIGINT,
    payment_id       INT,                                         -- acct_payments row it became (if any)
    outlet_id        INT,                                         -- POS: the till's outlet …
    terminal_id      INT,
    pos_request      JSONB,                                       -- … and the sale request completed when paid
    pos_sale_id      INT,
    note             TEXT          NOT NULL DEFAULT '',           -- what happened after payment (or why it needs a look)
    last_checked_at  TIMESTAMPTZ,
    created_by       INT           REFERENCES admin_users(id) ON DELETE SET NULL,
    cancelled_by     INT           REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pay_links_company ON pay_links (company_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pay_links_hold    ON pay_links (hold_id);
CREATE INDEX IF NOT EXISTS idx_pay_links_waiting ON pay_links (status, expires_at) WHERE status = 'waiting';

-- ── "Paystack" as a way of paying ───────────────────────────────────────────
ALTER TABLE acct_payments DROP CONSTRAINT IF EXISTS acct_payments_method_check;
ALTER TABLE acct_payments ADD CONSTRAINT acct_payments_method_check
    CHECK (method IN ('cash','card','mpesa','bank','ota_payout','intercompany','paystack'));

DO $$
BEGIN
    IF to_regclass('public.pos_sales') IS NOT NULL THEN
        ALTER TABLE pos_sales DROP CONSTRAINT IF EXISTS pos_sales_payment_method_check;
        ALTER TABLE pos_sales ADD CONSTRAINT pos_sales_payment_method_check
            CHECK (payment_method IN ('cash','card','room_charge','mobile_money','other','paystack'));
    END IF;
END $$;
