-- Tribal Sand: owner-editable service pricing catalog (laundry & transfer),
-- plus a price snapshot on requests. Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS service_options (
    id           SERIAL PRIMARY KEY,
    service      VARCHAR(20)   NOT NULL CHECK (service IN ('laundry','transfer')),
    label        VARCHAR(120)  NOT NULL,
    price_amount NUMERIC(10,2) NOT NULL DEFAULT 0,
    is_active    BOOLEAN       NOT NULL DEFAULT TRUE,
    sort_order   INT           NOT NULL DEFAULT 0,
    created_at   TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_service_options_service ON service_options (service, sort_order);

ALTER TABLE booking_addons ADD COLUMN IF NOT EXISTS price_amount NUMERIC(10,2);

-- Seed today's fixed options (prices 0 — owner fills them in). Only when the
-- table is empty, so re-running never duplicates.
INSERT INTO service_options (service, label, price_amount, sort_order)
SELECT v.service, v.label, v.price_amount, v.sort_order FROM (VALUES
    ('laundry','Wash & fold',0,1),
    ('laundry','Ironing',0,2),
    ('laundry','Dry-clean',0,3),
    ('laundry','Wash & iron',0,4),
    ('transfer','Airport → Property',0,1),
    ('transfer','Property → Airport',0,2),
    ('transfer','Between properties',0,3),
    ('transfer','Custom transfer',0,4)
) AS v(service,label,price_amount,sort_order)
WHERE NOT EXISTS (SELECT 1 FROM service_options);
