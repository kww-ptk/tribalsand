-- Tribal Sand: ad-hoc bill line items (minibar, damages…) not tied to a request.
-- Run via /admin/migrate.php. Idempotent.
CREATE TABLE IF NOT EXISTS bill_items (
    id         SERIAL PRIMARY KEY,
    hold_id    INT NOT NULL REFERENCES holds(id) ON DELETE CASCADE,
    label      VARCHAR(200)  NOT NULL,
    amount     NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP     NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_bill_items_hold ON bill_items (hold_id);
