-- Migration: a display name for restaurant tables (Zuri sync contract field
-- restaurant_table.name, string ≤80). Run AFTER add_restaurant_sync_models.sql.
-- Run via /admin/migrate.php. Idempotent.
--
-- `label` stays the table NUMBER (contract `number`, ≤10 — Zuri matches tables
-- on it, e.g. "7"); `name` is what staff call it ("Pool 1", "Private Dining").
ALTER TABLE restaurant_tables ADD COLUMN IF NOT EXISTS name VARCHAR(80);
