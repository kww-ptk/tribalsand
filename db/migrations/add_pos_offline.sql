-- Tribal Sand: POS offline mode — when a till saved a sale without a connection and
-- sent it later, this records when the sale really happened (the row's created_at
-- is when it reached the server). Run via /admin/migrate.php after add_pos.sql. Idempotent.
ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS offline_sold_at TIMESTAMPTZ;
