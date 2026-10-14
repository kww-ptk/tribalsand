-- Migration: the Support page's guide library (Admin → Settings → Support, design B,
-- Oct 2026). The owner imports PDF guides (Reception Guide, Till Guide, Guest
-- Website guide…) and staff read or download them. Nothing about a guide is in
-- code: a new feature = a new guide, or a new VERSION of an existing one (older
-- versions stay downloadable in Version history). Run via /admin/migrate.php.
-- Idempotent. Every read is guarded by support_docs_supported().
--
-- Files are PRIVATE — storage_put_private() under support/<doc id>/… — and served
-- only through admin/support-file.php (signed-in, the guide must be meant for the
-- account's role) or /guide (a signed share link, only for guides meant for
-- guests or travel agents).

CREATE TABLE IF NOT EXISTS support_docs (
    id          SERIAL PRIMARY KEY,
    title       TEXT        NOT NULL,
    subtitle    TEXT,
    summary     TEXT,                              -- one line under the title
    audience    TEXT[]      NOT NULL DEFAULT '{}', -- manager · reception · hr · staff · till · guests · agents (owner always sees all)
    sort_order  INT         NOT NULL DEFAULT 0,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE, -- removed guides are hidden, never deleted (owner can restore)
    created_by  INT         REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS support_doc_versions (
    id           SERIAL PRIMARY KEY,
    doc_id       INT         NOT NULL REFERENCES support_docs(id) ON DELETE CASCADE,
    version_no   INT         NOT NULL,
    file_key     TEXT        NOT NULL,
    filename     TEXT        NOT NULL,             -- the uploaded name, for the download
    size_bytes   INT         NOT NULL DEFAULT 0,
    pages        INT,                              -- read from the PDF when possible
    note         TEXT,                             -- "What changed" — shown in Version history
    uploaded_by  INT         REFERENCES admin_users(id) ON DELETE SET NULL,
    uploaded_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (doc_id, version_no)
);

CREATE INDEX IF NOT EXISTS idx_support_doc_versions_doc ON support_doc_versions (doc_id, version_no DESC);
