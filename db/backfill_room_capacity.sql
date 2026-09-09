-- Phase 0 — Capacity as source of truth (data backfill).
-- Capacity-aware search + the AI combination logic treat rooms.capacity as the
-- max occupancy of ONE unit of that room type. A NULL/0 capacity is "unknown":
-- such a room is never assumed to fit a party and is skipped by the combination
-- search — so every published, bookable room needs a defensible capacity.
--
-- NO schema change. Data only. Apply to PROD RDS separately (local .env is a
-- dev database; migrations/backfills run locally do NOT reach live data).
-- Idempotent: safe to re-run.

BEGIN;

-- Sandbox: capacity was NULL. Its own page states "sleeps up to 8" (4 bedrooms).
UPDATE rooms
   SET capacity = 8, updated_at = NOW()
 WHERE slug = 'sandbox'
   AND (capacity IS NULL OR capacity = 0);

COMMIT;

-- ── Audit (run manually; not part of the transaction) ───────────────────────
-- Any PUBLISHED room still lacking a capacity won't match guest-count searches.
-- Review each and set a real occupancy in Admin → Rooms → Edit → Details:
--
--   SELECT v.slug AS venue, r.slug, r.name, r.capacity, r.is_entire_place,
--          (SELECT COUNT(*) FROM units u WHERE u.room_id = r.id AND u.is_active) AS active_units
--     FROM rooms r JOIN venues v ON v.id = r.venue_id
--    WHERE r.is_published = TRUE
--      AND (r.capacity IS NULL OR r.capacity = 0)
--    ORDER BY v.slug, r.slug;
--
-- Known data note (owner decision — NOT auto-applied here):
--   maya_ilai / superior-suite is published with 0 active units, so it is
--   correctly excluded from availability today. Either seed a unit for it or
--   unpublish it; it should not remain a published room with nothing bookable.
--   (Phase 3 removes it as a sidebar default regardless.)
