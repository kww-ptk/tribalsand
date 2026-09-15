-- Migration: link a hold to the travel agent who requested it.
-- Run via /admin/migrate.php AFTER add_travel_agents.sql (the FK target). Apply
-- add_holds_quoted_amount.sql as well — that is where an agent hold freezes its
-- NET price, which bookings_sync_hold() snapshots into the ledger. Idempotent.
--
-- holds.agent_id says a hold was requested through the trade portal, and by whom.
-- NULL = a guest or staff hold — exactly what every existing row already means.
-- The submission payload (payload_json.agent_id, agency, quoted_total …) is the
-- canonical record of the request and needs no column; this link is what the
-- admin badge, the revenue ledger (source = 'agent') and the trade emails read.
ALTER TABLE holds ADD COLUMN IF NOT EXISTS agent_id INTEGER NULL
    REFERENCES travel_agents(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_holds_agent_id
    ON holds (agent_id) WHERE agent_id IS NOT NULL;

-- "Your requests" in the portal reads submissions by the agent id in the payload.
CREATE INDEX IF NOT EXISTS idx_submissions_agent_id
    ON submissions ((payload_json->>'agent_id'));
