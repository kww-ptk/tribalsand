-- Allow a new submission type for the 2-step availability search + lead-capture flow.
-- The search bar records the visitor's contact details and search criteria as a lead
-- (submissions.type = 'availability') BEFORE showing the available-rooms page, so an
-- abandoned search is still captured. See api/search-lead.php.
--
-- Idempotent: drops the known constraint by name and re-adds it with the extra value.
ALTER TABLE submissions DROP CONSTRAINT IF EXISTS submissions_type_check;
ALTER TABLE submissions ADD CONSTRAINT submissions_type_check
    CHECK (type IN ('enquiry','contact','agency','availability'));
