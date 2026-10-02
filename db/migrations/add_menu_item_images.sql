-- Migration: a photo per menu dish. Run via /admin/migrate.php, after add_menus.sql. Idempotent.
--
-- image_key = a storage key (storage_url()) or a full https URL. LOCAL ONLY: it is
-- not part of the Zuri sync payload (sync_map_menu_item() sends an explicit field
-- list and leaves Zuri's own `image` alone), so editing a photo never emits an event.
-- Set per dish in Admin → Restaurant → Menus, or in bulk with "Import photos from
-- Zuri's website" on the Zuri menu.
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS image_key TEXT;
