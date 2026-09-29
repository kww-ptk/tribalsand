-- Tribal Sand: editable email wording (Email Notifications Center, Phase 4).
-- Idempotent. Run after add_email_log.sql.
--
-- The code keeps every email's DEFAULT wording (includes/email-templates.php).
-- A row here overrides one template's editable fields — subject, heading,
-- intro, footer note — for every property (venue_id NULL) or for one property.
-- Resolution: property override → global override → code default. A blank
-- field falls through to the next level, so an empty row changes nothing.
--
-- Only plain text with {{placeholders}} is stored — never HTML. The fixed parts
-- of each email (detail tables, buttons, manage links) stay code-rendered so the
-- booking data in them is always right.
CREATE TABLE IF NOT EXISTS email_template_overrides (
    id            SERIAL PRIMARY KEY,
    template_key  VARCHAR(64) NOT NULL,
    venue_id      INT         REFERENCES venues(id) ON DELETE CASCADE,
    subject       TEXT,
    heading       TEXT,
    intro         TEXT,
    footer_note   TEXT,
    is_active     BOOLEAN     NOT NULL DEFAULT TRUE,
    updated_by    INT,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- One override per template per scope (NULL venue = every property).
CREATE UNIQUE INDEX IF NOT EXISTS uq_email_template_overrides_scope
    ON email_template_overrides (template_key, COALESCE(venue_id, 0));

-- Append-only history: every save and every reset writes one row, so a previous
-- wording can always be restored.
CREATE TABLE IF NOT EXISTS email_template_versions (
    id            BIGSERIAL PRIMARY KEY,
    template_key  VARCHAR(64) NOT NULL,
    venue_id      INT,
    subject       TEXT,
    heading       TEXT,
    intro         TEXT,
    footer_note   TEXT,
    action        VARCHAR(10) NOT NULL DEFAULT 'save' CHECK (action IN ('save','reset','restore')),
    saved_by      INT,
    saved_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_email_template_versions_key
    ON email_template_versions (template_key, COALESCE(venue_id, 0), saved_at DESC);
