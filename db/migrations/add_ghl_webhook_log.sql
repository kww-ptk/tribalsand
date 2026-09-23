-- Migration: de-dupe + observability log for inbound GoHighLevel webhooks
-- (api/ghl-webhook.php — a GHL Workflow "Customer Replied" → Webhook action posts
-- each WhatsApp reply here). Run via /admin/migrate.php. Idempotent.
--
-- GHL retries a webhook it thinks failed, so the same reply can arrive twice.
-- dedupe_key (the GHL message id, else a hash of the raw body) makes a retry a
-- no-op. Pre-migration the endpoint still threads replies; it just can't de-dupe.

CREATE TABLE IF NOT EXISTS ghl_webhook_log (
    id             SERIAL PRIMARY KEY,
    dedupe_key     TEXT         NOT NULL UNIQUE,
    contact_id     TEXT,
    channel        TEXT,
    submission_id  INT          REFERENCES submissions(id) ON DELETE SET NULL,
    action         TEXT         NOT NULL,        -- threaded | created | ignored
    received_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ghl_webhook_log_received ON ghl_webhook_log(received_at DESC);
