-- Tribal Sand: RAG content embeddings (AI assistant, Phase 2). Idempotent.
--
-- The assistant answers DESCRIPTIVE questions ("what's the villa like?",
-- "cancellation policy?", "what activities are nearby?") from embedded DB
-- prose, retrieved by vector similarity. Exact facts (availability, prices)
-- stay in the tool layer (Phase 1) — this table never holds a price.
--
-- One row = one CHUNK of a source document. bin/reindex-content.php rebuilds it
-- from the live DB (venue about/stay copy, room descriptions + FAQs, tours,
-- sustainability). It is a derived cache: safe to TRUNCATE and rebuild.
--
-- Requires pgvector. Confirmed available on Neon (dev) and RDS supports it on
-- current versions; the app degrades gracefully (rag_supported()) if the
-- extension/table is absent, so a deploy without this migration simply omits
-- the descriptive layer instead of erroring.
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS content_embeddings (
    id           BIGSERIAL PRIMARY KEY,
    source       VARCHAR(30)  NOT NULL,          -- 'venue' | 'room' | 'tour' | 'page'
    source_id    VARCHAR(120) NOT NULL,          -- slug or page key of the source document
    venue_id     INT,                            -- NULL = global/cross-property (e.g. tours); else scopes retrieval
    title        VARCHAR(255) NOT NULL DEFAULT '',
    chunk_index  SMALLINT     NOT NULL DEFAULT 0,
    chunk_text   TEXT         NOT NULL,
    content_hash CHAR(64)     NOT NULL,           -- sha256 of chunk_text; lets reindex skip re-embedding unchanged chunks
    embedding    vector(1536) NOT NULL,           -- OpenAI text-embedding-3-small dimensionality
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (source, source_id, chunk_index)
);

CREATE INDEX IF NOT EXISTS idx_content_emb_venue  ON content_embeddings (venue_id);
CREATE INDEX IF NOT EXISTS idx_content_emb_source ON content_embeddings (source, source_id);

-- Approximate-nearest-neighbour index for cosine distance (<=>). HNSW gives good
-- recall without a training step; the corpus here is small, so this is ample.
CREATE INDEX IF NOT EXISTS idx_content_emb_vec
    ON content_embeddings USING hnsw (embedding vector_cosine_ops);
