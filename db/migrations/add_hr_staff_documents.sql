-- Migration: employee documents (contracts, IDs, certificates) on the employee
-- profile. Run via /admin/migrate.php AFTER add_hr_staff.sql. Idempotent.
--
-- One-to-many: a person can have any number of documents. The file itself is
-- PRIVATE — stored with storage_put_private() (private bucket, else a non-web
-- local dir) and served only through admin/employee-file.php, which re-checks
-- manager access + venue scope on every view. file_key is never a public URL.
-- Every read is guarded by hr_staff_documents_supported(), so a deploy that runs
-- before this migration just hides the Documents card.

CREATE TABLE IF NOT EXISTS hr_staff_documents (
    id           SERIAL PRIMARY KEY,
    hr_staff_id  INT          NOT NULL REFERENCES hr_staff(id) ON DELETE CASCADE,
    file_key     TEXT         NOT NULL,
    filename     TEXT         NOT NULL,           -- original name, for display + download
    label        TEXT,                            -- e.g. "Employment contract 2026"
    content_type TEXT         NOT NULL DEFAULT 'application/octet-stream',
    size_bytes   INT          NOT NULL DEFAULT 0,
    uploaded_by  INT          REFERENCES admin_users(id) ON DELETE SET NULL,
    uploaded_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_hr_staff_documents_staff ON hr_staff_documents(hr_staff_id, uploaded_at DESC);
