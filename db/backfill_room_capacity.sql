-- Phase A / Phase 0 — Capacity as source of truth (data reconciliation).
--
-- Capacity-aware search + the AI combination logic treat rooms.capacity as the
-- max occupancy of ONE unit of that room type. A NULL/0 capacity is "unknown":
-- such a room is never assumed to fit a party and is skipped by the combination
-- search — so every published, bookable room needs a defensible capacity. The
-- number of *active units* per room type is the other half: search does
-- `free_units × capacity`, so a one-room suite that carries 2 active units makes
-- the AI suggest "2× that suite" (the reported bug). Both halves are DATA — the
-- algorithm and prompt are already correct.
--
-- NO schema change. Data only. Apply to PROD RDS separately (local .env is a dev
-- database; anything run locally does NOT reach live data).
--
-- >>> RUN bin/audit-capacity-units.php ON PROD FIRST. <<<  This backfill only sets
-- capacities that are currently NULL/0 (it never overwrites a value someone set
-- on purpose) and it does NOT touch units. If the audit shows a capacity that is
-- wrong-but-non-null (section F), or a single-room type with >1 active unit
-- (section B), fix those per the reviewed steps at the bottom — not blindly.
--
-- Idempotent: safe to re-run. Missing rows (e.g. a suite that was dropped) simply
-- match 0 rows.

BEGIN;

-- ── ZURI: ocean suites (one physical suite = one unit) + whole-property buyout ──
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'zuri-maji'   AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 4,  updated_at = NOW() WHERE slug = 'zuri-mwezi'  AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'zuri-ua'     AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'zuri-anga'   AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'zuri-jua'    AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'zuri-bahari' AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 14, updated_at = NOW() WHERE slug = 'zuri-buyout' AND (capacity IS NULL OR capacity = 0);

-- ── MAYA KOBE: 5 suites + whole-property buyout ──
UPDATE rooms SET capacity = 4,  updated_at = NOW() WHERE slug = 'maya-kobe-prestige' AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'maya-kobe-haze'     AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'maya-kobe-glow'     AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'maya-kobe-tide'     AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'maya-kobe-drift'    AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 16, updated_at = NOW() WHERE slug = 'maya-kobe-buyout'   AND (capacity IS NULL OR capacity = 0);

-- ── MAYA ILAI: 2 multi-unit room types (8 units each) + full-compound buyout ──
UPDATE rooms SET capacity = 6,  updated_at = NOW() WHERE slug = 'maya-ilai-villa'  AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 2,  updated_at = NOW() WHERE slug = 'maya-ilai-studio' AND (capacity IS NULL OR capacity = 0);
UPDATE rooms SET capacity = 48, updated_at = NOW() WHERE slug = 'maya-ilai-buyout' AND (capacity IS NULL OR capacity = 0);

-- ── ENTIRE-PROPERTY VENUES (single whole-property room each) ──
-- Scoped by venue + is_entire_place so we set the whole-property room without
-- guessing its slug. Still NULL/0-guarded.

-- Sandbox: its page states "sleeps up to 8" (4 bedrooms).
UPDATE rooms r SET capacity = 8, updated_at = NOW()
  FROM venues v
 WHERE r.venue_id = v.id AND v.slug = 'sandbox'
   AND r.is_entire_place = TRUE AND (r.capacity IS NULL OR r.capacity = 0);

-- My Amani: provisional 10 — CONFIRM against reality before trusting it. Left in
-- because the audit will show whether it was already set; NULL/0-guarded so a
-- confirmed different value is never clobbered.
UPDATE rooms r SET capacity = 10, updated_at = NOW()
  FROM venues v
 WHERE r.venue_id = v.id AND v.slug = 'my-amani'
   AND r.is_entire_place = TRUE AND (r.capacity IS NULL OR r.capacity = 0);

COMMIT;

-- ═════════════════════════════════════════════════════════════════════════════
-- OPEN OWNER DECISIONS — apply deliberately, NOT as part of the batch above.
-- ═════════════════════════════════════════════════════════════════════════════
--
-- 1) ENKARE BOFA capacity — no seed carries a number. Set the real whole-property
--    occupancy once the owner confirms it (replace 0 below), then run:
--
--      UPDATE rooms r SET capacity = <REAL>, updated_at = NOW()
--        FROM venues v
--       WHERE r.venue_id = v.id AND v.slug = 'enkare-bofa'
--         AND r.is_entire_place = TRUE;
--
-- 2) WRONG-BUT-NON-NULL capacity (audit section F). The NULL/0 guard above will
--    NOT fix a capacity that is set but wrong. Correct each confirmed case by
--    slug with an UNGUARDED update, e.g.:
--
--      UPDATE rooms SET capacity = 2, updated_at = NOW() WHERE slug = 'zuri-maji';
--
-- 3) UNIT COUNT anomalies (audit sections B & C). Units carry live bookings, so
--    NEVER mass-delete/re-seed units on prod (that is what db/seed_rooms_2026.sql
--    does — it is a dev rebuild and cascades away holds/blocks; do NOT run it on
--    prod). Fix surgically instead:
--
--    * A single-room suite showing 2+ active units (section B): the extra unit is
--      the "2× a one-room suite" defect. Confirm the surplus unit has no future
--      bookings, then DEACTIVATE it (don't delete, so history is preserved):
--
--        -- inspect first
--        SELECT u.id, u.name, u.is_active,
--               (SELECT COUNT(*) FROM availability_blocks b WHERE b.unit_id = u.id) AS blocks,
--               (SELECT COUNT(*) FROM holds h WHERE h.unit_id = u.id) AS holds
--          FROM units u JOIN rooms r ON r.id = u.room_id
--         WHERE r.slug = '<suite-slug>' ORDER BY u.sort_order, u.id;
--
--        -- then, for a confirmed-surplus, booking-free unit id:
--        UPDATE units SET is_active = FALSE WHERE id = <unit_id>;
--
--    * A published room with 0 active units (section C, e.g. maya_ilai/superior-suite):
--      either give it a real unit, or unpublish the room — a published room with
--      nothing bookable should not exist:
--
--        INSERT INTO units (room_id, name, sort_order)
--          SELECT id, 'Unit A', 0 FROM rooms WHERE slug = '<slug>';
--        -- OR
--        UPDATE rooms SET is_published = FALSE, updated_at = NOW() WHERE slug = '<slug>';
--
-- Re-run bin/audit-capacity-units.php after every change; the goal is zero
-- anomalies in sections A–G.
