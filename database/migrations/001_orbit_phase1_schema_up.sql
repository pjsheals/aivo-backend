-- ============================================================
-- ORBIT Phase 1 — Brand Content Index
-- 001_orbit_phase1_schema_up.sql
--
-- Creates three new tables for ORBIT Phase 1:
--   1) meridian_brand_content_sources             — user-provided entry points
--   2) meridian_brand_content_items                — indexed content + embeddings
--   3) meridian_brand_content_classifications_log  — audit of user corrections
--
-- Requires: pgvector extension (Railway Postgres standard image includes it).
-- Idempotent in the sense that CREATE EXTENSION IF NOT EXISTS is safe to re-run,
-- but the CREATE TABLE statements will error if tables already exist — by design.
-- ============================================================

CREATE EXTENSION IF NOT EXISTS vector;

-- ------------------------------------------------------------
-- 1) meridian_brand_content_sources
--    User-provided entry points per brand (sitemap, content hub, RSS, etc.)
-- ------------------------------------------------------------
CREATE TABLE meridian_brand_content_sources (
    id                  BIGSERIAL PRIMARY KEY,
    brand_id            BIGINT NOT NULL REFERENCES meridian_brands(id) ON DELETE CASCADE,
    source_url          TEXT NOT NULL,
    source_type         TEXT NOT NULL
                        CHECK (source_type IN ('sitemap','content_hub','rss','knowledge_base','document_repo')),
    crawl_cadence_days  SMALLINT NOT NULL DEFAULT 30,   -- matches meridian_brands.reaudit_cadence_days pattern
    is_active           BOOLEAN  NOT NULL DEFAULT TRUE,
    status              TEXT     NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','crawling','completed','failed','paused')),
    last_crawled_at     TIMESTAMPTZ,
    next_crawl_at       TIMESTAMPTZ,                    -- mirrors meridian_brands.next_reaudit_at
    last_error          TEXT,
    items_indexed       INTEGER  NOT NULL DEFAULT 0,
    created_by          BIGINT,                          -- FK to users table left soft for now (no users table seen yet)
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ,                     -- soft delete; preserves item lineage
    UNIQUE (brand_id, source_url)
);

CREATE INDEX idx_brand_content_sources_brand
    ON meridian_brand_content_sources (brand_id)
    WHERE deleted_at IS NULL;

-- For the crawler scheduler: "what's due to be re-crawled?"
CREATE INDEX idx_brand_content_sources_next_crawl
    ON meridian_brand_content_sources (next_crawl_at)
    WHERE deleted_at IS NULL AND is_active = TRUE;

-- ------------------------------------------------------------
-- 2) meridian_brand_content_items
--    Indexed content with embeddings + classifications
-- ------------------------------------------------------------
CREATE TABLE meridian_brand_content_items (
    id                          BIGSERIAL PRIMARY KEY,
    brand_id                    BIGINT NOT NULL REFERENCES meridian_brands(id) ON DELETE CASCADE,
    source_id                   BIGINT NOT NULL REFERENCES meridian_brand_content_sources(id) ON DELETE CASCADE,

    -- URL
    url                         TEXT NOT NULL,           -- as fetched
    url_canonical               TEXT NOT NULL,           -- normalised; used for the unique constraint

    -- Content
    title                       TEXT,
    content_text                TEXT,                    -- readability-extracted plain text (basis for embedding)
    content_html_hash           CHAR(64),                -- SHA-256 of original HTML; cheap re-crawl change detection
    embedding_input_text        TEXT,                    -- exactly what was sent to embeddings API; auditable
    embedding                   vector(1536),            -- text-embedding-3-small produces 1536-dim vectors

    -- Classifications (5 axes per brief + language per Decision 7)
    topics                      TEXT[],                  -- semantic tag list
    sub_brand                   TEXT,
    territory                   TEXT,                    -- ISO 3166-1 alpha-2 or locale, e.g. 'us', 'fr', 'us-es'
    content_type                TEXT,                    -- pdp, editorial, clinical, press, blog, faq, other
    content_date                DATE,                    -- extracted from page metadata; nullable
    language                    TEXT,                    -- ISO 639-1, e.g. 'en', 'fr', 'es'

    -- Per-axis confidence + provenance of classification
    classification_confidences  JSONB,                   -- {topics: 0.9, sub_brand: 0.7, territory: 0.95, ...}
    classified_by               TEXT,                    -- 'haiku-3.5' | 'user' | 'haiku+user-corrected'
    classified_at               TIMESTAMPTZ,

    -- Lifecycle
    first_indexed_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_indexed_at             TIMESTAMPTZ NOT NULL DEFAULT now(),

    UNIQUE (brand_id, url_canonical)
);

-- Vector similarity (pgvector HNSW + cosine, default for OpenAI embeddings)
CREATE INDEX idx_brand_content_items_embedding
    ON meridian_brand_content_items
    USING hnsw (embedding vector_cosine_ops);

-- Filter indexes for the Index view
CREATE INDEX idx_brand_content_items_brand        ON meridian_brand_content_items (brand_id);
CREATE INDEX idx_brand_content_items_source       ON meridian_brand_content_items (source_id);
CREATE INDEX idx_brand_content_items_brand_date   ON meridian_brand_content_items (brand_id, content_date DESC);
CREATE INDEX idx_brand_content_items_brand_type   ON meridian_brand_content_items (brand_id, content_type);
CREATE INDEX idx_brand_content_items_brand_lang   ON meridian_brand_content_items (brand_id, language);
CREATE INDEX idx_brand_content_items_brand_terr   ON meridian_brand_content_items (brand_id, territory);
CREATE INDEX idx_brand_content_items_topics_gin   ON meridian_brand_content_items USING gin (topics);

-- Keyword search (Index view: "Search by keyword")
CREATE INDEX idx_brand_content_items_text_fts
    ON meridian_brand_content_items
    USING gin (to_tsvector('simple', coalesce(title,'') || ' ' || coalesce(content_text,'')));

-- ------------------------------------------------------------
-- 3) meridian_brand_content_classifications_log
--    Audit of user corrections to auto-classifications
-- ------------------------------------------------------------
CREATE TABLE meridian_brand_content_classifications_log (
    id              BIGSERIAL PRIMARY KEY,
    item_id         BIGINT NOT NULL REFERENCES meridian_brand_content_items(id) ON DELETE CASCADE,
    brand_id        BIGINT NOT NULL REFERENCES meridian_brands(id) ON DELETE CASCADE,  -- denormalised for fast brand-scoped audit queries
    user_id         BIGINT,
    axis            TEXT NOT NULL
                    CHECK (axis IN ('topics','sub_brand','territory','content_type','content_date','language')),
    previous_value  JSONB,                                -- whatever was there (string, array, date)
    new_value       JSONB NOT NULL,
    rationale       TEXT,                                 -- optional user note
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_classifications_log_item       ON meridian_brand_content_classifications_log (item_id);
CREATE INDEX idx_classifications_log_brand_time ON meridian_brand_content_classifications_log (brand_id, created_at DESC);

-- ============================================================
-- Done.
-- ============================================================
