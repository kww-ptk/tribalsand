-- Trip Builder submissions get their own type.
--
-- api/trip-builder.php stored its rows as type 'enquiry' — the same value the
-- room booking widget and /ghl-submit use — so in Admin -> Submissions a trip
-- plan was indistinguishable from a room enquiry. Only the staff email subject
-- ('[Trip Builder] …') told them apart.
--
-- 1. submissions.type carries a CHECK constraint listing the permitted values,
--    so the new type has to be admitted before anything can be written with it.
--    Until this runs, api/trip-builder.php detects the rejection and falls back
--    to 'enquiry' rather than lose the lead — so running this late is safe, and
--    step 2 sweeps up anything that landed in the meantime.
-- 2. Retype the existing rows, matched on the fixed message string the endpoint
--    writes for every trip plan (never guest-supplied), so nothing else can be
--    caught by it.
--
-- Idempotent: the constraint is dropped by name before being recreated, and
-- rows already retyped no longer match type = 'enquiry'.

ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_type_check;

ALTER TABLE submissions ADD CONSTRAINT submissions_type_check
  CHECK (type IN ('enquiry', 'contact', 'agency', 'availability', 'event', 'trip_builder'));

UPDATE submissions
   SET type = 'trip_builder'
 WHERE type = 'enquiry'
   AND message = 'Trip Builder request — see payload for full itinerary.';
