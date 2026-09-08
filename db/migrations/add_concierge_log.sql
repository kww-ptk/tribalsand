-- Tribal Sand: guest concierge chat log (AI assistant, Phase 3). Idempotent.
--
-- The public concierge (concierge.php + api/concierge.php) reuses the same
-- read-only tool+RAG engine as the admin assistant, exposed to guests. This
-- table does double duty:
--   1. RATE LIMITING — count a client_ip's rows in a time window (the same
--      pattern the reservation/enquiry endpoints use) to throttle abuse and
--      cap LLM cost (NFR7).
--   2. OBSERVABILITY — one row per answered turn (question, tools used, ok),
--      so the owner can see what guests ask and spot problems.
--
-- Read-only feature: nothing here books or holds. Rows are a log, not state.
CREATE TABLE IF NOT EXISTS concierge_log (
    id          BIGSERIAL PRIMARY KEY,
    client_ip   VARCHAR(64)  NOT NULL DEFAULT '',
    session_id  VARCHAR(64)  NOT NULL DEFAULT '',
    question    TEXT         NOT NULL DEFAULT '',
    answer      TEXT         NOT NULL DEFAULT '',
    tools_used  VARCHAR(255) NOT NULL DEFAULT '',   -- comma-separated tool names for the turn
    tool_count  SMALLINT     NOT NULL DEFAULT 0,
    ok          BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Rate-limit reads are WHERE client_ip = ? AND created_at > now() - interval.
CREATE INDEX IF NOT EXISTS idx_concierge_ip_time ON concierge_log (client_ip, created_at);
CREATE INDEX IF NOT EXISTS idx_concierge_time    ON concierge_log (created_at);
