-- Inventory — more than one store: Main stock is the store flagged is_main; other
-- stores carry an owning venue and share_venue_ids (venues whose managers may also
-- use it). Run AFTER add_inventory.sql. Idempotent.
-- Spec: docs/superpowers/specs/2026-09-28-inventory-shipments-design.md
--
-- Load-bearing rules (see CLAUDE.md "Inventory & Assets"):
--   • Main stock is the store flagged is_main (exactly one). Other stores carry an
--     OWNING venue (venue_id — the accounting seam) and share_venue_ids: the
--     venues whose managers may also use it. A location's "venue set" = owner ∪ shares.

-- ── Stores ──────────────────────────────────────────────────────────────────
ALTER TABLE inv_locations ADD COLUMN IF NOT EXISTS is_main         BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE inv_locations ADD COLUMN IF NOT EXISTS share_venue_ids INT[]   NOT NULL DEFAULT '{}';
UPDATE inv_locations SET is_main = TRUE
 WHERE id = (SELECT MIN(id) FROM inv_locations WHERE kind = 'store')
   AND NOT EXISTS (SELECT 1 FROM inv_locations WHERE is_main);
DROP INDEX IF EXISTS uq_inv_locations_store;                       -- the old "one store only" rule
CREATE UNIQUE INDEX IF NOT EXISTS uq_inv_locations_main ON inv_locations (is_main) WHERE is_main;
ALTER TABLE inv_locations DROP CONSTRAINT IF EXISTS inv_locations_store_fields_check;
ALTER TABLE inv_locations ADD CONSTRAINT inv_locations_store_fields_check CHECK (
    (NOT is_main OR kind = 'store') AND (cardinality(share_venue_ids) = 0 OR kind = 'store')
    AND array_position(share_venue_ids, NULL) IS NULL);
