-- Tribal Sand: email a till receipt to the customer. Remembers where the last copy
-- went and how many were sent (capped per sale). Run via /admin/migrate.php after
-- add_pos.sql. Idempotent. Before it runs the till simply has no Email button.
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS receipt_email      VARCHAR(160);
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS receipt_sent_count INT NOT NULL DEFAULT 0;
