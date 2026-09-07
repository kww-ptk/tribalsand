-- Maya Ilai · Superior Suite — restore the standard two-step booking widget.
--
-- The property page renders includes/booking-widget.php, which serves the
-- multi-step availability widget only when BOTH hold:
--
--   1. rooms.form_mode = 'availability'
--   2. the room has at least one ACTIVE unit
--      (fetch_units_by_room() filters is_active = TRUE; when it returns none,
--       booking-widget.php silently downgrades $__form_mode to 'enquiry')
--
-- Superior Suite failed the checks, so Maya Ilai fell back to form-enquiry.php:
-- a different, single-step form with no numbered step bar — and one that has no
-- JavaScript bound to it, so its steppers are inert and its submit is never
-- intercepted. Every other property (zuri-buyout, my-amani-full-rental,
-- maya-kobe-prestige, sandbox) satisfies both conditions and gets the real widget.
--
-- Idempotent: the UPDATE is a no-op once applied, and the INSERT is guarded on
-- there being no active unit, so re-running never creates a duplicate.

-- 1. Put the room on the same form mode as every other property's booking room.
UPDATE rooms
   SET form_mode = 'availability'
 WHERE slug = 'superior-suite';

-- 2. Give it a bookable unit if it has no active one.
--    Deliberately guarded on is_active = TRUE rather than on row existence, so a
--    unit someone switched off on purpose is never silently re-enabled — a new
--    one is added alongside it instead.
--    NOTE: one unit = one Superior Suite bookable at a time. If the property has
--    several, add the rest in Admin -> Rooms -> Superior Suite -> Units.
INSERT INTO units (room_id, name, sort_order)
SELECT r.id, 'Superior Suite 1', 1
  FROM rooms r
 WHERE r.slug = 'superior-suite'
   AND NOT EXISTS (
         SELECT 1 FROM units u
          WHERE u.room_id = r.id
            AND u.is_active = TRUE
       );
