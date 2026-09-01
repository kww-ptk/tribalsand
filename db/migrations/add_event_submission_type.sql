-- Tribal Sand: allow submissions.type = 'event' for wedding/event enquiries.
-- Idempotent: drops the old CHECK and recreates it with the extra value.
--
-- api/submit-event.php is pre-migration-safe — it probes whether 'event' is
-- accepted and falls back to 'contact' if this has not been applied, so a lead
-- is never rejected by the constraint.
ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_type_check;
ALTER TABLE submissions ADD  CONSTRAINT submissions_type_check
      CHECK (type IN ('enquiry','contact','agency','availability','event'));
