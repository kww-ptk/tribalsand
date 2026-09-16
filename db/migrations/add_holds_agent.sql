-- Migration: link holds and submissions to the travel agent who requested them.
-- Run via /admin/migrate.php AFTER add_travel_agents.sql (the FK target). Apply
-- add_holds_quoted_amount.sql as well — that is where an agent hold freezes its
-- NET price, which bookings_sync_hold() snapshots into the ledger. Idempotent.
--
-- holds.agent_id says a hold was requested through the trade portal, and by whom.
-- NULL = a guest or staff hold — exactly what every existing row already means.
-- This link is what the admin badge, the revenue ledger (source = 'agent') and
-- the trade emails read.
ALTER TABLE holds ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_holds_agent_id
    ON holds (agent_id) WHERE agent_id IS NOT NULL;

-- submissions.agent_id is the SERVER-written record of who sent a request, and
-- is what "Your requests" in the portal is keyed on. It is deliberately a column
-- and not the payload's agent_id: payload_json is whatever a form posted, and a
-- public endpoint (api/trip-builder.php stores its POST body wholesale) would let
-- anyone plant an agent_id and appear in an agent's list.
ALTER TABLE submissions ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_submissions_agent
    ON submissions (agent_id) WHERE agent_id IS NOT NULL;

-- Rows written by a container deployed ahead of this migration carry the agent id
-- and an HMAC marker (agent_sig) in the payload instead; the portal still finds
-- them through this expression index. (Drops the earlier, unshipped name.)
DROP INDEX IF EXISTS idx_submissions_agent_id;
CREATE INDEX IF NOT EXISTS idx_submissions_payload_agent
    ON submissions ((payload_json->>'agent_id'));
