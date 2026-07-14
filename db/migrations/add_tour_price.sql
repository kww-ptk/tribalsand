-- Activities carry a price/rate; safaris don't. Additive + idempotent.
ALTER TABLE tours ADD COLUMN IF NOT EXISTS price VARCHAR(80);
