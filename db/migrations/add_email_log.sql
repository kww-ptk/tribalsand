-- Tribal Sand: email send log (Email Notifications Center, Phase 1). Idempotent.
--
-- One row per email the system sends — or decides NOT to send. Every email goes
-- through mail_send() (includes/mail-log.php), which writes exactly one row per
-- recipient, so "who got which email, and why" always has an answer.
--
--   status     sent       handed to the mail server (SES accepted it)
--              failed     the mail server refused it / could not be reached
--              suppressed the owner switched this email off (Admin → Emails)
--              skipped    staff chose not to email (e.g. unticked "Email the
--                         guest" on a confirm), or there was no valid address
--              delivered  SES delivery event (Phase 1b, api/ses-events.php)
--              bounced    SES bounce event
--              complained SES complaint (the guest marked it as spam)
--
-- html_snapshot holds the rendered body so we can see exactly what was sent.
-- It is pruned after 180 days (bin/email-log-prune.php); the row itself stays.
-- venue_id is resolved at write time (from the hold / submission / reservation)
-- so a manager's log view can be scoped to their properties.
CREATE TABLE IF NOT EXISTS email_log (
    id              BIGSERIAL PRIMARY KEY,
    template_key    VARCHAR(64)  NOT NULL,
    audience        VARCHAR(10)  NOT NULL DEFAULT 'guest'
                                 CHECK (audience IN ('guest','staff')),
    to_email        VARCHAR(320) NOT NULL DEFAULT '',
    subject         TEXT         NOT NULL DEFAULT '',
    status          VARCHAR(16)  NOT NULL
                                 CHECK (status IN ('sent','failed','suppressed','skipped',
                                                   'delivered','bounced','complained')),
    provider        VARCHAR(16)  NOT NULL DEFAULT '',
    provider_id     VARCHAR(255),
    error           TEXT,
    hold_id         INT,
    submission_id   INT,
    reservation_id  INT,
    pos_sale_id     INT,
    venue_id        INT,
    triggered_by    VARCHAR(10)  NOT NULL DEFAULT 'system'
                                 CHECK (triggered_by IN ('admin','guest','system','pos')),
    admin_id        INT,
    trigger_source  VARCHAR(160) NOT NULL DEFAULT '',
    note            TEXT,
    html_snapshot   TEXT,
    text_snapshot   TEXT,
    event_at        TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- No foreign keys on purpose: the log must outlive a deleted hold / submission
-- (it is evidence of what the guest was told), and a log write must never fail
-- because a referenced row is gone.
CREATE INDEX IF NOT EXISTS idx_email_log_created    ON email_log (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_email_log_template   ON email_log (template_key, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_email_log_hold       ON email_log (hold_id)        WHERE hold_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_email_log_submission ON email_log (submission_id)  WHERE submission_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_email_log_venue      ON email_log (venue_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_email_log_provider   ON email_log (provider_id)    WHERE provider_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_email_log_to         ON email_log (lower(to_email));
